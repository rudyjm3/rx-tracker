<?php

declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/MedicationRepository.php';
require __DIR__ . '/includes/PushNotificationService.php';
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
}

function parseTimeValue(string $raw): string
{
    $value = trim($raw);

    if (!preg_match('/^(0?[1-9]|1[0-2]):([0-5]\d)\s*([AaPp][Mm])$/', $value, $matches)) {
        throw new RuntimeException('Time must be h:mm AM/PM (e.g. 8:00 AM, 2:30 PM).');
    }

    $hour = (int) $matches[1];
    $minute = (int) $matches[2];
    $period = strtoupper($matches[3]);

    if ($period === 'AM') {
        $hour = $hour === 12 ? 0 : $hour;
    } else {
        $hour = $hour === 12 ? 12 : $hour + 12;
    }

    return sprintf('%02d:%02d:00', $hour, $minute);
}

function parseDoseTimes(string $raw): array
{
    $segments = preg_split('/\s*,\s*/', trim($raw)) ?: [];
    $times = [];
    foreach ($segments as $segment) {
        if ($segment === '') {
            continue;
        }
        $times[] = parseTimeValue($segment);
    }
    $times = array_values(array_unique($times));
    sort($times);

    return $times;
}

function to12h(string $time): string
{
    $dt = DateTimeImmutable::createFromFormat('H:i', substr($time, 0, 5));
    return $dt ? $dt->format('g:i A') : $time;
}

function timeToMinutes(string $time): int
{
    [$hour, $minute] = array_map('intval', explode(':', substr($time, 0, 5)));
    return ($hour * 60) + $minute;
}

function isLate(array $log, int $graceMinutes): bool
{
    if ((string) $log['status'] !== 'taken') {
        return false;
    }
    $takenAt = (string) ($log['taken_at'] ?? '');
    $scheduledDate = (string) ($log['scheduled_for_date'] ?? '');
    $scheduledTime = (string) ($log['scheduled_time'] ?? '');
    if ($takenAt === '' || $scheduledDate === '' || $scheduledTime === '') {
        return false;
    }
    try {
        $scheduled = new DateTimeImmutable($scheduledDate . ' ' . $scheduledTime);
        $threshold = $scheduled->modify('+' . $graceMinutes . ' minutes');
        $taken = new DateTimeImmutable($takenAt);
        return $taken > $threshold;
    } catch (Throwable) {
        return false;
    }
}

function daysUntilRunout(array $medication): ?int
{
    $pillCount = (int) ($medication['pill_count'] ?? 0);
    if ($pillCount <= 0) {
        return 0;
    }
    $dosesPerDay = 0;
    if ((string) $medication['schedule_mode'] === 'fixed_times') {
        $dosesPerDay = count($medication['times'] ?? []);
    } elseif ((string) $medication['schedule_mode'] === 'interval') {
        $intervalHours = (int) ($medication['interval_hours'] ?? 0);
        if ($intervalHours > 0) {
            $dosesPerDay = (int) max(1, round(24 / $intervalHours));
        }
    }
    if ($dosesPerDay <= 0) {
        return null;
    }
    return (int) floor($pillCount / $dosesPerDay);
}

$repository = new MedicationRepository(db());
$error = null;
$notice = null;
$today = today();
$currentTime = (new DateTimeImmutable())->format('H:i');
$page = (string) ($_GET['page'] ?? 'dashboard');
$requestAction = (string) ($_GET['action'] ?? '');

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

$jsonResponse = false;

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $jsonResponse = post_string('json_response') === '1';
    try {
        if (!verify_csrf_token(post_string('csrf_token'))) {
            throw new RuntimeException('Session expired, refresh and retry.');
        }

        $action = post_string('action');

        if ($action === 'add_medication' || $action === 'update_medication') {
            $id = (int) post_string('medication_id');
            $name = post_string('name');
            $dose = post_string('dose');
            $instructions = post_string('instructions');
            $scheduleMode = post_string('schedule_mode');
            $doseTimes = parseDoseTimes(post_string('dose_times'));
            $intervalHoursRaw = post_string('interval_hours');
            $intervalHours = $intervalHoursRaw === '' ? null : max(1, (int) $intervalHoursRaw);
            $firstDoseRaw = post_string('first_dose_time');
            $firstDoseTime = $firstDoseRaw === '' ? null : parseTimeValue($firstDoseRaw);
            $asNeeded = post_string('as_needed') === '1';
            $pillCount = max(0, (int) post_string('pill_count'));
            $lowSupplyThreshold = max(0, (int) post_string('low_supply_threshold'));
            $trackDoseFeedback = post_string('track_dose_feedback') === '1';
            $setId = substr(trim(post_string('set_id')), 0, 64);
            $groupIdRaw = (int) post_string('group_id');

            if ($name === '' || $dose === '') {
                throw new RuntimeException('Medication name and dose are required.');
            }

            if ($scheduleMode === 'interval') {
                $doseTimes = [];
            }

            if ($action === 'add_medication') {
                $repository->createMedication($name, $dose, $instructions, $scheduleMode, $doseTimes, $intervalHours, $firstDoseTime, $asNeeded, $pillCount, $lowSupplyThreshold, $trackDoseFeedback, $setId);
                $newMedicationId = $repository->lastInsertedMedicationId();
                if ($groupIdRaw > 0) {
                    $repository->addMedicationToGroup($groupIdRaw, $newMedicationId);
                }
            } else {
                $repository->updateMedication($id, $name, $dose, $instructions, $scheduleMode, $doseTimes, $intervalHours, $firstDoseTime, $asNeeded, $pillCount, $lowSupplyThreshold, $trackDoseFeedback, $setId);
                if ($groupIdRaw > 0) {
                    $repository->addMedicationToGroup($groupIdRaw, $id);
                } else {
                    $repository->removeMedicationFromGroup($id);
                }
            }

            redirect_home();
        }

        if ($action === 'create_group') {
            $groupName = trim(post_string('group_name'));
            $groupTime = post_string('group_time');
            if ($groupName === '') {
                throw new RuntimeException('Group name is required.');
            }
            $parsedTime = parseTimeValue($groupTime);
            $newGroupId = $repository->createGroup($groupName, $parsedTime);
            if ($jsonResponse) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => true,
                    'group_id' => $newGroupId,
                    'group_name' => $groupName,
                    'group_time_display' => to12h($parsedTime),
                    'ungrouped' => $repository->ungroupedActiveMedications(),
                ], JSON_THROW_ON_ERROR);
                exit;
            }
            redirect_home();
        }

        if ($action === 'update_group') {
            $groupId = (int) post_string('group_id');
            $groupName = trim(post_string('group_name'));
            $groupTime = post_string('group_time');
            if ($groupName === '') {
                throw new RuntimeException('Group name is required.');
            }
            $parsedTime = parseTimeValue($groupTime);
            $repository->updateGroup($groupId, $groupName, $parsedTime);
            if ($jsonResponse) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => true,
                    'group_id' => $groupId,
                    'group_name' => $groupName,
                    'group_time_display' => to12h($parsedTime),
                ], JSON_THROW_ON_ERROR);
                exit;
            }
            redirect_home();
        }

        if ($action === 'delete_group') {
            $repository->deleteGroup((int) post_string('group_id'));
            if ($jsonResponse) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
                exit;
            }
            redirect_home();
        }

        if ($action === 'add_medication_to_group') {
            $repository->addMedicationToGroup((int) post_string('group_id'), (int) post_string('medication_id'));
            if ($jsonResponse) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => true,
                    'ungrouped' => $repository->ungroupedActiveMedications(),
                ], JSON_THROW_ON_ERROR);
                exit;
            }
            redirect_home();
        }

        if ($action === 'remove_medication_from_group') {
            $medId = (int) post_string('medication_id');
            $repository->removeMedicationFromGroup($medId);
            if ($jsonResponse) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => true,
                    'medication_id' => $medId,
                    'ungrouped' => $repository->ungroupedActiveMedications(),
                ], JSON_THROW_ON_ERROR);
                exit;
            }
            redirect_home();
        }

        if ($action === 'mark_dose') {
            $rawPainLevel = post_string('pain_level');
            $painLevel = $rawPainLevel !== '' ? (int) $rawPainLevel : null;
            $repository->recordDoseStatus((int) post_string('medication_id'), post_string('scheduled_date'), post_string('scheduled_time'), post_string('status'), post_string('note'), $painLevel);
            if ($jsonResponse) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
                exit;
            }
            redirect_home();
        }

        if ($action === 'log_dose_now') {
            $medicationId = (int) post_string('medication_id');
            if ($medicationId <= 0) {
                throw new RuntimeException('Choose a medication first.');
            }
            $repository->logDoseNow($medicationId, post_string('note'));
            if ($jsonResponse) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
                exit;
            }
            redirect_home();
        }

        if ($action === 'deactivate_medication') {
            $medId = (int) post_string('medication_id');
            $repository->deactivateMedication($medId);
            if ($jsonResponse) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'medication_id' => $medId], JSON_THROW_ON_ERROR);
                exit;
            }
            redirect_home();
        }

        if ($action === 'activate_medication') {
            $medId = (int) post_string('medication_id');
            $repository->activateMedication($medId);
            if ($jsonResponse) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true, 'medication_id' => $medId], JSON_THROW_ON_ERROR);
                exit;
            }
            redirect_home();
        }

        if ($action === 'log_refill') {
            $medicationId = (int) post_string('medication_id');
            $refillDate = post_string('refill_date');
            $amount = (int) post_string('amount');
            $note = substr(trim(post_string('note')), 0, 255);
            if ($medicationId <= 0) {
                throw new RuntimeException('Invalid medication.');
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $refillDate)) {
                throw new RuntimeException('Invalid refill date.');
            }
            if ($amount <= 0) {
                throw new RuntimeException('Refill amount must be greater than 0.');
            }
            $repository->logRefill($medicationId, $refillDate, $amount, $note);
            if ($jsonResponse) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
                exit;
            }
            redirect_home();
        }

        if ($action === 'postpone_dose') {
            $delayMinutes = (int) post_string('postpone_minutes');
            $repository->postponeDose(
                (int) post_string('medication_id'),
                post_string('scheduled_date'),
                post_string('scheduled_time'),
                $delayMinutes
            );
            if ($jsonResponse) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
                exit;
            }
            header('Location: index.php?notice=' . urlencode('Dose snoozed'));
            exit;
        }

        if ($action === 'save_settings') {
            $graceMinutes = (int) post_string('missed_grace_minutes');
            $repository->setMissedGraceMinutes($graceMinutes);
            $repository->setSnoozeMinutes((int) post_string('snooze_minutes'));
            header('Location: index.php?page=settings&notice=Settings saved');
            exit;
        }

        if ($action === 'save_push_subscription') {
            header('Content-Type: application/json; charset=utf-8');
            $endpoint = trim((string) ($_POST['endpoint'] ?? ''));
            $p256dh = trim((string) ($_POST['p256dh'] ?? ''));
            $auth = trim((string) ($_POST['auth'] ?? ''));
            $repository->upsertPushSubscription($endpoint, $p256dh, $auth, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
            echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
            exit;
        }

        if ($action === 'send_test_push') {
            header('Content-Type: application/json; charset=utf-8');
            try {
                $service = PushNotificationService::fromEnv($repository);
                $sent = $service->sendTestPush();
                echo json_encode(['ok' => true, 'count' => $sent], JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR);
            }
            exit;
        }

        if ($action === 'remove_push_subscription') {
            header('Content-Type: application/json; charset=utf-8');
            $endpoint = trim((string) ($_POST['endpoint'] ?? ''));
            $repository->removePushSubscriptionByEndpoint($endpoint);
            echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
            exit;
        }
    } catch (Throwable $exception) {
        $isPushAction = in_array(post_string('action'), ['save_push_subscription', 'remove_push_subscription', 'send_test_push'], true);
        if ($jsonResponse || $isPushAction) {
            header('Content-Type: application/json; charset=utf-8');
            if ($isPushAction) {
                http_response_code(400);
            }
            echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR);
            exit;
        }
        $error = $exception->getMessage();
    }
}

$graceMinutes = $repository->getMissedGraceMinutes();
$snoozeMinutes = $repository->getSnoozeMinutes();
$repository->finalizeMissedDoses(new DateTimeImmutable('now'), $graceMinutes);
$notice = trim((string) ($_GET['notice'] ?? '')) ?: null;

$medications = $repository->activeMedications();
$inactiveMedications = $repository->inactiveMedications();
$medicationPlanCount = count($medications);
$inactiveMedicationCount = count($inactiveMedications);
$todaySchedule = $repository->todaySchedule($today);
$recentLogs = $repository->recentLogs(null, 50);
$missedCount = $repository->missedDoseCount($today, $currentTime);

$requiredRows = array_filter($todaySchedule, static fn(array $row): bool => !$row['as_needed']);
$takenRows = array_filter($requiredRows, static fn(array $row): bool => (string) ($row['status'] ?? '') === 'taken');
$adherence = count($requiredRows) > 0 ? (int) round((count($takenRows) / count($requiredRows)) * 100) : 0;
$nextDose = null;
foreach ($todaySchedule as $row) {
    if (!in_array((string) ($row['status'] ?? ''), ['taken', 'skipped', 'missed'], true)) {
        $nextDose = $row;
        break;
    }
}
$nextDoseWindow = [];
if ($nextDose !== null) {
    $startMinutes = timeToMinutes((string) $nextDose['reminder_time']);
    $endMinutes = $startMinutes + (4 * 60);
    foreach ($todaySchedule as $row) {
        if (in_array((string) ($row['status'] ?? ''), ['taken', 'skipped', 'missed'], true)) {
            continue;
        }
        $rowMinutes = timeToMinutes((string) $row['reminder_time']);
        if ($rowMinutes >= $startMinutes && $rowMinutes <= $endMinutes) {
            $nextDoseWindow[] = $row;
        }
    }
}

// Hero next dose: up to 2 slots, collapsing grouped meds into one slot each
$heroNextDoseItems = [];
$seenGroupIds = [];
foreach ($nextDoseWindow as $heroRow) {
    if (count($heroNextDoseItems) >= 2) {
        break;
    }
    $heroGid = $heroRow['group_id'];
    if ($heroGid !== null && in_array($heroGid, $seenGroupIds, true)) {
        continue;
    }
    if ($heroGid !== null) {
        $seenGroupIds[] = $heroGid;
        $heroRow['_group_members'] = array_values(
            array_filter($nextDoseWindow, static fn(array $r): bool => $r['group_id'] === $heroGid)
        );
    } else {
        $heroRow['_group_members'] = [];
    }
    $heroNextDoseItems[] = $heroRow;
}

$editId = (int) ($_GET['edit'] ?? 0);
$editing = $editId > 0 ? $repository->findMedication($editId) : null;
$editingGroupId = $editing ? (($repository->groupForMedication((int) $editing['id']))['id'] ?? 0) : 0;
$groups = $repository->allGroups();
$ungroupedMedications = $repository->ungroupedActiveMedications();

$lowSupplyMeds = array_values(array_filter($medications, static fn(array $m): bool =>
    (int) ($m['low_supply_threshold'] ?? 0) > 0 &&
    (int) ($m['pill_count'] ?? 0) <= (int) ($m['low_supply_threshold'] ?? 0)
));

$onTimeCount = 0;
$lateCount = 0;
foreach ($recentLogs as $log) {
    if ((string) $log['status'] !== 'taken') continue;
    if ((string) $log['scheduled_for_date'] !== $today) continue;
    if (isLate($log, $graceMinutes)) {
        $lateCount++;
    } else {
        $onTimeCount++;
    }
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#1269ff">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <title>RxTracker</title>
  <link rel="stylesheet" href="assets/css/styles.css">
  <link rel="icon" type="image/x-icon" href="assets/icons/favicon.ico">
  <link rel="icon" type="image/png" sizes="192x192" href="assets/icons/icon-192.png">
  <link rel="apple-touch-icon" href="assets/icons/icon-192.png">
  <link rel="manifest" href="manifest.json">
  <script src="assets/js/app.js" defer></script>
</head>
<body>
<main class="app-shell">
  <nav class="top-nav">
    <span class="nav-brand">
      <img src="assets/icons/icon-192.png" alt="" class="nav-logo" aria-hidden="true" width="28" height="28">
      RxTracker
    </span>
    <div class="nav-links">
      <a href="index.php"<?= !in_array($page, ['settings', 'calendar', 'export', 'medications'], true) ? ' class="is-active"' : '' ?>>Dashboard</a>
      <a href="index.php?page=medications"<?= $page === 'medications' ? ' class="is-active"' : '' ?>>Medications</a>
      <a href="index.php?page=calendar"<?= $page === 'calendar' ? ' class="is-active"' : '' ?>>Calendar</a>
      <a href="index.php?page=export"<?= $page === 'export' ? ' class="is-active"' : '' ?>>Export</a>
      <a href="index.php?page=settings"<?= $page === 'settings' ? ' class="is-active"' : '' ?>>Settings</a>
    </div>
    <button class="nav-hamburger" aria-label="Menu" aria-expanded="false" data-nav-toggle>&#9776;</button>
  </nav>

  <?php if (!in_array($page, ['settings', 'calendar', 'export', 'medications'], true)): ?>
  <section class="hero">
    <div class="hero-left">
      <div class="hero-card hero-med-card" hidden>
        <div class="hero-med-card-header">
          <div class="hero-med-card-title">
            <span class="stat-label">Medication plan</span>
            <span class="count-badge hero-count-badge"><?= e((string) $medicationPlanCount) ?></span>
          </div>
        </div>
        <div class="hero-med-card-actions">
          <button type="button" data-open-medication-modal>Add</button>
          <button type="button" class="hero-ellipsis-btn" data-open-med-plan-modal aria-label="View medication plan">&#8943;</button>
        </div>
      </div>

      <div class="hero-card hero-next-dose-panel" aria-label="Next dose">
        <span class="stat-label">Next dose</span>
        <?php if ($heroNextDoseItems !== []): ?>
          <?php foreach ($heroNextDoseItems as $ndIndex => $ndItem): ?>
            <div class="hero-next-dose-entry<?= $ndIndex > 0 ? ' hero-next-dose-entry--subtle' : '' ?>">
              <span class="hero-next-dose-time"><?= e(to12h((string) $ndItem['reminder_time'])) ?></span>
              <?php if ($ndItem['group_id'] !== null): ?>
                <p class="hero-next-dose-name"><?= e((string) $ndItem['group_name']) ?></p>
                <p class="hero-next-dose-meta"><?= e((string) count($ndItem['_group_members'])) ?> medication<?= count($ndItem['_group_members']) !== 1 ? 's' : '' ?> in group</p>
                <button type="button" class="group-meds-toggle" data-group-meds-toggle>view group meds</button>
                <div class="group-meds-list" hidden>
                  <?php foreach ($ndItem['_group_members'] as $ndMember): ?>
                    <div class="group-meds-member">
                      <span class="hero-med-name"><?= e((string) $ndMember['name']) ?></span>
                      <span class="hero-med-dose"><?= e((string) $ndMember['dose']) ?></span>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <p class="hero-next-dose-name">
                  <?= e((string) $ndItem['name']) ?>
                  <span class="hero-next-dose-dose"><?= e((string) $ndItem['dose']) ?><?= $ndItem['as_needed'] ? ' &middot; PRN' : '' ?></span>
                </p>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <p class="hero-copy">All scheduled doses complete for today.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="hero-card" aria-label="Today's adherence summary">
      <span class="stat-label">Today's adherence</span>
      <div class="adherence-ring-wrap">
        <svg class="adherence-ring" viewBox="0 0 100 100" aria-hidden="true">
          <circle class="adherence-ring-track" cx="50" cy="50" r="42" fill="none"/>
          <circle class="adherence-ring-fill" cx="50" cy="50" r="42" fill="none" data-adherence-pct="<?= e((string) $adherence) ?>"/>
        </svg>
        <span class="adherence-ring-num" data-adherence-num>0%</span>
      </div>
      <span>Required doses taken: <?= e((string) count($takenRows)) ?> of <?= e((string) count($requiredRows)) ?></span>
      <?php if ($onTimeCount + $lateCount > 0): ?>
        <span>On time: <?= e((string) $onTimeCount) ?> &middot; Late: <?= e((string) $lateCount) ?></span>
      <?php endif; ?>
      <span>Missed required doses today: <?= e((string) $missedCount) ?></span>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($notice !== null): ?><div class="notice"><?= e($notice) ?></div><?php endif; ?>
  <?php if ($error !== null): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>

  <div class="med-plan-modal-overlay" id="med-plan-modal" role="dialog" aria-modal="true" aria-label="Medication plan" hidden>
    <div class="med-plan-modal-inner">
      <div class="med-plan-modal-header">
        <div class="medication-plan-title-wrap">
          <h2>Medication plan</h2>
          <span class="count-badge"><?= e((string) $medicationPlanCount) ?></span>
        </div>
        <div class="medication-plan-actions">
          <button type="button" data-open-medication-modal data-med-plan-action="medication">Add medication</button>
          <button type="button" data-open-create-group-form-header data-med-plan-action="groups" hidden>+ Create group</button>
          <button type="button" class="secondary med-plan-close-btn" data-close-med-plan-modal aria-label="Close">&times;</button>
        </div>
      </div>
      <div class="medication-plan-tabs" role="tablist" aria-label="Medication status lists">
        <button type="button" class="secondary plan-tab is-active" data-plan-tab="active" role="tab" aria-selected="true" aria-controls="active-medications-panel" id="active-medications-tab">Active (<?= e((string) $medicationPlanCount) ?>)</button>
        <button type="button" class="secondary plan-tab" data-plan-tab="inactive" role="tab" aria-selected="false" aria-controls="inactive-medications-panel" id="inactive-medications-tab">Inactive (<?= e((string) $inactiveMedicationCount) ?>)</button>
        <button type="button" class="secondary plan-tab" data-plan-tab="groups" role="tab" aria-selected="false" aria-controls="groups-panel" id="groups-tab">Groups (<?= e((string) count($groups)) ?>)</button>
      </div>
      <?php include __DIR__ . '/includes/medication-plan-tabs.php'; ?>

    </div>
  </div>

  <div class="modal-overlay<?= $editing ? ' is-open' : '' ?>" data-medication-modal>
    <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="medication-modal-title">
      <div class="modal-header">
        <h2 id="medication-modal-title"><?= $editing ? 'Edit medication' : 'Add medication' ?></h2>
        <button type="button" class="icon-button" data-close-medication-modal aria-label="Close modal">&#10005;</button>
      </div>
      <div class="modal-scroll">
      <form class="medication-form" method="post" action="index.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $editing ? 'update_medication' : 'add_medication' ?>">
        <input type="hidden" name="medication_id" value="<?= e((string) ($editing['id'] ?? 0)) ?>">
        <input type="hidden" name="set_id" data-set-id-input value="<?= e((string) ($editing['set_id'] ?? '')) ?>">

        <label class="autocomplete-wrap">Name
          <input name="name" required autocomplete="off" data-med-name-input value="<?= e((string) ($editing['name'] ?? '')) ?>">
          <ul class="autocomplete-dropdown" data-autocomplete-dropdown hidden></ul>
        </label>
        <label>Dose<input name="dose" required value="<?= e((string) ($editing['dose'] ?? '')) ?>"></label>
        <label>Schedule type
          <select name="schedule_mode">
            <option value="fixed_times" <?= (($editing['schedule_mode'] ?? '') === 'fixed_times') ? 'selected' : '' ?>>Fixed times</option>
            <option value="interval" <?= (($editing['schedule_mode'] ?? '') === 'interval') ? 'selected' : '' ?>>Every X hours</option>
          </select>
        </label>
        <label>Fixed dose times (comma separated)
          <input name="dose_times" placeholder="8:00 AM, 2:00 PM, 9:00 PM" value="<?= e(isset($editing['times']) ? implode(', ', array_map('to12h', $editing['times'])) : '') ?>">
        </label>
        <label>Interval hours
          <input type="number" min="1" max="24" name="interval_hours" value="<?= e((string) ($editing['interval_hours'] ?? '')) ?>">
        </label>
        <label>First dose time
          <input name="first_dose_time" placeholder="8:00 AM" value="<?= e((string) (isset($editing['first_dose_time']) ? to12h((string) $editing['first_dose_time']) : '')) ?>">
        </label>
        <label>As needed (PRN)
          <select name="as_needed">
            <option value="0" <?= ((int) ($editing['as_needed'] ?? 0) === 0) ? 'selected' : '' ?>>No</option>
            <option value="1" <?= ((int) ($editing['as_needed'] ?? 0) === 1) ? 'selected' : '' ?>>Yes</option>
          </select>
        </label>
        <label>Track dose feedback (pain &amp; comments)
          <select name="track_dose_feedback">
            <option value="0" <?= ((int) ($editing['track_dose_feedback'] ?? 0) === 0) ? 'selected' : '' ?>>No</option>
            <option value="1" <?= ((int) ($editing['track_dose_feedback'] ?? 0) === 1) ? 'selected' : '' ?>>Yes &mdash; show feedback after each dose</option>
          </select>
        </label>
        <label>Pill count<input type="number" min="0" name="pill_count" value="<?= e((string) ($editing['pill_count'] ?? 0)) ?>"></label>
        <label>Low supply threshold (pills)
          <input type="number" min="0" name="low_supply_threshold" value="<?= e((string) ($editing['low_supply_threshold'] ?? 0)) ?>">
        </label>
        <label>Instructions<input name="instructions" value="<?= e((string) ($editing['instructions'] ?? '')) ?>"></label>
        <label>Medication group <span class="field-optional">(optional)</span>
          <select name="group_id">
            <option value="0"<?= $editingGroupId === 0 ? ' selected' : '' ?>>No group (individual)</option>
            <?php foreach ($groups as $grp): ?>
              <option value="<?= e((string) $grp['id']) ?>"<?= $editingGroupId === (int) $grp['id'] ? ' selected' : '' ?>><?= e($grp['name']) ?> &mdash; <?= e(to12h($grp['scheduled_time'])) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button type="submit"><?= $editing ? 'Save changes' : 'Add medication' ?></button>
      </form>
      </div>
    </div>
  </div>

  <div class="modal-overlay" data-pain-graph-modal>
    <div class="modal-dialog pain-graph-dialog" role="dialog" aria-modal="true" aria-labelledby="pain-graph-title">
      <div class="modal-header">
        <h2 id="pain-graph-title" data-pain-graph-title>Pain Level Trend</h2>
        <button type="button" class="icon-button" data-close-pain-graph aria-label="Close pain graph">&#10005;</button>
      </div>
      <div class="modal-scroll">
        <div class="pain-graph-range-tabs" role="group" aria-label="Date range">
          <button class="range-tab" data-range="0">Today</button>
          <button class="range-tab is-active" data-range="7">7 days</button>
          <button class="range-tab" data-range="30">30 days</button>
          <button class="range-tab" data-range="90">90 days</button>
        </div>
        <div class="pain-graph-body" data-pain-graph-body></div>
        <p class="pain-graph-empty" data-pain-graph-empty hidden>No pain level data recorded for this period.</p>
      </div>
    </div>
  </div>

  <div class="modal-overlay" data-dose-feedback-modal>
    <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="feedback-modal-title">
      <div class="modal-header">
        <div>
          <h2 id="feedback-modal-title">How are you feeling?</h2>
          <p class="feedback-queue-progress" data-feedback-queue-progress hidden></p>
        </div>
        <button type="button" class="icon-button" data-close-feedback-modal aria-label="Close feedback modal">&#10005;</button>
      </div>
      <div class="modal-scroll">
      <form method="post" action="index.php" class="stacked-form" data-feedback-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="mark_dose">
        <input type="hidden" name="status" value="taken">
        <input type="hidden" name="medication_id" data-feedback-medication-id value="">
        <input type="hidden" name="scheduled_date" data-feedback-scheduled-date value="">
        <input type="hidden" name="scheduled_time" data-feedback-scheduled-time value="">
        <input type="hidden" name="pain_level" data-feedback-pain-level value="">

        <div class="feedback-pain-section" data-feedback-pain-section>
          <p class="feedback-pain-label">Pain level <span class="feedback-pain-hint">(1 = minimal &mdash; 10 = severe)</span></p>
          <div class="pain-level-selector" role="group" aria-label="Select pain level">
            <?php for ($i = 1; $i <= 10; $i++): ?>
              <button type="button" class="pain-level-btn" data-pain-level="<?= $i ?>" aria-label="Pain level <?= $i ?>"><?= $i ?></button>
            <?php endfor; ?>
          </div>
        </div>

        <label>Comments <span class="field-optional">(optional)</span>
          <textarea name="note" data-feedback-note rows="3" maxlength="250" placeholder="How are you feeling? Any side effects or observations?"></textarea>
          <span class="char-counter" data-feedback-char-counter>[0/250]</span>
        </label>

        <div class="feedback-actions">
          <button type="submit">Log dose</button>
          <button type="button" class="secondary" data-skip-feedback>Take without comment</button>
        </div>
      </form>
      </div>
    </div>
  </div>

  <!-- Medication detail modal -->
  <div class="modal-overlay" data-med-detail-modal>
    <div class="modal-dialog med-detail-dialog" role="dialog" aria-modal="true" aria-labelledby="med-detail-title">
      <div class="modal-header">
        <h2 id="med-detail-title" data-med-detail-title></h2>
        <button type="button" class="icon-button" data-close-med-detail aria-label="Close">&#10005;</button>
      </div>
      <div class="modal-scroll">
        <div data-med-detail-body>
          <p class="pain-graph-loading">Loading&hellip;</p>
        </div>
      </div>
    </div>
  </div>

  <!-- Image lightbox -->
  <div class="image-lightbox-overlay" data-image-lightbox>
    <div class="image-lightbox-dialog" data-image-lightbox-dialog>
      <button type="button" class="icon-button image-lightbox-close" data-close-lightbox aria-label="Close image">&#10005;</button>
      <img class="image-lightbox-img" data-lightbox-img src="" alt="">
      <p class="image-lightbox-caption" data-lightbox-caption></p>
    </div>
  </div>

  <?php if ($page === 'medications'): ?>
  <section class="medications-page">
    <div class="medications-page-header">
      <div class="medications-page-title">
        <h1>Medication Plan</h1>
        <span class="count-badge"><?= e((string) $medicationPlanCount) ?></span>
      </div>
      <div class="medication-plan-tabs" role="tablist" aria-label="Medication status lists">
        <button type="button" class="secondary plan-tab is-active" data-plan-tab="active" role="tab" aria-selected="true" aria-controls="active-medications-panel" id="active-medications-tab">Active (<?= e((string) $medicationPlanCount) ?>)</button>
        <button type="button" class="secondary plan-tab" data-plan-tab="inactive" role="tab" aria-selected="false" aria-controls="inactive-medications-panel" id="inactive-medications-tab">Inactive (<?= e((string) $inactiveMedicationCount) ?>)</button>
        <button type="button" class="secondary plan-tab" data-plan-tab="groups" role="tab" aria-selected="false" aria-controls="groups-panel" id="groups-tab">Groups (<?= e((string) count($groups)) ?>)</button>
      </div>
      <div class="medications-page-actions">
        <button type="button" data-open-medication-modal>Add medication</button>
      </div>
    </div>
    <?php include __DIR__ . '/includes/medication-plan-tabs.php'; ?>
  </section>
  <?php endif; ?>

  <?php if ($page === 'settings'): ?>
    <?php
      $vapidConfigured = trim((string) getenv('PUSH_VAPID_PUBLIC_KEY')) !== ''
          && trim((string) getenv('PUSH_VAPID_PRIVATE_KEY')) !== ''
          && trim((string) getenv('PUSH_VAPID_SUBJECT')) !== '';
      $webPushInstalled = is_file(__DIR__ . '/vendor/autoload.php')
          && class_exists(\Minishlink\WebPush\WebPush::class);
      $lastPushSentAt = $repository->lastPushSentAt();
    ?>
    <section class="panel settings-panel">
      <div class="panel-heading"><h2>Reminder Settings</h2></div>
      <form method="post" action="index.php?page=settings" class="stacked-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_settings">
        <label>Missed-dose grace period
          <select name="missed_grace_minutes">
            <option value="30"<?= $graceMinutes === 30 ? ' selected' : '' ?>>30 minutes</option>
            <option value="60"<?= $graceMinutes === 60 ? ' selected' : '' ?>>60 minutes</option>
          </select>
        </label>
        <label>Default snooze duration
          <select name="snooze_minutes">
            <option value="5"<?= $snoozeMinutes === 5 ? ' selected' : '' ?>>5 minutes</option>
            <option value="10"<?= $snoozeMinutes === 10 ? ' selected' : '' ?>>10 minutes</option>
            <option value="15"<?= $snoozeMinutes === 15 ? ' selected' : '' ?>>15 minutes</option>
            <option value="30"<?= $snoozeMinutes === 30 ? ' selected' : '' ?>>30 minutes</option>
          </select>
        </label>
        <button type="submit">Save settings</button>
      </form>
      <hr>
      <h3 class="settings-subsection-heading">Alarm &amp; Notification Settings</h3>
      <p class="settings-subsection-hint">Enable both toggles below for full coverage — sound while the app is open, push alerts when it&rsquo;s closed.</p>
      <div class="notification-toggles">
        <div class="notification-toggle-row">
          <label class="toggle-control" for="sound-toggle">
            <input type="checkbox" id="sound-toggle" data-sound-toggle>
            <span class="toggle-slider" aria-hidden="true"></span>
            <span class="toggle-label">Alarm sound</span>
          </label>
          <p class="toggle-description">Audible alarm when a dose is due <strong>while the app is open</strong>. Works offline — no permission required. On by default.</p>
        </div>
        <div class="notification-toggle-row">
          <label class="toggle-control" for="vibration-toggle">
            <input type="checkbox" id="vibration-toggle" data-vibration-toggle>
            <span class="toggle-slider" aria-hidden="true"></span>
            <span class="toggle-label">Vibration</span>
          </label>
          <p class="toggle-description">Device vibration for in-app alarms. On by default. Turn off if you only want sound (e.g. when your phone is on a surface in a meeting).</p>
        </div>
        <div class="notification-toggle-row">
          <label class="toggle-control" for="reminders-toggle">
            <input type="checkbox" id="reminders-toggle" data-enable-reminders>
            <span class="toggle-slider" aria-hidden="true"></span>
            <span class="toggle-label">Background reminders</span>
          </label>
          <p class="toggle-description">Push notification delivered to your device <strong>even when the app is closed</strong>. Requires internet, notification permission, and a service worker. Tap "Take Now" or "Snooze" directly from the notification tray.</p>
          <span class="muted" data-reminder-status>Background push reminders are currently disabled on this device.</span>
        </div>
      </div>
      <div class="in-app-alert" data-in-app-alert hidden></div>
    </section>

    <section class="panel push-status-panel" data-push-status-panel>
      <div class="panel-heading"><h2>Push Notification Status</h2></div>
      <p class="push-status-intro">All checks must pass for background alarms to fire when the app is closed.</p>

      <div class="push-check-list">

        <div class="push-check-row">
          <span class="push-check-icon <?= $vapidConfigured ? 'push-check-ok' : 'push-check-fail' ?>" aria-hidden="true"><?= $vapidConfigured ? '✓' : '✗' ?></span>
          <div class="push-check-body">
            <strong>VAPID keys configured</strong>
            <?php if (!$vapidConfigured): ?>
              <p class="push-check-hint">Run <code>php scripts/generate_vapid_keys.php</code>, then paste the output into your <code>.env</code> file and restart the server.</p>
            <?php endif; ?>
          </div>
        </div>

        <div class="push-check-row">
          <span class="push-check-icon <?= $webPushInstalled ? 'push-check-ok' : 'push-check-fail' ?>" aria-hidden="true"><?= $webPushInstalled ? '✓' : '✗' ?></span>
          <div class="push-check-body">
            <strong>PHP web-push library installed</strong>
            <?php if (!$webPushInstalled): ?>
              <p class="push-check-hint">Run <code>composer install</code> in the project root.</p>
            <?php endif; ?>
          </div>
        </div>

        <div class="push-check-row" data-check-sw>
          <span class="push-check-icon push-check-pending" aria-hidden="true">…</span>
          <div class="push-check-body">
            <strong>Service worker registered</strong>
            <p class="push-check-hint" data-check-hint hidden></p>
          </div>
        </div>

        <div class="push-check-row" data-check-permission>
          <span class="push-check-icon push-check-pending" aria-hidden="true">…</span>
          <div class="push-check-body">
            <strong>Notification permission granted</strong>
            <p class="push-check-hint" data-check-hint hidden></p>
          </div>
        </div>

        <div class="push-check-row" data-check-subscription>
          <span class="push-check-icon push-check-pending" aria-hidden="true">…</span>
          <div class="push-check-body">
            <strong>Push subscription active on this device</strong>
            <p class="push-check-hint" data-check-hint hidden></p>
          </div>
        </div>

        <div class="push-check-row">
          <span class="push-check-icon push-check-warn" aria-hidden="true">⚠</span>
          <div class="push-check-body">
            <strong>Cron job scheduled</strong>
            <p class="push-check-hint">Cannot be verified from the browser. Schedule <code>scripts/send_due_push.php</code> to run every minute on your server (cron on Linux/macOS, Task Scheduler on Windows).
            <?php if ($lastPushSentAt !== null): ?>
              <br>Last push sent: <strong><?= e((new DateTimeImmutable($lastPushSentAt))->format('M j, g:i A')) ?></strong>.
            <?php else: ?>
              <br>No pushes sent yet &mdash; the cron may not be running, or no doses have been due since setup.
            <?php endif; ?>
            </p>
          </div>
        </div>

      </div>

      <div class="push-test-row">
        <button type="button" class="secondary" data-test-push-btn disabled>Send test push</button>
        <span class="push-test-status muted" data-test-push-status></span>
      </div>
    </section>

    <p class="disclaimer">RxTracker is a tracking aid only and does not provide medical advice or clinical decision support.</p>
  </main>
  </body>
  </html>
  <?php
  exit;
  ?>
  <?php endif; ?>

  <?php if ($page === 'calendar'): ?>
  <?php
    $monthParam = (string) ($_GET['m'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/', $monthParam)) {
        $monthParam = date('Y-m');
    }
    $monthStart = $monthParam . '-01';
    $calMonthDt = new DateTimeImmutable($monthStart);
    $monthEnd = $calMonthDt->modify('last day of this month')->format('Y-m-d');
    $calendarMarkers = $repository->calendarMarkersForMonth($monthStart, $monthEnd);
    $prevMonth = $calMonthDt->modify('-1 month')->format('Y-m');
    $nextMonth = $calMonthDt->modify('+1 month')->format('Y-m');
    $monthLabel = $calMonthDt->format('F Y');
    $firstDow = (int) $calMonthDt->format('w');
    $daysInMonth = (int) $calMonthDt->modify('last day of this month')->format('j');
    $todayDate = date('Y-m-d');
    $todayDow = (int) date('w');
  ?>
  <section class="panel calendar-section" id="calendar-section">
    <div class="panel-heading calendar-nav">
      <a class="calendar-nav-btn secondary" href="?page=calendar&m=<?= e($prevMonth) ?>#calendar-section">&lsaquo; Prev</a>
      <h2><?= e($monthLabel) ?></h2>
      <a class="calendar-nav-btn secondary" href="?page=calendar&m=<?= e($nextMonth) ?>#calendar-section">Next &rsaquo;</a>
    </div>
    <div class="calendar-grid">
      <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $i => $dayName): ?>
        <div class="calendar-day calendar-day--header<?= $i === $todayDow ? ' calendar-day--header-today' : '' ?>"><strong><?= e($dayName) ?></strong></div>
      <?php endforeach; ?>
      <?php for ($i = 0; $i < $firstDow; $i++): ?>
        <div class="calendar-day calendar-day--empty"></div>
      <?php endfor; ?>
      <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
        <?php
          $dateStr = sprintf('%s-%02d', $monthParam, $day);
          $marker = $calendarMarkers[$dateStr] ?? ['taken' => 0, 'skipped' => 0, 'missed' => 0];
          $isFuture = $dateStr > $todayDate;
          $isToday = $dateStr === $todayDate;
          if ($isFuture) {
              $dayClass = 'calendar-day--future';
          } elseif ($marker['missed'] > 0) {
              $dayClass = 'calendar-day--missed';
          } elseif ($marker['skipped'] > 0 && $marker['taken'] === 0) {
              $dayClass = 'calendar-day--skipped';
          } elseif ($marker['taken'] > 0) {
              $dayClass = 'calendar-day--taken';
          } else {
              $dayClass = 'calendar-day--empty';
          }
        ?>
        <div class="calendar-day <?= e($dayClass) ?><?= $isToday ? ' calendar-day--today' : '' ?>">
          <strong><?= e((string) $day) ?></strong>
          <?php if (!$isFuture && ($marker['taken'] > 0 || $marker['skipped'] > 0 || $marker['missed'] > 0)): ?>
            <small>
              <?php if ($marker['taken'] > 0): ?><span class="marker-taken"><?= e((string) $marker['taken']) ?>T</span><?php endif; ?>
              <?php if ($marker['skipped'] > 0): ?><span class="marker-skipped"><?= e((string) $marker['skipped']) ?>S</span><?php endif; ?>
              <?php if ($marker['missed'] > 0): ?><span class="marker-missed"><?= e((string) $marker['missed']) ?>M</span><?php endif; ?>
            </small>
          <?php endif; ?>
        </div>
      <?php endfor; ?>
    </div>
    <div class="calendar-legend">
      <span class="legend-item"><span class="legend-dot legend-dot--taken"></span>Taken</span>
      <span class="legend-item"><span class="legend-dot legend-dot--skipped"></span>Skipped</span>
      <span class="legend-item"><span class="legend-dot legend-dot--missed"></span>Missed</span>
    </div>
  </section>
  <p class="disclaimer">RxTracker is a tracking aid only and does not provide medical advice or clinical decision support.</p>
</main>
</body>
</html>
<?php exit; ?>
<?php endif; ?>

  <?php if ($page === 'export'): ?>
  <?php
    $exportMonth = (string) ($_GET['m'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-\d{2}$/', $exportMonth)) {
        $exportMonth = date('Y-m');
    }
    $rawStart = (string) ($_GET['start_date'] ?? '');
    $rawEnd   = (string) ($_GET['end_date'] ?? '');
    $validStart = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawStart) ? $rawStart : null;
    $validEnd   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawEnd)   ? $rawEnd   : null;
    if ($validStart !== null && $validEnd !== null && $validStart <= $validEnd) {
        $filterStart   = $validStart;
        $filterEnd     = $validEnd;
        $isCustomRange = true;
        $exportMonth   = (new DateTimeImmutable($filterStart))->format('Y-m');
    } else {
        $monthDate     = DateTimeImmutable::createFromFormat('Y-m-d', $exportMonth . '-01');
        $filterStart   = $exportMonth . '-01';
        $filterEnd     = $monthDate ? $monthDate->format('Y-m-t') : $exportMonth . '-31';
        $isCustomRange = false;
    }
    $monthDateObj = DateTimeImmutable::createFromFormat('Y-m', $exportMonth);
    $prevMonth    = $monthDateObj ? $monthDateObj->modify('-1 month')->format('Y-m') : '';
    $nextMonth    = $monthDateObj ? $monthDateObj->modify('+1 month')->format('Y-m') : '';
    $monthLabel   = $monthDateObj ? $monthDateObj->format('F Y') : $exportMonth;
  ?>
  <section class="panel export-section">
    <div class="panel-heading">
      <h2>Medication List &mdash; <?= e(date('F j, Y')) ?></h2>
      <button type="button" class="no-print" onclick="window.print()">Print / Save as PDF</button>
    </div>
    <table class="export-table">
      <thead>
        <tr>
          <th>Medication</th>
          <th>Dose</th>
          <th>Schedule</th>
          <th>Instructions</th>
          <th>Pills left</th>
          <th>Refill at</th>
          <th>Est. days left</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($medications as $med): ?>
          <?php $exportDays = daysUntilRunout($med); ?>
          <tr>
            <td><?= e((string) $med['name']) ?></td>
            <td><?= e((string) $med['dose']) ?></td>
            <td>
              <?php if ((string) $med['schedule_mode'] === 'interval'): ?>
                Every <?= e((string) $med['interval_hours']) ?>h from <?= e(to12h((string) $med['first_dose_time'])) ?>
              <?php else: ?>
                <?= e(implode(', ', array_map(static fn(string $t): string => to12h($t), $med['times']))) ?>
              <?php endif; ?>
            </td>
            <td><?= e((string) ($med['instructions'] ?: '—')) ?></td>
            <td><?= e((string) $med['pill_count']) ?></td>
            <td><?= e((string) $med['low_supply_threshold']) ?></td>
            <td>
              <?php if ($exportDays !== null): ?>
                ~<?= e((string) $exportDays) ?> days
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($medications === []): ?>
          <tr><td colspan="7">No active medications.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </section>

  <?php $exportLogs = $repository->logsForDateRange($filterStart, $filterEnd); ?>
  <section class="panel export-section export-history-section" id="dose-history">
    <div class="panel-heading export-history-heading">
      <h2>Dose History</h2>
      <div class="export-month-nav no-print">
        <a class="calendar-nav-btn secondary" href="?page=export&m=<?= e($prevMonth) ?>#dose-history">&lsaquo;</a>
        <span class="export-month-label"><?= e($monthLabel) ?></span>
        <a class="calendar-nav-btn secondary" href="?page=export&m=<?= e($nextMonth) ?>#dose-history">&rsaquo;</a>
      </div>
    </div>
    <form method="get" action="index.php" class="export-date-filter no-print" data-history-filter>
      <input type="hidden" name="page" value="export">
      <label>From <input type="date" name="start_date" value="<?= e($filterStart) ?>"></label>
      <label>To <input type="date" name="end_date" value="<?= e($filterEnd) ?>"></label>
      <button type="submit" class="secondary">Filter</button>
      <?php if ($isCustomRange): ?>
        <a href="?page=export&m=<?= e($exportMonth) ?>#dose-history" class="secondary">Clear</a>
      <?php endif; ?>
    </form>
    <p class="export-date-range-label">
      <?php if ($isCustomRange): ?>
        <?= e((new DateTimeImmutable($filterStart))->format('M j, Y')) ?> &ndash; <?= e((new DateTimeImmutable($filterEnd))->format('M j, Y')) ?>
      <?php else: ?>
        <?= e($monthLabel) ?>
      <?php endif; ?>
      &mdash; <?= count($exportLogs) ?> record<?= count($exportLogs) !== 1 ? 's' : '' ?>
    </p>
    <?php if ($exportLogs !== []): ?>
    <table class="export-table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Medication</th>
          <th>Time</th>
          <th>Status</th>
          <th>Pain Level</th>
          <th>Notes</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($exportLogs as $log): ?>
          <tr>
            <td><?= e((string) $log['scheduled_for_date']) ?></td>
            <td><?= e((string) $log['name']) ?></td>
            <td><?= e(to12h((string) $log['scheduled_time'])) ?></td>
            <td><?= e(ucfirst((string) $log['status'])) ?></td>
            <td><?= (isset($log['pain_level']) && $log['pain_level'] !== null) ? e((string) $log['pain_level']) . '/10' : '&mdash;' ?></td>
            <td><?= e((string) ($log['note'] ?: '—')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php else: ?>
    <p class="empty-state-text">No dose records for this period.</p>
    <?php endif; ?>
  </section>
  <p class="disclaimer no-print">RxTracker is a tracking aid only and does not provide medical advice or clinical decision support.</p>
</main>
</body>
</html>
<?php exit; ?>
<?php endif; ?>

  <?php if ($lowSupplyMeds !== []): ?>
  <div class="warning-banner" role="alert">
    <?php foreach ($lowSupplyMeds as $lowMed): ?>
      <p><strong><?= e((string) $lowMed['name']) ?></strong> &mdash; only <?= e((string) $lowMed['pill_count']) ?> pill<?= (int) $lowMed['pill_count'] === 1 ? '' : 's' ?> left (refill alert at &le;<?= e((string) $lowMed['low_supply_threshold']) ?>)</p>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="in-app-alert" data-in-app-alert hidden></div>

  <div id="pwa-install-banner" class="pwa-install-banner" hidden>
    <span class="pwa-install-text">Add RxTracker to your home screen for the best experience</span>
    <div class="pwa-install-actions">
      <button type="button" id="pwa-install-btn">Install</button>
      <button type="button" class="secondary icon-button" id="pwa-install-dismiss" aria-label="Dismiss install prompt">&#10005;</button>
    </div>
  </div>

  <?php if (!in_array($page, ['medications', 'settings', 'calendar', 'export'], true)): ?>
  <section class="dashboard-grid" aria-label="Medication dashboard">
    <article class="panel">
      <div class="panel-heading"><h2>Today schedule <span class="panel-heading-date"><?= date('D, M j') ?></span></h2></div>
      <div class="schedule-list">
        <?php foreach ($todaySchedule as $dose): ?>
          <div class="schedule-row">
            <div>
              <strong><?= e((string) $dose['name']) ?></strong>
              <p><?= e(to12h((string) $dose['reminder_time'])) ?> <?= $dose['as_needed'] ? '(PRN)' : '' ?></p>
              <?php if ($dose['group_name'] !== null): ?>
                <span class="group-badge"><?= e((string) $dose['group_name']) ?></span>
              <?php endif; ?>
              <?php if ((string) ($dose['status'] ?? '') === 'taken'): ?>
                <span class="done-pill">Taken</span>
              <?php elseif ((string) ($dose['status'] ?? '') === 'skipped'): ?>
                <span class="warn-pill">Skipped</span>
              <?php endif; ?>
            </div>
            <div class="row-actions">
              <?php $isCompleted = in_array((string) ($dose['status'] ?? ''), ['taken', 'skipped'], true); ?>
              <?php if (is_string($dose['postponed_until'] ?? null) && (string) $dose['postponed_until'] !== ''): ?>
                <span class="done-pill">Snoozed until <?= e(to12h((new DateTimeImmutable((string) $dose['postponed_until']))->format('H:i'))) ?></span>
              <?php endif; ?>
              <div class="schedule-actions-buttons">
                <form method="post" action="index.php"><?= csrf_field() ?><input type="hidden" name="action" value="mark_dose"><input type="hidden" name="medication_id" value="<?= e((string) $dose['medication_id']) ?>"><input type="hidden" name="scheduled_date" value="<?= e($today) ?>"><input type="hidden" name="scheduled_time" value="<?= e((string) $dose['reminder_time']) ?>:00"><input type="hidden" name="status" value="taken"><button type="submit" data-take-dose data-medication-id="<?= e((string) $dose['medication_id']) ?>" data-scheduled-date="<?= e($today) ?>" data-scheduled-time="<?= e((string) $dose['reminder_time']) ?>:00" data-track-dose-feedback="<?= $dose['track_dose_feedback'] ? '1' : '0' ?>"<?= $isCompleted ? ' disabled' : '' ?>>Take</button></form>
                <form method="post" action="index.php" data-confirm="Confirm skipped dose?"><?= csrf_field() ?><input type="hidden" name="action" value="mark_dose"><input type="hidden" name="medication_id" value="<?= e((string) $dose['medication_id']) ?>"><input type="hidden" name="scheduled_date" value="<?= e($today) ?>"><input type="hidden" name="scheduled_time" value="<?= e((string) $dose['reminder_time']) ?>:00"><input type="hidden" name="status" value="skipped"><input type="hidden" name="note" value="Skipped dose"><button type="submit" class="secondary"<?= $isCompleted ? ' disabled' : '' ?>>Skipped</button></form>
                <?php if (!$isCompleted): ?>
                  <button
                    type="button"
                    class="secondary"
                    data-open-postpone-modal
                    data-medication-id="<?= e((string) $dose['medication_id']) ?>"
                    data-scheduled-date="<?= e($today) ?>"
                    data-scheduled-time="<?= e((string) $dose['reminder_time']) ?>:00"
                    <?= (is_string($dose['postponed_until'] ?? null) && (string) $dose['postponed_until'] !== '') ? ' disabled' : '' ?>
                  >Snooze</button>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </article>
  </section>

  <section class="panel history-panel" data-history-panel>
    <div class="panel-heading"><h2>Recent history</h2></div>
    <ol class="history-list" data-history-list>
      <?php
        $yesterday = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
      ?>
      <?php foreach ($recentLogs as $log): ?>
        <?php
          $logDate = (string) $log['scheduled_for_date'];
          if ($logDate === $today) {
              $dateLabel = 'Today';
          } elseif ($logDate === $yesterday) {
              $dateLabel = 'Yesterday';
          } else {
              $dateLabel = (new DateTimeImmutable($logDate))->format('M j');
          }
        ?>
        <li>
          <span><span class="history-date"><?= e($dateLabel) ?></span><span class="history-time"><?= e(to12h((string) $log['scheduled_time'])) ?></span></span>
          <div>
            <strong><?= e((string) $log['name']) ?></strong>
            <p>
              <?php if ((string) $log['status'] === 'taken' && isLate($log, $graceMinutes)): ?>
                <span class="warn-pill">Taken (late)</span>
              <?php elseif ((string) $log['status'] === 'taken'): ?>
                <span class="done-pill">Taken</span>
              <?php elseif ((string) $log['status'] === 'skipped'): ?>
                <span class="warn-pill">Skipped</span>
              <?php elseif ((string) $log['status'] === 'missed'): ?>
                <span class="alert-pill">Missed</span>
              <?php else: ?>
                <?= e((string) $log['status']) ?>
              <?php endif; ?>
              <?php if (isset($log['pain_level']) && $log['pain_level'] !== null): ?>
                <?php $pl = (int) $log['pain_level']; $painMod = $pl <= 3 ? 'low' : ($pl <= 6 ? 'mid' : ($pl <= 8 ? 'high' : 'severe')); ?>
                <span class="history-pain-label">Pain Score</span> <span class="history-pain-badge history-pain-badge--<?= $painMod ?>"><?= e((string) $log['pain_level']) ?>/10</span>
              <?php endif; ?>
            </p>
            <?php if ((string) $log['note'] !== '' && (string) $log['note'] !== 'Skipped dose' && (string) $log['note'] !== 'Logged now'): ?>
              <small class="history-note"><span class="history-note-label">Comments:</span> <?= e((string) $log['note']) ?></small>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ol>
    <?php if (count($recentLogs) > 4): ?><button type="button" class="history-view-more" data-history-toggle>View more</button><?php endif; ?>
  </section>
  <?php endif; ?>
  <p class="disclaimer">RxTracker is a tracking aid only and does not provide medical advice or clinical decision support.</p>
</main>

<div class="modal-overlay" data-postpone-modal>
  <div class="modal-dialog postpone-dialog" role="dialog" aria-modal="true" aria-labelledby="postpone-modal-title">
    <div class="modal-header">
      <h2 id="postpone-modal-title">Snooze reminder</h2>
      <button type="button" class="icon-button" data-close-postpone-modal aria-label="Close postpone modal">&#10005;</button>
    </div>
    <div class="modal-scroll">
    <form method="post" action="index.php" class="stacked-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="postpone_dose">
      <input type="hidden" name="medication_id" data-postpone-medication-id>
      <input type="hidden" name="scheduled_date" data-postpone-scheduled-date>
      <input type="hidden" name="scheduled_time" data-postpone-scheduled-time>
      <label>Snooze for
        <select name="postpone_minutes" required>
          <option value="5">5 minutes</option>
          <option value="10">10 minutes</option>
          <option value="15">15 minutes</option>
          <option value="30">30 minutes</option>
        </select>
      </label>
      <button type="submit">Snooze</button>
    </form>
    </div>
  </div>
</div>

<div class="modal-overlay" data-refill-modal>
  <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="refill-modal-title">
    <div class="modal-header">
      <div>
        <h2 id="refill-modal-title">Log Refill</h2>
        <p class="refill-modal-subtitle" data-refill-med-name></p>
      </div>
      <button type="button" class="icon-button" data-close-refill-modal aria-label="Close refill modal">&#10005;</button>
    </div>
    <div class="modal-scroll">
    <form class="stacked-form" data-refill-form>
      <input type="hidden" name="medication_id" data-refill-medication-id value="">
      <label>Refill date
        <input type="date" name="refill_date" data-refill-date required>
      </label>
      <label>Amount (pills)
        <input type="number" min="1" name="amount" required placeholder="e.g. 30">
      </label>
      <label>Note <span class="field-optional">(optional)</span>
        <input name="note" placeholder="e.g. 30-day supply" maxlength="255">
      </label>
      <div class="refill-form-actions">
        <button type="submit">Log refill</button>
        <button type="button" class="secondary" data-close-refill-modal>Cancel</button>
      </div>
    </form>
    </div>
  </div>
</div>

<div class="modal-overlay" data-refill-history-modal>
  <div class="modal-dialog refill-history-dialog" role="dialog" aria-modal="true" aria-labelledby="refill-history-title">
    <div class="modal-header">
      <div>
        <h2 id="refill-history-title">Refill History</h2>
        <p class="refill-modal-subtitle" data-refill-history-med-name></p>
      </div>
      <button type="button" class="icon-button" data-close-refill-history aria-label="Close refill history">&#10005;</button>
    </div>
    <div class="modal-scroll">
      <div class="refill-history-body" data-refill-history-body>
        <p class="pain-graph-loading">Loading&hellip;</p>
      </div>
    </div>
  </div>
</div>

<div class="alarm-overlay" data-alarm-overlay aria-modal="true" role="alertdialog" aria-labelledby="alarm-title">
  <div class="alarm-dialog">
    <div class="alarm-pulse-ring"></div>
    <p class="alarm-eyebrow" data-alarm-eyebrow>Dose Due Now</p>

    <!-- Single medication mode -->
    <div data-alarm-single-mode>
      <h2 id="alarm-title" class="alarm-med-name" data-alarm-med-name></h2>
      <p class="alarm-med-dose" data-alarm-med-dose></p>
    </div>

    <!-- Group mode -->
    <div data-alarm-group-mode hidden>
      <h2 id="alarm-title-group" class="alarm-med-name" data-alarm-group-name></h2>
      <ul class="alarm-group-list" data-alarm-group-list></ul>
    </div>

    <div class="alarm-actions">
      <button type="button" class="alarm-take-btn" data-alarm-take>Take Now</button>
      <button type="button" class="secondary alarm-skip-btn" data-alarm-skip>Skip</button>
      <div class="alarm-snooze-row">
        <select data-alarm-snooze-minutes class="alarm-snooze-select">
          <option value="5"<?= $snoozeMinutes === 5 ? ' selected' : '' ?>>5 min</option>
          <option value="10"<?= $snoozeMinutes === 10 ? ' selected' : '' ?>>10 min</option>
          <option value="15"<?= $snoozeMinutes === 15 ? ' selected' : '' ?>>15 min</option>
          <option value="30"<?= $snoozeMinutes === 30 ? ' selected' : '' ?>>30 min</option>
        </select>
        <button type="button" class="secondary" data-alarm-snooze>Snooze</button>
      </div>
    </div>
  </div>
</div>
</body>
</html>
