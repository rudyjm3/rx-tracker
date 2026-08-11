<?php

declare(strict_types=1);

final class AllergyRepository
{
    private const ALLOWED_TYPES      = ['allergy', 'intolerance'];
    private const ALLOWED_SEVERITIES = ['low', 'moderate', 'high', 'very_high'];
    private const ALLOWED_CATEGORIES = ['drug', 'food', 'environment_animal', 'other'];

    public function __construct(
        private readonly PDO $db,
        private readonly int $userId
    ) {
    }

    public static function allowedTypes(): array
    {
        return self::ALLOWED_TYPES;
    }

    public static function allowedSeverities(): array
    {
        return self::ALLOWED_SEVERITIES;
    }

    public static function allowedCategories(): array
    {
        return self::ALLOWED_CATEGORIES;
    }

    public function catalogForUser(): array
    {
        $stmt = $this->db->prepare(
            'SELECT MIN(id) AS id, owner_user_id, name FROM allergy_catalog
             WHERE owner_user_id IS NULL OR owner_user_id = :user_id
             GROUP BY name, owner_user_id
             ORDER BY name ASC'
        );
        $stmt->execute(['user_id' => $this->userId]);
        return $stmt->fetchAll();
    }

    public function allergiesForProfile(?int $profileId): array
    {
        $sql = 'SELECT pa.id, pa.allergy_catalog_id, pa.allergy_type, pa.life_threatening, pa.severity, pa.category, pa.notes, pa.is_active, ac.name
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

    public function findAllergy(?int $profileId, int $allergyId): ?array
    {
        $sql = 'SELECT pa.id, pa.allergy_catalog_id, pa.allergy_type, pa.life_threatening, pa.severity, pa.category, pa.notes, pa.is_active, ac.name
                FROM profile_allergies pa
                JOIN allergy_catalog ac ON ac.id = pa.allergy_catalog_id
                WHERE pa.id = :id AND pa.owner_user_id = :user_id AND ' . ($profileId === null ? 'pa.profile_id IS NULL' : 'pa.profile_id = :profile_id') . '
                LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $params = ['id' => $allergyId, 'user_id' => $this->userId];
        if ($profileId !== null) {
            $params['profile_id'] = $profileId;
        }
        $stmt->execute($params);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function addAllergy(
        ?int $profileId,
        ?int $catalogId,
        ?string $newName,
        string $type = 'allergy',
        bool $lifeThreatening = false,
        ?string $severity = null,
        ?string $category = null,
        ?string $notes = null
    ): array {
        $this->validateType($type);
        $this->validateSeverity($severity);
        $this->validateCategory($category);
        $notes = $this->normalizeNotes($notes);

        $catalogId = $this->resolveCatalogId($catalogId, $newName);
        $this->assertNotDuplicate($profileId, $catalogId);

        $insert = $this->db->prepare(
            'INSERT INTO profile_allergies (owner_user_id, profile_id, allergy_catalog_id, allergy_type, life_threatening, severity, category, notes)
             VALUES (:user_id, :profile_id, :catalog_id, :type, :life_threatening, :severity, :category, :notes)'
        );
        $insert->execute([
            'user_id'          => $this->userId,
            'profile_id'       => $profileId,
            'catalog_id'       => $catalogId,
            'type'             => $type,
            'life_threatening' => $lifeThreatening ? 1 : 0,
            'severity'         => $severity,
            'category'         => $category,
            'notes'            => $notes,
        ]);

        return ['id' => (int) $this->db->lastInsertId(), 'allergy_catalog_id' => $catalogId];
    }

    public function updateAllergy(
        ?int $profileId,
        int $allergyId,
        ?int $catalogId,
        ?string $newName,
        string $type,
        bool $lifeThreatening,
        ?string $severity,
        ?string $category,
        ?string $notes,
        bool $isActive
    ): void {
        $this->validateType($type);
        $this->validateSeverity($severity);
        $this->validateCategory($category);
        $notes = $this->normalizeNotes($notes);

        $catalogId = $this->resolveCatalogId($catalogId, $newName);
        $this->assertNotDuplicate($profileId, $catalogId, $allergyId);

        $sql = 'UPDATE profile_allergies
                SET allergy_catalog_id = :catalog_id,
                    allergy_type       = :type,
                    life_threatening   = :life_threatening,
                    severity           = :severity,
                    category           = :category,
                    notes              = :notes,
                    is_active          = :is_active
                WHERE id = :id AND owner_user_id = :user_id AND ' .
               ($profileId === null ? 'profile_id IS NULL' : 'profile_id = :profile_id');
        $params = [
            'catalog_id'       => $catalogId,
            'type'             => $type,
            'life_threatening' => $lifeThreatening ? 1 : 0,
            'severity'         => $severity,
            'category'         => $category,
            'notes'            => $notes,
            'is_active'        => $isActive ? 1 : 0,
            'id'               => $allergyId,
            'user_id'          => $this->userId,
        ];
        if ($profileId !== null) {
            $params['profile_id'] = $profileId;
        }
        $this->db->prepare($sql)->execute($params);
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

    private function resolveCatalogId(?int $catalogId, ?string $newName): int
    {
        $catalogId = $catalogId !== null && $catalogId > 0 ? $catalogId : null;
        $newName   = $newName !== null ? trim($newName) : null;

        if ($catalogId === null && ($newName === null || $newName === '')) {
            throw new RuntimeException('Choose an allergy or enter a new one.');
        }

        if ($catalogId === null) {
            return $this->findOrCreateCatalogEntry((string) $newName);
        }

        $check = $this->db->prepare(
            'SELECT id FROM allergy_catalog WHERE id = :id AND (owner_user_id IS NULL OR owner_user_id = :user_id) LIMIT 1'
        );
        $check->execute(['id' => $catalogId, 'user_id' => $this->userId]);
        if ($check->fetchColumn() === false) {
            throw new RuntimeException('That allergy could not be found.');
        }

        return $catalogId;
    }

    private function assertNotDuplicate(?int $profileId, int $catalogId, ?int $excludeAllergyId = null): void
    {
        $sql = 'SELECT 1 FROM profile_allergies
                WHERE owner_user_id = :user_id AND allergy_catalog_id = :catalog_id AND ' .
               ($profileId === null ? 'profile_id IS NULL' : 'profile_id = :profile_id');
        $params = ['user_id' => $this->userId, 'catalog_id' => $catalogId];
        if ($profileId !== null) {
            $params['profile_id'] = $profileId;
        }
        if ($excludeAllergyId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeAllergyId;
        }
        $sql .= ' LIMIT 1';

        $dupCheck = $this->db->prepare($sql);
        $dupCheck->execute($params);
        if ($dupCheck->fetchColumn() !== false) {
            throw new RuntimeException('That allergy is already on the list.');
        }
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

    private function validateType(string $type): void
    {
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new RuntimeException('Invalid allergy type.');
        }
    }

    private function validateSeverity(?string $severity): void
    {
        if ($severity !== null && !in_array($severity, self::ALLOWED_SEVERITIES, true)) {
            throw new RuntimeException('Invalid severity value.');
        }
    }

    private function validateCategory(?string $category): void
    {
        if ($category !== null && !in_array($category, self::ALLOWED_CATEGORIES, true)) {
            throw new RuntimeException('Invalid category value.');
        }
    }

    private function normalizeNotes(?string $notes): ?string
    {
        if ($notes === null) {
            return null;
        }
        $notes = trim($notes);
        return $notes === '' ? null : mb_substr($notes, 0, 1000);
    }
}
