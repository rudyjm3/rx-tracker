-- Migration 009: Onboarding flow, granular tracking preferences, inventory ledger
-- All alterations use IF NOT EXISTS / defaults that preserve all existing rows.

-- Add setup lifecycle and tracking-preference columns to medications
ALTER TABLE medications
    ADD COLUMN IF NOT EXISTS setup_status ENUM('draft','ready','active') NOT NULL DEFAULT 'active',
    ADD COLUMN IF NOT EXISTS dashboard_enabled TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS reminders_enabled TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS adherence_enabled TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS inventory_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS tracking_started_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS inventory_count_method ENUM('counted','estimated','unknown') NOT NULL DEFAULT 'unknown',
    ADD COLUMN IF NOT EXISTS inventory_as_of DATETIME NULL;

-- Onboarding progress per user/profile (save-and-resume)
CREATE TABLE IF NOT EXISTS profile_onboarding (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    profile_id   INT UNSIGNED NULL,
    status       ENUM('not_started','in_progress','completed') NOT NULL DEFAULT 'not_started',
    current_step VARCHAR(40) NOT NULL DEFAULT 'medications',
    started_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    UNIQUE KEY uq_onboarding (user_id, profile_id),
    CONSTRAINT fk_onboarding_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inventory audit ledger (tracks every change as a transaction)
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

-- Extend medication_refills for carryover and fill-start tracking
ALTER TABLE medication_refills
    ADD COLUMN IF NOT EXISTS started_using_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS carryover_quantity DECIMAL(10,3) NOT NULL DEFAULT 0;
