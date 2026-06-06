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
            $payload = json_encode([
                'title' => (string) $item['name'] . ' (' . (string) $item['dose'] . ')',
                'body' => (string) ($item['postponed_until'] ? 'Snoozed dose due now' : 'Dose due now'),
                'tag' => 'dose|' . (int) $item['medication_id'] . '|' . (string) $item['scheduled_date'] . '|' . (string) $item['scheduled_time'],
                'url' => 'index.php',
            ], JSON_THROW_ON_ERROR);

            foreach ($subscriptions as $subscriptionRow) {
                $subscription = \Minishlink\WebPush\Subscription::create([
                    'endpoint' => (string) $subscriptionRow['endpoint'],
                    'publicKey' => (string) $subscriptionRow['p256dh_key'],
                    'authToken' => (string) $subscriptionRow['auth_key'],
                ]);
                $webPush->queueNotification($subscription, $payload);
            }
            $sentReminders[] = $item;
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
