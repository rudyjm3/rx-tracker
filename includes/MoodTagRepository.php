<?php

declare(strict_types=1);

final class MoodTagRepository
{
    public function __construct(
        private readonly PDO $db,
        private readonly int $userId = 0,
        private readonly ?int $profileId = null
    ) {
    }

    public function listMoodTags(bool $alwaysShowOnly = false): array
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $whereAlwaysShow = $alwaysShowOnly ? ' AND t.always_show = 1' : '';

        if ($driver === 'mysql') {
            $statement = $this->db->prepare(
                'SELECT t.id, t.name, t.always_show, t.sort_order,
                        (SELECT COUNT(*) FROM standalone_pain_mood_logs s
                          WHERE s.user_id = :user_id_sub AND FIND_IN_SET(t.name, s.tags) > 0) AS entry_count
                 FROM mood_tags t
                 WHERE t.user_id = :user_id' . $whereAlwaysShow . '
                 ORDER BY t.sort_order ASC, t.id ASC'
            );
            $statement->execute(['user_id' => $this->userId, 'user_id_sub' => $this->userId]);
            return $statement->fetchAll();
        }

        // sqlite has no FIND_IN_SET; compute entry counts in PHP instead.
        $tagStatement = $this->db->prepare(
            'SELECT id, name, always_show, sort_order FROM mood_tags
             WHERE user_id = :user_id' . $whereAlwaysShow . '
             ORDER BY sort_order ASC, id ASC'
        );
        $tagStatement->execute(['user_id' => $this->userId]);
        $tags = $tagStatement->fetchAll();

        $logStatement = $this->db->prepare(
            "SELECT tags FROM standalone_pain_mood_logs WHERE user_id = :user_id AND tags != ''"
        );
        $logStatement->execute(['user_id' => $this->userId]);
        $allTagLists = array_map(
            static fn (array $row): array => array_map('trim', explode(',', (string) $row['tags'])),
            $logStatement->fetchAll()
        );

        foreach ($tags as &$tag) {
            $count = 0;
            foreach ($allTagLists as $tagList) {
                if (in_array($tag['name'], $tagList, true)) {
                    $count++;
                }
            }
            $tag['entry_count'] = $count;
        }
        unset($tag);

        return $tags;
    }

    public function addMoodTag(string $name, bool $alwaysShow = true): array
    {
        $name = mb_substr(trim($name), 0, 30);
        if ($name === '') {
            throw new RuntimeException('Tag name cannot be empty.');
        }
        if (str_contains($name, ',')) {
            throw new RuntimeException('Tag names cannot contain commas.');
        }

        $dupCheck = $this->db->prepare(
            'SELECT 1 FROM mood_tags WHERE user_id = :user_id AND LOWER(name) = LOWER(:name) LIMIT 1'
        );
        $dupCheck->execute(['user_id' => $this->userId, 'name' => $name]);
        if ($dupCheck->fetchColumn() !== false) {
            throw new RuntimeException('Tag already exists.');
        }

        $sortStmt = $this->db->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM mood_tags WHERE user_id = :user_id');
        $sortStmt->execute(['user_id' => $this->userId]);
        $sortOrder = (int) $sortStmt->fetchColumn();

        $insert = $this->db->prepare(
            'INSERT INTO mood_tags (user_id, name, always_show, sort_order)
             VALUES (:user_id, :name, :always_show, :sort_order)'
        );
        $insert->execute([
            'user_id'     => $this->userId,
            'name'        => $name,
            'always_show' => $alwaysShow ? 1 : 0,
            'sort_order'  => $sortOrder,
        ]);

        return [
            'id'          => (int) $this->db->lastInsertId(),
            'name'        => $name,
            'always_show' => $alwaysShow ? 1 : 0,
            'sort_order'  => $sortOrder,
            'entry_count' => 0,
        ];
    }

    public function renameMoodTag(int $tagId, string $newName): void
    {
        $newName = mb_substr(trim($newName), 0, 30);
        if ($newName === '') {
            throw new RuntimeException('Tag name cannot be empty.');
        }
        if (str_contains($newName, ',')) {
            throw new RuntimeException('Tag names cannot contain commas.');
        }

        $dupCheck = $this->db->prepare(
            'SELECT 1 FROM mood_tags WHERE user_id = :user_id AND LOWER(name) = LOWER(:name) AND id != :id LIMIT 1'
        );
        $dupCheck->execute(['user_id' => $this->userId, 'name' => $newName, 'id' => $tagId]);
        if ($dupCheck->fetchColumn() !== false) {
            throw new RuntimeException('Tag already exists.');
        }

        $oldNameStmt = $this->db->prepare('SELECT name FROM mood_tags WHERE id = :id AND user_id = :user_id');
        $oldNameStmt->execute(['id' => $tagId, 'user_id' => $this->userId]);
        $oldName = (string) $oldNameStmt->fetchColumn();

        $statement = $this->db->prepare(
            'UPDATE mood_tags SET name = :name WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute(['name' => $newName, 'id' => $tagId, 'user_id' => $this->userId]);

        if ($oldName !== '' && $oldName !== $newName) {
            $this->renameTagInStandaloneLogs($oldName, $newName);
        }
    }

    private function renameTagInStandaloneLogs(string $oldName, string $newName): void
    {
        $rowsStmt = $this->db->prepare(
            "SELECT id, tags FROM standalone_pain_mood_logs WHERE user_id = :user_id AND tags != ''"
        );
        $rowsStmt->execute(['user_id' => $this->userId]);

        $update = $this->db->prepare('UPDATE standalone_pain_mood_logs SET tags = :tags WHERE id = :id');
        foreach ($rowsStmt->fetchAll() as $row) {
            $tagList = array_map('trim', explode(',', (string) $row['tags']));
            $changed = false;
            foreach ($tagList as &$tag) {
                if ($tag === $oldName) {
                    $tag = $newName;
                    $changed = true;
                }
            }
            unset($tag);
            if ($changed) {
                $update->execute(['tags' => implode(',', $tagList), 'id' => $row['id']]);
            }
        }
    }

    public function deleteMoodTag(int $tagId): void
    {
        $statement = $this->db->prepare('DELETE FROM mood_tags WHERE id = :id AND user_id = :user_id');
        $statement->execute(['id' => $tagId, 'user_id' => $this->userId]);
    }

    public function setMoodTagAlwaysShow(int $tagId, bool $alwaysShow): void
    {
        $statement = $this->db->prepare(
            'UPDATE mood_tags SET always_show = :val WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute(['val' => $alwaysShow ? 1 : 0, 'id' => $tagId, 'user_id' => $this->userId]);
    }
}
