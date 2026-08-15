-- Migration 021: Scope standalone pain/mood logs to a family profile.
--
-- Independent (no-medication) logs have no medication row to infer a profile
-- from, so without this column an independent entry logged under one family
-- profile would leak into every other profile's charts, history, and doctor
-- reports on the same account.

ALTER TABLE standalone_pain_mood_logs
    ADD COLUMN profile_id INT UNSIGNED NULL AFTER medication_id,
    ADD INDEX idx_standalone_profile (profile_id),
    ADD CONSTRAINT fk_standalone_profile
        FOREIGN KEY (profile_id) REFERENCES family_profiles(id) ON DELETE SET NULL;
