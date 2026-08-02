-- Migration 015: Multi-step "Add medication" wizard support.
-- A dedicated staging table for in-progress medication wizards (created only
-- when the user explicitly clicks "Save draft"), kept independent from the
-- onboarding flow's own setup_status/profile_onboarding draft plumbing so the
-- two can't collide. Also adds an optional end date to medications, captured
-- by the wizard's "+ Add End Date" field.
CREATE TABLE IF NOT EXISTS medication_drafts (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNSIGNED NOT NULL,
    profile_id     INT UNSIGNED NULL,
    form_data      TEXT NOT NULL,
    current_step   TINYINT UNSIGNED NOT NULL DEFAULT 1,
    furthest_step  TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_medication_drafts_user (user_id, profile_id),
    CONSTRAINT fk_medication_drafts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE medications
    ADD COLUMN IF NOT EXISTS end_date DATE NULL AFTER start_date;
