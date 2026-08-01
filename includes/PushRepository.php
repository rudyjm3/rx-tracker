<?php

declare(strict_types=1);

final class PushRepository
{
    public function __construct(
        private readonly PDO $db,
        private readonly int $userId,
        private readonly ?int $profileId,
        private readonly ScheduleRepository $scheduleRepo
    ) {
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
            ? 'INSERT INTO push_subscriptions (user_id, endpoint, p256dh_key, auth_key, user_agent, created_at, updated_at)
               VALUES (:user_id, :endpoint, :p256dh_key, :auth_key, :user_agent, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
               ON CONFLICT(endpoint)
               DO UPDATE SET p256dh_key = excluded.p256dh_key, auth_key = excluded.auth_key, user_agent = excluded.user_agent, updated_at = CURRENT_TIMESTAMP'
            : 'INSERT INTO push_subscriptions (user_id, endpoint, p256dh_key, auth_key, user_agent)
               VALUES (:user_id, :endpoint, :p256dh_key, :auth_key, :user_agent)
               ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh_key = VALUES(p256dh_key), auth_key = VALUES(auth_key), user_agent = VALUES(user_agent), updated_at = CURRENT_TIMESTAMP';
        $statement = $this->db->prepare($sql);
        $statement->execute([
            'user_id'    => $this->userId,
            'endpoint'   => $endpoint,
            'p256dh_key' => $publicKey,
            'auth_key'   => $authToken,
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
        $statement = $this->db->prepare('SELECT endpoint, p256dh_key, auth_key FROM push_subscriptions WHERE user_id = :user_id ORDER BY id ASC');
        $statement->execute(['user_id' => $this->userId]);
        return $statement->fetchAll();
    }

    public function userIdsWithPushSubscriptions(): array
    {
        try {
            $statement = $this->db->query('SELECT DISTINCT user_id FROM push_subscriptions WHERE user_id IS NOT NULL');
            return array_column($statement->fetchAll(), 'user_id');
        } catch (Throwable) {
            return [];
        }
    }

    public function markPushSentForReminderItems(array $items, DateTimeImmutable $sentAt): void
    {
        if ($items === []) {
            return;
        }
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'sqlite'
            ? 'INSERT INTO push_delivery_log (medication_id, scheduled_for_date, scheduled_time, sent_at, action_nonce, postponed_until)
               VALUES (:medication_id, :scheduled_for_date, :scheduled_time, :sent_at, :action_nonce, :postponed_until)
               ON CONFLICT(medication_id, scheduled_for_date, scheduled_time, postponed_until) DO NOTHING'
            : 'INSERT IGNORE INTO push_delivery_log (medication_id, scheduled_for_date, scheduled_time, sent_at, action_nonce, postponed_until)
               VALUES (:medication_id, :scheduled_for_date, :scheduled_time, :sent_at, :action_nonce, :postponed_until)';
        $statement = $this->db->prepare($sql);
        foreach ($items as $item) {
            $statement->execute([
                'medication_id' => (int) ($item['medication_id'] ?? 0),
                'scheduled_for_date' => (string) ($item['scheduled_date'] ?? ''),
                'scheduled_time' => (string) ($item['scheduled_time'] ?? ''),
                'sent_at' => $sentAt->format('Y-m-d H:i:s'),
                'action_nonce' => (string) ($item['_nonce'] ?? ''),
                'postponed_until' => (string) ($item['postponed_until'] ?? ''),
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
        $items = $this->scheduleRepo->dueReminderItems($now);
        if ($items === []) {
            return [];
        }

        $check = $this->db->prepare(
            'SELECT 1
             FROM push_delivery_log
             WHERE medication_id = :medication_id
               AND scheduled_for_date = :scheduled_for_date
               AND scheduled_time = :scheduled_time
               AND postponed_until = :postponed_until
             LIMIT 1'
        );
        $unsent = [];
        foreach ($items as $item) {
            $check->execute([
                'medication_id' => (int) $item['medication_id'],
                'scheduled_for_date' => (string) $item['scheduled_date'],
                'scheduled_time' => (string) $item['scheduled_time'],
                'postponed_until' => (string) ($item['postponed_until'] ?? ''),
            ]);
            if ($check->fetchColumn() === false) {
                $unsent[] = $item;
            }
        }

        return $unsent;
    }
}
