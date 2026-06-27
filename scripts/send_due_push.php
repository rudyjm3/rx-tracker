<?php

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/MedicationRepository.php';
require __DIR__ . '/../includes/FamilyProfileRepository.php';
require __DIR__ . '/../includes/PushNotificationService.php';
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
}

try {
    $now = new DateTimeImmutable('now');

    // Get all users who have push subscriptions
    $systemRepo = new MedicationRepository(db());
    $userIds    = $systemRepo->userIdsWithPushSubscriptions();

    if ($userIds === []) {
        fwrite(STDOUT, 'push_sent=0' . PHP_EOL);
        exit(0);
    }

    $totalSent  = 0;
    $familyRepo = new FamilyProfileRepository(db());

    foreach ($userIds as $userId) {
        $userId     = (int) $userId;

        // Primary user (no family profile).
        $repository   = new MedicationRepository(db(), $userId, null);
        $graceMinutes = $repository->getMissedGraceMinutes();
        $repository->finalizeMissedDoses($now, $graceMinutes);
        $service    = PushNotificationService::fromEnv($repository);
        $totalSent += $service->sendDueReminders($now);

        // Each family profile under this user.
        foreach ($familyRepo->profilesForUser($userId) as $profile) {
            $profileRepo = new MedicationRepository(db(), $userId, (int) $profile['id']);
            $profileRepo->finalizeMissedDoses($now, $graceMinutes);
            $profileService = PushNotificationService::fromEnv($profileRepo);
            $totalSent     += $profileService->sendDueReminders($now, (string) $profile['display_name']);
        }
    }

    fwrite(STDOUT, 'push_sent=' . $totalSent . PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'push_error=' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
