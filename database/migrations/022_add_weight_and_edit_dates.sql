-- Migration 022: Weight (value + unit) alongside the existing height field,
-- plus per-field last-edited timestamps for height and weight, for both the
-- primary account owner and family members.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS weight_value DECIMAL(6,2) NULL,
    ADD COLUMN IF NOT EXISTS weight_unit  VARCHAR(4) NULL,
    ADD COLUMN IF NOT EXISTS height_updated_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS weight_updated_at DATETIME NULL;

ALTER TABLE family_profiles
    ADD COLUMN IF NOT EXISTS weight_value DECIMAL(6,2) NULL,
    ADD COLUMN IF NOT EXISTS weight_unit  VARCHAR(4) NULL,
    ADD COLUMN IF NOT EXISTS height_updated_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS weight_updated_at DATETIME NULL;
