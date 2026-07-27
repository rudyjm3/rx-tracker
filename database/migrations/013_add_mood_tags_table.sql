-- Migration 013: Add mood_tags table for the user-managed mood-tag list
-- (replaces the old ephemeral "+Tags" custom-input UX with a persistent,
-- per-user list of tags with an "Always show" flag).
CREATE TABLE IF NOT EXISTS mood_tags (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    name        VARCHAR(30) NOT NULL,
    always_show TINYINT(1) NOT NULL DEFAULT 1,
    sort_order  INT UNSIGNED NOT NULL DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mood_tags_user_name (user_id, name),
    INDEX idx_mood_tags_user (user_id),
    CONSTRAINT fk_mood_tags_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
