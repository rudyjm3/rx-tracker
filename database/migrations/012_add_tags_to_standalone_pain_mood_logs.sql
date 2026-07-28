-- Migration 012: Add a tags column to standalone pain/mood logs, used by the
-- mood-only tag chips on the standalone mood log form.
ALTER TABLE standalone_pain_mood_logs
    ADD COLUMN IF NOT EXISTS tags VARCHAR(500) NOT NULL DEFAULT '' AFTER note;
