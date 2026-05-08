CREATE DATABASE IF NOT EXISTS rx_tracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE rx_tracker;

CREATE TABLE IF NOT EXISTS medications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    dose VARCHAR(120) NOT NULL,
    reminder_time TIME NOT NULL,
    instructions VARCHAR(255) NOT NULL DEFAULT '',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_medications_active_time (active, reminder_time)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dose_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    medication_id INT UNSIGNED NOT NULL,
    taken_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    note VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dose_logs_taken_at (taken_at),
    INDEX idx_dose_logs_medication_taken (medication_id, taken_at),
    CONSTRAINT fk_dose_logs_medication
        FOREIGN KEY (medication_id) REFERENCES medications (id)
        ON DELETE CASCADE
) ENGINE=InnoDB;
