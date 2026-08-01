<?php

declare(strict_types=1);

final class StockNotificationRepository
{
    public function __construct(
        private readonly PDO $db,
        private readonly int $userId = 0,
        private readonly ?int $profileId = null
    ) {
    }

    public function syncStockNotifications(array $medications): void
    {
        // Load existing notification rows keyed by medication_id
        $stmt = $this->db->prepare(
            'SELECT id, medication_id, type, is_dismissed FROM user_notifications WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $this->userId]);
        $existingByMed = [];
        foreach ($stmt->fetchAll() as $row) {
            $existingByMed[(int) $row['medication_id']] = $row;
        }

        $seenMedIds = [];

        foreach ($medications as $med) {
            $medId = (int) $med['id'];
            $threshold = (float) ($med['low_supply_threshold'] ?? 0);
            if ($threshold <= 0) {
                continue;
            }

            $qty = (float) ($med['current_quantity'] ?? $med['pill_count'] ?? 0);

            if ($qty <= 0) {
                $desiredType = 'out_of_stock';
            } elseif ($qty <= $threshold) {
                $days = daysUntilRunout($med);
                $desiredType = ($days !== null && $days <= 3) ? 'critical_stock' : 'low_stock';
            } else {
                $desiredType = null;
            }

            $seenMedIds[] = $medId;
            $existing = $existingByMed[$medId] ?? null;

            if ($desiredType === null) {
                // Stock is healthy — remove any existing notification
                if ($existing !== null) {
                    $del = $this->db->prepare('DELETE FROM user_notifications WHERE id = :id AND user_id = :user_id');
                    $del->execute(['id' => $existing['id'], 'user_id' => $this->userId]);
                }
                continue;
            }

            if ($existing === null) {
                $ins = $this->db->prepare(
                    'INSERT INTO user_notifications (user_id, medication_id, type, is_read, is_dismissed)
                     VALUES (:user_id, :medication_id, :type, 0, 0)'
                );
                $ins->execute(['user_id' => $this->userId, 'medication_id' => $medId, 'type' => $desiredType]);
            } elseif ((string) $existing['type'] !== $desiredType) {
                // Severity changed — update and re-alert (even if previously dismissed)
                $upd = $this->db->prepare(
                    'UPDATE user_notifications SET type = :type, is_read = 0, is_dismissed = 0
                     WHERE id = :id AND user_id = :user_id'
                );
                $upd->execute(['type' => $desiredType, 'id' => $existing['id'], 'user_id' => $this->userId]);
            }
            // Same type as before — leave is_read/is_dismissed untouched
        }

        // Remove notifications for medications no longer in the input list (e.g., deactivated)
        foreach ($existingByMed as $medId => $existing) {
            if (!in_array($medId, $seenMedIds, true)) {
                $del = $this->db->prepare('DELETE FROM user_notifications WHERE id = :id AND user_id = :user_id');
                $del->execute(['id' => $existing['id'], 'user_id' => $this->userId]);
            }
        }
    }

    public function getNotificationsForUser(): array
    {
        $stmt = $this->db->prepare(
            "SELECT un.id, un.medication_id, un.type, un.is_read, un.created_at,
                    m.name AS medication_name,
                    m.current_quantity, m.inventory_unit, m.low_supply_threshold,
                    m.dose_form, m.quantity_per_dose, m.schedule_mode, m.interval_hours,
                    m.dose_amount, m.dose_unit, m.dose
             FROM user_notifications un
             INNER JOIN medications m ON m.id = un.medication_id
             WHERE un.user_id = :user_id AND un.is_dismissed = 0
             ORDER BY
                 CASE un.type
                     WHEN 'out_of_stock'   THEN 1
                     WHEN 'critical_stock' THEN 2
                     WHEN 'low_stock'      THEN 3
                 END ASC,
                 m.name ASC"
        );
        $stmt->execute(['user_id' => $this->userId]);
        return $stmt->fetchAll();
    }

    public function markNotificationRead(int $notificationId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE user_notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute(['id' => $notificationId, 'user_id' => $this->userId]);
    }

    public function markAllNotificationsRead(): void
    {
        $stmt = $this->db->prepare(
            'UPDATE user_notifications SET is_read = 1 WHERE user_id = :user_id AND is_dismissed = 0'
        );
        $stmt->execute(['user_id' => $this->userId]);
    }

    public function dismissNotification(int $notificationId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE user_notifications SET is_dismissed = 1, is_read = 1 WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute(['id' => $notificationId, 'user_id' => $this->userId]);
    }

    public function clearStockNotificationsForMedication(int $medicationId): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM user_notifications WHERE medication_id = :medication_id AND user_id = :user_id'
        );
        $stmt->execute(['medication_id' => $medicationId, 'user_id' => $this->userId]);
    }
}
