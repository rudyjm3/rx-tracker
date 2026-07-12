-- Migration 008: Per-medication notes (replaces single instructions field with multiple notes)

CREATE TABLE IF NOT EXISTS medication_notes (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    medication_id INT UNSIGNED NOT NULL,
    note          TEXT NOT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_notes_medication (medication_id),
    CONSTRAINT fk_notes_medication
        FOREIGN KEY (medication_id) REFERENCES medications (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate existing instructions text into the new notes table
INSERT INTO medication_notes (medication_id, note, created_at, updated_at)
SELECT id, instructions, created_at, updated_at
FROM medications
WHERE instructions IS NOT NULL AND TRIM(instructions) <> '';
