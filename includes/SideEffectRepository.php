<?php

declare(strict_types=1);

final class SideEffectRepository
{
    public function __construct(
        private readonly PDO $db,
        private readonly int $userId = 0,
        private readonly ?int $profileId = null
    ) {
        $this->ensureSideEffectsTable();
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

    private function ensureSideEffectsTable(): void
    {
        try {
            $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $this->db->exec(
                    'CREATE TABLE IF NOT EXISTS side_effects (
                        id            INTEGER PRIMARY KEY AUTOINCREMENT,
                        medication_id INTEGER NOT NULL,
                        occurred_date TEXT NOT NULL,
                        description   TEXT NOT NULL,
                        severity      TEXT NOT NULL DEFAULT \'mild\',
                        note          TEXT NOT NULL DEFAULT \'\',
                        created_at    TEXT NOT NULL DEFAULT (datetime(\'now\')),
                        FOREIGN KEY (medication_id) REFERENCES medications(id) ON DELETE CASCADE
                    )'
                );
            } else {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS side_effects (
                        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        medication_id INT UNSIGNED NOT NULL,
                        occurred_date DATE NOT NULL,
                        description   VARCHAR(255) NOT NULL,
                        severity      ENUM('mild','moderate','severe') NOT NULL DEFAULT 'mild',
                        note          VARCHAR(500) NOT NULL DEFAULT '',
                        created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_se_medication_date (medication_id, occurred_date),
                        CONSTRAINT fk_se_medication
                            FOREIGN KEY (medication_id) REFERENCES medications(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB"
                );
            }
        } catch (PDOException) {
            // Table likely already exists; safe to ignore
        }
    }

    public function logSideEffect(
        int $medicationId,
        string $occurredDate,
        string $description,
        string $severity,
        string $note
    ): void {
        // Verify the medication belongs to this user/profile before inserting
        $check = $this->db->prepare(
            'SELECT id FROM medications WHERE id = :id AND user_id = :user_id ' . $this->profileSql('')
        );
        $check->execute(array_merge(['id' => $medicationId, 'user_id' => $this->userId], $this->profileParam()));
        if ($check->fetch() === false) {
            throw new RuntimeException('Medication not found.');
        }

        $stmt = $this->db->prepare(
            'INSERT INTO side_effects (medication_id, occurred_date, description, severity, note)
             VALUES (:medication_id, :occurred_date, :description, :severity, :note)'
        );
        $stmt->execute([
            'medication_id' => $medicationId,
            'occurred_date' => $occurredDate,
            'description'   => $description,
            'severity'      => in_array($severity, ['mild', 'moderate', 'severe'], true) ? $severity : 'mild',
            'note'          => $note,
        ]);
    }

    public function sideEffectsForDateRange(string $startDate, string $endDate): array
    {
        $stmt = $this->db->prepare(
            'SELECT se.id, se.occurred_date, se.description, se.severity, se.note, se.created_at,
                    m.name AS medication_name, m.id AS medication_id
             FROM side_effects se
             INNER JOIN medications m ON m.id = se.medication_id
             WHERE m.user_id = :user_id
               ' . $this->profileSql('m') . '
               AND se.occurred_date BETWEEN :start_date AND :end_date
             ORDER BY se.occurred_date ASC, se.created_at ASC'
        );
        $stmt->execute(array_merge(
            ['user_id' => $this->userId, 'start_date' => $startDate, 'end_date' => $endDate],
            $this->profileParam()
        ));

        return $stmt->fetchAll();
    }

    public function sideEffectsForMedication(int $medicationId): array
    {
        $stmt = $this->db->prepare(
            'SELECT se.id, se.occurred_date, se.description, se.severity, se.note, se.created_at
             FROM side_effects se
             INNER JOIN medications m ON m.id = se.medication_id
             WHERE se.medication_id = :medication_id
               AND m.user_id = :user_id
               ' . $this->profileSql('m') . '
             ORDER BY se.occurred_date DESC, se.created_at DESC'
        );
        $stmt->execute(array_merge(
            ['medication_id' => $medicationId, 'user_id' => $this->userId],
            $this->profileParam()
        ));

        return $stmt->fetchAll();
    }

    public function deleteSideEffect(int $id): void
    {
        $stmt = $this->db->prepare(
            'DELETE se FROM side_effects se
             INNER JOIN medications m ON m.id = se.medication_id
             WHERE se.id = :id AND m.user_id = :user_id ' . $this->profileSql('m')
        );
        $stmt->execute(array_merge(['id' => $id, 'user_id' => $this->userId], $this->profileParam()));
    }
}
