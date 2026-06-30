-- Add Google Identity Services account linking support.
ALTER TABLE users
    MODIFY COLUMN password_hash VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS google_id VARCHAR(255) NULL UNIQUE AFTER display_name,
    ADD COLUMN IF NOT EXISTS profile_picture VARCHAR(500) NULL AFTER google_id,
    ADD COLUMN IF NOT EXISTS email_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER profile_picture,
    ADD COLUMN IF NOT EXISTS last_login DATETIME NULL AFTER email_verified;
