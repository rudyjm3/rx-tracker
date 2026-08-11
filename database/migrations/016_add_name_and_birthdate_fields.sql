-- Migration 016: Add first/last name and a real birthdate to both the
-- primary user account and family member profiles, so the Profile
-- Information card can show a full name and calculate an exact age.
-- family_profiles.birth_year is left in place as a legacy fallback for
-- rows that only have a birth year (used to approximate age until a real
-- birth_date is entered).
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS first_name VARCHAR(50) NULL AFTER display_name,
    ADD COLUMN IF NOT EXISTS last_name  VARCHAR(50) NULL AFTER first_name,
    ADD COLUMN IF NOT EXISTS birth_date DATE NULL AFTER last_name;

ALTER TABLE family_profiles
    ADD COLUMN IF NOT EXISTS first_name VARCHAR(50) NULL AFTER display_name,
    ADD COLUMN IF NOT EXISTS last_name  VARCHAR(50) NULL AFTER first_name,
    ADD COLUMN IF NOT EXISTS birth_date DATE NULL AFTER birth_year;
