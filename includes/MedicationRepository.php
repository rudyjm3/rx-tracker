<?php

declare(strict_types=1);

final class MedicationRepository
{
    public function __construct(private readonly PDO $db)
    {
        $this->ensureStartingPillCountColumn();
    }

    public function activeMedications(): array
    {
        $statement = $this->db->query(
            "SELECT id, name, dose, instructions, schedule_mode, interval_hours, first_dose_time, as_needed, starting_pill_count, pill_count, low_supply_threshold
             FROM medications
             WHERE active = 1
             ORDER BY name ASC"
        );
        $medications = $statement->fetchAll();
        foreach ($medications as &$medication) {
            $medication['times'] = $this->scheduleTimesForMedication((int) $medication['id']);
        }

        return $medications;
    }

    public function recentLogs(int $limit = 12): array
    {
        $statement = $this->db->prepare(
            'SELECT dose_logs.id, dose_logs.taken_at, dose_logs.note, dose_logs.status,
                    dose_logs.scheduled_for_date, dose_logs.scheduled_time,
                    medications.name, medications.dose
             FROM dose_logs
             INNER JOIN medications ON medications.id = dose_logs.medication_id
             ORDER BY dose_logs.taken_at DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function findMedication(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, name, dose, instructions, schedule_mode, interval_hours, first_dose_time, as_needed, starting_pill_count, pill_count, low_supply_threshold
             FROM medications
             WHERE id = :id AND active = 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        $row['times'] = $this->scheduleTimesForMedication($id);

        return $row;
    }

    public function scheduleTimesForMedication(int $medicationId): array
    {
        $statement = $this->db->prepare(
            'SELECT reminder_time
             FROM medication_schedule_times
             WHERE medication_id = :medication_id
             ORDER BY reminder_time ASC'
        );
        $statement->execute(['medication_id' => $medicationId]);

        return array_map(static fn (string $time): string => substr($time, 0, 5), array_column($statement->fetchAll(), 'reminder_time'));
    }

    public function createMedication(
        string $name,
        string $dose,
        string $instructions,
        string $scheduleMode,
        array $doseTimes,
        ?int $intervalHours,
        ?string $firstDoseTime,
        bool $asNeeded,
        int $pillCount,
        int $lowSupplyThreshold
    ): void {
        $this->validateScheduleInputs($scheduleMode, $doseTimes, $intervalHours, $firstDoseTime);

        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                'INSERT INTO medications (name, dose, instructions, schedule_mode, interval_hours, first_dose_time, as_needed, starting_pill_count, pill_count, low_supply_threshold)
                 VALUES (:name, :dose, :instructions, :schedule_mode, :interval_hours, :first_dose_time, :as_needed, :starting_pill_count, :pill_count, :low_supply_threshold)'
            );
            $statement->execute([
                'name' => $name,
                'dose' => $dose,
                'instructions' => $instructions,
                'schedule_mode' => $scheduleMode,
                'interval_hours' => $intervalHours,
                'first_dose_time' => $firstDoseTime,
                'as_needed' => $asNeeded ? 1 : 0,
                'starting_pill_count' => $pillCount,
                'pill_count' => $pillCount,
                'low_supply_threshold' => $lowSupplyThreshold,
            ]);

            $medicationId = (int) $this->db->lastInsertId();
            $this->replaceScheduleTimes($medicationId, $doseTimes);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function updateMedication(
        int $id,
        string $name,
        string $dose,
        string $instructions,
        string $scheduleMode,
        array $doseTimes,
        ?int $intervalHours,
        ?string $firstDoseTime,
        bool $asNeeded,
        int $pillCount,
        int $lowSupplyThreshold
    ): void {
        $this->validateScheduleInputs($scheduleMode, $doseTimes, $intervalHours, $firstDoseTime);

        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                'UPDATE medications
                 SET name = :name,
                     dose = :dose,
                     instructions = :instructions,
                     schedule_mode = :schedule_mode,
                     interval_hours = :interval_hours,
                     first_dose_time = :first_dose_time,
                     as_needed = :as_needed,
                     starting_pill_count = :starting_pill_count,
                     pill_count = :pill_count,
                     low_supply_threshold = :low_supply_threshold
                 WHERE id = :id'
            );
            $statement->execute([
                'id' => $id,
                'name' => $name,
                'dose' => $dose,
                'instructions' => $instructions,
                'schedule_mode' => $scheduleMode,
                'interval_hours' => $intervalHours,
                'first_dose_time' => $firstDoseTime,
                'as_needed' => $asNeeded ? 1 : 0,
                'starting_pill_count' => $pillCount,
                'pill_count' => $pillCount,
                'low_supply_threshold' => $lowSupplyThreshold,
            ]);

            $this->replaceScheduleTimes($id, $doseTimes);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function recordDoseStatus(int $medicationId, string $date, string $time, string $status, string $note): void
    {
        if (!in_array($status, ['taken', 'skipped', 'missed'], true)) {
            throw new RuntimeException('Invalid dose status.');
        }

        $this->db->beginTransaction();
        try {
            if ($status === 'taken') {
                $candidate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date . ' ' . $time);
                if (!$candidate instanceof DateTimeImmutable) {
                    throw new RuntimeException('Invalid scheduled dose time.');
                }
                $this->assertIntervalAllowed($medicationId, $candidate);
            }

            $existing = $this->db->prepare(
                'SELECT id, status
                 FROM dose_logs
                 WHERE medication_id = :medication_id
                   AND scheduled_for_date = :scheduled_for_date
                   AND scheduled_time = :scheduled_time
                 LIMIT 1'
            );
            $existing->execute([
                'medication_id' => $medicationId,
                'scheduled_for_date' => $date,
                'scheduled_time' => $time,
            ]);
            $row = $existing->fetch();

            if (is_array($row)) {
                $takenAt = $status === 'taken' ? ($date . ' ' . $time) : (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
                $update = $this->db->prepare('UPDATE dose_logs SET status = :status, note = :note, taken_at = :taken_at WHERE id = :id');
                $update->execute(['status' => $status, 'note' => $note, 'taken_at' => $takenAt, 'id' => (int) $row['id']]);
                if ((string) $row['status'] !== 'taken' && $status === 'taken') {
                    $this->deductPillCount($medicationId);
                }
            } else {
                $insert = $this->db->prepare(
                    'INSERT INTO dose_logs (medication_id, scheduled_for_date, scheduled_time, status, note, taken_at)
                     VALUES (:medication_id, :scheduled_for_date, :scheduled_time, :status, :note, :taken_at)'
                );
                $takenAt = $status === 'taken' ? ($date . ' ' . $time) : (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
                $insert->execute([
                    'medication_id' => $medicationId,
                    'scheduled_for_date' => $date,
                    'scheduled_time' => $time,
                    'status' => $status,
                    'note' => $note,
                    'taken_at' => $takenAt,
                ]);
                if ($status === 'taken') {
                    $this->deductPillCount($medicationId);
                }
            }

            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function logDoseNow(int $medicationId, string $note = ''): void
    {
        $now = new DateTimeImmutable('now');
        $this->assertIntervalAllowed($medicationId, $now);

        $date = $now->format('Y-m-d');
        $time = $now->format('H:i:s');

        $this->db->beginTransaction();
        try {
            $existing = $this->db->prepare(
                'SELECT id
                 FROM dose_logs
                 WHERE medication_id = :medication_id
                   AND scheduled_for_date = :scheduled_for_date
                   AND scheduled_time = :scheduled_time
                 LIMIT 1'
            );
            $existing->execute([
                'medication_id' => $medicationId,
                'scheduled_for_date' => $date,
                'scheduled_time' => $time,
            ]);

            if ($existing->fetchColumn() !== false) {
                throw new RuntimeException('Dose already logged at this exact time.');
            }

            $insert = $this->db->prepare(
                'INSERT INTO dose_logs (medication_id, scheduled_for_date, scheduled_time, status, note)
                 VALUES (:medication_id, :scheduled_for_date, :scheduled_time, :status, :note)'
            );
            $insert->execute([
                'medication_id' => $medicationId,
                'scheduled_for_date' => $date,
                'scheduled_time' => $time,
                'status' => 'taken',
                'note' => $note !== '' ? $note : 'Logged now',
            ]);

            $this->deductPillCount($medicationId);
            $this->db->commit();
        } catch (PDOException $exception) {
            $this->db->rollBack();
            if ((string) $exception->getCode() === '23000') {
                throw new RuntimeException('Dose already logged. Please refresh to see the latest history.');
            }
            throw $exception;
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function todaySchedule(string $date): array
    {
        $medications = $this->activeMedications();
        $logs = $this->doseLogMapForDate($date);
        $schedule = [];

        foreach ($medications as $medication) {
            $times = $this->timesForDate($medication);
            foreach ($times as $time) {
                $key = (int) $medication['id'] . '|' . $time;
                $log = $logs[$key] ?? null;
                $schedule[] = [
                    'medication_id' => (int) $medication['id'],
                    'name' => (string) $medication['name'],
                    'dose' => (string) $medication['dose'],
                    'instructions' => (string) $medication['instructions'],
                    'starting_pill_count' => (int) $medication['starting_pill_count'],
                    'pill_count' => (int) $medication['pill_count'],
                    'low_supply_threshold' => (int) $medication['low_supply_threshold'],
                    'as_needed' => (int) $medication['as_needed'] === 1,
                    'reminder_time' => $time,
                    'status' => $log['status'] ?? null,
                    'note' => $log['note'] ?? '',
                ];
            }
        }

        usort($schedule, static fn (array $a, array $b): int => strcmp((string) $a['reminder_time'], (string) $b['reminder_time']) ?: strcmp((string) $a['name'], (string) $b['name']));

        return $schedule;
    }

    public function missedDoseCount(string $date, string $currentTime): int
    {
        $count = 0;
        foreach ($this->todaySchedule($date) as $row) {
            if ((bool) $row['as_needed']) {
                continue;
            }
            if ((string) $row['reminder_time'] < $currentTime && !in_array((string) ($row['status'] ?? ''), ['taken', 'skipped'], true)) {
                $count++;
            }
        }

        return $count;
    }

    public function calendarMarkersForMonth(string $monthStart, string $monthEnd): array
    {
        $statement = $this->db->prepare(
            'SELECT scheduled_for_date, status, COUNT(*) AS count
             FROM dose_logs
             WHERE scheduled_for_date BETWEEN :month_start AND :month_end
             GROUP BY scheduled_for_date, status'
        );
        $statement->execute(['month_start' => $monthStart, 'month_end' => $monthEnd]);

        $markers = [];
        foreach ($statement->fetchAll() as $row) {
            $date = (string) $row['scheduled_for_date'];
            if (!isset($markers[$date])) {
                $markers[$date] = ['taken' => 0, 'skipped' => 0, 'missed' => 0];
            }
            $markers[$date][(string) $row['status']] = (int) $row['count'];
        }

        return $markers;
    }

    public function deactivateMedication(int $medicationId): void
    {
        $statement = $this->db->prepare('UPDATE medications SET active = 0 WHERE id = :id');
        $statement->execute(['id' => $medicationId]);
    }

    private function validateScheduleInputs(string $scheduleMode, array $doseTimes, ?int $intervalHours, ?string $firstDoseTime): void
    {
        if (!in_array($scheduleMode, ['fixed_times', 'interval'], true)) {
            throw new RuntimeException('Invalid schedule mode.');
        }
        if ($scheduleMode === 'fixed_times' && $doseTimes === []) {
            throw new RuntimeException('At least one dose time is required for fixed schedules.');
        }
        if ($scheduleMode === 'interval') {
            if ($intervalHours === null || $intervalHours < 1 || $intervalHours > 24) {
                throw new RuntimeException('Interval hours must be between 1 and 24.');
            }
            if ($firstDoseTime === null || $firstDoseTime === '') {
                throw new RuntimeException('First dose time is required for interval schedules.');
            }
        }
    }

    private function replaceScheduleTimes(int $medicationId, array $doseTimes): void
    {
        $delete = $this->db->prepare('DELETE FROM medication_schedule_times WHERE medication_id = :medication_id');
        $delete->execute(['medication_id' => $medicationId]);
        if ($doseTimes === []) {
            return;
        }
        $insert = $this->db->prepare('INSERT INTO medication_schedule_times (medication_id, reminder_time) VALUES (:medication_id, :reminder_time)');
        foreach ($doseTimes as $time) {
            $insert->execute(['medication_id' => $medicationId, 'reminder_time' => $time]);
        }
    }

    private function doseLogMapForDate(string $date): array
    {
        $statement = $this->db->prepare('SELECT medication_id, scheduled_time, status, note FROM dose_logs WHERE scheduled_for_date = :date');
        $statement->execute(['date' => $date]);
        $map = [];
        foreach ($statement->fetchAll() as $row) {
            $map[(int) $row['medication_id'] . '|' . substr((string) $row['scheduled_time'], 0, 5)] = [
                'status' => (string) $row['status'],
                'note' => (string) $row['note'],
            ];
        }

        return $map;
    }

    private function timesForDate(array $medication): array
    {
        if ((string) $medication['schedule_mode'] === 'fixed_times') {
            return $medication['times'];
        }

        $interval = (int) $medication['interval_hours'];
        $step = $interval * 60;
        $nextDue = $this->nextDueDateTime((int) $medication['id']);

        if ($nextDue === null) {
            $first = substr((string) $medication['first_dose_time'], 0, 5);
            return [$first];
        }

        $times = [$nextDue->format('H:i')];
        for ($i = 1; $i <= 4; $i++) {
            $times[] = $nextDue->modify('+' . ($i * $step) . ' minutes')->format('H:i');
        }

        return $times;
    }

    private function timeToMinutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));
        return ($hour * 60) + $minute;
    }

    private function minutesToTime(int $minutes): string
    {
        $hour = intdiv($minutes, 60);
        $minute = $minutes % 60;
        return sprintf('%02d:%02d', $hour, $minute);
    }

    private function deductPillCount(int $medicationId): void
    {
        $statement = $this->db->prepare('UPDATE medications SET pill_count = CASE WHEN pill_count > 0 THEN pill_count - 1 ELSE 0 END WHERE id = :id');
        $statement->execute(['id' => $medicationId]);
    }

    private function nextDueDateTime(int $medicationId): ?DateTimeImmutable
    {
        $medication = $this->findMedication($medicationId);
        if (!is_array($medication) || (string) $medication['schedule_mode'] !== 'interval') {
            return null;
        }

        $intervalHours = (int) $medication['interval_hours'];
        $lastTaken = $this->latestTakenAt($medicationId);
        if ($lastTaken instanceof DateTimeImmutable) {
            return $lastTaken->modify('+' . $intervalHours . ' hours');
        }

        $firstDose = substr((string) $medication['first_dose_time'], 0, 5);
        if ($firstDose === '') {
            return null;
        }

        $todayBase = new DateTimeImmutable((new DateTimeImmutable('now'))->format('Y-m-d') . ' ' . $firstDose . ':00');
        $now = new DateTimeImmutable('now');
        if ($todayBase >= $now) {
            return $todayBase;
        }

        $stepSeconds = $intervalHours * 3600;
        $delta = $now->getTimestamp() - $todayBase->getTimestamp();
        $steps = (int) floor($delta / $stepSeconds) + 1;

        return $todayBase->modify('+' . ($steps * $intervalHours) . ' hours');
    }

    private function latestTakenAt(int $medicationId): ?DateTimeImmutable
    {
        $statement = $this->db->prepare(
            "SELECT taken_at
             FROM dose_logs
             WHERE medication_id = :medication_id
               AND status = 'taken'
             ORDER BY taken_at DESC
             LIMIT 1"
        );
        $statement->execute(['medication_id' => $medicationId]);
        $value = $statement->fetchColumn();

        if (!is_string($value) || $value === '') {
            return null;
        }

        return new DateTimeImmutable($value);
    }

    private function assertIntervalAllowed(int $medicationId, DateTimeImmutable $candidate): void
    {
        $medication = $this->findMedication($medicationId);
        if (!is_array($medication) || (string) $medication['schedule_mode'] !== 'interval') {
            return;
        }

        $lastTaken = $this->latestTakenAt($medicationId);
        if (!$lastTaken instanceof DateTimeImmutable) {
            return;
        }

        $intervalHours = (int) $medication['interval_hours'];
        $nextAllowed = $lastTaken->modify('+' . $intervalHours . ' hours');
        if ($candidate < $nextAllowed) {
            throw new RuntimeException(
                'Too early for this medication. Next allowed dose is at ' . $nextAllowed->format('g:i A') . '.'
            );
        }
    }

    private function ensureStartingPillCountColumn(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);

        try {
            if ($driver === 'mysql') {
                $check = $this->db->query("SHOW COLUMNS FROM medications LIKE 'starting_pill_count'");
                if ($check !== false && $check->fetchColumn() === false) {
                    $this->db->exec('ALTER TABLE medications ADD COLUMN starting_pill_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER as_needed');
                    $this->db->exec('UPDATE medications SET starting_pill_count = pill_count WHERE starting_pill_count = 0');
                }
                return;
            }

            if ($driver === 'sqlite') {
                $check = $this->db->query("PRAGMA table_info(medications)");
                if ($check === false) {
                    return;
                }
                $hasColumn = false;
                foreach ($check->fetchAll() as $column) {
                    if ((string) ($column['name'] ?? '') === 'starting_pill_count') {
                        $hasColumn = true;
                        break;
                    }
                }
                if (!$hasColumn) {
                    $this->db->exec('ALTER TABLE medications ADD COLUMN starting_pill_count INTEGER NOT NULL DEFAULT 0');
                    $this->db->exec('UPDATE medications SET starting_pill_count = pill_count WHERE starting_pill_count = 0');
                }
            }
        } catch (Throwable) {
            // Keep app booting even if migration fails; normal query errors will surface if unresolved.
        }
    }
}
