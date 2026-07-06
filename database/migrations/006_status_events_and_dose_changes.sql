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
