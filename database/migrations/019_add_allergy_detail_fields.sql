-- Migration 019: Richer allergy detail fields (type, life-threatening flag,
-- estimated severity, category, notes, active/resolved status).
ALTER TABLE profile_allergies
    ADD COLUMN IF NOT EXISTS allergy_type     VARCHAR(12) NOT NULL DEFAULT 'allergy',
    ADD COLUMN IF NOT EXISTS life_threatening TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS severity         VARCHAR(12) NULL,
    ADD COLUMN IF NOT EXISTS category         VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS notes            TEXT NULL,
    ADD COLUMN IF NOT EXISTS is_active        TINYINT(1) NOT NULL DEFAULT 1;
