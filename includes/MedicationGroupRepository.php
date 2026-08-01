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
    }

    public function deleteGroup(int $id): void
    {
        $statement = $this->db->prepare('DELETE FROM medication_groups WHERE id = :id AND user_id = :user_id ' . $this->profileSql(''));
        $statement->execute(array_merge(['id' => $id, 'user_id' => $this->userId], $this->profileParam()));
    }

    public function addMedicationToGroup(int $groupId, int $medicationId, ?float $quantityPerDose = null): void
    {
        // Ownership gate: never link a group or medication the caller does not own.
        if (!$this->groupBelongsToUser($groupId) || !$this->medicationBelongsToUser($medicationId)) {
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
    }

    public function removeMedicationFromGroup(int $medicationId, ?int $groupId = null): void
    {
        // Ownership gate: only touch memberships for a medication the caller owns.
        if (!$this->medicationBelongsToUser($medicationId)) {
            return;
        }
        if ($groupId !== null) {
            $statement = $this->db->prepare(
                'DELETE FROM medication_group_members WHERE medication_id = :medication_id AND group_id = :group_id'
            );
            $statement->execute(['medication_id' => $medicationId, 'group_id' => $groupId]);
        } else {
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
                'SELECT m.id AS medication_id, m.name, m.dose, m.dose_amount, m.dose_unit,
                        m.inventory_unit, m.track_dose_feedback,
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
                        m.inventory_unit, m.track_dose_feedback,
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

    public function medicationGroupMap(): array
    {
        try {
            $statement = $this->db->prepare(
                'SELECT mgm.medication_id, g.id AS group_id, g.name AS group_name, g.scheduled_time AS group_time
                 FROM medication_group_members mgm
                 INNER JOIN medication_groups g ON g.id = mgm.group_id
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
