-- Migration 004: Doctor Visit Report
-- Adds start_date to medications and creates the standalone side_effects table.

ALTER TABLE medications
    ADD COLUMN IF NOT EXISTS start_date DATE NULL AFTER dose;

UPDATE medications SET start_date = DATE(created_at) WHERE start_date IS NULL;

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
