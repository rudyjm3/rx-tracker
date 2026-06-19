-- Phase 1b: Add user_id to existing tables
-- Run AFTER 001_add_users.sql AND after inserting the first user via:
--   php scripts/migrate_to_first_user.php

-- medications
ALTER TABLE medications
    ADD COLUMN user_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE medications
    ADD INDEX idx_medications_user (user_id),
    ADD CONSTRAINT fk_medications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- medication_groups
ALTER TABLE medication_groups
    ADD COLUMN user_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE medication_groups
    ADD INDEX idx_medication_groups_user (user_id),
    ADD CONSTRAINT fk_medication_groups_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- push_subscriptions (nullable — a sub may exist before login)
ALTER TABLE push_subscriptions
    ADD COLUMN user_id INT UNSIGNED NULL AFTER id;
UPDATE push_subscriptions SET user_id = 1 WHERE user_id IS NULL;
ALTER TABLE push_subscriptions
    ADD INDEX idx_push_subscriptions_user (user_id);

-- app_settings: change PK from (setting_key) to (user_id, setting_key)
ALTER TABLE app_settings
    ADD COLUMN user_id INT UNSIGNED NOT NULL DEFAULT 1 FIRST;
ALTER TABLE app_settings
    DROP PRIMARY KEY;
ALTER TABLE app_settings
    ADD PRIMARY KEY (user_id, setting_key),
    ADD CONSTRAINT fk_app_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
