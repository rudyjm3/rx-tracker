-- Migration 007: Standalone pain/mood log entries (not tied to a scheduled dose)
-- and edit-tracking column for dose-log feedback.

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

-- Track when dose-log pain/mood feedback was edited after initial entry (NULL = never edited)
ALTER TABLE dose_logs
    ADD COLUMN IF NOT EXISTS feedback_edited_at TIMESTAMP NULL DEFAULT NULL AFTER mood_level;
