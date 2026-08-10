<?php

declare(strict_types=1);

final class AllergyRepository
{
    public function __construct(
        private readonly PDO $db,
        private readonly int $userId
    ) {
    }

    public function catalogForUser(): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, owner_user_id, name FROM allergy_catalog
             WHERE owner_user_id IS NULL OR owner_user_id = :user_id
             ORDER BY name ASC'
        );
        $stmt->execute(['user_id' => $this->userId]);
        return $stmt->fetchAll();
    }

    public function allergiesForProfile(?int $profileId): array
    {
        $sql = 'SELECT pa.id, pa.allergy_catalog_id, ac.name
                FROM profile_allergies pa
                JOIN allergy_catalog ac ON ac.id = pa.allergy_catalog_id
                WHERE pa.owner_user_id = :user_id AND ' . ($profileId === null ? 'pa.profile_id IS NULL' : 'pa.profile_id = :profile_id') . '
                ORDER BY ac.name ASC';
        $stmt = $this->db->prepare($sql);
        $params = ['user_id' => $this->userId];
        if ($profileId !== null) {
            $params['profile_id'] = $profileId;
        }
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function addAllergy(?int $profileId, ?int $catalogId, ?string $newName): array
    {
        $catalogId = $catalogId !== null && $catalogId > 0 ? $catalogId : null;
        $newName   = $newName !== null ? trim($newName) : null;

        if ($catalogId === null && ($newName === null || $newName === '')) {
            throw new RuntimeException('Choose an allergy or enter a new one.');
        }

        if ($catalogId === null) {
            $catalogId = $this->findOrCreateCatalogEntry($newName);
        } else {
            $check = $this->db->prepare(
                'SELECT id FROM allergy_catalog WHERE id = :id AND (owner_user_id IS NULL OR owner_user_id = :user_id) LIMIT 1'
            );
            $check->execute(['id' => $catalogId, 'user_id' => $this->userId]);
            if ($check->fetchColumn() === false) {
                throw new RuntimeException('That allergy could not be found.');
            }
        }

        $dupCheck = $this->db->prepare(
            'SELECT 1 FROM profile_allergies
             WHERE owner_user_id = :user_id AND allergy_catalog_id = :catalog_id AND ' .
            ($profileId === null ? 'profile_id IS NULL' : 'profile_id = :profile_id') . '
             LIMIT 1'
        );
        $dupParams = ['user_id' => $this->userId, 'catalog_id' => $catalogId];
        if ($profileId !== null) {
            $dupParams['profile_id'] = $profileId;
        }
        $dupCheck->execute($dupParams);
        if ($dupCheck->fetchColumn() !== false) {
            throw new RuntimeException('That allergy is already on the list.');
        }

        $insert = $this->db->prepare(
            'INSERT INTO profile_allergies (owner_user_id, profile_id, allergy_catalog_id)
             VALUES (:user_id, :profile_id, :catalog_id)'
        );
        $insert->execute([
            'user_id'    => $this->userId,
            'profile_id' => $profileId,
            'catalog_id' => $catalogId,
        ]);

        return ['id' => (int) $this->db->lastInsertId(), 'allergy_catalog_id' => $catalogId];
    }

    public function removeAllergy(?int $profileId, int $profileAllergyId): void
    {
        $sql = 'DELETE FROM profile_allergies
                WHERE id = :id AND owner_user_id = :user_id AND ' .
               ($profileId === null ? 'profile_id IS NULL' : 'profile_id = :profile_id');
        $params = ['id' => $profileAllergyId, 'user_id' => $this->userId];
        if ($profileId !== null) {
            $params['profile_id'] = $profileId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    private function findOrCreateCatalogEntry(string $name): int
    {
        $name = mb_substr($name, 0, 150);
        if ($name === '') {
            throw new RuntimeException('Allergy name cannot be empty.');
        }

        $existing = $this->db->prepare(
            'SELECT id FROM allergy_catalog
             WHERE (owner_user_id IS NULL OR owner_user_id = :user_id) AND LOWER(name) = LOWER(:name)
             LIMIT 1'
        );
        $existing->execute(['user_id' => $this->userId, 'name' => $name]);
        $existingId = $existing->fetchColumn();
        if ($existingId !== false) {
            return (int) $existingId;
        }

        $insert = $this->db->prepare(
            'INSERT INTO allergy_catalog (owner_user_id, name) VALUES (:user_id, :name)'
        );
        $insert->execute(['user_id' => $this->userId, 'name' => $name]);

        return (int) $this->db->lastInsertId();
    }
}
