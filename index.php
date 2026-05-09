<?php

declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/MedicationRepository.php';

function parseTimeValue(string $raw, string $format): string
{
    $value = trim($raw);

    if ($format === '24h') {
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) {
            throw new RuntimeException('Time must be HH:MM in 24-hour format.');
        }

        return $value . ':00';
    }

    if (!preg_match('/^(0?[1-9]|1[0-2]):([0-5]\d)\s*([AaPp][Mm])$/', $value, $matches)) {
        throw new RuntimeException('Time must be h:mm AM/PM in 12-hour format.');
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

function parseDoseTimes(string $raw, string $format): array
{
    $segments = preg_split('/\s*,\s*/', trim($raw)) ?: [];
    $times = [];
    foreach ($segments as $segment) {
        if ($segment === '') {
            continue;
        }
        $times[] = parseTimeValue($segment, $format);
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

function displayTimeByFormat(string $time, string $format): string
{
    return $format === '12h' ? to12h($time) : substr($time, 0, 5);
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
    $repository->finalizeMissedDoses($now, $graceMinutes);
    echo json_encode([
        'ok' => true,
        'grace_minutes' => $graceMinutes,
        'items' => $repository->dueReminderItems($now),
    ], JSON_THROW_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            $timeFormat = post_string('time_format') === '12h' ? '12h' : '24h';
            $doseTimes = parseDoseTimes(post_string('dose_times'), $timeFormat);
            $intervalHoursRaw = post_string('interval_hours');
            $intervalHours = $intervalHoursRaw === '' ? null : max(1, (int) $intervalHoursRaw);
            $firstDoseRaw = post_string('first_dose_time');
            $firstDoseTime = $firstDoseRaw === '' ? null : parseTimeValue($firstDoseRaw, $timeFormat);
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
                $repository->createMedication($name, $dose, $instructions, $scheduleMode, $timeFormat, $doseTimes, $intervalHours, $firstDoseTime, $asNeeded, $pillCount, $lowSupplyThreshold);
            } else {
                $repository->updateMedication($id, $name, $dose, $instructions, $scheduleMode, $timeFormat, $doseTimes, $intervalHours, $firstDoseTime, $asNeeded, $pillCount, $lowSupplyThreshold);
            }

            redirect_home();
        }

        if ($action === 'mark_dose') {
            $repository->recordDoseStatus((int) post_string('medication_id'), post_string('scheduled_date'), post_string('scheduled_time'), post_string('status'), post_string('note'));
            redirect_home();
        }

        if ($action === 'log_dose_now') {
            $medicationId = (int) post_string('medication_id');
            if ($medicationId <= 0) {
                throw new RuntimeException('Choose a medication first.');
            }
            $repository->logDoseNow($medicationId, post_string('note'));
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
            header('Location: index.php?notice=Dose postponed');
            exit;
        }

        if ($action === 'save_settings') {
            $graceMinutes = (int) post_string('missed_grace_minutes');
            $repository->setMissedGraceMinutes($graceMinutes);
            header('Location: index.php?page=settings&notice=Settings saved');
            exit;
        }
    } catch (Throwable $exception) {
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
$recentLogs = $repository->recentLogs();
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

$editId = (int) ($_GET['edit'] ?? 0);
$editing = $editId > 0 ? $repository->findMedication($editId) : null;

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>RxTracker</title>
  <link rel="stylesheet" href="assets/css/styles.css">
  <script src="assets/js/app.js" defer></script>
</head>
<body>
<main class="app-shell">
  <nav class="top-nav">
    <a href="index.php"<?= $page !== 'settings' ? ' class="is-active"' : '' ?>>Dashboard</a>
    <a href="index.php?page=settings"<?= $page === 'settings' ? ' class="is-active"' : '' ?>>Settings</a>
  </nav>

  <section class="hero">
    <div>
      <p class="eyebrow">Medication tracking and reminders</p>
      <h1>RxTracker keeps today's doses clear.</h1>
      <p class="hero-copy">Track your medication plan, log doses, and keep reminders aligned to real intake times.</p>
    </div>
    <div class="hero-card" aria-label="Today's adherence summary">
      <span class="stat-label">Today's adherence</span>
      <strong><?= e((string) $adherence) ?>%</strong>
      <span>Required doses taken: <?= e((string) count($takenRows)) ?> of <?= e((string) count($requiredRows)) ?></span>
      <span>Missed required doses today: <?= e((string) $missedCount) ?></span>
    </div>
  </section>

  <?php if ($notice !== null): ?><div class="notice"><?= e($notice) ?></div><?php endif; ?>
  <?php if ($error !== null): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>

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
    </section>
    <p class="disclaimer">RxTracker is a tracking aid only and does not provide medical advice or clinical decision support.</p>
  </main>
  </body>
  </html>
  <?php
  exit;
  ?>
  <?php endif; ?>

  <section class="panel reminder-panel">
    <div class="panel-heading"><h2>Reminders</h2></div>
    <div class="row-actions">
      <button type="button" class="secondary" data-enable-reminders>Enable reminders</button>
      <span class="muted">Grace period: <?= e((string) $graceMinutes) ?> minutes</span>
    </div>
    <div class="in-app-alert" data-in-app-alert hidden></div>
  </section>

  <section class="dashboard-grid" aria-label="Medication dashboard">
    <article class="panel medication-list-panel is-collapsed" data-medication-plan>
      <div class="panel-heading medication-plan-heading">
        <div class="medication-plan-title-wrap">
          <h2>Medication plan</h2>
          <span class="count-badge"><?= e((string) $medicationPlanCount) ?></span>
        </div>
        <div class="medication-plan-actions">
          <button type="button" data-open-medication-modal>Add medication</button>
          <button
            type="button"
            class="secondary collapse-toggle"
            data-medication-plan-toggle
            aria-expanded="false"
            aria-controls="medication-plan-body"
          >Expand</button>
        </div>
      </div>
      <div class="medication-list-wrap" id="medication-plan-body" hidden>
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
              <div class="medication-row">
                <div>
                  <strong><?= e((string) $medication['name']) ?></strong>
                  <p><?= e((string) $medication['dose']) ?></p>
                  <p>
                    <?php if ((string) $medication['schedule_mode'] === 'interval'): ?>
                      Every <?= e((string) $medication['interval_hours']) ?> hours from <?= e(displayTimeByFormat((string) $medication['first_dose_time'], (string) ($medication['time_format'] ?? '24h'))) ?>
                    <?php else: ?>
                      <?= e(implode(', ', array_map(static fn(string $time): string => displayTimeByFormat($time, (string) ($medication['time_format'] ?? '24h')), $medication['times']))) ?>
                    <?php endif; ?>
                    <?= ((int) $medication['as_needed'] === 1) ? '(As needed)' : '' ?>
                  </p>
                  <p class="pill-meta">Pills: <?= e((string) $medication['starting_pill_count']) ?> / <?= e((string) $medication['pill_count']) ?> | Refill alert at <?= e((string) $medication['low_supply_threshold']) ?> pills</p>
                </div>
                <div class="row-actions">
                  <a class="secondary modal-edit-link" href="index.php?edit=<?= e((string) $medication['id']) ?>">Edit</a>
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
    </article>

    <article class="panel next-dose">
      <div class="panel-heading"><h2>Next dose</h2></div>
      <?php if ($nextDose !== null): ?>
        <div class="next-dose-card">
          <span><?= e(to12h((string) $nextDose['reminder_time'])) ?></span>
          <h3><?= e((string) $nextDose['name']) ?></h3>
          <p><?= e((string) $nextDose['dose']) ?> <?= $nextDose['as_needed'] ? '(PRN)' : '' ?></p>
          <small><?= e((string) ($nextDose['instructions'] ?: 'No special instructions')) ?></small>
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
                <span class="done-pill">Postponed until <?= e(to12h((new DateTimeImmutable((string) $dose['postponed_until']))->format('H:i'))) ?></span>
              <?php endif; ?>
              <form method="post" action="index.php"><?= csrf_field() ?><input type="hidden" name="action" value="mark_dose"><input type="hidden" name="medication_id" value="<?= e((string) $dose['medication_id']) ?>"><input type="hidden" name="scheduled_date" value="<?= e($today) ?>"><input type="hidden" name="scheduled_time" value="<?= e((string) $dose['reminder_time']) ?>:00"><input type="hidden" name="status" value="taken"><button type="submit"<?= $isCompleted ? ' disabled' : '' ?>>Taken</button></form>
              <form method="post" action="index.php" data-confirm="Confirm skipped dose?"><?= csrf_field() ?><input type="hidden" name="action" value="mark_dose"><input type="hidden" name="medication_id" value="<?= e((string) $dose['medication_id']) ?>"><input type="hidden" name="scheduled_date" value="<?= e($today) ?>"><input type="hidden" name="scheduled_time" value="<?= e((string) $dose['reminder_time']) ?>:00"><input type="hidden" name="status" value="skipped"><input type="hidden" name="note" value="Skipped dose"><button type="submit" class="secondary"<?= $isCompleted ? ' disabled' : '' ?>>Skipped</button></form>
              <?php if (!$isCompleted): ?>
                <form method="post" action="index.php"><?= csrf_field() ?><input type="hidden" name="action" value="postpone_dose"><input type="hidden" name="medication_id" value="<?= e((string) $dose['medication_id']) ?>"><input type="hidden" name="scheduled_date" value="<?= e($today) ?>"><input type="hidden" name="scheduled_time" value="<?= e((string) $dose['reminder_time']) ?>:00"><input type="hidden" name="postpone_minutes" value="5"><button type="submit" class="secondary"<?= (is_string($dose['postponed_until'] ?? null) && (string) $dose['postponed_until'] !== '') ? ' disabled' : '' ?>>Postpone 5m</button></form>
                <form method="post" action="index.php"><?= csrf_field() ?><input type="hidden" name="action" value="postpone_dose"><input type="hidden" name="medication_id" value="<?= e((string) $dose['medication_id']) ?>"><input type="hidden" name="scheduled_date" value="<?= e($today) ?>"><input type="hidden" name="scheduled_time" value="<?= e((string) $dose['reminder_time']) ?>:00"><input type="hidden" name="postpone_minutes" value="15"><button type="submit" class="secondary"<?= (is_string($dose['postponed_until'] ?? null) && (string) $dose['postponed_until'] !== '') ? ' disabled' : '' ?>>Postpone 15m</button></form>
                <form method="post" action="index.php"><?= csrf_field() ?><input type="hidden" name="action" value="postpone_dose"><input type="hidden" name="medication_id" value="<?= e((string) $dose['medication_id']) ?>"><input type="hidden" name="scheduled_date" value="<?= e($today) ?>"><input type="hidden" name="scheduled_time" value="<?= e((string) $dose['reminder_time']) ?>:00"><input type="hidden" name="postpone_minutes" value="30"><button type="submit" class="secondary"<?= (is_string($dose['postponed_until'] ?? null) && (string) $dose['postponed_until'] !== '') ? ' disabled' : '' ?>>Postpone 30m</button></form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </article>
  </section>

  <section class="panel history-panel"><div class="panel-heading"><h2>Recent history</h2></div><ol class="history-list"><?php foreach ($recentLogs as $log): ?><li><span><?= e(to12h((string) $log['scheduled_time'])) ?></span><div><strong><?= e((string) $log['name']) ?></strong><p><?= e((string) $log['status']) ?></p></div></li><?php endforeach; ?></ol></section>
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
      <label>Time format
        <select name="time_format">
          <option value="24h" <?= (($editing['time_format'] ?? '24h') === '24h') ? 'selected' : '' ?>>24-hour</option>
          <option value="12h" <?= (($editing['time_format'] ?? '24h') === '12h') ? 'selected' : '' ?>>12-hour</option>
        </select>
      </label>
      <label>Schedule type
        <select name="schedule_mode">
          <option value="fixed_times" <?= (($editing['schedule_mode'] ?? '') === 'fixed_times') ? 'selected' : '' ?>>Fixed times</option>
          <option value="interval" <?= (($editing['schedule_mode'] ?? '') === 'interval') ? 'selected' : '' ?>>Every X hours</option>
        </select>
      </label>
      <label>Fixed dose times (comma separated)
        <input name="dose_times" placeholder="08:00, 14:00, 21:00 OR 8:00 AM, 2:00 PM" value="<?= e(isset($editing['times']) ? implode(', ', $editing['times']) : '') ?>">
      </label>
      <label>Interval hours
        <input type="number" min="1" max="24" name="interval_hours" value="<?= e((string) ($editing['interval_hours'] ?? '')) ?>">
      </label>
      <label>First dose time
        <input name="first_dose_time" placeholder="08:00 or 8:00 AM" value="<?= e((string) (isset($editing['first_dose_time']) ? displayTimeByFormat((string) $editing['first_dose_time'], (string) ($editing['time_format'] ?? '24h')) : '')) ?>">
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
</body>
</html>
