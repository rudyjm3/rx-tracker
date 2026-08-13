<?php

declare(strict_types=1);

require_once __DIR__ . '/SchemaInstaller.php';
require_once __DIR__ . '/StockNotificationRepository.php';
require_once __DIR__ . '/MedicationGroupRepository.php';
require_once __DIR__ . '/InventoryRepository.php';
require_once __DIR__ . '/ScheduleRepository.php';
require_once __DIR__ . '/OnboardingDraftRepository.php';
require_once __DIR__ . '/PushRepository.php';
require_once __DIR__ . '/AdherenceRepository.php';
require_once __DIR__ . '/MoodTagRepository.php';
require_once __DIR__ . '/MedicationNoteRepository.php';
require_once __DIR__ . '/UserSettingsRepository.php';
require_once __DIR__ . '/MedicationDraftRepository.php';

final class MedicationRepository
{
    private readonly SchemaInstaller $schemaInstaller;
    private readonly StockNotificationRepository $stockNotificationRepo;
    private readonly MedicationGroupRepository $groupRepo;
    private readonly InventoryRepository $inventoryRepo;
    private readonly ScheduleRepository $scheduleRepo;
    private readonly OnboardingDraftRepository $onboardingRepo;
    private readonly PushRepository $pushRepo;
    private readonly AdherenceRepository $adherenceRepo;
    private readonly MoodTagRepository $moodTagRepo;
    private readonly MedicationNoteRepository $noteRepo;
    private readonly UserSettingsRepository $settingsRepo;
    private readonly MedicationDraftRepository $medicationDraftRepo;

    public function __construct(
        private readonly PDO $db,
        private readonly int $userId = 0,
        private readonly ?int $profileId = null
    ) {
        $this->schemaInstaller = new SchemaInstaller($db, $userId);
        $this->schemaInstaller->install();

        $this->stockNotificationRepo = new StockNotificationRepository($db, $userId, $profileId);
        $this->groupRepo             = new MedicationGroupRepository($db, $userId, $profileId);
        $this->inventoryRepo         = new InventoryRepository($db, $userId, $profileId, $this->stockNotificationRepo);
        $this->scheduleRepo          = new ScheduleRepository($db, $userId, $profileId, $this->inventoryRepo, $this->groupRepo);
        $this->onboardingRepo        = new OnboardingDraftRepository($db, $userId, $profileId, $this->scheduleRepo);
        $this->pushRepo              = new PushRepository($db, $userId, $profileId, $this->scheduleRepo);
        $this->adherenceRepo         = new AdherenceRepository($db, $userId, $profileId);
        $this->moodTagRepo           = new MoodTagRepository($db, $userId, $profileId);
        $this->noteRepo              = new MedicationNoteRepository($db, $userId, $profileId);
        $this->settingsRepo          = new UserSettingsRepository($db, $userId, $profileId);
        $this->medicationDraftRepo   = new MedicationDraftRepository($db, $userId, $profileId);
    }

    public function listMedicationDrafts(): array
    {
        return $this->medicationDraftRepo->listDrafts();
    }

    public function findMedicationDraft(int $id): ?array
    {
        return $this->medicationDraftRepo->findDraft($id);
    }

    public function saveMedicationDraft(?int $id, array $formData, int $currentStep, int $furthestStep): int
    {
        return $this->medicationDraftRepo->saveDraft($id, $formData, $currentStep, $furthestStep);
    }

    public function deleteMedicationDraft(int $id): void
    {
        $this->medicationDraftRepo->deleteDraft($id);
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
        float $quantityPerDose = 1.0,
        array $doseQtys = [],
        ?string $startDate = null,
        string $feedbackType = 'none',
        ?string $endDate = null
    ): void {
        $this->validateScheduleInputs($scheduleMode, $doseTimes, $intervalHours, $firstDoseTime);
        $this->validateMedicationType($medicationType);
        $this->validateInventoryType($inventoryType);
        $this->validateFeedbackType($feedbackType);

        $inventoryUnit = $this->inventoryUnitFor($inventoryType);
        // Keep feedback_type and track_dose_feedback in sync. If a caller still only
        // passes the legacy boolean (no explicit feedback_type), fall back to 'pain'
        // so the two columns never disagree.
        $effectiveFeedbackType = $feedbackType !== 'none' ? $feedbackType : ($trackDoseFeedback ? 'pain' : 'none');
        $trackDoseFeedbackFlag = $effectiveFeedbackType !== 'none' ? 1 : 0;

        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                'INSERT INTO medications (user_id, profile_id, name, dose, start_date, end_date, tracking_started_at, instructions, schedule_mode, time_format, interval_hours, first_dose_time, as_needed, starting_pill_count, pill_count, low_supply_threshold, track_dose_feedback, feedback_type, set_id,
                                          medication_type, dose_amount, dose_unit, dose_form, inventory_type, inventory_unit, starting_quantity, current_quantity, quantity_per_dose)
                 VALUES (:user_id, :profile_id, :name, \'\', :start_date, :end_date, :tracking_started_at, :instructions, :schedule_mode, :time_format, :interval_hours, :first_dose_time, :as_needed, 0, 0, :low_supply_threshold, :track_dose_feedback, :feedback_type, :set_id,
                         :medication_type, :dose_amount, :dose_unit, :dose_form, :inventory_type, :inventory_unit, :starting_quantity, :current_quantity, :quantity_per_dose)'
            );
            $statement->execute([
                'user_id'    => $this->userId,
                'profile_id' => $this->profileId,
                'name' => $name,
                'start_date' => $startDate ?? date('Y-m-d'),
                'end_date' => $endDate,
                // Only gate on tracking_started_at when the caller explicitly gave a
                // start date. Leaving it null preserves today's behavior for the
                // common case (no start date entered = assume tracking starts now).
                'tracking_started_at' => $startDate !== null ? $startDate . ' 00:00:00' : null,
                'instructions' => $instructions,
                'schedule_mode' => $scheduleMode,
                'time_format' => '12h',
                'interval_hours' => $intervalHours,
                'first_dose_time' => $firstDoseTime,
                'as_needed' => $asNeeded ? 1 : 0,
                'low_supply_threshold' => $lowSupplyThreshold,
                'track_dose_feedback' => $trackDoseFeedbackFlag,
                'feedback_type' => $effectiveFeedbackType,
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
            $this->scheduleRepo->replaceScheduleTimes($medicationId, $doseTimes, $doseQtys);
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
        float $quantityPerDose = 1.0,
        array $doseQtys = [],
        ?string $startDate = null,
        string $feedbackType = 'none'
    ): void {
        $this->validateScheduleInputs($scheduleMode, $doseTimes, $intervalHours, $firstDoseTime);
        $this->validateMedicationType($medicationType);
        $this->validateInventoryType($inventoryType);
        $this->validateFeedbackType($feedbackType);

        $inventoryUnit = $this->inventoryUnitFor($inventoryType);
        // Keep feedback_type and track_dose_feedback in sync. If a caller still only
        // passes the legacy boolean (no explicit feedback_type), fall back to 'pain'
        // so the two columns never disagree.
        $effectiveFeedbackType = $feedbackType !== 'none' ? $feedbackType : ($trackDoseFeedback ? 'pain' : 'none');
        $trackDoseFeedbackFlag = $effectiveFeedbackType !== 'none' ? 1 : 0;

        // Ownership gate: the medications UPDATE below is scoped by user_id, but
        // replaceScheduleTimes() keys only on medication_id. Without this guard a
        // non-owner could wipe/rewrite another user's schedule times.
        if (!$this->groupRepo->medicationBelongsToUser($id)) {
            throw new RuntimeException('Medication not found.');
        }

        $this->db->beginTransaction();
        try {
            // Once a refill has been logged, the refill flow owns starting_quantity,
            // so the edit form must not overwrite it. Independently, only re-baseline
            // current_quantity when the user actually changed the Starting quantity
            // field — a plain save must not reset the dose-deducted count.
            $qtyStmt = $this->db->prepare('SELECT starting_quantity FROM medications WHERE id = :id AND user_id = :user_id ' . $this->profileSql(''));
            $qtyStmt->execute(array_merge(['id' => $id, 'user_id' => $this->userId], $this->profileParam()));
            $storedStartRaw = $qtyStmt->fetchColumn();
            $storedStart = ($storedStartRaw !== false && $storedStartRaw !== null) ? (float) $storedStartRaw : null;

            $refillStmt = $this->db->prepare("SELECT 1 FROM medication_refills WHERE medication_id = :id AND entry_type = 'refill' LIMIT 1");
            $refillStmt->execute(['id' => $id]);
            $canRebase = $refillStmt->fetchColumn() === false;
            $startChanged = $storedStart === null || abs($storedStart - $startingQuantity) > 0.0005;

            // The edit form always posts the medication's current start_date, so
            // without this check every save would collapse a precise
            // tracking_started_at (e.g. set to the exact moment onboarding was
            // activated) down to midnight. Only touch it when the start date
            // itself actually changed.
            $startDateStmt = $this->db->prepare('SELECT start_date FROM medications WHERE id = :id AND user_id = :user_id ' . $this->profileSql(''));
            $startDateStmt->execute(array_merge(['id' => $id, 'user_id' => $this->userId], $this->profileParam()));
            $storedStartDateRaw = $startDateStmt->fetchColumn();
            $storedStartDate = $storedStartDateRaw !== false ? (string) $storedStartDateRaw : null;
            $startDateChanged = $startDate !== null && $startDate !== $storedStartDate;

            $inventorySql = '';
            $inventoryParams = [];
            if ($canRebase) {
                $inventorySql .= ' starting_pill_count = 0, starting_quantity = :starting_quantity,';
                $inventoryParams['starting_quantity'] = $startingQuantity;
                if ($startChanged) {
                    $inventorySql .= ' current_quantity = :current_quantity,';
                    $inventoryParams['current_quantity'] = $startingQuantity;
                }
            }

            $statement = $this->db->prepare(
                'UPDATE medications
                 SET name = :name,
                     start_date = COALESCE(:start_date, start_date),
                     tracking_started_at = COALESCE(:tracking_started_at, tracking_started_at),
                     instructions = :instructions,
                     schedule_mode = :schedule_mode,
                     time_format = :time_format,
                     interval_hours = :interval_hours,
                     first_dose_time = :first_dose_time,
                     as_needed = :as_needed,' . $inventorySql . '
                     low_supply_threshold = :low_supply_threshold,
                     track_dose_feedback = :track_dose_feedback,
                     feedback_type = :feedback_type,
                     set_id = :set_id,
                     medication_type = :medication_type,
                     dose_amount = :dose_amount,
                     dose_unit = :dose_unit,
                     dose_form = :dose_form,
                     inventory_type = :inventory_type,
                     inventory_unit = :inventory_unit,
                     quantity_per_dose = :quantity_per_dose
                 WHERE id = :id AND user_id = :user_id ' . $this->profileSql('')
            );
            $statement->execute(array_merge([
                'id' => $id,
                'user_id' => $this->userId,
                'name' => $name,
                'start_date' => $startDate,
                'tracking_started_at' => $startDateChanged ? $startDate . ' 00:00:00' : null,
                'instructions' => $instructions,
                'schedule_mode' => $scheduleMode,
                'time_format' => '12h',
                'interval_hours' => $intervalHours,
                'first_dose_time' => $firstDoseTime,
                'as_needed' => $asNeeded ? 1 : 0,
                'low_supply_threshold' => $lowSupplyThreshold,
                'track_dose_feedback' => $trackDoseFeedbackFlag,
                'feedback_type' => $effectiveFeedbackType,
                'set_id' => $setId,
                'medication_type' => $medicationType,
                'dose_amount' => $doseAmount,
                'dose_unit' => $doseUnit,
                'dose_form' => $doseForm,
                'inventory_type' => $inventoryType,
                'inventory_unit' => $inventoryUnit,
                'quantity_per_dose' => $quantityPerDose,
            ], $inventoryParams, $this->profileParam()));

            $this->scheduleRepo->replaceScheduleTimes($id, $doseTimes, $doseQtys);

            // If the user pushed the start date into the future after some
            // slots were already auto-marked missed, those auto-marks are now
            // stale (the medication wasn't supposed to be tracked yet).
            // Manually-confirmed misses (no auto-marker note) are left intact.
            if ($startDateChanged) {
                $cleanupStatement = $this->db->prepare(
                    "DELETE FROM dose_logs
                     WHERE medication_id = :id AND status = 'missed' AND note = 'Auto-marked missed'
                       AND scheduled_for_date < :start_date"
                );
                $cleanupStatement->execute(['id' => $id, 'start_date' => $startDate]);
            }

            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }

    public function updateInstructions(int $id, string $instructions): void
    {
        $statement = $this->db->prepare(
            'UPDATE medications
             SET instructions = :instructions
             WHERE id = :id AND user_id = :user_id ' . $this->profileSql('')
        );
        $statement->execute(array_merge([
            'id' => $id,
            'user_id' => $this->userId,
            'instructions' => $instructions,
        ], $this->profileParam()));
    }

    public function recordDoseChange(
        int $medicationId,
        ?float $oldAmount,
        string $oldUnit,
        ?float $newAmount,
        string $newUnit,
        string $comment = ''
    ): void {
        $statement = $this->db->prepare(
            'INSERT INTO medication_dose_changes (medication_id, old_dose_amount, old_dose_unit, new_dose_amount, new_dose_unit, comment)
             VALUES (:medication_id, :old_amount, :old_unit, :new_amount, :new_unit, :comment)'
        );
        $statement->execute([
            'medication_id' => $medicationId,
            'old_amount'    => $oldAmount,
            'old_unit'      => $oldUnit,
            'new_amount'    => $newAmount,
            'new_unit'      => $newUnit,
            'comment'       => $comment,
        ]);
    }

    public function doseChangesByMedicationId(int $medicationId): array
    {
        $statement = $this->db->prepare(
            'SELECT dc.changed_at, dc.old_dose_amount, dc.old_dose_unit, dc.new_dose_amount, dc.new_dose_unit, dc.comment
             FROM medication_dose_changes dc
             INNER JOIN medications m ON m.id = dc.medication_id
             WHERE dc.medication_id = :medication_id AND m.user_id = :user_id ' . $this->profileSql() . '
             ORDER BY dc.changed_at DESC, dc.id DESC'
        );
        $statement->execute(array_merge(['medication_id' => $medicationId, 'user_id' => $this->userId], $this->profileParam()));
        return $statement->fetchAll();
    }

    public function updateDose(int $medicationId, ?float $doseAmount, string $doseUnit): void
    {
        $statement = $this->db->prepare(
            'UPDATE medications SET dose_amount = :dose_amount, dose_unit = :dose_unit
             WHERE id = :id AND user_id = :user_id ' . $this->profileSql('')
        );
        $statement->execute(array_merge([
            'id'          => $medicationId,
            'user_id'     => $this->userId,
            'dose_amount' => $doseAmount,
            'dose_unit'   => $doseUnit,
        ], $this->profileParam()));
    }

    public function doseChangesByMedicationIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->db->prepare(
            "SELECT dc.medication_id, dc.changed_at, dc.old_dose_amount, dc.old_dose_unit,
                    dc.new_dose_amount, dc.new_dose_unit
             FROM medication_dose_changes dc
             WHERE dc.medication_id IN ({$placeholders})
             ORDER BY dc.changed_at DESC, dc.id DESC"
        );
        $statement->execute(array_values($ids));
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $result[(int) $row['medication_id']][] = $row;
        }
        return $result;
    }

    public function doseChangesForDateRange(string $startDate, string $endDate): array
    {
        $statement = $this->db->prepare(
            'SELECT dc.changed_at, dc.old_dose_amount, dc.old_dose_unit,
                    dc.new_dose_amount, dc.new_dose_unit, dc.comment, m.name
             FROM medication_dose_changes dc
             INNER JOIN medications m ON m.id = dc.medication_id
             WHERE m.user_id = :user_id ' . $this->profileSql() . '
               AND dc.changed_at BETWEEN :start_date AND :end_date
             ORDER BY m.name, dc.changed_at'
        );
        $statement->execute(array_merge(
            ['user_id' => $this->userId, 'start_date' => $startDate, 'end_date' => $endDate . ' 23:59:59'],
            $this->profileParam()
        ));
        return $statement->fetchAll();
    }

    private function validateMedicationType(string $type): void
    {
        if (!in_array($type, ['prescription', 'otc', 'supplement'], true)) {
            throw new RuntimeException('Invalid medication type.');
        }
    }

    private function validateFeedbackType(string $type): void
    {
        if (!in_array($type, ['none', 'pain', 'mood', 'both'], true)) {
            throw new RuntimeException('Invalid feedback type.');
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

    public function lastInsertedMedicationId(): int
    {
        return (int) $this->db->lastInsertId();
    }

    public function activeMedications(): array
    {
        return $this->scheduleRepo->activeMedications();
    }

    public function inactiveMedications(): array
    {
        return $this->scheduleRepo->inactiveMedications();
    }

    public function recentLogs(?string $date = null, int $limit = 12): array
    {
        return $this->adherenceRepo->recentLogs($date, $limit);
    }

    public function logsForDateRange(string $startDate, string $endDate): array
    {
        return $this->adherenceRepo->logsForDateRange($startDate, $endDate);
    }

    public function adherenceForDateRange(string $startDate, string $endDate): array
    {
        return $this->adherenceRepo->adherenceForDateRange($startDate, $endDate);
    }

    public function painLevelTrendForRange(int $medicationId, string $startDate, string $endDate): array
    {
        return $this->adherenceRepo->painLevelTrendForRange($medicationId, $startDate, $endDate);
    }

    public function missedAndSkippedForDateRange(string $startDate, string $endDate): array
    {
        return $this->adherenceRepo->missedAndSkippedForDateRange($startDate, $endDate);
    }

    public function dailyDoseSummaryForDateRange(string $startDate, string $endDate): array
    {
        return $this->adherenceRepo->dailyDoseSummaryForDateRange($startDate, $endDate);
    }

    public function painLevelTrend(int $medicationId, int $days): array
    {
        return $this->adherenceRepo->painLevelTrend($medicationId, $days);
    }

    public function painLevelTrendForDate(int $medicationId, string $date): array
    {
        return $this->adherenceRepo->painLevelTrendForDate($medicationId, $date);
    }

    public function moodLevelTrendForRange(int $medicationId, string $startDate, string $endDate): array
    {
        return $this->adherenceRepo->moodLevelTrendForRange($medicationId, $startDate, $endDate);
    }

    public function moodLevelTrend(int $medicationId, int $days): array
    {
        return $this->adherenceRepo->moodLevelTrend($medicationId, $days);
    }

    public function moodLevelTrendForDate(int $medicationId, string $date): array
    {
        return $this->adherenceRepo->moodLevelTrendForDate($medicationId, $date);
    }

    public function painLogHistory(int $medicationId, int $days = 365, ?string $onDate = null): array
    {
        return $this->adherenceRepo->painLogHistory($medicationId, $days, $onDate);
    }

    public function moodLogHistory(int $medicationId, int $days = 365, ?string $onDate = null): array
    {
        return $this->adherenceRepo->moodLogHistory($medicationId, $days, $onDate);
    }

    public function insertStandalonePainMoodLog(
        int $medicationId,
        string $logType,
        ?int $painLevel,
        ?int $moodLevel,
        string $note,
        string $loggedAt = '',
        string $tags = ''
    ): int
    {
        return $this->adherenceRepo->insertStandalonePainMoodLog($medicationId, $logType, $painLevel, $moodLevel, $note, $loggedAt, $tags);
    }

    public function updateDoseLogFeedback(int $logId, ?int $painLevel, ?int $moodLevel, string $note): bool
    {
        return $this->adherenceRepo->updateDoseLogFeedback($logId, $painLevel, $moodLevel, $note);
    }

    public function updateStandaloneLog(int $logId, ?int $painLevel, ?int $moodLevel, string $note): bool
    {
        return $this->adherenceRepo->updateStandaloneLog($logId, $painLevel, $moodLevel, $note);
    }

    public function medicationTracksPain(array $medication): bool
    {
        return $this->adherenceRepo->medicationTracksPain($medication);
    }

    public function medicationTracksMood(array $medication): bool
    {
        return $this->adherenceRepo->medicationTracksMood($medication);
    }

    public function findMedication(int $id): ?array
    {
        return $this->scheduleRepo->findMedication($id);
    }

    public function scheduleTimesForMedication(int $medicationId): array
    {
        return $this->scheduleRepo->scheduleTimesForMedication($medicationId);
    }

    public function recordDoseStatus(int $medicationId, string $date, string $time, string $status, string $note, ?int $painLevel = null, ?int $groupId = null, ?string $customTakenAt = null, ?int $moodLevel = null): int
    {
        return $this->scheduleRepo->recordDoseStatus($medicationId, $date, $time, $status, $note, $painLevel, $groupId, $customTakenAt, $moodLevel);
    }

    public function logDoseNow(int $medicationId, string $note = '', ?string $scheduledTime = null, bool $takenOnTime = false, ?string $actualTakenTime = null): int
    {
        return $this->scheduleRepo->logDoseNow($medicationId, $note, $scheduledTime, $takenOnTime, $actualTakenTime);
    }

    public function revertTakenDose(int $logId): void
    {
        $this->scheduleRepo->revertTakenDose($logId);
    }

    public function deleteDoseLog(int $logId): void
    {
        $this->scheduleRepo->deleteDoseLog($logId);
    }

    public function dateInventoryCrossedZero(int $medicationId): ?string
    {
        return $this->inventoryRepo->dateInventoryCrossedZero($medicationId);
    }

    public function todaySchedule(string $date): array
    {
        return $this->scheduleRepo->todaySchedule($date);
    }

    public function missedDoseCount(string $date, string $currentTime): int
    {
        return $this->scheduleRepo->missedDoseCount($date, $currentTime);
    }

    public function calendarMarkersForMonth(string $monthStart, string $monthEnd): array
    {
        return $this->scheduleRepo->calendarMarkersForMonth($monthStart, $monthEnd);
    }

    public function calendarLogsForMonth(string $start, string $end): array
    {
        return $this->scheduleRepo->calendarLogsForMonth($start, $end);
    }

    public function deactivateMedication(int $medicationId, string $reason = '', string $comment = ''): void
    {
        $this->scheduleRepo->deactivateMedication($medicationId, $reason, $comment);
    }

    public function activateMedication(int $medicationId, string $reason = '', string $comment = ''): void
    {
        $this->scheduleRepo->activateMedication($medicationId, $reason, $comment);
    }

    public function statusEventsByMedicationIds(array $ids): array
    {
        return $this->scheduleRepo->statusEventsByMedicationIds($ids);
    }

    public function findInactiveMedication(int $medicationId): ?array
    {
        return $this->scheduleRepo->findInactiveMedication($medicationId);
    }

    public function getNotesByMedicationId(int $medicationId): array
    {
        return $this->noteRepo->getNotesByMedicationId($medicationId);
    }

    public function addNote(int $medicationId, string $noteText): array
    {
        return $this->noteRepo->addNote($medicationId, $noteText);
    }

    public function updateNote(int $noteId, int $medicationId, string $noteText): string
    {
        return $this->noteRepo->updateNote($noteId, $medicationId, $noteText);
    }

    public function deleteNote(int $noteId, int $medicationId): void
    {
        $this->noteRepo->deleteNote($noteId, $medicationId);
    }

    public function listMoodTags(bool $alwaysShowOnly = false): array
    {
        return $this->moodTagRepo->listMoodTags($alwaysShowOnly);
    }

    public function addMoodTag(string $name, bool $alwaysShow = true): array
    {
        return $this->moodTagRepo->addMoodTag($name, $alwaysShow);
    }

    public function renameMoodTag(int $tagId, string $newName): void
    {
        $this->moodTagRepo->renameMoodTag($tagId, $newName);
    }

    public function deleteMoodTag(int $tagId): void
    {
        $this->moodTagRepo->deleteMoodTag($tagId);
    }

    public function setMoodTagAlwaysShow(int $tagId, bool $alwaysShow): void
    {
        $this->moodTagRepo->setMoodTagAlwaysShow($tagId, $alwaysShow);
    }

    public function getMissedGraceMinutes(): int
    {
        return $this->settingsRepo->getMissedGraceMinutes();
    }

    public function getSnoozeMinutes(): int
    {
        return $this->settingsRepo->getSnoozeMinutes();
    }

    public function setSnoozeMinutes(int $minutes): void
    {
        $this->settingsRepo->setSnoozeMinutes($minutes);
    }

    public function setMissedGraceMinutes(int $minutes): void
    {
        $this->settingsRepo->setMissedGraceMinutes($minutes);
    }

    public function getUserTimezone(): string
    {
        return $this->settingsRepo->getUserTimezone();
    }

    public function setUserTimezone(string $timezone): void
    {
        $this->settingsRepo->setUserTimezone($timezone);
    }

    public function getMoodChartScheme(): string
    {
        return $this->settingsRepo->getMoodChartScheme();
    }

    public function setMoodChartScheme(string $scheme): void
    {
        $this->settingsRepo->setMoodChartScheme($scheme);
    }

    public function postponeDose(int $medicationId, string $scheduledDate, string $scheduledTime, int $delayMinutes): void
    {
        $this->scheduleRepo->postponeDose($medicationId, $scheduledDate, $scheduledTime, $delayMinutes);
    }

    public function activePostponeForDose(int $medicationId, string $scheduledDate, string $scheduledTime): ?string
    {
        return $this->scheduleRepo->activePostponeForDose($medicationId, $scheduledDate, $scheduledTime);
    }

    public function clearPostponeForDose(int $medicationId, string $scheduledDate, string $scheduledTime): void
    {
        $this->scheduleRepo->clearPostponeForDose($medicationId, $scheduledDate, $scheduledTime);
    }

    public function finalizeMissedDoses(DateTimeImmutable $now, int $graceMinutes): void
    {
        $this->scheduleRepo->finalizeMissedDoses($now, $graceMinutes);
    }

    public function backfillMissedDosesForDates(array $dates, DateTimeImmutable $now, int $graceMinutes): void
    {
        $this->scheduleRepo->backfillMissedDosesForDates($dates, $now, $graceMinutes);
    }

    public function dueReminderItems(DateTimeImmutable $now): array
    {
        return $this->scheduleRepo->dueReminderItems($now);
    }

    public function upsertPushSubscription(string $endpoint, ?string $publicKey, ?string $authToken, ?string $userAgent): void
    {
        $this->pushRepo->upsertPushSubscription($endpoint, $publicKey, $authToken, $userAgent);
    }

    public function removePushSubscriptionByEndpoint(string $endpoint): void
    {
        $this->pushRepo->removePushSubscriptionByEndpoint($endpoint);
    }

    public function pushSubscriptions(): array
    {
        return $this->pushRepo->pushSubscriptions();
    }

    public function userIdsWithPushSubscriptions(): array
    {
        return $this->pushRepo->userIdsWithPushSubscriptions();
    }

    public function markPushSentForReminderItems(array $items, DateTimeImmutable $sentAt): void
    {
        $this->pushRepo->markPushSentForReminderItems($items, $sentAt);
    }

    public function clearPushDeliveryLog(int $medicationId, string $scheduledDate, string $scheduledTime): void
    {
        $this->pushRepo->clearPushDeliveryLog($medicationId, $scheduledDate, $scheduledTime);
    }

    public function lastPushSentAt(): ?string
    {
        return $this->pushRepo->lastPushSentAt();
    }

    public function findAndConsumePushNonce(string $nonce): ?array
    {
        return $this->pushRepo->findAndConsumePushNonce($nonce);
    }

    public function dueReminderItemsNotYetPushed(DateTimeImmutable $now): array
    {
        return $this->pushRepo->dueReminderItemsNotYetPushed($now);
    }

    public function logRefill(int $medicationId, string $refillDate, float $amount, string $note): void
    {
        $this->inventoryRepo->logRefill($medicationId, $refillDate, $amount, $note);
    }

    public function adjustQuantity(int $medicationId, float $newCount, string $note = ''): void
    {
        $this->inventoryRepo->adjustQuantity($medicationId, $newCount, $note);
    }

    public function lastRefillForMedication(int $medicationId): ?array
    {
        return $this->inventoryRepo->lastRefillForMedication($medicationId);
    }

    public function refillsForMonth(int $medicationId, string $monthStart, string $monthEnd): array
    {
        return $this->inventoryRepo->refillsForMonth($medicationId, $monthStart, $monthEnd);
    }

    public function refillSummaryStats(int $medicationId, int $year): array
    {
        return $this->inventoryRepo->refillSummaryStats($medicationId, $year);
    }

    public function allGroups(): array
    {
        return $this->groupRepo->allGroups();
    }

    public function findGroup(int $id): ?array
    {
        return $this->groupRepo->findGroup($id);
    }

    public function reorderMedications(array $orderedIds): void
    {
        $this->groupRepo->reorderMedications($orderedIds);
    }

    public function reorderGroups(array $orderedIds): void
    {
        $this->groupRepo->reorderGroups($orderedIds);
    }

    public function createGroup(string $name, string $scheduledTime): int
    {
        return $this->groupRepo->createGroup($name, $scheduledTime);
    }

    public function updateGroup(int $id, string $name, string $scheduledTime): void
    {
        $this->groupRepo->updateGroup($id, $name, $scheduledTime);
    }

    public function deleteGroup(int $id): void
    {
        $this->groupRepo->deleteGroup($id);
    }

    public function addMedicationToGroup(int $groupId, int $medicationId, ?float $quantityPerDose = null): void
    {
        $this->groupRepo->addMedicationToGroup($groupId, $medicationId, $quantityPerDose);
    }

    public function updateMemberDose(int $groupId, int $medicationId, ?float $quantityPerDose): void
    {
        $this->groupRepo->updateMemberDose($groupId, $medicationId, $quantityPerDose);
    }

    public function createGroupWithMembers(string $name, string $scheduledTime, array $members): int
    {
        return $this->groupRepo->createGroupWithMembers($name, $scheduledTime, $members);
    }

    public function removeMedicationFromGroup(int $medicationId, ?int $groupId = null): void
    {
        $this->groupRepo->removeMedicationFromGroup($medicationId, $groupId);
    }

    public function groupForMedication(int $medicationId): ?array
    {
        return $this->groupRepo->groupForMedication($medicationId);
    }

    public function ungroupedActiveMedications(int $excludeGroupId = 0): array
    {
        return $this->groupRepo->ungroupedActiveMedications($excludeGroupId);
    }

    public function syncStockNotifications(array $medications): void
    {
        $this->stockNotificationRepo->syncStockNotifications($medications);
    }

    public function getNotificationsForUser(): array
    {
        return $this->stockNotificationRepo->getNotificationsForUser();
    }

    public function markNotificationRead(int $notificationId): void
    {
        $this->stockNotificationRepo->markNotificationRead($notificationId);
    }

    public function markAllNotificationsRead(): void
    {
        $this->stockNotificationRepo->markAllNotificationsRead();
    }

    public function dismissNotification(int $notificationId): void
    {
        $this->stockNotificationRepo->dismissNotification($notificationId);
    }

    public function clearStockNotificationsForMedication(int $medicationId): void
    {
        $this->stockNotificationRepo->clearStockNotificationsForMedication($medicationId);
    }

    public function activeMedicationCount(): int
    {
        return $this->onboardingRepo->activeMedicationCount();
    }

    public function draftMedications(): array
    {
        return $this->onboardingRepo->draftMedications();
    }

    public function createDraftMedication(
        string $name,
        ?float $doseAmount,
        ?string $doseUnit,
        ?string $doseForm,
        string $medicationType = 'prescription',
        string $setId = '',
        bool $asNeeded = false
    ): int
    {
        return $this->onboardingRepo->createDraftMedication($name, $doseAmount, $doseUnit, $doseForm, $medicationType, $setId, $asNeeded);
    }

    public function updateDraftMedication(
        int $id,
        string $name,
        ?float $doseAmount,
        ?string $doseUnit,
        ?string $doseForm,
        string $medicationType = 'prescription',
        string $setId = '',
        bool $asNeeded = false
    ): void
    {
        $this->onboardingRepo->updateDraftMedication($id, $name, $doseAmount, $doseUnit, $doseForm, $medicationType, $setId, $asNeeded);
    }

    public function deleteDraftMedication(int $id): void
    {
        $this->onboardingRepo->deleteDraftMedication($id);
    }

    public function updateTrackingPreferences(int $id, array $prefs): void
    {
        $this->onboardingRepo->updateTrackingPreferences($id, $prefs);
    }

    public function setDraftSchedule(int $medicationId, array $doseTimes, array $doseQtys): void
    {
        $this->onboardingRepo->setDraftSchedule($medicationId, $doseTimes, $doseQtys);
    }

    public function setDraftInventory(int $id, float $currentQty, string $countMethod, ?string $asOf): void
    {
        $this->onboardingRepo->setDraftInventory($id, $currentQty, $countMethod, $asOf);
    }

    public function activateOnboardingMedications(string $trackingStartedAt): int
    {
        return $this->onboardingRepo->activateOnboardingMedications($trackingStartedAt);
    }

    public function getOnboardingProgress(): ?array
    {
        return $this->onboardingRepo->getOnboardingProgress();
    }

    public function upsertOnboardingProgress(string $status, string $currentStep): void
    {
        $this->onboardingRepo->upsertOnboardingProgress($status, $currentStep);
    }
}
