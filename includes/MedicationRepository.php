<?php

declare(strict_types=1);

final class MedicationRepository
{
    public function __construct(private readonly PDO $db)
    {
        $this->ensureStartingPillCountColumn();
        $this->ensureTimeFormatColumn();
        $this->ensureSupportTables();
        $this->ensureTrackDoseFeedbackColumn();
        $this->ensurePainLevelColumn();
        $this->ensureSetIdColumn();
        $this->ensureGroupTables();
        $this->ensureRefillsTable();
        $this->ensurePushActionNonceColumn();
        $this->ensureMedicationTypeColumn();
        $this->ensureDoseStructuredColumns();
        $this->ensureInventoryColumns();
    }

    public function activeMedications(): array
    {
        $statement = $this->db->query(
            "SELECT id, name, dose, instructions, schedule_mode, time_format, interval_hours, first_dose_time, as_needed, starting_pill_count, pill_count, low_supply_threshold, track_dose_feedback, set_id,
                    medication_type, dose_amount, dose_unit, dose_form, inventory_type, inventory_unit, starting_quantity, current_quantity, quantity_per_dose
             FROM medications
             WHERE active = 1
             ORDER BY name ASC"
        );
        $medications = $statement->fetchAll();
        foreach ($medications as &$medication) {
            $medication['times'] = $this->scheduleTimesForMedication((int) $medication['id']);
            $medication['last_refill'] = $this->lastRefillForMedication((int) $medication['id']);
        }

        return $medications;
    }

    public function inactiveMedications(): array
    {
        $statement = $this->db->query(
            "SELECT id, name, dose, instructions, schedule_mode, time_format, interval_hours, first_dose_time, as_needed, starting_pill_count, pill_count, low_supply_threshold, track_dose_feedback, set_id,
                    medication_type, dose_amount, dose_unit, dose_form, inventory_type, inventory_unit, starting_quantity, current_quantity, quantity_per_dose
             FROM medications
             WHERE active = 0
             ORDER BY name ASC"
        );
        $medications = $statement->fetchAll();
        foreach ($medications as &$medication) {
            $medication['times'] = $this->scheduleTimesForMedication((int) $medication['id']);
        }

        return $medications;
    }

    public function recentLogs(?string $date = null, int $limit = 12): array
    {
        $sql = 'SELECT dose_logs.id, dose_logs.medication_id, dose_logs.taken_at, dose_logs.note, dose_logs.pain_level, dose_logs.status,
                       dose_logs.scheduled_for_date, dose_logs.scheduled_time,
                       medications.name, medications.dose_amount, medications.dose_unit, medications.as_needed
                FROM dose_logs
                INNER JOIN medications ON medications.id = dose_logs.medication_id';
        if ($date !== null && $date !== '') {
            $sql .= ' WHERE dose_logs.scheduled_for_date = :scheduled_for_date';
        }
        $sql .= ' ORDER BY dose_logs.taken_at DESC LIMIT :limit';
        $statement = $this->db->prepare($sql);
        if ($date !== null && $date !== '') {
            $statement->bindValue(':scheduled_for_date', $date, PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function logsForDateRange(string $startDate, string $endDate): array
    {
        $statement = $this->db->prepare(
            'SELECT dose_logs.id, dose_logs.taken_at, dose_logs.note, dose_logs.pain_level, dose_logs.status,
                    dose_logs.scheduled_for_date, dose_logs.scheduled_time,
                    medications.name, medications.dose
             FROM dose_logs
             INNER JOIN medications ON medications.id = dose_logs.medication_id
             WHERE dose_logs.scheduled_for_date BETWEEN :start_date AND :end_date
             ORDER BY dose_logs.scheduled_for_date DESC, dose_logs.scheduled_time DESC'
        );
        $statement->execute(['start_date' => $startDate, 'end_date' => $endDate]);

        return $statement->fetchAll();
    }

    public function painLevelTrend(int $medicationId, int $days): array
    {
        $startDate = (new DateTimeImmutable("now -$days days"))->format('Y-m-d');
        $statement = $this->db->prepare(
            'SELECT scheduled_for_date AS date, scheduled_time AS time,
                    pain_level, note, status
             FROM dose_logs
             WHERE medication_id = :medication_id
               AND pain_level IS NOT NULL
               AND scheduled_for_date >= :start_date
             ORDER BY scheduled_for_date ASC, scheduled_time ASC'
        );
        $statement->execute(['medication_id' => $medicationId, 'start_date' => $startDate]);

        return $statement->fetchAll();
    }

    public function painLevelTrendForDate(int $medicationId, string $date): array
    {
        $statement = $this->db->prepare(
            'SELECT scheduled_for_date AS date, scheduled_time AS time,
                    pain_level, note, status
             FROM dose_logs
             WHERE medication_id = :medication_id
               AND pain_level IS NOT NULL
               AND scheduled_for_date = :date
             ORDER BY scheduled_time ASC'
        );
        $statement->execute(['medication_id' => $medicationId, 'date' => $date]);

        return $statement->fetchAll();
    }

    public function findMedication(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, name, dose, instructions, schedule_mode, time_format, interval_hours, first_dose_time, as_needed, starting_pill_count, pill_count, low_supply_threshold, track_dose_feedback, set_id,
                    medication_type, dose_amount, dose_unit, dose_form, inventory_type, inventory_unit, starting_quantity, current_quantity, quantity_per_dose
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
        string $instructions,
        string $scheduleMode,
        array $doseTimes,
        ?int $intervalHours,
        ?string $firstDoseTime,
        bool $asNeeded,
        int $lowSupplyThreshold,
        bool $trackDoseFeedback = false,
        string $setId = '',
        string $medicationType = 'prescription',
        ?float $doseAmount = null,
        ?string $doseUnit = null,
        ?string $doseForm = null,
        string $inventoryType = 'pills',
        float $startingQuantity = 0.0,
        float $quantityPerDose = 1.0
    ): void {
        $this->validateScheduleInputs($scheduleMode, $doseTimes, $intervalHours, $firstDoseTime);
        $this->validateMedicationType($medicationType);
        $this->validateInventoryType($inventoryType);

        $inventoryUnit = $this->inventoryUnitFor($inventoryType);

        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                'INSERT INTO medications (name, dose, instructions, schedule_mode, time_format, interval_hours, first_dose_time, as_needed, starting_pill_count, pill_count, low_supply_threshold, track_dose_feedback, set_id,
                                          medication_type, dose_amount, dose_unit, dose_form, inventory_type, inventory_unit, starting_quantity, current_quantity, quantity_per_dose)
                 VALUES (:name, \'\', :instructions, :schedule_mode, :time_format, :interval_hours, :first_dose_time, :as_needed, 0, 0, :low_supply_threshold, :track_dose_feedback, :set_id,
                         :medication_type, :dose_amount, :dose_unit, :dose_form, :inventory_type, :inventory_unit, :starting_quantity, :current_quantity, :quantity_per_dose)'
            );
            $statement->execute([
                'name' => $name,
                'instructions' => $instructions,
                'schedule_mode' => $scheduleMode,
                'time_format' => '12h',
                'interval_hours' => $intervalHours,
                'first_dose_time' => $firstDoseTime,
                'as_needed' => $asNeeded ? 1 : 0,
                'low_supply_threshold' => $lowSupplyThreshold,
                'track_dose_feedback' => $trackDoseFeedback ? 1 : 0,
                'set_id' => $setId,
                'medication_type' => $medicationType,
                'dose_amount' => $doseAmount,
                'dose_unit' => $doseUnit,
                'dose_form' => $doseForm,
                'inventory_type' => $inventoryType,
                'inventory_unit' => $inventoryUnit,
                'starting_quantity' => $startingQuantity,
                'current_quantity' => $startingQuantity,
                'quantity_per_dose' => $quantityPerDose,
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
        string $instructions,
        string $scheduleMode,
        array $doseTimes,
        ?int $intervalHours,
        ?string $firstDoseTime,
        bool $asNeeded,
        int $lowSupplyThreshold,
        bool $trackDoseFeedback = false,
        string $setId = '',
        string $medicationType = 'prescription',
        ?float $doseAmount = null,
        ?string $doseUnit = null,
        ?string $doseForm = null,
        string $inventoryType = 'pills',
        float $startingQuantity = 0.0,
        float $quantityPerDose = 1.0
    ): void {
        $this->validateScheduleInputs($scheduleMode, $doseTimes, $intervalHours, $firstDoseTime);
        $this->validateMedicationType($medicationType);
        $this->validateInventoryType($inventoryType);

        $inventoryUnit = $this->inventoryUnitFor($inventoryType);

        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                'UPDATE medications
                 SET name = :name,
                     instructions = :instructions,
                     schedule_mode = :schedule_mode,
                     time_format = :time_format,
                     interval_hours = :interval_hours,
                     first_dose_time = :first_dose_time,
                     as_needed = :as_needed,
                     starting_pill_count = CASE WHEN NOT EXISTS (
                         SELECT 1 FROM medication_refills WHERE medication_id = :refill_check_id
                     ) THEN :starting_pill_count ELSE starting_pill_count END,
                     low_supply_threshold = :low_supply_threshold,
                     track_dose_feedback = :track_dose_feedback,
                     set_id = :set_id,
                     medication_type = :medication_type,
                     dose_amount = :dose_amount,
                     dose_unit = :dose_unit,
                     dose_form = :dose_form,
                     inventory_type = :inventory_type,
                     inventory_unit = :inventory_unit,
                     starting_quantity = CASE WHEN NOT EXISTS (
                         SELECT 1 FROM medication_refills WHERE medication_id = :refill_check_id2
                     ) THEN :starting_quantity ELSE starting_quantity END,
                     current_quantity = :current_quantity,
                     quantity_per_dose = :quantity_per_dose
                 WHERE id = :id'
            );
            $statement->execute([
                'id' => $id,
                'refill_check_id' => $id,
                'refill_check_id2' => $id,
                'starting_pill_count' => 0,
                'name' => $name,
                'instructions' => $instructions,
                'schedule_mode' => $scheduleMode,
                'time_format' => '12h',
                'interval_hours' => $intervalHours,
                'first_dose_time' => $firstDoseTime,
                'as_needed' => $asNeeded ? 1 : 0,
                'low_supply_threshold' => $lowSupplyThreshold,
                'track_dose_feedback' => $trackDoseFeedback ? 1 : 0,
                'set_id' => $setId,
                'medication_type' => $medicationType,
                'dose_amount' => $doseAmount,
                'dose_unit' => $doseUnit,
                'dose_form' => $doseForm,
                'inventory_type' => $inventoryType,
                'inventory_unit' => $inventoryUnit,
                'starting_quantity' => $startingQuantity,
                'current_quantity' => $startingQuantity,
                'quantity_per_dose' => $quantityPerDose,
            ]);

            $this->replaceScheduleTimes($id, $doseTimes);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function recordDoseStatus(int $medicationId, string $date, string $time, string $status, string $note, ?int $painLevel = null): void
    {
        if (!in_array($status, ['taken', 'skipped', 'missed'], true)) {
            throw new RuntimeException('Invalid dose status.');
        }

        if ($painLevel !== null && ($painLevel < 1 || $painLevel > 10)) {
            throw new RuntimeException('Pain level must be between 1 and 10.');
        }

        $this->db->beginTransaction();
        try {
            if ($status === 'taken') {
                $scheduledAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date . ' ' . $time);
                if (!$scheduledAt instanceof DateTimeImmutable) {
                    throw new RuntimeException('Invalid scheduled dose time.');
                }
                // Skip the interval check for snoozed doses — the snooze itself is
                // explicit user intent to take the dose later, so the original slot
                // time should not block it.
                $isSnoozed = $this->activePostponeForDose($medicationId, $date, $time) !== null;
                if (!$isSnoozed) {
                    $this->assertIntervalAllowed($medicationId, $scheduledAt);
                }
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
                $takenAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
                $update = $this->db->prepare('UPDATE dose_logs SET status = :status, note = :note, pain_level = :pain_level, taken_at = :taken_at WHERE id = :id');
                $update->execute(['status' => $status, 'note' => $note, 'pain_level' => $painLevel, 'taken_at' => $takenAt, 'id' => (int) $row['id']]);
                if ((string) $row['status'] !== 'taken' && $status === 'taken') {
                    $this->deductInventory($medicationId);
                }
                if (in_array($status, ['taken', 'skipped', 'missed'], true)) {
                    $this->clearPostponeForDose($medicationId, $date, $time);
                }
            } else {
                $insert = $this->db->prepare(
                    'INSERT INTO dose_logs (medication_id, scheduled_for_date, scheduled_time, status, note, pain_level, taken_at)
                     VALUES (:medication_id, :scheduled_for_date, :scheduled_time, :status, :note, :pain_level, :taken_at)'
                );
                $takenAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
                $insert->execute([
                    'medication_id' => $medicationId,
                    'scheduled_for_date' => $date,
                    'scheduled_time' => $time,
                    'status' => $status,
                    'note' => $note,
                    'pain_level' => $painLevel,
                    'taken_at' => $takenAt,
                ]);
                if ($status === 'taken') {
                    $this->deductInventory($medicationId);
                }
                if (in_array($status, ['taken', 'skipped', 'missed'], true)) {
                    $this->clearPostponeForDose($medicationId, $date, $time);
                }
            }

            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function logDoseNow(int $medicationId, string $note = '', ?string $scheduledTime = null, bool $takenOnTime = false): void
    {
        $now = new DateTimeImmutable('now');
        $this->assertIntervalAllowed($medicationId, $now);

        $date = $now->format('Y-m-d');

        if ($scheduledTime !== null) {
            $time   = $scheduledTime . ':00';
            $takenAt = $takenOnTime
                ? new DateTimeImmutable($date . ' ' . $scheduledTime)
                : $now;
        } else {
            // Map to the closest unlogged scheduled slot so todaySchedule can match it.
            $medication = $this->medicationById($medicationId);
            $time    = $this->bestUnloggedSlotTime($medication, $date, $now);
            $takenAt = $now;
        }

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
                'INSERT INTO dose_logs (medication_id, scheduled_for_date, scheduled_time, status, note, taken_at)
                 VALUES (:medication_id, :scheduled_for_date, :scheduled_time, :status, :note, :taken_at)'
            );
            $insert->execute([
                'medication_id' => $medicationId,
                'scheduled_for_date' => $date,
                'scheduled_time' => $time,
                'status' => 'taken',
                'note' => $note !== '' ? $note : 'Logged now',
                'taken_at' => $takenAt->format('Y-m-d H:i:s'),
            ]);

            $this->deductInventory($medicationId);
            $this->clearPostponeForDose($medicationId, $date, $time);
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
        $postpones = $this->activePostponesForDate($date);
        $groupMap = $this->medicationGroupMap();
        $schedule = [];

        foreach ($medications as $medication) {
            $times = $this->timesForDate($medication);
            $medGroup = $groupMap[(int) $medication['id']] ?? null;
            foreach ($times as $time) {
                $key = (int) $medication['id'] . '|' . $time;
                $log = $logs[$key] ?? null;
                $schedule[] = [
                    'medication_id' => (int) $medication['id'],
                    'name' => (string) $medication['name'],
                    'dose' => $medication['dose'] ?? '',
                    'dose_amount' => $medication['dose_amount'],
                    'dose_unit' => $medication['dose_unit'],
                    'dose_form' => $medication['dose_form'],
                    'instructions' => (string) $medication['instructions'],
                    'starting_pill_count' => (int) $medication['starting_pill_count'],
                    'pill_count' => (int) $medication['pill_count'],
                    'low_supply_threshold' => (int) $medication['low_supply_threshold'],
                    'as_needed' => (int) $medication['as_needed'] === 1,
                    'track_dose_feedback' => (int) $medication['track_dose_feedback'] === 1,
                    'reminder_time' => $time,
                    'scheduled_for_date' => $date,
                    'scheduled_time' => $time . ':00',
                    'status' => $log['status'] ?? null,
                    'note' => $log['note'] ?? '',
                    'taken_at' => $log['taken_at'] ?? null,
                    'postponed_until' => $postpones[$key] ?? null,
                    'group_id' => $medGroup !== null ? (int) $medGroup['group_id'] : null,
                    'group_name' => $medGroup !== null ? (string) $medGroup['group_name'] : null,
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

    public function activateMedication(int $medicationId): void
    {
        $statement = $this->db->prepare('UPDATE medications SET active = 1 WHERE id = :id');
        $statement->execute(['id' => $medicationId]);
    }

    public function getMissedGraceMinutes(): int
    {
        $statement = $this->db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :key LIMIT 1');
        $statement->execute(['key' => 'missed_grace_minutes']);
        $value = (string) ($statement->fetchColumn() ?: '60');
        $minutes = (int) $value;

        return in_array($minutes, [30, 60], true) ? $minutes : 60;
    }

    public function getSnoozeMinutes(): int
    {
        $statement = $this->db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :key LIMIT 1');
        $statement->execute(['key' => 'snooze_minutes']);
        $value = (string) ($statement->fetchColumn() ?: '15');
        $minutes = (int) $value;

        return in_array($minutes, [5, 10, 15, 30], true) ? $minutes : 15;
    }

    public function setSnoozeMinutes(int $minutes): void
    {
        if (!in_array($minutes, [5, 10, 15, 30], true)) {
            throw new RuntimeException('Snooze duration must be 5, 10, 15, or 30 minutes.');
        }

        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'sqlite'
            ? 'INSERT INTO app_settings (setting_key, setting_value)
               VALUES (:key, :value)
               ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value'
            : 'INSERT INTO app_settings (setting_key, setting_value)
               VALUES (:key, :insert_value)
               ON DUPLICATE KEY UPDATE setting_value = :update_value';
        $statement = $this->db->prepare($sql);
        if ($driver === 'sqlite') {
            $statement->execute(['key' => 'snooze_minutes', 'value' => (string) $minutes]);
            return;
        }
        $statement->execute([
            'key'          => 'snooze_minutes',
            'insert_value' => (string) $minutes,
            'update_value' => (string) $minutes,
        ]);
    }

    public function setMissedGraceMinutes(int $minutes): void
    {
        if (!in_array($minutes, [30, 60], true)) {
            throw new RuntimeException('Grace period must be 30 or 60 minutes.');
        }

        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'sqlite'
            ? 'INSERT INTO app_settings (setting_key, setting_value)
               VALUES (:key, :value)
               ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value'
            : 'INSERT INTO app_settings (setting_key, setting_value)
               VALUES (:key, :insert_value)
               ON DUPLICATE KEY UPDATE setting_value = :update_value';
        $statement = $this->db->prepare($sql);
        if ($driver === 'sqlite') {
            $statement->execute(['key' => 'missed_grace_minutes', 'value' => (string) $minutes]);
            return;
        }
        $statement->execute([
            'key' => 'missed_grace_minutes',
            'insert_value' => (string) $minutes,
            'update_value' => (string) $minutes,
        ]);
    }

    public function postponeDose(int $medicationId, string $scheduledDate, string $scheduledTime, int $delayMinutes): void
    {
        if (!in_array($delayMinutes, [5, 10, 15, 30], true)) {
            throw new RuntimeException('Postpone must be 5, 10, 15, or 30 minutes.');
        }

        if (!DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $scheduledDate . ' ' . $scheduledTime) instanceof DateTimeImmutable) {
            throw new RuntimeException('Invalid scheduled dose time.');
        }

        $postponedUntil = (new DateTimeImmutable('now'))->modify('+' . $delayMinutes . ' minutes')->format('Y-m-d H:i:s');
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'sqlite'
            ? 'INSERT INTO dose_postpones (medication_id, scheduled_for_date, scheduled_time, postponed_until, resolved_at)
               VALUES (:medication_id, :scheduled_for_date, :scheduled_time, :postponed_until, NULL)
               ON CONFLICT(medication_id, scheduled_for_date, scheduled_time)
               DO UPDATE SET postponed_until = excluded.postponed_until, resolved_at = NULL'
            : 'INSERT INTO dose_postpones (medication_id, scheduled_for_date, scheduled_time, postponed_until, resolved_at)
               VALUES (:medication_id, :scheduled_for_date, :scheduled_time, :postponed_until, NULL)
               ON DUPLICATE KEY UPDATE postponed_until = VALUES(postponed_until), resolved_at = NULL';
        $statement = $this->db->prepare($sql);
        $statement->execute([
            'medication_id' => $medicationId,
            'scheduled_for_date' => $scheduledDate,
            'scheduled_time' => $scheduledTime,
            'postponed_until' => $postponedUntil,
        ]);
    }

    public function activePostponeForDose(int $medicationId, string $scheduledDate, string $scheduledTime): ?string
    {
        $statement = $this->db->prepare(
            'SELECT postponed_until
             FROM dose_postpones
             WHERE medication_id = :medication_id
               AND scheduled_for_date = :scheduled_for_date
               AND scheduled_time = :scheduled_time
               AND resolved_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'medication_id' => $medicationId,
            'scheduled_for_date' => $scheduledDate,
            'scheduled_time' => $scheduledTime,
        ]);
        $value = $statement->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function clearPostponeForDose(int $medicationId, string $scheduledDate, string $scheduledTime): void
    {
        $statement = $this->db->prepare(
            'UPDATE dose_postpones
             SET resolved_at = :resolved_at
             WHERE medication_id = :medication_id
               AND scheduled_for_date = :scheduled_for_date
               AND scheduled_time = :scheduled_time
               AND resolved_at IS NULL'
        );
        $statement->execute([
            'resolved_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            'medication_id' => $medicationId,
            'scheduled_for_date' => $scheduledDate,
            'scheduled_time' => $scheduledTime,
        ]);
    }

    public function finalizeMissedDoses(DateTimeImmutable $now, int $graceMinutes): void
    {
        $schedule = $this->todaySchedule($now->format('Y-m-d'));
        foreach ($schedule as $row) {
            if ((bool) $row['as_needed']) {
                continue;
            }
            if (in_array((string) ($row['status'] ?? ''), ['taken', 'skipped', 'missed'], true)) {
                continue;
            }

            $baseDue = DateTimeImmutable::createFromFormat('Y-m-d H:i', $now->format('Y-m-d') . ' ' . (string) $row['reminder_time']);
            if (!$baseDue instanceof DateTimeImmutable) {
                continue;
            }

            $postponedUntil = $row['postponed_until'] ?? null;
            $duePoint = $baseDue;
            if (is_string($postponedUntil) && $postponedUntil !== '') {
                $postponedAt = new DateTimeImmutable($postponedUntil);
                if ($postponedAt > $duePoint) {
                    $duePoint = $postponedAt;
                }
            }
            $cutoff = $duePoint->modify('+' . $graceMinutes . ' minutes');
            if ($now < $cutoff) {
                continue;
            }

            $this->recordDoseStatus(
                (int) $row['medication_id'],
                $now->format('Y-m-d'),
                (string) $row['reminder_time'] . ':00',
                'missed',
                'Auto-marked missed'
            );
            $this->clearPostponeForDose(
                (int) $row['medication_id'],
                $now->format('Y-m-d'),
                (string) $row['reminder_time'] . ':00'
            );
        }
    }

    public function dueReminderItems(DateTimeImmutable $now): array
    {
        $rows = [];
        foreach ($this->todaySchedule($now->format('Y-m-d')) as $row) {
            if (in_array((string) ($row['status'] ?? ''), ['taken', 'skipped', 'missed'], true)) {
                continue;
            }
            $dueAt = (string) ($row['postponed_until'] ?? '');
            if ($dueAt === '') {
                $dueAt = $now->format('Y-m-d') . ' ' . (string) $row['reminder_time'] . ':00';
            }
            $dueTime = new DateTimeImmutable($dueAt);
            if ($dueTime > $now) {
                continue;
            }
            $rows[] = [
                'medication_id' => (int) $row['medication_id'],
                'name' => (string) $row['name'],
                'dose' => formattedDose($row),
                'dose_amount' => $row['dose_amount'],
                'dose_unit' => $row['dose_unit'],
                'reminder_time' => (string) $row['reminder_time'],
                'scheduled_date' => $now->format('Y-m-d'),
                'scheduled_time' => (string) $row['reminder_time'] . ':00',
                'postponed_until' => $row['postponed_until'] ?? null,
                'as_needed' => (bool) $row['as_needed'],
                'track_dose_feedback' => (bool) $row['track_dose_feedback'],
                'group_id' => $row['group_id'] !== null ? (int) $row['group_id'] : null,
                'group_name' => $row['group_name'] !== null ? (string) $row['group_name'] : null,
            ];
        }

        return $rows;
    }

    public function upsertPushSubscription(string $endpoint, ?string $publicKey, ?string $authToken, ?string $userAgent): void
    {
        if ($endpoint === '') {
            throw new RuntimeException('Subscription endpoint is required.');
        }
        if ($publicKey === null || $publicKey === '' || $authToken === null || $authToken === '') {
            throw new RuntimeException('Subscription keys are required.');
        }

        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'sqlite'
            ? 'INSERT INTO push_subscriptions (endpoint, p256dh_key, auth_key, user_agent, created_at, updated_at)
               VALUES (:endpoint, :p256dh_key, :auth_key, :user_agent, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
               ON CONFLICT(endpoint)
               DO UPDATE SET p256dh_key = excluded.p256dh_key, auth_key = excluded.auth_key, user_agent = excluded.user_agent, updated_at = CURRENT_TIMESTAMP'
            : 'INSERT INTO push_subscriptions (endpoint, p256dh_key, auth_key, user_agent)
               VALUES (:endpoint, :p256dh_key, :auth_key, :user_agent)
               ON DUPLICATE KEY UPDATE p256dh_key = VALUES(p256dh_key), auth_key = VALUES(auth_key), user_agent = VALUES(user_agent), updated_at = CURRENT_TIMESTAMP';
        $statement = $this->db->prepare($sql);
        $statement->execute([
            'endpoint' => $endpoint,
            'p256dh_key' => $publicKey,
            'auth_key' => $authToken,
            'user_agent' => $userAgent ?? '',
        ]);
    }

    public function removePushSubscriptionByEndpoint(string $endpoint): void
    {
        if ($endpoint === '') {
            return;
        }
        $statement = $this->db->prepare('DELETE FROM push_subscriptions WHERE endpoint = :endpoint');
        $statement->execute(['endpoint' => $endpoint]);
    }

    public function pushSubscriptions(): array
    {
        $statement = $this->db->query('SELECT endpoint, p256dh_key, auth_key FROM push_subscriptions ORDER BY id ASC');
        return $statement->fetchAll();
    }

    public function markPushSentForReminderItems(array $items, DateTimeImmutable $sentAt): void
    {
        if ($items === []) {
            return;
        }
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'sqlite'
            ? 'INSERT INTO push_delivery_log (medication_id, scheduled_for_date, scheduled_time, sent_at, action_nonce)
               VALUES (:medication_id, :scheduled_for_date, :scheduled_time, :sent_at, :action_nonce)
               ON CONFLICT(medication_id, scheduled_for_date, scheduled_time) DO NOTHING'
            : 'INSERT IGNORE INTO push_delivery_log (medication_id, scheduled_for_date, scheduled_time, sent_at, action_nonce)
               VALUES (:medication_id, :scheduled_for_date, :scheduled_time, :sent_at, :action_nonce)';
        $statement = $this->db->prepare($sql);
        foreach ($items as $item) {
            $statement->execute([
                'medication_id' => (int) ($item['medication_id'] ?? 0),
                'scheduled_for_date' => (string) ($item['scheduled_date'] ?? ''),
                'scheduled_time' => (string) ($item['scheduled_time'] ?? ''),
                'sent_at' => $sentAt->format('Y-m-d H:i:s'),
                'action_nonce' => (string) ($item['_nonce'] ?? ''),
            ]);
        }
    }

    public function clearPushDeliveryLog(int $medicationId, string $scheduledDate, string $scheduledTime): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM push_delivery_log
             WHERE medication_id = :medication_id
               AND scheduled_for_date = :scheduled_for_date
               AND scheduled_time = :scheduled_time'
        );
        $stmt->execute([
            'medication_id' => $medicationId,
            'scheduled_for_date' => $scheduledDate,
            'scheduled_time' => $scheduledTime,
        ]);
    }

    public function lastPushSentAt(): ?string
    {
        try {
            $stmt = $this->db->query('SELECT MAX(sent_at) FROM push_delivery_log');
            $result = $stmt ? $stmt->fetchColumn() : false;
            return (is_string($result) && $result !== '') ? $result : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function findAndConsumePushNonce(string $nonce): ?array
    {
        if ($nonce === '') {
            return null;
        }
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'SELECT medication_id, scheduled_for_date, scheduled_time
                 FROM push_delivery_log
                 WHERE action_nonce = :nonce
                 LIMIT 1'
            );
            $stmt->execute(['nonce' => $nonce]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->db->rollBack();
                return null;
            }
            $clear = $this->db->prepare(
                "UPDATE push_delivery_log SET action_nonce = '' WHERE action_nonce = :nonce"
            );
            $clear->execute(['nonce' => $nonce]);
            $this->db->commit();
            return $row;
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function dueReminderItemsNotYetPushed(DateTimeImmutable $now): array
    {
        $items = $this->dueReminderItems($now);
        if ($items === []) {
            return [];
        }

        $check = $this->db->prepare(
            'SELECT 1
             FROM push_delivery_log
             WHERE medication_id = :medication_id
               AND scheduled_for_date = :scheduled_for_date
               AND scheduled_time = :scheduled_time
             LIMIT 1'
        );
        $unsent = [];
        foreach ($items as $item) {
            $check->execute([
                'medication_id' => (int) $item['medication_id'],
                'scheduled_for_date' => (string) $item['scheduled_date'],
                'scheduled_time' => (string) $item['scheduled_time'],
            ]);
            if ($check->fetchColumn() === false) {
                $unsent[] = $item;
            }
        }

        return $unsent;
    }

    public function logRefill(int $medicationId, string $refillDate, float $amount, string $note): void
    {
        if ($amount <= 0) {
            throw new RuntimeException('Refill amount must be greater than 0.');
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT current_quantity FROM medications WHERE id = :id AND active = 1');
            $stmt->execute(['id' => $medicationId]);
            $current = $stmt->fetchColumn();
            if ($current === false) {
                throw new RuntimeException('Medication not found.');
            }
            $newCount = (float) $current + $amount;

            $update = $this->db->prepare(
                'UPDATE medications SET current_quantity = :current_quantity, starting_quantity = :starting_quantity WHERE id = :id'
            );
            $update->execute([
                'current_quantity' => $newCount,
                'starting_quantity' => $amount,
                'id' => $medicationId,
            ]);

            $insert = $this->db->prepare(
                'INSERT INTO medication_refills (medication_id, refill_date, amount, pills_on_hand, note)
                 VALUES (:medication_id, :refill_date, :amount, :pills_on_hand, :note)'
            );
            $insert->execute([
                'medication_id' => $medicationId,
                'refill_date' => $refillDate,
                'amount' => $amount,
                'pills_on_hand' => $newCount,
                'note' => $note,
            ]);

            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function lastRefillForMedication(int $medicationId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, refill_date, amount, pills_on_hand, note
             FROM medication_refills
             WHERE medication_id = :medication_id
             ORDER BY refill_date DESC, id DESC
             LIMIT 1'
        );
        $statement->execute(['medication_id' => $medicationId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function refillsForMonth(int $medicationId, string $monthStart, string $monthEnd): array
    {
        $statement = $this->db->prepare(
            'SELECT r1.id, r1.refill_date, r1.amount, r1.pills_on_hand, r1.note,
                    (SELECT r2.refill_date FROM medication_refills r2
                     WHERE r2.medication_id = r1.medication_id AND r2.refill_date < r1.refill_date
                     ORDER BY r2.refill_date DESC LIMIT 1) AS prev_refill_date
             FROM medication_refills r1
             WHERE r1.medication_id = :medication_id
               AND r1.refill_date BETWEEN :month_start AND :month_end
             ORDER BY r1.refill_date DESC, r1.id DESC'
        );
        $statement->execute([
            'medication_id' => $medicationId,
            'month_start' => $monthStart,
            'month_end' => $monthEnd,
        ]);
        $rows = $statement->fetchAll();

        foreach ($rows as &$row) {
            if ($row['prev_refill_date'] !== null) {
                $prev = new DateTimeImmutable((string) $row['prev_refill_date']);
                $curr = new DateTimeImmutable((string) $row['refill_date']);
                $row['days_since_prev'] = (int) $prev->diff($curr)->days;
            } else {
                $row['days_since_prev'] = null;
            }
            unset($row['prev_refill_date']);
        }

        return $rows;
    }

    public function refillSummaryStats(int $medicationId, int $year): array
    {
        $yearStart = sprintf('%04d-01-01', $year);
        $yearEnd = sprintf('%04d-12-31', $year);

        $stmt = $this->db->prepare(
            'SELECT refill_date
             FROM medication_refills
             WHERE medication_id = :medication_id
               AND refill_date BETWEEN :year_start AND :year_end
             ORDER BY refill_date ASC'
        );
        $stmt->execute([
            'medication_id' => $medicationId,
            'year_start' => $yearStart,
            'year_end' => $yearEnd,
        ]);
        $rows = $stmt->fetchAll();
        $count = count($rows);

        $avgDays = null;
        if ($count >= 2) {
            $dates = array_map(static fn(array $r): DateTimeImmutable => new DateTimeImmutable((string) $r['refill_date']), $rows);
            $totalDays = 0;
            for ($i = 1; $i < count($dates); $i++) {
                $totalDays += (int) $dates[$i - 1]->diff($dates[$i])->days;
            }
            $avgDays = (int) round($totalDays / ($count - 1));
        }

        return [
            'count' => $count,
            'avg_days' => $avgDays,
            'year' => $year,
        ];
    }

    private function validateMedicationType(string $type): void
    {
        if (!in_array($type, ['prescription', 'otc', 'supplement'], true)) {
            throw new RuntimeException('Invalid medication type.');
        }
    }

    private function validateInventoryType(string $type): void
    {
        if (!in_array($type, ['pills', 'liquid', 'inhaler', 'injection', 'patch', 'drops', 'other'], true)) {
            throw new RuntimeException('Invalid inventory type.');
        }
    }

    private function inventoryUnitFor(string $inventoryType): string
    {
        return match ($inventoryType) {
            'pills'     => 'tablets',
            'liquid'    => 'mL',
            'inhaler'   => 'puffs',
            'injection' => 'units',
            'patch'     => 'patches',
            'drops'     => 'drops',
            default     => 'units',
        };
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

    private function medicationById(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, dose, instructions, schedule_mode, time_format, interval_hours,
                    first_dose_time, as_needed, starting_pill_count, pill_count,
                    low_supply_threshold, track_dose_feedback, set_id,
                    medication_type, dose_amount, dose_unit, dose_form, inventory_type, inventory_unit, starting_quantity, current_quantity, quantity_per_dose
             FROM medications WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $med = $stmt->fetch();
        if ($med === false) {
            throw new RuntimeException('Medication not found.');
        }
        $med['times'] = $this->scheduleTimesForMedication($id);
        return $med;
    }

    private function bestUnloggedSlotTime(array $medication, string $date, DateTimeImmutable $now): string
    {
        $slots = $this->timesForDate($medication);
        $logMap = $this->doseLogMapForDate($date);
        $nowMinutes = (int) $now->format('G') * 60 + (int) $now->format('i');
        $bestTime = null;
        $bestDiff = PHP_INT_MAX;

        foreach ($slots as $slot) {
            $key = (int) $medication['id'] . '|' . $slot;
            if (isset($logMap[$key])) {
                continue; // slot already logged
            }
            [$h, $m] = explode(':', $slot);
            $diff = abs((int) $h * 60 + (int) $m - $nowMinutes);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $bestTime = $slot . ':00';
            }
        }

        return $bestTime ?? $now->format('H:i:s');
    }

    private function doseLogMapForDate(string $date): array
    {
        $statement = $this->db->prepare('SELECT medication_id, scheduled_time, status, note, pain_level, taken_at FROM dose_logs WHERE scheduled_for_date = :date');
        $statement->execute(['date' => $date]);
        $map = [];
        foreach ($statement->fetchAll() as $row) {
            $map[(int) $row['medication_id'] . '|' . substr((string) $row['scheduled_time'], 0, 5)] = [
                'status' => (string) $row['status'],
                'note' => (string) $row['note'],
                'pain_level' => $row['pain_level'] !== null ? (int) $row['pain_level'] : null,
                'taken_at' => $row['taken_at'] !== null ? (string) $row['taken_at'] : null,
            ];
        }

        return $map;
    }

    private function activePostponesForDate(string $date): array
    {
        $statement = $this->db->prepare(
            'SELECT medication_id, scheduled_time, postponed_until
             FROM dose_postpones
             WHERE scheduled_for_date = :date
               AND resolved_at IS NULL'
        );
        $statement->execute(['date' => $date]);
        $map = [];
        foreach ($statement->fetchAll() as $row) {
            $key = (int) $row['medication_id'] . '|' . substr((string) $row['scheduled_time'], 0, 5);
            $map[$key] = (string) $row['postponed_until'];
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

        $windowEnd = $nextDue->modify('+' . (24 * 60 - 1) . ' minutes');
        $times = [$nextDue->format('H:i')];
        for ($i = 1; $i <= 4; $i++) {
            $candidate = $nextDue->modify('+' . ($i * $step) . ' minutes');
            if ($candidate > $windowEnd) {
                break;
            }
            $times[] = $candidate->format('H:i');
        }

        return array_values(array_unique($times));
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

    private function deductInventory(int $medicationId): void
    {
        $this->db->prepare(
            'UPDATE medications
             SET current_quantity = CASE
                 WHEN current_quantity IS NULL OR current_quantity <= 0 THEN 0
                 WHEN current_quantity >= quantity_per_dose THEN current_quantity - quantity_per_dose
                 ELSE 0
             END
             WHERE id = :id'
        )->execute(['id' => $medicationId]);
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
        // Truncate seconds from lastTaken so the interval is minute-precise,
        // matching the H:i slot times produced by timesForDate().
        $lastTakenMinute = $lastTaken->setTime((int) $lastTaken->format('H'), (int) $lastTaken->format('i'), 0);
        $nextAllowed = $lastTakenMinute->modify('+' . $intervalHours . ' hours');
        if ($candidate < $nextAllowed) {
            throw new RuntimeException(
                'Too early for this medication. Next allowed dose is at ' . $nextAllowed->format('g:i A') . '.'
            );
        }
    }

    public function lastInsertedMedicationId(): int
    {
        return (int) $this->db->lastInsertId();
    }

    // ── Medication groups ────────────────────────────────────────────────────────

    public function allGroups(): array
    {
        try {
            $statement = $this->db->query(
                'SELECT id, name, scheduled_time, active FROM medication_groups ORDER BY scheduled_time ASC, name ASC'
            );
            $groups = $statement->fetchAll();
        } catch (Throwable) {
            return [];
        }
        foreach ($groups as &$group) {
            $group['scheduled_time'] = substr((string) $group['scheduled_time'], 0, 5);
            $group['members'] = $this->groupMembers((int) $group['id']);
        }

        return $groups;
    }

    public function findGroup(int $id): ?array
    {
        try {
            $statement = $this->db->prepare(
                'SELECT id, name, scheduled_time, active FROM medication_groups WHERE id = :id LIMIT 1'
            );
            $statement->execute(['id' => $id]);
            $row = $statement->fetch();
        } catch (Throwable) {
            return null;
        }
        if (!is_array($row)) {
            return null;
        }
        $row['scheduled_time'] = substr((string) $row['scheduled_time'], 0, 5);
        $row['members'] = $this->groupMembers($id);

        return $row;
    }

    public function createGroup(string $name, string $scheduledTime): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO medication_groups (name, scheduled_time) VALUES (:name, :scheduled_time)'
        );
        $statement->execute(['name' => $name, 'scheduled_time' => $scheduledTime]);

        return (int) $this->db->lastInsertId();
    }

    public function updateGroup(int $id, string $name, string $scheduledTime): void
    {
        $statement = $this->db->prepare(
            'UPDATE medication_groups SET name = :name, scheduled_time = :scheduled_time WHERE id = :id'
        );
        $statement->execute(['id' => $id, 'name' => $name, 'scheduled_time' => $scheduledTime]);
    }

    public function deleteGroup(int $id): void
    {
        $statement = $this->db->prepare('DELETE FROM medication_groups WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    public function addMedicationToGroup(int $groupId, int $medicationId): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'sqlite'
            ? 'INSERT INTO medication_group_members (group_id, medication_id)
               VALUES (:group_id, :medication_id)
               ON CONFLICT(medication_id) DO UPDATE SET group_id = excluded.group_id'
            : 'INSERT INTO medication_group_members (group_id, medication_id)
               VALUES (:group_id, :medication_id)
               ON DUPLICATE KEY UPDATE group_id = VALUES(group_id)';
        $statement = $this->db->prepare($sql);
        $statement->execute(['group_id' => $groupId, 'medication_id' => $medicationId]);
    }

    public function removeMedicationFromGroup(int $medicationId): void
    {
        $statement = $this->db->prepare(
            'DELETE FROM medication_group_members WHERE medication_id = :medication_id'
        );
        $statement->execute(['medication_id' => $medicationId]);
    }

    public function groupForMedication(int $medicationId): ?array
    {
        try {
            $statement = $this->db->prepare(
                'SELECT g.id, g.name, g.scheduled_time
                 FROM medication_groups g
                 INNER JOIN medication_group_members mgm ON mgm.group_id = g.id
                 WHERE mgm.medication_id = :medication_id
                 LIMIT 1'
            );
            $statement->execute(['medication_id' => $medicationId]);
            $row = $statement->fetch();
        } catch (Throwable) {
            return null;
        }
        if (!is_array($row)) {
            return null;
        }
        $row['scheduled_time'] = substr((string) $row['scheduled_time'], 0, 5);

        return $row;
    }

    public function ungroupedActiveMedications(): array
    {
        try {
            $statement = $this->db->query(
                'SELECT m.id, m.name, m.dose, m.dose_amount, m.dose_unit
                 FROM medications m
                 LEFT JOIN medication_group_members mgm ON mgm.medication_id = m.id
                 WHERE m.active = 1 AND mgm.medication_id IS NULL
                 ORDER BY m.name ASC'
            );
            $rows = $statement->fetchAll();
            foreach ($rows as &$row) {
                $row['dose'] = formattedDose($row);
            }

            return $rows;
        } catch (Throwable) {
            return [];
        }
    }

    private function groupMembers(int $groupId): array
    {
        try {
            $statement = $this->db->prepare(
                'SELECT m.id AS medication_id, m.name, m.dose, m.dose_amount, m.dose_unit, m.track_dose_feedback, mgm.sort_order
                 FROM medications m
                 INNER JOIN medication_group_members mgm ON mgm.medication_id = m.id
                 WHERE mgm.group_id = :group_id AND m.active = 1
                 ORDER BY mgm.sort_order ASC, m.name ASC'
            );
            $statement->execute(['group_id' => $groupId]);

            return $statement->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    private function medicationGroupMap(): array
    {
        try {
            $statement = $this->db->query(
                'SELECT mgm.medication_id, g.id AS group_id, g.name AS group_name
                 FROM medication_group_members mgm
                 INNER JOIN medication_groups g ON g.id = mgm.group_id'
            );
            $map = [];
            foreach ($statement->fetchAll() as $row) {
                $map[(int) $row['medication_id']] = [
                    'group_id' => (int) $row['group_id'],
                    'group_name' => (string) $row['group_name'],
                ];
            }

            return $map;
        } catch (Throwable) {
            return [];
        }
    }

    private function ensureGroupTables(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS medication_groups (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(120) NOT NULL,
                        scheduled_time TIME NOT NULL,
                        active TINYINT(1) NOT NULL DEFAULT 1,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_groups_active_time (active, scheduled_time)
                    ) ENGINE=InnoDB"
                );
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS medication_group_members (
                        group_id INT UNSIGNED NOT NULL,
                        medication_id INT UNSIGNED NOT NULL,
                        sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
                        PRIMARY KEY (group_id, medication_id),
                        UNIQUE KEY uq_medication_one_group (medication_id),
                        CONSTRAINT fk_group_members_group
                            FOREIGN KEY (group_id) REFERENCES medication_groups (id) ON DELETE CASCADE,
                        CONSTRAINT fk_group_members_medication
                            FOREIGN KEY (medication_id) REFERENCES medications (id) ON DELETE CASCADE
                    ) ENGINE=InnoDB"
                );
                return;
            }

            if ($driver === 'sqlite') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS medication_groups (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        name TEXT NOT NULL,
                        scheduled_time TEXT NOT NULL,
                        active INTEGER NOT NULL DEFAULT 1,
                        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
                    )"
                );
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS medication_group_members (
                        group_id INTEGER NOT NULL,
                        medication_id INTEGER NOT NULL,
                        sort_order INTEGER NOT NULL DEFAULT 0,
                        PRIMARY KEY (group_id, medication_id),
                        UNIQUE (medication_id)
                    )"
                );
            }
        } catch (Throwable) {
            // Keep app booting even if table setup fails.
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

    private function ensureTimeFormatColumn(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);

        try {
            if ($driver === 'mysql') {
                $check = $this->db->query("SHOW COLUMNS FROM medications LIKE 'time_format'");
                if ($check !== false && $check->fetchColumn() === false) {
                    $this->db->exec("ALTER TABLE medications ADD COLUMN time_format ENUM('24h', '12h') NOT NULL DEFAULT '12h' AFTER schedule_mode");
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
                    if ((string) ($column['name'] ?? '') === 'time_format') {
                        $hasColumn = true;
                        break;
                    }
                }
                if (!$hasColumn) {
                    $this->db->exec("ALTER TABLE medications ADD COLUMN time_format TEXT NOT NULL DEFAULT '12h'");
                }
            }
        } catch (Throwable) {
            // Keep app booting even if migration fails; normal query errors will surface if unresolved.
        }
    }

    private function ensureSupportTables(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS app_settings (
                        setting_key VARCHAR(120) PRIMARY KEY,
                        setting_value VARCHAR(255) NOT NULL,
                        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB"
                );
                $this->db->exec(
                    "INSERT INTO app_settings (setting_key, setting_value)
                     VALUES ('missed_grace_minutes', '60')
                     ON DUPLICATE KEY UPDATE setting_value = setting_value"
                );
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS dose_postpones (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        medication_id INT UNSIGNED NOT NULL,
                        scheduled_for_date DATE NOT NULL,
                        scheduled_time TIME NOT NULL,
                        postponed_until DATETIME NOT NULL,
                        resolved_at DATETIME NULL,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        UNIQUE KEY uq_postpone_dose (medication_id, scheduled_for_date, scheduled_time),
                        INDEX idx_postpone_due (postponed_until, resolved_at),
                        CONSTRAINT fk_dose_postpones_medication
                            FOREIGN KEY (medication_id) REFERENCES medications (id)
                            ON DELETE CASCADE
                    ) ENGINE=InnoDB"
                );
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS push_subscriptions (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        endpoint TEXT NOT NULL,
                        p256dh_key VARCHAR(255) NOT NULL,
                        auth_key VARCHAR(255) NOT NULL,
                        user_agent VARCHAR(255) NOT NULL DEFAULT '',
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        UNIQUE KEY uq_push_endpoint (endpoint(191))
                    ) ENGINE=InnoDB"
                );
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS push_delivery_log (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        medication_id INT UNSIGNED NOT NULL,
                        scheduled_for_date DATE NOT NULL,
                        scheduled_time TIME NOT NULL,
                        sent_at DATETIME NOT NULL,
                        action_nonce VARCHAR(64) NOT NULL DEFAULT '',
                        UNIQUE KEY uq_push_delivery (medication_id, scheduled_for_date, scheduled_time),
                        INDEX idx_push_nonce (action_nonce(32)),
                        CONSTRAINT fk_push_delivery_medication
                            FOREIGN KEY (medication_id) REFERENCES medications (id)
                            ON DELETE CASCADE
                    ) ENGINE=InnoDB"
                );
                return;
            }

            if ($driver === 'sqlite') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS app_settings (
                        setting_key TEXT PRIMARY KEY,
                        setting_value TEXT NOT NULL,
                        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
                    )"
                );
                $this->db->exec(
                    "INSERT INTO app_settings (setting_key, setting_value)
                     VALUES ('missed_grace_minutes', '60')
                     ON CONFLICT(setting_key) DO NOTHING"
                );
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS dose_postpones (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        medication_id INTEGER NOT NULL,
                        scheduled_for_date TEXT NOT NULL,
                        scheduled_time TEXT NOT NULL,
                        postponed_until TEXT NOT NULL,
                        resolved_at TEXT NULL,
                        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE (medication_id, scheduled_for_date, scheduled_time)
                    )"
                );
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS push_subscriptions (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        endpoint TEXT NOT NULL UNIQUE,
                        p256dh_key TEXT NOT NULL,
                        auth_key TEXT NOT NULL,
                        user_agent TEXT NOT NULL DEFAULT '',
                        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
                    )"
                );
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS push_delivery_log (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        medication_id INTEGER NOT NULL,
                        scheduled_for_date TEXT NOT NULL,
                        scheduled_time TEXT NOT NULL,
                        sent_at TEXT NOT NULL,
                        action_nonce TEXT NOT NULL DEFAULT '',
                        UNIQUE (medication_id, scheduled_for_date, scheduled_time)
                    )"
                );
            }
        } catch (Throwable) {
            // Keep app booting even if table setup fails; runtime errors will surface if unresolved.
        }
    }

    private function ensureTrackDoseFeedbackColumn(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $check = $this->db->query("SHOW COLUMNS FROM medications LIKE 'track_dose_feedback'");
                if ($check !== false && $check->fetchColumn() === false) {
                    $this->db->exec('ALTER TABLE medications ADD COLUMN track_dose_feedback TINYINT(1) NOT NULL DEFAULT 0');
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
                    if ((string) ($column['name'] ?? '') === 'track_dose_feedback') {
                        $hasColumn = true;
                        break;
                    }
                }
                if (!$hasColumn) {
                    $this->db->exec('ALTER TABLE medications ADD COLUMN track_dose_feedback INTEGER NOT NULL DEFAULT 0');
                }
            }
        } catch (Throwable) {
            // Keep app booting even if migration fails; normal query errors will surface if unresolved.
        }
    }

    private function ensurePainLevelColumn(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $check = $this->db->query("SHOW COLUMNS FROM dose_logs LIKE 'pain_level'");
                if ($check !== false && $check->fetchColumn() === false) {
                    $this->db->exec('ALTER TABLE dose_logs ADD COLUMN pain_level TINYINT UNSIGNED NULL');
                }
                return;
            }
            if ($driver === 'sqlite') {
                $check = $this->db->query("PRAGMA table_info(dose_logs)");
                if ($check === false) {
                    return;
                }
                $hasColumn = false;
                foreach ($check->fetchAll() as $column) {
                    if ((string) ($column['name'] ?? '') === 'pain_level') {
                        $hasColumn = true;
                        break;
                    }
                }
                if (!$hasColumn) {
                    $this->db->exec('ALTER TABLE dose_logs ADD COLUMN pain_level INTEGER NULL');
                }
            }
        } catch (Throwable) {
            // Keep app booting even if migration fails; normal query errors will surface if unresolved.
        }
    }

    private function ensureSetIdColumn(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $check = $this->db->query("SHOW COLUMNS FROM medications LIKE 'set_id'");
                if ($check !== false && $check->fetchColumn() === false) {
                    $this->db->exec("ALTER TABLE medications ADD COLUMN set_id VARCHAR(64) NOT NULL DEFAULT ''");
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
                    if ((string) ($column['name'] ?? '') === 'set_id') {
                        $hasColumn = true;
                        break;
                    }
                }
                if (!$hasColumn) {
                    $this->db->exec("ALTER TABLE medications ADD COLUMN set_id TEXT NOT NULL DEFAULT ''");
                }
            }
        } catch (Throwable) {
            // Keep app booting even if migration fails; normal query errors will surface if unresolved.
        }
    }

    private function ensureRefillsTable(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS medication_refills (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        medication_id INT UNSIGNED NOT NULL,
                        refill_date DATE NOT NULL,
                        amount INT UNSIGNED NOT NULL,
                        pills_on_hand INT UNSIGNED NOT NULL,
                        note VARCHAR(255) NOT NULL DEFAULT '',
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_refills_med_date (medication_id, refill_date),
                        CONSTRAINT fk_refills_medication
                            FOREIGN KEY (medication_id) REFERENCES medications (id)
                            ON DELETE CASCADE
                    ) ENGINE=InnoDB"
                );
                return;
            }
            if ($driver === 'sqlite') {
                $this->db->exec(
                    "CREATE TABLE IF NOT EXISTS medication_refills (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        medication_id INTEGER NOT NULL,
                        refill_date TEXT NOT NULL,
                        amount INTEGER NOT NULL,
                        pills_on_hand INTEGER NOT NULL,
                        note TEXT NOT NULL DEFAULT '',
                        created_at TEXT DEFAULT CURRENT_TIMESTAMP
                    )"
                );
            }
        } catch (Throwable) {
            // Keep app booting even if table setup fails.
        }
    }

    private function ensurePushActionNonceColumn(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $check = $this->db->query("SHOW COLUMNS FROM push_delivery_log LIKE 'action_nonce'");
                if ($check !== false && $check->fetchColumn() === false) {
                    $this->db->exec("ALTER TABLE push_delivery_log ADD COLUMN action_nonce VARCHAR(64) NOT NULL DEFAULT ''");
                    $this->db->exec("ALTER TABLE push_delivery_log ADD INDEX idx_push_nonce (action_nonce(32))");
                }
                return;
            }
            if ($driver === 'sqlite') {
                $check = $this->db->query('PRAGMA table_info(push_delivery_log)');
                if ($check === false) {
                    return;
                }
                $hasColumn = false;
                foreach ($check->fetchAll() as $column) {
                    if ((string) ($column['name'] ?? '') === 'action_nonce') {
                        $hasColumn = true;
                        break;
                    }
                }
                if (!$hasColumn) {
                    $this->db->exec("ALTER TABLE push_delivery_log ADD COLUMN action_nonce TEXT NOT NULL DEFAULT ''");
                }
            }
        } catch (Throwable) {
            // Keep app booting even if migration fails; runtime errors will surface if unresolved.
        }
    }

    private function ensureMedicationTypeColumn(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $check = $this->db->query("SHOW COLUMNS FROM medications LIKE 'medication_type'");
                if ($check !== false && $check->fetchColumn() === false) {
                    $this->db->exec("ALTER TABLE medications ADD COLUMN medication_type ENUM('prescription','otc','supplement') NOT NULL DEFAULT 'prescription'");
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
                    if ((string) ($column['name'] ?? '') === 'medication_type') {
                        $hasColumn = true;
                        break;
                    }
                }
                if (!$hasColumn) {
                    $this->db->exec("ALTER TABLE medications ADD COLUMN medication_type TEXT NOT NULL DEFAULT 'prescription'");
                }
            }
        } catch (Throwable) {
            // Keep app booting even if migration fails.
        }
    }

    private function ensureDoseStructuredColumns(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                foreach (['dose_amount' => 'DECIMAL(10,3) NULL', 'dose_unit' => 'VARCHAR(20) NULL', 'dose_form' => 'VARCHAR(30) NULL'] as $col => $def) {
                    $check = $this->db->query("SHOW COLUMNS FROM medications LIKE '{$col}'");
                    if ($check !== false && $check->fetchColumn() === false) {
                        $this->db->exec("ALTER TABLE medications ADD COLUMN {$col} {$def}");
                    }
                }
                return;
            }
            if ($driver === 'sqlite') {
                $check = $this->db->query("PRAGMA table_info(medications)");
                if ($check === false) {
                    return;
                }
                $existing = array_column($check->fetchAll(), 'name');
                if (!in_array('dose_amount', $existing, true)) {
                    $this->db->exec('ALTER TABLE medications ADD COLUMN dose_amount REAL NULL');
                }
                if (!in_array('dose_unit', $existing, true)) {
                    $this->db->exec('ALTER TABLE medications ADD COLUMN dose_unit TEXT NULL');
                }
                if (!in_array('dose_form', $existing, true)) {
                    $this->db->exec('ALTER TABLE medications ADD COLUMN dose_form TEXT NULL');
                }
            }
        } catch (Throwable) {
            // Keep app booting even if migration fails.
        }
    }

    private function ensureInventoryColumns(): void
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'mysql') {
                $cols = [
                    'inventory_type'   => "VARCHAR(30) NOT NULL DEFAULT 'pills'",
                    'inventory_unit'   => "VARCHAR(20) NOT NULL DEFAULT 'tablets'",
                    'starting_quantity' => 'DECIMAL(10,3) NULL',
                    'current_quantity'  => 'DECIMAL(10,3) NULL',
                    'quantity_per_dose' => 'DECIMAL(10,3) NOT NULL DEFAULT 1.000',
                ];
                foreach ($cols as $col => $def) {
                    $check = $this->db->query("SHOW COLUMNS FROM medications LIKE '{$col}'");
                    if ($check !== false && $check->fetchColumn() === false) {
                        $this->db->exec("ALTER TABLE medications ADD COLUMN {$col} {$def}");
                    }
                }
                $this->db->exec(
                    "UPDATE medications
                     SET current_quantity  = pill_count,
                         starting_quantity = starting_pill_count,
                         inventory_unit    = 'tablets'
                     WHERE current_quantity IS NULL"
                );
                return;
            }
            if ($driver === 'sqlite') {
                $check = $this->db->query("PRAGMA table_info(medications)");
                if ($check === false) {
                    return;
                }
                $existing = array_column($check->fetchAll(), 'name');
                if (!in_array('inventory_type', $existing, true)) {
                    $this->db->exec("ALTER TABLE medications ADD COLUMN inventory_type TEXT NOT NULL DEFAULT 'pills'");
                }
                if (!in_array('inventory_unit', $existing, true)) {
                    $this->db->exec("ALTER TABLE medications ADD COLUMN inventory_unit TEXT NOT NULL DEFAULT 'tablets'");
                }
                if (!in_array('starting_quantity', $existing, true)) {
                    $this->db->exec('ALTER TABLE medications ADD COLUMN starting_quantity REAL NULL');
                }
                if (!in_array('current_quantity', $existing, true)) {
                    $this->db->exec('ALTER TABLE medications ADD COLUMN current_quantity REAL NULL');
                }
                if (!in_array('quantity_per_dose', $existing, true)) {
                    $this->db->exec('ALTER TABLE medications ADD COLUMN quantity_per_dose REAL NOT NULL DEFAULT 1.0');
                }
                $this->db->exec(
                    "UPDATE medications
                     SET current_quantity  = pill_count,
                         starting_quantity = starting_pill_count,
                         inventory_unit    = 'tablets'
                     WHERE current_quantity IS NULL"
                );
            }
        } catch (Throwable) {
            // Keep app booting even if migration fails.
        }
    }
}
