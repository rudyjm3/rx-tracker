<?php

declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $requestAction === 'poll_due') {
    header('Content-Type: application/json; charset=utf-8');
    $graceMinutes = $repository->getMissedGraceMinutes();
    $now = new DateTimeImmutable('now');
    $dueItems = $repository->dueReminderItems($now);
    $repository->finalizeMissedDoses($now, $graceMinutes);
    echo json_encode([
        'ok' => true,
        'grace_minutes' => $graceMinutes,
        'items' => $dueItems,
    ], JSON_THROW_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $requestAction === 'push_public_key') {
    header('Content-Type: application/json; charset=utf-8');
    $publicKey = trim((string) getenv('PUSH_VAPID_PUBLIC_KEY'));
    echo json_encode([
        'ok' => $publicKey !== '',
        'public_key' => $publicKey,
    ], JSON_THROW_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $requestAction === 'pain_trend') {
    header('Content-Type: application/json; charset=utf-8');
    $medicationId = (int) ($_GET['medication_id'] ?? 0);
    $daysParam = (int) ($_GET['days'] ?? 30);
    if ($daysParam === 0) {
        $data = $repository->painLevelTrendForDate($medicationId, date('Y-m-d'));
    } else {
        $days = max(1, min(365, $daysParam));
        $data = $repository->painLevelTrend($medicationId, $days);
    }
    echo json_encode(['ok' => true, 'data' => $data], JSON_THROW_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $requestAction === 'refill_history') {
    header('Content-Type: application/json; charset=utf-8');
    $medicationId = (int) ($_GET['medication_id'] ?? 0);
    if ($medicationId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid medication.'], JSON_THROW_ON_ERROR);
        exit;
    }
    $year = max(2000, min(2099, (int) ($_GET['year'] ?? (int) date('Y'))));
    $month = max(1, min(12, (int) ($_GET['month'] ?? (int) date('n'))));
    $monthStart = sprintf('%04d-%02d-01', $year, $month);
    $monthEnd = (new DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d');
    $refills = $repository->refillsForMonth($medicationId, $monthStart, $monthEnd);
    $stats = $repository->refillSummaryStats($medicationId, $year);
    echo json_encode([
        'ok' => true,
        'refills' => $refills,
        'stats' => $stats,
        'year' => $year,
        'month' => $month,
    ], JSON_THROW_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $requestAction === 'push_action') {
    header('Content-Type: application/json; charset=utf-8');
    $nonce = trim((string) ($_GET['nonce'] ?? ''));
    $act   = (string) ($_GET['act'] ?? '');
    if ($nonce === '' || !in_array($act, ['take', 'snooze'], true)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid request.'], JSON_THROW_ON_ERROR);
        exit;
    }
    try {
        $item = $repository->findAndConsumePushNonce($nonce);
        if ($item === null) {
            echo json_encode(['ok' => false, 'error' => 'Invalid or expired token.'], JSON_THROW_ON_ERROR);
            exit;
        }
        $medId   = (int) $item['medication_id'];
        $pDate   = (string) $item['scheduled_for_date'];
        $pTime   = (string) $item['scheduled_time'];
        if ($act === 'take') {
            $repository->recordDoseStatus($medId, $pDate, $pTime, 'taken', 'Taken via notification');
            echo json_encode(['ok' => true, 'message' => 'Dose marked as taken.'], JSON_THROW_ON_ERROR);
        } else {
            $minutes = (int) ($_GET['minutes'] ?? 15);
            if (!in_array($minutes, [5, 10, 15, 30], true)) {
                $minutes = 15;
            }
            $repository->postponeDose($medId, $pDate, $pTime, $minutes);
            // Remove the delivery log so the cron re-pushes when postponed_until arrives
            $repository->clearPushDeliveryLog($medId, $pDate, $pTime);
            echo json_encode(['ok' => true, 'message' => "Snoozed {$minutes} minutes."], JSON_THROW_ON_ERROR);
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}
