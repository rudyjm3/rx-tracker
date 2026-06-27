<?php

declare(strict_types=1);

final class FamilyProfileRepository
{
    private const ALLOWED_RELATIONSHIPS = ['Spouse', 'Partner', 'Child', 'Parent', 'Sibling', 'Caregiver', 'Other'];

    public function __construct(private readonly PDO $db) {}

    public function profilesForUser(int $userId): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT id, owner_user_id, display_name, avatar_color, relationship, birth_year, created_at
                 FROM family_profiles
                 WHERE owner_user_id = :user_id
                 ORDER BY created_at ASC'
            );
            $stmt->execute(['user_id' => $userId]);
            return $stmt->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    public function findProfile(int $profileId, int $userId): ?array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT id, owner_user_id, display_name, avatar_color, relationship, birth_year, created_at
                 FROM family_profiles
                 WHERE id = :id AND owner_user_id = :user_id
                 LIMIT 1'
            );
            $stmt->execute(['id' => $profileId, 'user_id' => $userId]);
            $row = $stmt->fetch();
            return is_array($row) ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function createProfile(
        int $userId,
        string $displayName,
        ?string $avatarColor,
        ?string $relationship,
        ?int $birthYear
    ): int {
        $this->validateDisplayName($displayName);
        $this->validateAvatarColor($avatarColor);
        $this->validateRelationship($relationship);
        $this->validateBirthYear($birthYear);

        $stmt = $this->db->prepare(
            'INSERT INTO family_profiles (owner_user_id, display_name, avatar_color, relationship, birth_year)
             VALUES (:owner_user_id, :display_name, :avatar_color, :relationship, :birth_year)'
        );
        $stmt->execute([
            'owner_user_id' => $userId,
            'display_name'  => $displayName,
            'avatar_color'  => $avatarColor,
            'relationship'  => $relationship,
            'birth_year'    => $birthYear,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function updateProfile(
        int $profileId,
        int $userId,
        string $displayName,
        ?string $avatarColor,
        ?string $relationship,
        ?int $birthYear
    ): void {
        $this->validateDisplayName($displayName);
        $this->validateAvatarColor($avatarColor);
        $this->validateRelationship($relationship);
        $this->validateBirthYear($birthYear);

        $stmt = $this->db->prepare(
            'UPDATE family_profiles
             SET display_name = :display_name,
                 avatar_color = :avatar_color,
                 relationship = :relationship,
                 birth_year   = :birth_year
             WHERE id = :id AND owner_user_id = :user_id'
        );
        $stmt->execute([
            'id'           => $profileId,
            'user_id'      => $userId,
            'display_name' => $displayName,
            'avatar_color' => $avatarColor,
            'relationship' => $relationship,
            'birth_year'   => $birthYear,
        ]);
    }

    public function deleteProfile(int $profileId, int $userId): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM family_profiles WHERE id = :id AND owner_user_id = :user_id'
        );
        $stmt->execute(['id' => $profileId, 'user_id' => $userId]);
    }

    public static function allowedRelationships(): array
    {
        return self::ALLOWED_RELATIONSHIPS;
    }

    private function validateDisplayName(string $name): void
    {
        if (trim($name) === '') {
            throw new RuntimeException('Display name is required.');
        }
        if (mb_strlen($name) > 100) {
            throw new RuntimeException('Display name must be 100 characters or fewer.');
        }
    }

    private function validateAvatarColor(?string $color): void
    {
        if ($color !== null && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            throw new RuntimeException('Avatar color must be a valid hex color (e.g. #6366f1).');
        }
    }

    private function validateRelationship(?string $relationship): void
    {
        if ($relationship !== null && !in_array($relationship, self::ALLOWED_RELATIONSHIPS, true)) {
            throw new RuntimeException('Invalid relationship value.');
        }
    }

    private function validateBirthYear(?int $year): void
    {
        if ($year !== null && ($year < 1900 || $year > (int) date('Y'))) {
            throw new RuntimeException('Birth year must be between 1900 and the current year.');
        }
    }
}
