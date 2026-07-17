CREATE DATABASE IF NOT EXISTS rx_tracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE rx_tracker;

CREATE TABLE IF NOT EXISTS medications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    dose VARCHAR(120) NOT NULL,
    instructions VARCHAR(255) NOT NULL DEFAULT '',
    schedule_mode ENUM('fixed_times', 'interval') NOT NULL DEFAULT 'fixed_times',
    time_format ENUM('24h', '12h') NOT NULL DEFAULT '24h',
    interval_hours TINYINT UNSIGNED NULL,
    first_dose_time TIME NULL,
    as_needed TINYINT(1) NOT NULL DEFAULT 0,
    starting_pill_count INT UNSIGNED NOT NULL DEFAULT 0,
    pill_count INT UNSIGNED NOT NULL DEFAULT 0,
    low_supply_threshold INT UNSIGNED NOT NULL DEFAULT 5,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_medications_active_name (active, name)
) ENGINE=InnoDB;

ALTER TABLE medications
    ADD COLUMN IF NOT EXISTS starting_pill_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER as_needed;

ALTER TABLE medications
    ADD COLUMN IF NOT EXISTS time_format ENUM('24h', '12h') NOT NULL DEFAULT '24h' AFTER schedule_mode;

UPDATE medications
SET starting_pill_count = pill_count
WHERE starting_pill_count = 0;

CREATE TABLE IF NOT EXISTS medication_schedule_times (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    medication_id INT UNSIGNED NOT NULL,
    reminder_time TIME NOT NULL,
    quantity_per_dose DECIMAL(10,3) NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_schedule_medication_time (medication_id, reminder_time),
    INDEX idx_schedule_time (reminder_time),
    CONSTRAINT fk_schedule_medication
        FOREIGN KEY (medication_id) REFERENCES medications (id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dose_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    medication_id INT UNSIGNED NOT NULL,
    scheduled_for_date DATE NOT NULL,
    scheduled_time TIME NOT NULL,
    status ENUM('taken', 'skipped', 'missed') NOT NULL DEFAULT 'taken',
    note VARCHAR(255) NOT NULL DEFAULT '',
    taken_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_log_medication_schedule (medication_id, scheduled_for_date, scheduled_time),
    INDEX idx_dose_logs_date (scheduled_for_date),
    INDEX idx_dose_logs_status (status),
    INDEX idx_dose_logs_taken_at (taken_at),
    CONSTRAINT fk_dose_logs_medication
        FOREIGN KEY (medication_id) REFERENCES medications (id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dose_postpones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    medication_id INT UNSIGNED NOT NULL,
    scheduled_for_date DATE NOT NULL,
    scheduled_time TIME NOT NULL,
    postponed_until DATETIME NOT NULL,
    resolved_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_postpone_dose (medication_id, scheduled_for_date, scheduled_time),
    INDEX idx_postpone_due (postponed_until, resolved_at),
    CONSTRAINT fk_dose_postpones_medication
        FOREIGN KEY (medication_id) REFERENCES medications (id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    endpoint TEXT NOT NULL,
    p256dh_key VARCHAR(255) NOT NULL,
    auth_key VARCHAR(255) NOT NULL,
    user_agent VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_push_endpoint (endpoint(191))
) ENGINE=InnoDB;

ALTER TABLE medications
    ADD COLUMN IF NOT EXISTS track_dose_feedback TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE dose_logs
    ADD COLUMN IF NOT EXISTS pain_level TINYINT UNSIGNED NULL;

-- Amount actually removed from inventory when this log was marked taken,
-- so reverting the log restores exactly what was deducted.
ALTER TABLE dose_logs
    ADD COLUMN IF NOT EXISTS deducted_quantity DECIMAL(10,3) NULL;

CREATE TABLE IF NOT EXISTS medication_groups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    scheduled_time TIME NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_groups_active_time (active, scheduled_time)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS medication_group_members (
    group_id INT UNSIGNED NOT NULL,
    medication_id INT UNSIGNED NOT NULL,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    quantity_per_dose DECIMAL(10,2) NULL DEFAULT NULL,
    PRIMARY KEY (group_id, medication_id),
    CONSTRAINT fk_group_members_group
        FOREIGN KEY (group_id) REFERENCES medication_groups (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_group_members_medication
        FOREIGN KEY (medication_id) REFERENCES medications (id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS push_delivery_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    medication_id INT UNSIGNED NOT NULL,
    scheduled_for_date DATE NOT NULL,
    scheduled_time TIME NOT NULL,
    sent_at DATETIME NOT NULL,
    action_nonce VARCHAR(64) NOT NULL DEFAULT '',
    UNIQUE KEY uq_push_delivery (medication_id, scheduled_for_date, scheduled_time),
    INDEX idx_push_nonce (action_nonce(32)),
    CONSTRAINT fk_push_delivery_medication
        FOREIGN KEY (medication_id) REFERENCES medications (id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE push_delivery_log
    ADD COLUMN IF NOT EXISTS action_nonce VARCHAR(64) NOT NULL DEFAULT '';

ALTER TABLE medications
    ADD COLUMN IF NOT EXISTS medication_type ENUM('prescription','otc','supplement') NOT NULL DEFAULT 'prescription';

ALTER TABLE medications
    ADD COLUMN IF NOT EXISTS dose_amount DECIMAL(10,3) NULL;

ALTER TABLE medications
    ADD COLUMN IF NOT EXISTS dose_unit VARCHAR(20) NULL;

ALTER TABLE medications
    ADD COLUMN IF NOT EXISTS dose_form VARCHAR(30) NULL;

ALTER TABLE medications
    ADD COLUMN IF NOT EXISTS inventory_type VARCHAR(30) NOT NULL DEFAULT 'pills';

ALTER TABLE medications
    ADD COLUMN IF NOT EXISTS inventory_unit VARCHAR(20) NOT NULL DEFAULT 'tablets';

ALTER TABLE medications
    ADD COLUMN IF NOT EXISTS starting_quantity DECIMAL(10,3) NULL;

ALTER TABLE medications
    ADD COLUMN IF NOT EXISTS current_quantity DECIMAL(10,3) NULL;

ALTER TABLE medications
    ADD COLUMN IF NOT EXISTS quantity_per_dose DECIMAL(10,3) NOT NULL DEFAULT 1.000;

UPDATE medications
SET current_quantity  = pill_count,
    starting_quantity = starting_pill_count,
    inventory_unit    = 'tablets'
WHERE current_quantity IS NULL;

-- User authentication tables
CREATE TABLE IF NOT EXISTS users (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email                  VARCHAR(255) NOT NULL UNIQUE,
    password_hash          VARCHAR(255) NULL,
    display_name           VARCHAR(100),
    google_id              VARCHAR(255) NULL UNIQUE,
    profile_picture        VARCHAR(500) NULL,
    email_verified         TINYINT(1) NOT NULL DEFAULT 0,
    last_login             DATETIME NULL,
    reset_token            VARCHAR(64),
    reset_token_expires_at DATETIME,
    created_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_sessions (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNSIGNED NOT NULL,
    session_token  VARCHAR(64) NOT NULL UNIQUE,
    user_agent     VARCHAR(255),
    ip_address     VARCHAR(45),
    expires_at     DATETIME NOT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_session_token (session_token)
) ENGINE=InnoDB;

-- Per-user settings (composite PK replaces single-key design)
CREATE TABLE IF NOT EXISTS app_settings (
    user_id       INT UNSIGNED NOT NULL,
    setting_key   VARCHAR(120) NOT NULL,
    setting_value VARCHAR(255) NOT NULL,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, setting_key),
    CONSTRAINT fk_app_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Add user_id to existing tables (idempotent)
ALTER TABLE medications
    ADD COLUMN IF NOT EXISTS user_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE medication_groups
    ADD COLUMN IF NOT EXISTS user_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE push_subscriptions
    ADD COLUMN IF NOT EXISTS user_id INT UNSIGNED NULL AFTER id;

-- Multi-group support: drop one-group constraint, add per-group dose override
-- Create non-unique index first so the FK (which uses it as its index) isn't broken.
CREATE INDEX IF NOT EXISTS idx_mgm_medication_id ON medication_group_members (medication_id);
ALTER TABLE medication_group_members
    DROP INDEX IF EXISTS uq_medication_one_group;
ALTER TABLE medication_group_members
    ADD COLUMN IF NOT EXISTS quantity_per_dose DECIMAL(10,3) NULL DEFAULT NULL;

-- Per-slot dose override for non-grouped medications
ALTER TABLE medication_schedule_times
    ADD COLUMN IF NOT EXISTS quantity_per_dose DECIMAL(10,3) NULL DEFAULT NULL;

-- Drag-and-drop sort order for medications and groups
ALTER TABLE medications
    ADD COLUMN IF NOT EXISTS sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE medication_groups
    ADD COLUMN IF NOT EXISTS sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0;

-- Family member profiles (Phase 2)
CREATE TABLE IF NOT EXISTS family_profiles (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_user_id  INT UNSIGNED NOT NULL,
    display_name   VARCHAR(100) NOT NULL,
    avatar_color   VARCHAR(7)   NULL,
    relationship   VARCHAR(50)  NULL,
    birth_year     YEAR         NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_family_profiles_owner (owner_user_id),
    CONSTRAINT fk_family_profiles_user
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE medications
    ADD COLUMN IF NOT EXISTS profile_id INT UNSIGNED NULL AFTER user_id,
    ADD INDEX IF NOT EXISTS idx_medications_profile (profile_id),
    ADD CONSTRAINT fk_medications_profile
        FOREIGN KEY (profile_id) REFERENCES family_profiles(id) ON DELETE SET NULL;

-- Nearly every query filters medications by user_id (+ profile_id); index the tenant
-- lookup. Uses a distinct name from migration 002's single-column idx_medications_user.
CREATE INDEX IF NOT EXISTS idx_medications_tenant ON medications (user_id, profile_id, active);

ALTER TABLE medication_groups
    ADD COLUMN IF NOT EXISTS profile_id INT UNSIGNED NULL AFTER user_id,
    ADD INDEX IF NOT EXISTS idx_medication_groups_profile (profile_id),
    ADD CONSTRAINT fk_medication_groups_profile
        FOREIGN KEY (profile_id) REFERENCES family_profiles(id) ON DELETE SET NULL;

-- In-app low-stock notifications
CREATE TABLE IF NOT EXISTS user_notifications (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    medication_id INT UNSIGNED NOT NULL,
    type          ENUM('low_stock','critical_stock','out_of_stock') NOT NULL,
    is_read       TINYINT(1) NOT NULL DEFAULT 0,
    is_dismissed  TINYINT(1) NOT NULL DEFAULT 0,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_notif_user_unread (user_id, is_read, is_dismissed),
    CONSTRAINT fk_notif_medication
        FOREIGN KEY (medication_id) REFERENCES medications (id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

-- Migration 004: Doctor Visit Report
ALTER TABLE medications
    ADD COLUMN IF NOT EXISTS start_date DATE NULL AFTER dose;

UPDATE medications SET start_date = DATE(created_at) WHERE start_date IS NULL;

-- Mood & Wellbeing tracking (mirrors pain-level feedback, generalized)
ALTER TABLE medications
    ADD COLUMN IF NOT EXISTS feedback_type ENUM('none','pain','mood','both') NOT NULL DEFAULT 'none';

UPDATE medications SET feedback_type = 'pain' WHERE track_dose_feedback = 1 AND feedback_type = 'none';

ALTER TABLE dose_logs
    ADD COLUMN IF NOT EXISTS mood_level TINYINT UNSIGNED NULL;

ALTER TABLE dose_logs
    ADD COLUMN IF NOT EXISTS feedback_edited_at TIMESTAMP NULL DEFAULT NULL AFTER mood_level;

ALTER TABLE medications
    MODIFY COLUMN instructions TEXT NOT NULL;

CREATE TABLE IF NOT EXISTS side_effects (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    medication_id INT UNSIGNED NOT NULL,
    occurred_date DATE NOT NULL,
    description   VARCHAR(255) NOT NULL,
    severity      ENUM('mild','moderate','severe') NOT NULL DEFAULT 'mild',
    note          VARCHAR(500) NOT NULL DEFAULT '',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_se_medication_date (medication_id, occurred_date),
    CONSTRAINT fk_se_medication
        FOREIGN KEY (medication_id) REFERENCES medications(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Migration 006: Medication status events (discontinue/resume) and dose-change history
CREATE TABLE IF NOT EXISTS medication_status_events (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    medication_id INT UNSIGNED NOT NULL,
    event         VARCHAR(20) NOT NULL,
    event_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reason        VARCHAR(64) NOT NULL DEFAULT '',
    comment       VARCHAR(500) NOT NULL DEFAULT '',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status_events_med_date (medication_id, event_at),
    CONSTRAINT fk_status_events_medication
        FOREIGN KEY (medication_id) REFERENCES medications (id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS medication_dose_changes (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    medication_id   INT UNSIGNED NOT NULL,
    changed_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    old_dose_amount DECIMAL(10,3) NULL,
    old_dose_unit   VARCHAR(20) NOT NULL DEFAULT '',
    new_dose_amount DECIMAL(10,3) NULL,
    new_dose_unit   VARCHAR(20) NOT NULL DEFAULT '',
    comment         VARCHAR(500) NOT NULL DEFAULT '',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dose_changes_med_date (medication_id, changed_at),
    CONSTRAINT fk_dose_changes_medication
        FOREIGN KEY (medication_id) REFERENCES medications (id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

-- Migration 008: Per-medication notes (replaces single instructions field with multiple notes)
CREATE TABLE IF NOT EXISTS medication_notes (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    medication_id INT UNSIGNED NOT NULL,
    note          TEXT NOT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_notes_medication (medication_id),
    CONSTRAINT fk_notes_medication
        FOREIGN KEY (medication_id) REFERENCES medications (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration 007: Standalone pain/mood log entries (not tied to a scheduled dose)
CREATE TABLE IF NOT EXISTS standalone_pain_mood_logs (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    medication_id INT UNSIGNED NOT NULL,
    log_type      ENUM('pain','mood','both') NOT NULL,
    pain_level    TINYINT UNSIGNED NULL,
    mood_level    TINYINT UNSIGNED NULL,
    note          VARCHAR(255) NOT NULL DEFAULT '',
    logged_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_standalone_user_med_date (user_id, medication_id, logged_at),
    CONSTRAINT fk_standalone_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_standalone_medication
        FOREIGN KEY (medication_id) REFERENCES medications(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Migration 009: Onboarding flow, granular tracking preferences, inventory ledger

ALTER TABLE medications
    ADD COLUMN IF NOT EXISTS setup_status ENUM('draft','ready','active') NOT NULL DEFAULT 'active',
    ADD COLUMN IF NOT EXISTS dashboard_enabled TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS reminders_enabled TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS adherence_enabled TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS inventory_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS tracking_started_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS inventory_count_method ENUM('counted','estimated','unknown') NOT NULL DEFAULT 'unknown',
    ADD COLUMN IF NOT EXISTS inventory_as_of DATETIME NULL;

CREATE TABLE IF NOT EXISTS profile_onboarding (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    profile_id   INT UNSIGNED NOT NULL DEFAULT 0,
    status       ENUM('not_started','in_progress','completed','skipped') NOT NULL DEFAULT 'not_started',
    current_step VARCHAR(40) NOT NULL DEFAULT 'medications',
    started_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    UNIQUE KEY uq_onboarding (user_id, profile_id),
    CONSTRAINT fk_onboarding_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_transactions (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    medication_id    INT UNSIGNED NOT NULL,
    dose_log_id      INT UNSIGNED NULL,
    refill_id        INT UNSIGNED NULL,
    transaction_type VARCHAR(30) NOT NULL,
    quantity_delta   DECIMAL(10,3) NOT NULL,
    balance_after    DECIMAL(10,3) NOT NULL,
    effective_at     DATETIME NOT NULL,
    count_method     VARCHAR(20) NULL,
    note             VARCHAR(255) NOT NULL DEFAULT '',
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inv_tx_med_effective (medication_id, effective_at),
    CONSTRAINT fk_inv_tx_medication FOREIGN KEY (medication_id) REFERENCES medications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE medication_refills
    ADD COLUMN IF NOT EXISTS started_using_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS carryover_quantity DECIMAL(10,3) NOT NULL DEFAULT 0;

-- Migration 010: Security hardening for closed-beta readiness

-- Per-IP and per-email login rate limiting (5 failures → 15-min lockout)
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier   VARCHAR(255) NOT NULL,
    attempt_type ENUM('email','ip') NOT NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts (identifier, attempt_type, attempted_at)
) ENGINE=InnoDB;

-- Per-user per-minute bucket counter for api-proxy.php rate limiting
CREATE TABLE IF NOT EXISTS api_proxy_rate_limit (
    user_id      INT UNSIGNED NOT NULL,
    window_start INT UNSIGNED NOT NULL,
    hits         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (user_id, window_start)
) ENGINE=InnoDB;

-- Email verification tokens for password-based accounts
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS verification_token            VARCHAR(64)  NULL,
    ADD COLUMN IF NOT EXISTS verification_token_expires_at DATETIME     NULL;

-- FK ensuring user_notifications rows are removed when the owning user is deleted
ALTER TABLE user_notifications
    ADD CONSTRAINT IF NOT EXISTS fk_notif_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
