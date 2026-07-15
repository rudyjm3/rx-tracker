-- Allow onboarding to be explicitly skipped without being marked completed
ALTER TABLE profile_onboarding
  MODIFY COLUMN status ENUM('not_started','in_progress','completed','skipped')
  NOT NULL DEFAULT 'not_started';
