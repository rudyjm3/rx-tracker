<?php

declare(strict_types=1);

final class MedicationRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activeMedications(): array
    {
        $statement = $this->db->query(
            'SELECT id, name, dose, reminder_time, instructions
             FROM medications
             WHERE active = 1
             ORDER BY reminder_time ASC, name ASC'
        );

        return $statement->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentLogs(int $limit = 8): array
    {
        $statement = $this->db->prepare(
            'SELECT dose_logs.id, dose_logs.taken_at, dose_logs.note, medications.name, medications.dose
             FROM dose_logs
             INNER JOIN medications ON medications.id = dose_logs.medication_id
             ORDER BY dose_logs.taken_at DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * @return array<int, int>
     */
    public function loggedMedicationIdsForDate(string $date): array
    {
        $statement = $this->db->prepare(
            'SELECT DISTINCT dose_logs.medication_id
             FROM dose_logs
             INNER JOIN medications ON medications.id = dose_logs.medication_id
             WHERE DATE(dose_logs.taken_at) = :taken_on
               AND medications.active = 1'
        );
        $statement->execute(['taken_on' => $date]);

        return array_map('intval', array_column($statement->fetchAll(), 'medication_id'));
    }

    public function createMedication(string $name, string $dose, string $reminderTime, string $instructions): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO medications (name, dose, reminder_time, instructions)
             VALUES (:name, :dose, :reminder_time, :instructions)'
        );
        $statement->execute([
            'name' => $name,
            'dose' => $dose,
            'reminder_time' => $reminderTime,
            'instructions' => $instructions,
        ]);
    }

    public function logDose(int $medicationId, string $note): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO dose_logs (medication_id, note)
             VALUES (:medication_id, :note)'
        );
        $statement->execute([
            'medication_id' => $medicationId,
            'note' => $note,
        ]);
    }

    public function deactivateMedication(int $medicationId): void
    {
        $statement = $this->db->prepare('UPDATE medications SET active = 0 WHERE id = :id');
        $statement->execute(['id' => $medicationId]);
    }
}
