<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/MedicationRepository.php';
require __DIR__ . '/../includes/PushNotificationService.php';
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
}

try {
    $repository = new MedicationRepository(db());
    $graceMinutes = $repository->getMissedGraceMinutes();
    $now = new DateTimeImmutable('now');
    $repository->finalizeMissedDoses($now, $graceMinutes);

    $service = PushNotificationService::fromEnv($repository);
    $sent = $service->sendDueReminders($now);
    fwrite(STDOUT, 'push_sent=' . $sent . PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'push_error=' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
