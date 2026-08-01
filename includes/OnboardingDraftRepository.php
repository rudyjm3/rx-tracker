<?php

declare(strict_types=1);

final class OnboardingDraftRepository
{

    private const MEDICATION_COLUMNS = 'id, name, dose, start_date, created_at, instructions, schedule_mode, time_format, interval_hours, first_dose_time, as_needed, starting_pill_count, pill_count, low_supply_threshold, track_dose_feedback, feedback_type, set_id, medication_type, dose_amount, dose_unit, dose_form, inventory_type, inventory_unit, starting_quantity, current_quantity, quantity_per_dose, setup_status, dashboard_enabled, reminders_enabled, adherence_enabled, inventory_enabled, tracking_started_at, inventory_count_method, inventory_as_of';

    public function __construct(
        private readonly PDO $db,
        private readonly int $userId,
        private readonly ?int $profileId,
        private readonly ScheduleRepository $scheduleRepo
    ) {
    }

    public function activeMedicationCount(): int
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM medications WHERE active = 1 AND setup_status = \'active\' AND user_id = :user_id ' . $this->profileSql('')
        );
        $statement->execute(array_merge(['user_id' => $this->userId], $this->profileParam()));
        return (int) $statement->fetchColumn();
    }

    public function draftMedications(): array
    {
        $statement = $this->db->prepare(
            'SELECT ' . self::MEDICATION_COLUMNS . ' FROM medications m WHERE m.setup_status = \'draft\' AND m.user_id = :user_id ' . $this->profileSql() . ' ORDER BY m.sort_order ASC, m.name ASC'
        );
        $statement->execute(array_merge(['user_id' => $this->userId], $this->profileParam()));
        $medications = $statement->fetchAll();
        $ids = array_column($medications, 'id');
        $allTimes     = $this->scheduleRepo->scheduleTimesByMedicationIds($ids);
        $allTimeDoses = $this->scheduleRepo->scheduleTimeDosesByMedicationIds($ids);
        foreach ($medications as &$medication) {
            $medication['times']      = $allTimes[(int) $medication['id']] ?? [];
            $medication['time_doses'] = $allTimeDoses[(int) $medication['id']] ?? [];
        }
        unset($medication);
        return $medications;
    }

    public function createDraftMedication(
        string $name,
        ?float $doseAmount,
        ?string $doseUnit,
        ?string $doseForm,
        string $medicationType = 'prescription',
        string $setId = '',
        bool $asNeeded = false
    ): int {
        $statement = $this->db->prepare(
            'INSERT INTO medications (user_id, profile_id, name, dose, start_date, instructions,
                schedule_mode, time_format, as_needed, starting_pill_count, pill_count,
                low_supply_threshold, track_dose_feedback, feedback_type,
                medication_type, dose_amount, dose_unit, dose_form,
                inventory_type, inventory_unit, starting_quantity, current_quantity, quantity_per_dose,
                set_id, setup_status, adherence_enabled)
             VALUES (:user_id, :profile_id, :name, \'\', :start_date, \'\',
                \'fixed_times\', \'12h\', :as_needed, 0, 0,
                5, 0, \'none\',
                :medication_type, :dose_amount, :dose_unit, :dose_form,
                \'pills\', \'tablets\', 0, 0, 1,
                :set_id, \'draft\', :adherence_enabled)'
        );
        $statement->execute([
            'user_id'           => $this->userId,
            'profile_id'        => $this->profileId,
            'name'              => $name,
            'start_date'        => date('Y-m-d'),
            'medication_type'   => $medicationType,
            'dose_amount'       => $doseAmount,
            'dose_unit'         => $doseUnit,
            'dose_form'         => $doseForm,
            'set_id'            => $setId,
            'as_needed'         => $asNeeded ? 1 : 0,
            'adherence_enabled' => $asNeeded ? 0 : 1,
        ]);
        return (int) $this->db->lastInsertId();
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
    ): void {
        $statement = $this->db->prepare(
            'UPDATE medications SET name = :name, dose_amount = :dose_amount, dose_unit = :dose_unit,
             dose_form = :dose_form, medication_type = :medication_type, set_id = :set_id,
             as_needed = :as_needed, adherence_enabled = :adherence_enabled
             WHERE id = :id AND user_id = :user_id AND setup_status = \'draft\' ' . $this->profileSql('')
        );
        $statement->execute(array_merge([
            'id'                => $id,
            'user_id'           => $this->userId,
            'name'              => $name,
            'dose_amount'       => $doseAmount,
            'dose_unit'         => $doseUnit,
            'dose_form'         => $doseForm,
            'medication_type'   => $medicationType,
            'set_id'            => $setId,
            'as_needed'         => $asNeeded ? 1 : 0,
            'adherence_enabled' => $asNeeded ? 0 : 1,
        ], $this->profileParam()));
    }

    public function deleteDraftMedication(int $id): void
    {
        $statement = $this->db->prepare(
            'DELETE FROM medications WHERE id = :id AND user_id = :user_id AND setup_status = \'draft\' ' . $this->profileSql('')
        );
        $statement->execute(array_merge(['id' => $id, 'user_id' => $this->userId], $this->profileParam()));
    }

    public function updateTrackingPreferences(int $id, array $prefs): void
    {
        $feedbackType = 'none';
        if (!empty($prefs['track_pain']) && !empty($prefs['track_mood'])) {
            $feedbackType = 'both';
        } elseif (!empty($prefs['track_pain'])) {
            $feedbackType = 'pain';
        } elseif (!empty($prefs['track_mood'])) {
            $feedbackType = 'mood';
        }
        $statement = $this->db->prepare(
            'UPDATE medications SET
                dashboard_enabled   = :dashboard_enabled,
                reminders_enabled   = :reminders_enabled,
                adherence_enabled   = :adherence_enabled,
                inventory_enabled   = :inventory_enabled,
                feedback_type       = :feedback_type,
                track_dose_feedback = :track_dose_feedback
             WHERE id = :id AND user_id = :user_id AND setup_status = \'draft\' ' . $this->profileSql('')
        );
        $statement->execute(array_merge([
            'id'                  => $id,
            'user_id'             => $this->userId,
            'dashboard_enabled'   => (int) !empty($prefs['dashboard_enabled']),
            'reminders_enabled'   => (int) !empty($prefs['reminders_enabled']),
            'adherence_enabled'   => (int) !empty($prefs['adherence_enabled']),
            'inventory_enabled'   => (int) !empty($prefs['inventory_enabled']),
            'feedback_type'       => $feedbackType,
            'track_dose_feedback' => $feedbackType !== 'none' ? 1 : 0,
        ], $this->profileParam()));
    }

    public function setDraftSchedule(int $medicationId, array $doseTimes, array $doseQtys): void
    {
        $ownerCheck = $this->db->prepare(
            'SELECT id FROM medications WHERE id = :id AND user_id = :user_id AND setup_status = \'draft\' ' . $this->profileSql('')
        );
        $ownerCheck->execute(array_merge(['id' => $medicationId, 'user_id' => $this->userId], $this->profileParam()));
        if (!$ownerCheck->fetchColumn()) {
            throw new RuntimeException('Draft medication not found.');
        }
        $this->scheduleRepo->replaceScheduleTimes($medicationId, $doseTimes, $doseQtys);
    }

    public function setDraftInventory(int $id, float $currentQty, string $countMethod, ?string $asOf): void
    {
        $statement = $this->db->prepare(
            'UPDATE medications SET
                current_quantity       = :current_qty,
                starting_quantity      = :starting_qty,
                inventory_count_method = :method,
                inventory_as_of        = :as_of
             WHERE id = :id AND user_id = :user_id AND setup_status = \'draft\' ' . $this->profileSql('')
        );
        $statement->execute(array_merge([
            'id'          => $id,
            'user_id'     => $this->userId,
            'current_qty' => $currentQty,
            'starting_qty' => $currentQty,
            'method'      => $countMethod,
            'as_of'       => $asOf,
        ], $this->profileParam()));
    }

    public function activateOnboardingMedications(string $trackingStartedAt): int
    {
        $statement = $this->db->prepare(
            'UPDATE medications SET setup_status = \'active\', tracking_started_at = :started_at
             WHERE user_id = :user_id AND setup_status = \'draft\' ' . $this->profileSql('')
        );
        $statement->execute(array_merge(
            ['user_id' => $this->userId, 'started_at' => $trackingStartedAt],
            $this->profileParam()
        ));
        return $statement->rowCount();
    }

    public function getOnboardingProgress(): ?array
    {
        $profileId = $this->profileId ?? 0;
        $statement = $this->db->prepare(
            'SELECT id, status, current_step, started_at, completed_at
             FROM profile_onboarding
             WHERE user_id = :user_id AND profile_id = :profile_id
             LIMIT 1'
        );
        $statement->execute(['user_id' => $this->userId, 'profile_id' => $profileId]);
        $row = $statement->fetch();
        return $row !== false ? $row : null;
    }

    public function upsertOnboardingProgress(string $status, string $currentStep): void
    {
        $profileId = $this->profileId ?? 0;
        $statement = $this->db->prepare(
            'INSERT INTO profile_onboarding (user_id, profile_id, status, current_step)
             VALUES (:user_id, :profile_id, :ins_status, :ins_step)
             ON DUPLICATE KEY UPDATE status = :upd_status, current_step = :upd_step,
             completed_at = CASE WHEN :chk_status = \'completed\' THEN NOW() ELSE NULL END'
        );
        $statement->execute([
            'user_id'    => $this->userId,
            'profile_id' => $profileId,
            'ins_status' => $status,
            'ins_step'   => $currentStep,
            'upd_status' => $status,
            'upd_step'   => $currentStep,
            'chk_status' => $status,
        ]);
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
