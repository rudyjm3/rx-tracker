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

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(120) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO app_settings (setting_key, setting_value)
VALUES ('missed_grace_minutes', '60')
ON DUPLICATE KEY UPDATE setting_value = setting_value;

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

CREATE TABLE IF NOT EXISTS push_delivery_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    medication_id INT UNSIGNED NOT NULL,
    scheduled_for_date DATE NOT NULL,
    scheduled_time TIME NOT NULL,
    sent_at DATETIME NOT NULL,
    UNIQUE KEY uq_push_delivery (medication_id, scheduled_for_date, scheduled_time),
    CONSTRAINT fk_push_delivery_medication
        FOREIGN KEY (medication_id) REFERENCES medications (id)
        ON DELETE CASCADE
) ENGINE=InnoDB;
