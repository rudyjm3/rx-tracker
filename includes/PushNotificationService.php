<?php

declare(strict_types=1);

final class PushNotificationService
{
    public function __construct(
        private readonly MedicationRepository $repository,
        private readonly string $vapidPublicKey,
        private readonly string $vapidPrivateKey,
        private readonly string $vapidSubject
    ) {
    }

    public static function fromEnv(MedicationRepository $repository): self
    {
        $publicKey = trim((string) getenv('PUSH_VAPID_PUBLIC_KEY'));
        $privateKey = trim((string) getenv('PUSH_VAPID_PRIVATE_KEY'));
        $subject = trim((string) getenv('PUSH_VAPID_SUBJECT'));

        if ($publicKey === '' || $privateKey === '' || $subject === '') {
            throw new RuntimeException('Push VAPID keys are not configured.');
        }

        return new self($repository, $publicKey, $privateKey, $subject);
    }

    public function publicKey(): string
    {
        return $this->vapidPublicKey;
    }

    public function sendTestPush(): int
    {
        if (!class_exists(\Minishlink\WebPush\WebPush::class) || !class_exists(\Minishlink\WebPush\Subscription::class)) {
            throw new RuntimeException('Web Push library missing. Run: composer require minishlink/web-push');
        }

        $subscriptions = $this->repository->pushSubscriptions();
        if ($subscriptions === []) {
            throw new RuntimeException('No push subscriptions found. Enable "Background reminders" in Settings first.');
        }

        $auth = [
            'VAPID' => [
                'subject' => $this->vapidSubject,
                'publicKey' => $this->vapidPublicKey,
                'privateKey' => $this->vapidPrivateKey,
            ],
        ];
        $webPush = new \Minishlink\WebPush\WebPush($auth);
        $webPush->setReuseVAPIDHeaders(true);

        $payload = json_encode([
            'title' => 'RxTracker — test notification',
            'body' => 'Push notifications are working! Background alarms will fire when doses are due.',
            'tag' => 'rx-test-' . time(),
            'url' => 'index.php',
        ], JSON_THROW_ON_ERROR);

        foreach ($subscriptions as $sub) {
            $subscription = \Minishlink\WebPush\Subscription::create([
                'endpoint' => (string) $sub['endpoint'],
                'publicKey' => (string) $sub['p256dh_key'],
                'authToken' => (string) $sub['auth_key'],
            ]);
            $webPush->queueNotification($subscription, $payload);
        }

        $sent = 0;
        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $sent++;
                continue;
            }
            $endpoint = $report->getRequest()->getUri()->__toString();
            $statusCode = $report->getResponse()?->getStatusCode();
            if (in_array($statusCode, [404, 410], true)) {
                $this->repository->removePushSubscriptionByEndpoint($endpoint);
            }
        }

        if ($sent === 0) {
            throw new RuntimeException('Push delivery failed. Check your VAPID keys and that the subscription is still valid.');
        }

        return $sent;
    }

    public function sendDueReminders(DateTimeImmutable $now): int
    {
        if (!class_exists(\Minishlink\WebPush\WebPush::class) || !class_exists(\Minishlink\WebPush\Subscription::class)) {
            throw new RuntimeException('Web Push library missing. Run: composer require minishlink/web-push');
        }

        $due = $this->repository->dueReminderItemsNotYetPushed($now);
        if ($due === []) {
            return 0;
        }
        $subscriptions = $this->repository->pushSubscriptions();
        if ($subscriptions === []) {
            return 0;
        }

        $auth = [
            'VAPID' => [
                'subject' => $this->vapidSubject,
                'publicKey' => $this->vapidPublicKey,
                'privateKey' => $this->vapidPrivateKey,
            ],
        ];
        $webPush = new \Minishlink\WebPush\WebPush($auth);
        $webPush->setReuseVAPIDHeaders(true);
        $sentReminders = [];

        foreach ($due as $item) {
            $nonce = bin2hex(random_bytes(16));
            $payload = json_encode([
                'title' => (string) $item['name'] . ' (' . (string) $item['dose'] . ')',
                'body' => (string) ($item['postponed_until'] ? 'Snoozed dose due now' : 'Dose due now'),
                'tag' => 'dose|' . (int) $item['medication_id'] . '|' . (string) $item['scheduled_date'] . '|' . (string) $item['scheduled_time'],
                'url' => 'index.php',
                'nonce' => $nonce,
                'medication_id' => (int) $item['medication_id'],
                'scheduled_date' => (string) $item['scheduled_date'],
                'scheduled_time' => (string) $item['scheduled_time'],
            ], JSON_THROW_ON_ERROR);

            foreach ($subscriptions as $subscriptionRow) {
                $subscription = \Minishlink\WebPush\Subscription::create([
                    'endpoint' => (string) $subscriptionRow['endpoint'],
                    'publicKey' => (string) $subscriptionRow['p256dh_key'],
                    'authToken' => (string) $subscriptionRow['auth_key'],
                ]);
                $webPush->queueNotification($subscription, $payload);
            }
            $sentReminders[] = array_merge($item, ['_nonce' => $nonce]);
        }

        $hasSuccessfulDelivery = false;

        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $hasSuccessfulDelivery = true;
                continue;
            }
            $endpoint = $report->getRequest()->getUri()->__toString();
            $statusCode = $report->getResponse()?->getStatusCode();
            if (in_array($statusCode, [404, 410], true)) {
                $this->repository->removePushSubscriptionByEndpoint($endpoint);
            }
        }

        if (!$hasSuccessfulDelivery) {
            return 0;
        }

        $this->repository->markPushSentForReminderItems($sentReminders, $now);

        return count($sentReminders);
    }
}
