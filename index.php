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

            if ($name === '' || $dose === '') {
                throw new RuntimeException('Medication name and dose are required.');
            }

            if ($scheduleMode === 'interval') {
                $doseTimes = [];
            }

            if ($action === 'add_medication') {
                $repository->createMedication($name, $dose, $instructions, $scheduleMode, $doseTimes, $intervalHours, $firstDoseTime, $asNeeded, $pillCount, $lowSupplyThreshold);
            } else {
                $repository->updateMedication($id, $name, $dose, $instructions, $scheduleMode, $doseTimes, $intervalHours, $firstDoseTime, $asNeeded, $pillCount, $lowSupplyThreshold);
            }

            redirect_home();
        }

        if ($action === 'mark_dose') {
            $repository->recordDoseStatus((int) post_string('medication_id'), post_string('scheduled_date'), post_string('scheduled_time'), post_string('status'), post_string('note'));
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
            $repository->deactivateMedication((int) post_string('medication_id'));
            redirect_home();
        }

        if ($action === 'activate_medication') {
            $repository->activateMedication((int) post_string('medication_id'));
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
            header('Location: index.php?notice=Dose snoozed');
            exit;
        }

        if ($action === 'save_settings') {
            $graceMinutes = (int) post_string('missed_grace_minutes');
            $repository->setMissedGraceMinutes($graceMinutes);
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

        if ($action === 'remove_push_subscription') {
            header('Content-Type: application/json; charset=utf-8');
            $endpoint = trim((string) ($_POST['endpoint'] ?? ''));
            $repository->removePushSubscriptionByEndpoint($endpoint);
            echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
            exit;
        }
    } catch (Throwable $exception) {
        $isPushAction = in_array(post_string('action'), ['save_push_subscription', 'remove_push_subscription'], true);
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
$repository->finalizeMissedDoses(new DateTimeImmutable('now'), $graceMinutes);
$notice = trim((string) ($_GET['notice'] ?? '')) ?: null;

$medications = $repository->activeMedications();
$inactiveMedications = $repository->inactiveMedications();
$medicationPlanCount = count($medications);
$inactiveMedicationCount = count($inactiveMedications);
$todaySchedule = $repository->todaySchedule($today);
$recentLogs = $repository->recentLogs($today, 50);
$missedCount = $repository->missedDoseCount($today, $currentTime);

$requiredRows = array_filter($todaySchedule, static fn(array $row): bool => !$row['as_needed']);
$takenRows = array_filter($requiredRows, static fn(array $row): bool => (string) ($row['status'] ?? '') === 'taken');
$adherence = count($requiredRows) > 0 ? (int) round((count($takenRows) / count($requiredRows)) * 100) : 0;
$nextDose = null;
foreach ($todaySchedule as $row) {
    if (!in_array((string) ($row['status'] ?? ''), ['taken', 'skipped'], true)) {
        $nextDose = $row;
        break;
    }
}
$nextDoseWindow = [];
if ($nextDose !== null) {
    $startMinutes = timeToMinutes((string) $nextDose['reminder_time']);
    $endMinutes = $startMinutes + (4 * 60);
    foreach ($todaySchedule as $row) {
        if (in_array((string) ($row['status'] ?? ''), ['taken', 'skipped'], true)) {
            continue;
        }
        $rowMinutes = timeToMinutes((string) $row['reminder_time']);
        if ($rowMinutes >= $startMinutes && $rowMinutes <= $endMinutes) {
            $nextDoseWindow[] = $row;
        }
    }
}

$editId = (int) ($_GET['edit'] ?? 0);
$editing = $editId > 0 ? $repository->findMedication($editId) : null;

$lowSupplyMeds = array_values(array_filter($medications, static fn(array $m): bool =>
    (int) ($m['low_supply_threshold'] ?? 0) > 0 &&
    (int) ($m['pill_count'] ?? 0) <= (int) ($m['low_supply_threshold'] ?? 0)
));

$onTimeCount = 0;
$lateCount = 0;
foreach ($recentLogs as $log) {
    if ((string) $log['status'] === 'taken') {
        if (isLate($log, $graceMinutes)) {
            $lateCount++;
        } else {
            $onTimeCount++;
        }
    }
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#1269ff">
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
      <a href="index.php"<?= !in_array($page, ['settings', 'calendar', 'export'], true) ? ' class="is-active"' : '' ?>>Dashboard</a>
      <a href="index.php?page=calendar"<?= $page === 'calendar' ? ' class="is-active"' : '' ?>>Calendar</a>
      <a href="index.php?page=export"<?= $page === 'export' ? ' class="is-active"' : '' ?>>Export</a>
      <a href="index.php?page=settings"<?= $page === 'settings' ? ' class="is-active"' : '' ?>>Settings</a>
    </div>
    <button class="nav-hamburger" aria-label="Menu" aria-expanded="false" data-nav-toggle>&#9776;</button>
  </nav>

  <section class="hero">
    <div class="hero-card hero-med-card">
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
    <div class="hero-card" aria-label="Today's adherence summary">
      <span class="stat-label">Today's adherence</span>
      <strong><?= e((string) $adherence) ?>%</strong>
      <span>Required doses taken: <?= e((string) count($takenRows)) ?> of <?= e((string) count($requiredRows)) ?></span>
      <?php if ($onTimeCount + $lateCount > 0): ?>
        <span>On time: <?= e((string) $onTimeCount) ?> &middot; Late: <?= e((string) $lateCount) ?></span>
      <?php endif; ?>
      <span>Missed required doses today: <?= e((string) $missedCount) ?></span>
    </div>
  </section>

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
          <button type="button" data-open-medication-modal>Add medication</button>
          <button type="button" class="secondary med-plan-close-btn" data-close-med-plan-modal aria-label="Close">&times;</button>
        </div>
      </div>
      <div class="medication-plan-tabs" role="tablist" aria-label="Medication status lists">
          <button
            type="button"
            class="secondary plan-tab is-active"
            data-plan-tab="active"
            role="tab"
            aria-selected="true"
            aria-controls="active-medications-panel"
            id="active-medications-tab"
          >Active (<?= e((string) $medicationPlanCount) ?>)</button>
          <button
            type="button"
            class="secondary plan-tab"
            data-plan-tab="inactive"
            role="tab"
            aria-selected="false"
            aria-controls="inactive-medications-panel"
            id="inactive-medications-tab"
          >Inactive (<?= e((string) $inactiveMedicationCount) ?>)</button>
        </div>

        <div class="plan-tab-panel" id="active-medications-panel" role="tabpanel" aria-labelledby="active-medications-tab">
          <div class="medication-list">
            <?php if ($medicationPlanCount === 0): ?>
              <div class="empty-state"><p>No active medications yet.</p></div>
            <?php endif; ?>
            <?php foreach ($medications as $medication): ?>
              <?php $daysLeft = daysUntilRunout($medication); ?>
              <div class="medication-row medication-row-plan">
                <div class="medication-content">
                  <strong><?= e((string) $medication['name']) ?></strong>
                  <p><?= e((string) $medication['dose']) ?></p>
                  <p>
                    <?php if ((string) $medication['schedule_mode'] === 'interval'): ?>
                      Every <?= e((string) $medication['interval_hours']) ?> hours from <?= e(to12h((string) $medication['first_dose_time'])) ?>
                    <?php else: ?>
                      <?= e(implode(', ', array_map(static fn(string $time): string => to12h($time), $medication['times']))) ?>
                    <?php endif; ?>
                    <?= ((int) $medication['as_needed'] === 1) ? '(As needed)' : '' ?>
                  </p>
                  <p class="pill-meta">Pills: <?= e((string) $medication['starting_pill_count']) ?> / <?= e((string) $medication['pill_count']) ?> | Refill alert at <?= e((string) $medication['low_supply_threshold']) ?> pills</p>
                  <?php if ($daysLeft !== null): ?>
                    <p class="pill-meta<?= $daysLeft <= 7 ? ' refill-soon' : '' ?>">~<?= e((string) $daysLeft) ?> days left &middot; runs out ~<?= e((new DateTime())->modify('+' . $daysLeft . ' days')->format('M j')) ?></p>
                  <?php endif; ?>
                </div>
                <div class="row-actions medication-actions-top">
                  <a class="secondary modal-edit-link" href="index.php?edit=<?= e((string) $medication['id']) ?>">Edit</a>
                </div>
                <div class="row-actions medication-actions-bottom">
                  <form method="post" action="index.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="log_dose_now">
                    <input type="hidden" name="medication_id" value="<?= e((string) $medication['id']) ?>">
                    <input type="hidden" name="note" value="Logged now">
                    <button type="submit" class="secondary">Log dose now</button>
                  </form>
                  <form method="post" action="index.php" data-confirm="Move this medication to inactive?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="deactivate_medication">
                    <input type="hidden" name="medication_id" value="<?= e((string) $medication['id']) ?>">
                    <button type="submit" class="secondary">Deactivate</button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="plan-tab-panel" id="inactive-medications-panel" role="tabpanel" aria-labelledby="inactive-medications-tab" hidden>
          <div class="inactive-list">
            <?php if ($inactiveMedications === []): ?>
              <div class="empty-state"><p>No inactive medications.</p></div>
            <?php endif; ?>
            <?php foreach ($inactiveMedications as $medication): ?>
              <div class="medication-row">
                <div>
                  <strong><?= e((string) $medication['name']) ?></strong>
                  <p><?= e((string) $medication['dose']) ?></p>
                </div>
                <div class="row-actions">
                  <form method="post" action="index.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="activate_medication">
                    <input type="hidden" name="medication_id" value="<?= e((string) $medication['id']) ?>">
                    <button type="submit">Activate</button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
    </div>
  </div>

  <?php if ($page === 'settings'): ?>
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
        <button type="submit">Save settings</button>
      </form>
      <hr>
      <div class="row-actions">
        <label class="toggle-control" for="reminders-toggle">
          <input type="checkbox" id="reminders-toggle" data-enable-reminders>
          <span class="toggle-slider" aria-hidden="true"></span>
          <span class="toggle-label">Background reminders</span>
        </label>
        <span class="muted" data-reminder-status>Background push reminders are currently disabled on this device.</span>
      </div>
      <div class="in-app-alert" data-in-app-alert hidden></div>
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
  <section class="panel calendar-section">
    <div class="panel-heading calendar-nav">
      <a class="calendar-nav-btn secondary" href="?page=calendar&m=<?= e($prevMonth) ?>">&lsaquo; Prev</a>
      <h2><?= e($monthLabel) ?></h2>
      <a class="calendar-nav-btn secondary" href="?page=calendar&m=<?= e($nextMonth) ?>">Next &rsaquo;</a>
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

  <section class="dashboard-grid" aria-label="Medication dashboard">
    <article class="panel next-dose">
      <div class="panel-heading"><h2>Next dose</h2></div>
      <?php if ($nextDose !== null): ?>
        <div class="next-dose-list">
          <?php foreach ($nextDoseWindow as $index => $doseItem): ?>
            <div class="next-dose-card<?= $index > 0 ? ' next-dose-card-subtle' : '' ?>">
              <span><?= e(to12h((string) $doseItem['reminder_time'])) ?></span>
              <?php if ($index === 0): ?>
                <h3><?= e((string) $doseItem['name']) ?></h3>
              <?php else: ?>
                <h4><?= e((string) $doseItem['name']) ?></h4>
              <?php endif; ?>
              <p><?= e((string) $doseItem['dose']) ?> <?= $doseItem['as_needed'] ? '(PRN)' : '' ?></p>
              <small><?= e((string) ($doseItem['instructions'] ?: 'No special instructions')) ?></small>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="empty-state"><p>All scheduled doses are complete.</p></div>
      <?php endif; ?>
    </article>

    <article class="panel">
      <div class="panel-heading"><h2>Today schedule</h2></div>
      <div class="schedule-list">
        <?php foreach ($todaySchedule as $dose): ?>
          <div class="schedule-row">
            <div>
              <strong><?= e((string) $dose['name']) ?></strong>
              <p><?= e(to12h((string) $dose['reminder_time'])) ?> <?= $dose['as_needed'] ? '(PRN)' : '' ?></p>
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
                <form method="post" action="index.php"><?= csrf_field() ?><input type="hidden" name="action" value="mark_dose"><input type="hidden" name="medication_id" value="<?= e((string) $dose['medication_id']) ?>"><input type="hidden" name="scheduled_date" value="<?= e($today) ?>"><input type="hidden" name="scheduled_time" value="<?= e((string) $dose['reminder_time']) ?>:00"><input type="hidden" name="status" value="taken"><button type="submit"<?= $isCompleted ? ' disabled' : '' ?>>Take</button></form>
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
      <?php foreach ($recentLogs as $log): ?>
        <li>
          <span><?= e(to12h((string) $log['scheduled_time'])) ?></span>
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
            </p>
          </div>
        </li>
      <?php endforeach; ?>
    </ol>
    <?php if (count($recentLogs) > 4): ?><button type="button" class="history-view-more" data-history-toggle>View more</button><?php endif; ?>
  </section>
  <p class="disclaimer">RxTracker is a tracking aid only and does not provide medical advice or clinical decision support.</p>
</main>

<div class="modal-overlay<?= $editing ? ' is-open' : '' ?>" data-medication-modal>
  <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="medication-modal-title">
    <div class="modal-header">
      <h2 id="medication-modal-title"><?= $editing ? 'Edit medication' : 'Add medication' ?></h2>
      <button type="button" class="icon-button" data-close-medication-modal aria-label="Close modal">X</button>
    </div>
    <form class="medication-form" method="post" action="index.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= $editing ? 'update_medication' : 'add_medication' ?>">
      <input type="hidden" name="medication_id" value="<?= e((string) ($editing['id'] ?? 0)) ?>">

      <label>Name<input name="name" required value="<?= e((string) ($editing['name'] ?? '')) ?>"></label>
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
      <label>Pill count<input type="number" min="0" name="pill_count" value="<?= e((string) ($editing['pill_count'] ?? 0)) ?>"></label>
      <label>Low supply threshold (pills)
        <input type="number" min="0" name="low_supply_threshold" value="<?= e((string) ($editing['low_supply_threshold'] ?? 5)) ?>">
      </label>
      <label>Instructions<input name="instructions" value="<?= e((string) ($editing['instructions'] ?? '')) ?>"></label>
      <button type="submit"><?= $editing ? 'Save changes' : 'Add medication' ?></button>
    </form>
  </div>
</div>

<div class="modal-overlay" data-postpone-modal>
  <div class="modal-dialog postpone-dialog" role="dialog" aria-modal="true" aria-labelledby="postpone-modal-title">
    <div class="modal-header">
      <h2 id="postpone-modal-title">Snooze reminder</h2>
      <button type="button" class="icon-button" data-close-postpone-modal aria-label="Close postpone modal">X</button>
    </div>
    <form method="post" action="index.php" class="stacked-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="postpone_dose">
      <input type="hidden" name="medication_id" data-postpone-medication-id>
      <input type="hidden" name="scheduled_date" data-postpone-scheduled-date>
      <input type="hidden" name="scheduled_time" data-postpone-scheduled-time>
      <label>Snooze for
        <select name="postpone_minutes" required>
          <option value="5">5 minutes</option>
          <option value="15">15 minutes</option>
          <option value="30">30 minutes</option>
        </select>
      </label>
      <button type="submit">Snooze</button>
    </form>
  </div>
</div>

<div class="alarm-overlay" data-alarm-overlay aria-modal="true" role="alertdialog" aria-labelledby="alarm-title">
  <div class="alarm-dialog">
    <div class="alarm-pulse-ring"></div>
    <p class="alarm-eyebrow">Dose Due Now</p>
    <h2 id="alarm-title" class="alarm-med-name" data-alarm-med-name></h2>
    <p class="alarm-med-dose" data-alarm-med-dose></p>
    <div class="alarm-actions">
      <button type="button" class="alarm-take-btn" data-alarm-take>Take Now</button>
      <button type="button" class="secondary alarm-skip-btn" data-alarm-skip>Skip Dose</button>
      <div class="alarm-snooze-row">
        <select data-alarm-snooze-minutes class="alarm-snooze-select">
          <option value="5">5 min</option>
          <option value="15">15 min</option>
          <option value="30">30 min</option>
        </select>
        <button type="button" class="secondary" data-alarm-snooze>Snooze</button>
      </div>
    </div>
  </div>
</div>
</body>
</html>
