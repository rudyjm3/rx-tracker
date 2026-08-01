<?php

declare(strict_types=1);

final class MedicationNoteRepository
{
    public function __construct(
        private readonly PDO $db,
        private readonly int $userId = 0,
        private readonly ?int $profileId = null
    ) {
    }

    public function getNotesByMedicationId(int $medicationId): array
    {
        $statement = $this->db->prepare(
            'SELECT n.id, n.note, n.created_at, n.updated_at
             FROM medication_notes n
             INNER JOIN medications m ON m.id = n.medication_id
             WHERE n.medication_id = :medication_id AND m.user_id = :user_id ' . $this->profileSql() . '
             ORDER BY n.created_at ASC, n.id ASC'
        );
        $statement->execute(array_merge(
            ['medication_id' => $medicationId, 'user_id' => $this->userId],
            $this->profileParam()
        ));
        return $statement->fetchAll();
    }

    public function addNote(int $medicationId, string $noteText): array
    {
        $statement = $this->db->prepare(
            'INSERT INTO medication_notes (medication_id, note) VALUES (:medication_id, :note)'
        );
        $statement->execute(['medication_id' => $medicationId, 'note' => $noteText]);
        $id = (int) $this->db->lastInsertId();
        $row = $this->db->prepare('SELECT id, note, created_at, updated_at FROM medication_notes WHERE id = :id');
        $row->execute(['id' => $id]);
        return (array) $row->fetch();
    }

    public function updateNote(int $noteId, int $medicationId, string $noteText): string
    {
        $statement = $this->db->prepare(
            'UPDATE medication_notes
             SET note = :note, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND medication_id = :medication_id
               AND EXISTS (
                 SELECT 1 FROM medications
                 WHERE id = :check_med_id AND user_id = :user_id ' . $this->profileSql('') . '
               )'
        );
        $statement->execute(array_merge(
            ['id' => $noteId, 'medication_id' => $medicationId, 'note' => $noteText,
             'user_id' => $this->userId, 'check_med_id' => $medicationId],
            $this->profileParam()
        ));
        $row = $this->db->prepare('SELECT updated_at FROM medication_notes WHERE id = :id');
        $row->execute(['id' => $noteId]);
        return (string) ($row->fetchColumn() ?: '');
    }

    public function deleteNote(int $noteId, int $medicationId): void
    {
        $statement = $this->db->prepare(
            'DELETE FROM medication_notes
             WHERE id = :id
               AND medication_id = :medication_id
               AND EXISTS (
                 SELECT 1 FROM medications
                 WHERE id = :check_med_id AND user_id = :user_id ' . $this->profileSql('') . '
               )'
        );
        $statement->execute(array_merge(
            ['id' => $noteId, 'medication_id' => $medicationId, 'user_id' => $this->userId, 'check_med_id' => $medicationId],
            $this->profileParam()
        ));
    }

    private function profileSql(string $alias = 'm'): string
    {
        $col = $alias !== '' ? "{$alias}.profile_id" : 'profile_id';
        return $this->profileId === null
            ? "AND {$col} IS NULL"
            : "AND {$col} = :profile_id";
    }

    private function profileParam(): array
    {
        return $this->profileId !== null ? ['profile_id' => $this->profileId] : [];
    }
}
