-- Phase 2: Family member profiles
-- Run after 001_add_users.sql and 002_add_user_id_to_tables.sql are applied.
-- NULL profile_id = belongs to the primary user themselves.
-- Non-null profile_id = belongs to that family member's profile.

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

ALTER TABLE medication_groups
    ADD COLUMN IF NOT EXISTS profile_id INT UNSIGNED NULL AFTER user_id,
    ADD INDEX IF NOT EXISTS idx_medication_groups_profile (profile_id),
    ADD CONSTRAINT fk_medication_groups_profile
        FOREIGN KEY (profile_id) REFERENCES family_profiles(id) ON DELETE SET NULL;
