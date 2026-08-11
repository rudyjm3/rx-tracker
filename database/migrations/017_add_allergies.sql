-- Migration 017: Allergies. A per-user catalog of allergy names (seeded
-- with common allergies, plus any custom ones a user adds) and a join
-- table linking allergies to a profile. profile_id NULL means the
-- primary account owner, matching the convention already used by
-- medications.profile_id and medication_groups.profile_id.
CREATE TABLE IF NOT EXISTS allergy_catalog (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_user_id  INT UNSIGNED NULL,
    name           VARCHAR(150) NOT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_allergy_catalog_name_owner (name, owner_user_id),
    CONSTRAINT fk_allergy_catalog_user FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS profile_allergies (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_user_id       INT UNSIGNED NOT NULL,
    profile_id          INT UNSIGNED NULL,
    allergy_catalog_id  INT UNSIGNED NOT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_profile_allergy (owner_user_id, profile_id, allergy_catalog_id),
    CONSTRAINT fk_profile_allergies_user FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_profile_allergies_profile FOREIGN KEY (profile_id) REFERENCES family_profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_profile_allergies_catalog FOREIGN KEY (allergy_catalog_id) REFERENCES allergy_catalog(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- MySQL treats NULL as distinct in a unique index, so ON DUPLICATE KEY
-- UPDATE would not de-dupe these global (owner_user_id IS NULL) rows on a
-- re-run; guard idempotency explicitly instead.
INSERT INTO allergy_catalog (owner_user_id, name)
SELECT NULL, v.name FROM (
    SELECT 'Penicillin' AS name UNION ALL SELECT 'Sulfa Drugs' UNION ALL SELECT 'Aspirin/NSAIDs' UNION ALL
    SELECT 'Codeine/Opioids' UNION ALL SELECT 'Iodine/Contrast Dye' UNION ALL SELECT 'Latex' UNION ALL
    SELECT 'Peanuts' UNION ALL SELECT 'Tree Nuts' UNION ALL SELECT 'Shellfish' UNION ALL
    SELECT 'Eggs' UNION ALL SELECT 'Milk/Dairy' UNION ALL SELECT 'Soy' UNION ALL SELECT 'Wheat/Gluten' UNION ALL
    SELECT 'Pollen' UNION ALL SELECT 'Pet Dander (Cat/Dog)' UNION ALL SELECT 'Bee/Insect Stings'
) v
WHERE NOT EXISTS (
    SELECT 1 FROM allergy_catalog ac WHERE ac.owner_user_id IS NULL AND ac.name = v.name
);
