<?php

declare(strict_types=1);

final class MedicationGroupRepository
{
    public function __construct(
        private readonly PDO $db,
        private readonly int $userId = 0,
        private readonly ?int $profileId = null
    ) {
    }

    public function allGroups(): array
    {
        try {
            $statement = $this->db->prepare(
                'SELECT id, name, scheduled_time, active FROM medication_groups WHERE user_id = :user_id ' . $this->profileSql('medication_groups') . ' ORDER BY sort_order ASC, name ASC'
            );
            $statement->execute(array_merge(['user_id' => $this->userId], $this->profileParam()));
            $groups = $statement->fetchAll();
        } catch (Throwable) {
            return [];
        }
        $membersByGroup = $this->allGroupMembersByGroupId();
        foreach ($groups as &$group) {
            $group['scheduled_time'] = substr((string) $group['scheduled_time'], 0, 5);
            $group['members'] = $membersByGroup[(int) $group['id']] ?? [];
        }
        unset($group);

        return $groups;
    }

    public function findGroup(int $id): ?array
    {
        try {
            $statement = $this->db->prepare(
                'SELECT id, name, scheduled_time, active FROM medication_groups WHERE id = :id AND user_id = :user_id ' . $this->profileSql('') . ' LIMIT 1'
            );
            $statement->execute(array_merge(['id' => $id, 'user_id' => $this->userId], $this->profileParam()));
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

    public function reorderMedications(array $orderedIds): void
    {
        $stmt = $this->db->prepare(
            'UPDATE medications SET sort_order = :sort_order WHERE id = :id AND user_id = :user_id'
        );
        foreach ($orderedIds as $pos => $id) {
            $stmt->execute(['sort_order' => $pos, 'id' => (int) $id, 'user_id' => $this->userId]);
        }
    }

    public function reorderGroups(array $orderedIds): void
    {
        $stmt = $this->db->prepare(
            'UPDATE medication_groups SET sort_order = :sort_order WHERE id = :id AND user_id = :user_id'
        );
        foreach ($orderedIds as $pos => $id) {
            $stmt->execute(['sort_order' => $pos, 'id' => (int) $id, 'user_id' => $this->userId]);
        }
    }

    public function createGroup(string $name, string $scheduledTime): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO medication_groups (user_id, profile_id, name, scheduled_time) VALUES (:user_id, :profile_id, :name, :scheduled_time)'
        );
        $statement->execute(['user_id' => $this->userId, 'profile_id' => $this->profileId, 'name' => $name, 'scheduled_time' => $scheduledTime]);

        return (int) $this->db->lastInsertId();
    }

    public function updateGroup(int $id, string $name, string $scheduledTime): void
    {
        $statement = $this->db->prepare(
            'UPDATE medication_groups SET name = :name, scheduled_time = :scheduled_time WHERE id = :id AND user_id = :user_id ' . $this->profileSql('')
        );
        $statement->execute(array_merge(['id' => $id, 'name' => $name, 'scheduled_time' => $scheduledTime, 'user_id' => $this->userId], $this->profileParam()));
        if ($statement->rowCount() === 0) {
            return;
        }

        // Keep every current member's group-owned schedule row following the group's time.
        $members = $this->db->prepare('SELECT medication_id FROM medication_group_members WHERE group_id = :group_id');
        $members->execute(['group_id' => $id]);
        foreach ($members->fetchAll(PDO::FETCH_COLUMN) as $memberId) {
            $this->syncGroupScheduleTime($id, (int) $memberId, $scheduledTime);
        }
    }

    public function deleteGroup(int $id): void
    {
        // Ownership gate: without this, submitting another tenant's group id would still
        // un-tag that foreign group's schedule rows below even though the owner-scoped DELETE
        // afterward correctly refuses to remove the group itself.
        if ($this->groupBelongsToUser($id)) {
            // No FK cascade on medication_schedule_times.group_id: un-tag its rows first so
            // member medications keep their dose (now a plain individual reminder) instead of
            // losing it.
            $this->db->prepare('UPDATE medication_schedule_times SET group_id = NULL WHERE group_id = :group_id')
                ->execute(['group_id' => $id]);
        }
        $statement = $this->db->prepare('DELETE FROM medication_groups WHERE id = :id AND user_id = :user_id ' . $this->profileSql(''));
        $statement->execute(array_merge(['id' => $id, 'user_id' => $this->userId], $this->profileParam()));
    }

    public function addMedicationToGroup(int $groupId, int $medicationId, ?float $quantityPerDose = null): void
    {
        // Ownership gate: never link a group or medication the caller does not own.
        if (!$this->groupBelongsToUser($groupId) || !$this->medicationBelongsToUser($medicationId)) {
            return;
        }
        $groupTime = $this->scheduledTimeForGroup($groupId);
        if ($groupTime === null) {
            return;
        }
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'sqlite'
            ? 'INSERT OR IGNORE INTO medication_group_members (group_id, medication_id, quantity_per_dose)
               VALUES (:group_id, :medication_id, :quantity_per_dose)'
            : 'INSERT IGNORE INTO medication_group_members (group_id, medication_id, quantity_per_dose)
               VALUES (:group_id, :medication_id, :quantity_per_dose)';
        $statement = $this->db->prepare($sql);
        $statement->execute(['group_id' => $groupId, 'medication_id' => $medicationId, 'quantity_per_dose' => $quantityPerDose]);

        // Runs on every call, not just the first: the medication's own createMedication()/
        // updateMedication() (called just before this in routes/actions.php) already wrote
        // whatever individual times the form submitted — this sync must run after and win, so
        // the group's time is what actually takes effect for this membership.
        $this->syncGroupScheduleTime($groupId, $medicationId, $groupTime);
    }

    public function updateMemberDose(int $groupId, int $medicationId, ?float $quantityPerDose): void
    {
        // Ownership gate: never touch a membership row the caller does not own.
        if (!$this->groupBelongsToUser($groupId) || !$this->medicationBelongsToUser($medicationId)) {
            return;
        }
        $statement = $this->db->prepare(
            'UPDATE medication_group_members SET quantity_per_dose = :quantity_per_dose WHERE group_id = :group_id AND medication_id = :medication_id'
        );
        $statement->execute(['quantity_per_dose' => $quantityPerDose, 'group_id' => $groupId, 'medication_id' => $medicationId]);
    }

    public function createGroupWithMembers(string $name, string $scheduledTime, array $members): int
    {
        $this->db->beginTransaction();
        try {
            $id = $this->createGroup($name, $scheduledTime);
            foreach ($members as $member) {
                $medicationId = (int) ($member['medication_id'] ?? 0);
                if ($medicationId <= 0) {
                    continue;
                }
                $quantityPerDose = isset($member['quantity_per_dose']) && $member['quantity_per_dose'] !== null
                    ? (float) $member['quantity_per_dose']
                    : null;
                $this->addMedicationToGroup($id, $medicationId, $quantityPerDose);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $id;
    }

    private function scheduledTimeForGroup(int $groupId): ?string
    {
        $statement = $this->db->prepare('SELECT scheduled_time FROM medication_groups WHERE id = :id');
        $statement->execute(['id' => $groupId]);
        $time = $statement->fetchColumn();

        return $time !== false ? (string) $time : null;
    }

    // Ensures $medicationId has a schedule row owned by $groupId at $scheduledTime: reuses an
    // exact-time individual row if one exists (the already-working case, no visible change),
    // else claims the medication's earliest remaining individual row (the dose this group is
    // taking over), else inserts a fresh row. Any other individual doses the medication has —
    // e.g. a second, unrelated daily dose — are left untouched. Never touches rows owned by a
    // different group_id, so a medication can belong to several groups at once.
    private function syncGroupScheduleTime(int $groupId, int $medicationId, string $scheduledTime): void
    {
        // Already owned by this group (e.g. the group's own time just changed) — relocate that
        // row in place rather than deleting it and re-running the claim heuristic below, which
        // would otherwise steal an unrelated individual dose on every re-sync.
        $existing = $this->db->prepare(
            'SELECT id, reminder_time FROM medication_schedule_times WHERE medication_id = :medication_id AND group_id = :group_id LIMIT 1'
        );
        $existing->execute(['medication_id' => $medicationId, 'group_id' => $groupId]);
        $existingRow = $existing->fetch();
        if ($existingRow !== false) {
            $existingId = (int) $existingRow['id'];
            if ((string) $existingRow['reminder_time'] === $scheduledTime) {
                return;
            }
            // Bump created_at whenever the time actually moves: ScheduleRepository::
            // medicationConfigurationValidForDate() reads this column as its "how far back
            // can the current schedule be trusted" signal for historical missed-dose
            // backfill. Relocating the row in place (rather than delete+insert, which is
            // what individual-dose edits do) would otherwise leave that signal pointing at
            // the row's original creation time, letting backfill treat every earlier date
            // as if the group's *new* time had always applied and fabricate a missed dose
            // at a time that never existed on that historical day.
            try {
                $this->db->prepare(
                    'UPDATE medication_schedule_times SET reminder_time = :reminder_time, created_at = CURRENT_TIMESTAMP WHERE id = :id'
                )->execute(['reminder_time' => $scheduledTime, 'id' => $existingId]);
            } catch (Throwable) {
                // No created_at column on this install/test double: still relocate the row.
                $this->db->prepare(
                    'UPDATE medication_schedule_times SET reminder_time = :reminder_time WHERE id = :id'
                )->execute(['reminder_time' => $scheduledTime, 'id' => $existingId]);
            }
            return;
        }

        // First time this medication joins this group: claim an exact-time individual row if
        // one exists, else the earliest remaining individual row, else insert fresh.
        $claim = $this->db->prepare(
            'UPDATE medication_schedule_times SET group_id = :group_id
             WHERE medication_id = :medication_id AND reminder_time = :reminder_time AND group_id IS NULL'
        );
        $claim->execute(['group_id' => $groupId, 'medication_id' => $medicationId, 'reminder_time' => $scheduledTime]);
        if ($claim->rowCount() > 0) {
            return;
        }

        $earliest = $this->db->prepare(
            'SELECT id FROM medication_schedule_times
             WHERE medication_id = :medication_id AND group_id IS NULL
             ORDER BY reminder_time ASC LIMIT 1'
        );
        $earliest->execute(['medication_id' => $medicationId]);
        $rowId = $earliest->fetchColumn();
        if ($rowId !== false) {
            $this->db->prepare(
                'UPDATE medication_schedule_times SET reminder_time = :reminder_time, group_id = :group_id WHERE id = :id'
            )->execute(['reminder_time' => $scheduledTime, 'group_id' => $groupId, 'id' => (int) $rowId]);
            return;
        }

        $this->db->prepare(
            'INSERT INTO medication_schedule_times (medication_id, reminder_time, quantity_per_dose, group_id)
             VALUES (:medication_id, :reminder_time, NULL, :group_id)'
        )->execute(['medication_id' => $medicationId, 'reminder_time' => $scheduledTime, 'group_id' => $groupId]);
    }

    public function removeMedicationFromGroup(int $medicationId, ?int $groupId = null): void
    {
        // Ownership gate: only touch memberships for a medication the caller owns.
        if (!$this->medicationBelongsToUser($medicationId)) {
            return;
        }
        if ($groupId !== null) {
            // Un-tag rather than delete: the medication keeps its dose, just as a plain
            // individual reminder instead of a group-tagged one.
            $this->db->prepare(
                'UPDATE medication_schedule_times SET group_id = NULL WHERE medication_id = :medication_id AND group_id = :group_id'
            )->execute(['medication_id' => $medicationId, 'group_id' => $groupId]);
            $statement = $this->db->prepare(
                'DELETE FROM medication_group_members WHERE medication_id = :medication_id AND group_id = :group_id'
            );
            $statement->execute(['medication_id' => $medicationId, 'group_id' => $groupId]);
        } else {
            $this->db->prepare(
                'UPDATE medication_schedule_times SET group_id = NULL WHERE medication_id = :medication_id AND group_id IS NOT NULL'
            )->execute(['medication_id' => $medicationId]);
            $statement = $this->db->prepare(
                'DELETE FROM medication_group_members WHERE medication_id = :medication_id'
            );
            $statement->execute(['medication_id' => $medicationId]);
        }
    }

    public function groupForMedication(int $medicationId): ?array
    {
        try {
            $statement = $this->db->prepare(
                'SELECT g.id, g.name, g.scheduled_time
                 FROM medication_groups g
                 INNER JOIN medication_group_members mgm ON mgm.group_id = g.id
                 WHERE mgm.medication_id = :medication_id AND g.user_id = :user_id ' . $this->profileSql('g') . '
                 LIMIT 1'
            );
            $statement->execute(array_merge(['medication_id' => $medicationId, 'user_id' => $this->userId], $this->profileParam()));
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

    public function ungroupedActiveMedications(int $excludeGroupId = 0): array
    {
        try {
            $statement = $this->db->prepare(
                'SELECT m.id, m.name, m.dose, m.dose_amount, m.dose_unit,
                        GROUP_CONCAT(mg.name) AS existing_groups
                 FROM medications m
                 LEFT JOIN medication_group_members mgm_this
                        ON mgm_this.medication_id = m.id AND mgm_this.group_id = :exclude_group_id
                 LEFT JOIN medication_group_members mgm_all ON mgm_all.medication_id = m.id
                 LEFT JOIN medication_groups mg ON mg.id = mgm_all.group_id
                 WHERE m.active = 1 AND m.user_id = :user_id ' . $this->profileSql() . ' AND mgm_this.medication_id IS NULL
                 GROUP BY m.id, m.name, m.dose, m.dose_amount, m.dose_unit
                 ORDER BY m.name ASC'
            );
            $statement->execute(array_merge(['user_id' => $this->userId, 'exclude_group_id' => $excludeGroupId], $this->profileParam()));
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
                'SELECT m.id AS medication_id, m.name, m.medication_type, m.dose, m.dose_amount, m.dose_unit,
                        m.inventory_unit, m.track_dose_feedback, m.feedback_type, m.quantity_per_dose AS medication_default_quantity_per_dose,
                        mgm.sort_order, mgm.quantity_per_dose AS group_quantity_per_dose
                 FROM medications m
                 INNER JOIN medication_group_members mgm ON mgm.medication_id = m.id
                 WHERE mgm.group_id = :group_id AND m.active = 1 AND m.user_id = :user_id
                 ORDER BY mgm.sort_order ASC, m.name ASC'
            );
            $statement->execute(['group_id' => $groupId, 'user_id' => $this->userId]);

            return $statement->fetchAll();
        } catch (Throwable) {
            return [];
        }
    }

    private function groupBelongsToUser(int $groupId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM medication_groups WHERE id = :id AND user_id = :user_id ' . $this->profileSql('') . ' LIMIT 1'
        );
        $stmt->execute(array_merge(['id' => $groupId, 'user_id' => $this->userId], $this->profileParam()));
        return $stmt->fetchColumn() !== false;
    }

    public function medicationBelongsToUser(int $medicationId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM medications WHERE id = :id AND user_id = :user_id ' . $this->profileSql('') . ' LIMIT 1'
        );
        $stmt->execute(array_merge(['id' => $medicationId, 'user_id' => $this->userId], $this->profileParam()));
        return $stmt->fetchColumn() !== false;
    }

    private function allGroupMembersByGroupId(): array
    {
        try {
            $statement = $this->db->prepare(
                'SELECT mgm.group_id, m.id AS medication_id, m.name, m.medication_type, m.dose, m.dose_amount, m.dose_unit,
                        m.inventory_unit, m.track_dose_feedback, m.feedback_type, m.quantity_per_dose AS medication_default_quantity_per_dose,
                        mgm.sort_order, mgm.quantity_per_dose AS group_quantity_per_dose
                 FROM medications m
                 INNER JOIN medication_group_members mgm ON mgm.medication_id = m.id
                 WHERE m.active = 1 AND m.user_id = :user_id
                 ORDER BY mgm.group_id ASC, mgm.sort_order ASC, m.name ASC'
            );
            $statement->execute(['user_id' => $this->userId]);
            $rows = $statement->fetchAll();
        } catch (Throwable) {
            return [];
        }
        $result = [];
        foreach ($rows as $row) {
            $gid = (int) $row['group_id'];
            unset($row['group_id']);
            $result[$gid][] = $row;
        }
        return $result;
    }

    // Tags each medication's schedule slot(s) with the group that owns them, straight from
    // medication_schedule_times.group_id rather than inferring membership by comparing times —
    // a medication can have one tagged row per group it belongs to, each at that group's own
    // time (see syncGroupScheduleTime()).
    public function medicationGroupMap(): array
    {
        try {
            $statement = $this->db->prepare(
                'SELECT mst.medication_id, mst.reminder_time AS group_time, g.id AS group_id, g.name AS group_name
                 FROM medication_schedule_times mst
                 INNER JOIN medication_groups g ON g.id = mst.group_id
                 WHERE g.user_id = :user_id'
            );
            $statement->execute(['user_id' => $this->userId]);
            $map = [];
            foreach ($statement->fetchAll() as $row) {
                $timeKey = substr((string) $row['group_time'], 0, 5);
                $map[(int) $row['medication_id']][$timeKey] = [
                    'group_id' => (int) $row['group_id'],
                    'group_name' => (string) $row['group_name'],
                ];
            }

            return $map;
        } catch (Throwable) {
            return [];
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
