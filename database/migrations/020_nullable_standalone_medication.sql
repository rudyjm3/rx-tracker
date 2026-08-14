-- Migration 020: Allow standalone pain/mood logs to exist independently of any
-- medication ("independent" pain/mood logging, not tied to a scheduled dose or
-- to a medication that tracks pain/mood).

ALTER TABLE standalone_pain_mood_logs MODIFY COLUMN medication_id INT UNSIGNED NULL;
