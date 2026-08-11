-- Migration 018: Height (value + unit) for both the primary account owner
-- and family members, plus a profile picture for family members (users
-- already has profile_picture, populated by Google sign-in).
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS height_value DECIMAL(5,2) NULL,
    ADD COLUMN IF NOT EXISTS height_unit  VARCHAR(4) NULL;

ALTER TABLE family_profiles
    ADD COLUMN IF NOT EXISTS height_value    DECIMAL(5,2) NULL,
    ADD COLUMN IF NOT EXISTS height_unit     VARCHAR(4) NULL,
    ADD COLUMN IF NOT EXISTS profile_picture VARCHAR(500) NULL;
