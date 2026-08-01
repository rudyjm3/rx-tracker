<?php

declare(strict_types=1);

final class ScheduleRepository
{

    private const MEDICATION_COLUMNS = 'id, name, dose, start_date, created_at, instructions, schedule_mode, time_format, interval_hours, first_dose_time, as_needed, starting_pill_count, pill_count, low_supply_threshold, track_dose_feedback, feedback_type, set_id, medication_type, dose_amount, dose_unit, dose_form, inventory_type, inventory_unit, starting_quantity, current_quantity, quantity_per_dose, setup_status, dashboard_enabled, reminders_enabled, adherence_enabled, inventory_enabled, tracking_started_at, inventory_count_method, inventory_as_of';

    public function __construct(
        private readonly PDO $db,
        private readonly int $userId,
        private readonly ?int $profileId,
        private readonly InventoryRepository $inventoryRepo,
        private readonly MedicationGroupRepository $groupRepo
    ) {
    }

    public function findMedication(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT ' . self::MEDICATION_COLUMNS . ' FROM medications WHERE id = :id AND user_id = :user_id ' . $this->profileSql('') . ' AND active = 1'
        );
        $statement->execute(array_merge(['id' => $id, 'user_id' => $this->userId], $this->profileParam()));
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        $row['times']      = $this->scheduleTimesForMedication($id);
        $row['time_doses'] = $this->scheduleTimeDosesForMedication($id);

        return $row;
    }

    private function medicationById(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::MEDICATION_COLUMNS . ' FROM medications WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $med = $stmt->fetch();
        if ($med === false) {
            throw new RuntimeException('Medication not found.');
        }
        $med['times'] = $this->scheduleTimesForMedication($id);
        return $med;
    }

    private function recordStatusEvent(int $medicationId, string $event, string $reason = '', string $comment = ''): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO medication_status_events (medication_id, event, reason, comment) VALUES (:medication_id, :event, :reason, :comment)'
        );
        $statement->execute([
            'medication_id' => $medicationId,
            'event'         => $event,
            'reason'        => $reason,
            'comment'       => $comment,
        ]);
    }

    public function replaceScheduleTimes(int $medicationId, array $doseTimes, array $doseQtys = []): void
    {
        $delete = $this->db->prepare('DELETE FROM medication_schedule_times WHERE medication_id = :medication_id');
        $delete->execute(['medication_id' => $medicationId]);
        if ($doseTimes === []) {
            return;
        }
        $insert = $this->db->prepare(
            'INSERT INTO medication_schedule_times (medication_id, reminder_time, quantity_per_dose)
             VALUES (:medication_id, :reminder_time, :quantity_per_dose)'
        );
        foreach ($doseTimes as $i => $time) {
            $rawQty = $doseQtys[$i] ?? '';
            $qty = ($rawQty !== '' && (float) $rawQty > 0) ? (float) $rawQty : null;
            $insert->execute(['medication_id' => $medicationId, 'reminder_time' => $time, 'quantity_per_dose' => $qty]);
        }
    }

    public function activeMedications(): array
    {
        $statement = $this->db->prepare(
            'SELECT ' . self::MEDICATION_COLUMNS . ' FROM medications m WHERE m.active = 1 AND m.setup_status = \'active\' AND m.user_id = :user_id ' . $this->profileSql() . ' ORDER BY m.sort_order ASC, m.name ASC'
        );
        $statement->execute(array_merge(['user_id' => $this->userId], $this->profileParam()));
        $medications = $statement->fetchAll();
        $ids = array_column($medications, 'id');
        $allTimes     = $this->scheduleTimesByMedicationIds($ids);
        $allTimeDoses = $this->scheduleTimeDosesByMedicationIds($ids);
        $allRefills   = $this->inventoryRepo->lastRefillsByMedicationIds($ids);
        foreach ($medications as &$medication) {
            $medication['times']       = $allTimes[(int) $medication['id']] ?? [];
            $medication['time_doses']  = $allTimeDoses[(int) $medication['id']] ?? [];
            $medication['last_refill'] = $allRefills[(int) $medication['id']] ?? null;
        }
        unset($medication);

        return $medications;
    }

    public function inactiveMedications(): array
    {
        $statement = $this->db->prepare(
            'SELECT ' . self::MEDICATION_COLUMNS . ' FROM medications m WHERE m.active = 0 AND m.user_id = :user_id ' . $this->profileSql() . ' ORDER BY m.name ASC'
        );
        $statement->execute(array_merge(['user_id' => $this->userId], $this->profileParam()));
        $medications = $statement->fetchAll();
        $ids = array_column($medications, 'id');
        $allTimes     = $this->scheduleTimesByMedicationIds($ids);
        $allTimeDoses = $this->scheduleTimeDosesByMedicationIds($ids);
        $allEvents    = $this->statusEventsByMedicationIds($ids);
        foreach ($medications as &$medication) {
            $medication['times']      = $allTimes[(int) $medication['id']] ?? [];
            $medication['time_doses'] = $allTimeDoses[(int) $medication['id']] ?? [];
            $this->attachStatusEvents($medication, $allEvents);
        }
        unset($medication);

        return $medications;
    }

    private function attachStatusEvents(array &$medication, array $eventsByMedId): void
    {
        $events = $eventsByMedId[(int) $medication['id']] ?? [];
        $medication['status_events'] = $events;
        $medication['last_discontinued'] = null;
        foreach ($events as $event) {
            if ((string) $event['event'] === 'discontinued') {
                $medication['last_discontinued'] = $event;
                break;
            }
        }
    }

    public function statusEventsByMedicationIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->db->prepare(
            "SELECT medication_id, event, event_at, reason, comment
             FROM medication_status_events
             WHERE medication_id IN ({$placeholders})
             ORDER BY event_at DESC, id DESC"
        );
        $statement->execute(array_values($ids));
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $result[(int) $row['medication_id']][] = $row;
        }
        return $result;
    }

    public function findInactiveMedication(int $medicationId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT ' . self::MEDICATION_COLUMNS . ' FROM medications m WHERE m.id = :id AND m.active = 0 AND m.user_id = :user_id ' . $this->profileSql()
        );
        $statement->execute(array_merge(['id' => $medicationId, 'user_id' => $this->userId], $this->profileParam()));
        $medication = $statement->fetch();
        if ($medication === false) {
            return null;
        }
        $events = $this->statusEventsByMedicationIds([(int) $medication['id']]);
        $this->attachStatusEvents($medication, $events);
        return $medication;
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

    private function scheduleTimeDosesForMedication(int $medicationId): array
    {
        $statement = $this->db->prepare(
            'SELECT reminder_time, quantity_per_dose
             FROM medication_schedule_times
             WHERE medication_id = :medication_id
             ORDER BY reminder_time ASC'
        );
        $statement->execute(['medication_id' => $medicationId]);
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $time = substr((string) $row['reminder_time'], 0, 5);
            $result[$time] = $row['quantity_per_dose'] !== null ? (float) $row['quantity_per_dose'] : null;
        }
        return $result;
    }

    public function scheduleTimesByMedicationIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->db->prepare(
            "SELECT medication_id, reminder_time
             FROM medication_schedule_times
             WHERE medication_id IN ({$placeholders})
             ORDER BY medication_id ASC, reminder_time ASC"
        );
        $statement->execute(array_values($ids));
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $result[(int) $row['medication_id']][] = substr((string) $row['reminder_time'], 0, 5);
        }
        return $result;
    }

    public function scheduleTimeDosesByMedicationIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->db->prepare(
            "SELECT medication_id, reminder_time, quantity_per_dose
             FROM medication_schedule_times
             WHERE medication_id IN ({$placeholders})
             ORDER BY medication_id ASC, reminder_time ASC"
        );
        $statement->execute(array_values($ids));
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $time = substr((string) $row['reminder_time'], 0, 5);
            $result[(int) $row['medication_id']][$time] = $row['quantity_per_dose'] !== null ? (float) $row['quantity_per_dose'] : null;
        }
        return $result;
    }

    public function recordDoseStatus(int $medicationId, string $date, string $time, string $status, string $note, ?int $painLevel = null, ?int $groupId = null, ?string $customTakenAt = null, ?int $moodLevel = null): void
    {
        if (!in_array($status, ['taken', 'skipped', 'missed'], true)) {
            throw new RuntimeException('Invalid dose status.');
        }

        if ($painLevel !== null && ($painLevel < 1 || $painLevel > 10)) {
            throw new RuntimeException('Pain level must be between 1 and 10.');
        }

        if ($moodLevel !== null && ($moodLevel < 1 || $moodLevel > 10)) {
            throw new RuntimeException('Mood level must be between 1 and 10.');
        }

        $ownerCheck = $this->db->prepare(
            'SELECT id FROM medications WHERE id = :id AND user_id = :user_id ' . $this->profileSql('') . ' AND active = 1'
        );
        $ownerCheck->execute(array_merge(['id' => $medicationId, 'user_id' => $this->userId], $this->profileParam()));
        if (!$ownerCheck->fetchColumn()) {
            throw new RuntimeException('Medication not found.');
        }

        // Needed both to deduct on a transition into 'taken' and to restore the
        // same amount when a taken dose is reverted to skipped/missed.
        if ($groupId !== null) {
            $stmt = $this->db->prepare(
                'SELECT quantity_per_dose FROM medication_group_members
                 WHERE group_id = :group_id AND medication_id = :medication_id LIMIT 1'
            );
            $stmt->execute(['group_id' => $groupId, 'medication_id' => $medicationId]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT quantity_per_dose FROM medication_schedule_times
                 WHERE medication_id = :medication_id AND reminder_time = :reminder_time LIMIT 1'
            );
            $stmt->execute(['medication_id' => $medicationId, 'reminder_time' => $time]);
        }
        $val = $stmt->fetchColumn();
        $doseQtyOverride = ($val !== false && $val !== null) ? (float) $val : null;

        $this->db->beginTransaction();
        try {
            // Fetch existing record first so we can skip the interval check for missed→taken updates.
            $existing = $this->db->prepare(
                'SELECT id, status, deducted_quantity
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

            if ($status === 'taken') {
                $scheduledAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date . ' ' . $time);
                if (!$scheduledAt instanceof DateTimeImmutable) {
                    throw new RuntimeException('Invalid scheduled dose time.');
                }
                // Skip the interval check for snoozed doses — the snooze itself is
                // explicit user intent to take the dose later, so the original slot
                // time should not block it. Also skip for missed→taken retroactive
                // updates, and for backfilling a prior calendar day (e.g. via "Log
                // past dose") — the interval gate exists to stop a live double-dose,
                // not to validate history being entered after the fact.
                $isSnoozed = $this->activePostponeForDose($medicationId, $date, $time) !== null;
                $isMissedRetroactive = is_array($row) && (string) $row['status'] === 'missed';
                $isPastDayBackfill = $date < (new DateTimeImmutable('today'))->format('Y-m-d');
                if (!$isSnoozed && !$isMissedRetroactive && !$isPastDayBackfill) {
                    $this->assertIntervalAllowed($medicationId, $scheduledAt, true);
                }
            }

            $takenAt = $customTakenAt ?? (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

            if (is_array($row)) {
                // Track what this specific log actually removed from inventory so a
                // later revert restores that exact amount, even if quantity_per_dose
                // or a group/slot override has been edited in the meantime.
                $newDeducted = $row['deducted_quantity'];
                if ((string) $row['status'] !== 'taken' && $status === 'taken') {
                    $newDeducted = $this->inventoryRepo->deductInventory($medicationId, $doseQtyOverride);
                } elseif ((string) $row['status'] === 'taken' && $status !== 'taken') {
                    $storedDeducted = $row['deducted_quantity'];
                    // Logs from before deducted_quantity existed fall back to the
                    // currently configured amount.
                    $this->inventoryRepo->restoreInventory($medicationId, $storedDeducted !== null ? (float) $storedDeducted : $doseQtyOverride);
                    $newDeducted = null;
                }
                $update = $this->db->prepare('UPDATE dose_logs SET status = :status, note = :note, pain_level = :pain_level, mood_level = :mood_level, taken_at = :taken_at, deducted_quantity = :deducted_quantity WHERE id = :id');
                $update->execute(['status' => $status, 'note' => $note, 'pain_level' => $painLevel, 'mood_level' => $moodLevel, 'taken_at' => $takenAt, 'deducted_quantity' => $newDeducted, 'id' => (int) $row['id']]);
                if (in_array($status, ['taken', 'skipped', 'missed'], true)) {
                    $this->clearPostponeForDose($medicationId, $date, $time);
                }
            } else {
                $deducted = $status === 'taken' ? $this->inventoryRepo->deductInventory($medicationId, $doseQtyOverride) : null;
                $insert = $this->db->prepare(
                    'INSERT INTO dose_logs (medication_id, scheduled_for_date, scheduled_time, status, note, pain_level, mood_level, taken_at, deducted_quantity)
                     VALUES (:medication_id, :scheduled_for_date, :scheduled_time, :status, :note, :pain_level, :mood_level, :taken_at, :deducted_quantity)'
                );
                $insert->execute([
                    'medication_id' => $medicationId,
                    'scheduled_for_date' => $date,
                    'scheduled_time' => $time,
                    'status' => $status,
                    'note' => $note,
                    'pain_level' => $painLevel,
                    'mood_level' => $moodLevel,
                    'taken_at' => $takenAt,
                    'deducted_quantity' => $deducted,
                ]);
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

    public function logDoseNow(int $medicationId, string $note = '', ?string $scheduledTime = null, bool $takenOnTime = false, ?string $actualTakenTime = null): void
    {
        $ownerCheck = $this->db->prepare(
            'SELECT id FROM medications WHERE id = :id AND user_id = :user_id ' . $this->profileSql('') . ' AND active = 1'
        );
        $ownerCheck->execute(array_merge(['id' => $medicationId, 'user_id' => $this->userId], $this->profileParam()));
        if (!$ownerCheck->fetchColumn()) {
            throw new RuntimeException('Medication not found.');
        }

        $now = new DateTimeImmutable('now');
        $date = $now->format('Y-m-d');

        if ($scheduledTime !== null) {
            $time = $scheduledTime . ':00';
            if ($actualTakenTime !== null) {
                $candidateAt = new DateTimeImmutable($date . ' ' . $actualTakenTime . ':00');
                if ($candidateAt > $now) {
                    throw new RuntimeException('Taken time cannot be in the future.');
                }
                $takenAt = $candidateAt;
            } else {
                $takenAt = $takenOnTime
                    ? new DateTimeImmutable($date . ' ' . $scheduledTime)
                    : $now;
            }
        } else {
            // Map to the closest unlogged scheduled slot so todaySchedule can match it.
            $medication = $this->medicationById($medicationId);
            $slotTime = $this->bestUnloggedSlotTime($medication, $date, $now);
            if ($slotTime === null) {
                throw new RuntimeException('Dose already logged. Please refresh to see the latest history.');
            }
            $time    = $slotTime;
            $takenAt = $now;
        }

        $this->db->beginTransaction();
        try {
            // Check for an existing record first — allows us to update missed slots
            // without triggering the "already logged" error, and to skip the interval
            // check when retroactively logging a missed dose.
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

            if ($row !== false && (string) $row['status'] !== 'missed') {
                throw new RuntimeException('Dose already logged. Please refresh to see the latest history.');
            }

            // Skip interval check when retroactively updating a missed record; only
            // enforce it for fresh insertions. Validate against the actual taken
            // time (not $now) so a backdated late dose is checked against when it
            // was really taken, not the moment it was logged.
            if ($row === false) {
                $this->assertIntervalAllowed($medicationId, $takenAt);
            }

            $deducted = $this->inventoryRepo->deductInventory($medicationId);

            if ($row !== false) {
                $update = $this->db->prepare(
                    'UPDATE dose_logs SET status = :status, note = :note, taken_at = :taken_at, deducted_quantity = :deducted_quantity WHERE id = :id'
                );
                $update->execute([
                    'status'   => 'taken',
                    'note'     => $note !== '' ? $note : 'Logged now',
                    'taken_at' => $takenAt->format('Y-m-d H:i:s'),
                    'deducted_quantity' => $deducted,
                    'id'       => (int) $row['id'],
                ]);
            } else {
                $insert = $this->db->prepare(
                    'INSERT INTO dose_logs (medication_id, scheduled_for_date, scheduled_time, status, note, taken_at, deducted_quantity)
                     VALUES (:medication_id, :scheduled_for_date, :scheduled_time, :status, :note, :taken_at, :deducted_quantity)'
                );
                $insert->execute([
                    'medication_id'      => $medicationId,
                    'scheduled_for_date' => $date,
                    'scheduled_time'     => $time,
                    'status'             => 'taken',
                    'note'               => $note !== '' ? $note : 'Logged now',
                    'taken_at'           => $takenAt->format('Y-m-d H:i:s'),
                    'deducted_quantity'  => $deducted,
                ]);
            }

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
        $groupMap = $this->groupRepo->medicationGroupMap();
        $schedule = [];

        foreach ($medications as $medication) {
            $times = $this->timesForDate($medication);
            $medGroupsByTime = $groupMap[(int) $medication['id']] ?? [];
            $timeDoses = $medication['time_doses'] ?? [];
            $trackingStartedAt = isset($medication['tracking_started_at']) && $medication['tracking_started_at'] !== null
                ? new DateTimeImmutable((string) $medication['tracking_started_at'])
                : null;
            foreach ($times as $time) {
                // Skip slots that occurred before tracking was activated for this medication
                if ($trackingStartedAt !== null) {
                    $slotDt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . $time);
                    if ($slotDt instanceof DateTimeImmutable && $slotDt < $trackingStartedAt) {
                        continue;
                    }
                }
                $key = (int) $medication['id'] . '|' . $time;
                $log = $logs[$key] ?? null;
                $medGroup = $medGroupsByTime[$time] ?? null;
                $schedule[] = [
                    'medication_id' => (int) $medication['id'],
                    'name' => (string) $medication['name'],
                    'medication_type' => (string) ($medication['medication_type'] ?? 'prescription'),
                    'dose' => $medication['dose'] ?? '',
                    'dose_amount' => $medication['dose_amount'],
                    'dose_unit' => $medication['dose_unit'],
                    'dose_form' => $medication['dose_form'],
                    'instructions' => (string) $medication['instructions'],
                    'starting_pill_count' => (int) $medication['starting_pill_count'],
                    'pill_count' => (int) $medication['pill_count'],
                    'low_supply_threshold' => (int) $medication['low_supply_threshold'],
                    'as_needed' => (int) $medication['as_needed'] === 1,
                    'adherence_enabled' => (bool) ($medication['adherence_enabled'] ?? true),
                    'track_dose_feedback' => (int) $medication['track_dose_feedback'] === 1,
                    'feedback_type' => (string) ($medication['feedback_type'] ?? 'none'),
                    'reminder_time' => $time,
                    'scheduled_for_date' => $date,
                    'scheduled_time' => $time . ':00',
                    'status' => $log['status'] ?? null,
                    'note' => $log['note'] ?? '',
                    'taken_at' => $log['taken_at'] ?? null,
                    'postponed_until' => $postpones[$key] ?? null,
                    'group_id' => $medGroup !== null ? (int) $medGroup['group_id'] : null,
                    'group_name' => $medGroup !== null ? (string) $medGroup['group_name'] : null,
                    'slot_qty_override' => array_key_exists($time, $timeDoses) ? $timeDoses[$time] : null,
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
            'SELECT dose_logs.scheduled_for_date, dose_logs.status, COUNT(*) AS count
             FROM dose_logs
             INNER JOIN medications ON medications.id = dose_logs.medication_id
             WHERE medications.user_id = :user_id
               ' . $this->profileSql('medications') . '
               AND dose_logs.scheduled_for_date BETWEEN :month_start AND :month_end
             GROUP BY dose_logs.scheduled_for_date, dose_logs.status'
        );
        $statement->execute(array_merge(['user_id' => $this->userId, 'month_start' => $monthStart, 'month_end' => $monthEnd], $this->profileParam()));

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

    public function calendarLogsForMonth(string $start, string $end): array
    {
        $statement = $this->db->prepare(
            'SELECT dose_logs.medication_id, dose_logs.status,
                    dose_logs.scheduled_for_date, dose_logs.scheduled_time, dose_logs.taken_at,
                    medications.name, medications.dose_amount, medications.dose_unit, medications.dose_form
             FROM dose_logs
             INNER JOIN medications ON medications.id = dose_logs.medication_id
             WHERE medications.user_id = :user_id
               ' . $this->profileSql('medications') . '
               AND dose_logs.scheduled_for_date BETWEEN :start AND :end
             ORDER BY dose_logs.scheduled_for_date, medications.name, dose_logs.scheduled_time'
        );
        $statement->execute(array_merge(['user_id' => $this->userId, 'start' => $start, 'end' => $end], $this->profileParam()));
        return $statement->fetchAll();
    }

    public function deactivateMedication(int $medicationId, string $reason = '', string $comment = ''): void
    {
        $statement = $this->db->prepare('UPDATE medications SET active = 0 WHERE id = :id AND user_id = :user_id ' . $this->profileSql(''));
        $statement->execute(array_merge(['id' => $medicationId, 'user_id' => $this->userId], $this->profileParam()));
        if ($statement->rowCount() > 0) {
            $this->recordStatusEvent($medicationId, 'discontinued', $reason, $comment);
        }
    }

    public function activateMedication(int $medicationId, string $reason = '', string $comment = ''): void
    {
        $statement = $this->db->prepare('UPDATE medications SET active = 1 WHERE id = :id AND user_id = :user_id ' . $this->profileSql(''));
        $statement->execute(array_merge(['id' => $medicationId, 'user_id' => $this->userId], $this->profileParam()));
        if ($statement->rowCount() > 0) {
            $this->recordStatusEvent($medicationId, 'resumed', $reason, $comment);
        }
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
            if (!(bool) ($row['adherence_enabled'] ?? true)) {
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
                'feedback_type' => (string) ($row['feedback_type'] ?? 'none'),
                'group_id' => $row['group_id'] !== null ? (int) $row['group_id'] : null,
                'group_name' => $row['group_name'] !== null ? (string) $row['group_name'] : null,
            ];
        }

        return $rows;
    }

    private function bestUnloggedSlotTime(array $medication, string $date, DateTimeImmutable $now): ?string
    {
        $slots = $this->timesForDate($medication);
        if ($slots === []) {
            return $now->format('H:i:s');
        }

        $logMap = $this->doseLogMapForDate($date);
        $nowMinutes = (int) $now->format('G') * 60 + (int) $now->format('i');
        $bestTime = null;
        $bestDiff = PHP_INT_MAX;

        foreach ($slots as $slot) {
            $key = (int) $medication['id'] . '|' . $slot;
            $existingLog = $logMap[$key] ?? null;
            if ($existingLog !== null && $existingLog['status'] !== 'missed') {
                continue; // slot already resolved (taken/skipped)
            }
            [$h, $m] = explode(':', $slot);
            $diff = abs((int) $h * 60 + (int) $m - $nowMinutes);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $bestTime = $slot . ':00';
            }
        }

        return $bestTime;
    }

    private function doseLogMapForDate(string $date): array
    {
        $statement = $this->db->prepare(
            'SELECT dl.medication_id, dl.scheduled_time, dl.status, dl.note, dl.pain_level, dl.taken_at
             FROM dose_logs dl
             INNER JOIN medications m ON m.id = dl.medication_id
             WHERE dl.scheduled_for_date = :date AND m.user_id = :user_id ' . $this->profileSql('m')
        );
        $statement->execute(array_merge(['date' => $date, 'user_id' => $this->userId], $this->profileParam()));
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
            'SELECT dp.medication_id, dp.scheduled_time, dp.postponed_until
             FROM dose_postpones dp
             INNER JOIN medications m ON m.id = dp.medication_id
             WHERE dp.scheduled_for_date = :date
               AND dp.resolved_at IS NULL
               AND m.user_id = :user_id ' . $this->profileSql('m')
        );
        $statement->execute(array_merge(['date' => $date, 'user_id' => $this->userId], $this->profileParam()));
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
        if ($interval <= 0) {
            return [];
        }

        $firstDose = substr((string) $medication['first_dose_time'], 0, 5);
        if ($firstDose === '') {
            return [];
        }

        // Generate every dose slot within a 24-hour day starting from first_dose_time.
        // This ensures the full day's schedule (and adherence count) is correct
        // regardless of what time of day timesForDate() is called.
        $stepMinutes  = $interval * 60;
        $startMinutes = $this->timeToMinutes($firstDose);
        $times        = [];

        for ($m = $startMinutes; $m < 1440; $m += $stepMinutes) {
            $times[] = $this->minutesToTime($m);
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

    private function latestTakenScheduledAt(int $medicationId): ?DateTimeImmutable
    {
        $statement = $this->db->prepare(
            "SELECT scheduled_for_date, scheduled_time
             FROM dose_logs
             WHERE medication_id = :medication_id
               AND status = 'taken'
             ORDER BY scheduled_for_date DESC, scheduled_time DESC
             LIMIT 1"
        );
        $statement->execute(['medication_id' => $medicationId]);
        $row = $statement->fetch();

        if (!is_array($row) || !isset($row['scheduled_for_date'], $row['scheduled_time'])) {
            return null;
        }

        $scheduledAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $row['scheduled_for_date'] . ' ' . $row['scheduled_time']);

        return $scheduledAt instanceof DateTimeImmutable ? $scheduledAt : null;
    }

    private function assertIntervalAllowed(int $medicationId, DateTimeImmutable $candidate, bool $useScheduledAnchor = false): void
    {
        $medication = $this->findMedication($medicationId);
        if (!is_array($medication) || (string) $medication['schedule_mode'] !== 'interval') {
            return;
        }

        // For scheduled-slot logging (recordDoseStatus), anchor to the previous
        // dose's scheduled slot time so that small real-world click delays don't
        // drift nextAllowed past the next slot's exact scheduled time, blocking it.
        // For PRN/free-log (logDoseNow), anchor to taken_at so that a dose logged
        // late against an earlier slot doesn't make the next dose available
        // sooner than the actual elapsed time allows.
        $lastAnchor = $useScheduledAnchor
            ? $this->latestTakenScheduledAt($medicationId)
            : $this->latestTakenAt($medicationId);
        if (!$lastAnchor instanceof DateTimeImmutable) {
            return;
        }

        $intervalHours = (int) $medication['interval_hours'];
        // When using taken_at, truncate seconds to match H:i slot precision.
        if (!$useScheduledAnchor) {
            $lastAnchor = $lastAnchor->setTime((int) $lastAnchor->format('H'), (int) $lastAnchor->format('i'), 0);
        }
        $nextAllowed = $lastAnchor->modify('+' . $intervalHours . ' hours');
        if ($candidate < $nextAllowed) {
            throw new RuntimeException(
                'Too early for this medication. Next allowed dose is at ' . $nextAllowed->format('g:i A') . '.'
            );
        }
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
