<?php

/**
 * Missed-dose finalization for ALL users with active, adherence-tracked medications.
 *
 * The push-notification cron (send_due_push.php) already runs finalizeMissedDoses()
 * but only for users who have push subscriptions. This script covers every user,
 * including those without push notifications, so adherence reports stay accurate
 * for all accounts even when the browser isn't open.
 *
 * Recommended cron schedule (every 5 minutes):
 *   *\/5 * * * *  php /var/www/rx-tracker/scripts/finalize_missed.php >> /var/log/rx-tracker-missed.log 2>&1
 */

declare(strict_types=1);

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/MedicationRepository.php';
require __DIR__ . '/../includes/FamilyProfileRepository.php';

try {
    $now = new DateTimeImmutable('now');

    // Collect every user_id that has at least one active, adherence-enabled medication.
    $stmt = db()->query(
        "SELECT DISTINCT user_id FROM medications WHERE active = 1 AND adherence_enabled = 1"
    );
    $userIds = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'user_id');

    if ($userIds === []) {
        fwrite(STDOUT, 'finalized=0' . PHP_EOL);
        exit(0);
    }

    $familyRepo    = new FamilyProfileRepository(db());
    $totalFinalized = 0;

    foreach ($userIds as $userId) {
        $userId = (int) $userId;

        // Primary user (no family profile)
        $repo         = new MedicationRepository(db(), $userId, null);
        $graceMinutes = $repo->getMissedGraceMinutes();
        $repo->finalizeMissedDoses($now, $graceMinutes);
        $totalFinalized++;

        // Each family profile under this user
        foreach ($familyRepo->profilesForUser($userId) as $profile) {
            $profileRepo = new MedicationRepository(db(), $userId, (int) $profile['id']);
            $profileRepo->finalizeMissedDoses($now, $graceMinutes);
            $totalFinalized++;
        }
    }

    fwrite(STDOUT, 'finalized=' . $totalFinalized . PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'finalize_error=' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
