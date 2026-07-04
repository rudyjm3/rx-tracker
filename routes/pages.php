<?php

declare(strict_types=1);

/** @var MedicationRepository $repository */
/** @var AuthService $auth */
/** @var string $today */
/** @var string $currentTime */
/** @var string $page */
/** @var string|null $error */
/** @var string|null $notice */

$graceMinutes = $repository->getMissedGraceMinutes();
$snoozeMinutes = $repository->getSnoozeMinutes();
$repository->finalizeMissedDoses(new DateTimeImmutable('now'), $graceMinutes);
$notice = trim((string) ($_GET['notice'] ?? '')) ?: null;

$medications = $repository->activeMedications();
$inactiveMedications = $repository->inactiveMedications();
$medicationPlanCount = count($medications);
$inactiveMedicationCount = count($inactiveMedications);
$todaySchedule = $repository->todaySchedule($today);
$todaySlotStatusMap = [];
foreach ($todaySchedule as $slot) {
    $todaySlotStatusMap[(int) $slot['medication_id']][$slot['reminder_time']] = (string) ($slot['status'] ?? '');
}
$recentLogs = $repository->recentLogs($today, 50);
$missedCount = $repository->missedDoseCount($today, $currentTime);

$requiredRows = array_filter($todaySchedule, static fn(array $row): bool => !$row['as_needed']);
$requiredByMed = [];
foreach ($requiredRows as $row) {
    $requiredByMed[(int) $row['medication_id']][] = $row;
}
$takenRows = array_filter($requiredRows, static fn(array $row): bool => (string) ($row['status'] ?? '') === 'taken');
$takenTodayCount = count(array_filter($recentLogs, static fn(array $l): bool =>
    (string) ($l['status'] ?? '') === 'taken' &&
    (string) ($l['scheduled_for_date'] ?? '') === $today &&
    !(bool) ($l['as_needed'] ?? false)
));
$adherence = count($requiredRows) > 0 ? (int) round(($takenTodayCount / count($requiredRows)) * 100) : 0;
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
    (float) ($m['low_supply_threshold'] ?? 0) > 0 &&
    (float) ($m['current_quantity'] ?? $m['pill_count'] ?? 0) <= (float) ($m['low_supply_threshold'] ?? 0)
));

$repository->syncStockNotifications($medications);
$navNotifications = $repository->getNotificationsForUser();
$navUnreadCount   = count(array_filter($navNotifications, static fn(array $n): bool => !(bool) $n['is_read']));

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
$skippedCount = count(array_filter($todaySchedule, static fn(array $row): bool =>
    (string) ($row['status'] ?? '') === 'skipped' && !(bool) $row['as_needed']
));

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#0754A8">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <title>RxTracker</title>
  <link rel="stylesheet" href="assets/css/styles.css?v=<?= filemtime(__DIR__ . '/../assets/css/styles.css') ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous">
  <link rel="icon" type="image/x-icon" href="assets/icons/favicon.ico">
  <link rel="icon" type="image/png" sizes="192x192" href="assets/icons/icon-192.png">
  <link rel="apple-touch-icon" href="assets/icons/icon-192.png">
  <link rel="manifest" href="manifest.json">
  <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>" defer></script>
</head>
<body>
<main class="app-shell">
  <nav class="top-nav">
    <a class="nav-brand" href="index.php">
      <img src="assets/icons/icon-192.png" alt="" class="nav-logo" aria-hidden="true" width="48" height="48">
      RxTracker
    </a>
    <div class="nav-links">
      <a href="index.php"<?= !in_array($page, ['settings', 'calendar', 'export', 'medications', 'help', 'pain-tracking', 'mood-wellbeing'], true) ? ' class="is-active"' : '' ?>>Dashboard</a>
      <a href="index.php?page=medications"<?= $page === 'medications' ? ' class="is-active"' : '' ?>>Medications</a>
      <a href="index.php?page=calendar"<?= $page === 'calendar' ? ' class="is-active"' : '' ?>>Calendar</a>
      <a href="index.php?page=export"<?= $page === 'export' ? ' class="is-active"' : '' ?>>Export</a>
    </div>
    <div class="nav-actions">
      <?php $currentUser = $auth->currentUser(); ?>
      <?php require __DIR__ . '/../includes/nav-bell.php'; ?>
      <?php
        $navAvatarColor = (string) ($activeProfile['avatar_color'] ?? '#6366f1');
        $navAvatarLetter = mb_strtoupper(mb_substr((string) ($activeProfile['display_name'] ?? ($currentUser['display_name'] ?? 'U')), 0, 1));
      ?>
      <div class="nav-user-menu" data-user-menu>
        <button type="button" class="nav-user-btn" aria-haspopup="true" aria-expanded="false" data-user-menu-btn
                title="<?= e($currentUser['email'] ?? '') ?>" aria-label="My profile">
          <span class="nav-user-avatar" style="background:<?= e($navAvatarColor) ?>"><?= e($navAvatarLetter) ?></span>
        </button>
        <div class="nav-user-menu-panel" data-user-menu-panel hidden>
          <a href="index.php?page=profile" class="nav-user-menu-link nav-user-menu-link--top">
            <i class="fa-solid fa-circle-user" aria-hidden="true"></i>
            My Profile
          </a>
          <?php if (!empty($familyProfiles)): ?>
          <div class="nav-user-menu-section-label">Family Members</div>
          <form method="post" action="index.php?page=profile" class="nav-user-menu-switcher-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="switch_family_profile">
            <input type="hidden" name="redirect_to" value="<?= e($_SERVER['REQUEST_URI'] ?? 'index.php') ?>">
            <button type="submit" name="profile_id" value="0"
                    class="profile-option<?= $activeProfileId === null ? ' is-active' : '' ?>">
              <span class="profile-option-avatar" style="background:#6366f1">
                <?= e(mb_strtoupper(mb_substr((string) ($currentUser['display_name'] ?? 'U'), 0, 1))) ?>
              </span>
              <?= e((string) ($currentUser['display_name'] ?? 'Me')) ?>
            </button>
            <?php foreach ($familyProfiles as $fp): ?>
            <button type="submit" name="profile_id" value="<?= (int) $fp['id'] ?>"
                    class="profile-option<?= $activeProfileId === (int) $fp['id'] ? ' is-active' : '' ?>">
              <span class="profile-option-avatar" style="background:<?= e((string) ($fp['avatar_color'] ?? '#6366f1')) ?>">
                <?= e(mb_strtoupper(mb_substr((string) $fp['display_name'], 0, 1))) ?>
              </span>
              <?= e((string) $fp['display_name']) ?>
              <?php if ($fp['relationship']): ?>
                <span class="profile-option-rel"><?= e((string) $fp['relationship']) ?></span>
              <?php endif; ?>
            </button>
            <?php endforeach; ?>
          </form>
          <a href="index.php?page=profile#family" class="nav-user-menu-link nav-user-menu-link--manage">
            <i class="fa-solid fa-users" aria-hidden="true"></i>
            Manage Family
          </a>
          <?php endif; ?>
        </div>
      </div>
      <a href="index.php?page=settings" class="nav-icon-link<?= $page === 'settings' ? ' is-active' : '' ?>"
         aria-label="Settings" title="Settings">
        <i class="fa-solid fa-gear" aria-hidden="true"></i>
      </a>
      <a href="index.php?page=help" class="nav-icon-link<?= $page === 'help' ? ' is-active' : '' ?>"
         aria-label="Help" title="Help">
        <i class="fa-solid fa-circle-question" aria-hidden="true"></i>
      </a>
    </div>
    <button class="nav-hamburger" aria-label="Menu" aria-expanded="false" data-nav-toggle>&#9776;</button>
  </nav>

  <?php if (!empty($activeProfile)): ?>
  <div class="profile-context-banner" role="status">
    <span class="profile-context-avatar" style="background:<?= e((string) ($activeProfile['avatar_color'] ?? '#6366f1')) ?>">
      <?= e(mb_strtoupper(mb_substr((string) $activeProfile['display_name'], 0, 1))) ?>
    </span>
    <span>Viewing <strong><?= e((string) $activeProfile['display_name']) ?></strong>'s medications</span>
    <form method="post" action="index.php?page=profile" style="display:inline">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="switch_family_profile">
      <input type="hidden" name="profile_id" value="0">
      <input type="hidden" name="redirect_to" value="<?= e($_SERVER['REQUEST_URI'] ?? 'index.php') ?>">
      <button type="submit" class="profile-context-switch-btn">Switch back to Me</button>
    </form>
  </div>
  <?php endif; ?>

  <?php if (!in_array($page, ['settings', 'calendar', 'export', 'medications', 'help', 'pain-tracking', 'mood-wellbeing'], true)): ?>
  <section class="hero">
    <div class="hero-left">
      <div class="hero-card hero-next-dose-panel" aria-label="Next dose">
        <?php if ($heroNextDoseItems !== []): ?>
          <?php $ndItem = $heroNextDoseItems[0]; ?>
          <div class="hero-next-dose-primary">
            <div class="hero-next-dose-info">
              <div class="hero-next-dose-eyebrow"><i class="fa-regular fa-clock" aria-hidden="true"></i> NEXT DOSE</div>
              <div class="hero-next-dose-time-large"><?= e(to12h((string) $ndItem['reminder_time'])) ?></div>
              <?php if ($ndItem['group_id'] !== null): ?>
                <div class="hero-next-dose-name-large"><?= e((string) $ndItem['group_name']) ?></div>
                <p class="hero-next-dose-meta"><?= e((string) count($ndItem['_group_members'])) ?> medication<?= count($ndItem['_group_members']) !== 1 ? 's' : '' ?> in group</p>
                <button type="button" class="group-meds-toggle" data-group-meds-toggle>view group meds</button>
                <div class="group-meds-list" hidden>
                  <?php $heroMedTypeLabels = ['prescription' => 'Rx', 'otc' => 'OTC', 'supplement' => 'Supplement']; ?>
                  <?php foreach ($ndItem['_group_members'] as $ndMember): ?>
                    <?php $heroGrpTypeSlug = (string) ($ndMember['medication_type'] ?? 'prescription'); ?>
                    <div class="group-meds-member">
                      <span class="hero-med-name"><?= e((string) $ndMember['name']) ?></span><span class="med-type-badge med-type-badge--<?= e($heroGrpTypeSlug) ?>"><?= e($heroMedTypeLabels[$heroGrpTypeSlug] ?? 'Rx') ?></span>
                      <span class="hero-med-dose"><?= e(formattedDose($ndMember)) ?></span>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <?php $heroSingleTypeSlug = (string) ($ndItem['medication_type'] ?? 'prescription'); $heroMedTypeLabels = ['prescription' => 'Rx', 'otc' => 'OTC', 'supplement' => 'Supplement']; ?>
                <div class="hero-next-dose-name-large"><?= e((string) $ndItem['name']) ?><span class="med-type-badge med-type-badge--<?= e($heroSingleTypeSlug) ?>"><?= e($heroMedTypeLabels[$heroSingleTypeSlug] ?? 'Rx') ?></span></div>
                <?php if (formattedDose($ndItem) !== ''): ?>
                  <span class="hero-dose-badge"><?= e(formattedDose($ndItem)) ?></span>
                <?php endif; ?>
              <?php endif; ?>
            </div>
            <div class="hero-med-graphic" aria-hidden="true">
              <?php
              $heroMedImageMap = [
                'tablet'    => 'med-pill.png',
                'capsule'   => 'med-capsule.png',
                'liquid'    => 'med-bottle.png',
                'inhaler'   => 'med-inhaler.png',
                'injection' => 'med-injection.png',
                'patch'     => 'med-patch.png',
                'drops'     => 'med-drop.png',
              ];
              $heroMedImg = $heroMedImageMap[(string) ($ndItem['dose_form'] ?? '')] ?? 'med-pill.png';
              ?>
              <img src="assets/images/<?= e($heroMedImg) ?>" alt="" class="med-graphic-image">
            </div>
          </div>
          <?php if (isset($heroNextDoseItems[1])): ?>
            <?php $ndNext = $heroNextDoseItems[1]; ?>
            <div class="hero-upcoming-section">
              <div class="hero-upcoming-label">UPCOMING</div>
              <div class="hero-upcoming-row">
                <span class="hero-upcoming-time"><?= e(to12h((string) $ndNext['reminder_time'])) ?></span>
                <span class="hero-upcoming-name"><?= e($ndNext['group_id'] !== null ? (string) $ndNext['group_name'] : (string) $ndNext['name']) ?></span>
                <?php if ($ndNext['group_id'] === null && formattedDose($ndNext) !== ''): ?>
                  <span class="hero-upcoming-dose-badge"><?= e(formattedDose($ndNext)) ?></span>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <div class="hero-next-dose-info">
            <div class="hero-next-dose-eyebrow"><i class="fa-regular fa-clock" aria-hidden="true"></i> NEXT DOSE</div>
            <p class="hero-copy">All scheduled doses complete for today.</p>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="hero-card hero-adherence-card" aria-label="Today's adherence summary">
      <div class="hero-adherence-header"><i class="fa-regular fa-calendar-check" aria-hidden="true"></i> TODAY'S ADHERENCE</div>
      <div class="hero-adherence-body">
        <div class="adherence-ring-wrap">
          <svg class="adherence-ring" viewBox="0 0 100 100" aria-hidden="true">
            <defs>
              <linearGradient id="adherence-gradient" x1="0%" y1="100%" x2="100%" y2="0%">
                <stop offset="0%" stop-color="rgba(255,255,255,0.6)"/>
                <stop offset="100%" stop-color="#ffffff"/>
              </linearGradient>
            </defs>
            <circle class="adherence-ring-track" cx="50" cy="50" r="42" fill="none"/>
            <circle class="adherence-ring-fill" cx="50" cy="50" r="42" fill="none" data-adherence-pct="<?= e((string) $adherence) ?>"/>
          </svg>
          <span class="adherence-ring-num" data-adherence-num>0%</span>
        </div>
        <div class="hero-adherence-stats">
          <span>Required doses taken: <?= e((string) $takenTodayCount) ?> of <?= e((string) count($requiredRows)) ?></span>
          <?php if ($onTimeCount + $lateCount > 0): ?>
            <span>On time: <?= e((string) $onTimeCount) ?> &middot; Late: <?= e((string) $lateCount) ?><?php if ($skippedCount > 0): ?> &middot; Skipped: <?= e((string) $skippedCount) ?><?php endif; ?></span>
          <?php elseif ($skippedCount > 0): ?>
            <span>Skipped: <?= e((string) $skippedCount) ?></span>
          <?php endif; ?>
          <span>Missed required doses today: <?= e((string) $missedCount) ?></span>
        </div>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($notice !== null): ?><div class="notice"><?= e($notice) ?></div><?php endif; ?>
  <?php if ($error !== null): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>

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
        <input type="hidden" name="redirect_page" value="<?= e($page) ?>">

        <label class="autocomplete-wrap">Name
          <input name="name" required autocomplete="off" data-med-name-input value="<?= e((string) ($editing['name'] ?? '')) ?>">
          <ul class="autocomplete-dropdown" data-autocomplete-dropdown hidden></ul>
        </label>

        <fieldset class="form-section">
          <legend>Dose info</legend>
          <label>Medication type
            <select name="medication_type">
              <option value="prescription" <?= (($editing['medication_type'] ?? 'prescription') === 'prescription') ? 'selected' : '' ?>>Prescription</option>
              <option value="otc"          <?= (($editing['medication_type'] ?? '') === 'otc')          ? 'selected' : '' ?>>OTC Medication</option>
              <option value="supplement"   <?= (($editing['medication_type'] ?? '') === 'supplement')   ? 'selected' : '' ?>>Vitamin / Supplement</option>
            </select>
          </label>
          <label>Start date <span class="field-optional">(optional — when you began taking this medication)</span>
            <input type="date" name="start_date" value="<?= e((string) ($editing['start_date'] ?? '')) ?>">
          </label>
          <label>Dose amount
            <input type="number" step="0.001" min="0" name="dose_amount" data-dailymed-dose-amount value="<?= e($editing && ($editing['dose_amount'] ?? '') !== '' ? (string)(float)$editing['dose_amount'] : '') ?>">
          </label>
          <label>Dose unit
            <select name="dose_unit" data-dailymed-dose-unit>
              <?php
              $doseUnits = ['mg', 'mcg', 'g', 'mL', 'tsp', 'tbsp', 'oz', 'IU', 'units', 'drops', 'puffs', 'patches'];
              $selectedDoseUnit = (string) ($editing['dose_unit'] ?? 'mg');
              foreach ($doseUnits as $u): ?>
              <option value="<?= e($u) ?>" <?= $selectedDoseUnit === $u ? 'selected' : '' ?>><?= e($u) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Dose form <span class="field-optional">(optional)</span>
            <select name="dose_form" data-dailymed-dose-form>
              <?php
              $doseForms = ['', 'tablet', 'capsule', 'liquid', 'inhaler', 'injection', 'patch', 'drops', 'other'];
              $doseFormLabels = ['' => '-- select --', 'tablet' => 'Tablet', 'capsule' => 'Capsule', 'liquid' => 'Liquid', 'inhaler' => 'Inhaler', 'injection' => 'Injection', 'patch' => 'Patch', 'drops' => 'Drops', 'other' => 'Other'];
              $selectedDoseForm = (string) ($editing['dose_form'] ?? '');
              foreach ($doseForms as $f): ?>
              <option value="<?= e($f) ?>" <?= $selectedDoseForm === $f ? 'selected' : '' ?>><?= e($doseFormLabels[$f]) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </fieldset>

        <label>Schedule type
          <select name="schedule_mode">
            <option value="fixed_times" <?= (($editing['schedule_mode'] ?? '') === 'fixed_times') ? 'selected' : '' ?>>Fixed times</option>
            <option value="interval" <?= (($editing['schedule_mode'] ?? '') === 'interval') ? 'selected' : '' ?>>Every X hours</option>
          </select>
        </label>
        <div data-dose-times-section>
          <div class="dose-times-label">Dose times <span class="field-optional">(one per row)</span></div>
          <div data-dose-time-rows>
          <?php
          $editingTimes     = $editing['times']      ?? [];
          $editingTimeDoses = $editing['time_doses']  ?? [];
          if ($editingTimes === []):
          ?>
            <div class="dose-time-row">
              <input type="text" name="dose_times[]" placeholder="8:00 AM" class="dose-time-field" autocomplete="off">
              <input type="number" name="dose_qtys[]" min="0.25" step="0.25" placeholder="Qty (default)" class="dose-qty-field">
              <button type="button" class="btn-icon remove-dose-time" aria-label="Remove time">−</button>
            </div>
          <?php else: ?>
            <?php foreach ($editingTimes as $t): ?>
            <div class="dose-time-row">
              <input type="text" name="dose_times[]" placeholder="8:00 AM" class="dose-time-field" autocomplete="off" value="<?= e(to12h($t)) ?>">
              <input type="number" name="dose_qtys[]" min="0.25" step="0.25" placeholder="Qty (default)" class="dose-qty-field" value="<?= e((string) ($editingTimeDoses[$t] ?? '')) ?>">
              <button type="button" class="btn-icon remove-dose-time" aria-label="Remove time">−</button>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
          </div>
          <button type="button" class="btn-text" data-add-dose-time>+ Add time</button>
        </div>
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
          <small class="field-hint">If Yes, excluded from the dashboard's required dose count.</small>
        </label>
        <?php $editingFeedbackType = (string) ($editing['feedback_type'] ?? 'none'); ?>
        <label>Track dose feedback
          <select name="feedback_type">
            <option value="none" <?= $editingFeedbackType === 'none' ? 'selected' : '' ?>>No tracking</option>
            <option value="mood" <?= $editingFeedbackType === 'mood' ? 'selected' : '' ?>>Mood level</option>
            <option value="pain" <?= $editingFeedbackType === 'pain' ? 'selected' : '' ?>>Pain level</option>
            <option value="both" <?= $editingFeedbackType === 'both' ? 'selected' : '' ?>>Both pain and mood</option>
          </select>
        </label>
        <details class="form-disclosure" <?= (!empty($editing) && (float) ($editing['current_quantity'] ?? 0) > 0) ? 'open' : '' ?>>
          <summary class="form-disclosure-toggle">Inventory tracking</summary>
        <fieldset class="form-section" data-inventory-section>
          <legend>Inventory</legend>
          <label data-inv-qty-label>Starting quantity
            <span class="input-with-unit">
              <input type="number" step="0.001" min="0" name="starting_quantity" value="<?= e((string)(float)($editing['current_quantity'] ?? $editing['pill_count'] ?? 0)) ?>">
              <span data-inv-unit-label><?= e((string) ($editing['inventory_unit'] ?? 'tablets')) ?></span>
            </span>
          </label>

          <label data-inv-liquid-label style="display:none">Bottle amount
            <span class="input-with-unit">
              <?php
              $storedMl = (float) ($editing['current_quantity'] ?? 0);
              $bottleDisplayVal = $storedMl > 0 ? (string)(float)round($storedMl, 3) : '';
              ?>
              <input type="number" step="0.001" min="0" name="bottle_amount" data-bottle-amount-input value="<?= e($bottleDisplayVal) ?>">
              <select name="bottle_unit" data-bottle-unit-select>
                <option value="mL">mL</option>
                <option value="oz">oz</option>
              </select>
            </span>
          </label>

          <label>Dose reduces inventory by
            <span class="input-with-unit">
              <input type="number" step="0.001" min="0.001" name="quantity_per_dose" value="<?= e((string)(float)($editing['quantity_per_dose'] ?? 1)) ?>">
              <span data-inv-unit-label><?= e((string) ($editing['inventory_unit'] ?? 'tablets')) ?></span>
            </span>
          </label>

          <label>Low supply alert at
            <span class="input-with-unit">
              <input type="number" step="0.001" min="0" name="low_supply_threshold" value="<?= e((string)(float)($editing['low_supply_threshold'] ?? 0)) ?>">
              <span data-inv-unit-label><?= e((string) ($editing['inventory_unit'] ?? 'tablets')) ?></span>
            </span>
          </label>
        </fieldset>
        </details>

        <label>Instructions and Notes<textarea name="instructions" rows="3"><?= e((string) ($editing['instructions'] ?? '')) ?></textarea></label>
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
        <div class="pain-graph-controls">
          <div class="pain-graph-range-tabs" role="group" aria-label="Date range">
            <button class="range-tab is-active" data-range="0">Today</button>
            <button class="range-tab" data-range="7">7 days</button>
            <button class="range-tab" data-range="30">30 days</button>
            <button class="range-tab" data-range="90">90 days</button>
          </div>
          <button type="button" class="pain-graph-print-btn" data-pain-graph-print aria-label="Print pain graph" title="Print">
            <i class="fa-solid fa-print" aria-hidden="true"></i>
          </button>
        </div>
        <div class="pain-graph-body" data-pain-graph-body></div>
        <p class="pain-graph-empty" data-pain-graph-empty hidden>No pain level data recorded for this period.</p>
      </div>
    </div>
  </div>

  <div class="modal-overlay" data-mood-graph-modal>
    <div class="modal-dialog pain-graph-dialog" role="dialog" aria-modal="true" aria-labelledby="mood-graph-title">
      <div class="modal-header">
        <h2 id="mood-graph-title" data-mood-graph-title>Mood Trend</h2>
        <button type="button" class="icon-button" data-close-mood-graph aria-label="Close mood graph">&#10005;</button>
      </div>
      <div class="modal-scroll">
        <div class="pain-graph-controls">
          <div class="pain-graph-range-tabs" role="group" aria-label="Date range">
            <button class="range-tab is-active" data-range="0">Today</button>
            <button class="range-tab" data-range="7">7 days</button>
            <button class="range-tab" data-range="30">30 days</button>
            <button class="range-tab" data-range="90">90 days</button>
          </div>
          <button type="button" class="pain-graph-print-btn" data-mood-graph-print aria-label="Print mood graph" title="Print">
            <i class="fa-solid fa-print" aria-hidden="true"></i>
          </button>
        </div>
        <div class="pain-graph-body" data-mood-graph-body></div>
        <p class="pain-graph-empty" data-mood-graph-empty hidden>No mood data recorded for this period.</p>
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
        <input type="hidden" name="mood_level" data-feedback-mood-level value="">

        <div class="feedback-pain-section" data-feedback-pain-section>
          <p class="feedback-pain-label">Pain level <span class="feedback-pain-hint">(1 = minimal &mdash; 10 = severe)</span></p>
          <div class="pain-level-selector" role="group" aria-label="Select pain level">
            <?php for ($i = 1; $i <= 10; $i++): ?>
              <button type="button" class="pain-level-btn" data-pain-level="<?= $i ?>" aria-label="Pain level <?= $i ?>"><?= $i ?></button>
            <?php endfor; ?>
          </div>
        </div>

        <div class="feedback-mood-section" data-feedback-mood-section>
          <p class="feedback-pain-label">Mood level <span class="feedback-pain-hint">(1 = very low &mdash; 10 = excellent)</span></p>
          <div class="pain-level-selector" role="group" aria-label="Select mood level">
            <?php for ($i = 1; $i <= 10; $i++): ?>
              <button type="button" class="mood-level-btn" data-mood-level="<?= $i ?>" aria-label="Mood level <?= $i ?>"><?= $i ?></button>
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

  <!-- Side effect log modal -->
  <div class="modal-overlay" id="side-effect-modal" data-se-modal>
    <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="se-modal-title">
      <div class="modal-header">
        <h2 id="se-modal-title">Log Side Effect</h2>
        <button type="button" class="icon-button" data-close-se-modal aria-label="Close">&#10005;</button>
      </div>
      <div class="modal-scroll">
      <form method="post" action="index.php" class="stacked-form" data-se-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="log_side_effect">
        <input type="hidden" name="medication_id" id="se-medication-id" value="">
        <label>Date
          <input type="date" name="occurred_date" value="<?= e(date('Y-m-d')) ?>">
        </label>
        <label>Description <span class="field-required">*</span>
          <input type="text" name="description" maxlength="255" required placeholder="e.g. Nausea, headache, dizziness">
        </label>
        <label>Severity
          <select name="severity">
            <option value="mild">Mild</option>
            <option value="moderate">Moderate</option>
            <option value="severe">Severe</option>
          </select>
        </label>
        <label>Notes <span class="field-optional">(optional)</span>
          <textarea name="note" rows="3" maxlength="500" placeholder="Any additional context or observations"></textarea>
        </label>
        <button type="submit">Log side effect</button>
        <button type="button" class="secondary" data-close-se-modal>Cancel</button>
      </form>
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
      </div>
      <div class="medication-plan-tabs" role="tablist" aria-label="Medication status lists">
        <button type="button" class="plan-tab is-active" data-plan-tab="active" role="tab" aria-selected="true" aria-controls="active-medications-panel" id="active-medications-tab"><i class="fa-regular fa-circle-check" aria-hidden="true"></i> Active (<?= e((string) $medicationPlanCount) ?>)</button>
        <button type="button" class="plan-tab" data-plan-tab="inactive" role="tab" aria-selected="false" aria-controls="inactive-medications-panel" id="inactive-medications-tab"><i class="fa-regular fa-clock" aria-hidden="true"></i> Inactive (<?= e((string) $inactiveMedicationCount) ?>)</button>
        <button type="button" class="plan-tab" data-plan-tab="groups" role="tab" aria-selected="false" aria-controls="groups-panel" id="groups-tab"><i class="fa-solid fa-layer-group" aria-hidden="true"></i> Groups (<?= e((string) count($groups)) ?>)</button>
      </div>
      <div class="medications-page-actions">
        <button type="button" data-open-medication-modal><i class="fa-solid fa-plus" aria-hidden="true"></i> Add medication</button>
      </div>
    </div>
    <?php include dirname(__DIR__) . '/includes/medication-plan-tabs.php'; ?>
  </section>
  <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
  <?php endif; ?>

  <?php if ($page === 'pain-tracking'): ?>
  <?php
    $trackedMedications = array_values(array_filter(
        $medications,
        fn(array $m): bool => $repository->medicationTracksPain($m)
    ));
  ?>
  <section class="pain-tracking-page">
    <div class="pain-tracking-header">
      <h1>Pain Tracking</h1>
    </div>

    <?php if ($trackedMedications === []): ?>
    <div class="pain-tracking-empty">
      <p>No medications are currently set up for pain tracking. Enable &ldquo;Track dose feedback&rdquo; on a medication to start recording pain levels.</p>
      <a href="index.php?page=medications" class="button secondary">Manage medications</a>
    </div>
    <?php else: ?>

    <div class="pain-tracking-med-panel">
      <div class="panel-heading"><h2>Tracked medications</h2></div>
      <div class="pain-tracking-med-list" role="group" aria-label="Select medication to view">
        <?php foreach ($trackedMedications as $trackedMed): ?>
        <button
          type="button"
          class="pain-tracking-med-btn"
          data-select-medication
          data-medication-id="<?= e((string) $trackedMed['id']) ?>"
          data-medication-name="<?= e((string) $trackedMed['name']) ?>"
        ><?= e((string) $trackedMed['name']) ?><?php if ((string) $trackedMed['dose'] !== ''): ?><span class="pain-tracking-med-dose"><?= e((string) $trackedMed['dose']) ?></span><?php endif; ?></button>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="pain-tracking-chart-section">
      <div class="pain-tracking-med-name" data-pain-page-med-name aria-live="polite"></div>
      <div class="pain-graph-range-tabs" role="group" aria-label="Date range">
        <button class="pain-page-range-tab is-active" data-range="0">Today</button>
        <button class="pain-page-range-tab" data-range="7">7 days</button>
        <button class="pain-page-range-tab" data-range="30">30 days</button>
        <button class="pain-page-range-tab" data-range="90">90 days</button>
      </div>
      <div class="pain-graph-body" data-pain-page-body></div>
      <p class="pain-graph-empty" data-pain-page-empty hidden>No pain level data recorded for this period.</p>
    </div>

    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($page === 'mood-wellbeing'): ?>
  <?php
    $moodTrackedMedications = array_values(array_filter(
        $medications,
        fn(array $m): bool => $repository->medicationTracksMood($m)
    ));
  ?>
  <section class="pain-tracking-page mood-tracking-page">
    <div class="pain-tracking-header mood-tracking-header">
      <h1>Mood &amp; Wellbeing</h1>
    </div>

    <?php if ($moodTrackedMedications === []): ?>
    <div class="pain-tracking-empty mood-tracking-empty">
      <p>No medications are currently set up for mood tracking. Set Feedback tracking to Mood or Both on a medication to start.</p>
      <a href="index.php?page=medications" class="button secondary">Manage medications</a>
    </div>
    <?php else: ?>

    <div class="pain-tracking-med-panel mood-tracking-med-panel">
      <div class="panel-heading"><h2>Tracked medications</h2></div>
      <div class="pain-tracking-med-list mood-tracking-med-list" role="group" aria-label="Select medication to view">
        <?php foreach ($moodTrackedMedications as $trackedMed): ?>
        <button
          type="button"
          class="pain-tracking-med-btn mood-tracking-med-btn"
          data-select-mood-medication
          data-medication-id="<?= e((string) $trackedMed['id']) ?>"
          data-medication-name="<?= e((string) $trackedMed['name']) ?>"
        ><?= e((string) $trackedMed['name']) ?><?php if ((string) $trackedMed['dose'] !== ''): ?><span class="pain-tracking-med-dose mood-tracking-med-dose"><?= e((string) $trackedMed['dose']) ?></span><?php endif; ?></button>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="pain-tracking-chart-section mood-tracking-chart-section">
      <div class="pain-tracking-med-name mood-tracking-med-name" data-mood-page-med-name aria-live="polite"></div>
      <div class="pain-graph-range-tabs" role="group" aria-label="Date range">
        <button class="mood-page-range-tab is-active" data-range="0">Today</button>
        <button class="mood-page-range-tab" data-range="7">7 days</button>
        <button class="mood-page-range-tab" data-range="30">30 days</button>
        <button class="mood-page-range-tab" data-range="90">90 days</button>
      </div>
      <div class="pain-graph-body" data-mood-page-body></div>
      <p class="pain-graph-empty" data-mood-page-empty hidden>No mood level data recorded for this period.</p>
    </div>

    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($page === 'settings'): ?>
    <?php
      $vapidConfigured = trim((string) getenv('PUSH_VAPID_PUBLIC_KEY')) !== ''
          && trim((string) getenv('PUSH_VAPID_PRIVATE_KEY')) !== ''
          && trim((string) getenv('PUSH_VAPID_SUBJECT')) !== '';
      $webPushInstalled = is_file(dirname(__DIR__) . '/vendor/autoload.php')
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

    <section class="panel settings-panel" style="margin-top:1rem;">
      <div class="panel-heading"><h2>Help &amp; Documentation</h2></div>
      <p style="margin:0 0 .75rem;">New to RxTracker or need a refresher? The user guide covers every feature step by step.</p>
      <a href="index.php?page=help" class="button secondary" style="display:inline-block;">Open User Guide</a>
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
    $calendarDayData = [];
    foreach ($repository->calendarLogsForMonth($monthStart, $monthEnd) as $log) {
        $cdDate  = (string) $log['scheduled_for_date'];
        $cdMedId = (int) $log['medication_id'];
        if (!isset($calendarDayData[$cdDate])) {
            $cdDt = new DateTimeImmutable($cdDate);
            $calendarDayData[$cdDate] = [
                'dayName'     => $cdDt->format('l'),
                'displayDate' => $cdDt->format('F j, Y'),
                'medications' => [],
            ];
        }
        if (!isset($calendarDayData[$cdDate]['medications'][$cdMedId])) {
            $calendarDayData[$cdDate]['medications'][$cdMedId] = [
                'name'          => (string) $log['name'],
                'doseFormatted' => formattedDose($log),
                'total' => 0, 'taken' => 0, 'late' => 0, 'skipped' => 0, 'missed' => 0,
                'slots' => [],
            ];
        }
        $cdStatus  = (string) $log['status'];
        $cdLateMin = $cdStatus === 'taken' ? minutesLate($log, $graceMinutes) : null;
        $cdMed = &$calendarDayData[$cdDate]['medications'][$cdMedId];
        $cdMed['total']++;
        if ($cdStatus === 'taken') { $cdMed['taken']++; if ($cdLateMin !== null) $cdMed['late']++; }
        elseif ($cdStatus === 'skipped') $cdMed['skipped']++;
        elseif ($cdStatus === 'missed')  $cdMed['missed']++;
        $cdMed['slots'][] = [
            'displayTime' => to12h((string) $log['scheduled_time']),
            'status'      => $cdStatus,
            'isLate'      => $cdLateMin !== null,
            'lateLabel'   => $cdLateMin !== null ? formatLate($cdLateMin) : null,
        ];
        unset($cdMed);
    }
    foreach ($calendarDayData as &$cdDay) {
        $cdDay['medications'] = array_values($cdDay['medications']);
    }
    unset($cdDay);
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
        <?php $cdHasData = isset($calendarDayData[$dateStr]); ?>
        <div class="calendar-day <?= e($dayClass) ?><?= $isToday ? ' calendar-day--today' : '' ?>"<?= (!$isFuture && $cdHasData) ? ' data-calendar-day data-date="' . e($dateStr) . '"' : '' ?>>
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
  <script>const calendarDayData = <?= json_encode($calendarDayData, JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
  <p class="disclaimer">RxTracker is a tracking aid only and does not provide medical advice or clinical decision support.</p>
</main>
<?php include __DIR__ . '/../includes/bottom-nav.php'; ?>

<div class="modal-overlay" data-calendar-day-modal>
  <div class="modal-dialog calendar-day-dialog" role="dialog" aria-modal="true" aria-labelledby="calendar-day-modal-title">
    <div class="modal-header">
      <h2 id="calendar-day-modal-title" data-calendar-day-modal-title></h2>
      <button type="button" class="modal-close" data-close-calendar-day-modal aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    </div>
    <div class="modal-scroll" data-calendar-day-modal-body></div>
  </div>
</div>

</body>
</html>
<?php exit; ?>
<?php endif; ?>

  <?php if ($page === 'export'): ?>
  <?php
    // Default reporting period: last 30 days
    $reportDefaultStart = date('Y-m-d', strtotime('-30 days'));
    $reportDefaultEnd   = date('Y-m-d');
    $reportStart = (string) ($_GET['report_start'] ?? $reportDefaultStart);
    $reportEnd   = (string) ($_GET['report_end']   ?? $reportDefaultEnd);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportStart)) { $reportStart = $reportDefaultStart; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportEnd))   { $reportEnd   = $reportDefaultEnd; }
    if ($reportStart > $reportEnd) { $reportStart = $reportDefaultStart; }

    $trackedMedications = array_values(array_filter(
        $medications,
        fn(array $m): bool => $repository->medicationTracksPain($m)
    ));
    $moodTrackedMedicationsExport = array_values(array_filter(
        $medications,
        fn(array $m): bool => $repository->medicationTracksMood($m)
    ));

    // Compute days-on-medication and default chart range for each pain-tracked medication
    $computeChartInfo = static function (array $meds): array {
        $info = [];
        foreach ($meds as $m) {
            $sd     = !empty($m['start_date']) ? (string) $m['start_date'] : date('Y-m-d');
            $daysOn = max(0, (int) floor((time() - strtotime($sd)) / 86400));
            if ($daysOn < 7) {
                $defaultRange = 0;
                $extraOpts    = [];
            } elseif ($daysOn < 30) {
                $defaultRange = 7;
                $extraOpts    = [];
            } elseif ($daysOn < 90) {
                $defaultRange = 30;
                $extraOpts    = [7];
            } else {
                $defaultRange = 90;
                $extraOpts    = [7, 30];
            }
            $info[(int) $m['id']] = [
                'days_on'       => $daysOn,
                'default_range' => $defaultRange,
                'extra_opts'    => $extraOpts,
                'start_date'    => $sd,
            ];
        }
        return $info;
    };
    $medChartInfo = $computeChartInfo($trackedMedications);
    $moodChartInfo = $computeChartInfo($moodTrackedMedicationsExport);
  ?>
  <?php if ($error !== null): ?>
    <div class="alert" style="max-width:700px;margin:1.5rem auto 0;"><?= e($error) ?></div>
  <?php endif; ?>

  <section class="panel export-section" style="max-width:700px;margin:1.5rem auto 0;">
    <div class="panel-heading">
      <h2>Reporting Period</h2>
    </div>
    <p style="color:var(--rx-text-muted);margin-bottom:1rem;font-size:0.9rem;">
      Shared by both reports below.
    </p>
    <div style="display:flex;gap:1rem;flex-wrap:wrap;">
      <label style="flex:1;min-width:140px;">From
        <input type="date" id="report-start-shared" value="<?= e($reportStart) ?>" required>
      </label>
      <label style="flex:1;min-width:140px;">To
        <input type="date" id="report-end-shared" value="<?= e($reportEnd) ?>" required>
      </label>
    </div>
  </section>

  <section class="panel export-section" style="max-width:700px;margin:1.25rem auto;">
    <div class="panel-heading">
      <h2>Pain Level Tracking (Doctor Visit Report)</h2>
    </div>
    <p style="color:var(--rx-text-muted);margin-bottom:1.25rem;font-size:0.9rem;">
      Generate a branded PDF summary of your medication history, adherence, pain trends, and side effects — ready to share with your doctor.
    </p>
    <form method="post" action="index.php" class="stacked-form" data-export-form="pain">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="generate_doctor_visit_report">
      <input type="hidden" name="download_token" data-download-token value="">
      <input type="hidden" name="report_start" data-report-start-mirror value="<?= e($reportStart) ?>">
      <input type="hidden" name="report_end" data-report-end-mirror value="<?= e($reportEnd) ?>">

      <?php if ($trackedMedications !== []): ?>
      <fieldset style="border:1px solid var(--rx-border);border-radius:var(--rx-radius-sm);padding:1rem 1.25rem;margin-bottom:1.25rem;">
        <legend style="padding:0 0.5rem;font-weight:600;color:var(--rx-navy);">Pain chart range per medication</legend>
        <p style="font-size:0.85rem;color:var(--rx-text-muted);margin-top:0.5rem;margin-bottom:0.75rem;">
          Charts are based on days on medication. The default range is pre-selected; you can choose a different window if you prefer.
        </p>
        <?php foreach ($trackedMedications as $m): ?>
          <?php
            $mId   = (int) $m['id'];
            $info  = $medChartInfo[$mId];
            $daysOn = $info['days_on'];
            $defR   = $info['default_range'];
          ?>
          <div style="margin-bottom:0.75rem;padding:0.6rem 0.75rem;background:var(--rx-bg);border-radius:8px;">
            <strong><?= e((string) $m['name']) ?></strong>
            <span style="font-size:0.8rem;color:var(--rx-text-muted);margin-left:0.5rem;"><?= $daysOn ?> days on medication</span>
            <?php if ($defR === 0): ?>
              <p style="font-size:0.82rem;color:var(--rx-text-muted);margin-top:4px;font-style:italic;">
                Pain tracking started <?= e(date('F j', strtotime($info['start_date']))) ?> — check back after a few more days of logged doses.
              </p>
              <input type="hidden" name="chart_days[<?= $mId ?>]" value="0">
            <?php else: ?>
              <div style="margin-top:0.4rem;">
                <label style="font-size:0.88rem;">Chart window
                  <select name="chart_days[<?= $mId ?>]" style="margin-left:0.5rem;">
                    <?php if (in_array(7, $info['extra_opts'], true) || $defR === 7): ?>
                      <option value="7" <?= $defR === 7 ? 'selected' : '' ?>>7 days</option>
                    <?php endif; ?>
                    <?php if (in_array(30, $info['extra_opts'], true) || $defR === 30): ?>
                      <option value="30" <?= $defR === 30 ? 'selected' : '' ?>>30 days</option>
                    <?php endif; ?>
                    <?php if ($defR === 90 || $daysOn >= 90): ?>
                      <option value="90" <?= $defR === 90 ? 'selected' : '' ?>>90 days</option>
                    <?php endif; ?>
                  </select>
                </label>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </fieldset>
      <?php else: ?>
      <p style="font-size:0.85rem;color:var(--rx-text-muted);margin-bottom:1.25rem;font-style:italic;">
        No medications are currently tracking pain levels.
      </p>
      <?php endif; ?>

      <button type="submit" style="width:100%;" data-export-btn>
        <i class="fa-solid fa-file-pdf" aria-hidden="true"></i> Generate &amp; Download PDF
      </button>
      <div data-export-notice style="display:none;align-items:center;flex-wrap:wrap;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;color:#166534;gap:0.6rem;margin-top:0.75rem;padding:0.7rem 1rem;">
        <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
        Your PDF is downloading — check your Downloads folder.
        <a data-view-pdf-link href="#" hidden style="margin-left:auto;font-weight:600;color:#166534;text-decoration:underline;white-space:nowrap;">
          <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i> Open PDF
        </a>
      </div>
    </form>
  </section>

  <section class="panel export-section" style="max-width:700px;margin:1.25rem auto;">
    <div class="panel-heading">
      <h2>Mood and Wellbeing Tracking</h2>
    </div>
    <p style="color:var(--rx-text-muted);margin-bottom:1.25rem;font-size:0.9rem;">
      Generate a branded PDF summary of your medication history, adherence, mood trends, and side effects — ready to share with your doctor.
    </p>
    <form method="post" action="index.php" class="stacked-form" data-export-form="mood">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="generate_mood_report">
      <input type="hidden" name="download_token" data-download-token value="">
      <input type="hidden" name="report_start" data-report-start-mirror value="<?= e($reportStart) ?>">
      <input type="hidden" name="report_end" data-report-end-mirror value="<?= e($reportEnd) ?>">

      <?php if ($moodTrackedMedicationsExport !== []): ?>
      <fieldset style="border:1px solid var(--rx-border);border-radius:var(--rx-radius-sm);padding:1rem 1.25rem;margin-bottom:1.25rem;">
        <legend style="padding:0 0.5rem;font-weight:600;color:var(--rx-navy);">Mood chart range per medication</legend>
        <p style="font-size:0.85rem;color:var(--rx-text-muted);margin-top:0.5rem;margin-bottom:0.75rem;">
          Charts are based on days on medication. The default range is pre-selected; you can choose a different window if you prefer.
        </p>
        <?php foreach ($moodTrackedMedicationsExport as $m): ?>
          <?php
            $mmId   = (int) $m['id'];
            $minfo  = $moodChartInfo[$mmId];
            $mDaysOn = $minfo['days_on'];
            $mDefR   = $minfo['default_range'];
          ?>
          <div style="margin-bottom:0.75rem;padding:0.6rem 0.75rem;background:var(--rx-bg);border-radius:8px;">
            <strong><?= e((string) $m['name']) ?></strong>
            <span style="font-size:0.8rem;color:var(--rx-text-muted);margin-left:0.5rem;"><?= $mDaysOn ?> days on medication</span>
            <?php if ($mDefR === 0): ?>
              <p style="font-size:0.82rem;color:var(--rx-text-muted);margin-top:4px;font-style:italic;">
                Mood tracking started <?= e(date('F j', strtotime($minfo['start_date']))) ?> — check back after a few more days of logged doses.
              </p>
              <input type="hidden" name="mood_chart_days[<?= $mmId ?>]" value="0">
            <?php else: ?>
              <div style="margin-top:0.4rem;">
                <label style="font-size:0.88rem;">Chart window
                  <select name="mood_chart_days[<?= $mmId ?>]" style="margin-left:0.5rem;">
                    <?php if (in_array(7, $minfo['extra_opts'], true) || $mDefR === 7): ?>
                      <option value="7" <?= $mDefR === 7 ? 'selected' : '' ?>>7 days</option>
                    <?php endif; ?>
                    <?php if (in_array(30, $minfo['extra_opts'], true) || $mDefR === 30): ?>
                      <option value="30" <?= $mDefR === 30 ? 'selected' : '' ?>>30 days</option>
                    <?php endif; ?>
                    <?php if ($mDefR === 90 || $mDaysOn >= 90): ?>
                      <option value="90" <?= $mDefR === 90 ? 'selected' : '' ?>>90 days</option>
                    <?php endif; ?>
                  </select>
                </label>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </fieldset>
      <?php else: ?>
      <p style="font-size:0.85rem;color:var(--rx-text-muted);margin-bottom:1.25rem;font-style:italic;">
        No medications are currently tracking mood levels.
      </p>
      <?php endif; ?>

      <button type="submit" style="width:100%;" data-export-btn>
        <i class="fa-solid fa-file-pdf" aria-hidden="true"></i> Generate &amp; Download PDF
      </button>
      <div data-export-notice style="display:none;align-items:center;flex-wrap:wrap;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;color:#166534;gap:0.6rem;margin-top:0.75rem;padding:0.7rem 1rem;">
        <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
        Your PDF is downloading — check your Downloads folder.
        <a data-view-pdf-link href="#" hidden style="margin-left:auto;font-weight:600;color:#166534;text-decoration:underline;white-space:nowrap;">
          <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i> Open PDF
        </a>
      </div>
    </form>
  </section>
</main>
<?php include __DIR__ . '/../includes/bottom-nav.php'; ?>
</body>
</html>
<?php exit; ?>
<?php endif; ?>

  <?php if ($page === 'help'): ?>
<main class="app-shell app-shell--full">
  <section class="panel help-panel" style="margin:1.5rem auto;padding:1.5rem 1.75rem;">
    <div class="panel-heading"><h2>Help &amp; User Guide</h2></div>

    <nav class="help-toc" style="margin-bottom:1.5rem;line-height:2;">
      <strong>Jump to:</strong>
      <a href="#help-dashboard">Dashboard</a> &bull;
      <a href="#help-add-med">Adding Medications</a> &bull;
      <a href="#help-doses">Marking Doses</a> &bull;
      <a href="#help-inventory">Inventory &amp; Refills</a> &bull;
      <a href="#help-groups">Groups</a> &bull;
      <a href="#help-feedback">Pain Tracking</a> &bull;
      <a href="#help-history">History &amp; Calendar</a> &bull;
      <a href="#help-export">Export &amp; Reports</a> &bull;
      <a href="#help-family">Family Profiles</a> &bull;
      <a href="#help-profile">My Profile</a> &bull;
      <a href="#help-settings">Settings</a> &bull;
      <a href="#help-push">Notifications</a> &bull;
      <a href="#help-pwa">Install App</a> &bull;
      <a href="#help-troubleshoot">Troubleshooting</a>
    </nav>

    <h3 id="help-dashboard">Dashboard</h3>
    <p>The Dashboard is your home base. It shows your <strong>Next Dose</strong> hero card (with upcoming dose info and group medication details), today&rsquo;s full schedule with Take / Skip / Snooze action buttons, your adherence ring summary for the day, and a quick-actions sidebar.</p>

    <h3 id="help-add-med">Adding a Medication</h3>
    <p>Click <strong>Add medication</strong> on the Dashboard or Medications page. Fill in:</p>
    <ul>
      <li><strong>Name</strong> &mdash; start typing for autocomplete suggestions from DailyMed.</li>
      <li><strong>Type</strong> &mdash; Prescription (<em>Rx</em>), OTC, or Supplement. A color-coded badge appears next to the name throughout the app.</li>
      <li><strong>Dose amount &amp; unit</strong> &mdash; e.g. 500 mg or 10 mL.</li>
      <li><strong>Dose form</strong> (optional) &mdash; Tablet, Capsule, Liquid, Inhaler, etc. Affects the icon shown on the dashboard.</li>
      <li><strong>Schedule</strong> &mdash; Fixed times (e.g. <code>8:00 AM, 2:00 PM, 9:00 PM</code>) or Every X hours with a first-dose time. Mark <em>As needed (PRN)</em> to exclude from required dose counts.</li>
      <li><strong>Inventory</strong> (optional) &mdash; starting quantity, quantity per dose, and a low-supply alert threshold. The supply bar turns yellow below 50% and red below 25%.</li>
      <li><strong>Track dose feedback</strong> (optional) &mdash; prompts for a 1&ndash;10 pain/symptom rating and optional note after each dose.</li>
    </ul>
    <p>To <strong>edit</strong> a medication: Medications page &rarr; click the edit icon on the card. To <strong>deactivate</strong>: click Deactivate on the card; reactivate it from the <em>Inactive</em> tab.</p>

    <h3 id="help-doses">Marking Doses</h3>
    <p>Each scheduled dose on the Dashboard has three action buttons:</p>
    <ul>
      <li><strong>Take</strong> &mdash; marks the dose taken now. Opens a feedback prompt if dose feedback is enabled for that medication.</li>
      <li><strong>Skip</strong> &mdash; records an intentional skip with a confirmation prompt.</li>
      <li><strong>Snooze</strong> &mdash; delays the reminder by your chosen snooze duration (configured in Settings).</li>
    </ul>
    <p>Possible statuses: <em>Taken</em>, <em>Taken late</em> (logged after the grace period), <em>Skipped</em>, <em>Missed</em> (grace period expired with no action), <em>Snoozed until [time]</em>.</p>

    <h3 id="help-inventory">Inventory &amp; Refills</h3>
    <p>RxTracker deducts from your supply each time a dose is logged as taken. A days-remaining estimate appears on the medication card, and a refill alert is shown when supply falls below your set threshold.</p>
    <p>To <strong>log a refill</strong>: Medications &rarr; click <em>Log refill</em> on the card &rarr; enter the date, quantity added, and an optional note. View past refills with the <em>Refill history</em> button on the card.</p>

    <h3 id="help-groups">Medication Groups</h3>
    <p>Groups bundle two or more medications taken at the same time into a single scheduled alarm. Go to <strong>Medications &rarr; Groups tab</strong> to create a group (name + scheduled time) and add medications to it. Notes:</p>
    <ul>
      <li>A medication can only belong to one group at a time.</li>
      <li>You can set a <strong>group dose override</strong> — a different quantity-per-dose for a specific medication when taken as part of this group (e.g. 2 tablets in the group vs. the default 1).</li>
      <li>Each medication in a group retains its own inventory tracking and feedback settings.</li>
    </ul>

    <h3 id="help-feedback">Pain &amp; Feedback Tracking</h3>
    <p>Enable <em>Track dose feedback</em> in the medication form. After marking a dose taken, you&rsquo;ll be prompted to rate your pain or symptom level 1&ndash;10 and add an optional note. To review trends, click the <strong>Pain trend</strong> button on the medication card and select a window: Today, 7, 30, or 90 days. Pain charts are also included in the Doctor Visit Report PDF.</p>

    <h3 id="help-history">History &amp; Calendar</h3>
    <p>The <strong>Calendar</strong> page shows a month view with color-coded adherence markers for each day. Click any day to see that day&rsquo;s dose log. Navigate months with the left/right arrows. The <strong>Export</strong> page provides a filterable full dose history table.</p>

    <h3 id="help-export">Export &amp; Doctor Visit Report</h3>
    <p>The <strong>Export</strong> page has two main features:</p>
    <ul>
      <li><strong>Dose history table</strong> &mdash; filter by date range and medication, then use your browser&rsquo;s print dialog (<em>Print / Save as PDF</em>) to save or print it.</li>
      <li><strong>Doctor Visit Report PDF</strong> &mdash; a polished, multi-page PDF designed to share with your healthcare provider. Select a date range, optionally toggle per-medication pain charts, then click <em>Download Doctor Visit Report</em>. The PDF includes: adherence summary with rings, current medications list (with type badges), full dose history, pain level charts, side effects log, and a footer disclaimer. The filename reflects the date range selected (e.g. <code>doctor-visit-report-5-29-2026-thru-6-29-2026.pdf</code>).</li>
    </ul>

    <h3 id="help-family">Family Members &amp; Profiles</h3>
    <p>RxTracker supports multiple profiles so you can track medications for family members from one account.</p>
    <ul>
      <li><strong>Add a family member</strong>: Go to <strong>My Profile &rarr; Family Members</strong> section. Enter the name, relationship, birth year (optional), and choose an avatar color.</li>
      <li><strong>Switch profiles</strong>: Click the avatar button in the top-right navigation to open the profile switcher dropdown. Select a family member to view and manage their medications. A banner at the top of the app confirms whose profile you&rsquo;re viewing.</li>
      <li><strong>Switching back</strong>: Open the avatar dropdown and select your own name (shown at the top of the list).</li>
      <li><strong>Edit or remove a member</strong>: Go to My Profile &rarr; Family Members and use the edit/remove buttons on each member card.</li>
    </ul>

    <h3 id="help-profile">My Profile</h3>
    <p>Access My Profile via the avatar button in the top nav &rarr; <em>My Profile</em>. From here you can:</p>
    <ul>
      <li>Update your <strong>display name</strong>.</li>
      <li>Change your <strong>password</strong>.</li>
      <li>Manage <strong>family member profiles</strong> (add, edit, remove, set avatar colors).</li>
      <li>Export or delete your <strong>account data</strong>.</li>
      <li>View and revoke active <strong>remember-me sessions</strong>.</li>
    </ul>

    <h3 id="help-settings">Settings</h3>
    <ul>
      <li><strong>Grace period</strong> &mdash; how long (30 or 60 minutes) before a dose is auto-marked Missed if no action is taken.</li>
      <li><strong>Snooze duration</strong> &mdash; default snooze length: 5, 10, 15, or 30 minutes.</li>
      <li><strong>Sound &amp; Vibration</strong> &mdash; toggle in-app alarm sound and vibration for dose reminders.</li>
      <li><strong>Background Reminders</strong> &mdash; enables push notifications so you receive alerts even when the app is closed (see Notifications below).</li>
    </ul>

    <h3 id="help-push">Push Notifications</h3>
    <p>Go to <strong>Settings &rarr; Background Reminders</strong> and toggle it on. When your browser prompts for permission, click <em>Allow</em>. The push status checklist must show all items passing. Use <em>Send test notification</em> to verify delivery. Important notes:</p>
    <ul>
      <li>On <strong>iPhone</strong>, RxTracker must be installed to the home screen (as a PWA) before push notifications will work.</li>
      <li>Notifications require a server-side scheduled task (cron job) to dispatch them — confirm this is running with your hosting setup.</li>
    </ul>

    <h3 id="help-pwa">Installing as an App</h3>
    <ul>
      <li><strong>iPhone (Safari)</strong>: Tap the Share button &rarr; Add to Home Screen &rarr; Add.</li>
      <li><strong>Android (Chrome)</strong>: Tap the menu (&#8942;) &rarr; Add to Home Screen &rarr; Install.</li>
      <li><strong>Desktop (Chrome/Edge)</strong>: Click the install icon in the address bar.</li>
    </ul>
    <p>Once installed, the app runs in a standalone window without browser chrome and receives push notifications on supported platforms.</p>

    <h3 id="help-troubleshoot">Troubleshooting</h3>
    <ul>
      <li><strong>No push notifications</strong> &mdash; Check browser notification permission is set to <em>Allow</em>. On iPhone, the PWA must be installed to the home screen. Verify the server-side cron job is active.</li>
      <li><strong>Dose shows Missed despite taking it</strong> &mdash; The grace period expired before you logged it. Increase the grace period in Settings.</li>
      <li><strong>Supply count is wrong</strong> &mdash; Check that <em>Quantity per dose</em> is set correctly in the medication edit form, and that any group dose overrides are set as intended.</li>
      <li><strong>Autocomplete not working</strong> &mdash; Requires internet access to DailyMed. Type the medication name manually if offline.</li>
      <li><strong>App feels outdated after an update</strong> &mdash; Force-refresh: Ctrl+Shift+R (Windows/Linux) or Cmd+Shift+R (Mac). If that doesn&rsquo;t help, clear the site data in browser settings.</li>
      <li><strong>Profile switcher not showing family members</strong> &mdash; Add family members first via My Profile &rarr; Family Members.</li>
      <li><strong>Doctor Visit Report is blank or missing data</strong> &mdash; Ensure you have dose logs within the selected date range. Pain charts only appear for medications with <em>Track dose feedback</em> enabled.</li>
    </ul>

    <p style="margin-top:2rem;color:var(--color-text-muted,#64748b);font-size:.875rem;">
      Full documentation available in <a href="docs/user-guide.md" target="_blank" rel="noopener"><code>docs/user-guide.md</code></a>.
    </p>
  </section>
</main>
<?php include __DIR__ . '/../includes/bottom-nav.php'; ?>
</body>
</html>
<?php exit; ?>
<?php endif; ?>

  <?php if ($lowSupplyMeds !== []): ?>
  <div class="warning-banner" role="alert">
    <?php foreach ($lowSupplyMeds as $lowMed): ?>
      <?php
        $lowCurQty = (float) ($lowMed['current_quantity'] ?? $lowMed['pill_count'] ?? 0);
        $lowUnit   = (string) ($lowMed['inventory_unit'] ?? 'tablets');
        $lowCurDisplay = $lowCurQty == (int) $lowCurQty ? (string) (int) $lowCurQty : rtrim(number_format($lowCurQty, 3), '0');
      ?>
      <p><strong><?= e((string) $lowMed['name']) ?><?= formattedDose($lowMed) !== '' ? ' ' . e(formattedDose($lowMed)) : '' ?></strong> &mdash; only <?= e($lowCurDisplay) ?> <?= e($lowUnit) ?> left (refill alert at &le;<?= e((string) $lowMed['low_supply_threshold']) ?> <?= e($lowUnit) ?>)</p>
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

  <?php if (!in_array($page, ['medications', 'settings', 'calendar', 'export', 'help', 'pain-tracking', 'mood-wellbeing'], true)): ?>
  <section class="dashboard-grid" aria-label="Medication dashboard">
    <article class="panel dashboard-schedule-panel">
      <div class="panel-heading">
        <h2>Today schedule <span class="panel-heading-date"><?= date('D, M j') ?></span></h2>
        <a href="index.php?page=calendar" class="panel-heading-link"><i class="fa-regular fa-calendar" aria-hidden="true"></i> View calendar</a>
      </div>
      <div class="schedule-list">
        <?php foreach ($todaySchedule as $dose): ?>
          <div class="schedule-row">
            <div class="schedule-row-time">
              <i class="fa-regular fa-clock" aria-hidden="true"></i>
              <span><?= e(to12h((string) $dose['reminder_time'])) ?></span>
              <?php if ($dose['as_needed']): ?><span class="schedule-prn">(PRN)</span><?php endif; ?>
            </div>
            <div class="schedule-row-info">
              <?php $schMedTypeSlug = (string) ($dose['medication_type'] ?? 'prescription'); $schMedTypeLabels = ['prescription' => 'Rx', 'otc' => 'OTC', 'supplement' => 'Supplement']; ?>
              <span class="med-name-row"><strong><?= e((string) $dose['name']) ?></strong><span class="med-type-badge med-type-badge--<?= e($schMedTypeSlug) ?>"><?= e($schMedTypeLabels[$schMedTypeSlug] ?? 'Rx') ?></span></span>
              <?php if (formattedDose($dose) !== ''): ?><span class="dose-inline"><?= e(formattedDose($dose)) ?></span><?php endif; ?>
              <?php if ($dose['group_name'] !== null): ?>
                <span class="group-badge"><i class="fa-solid fa-layer-group" aria-hidden="true"></i><?= e((string) $dose['group_name']) ?></span>
              <?php endif; ?>
              <?php if ((string) ($dose['status'] ?? '') === 'taken'): ?>
                <?php $lateMin = minutesLate($dose, $graceMinutes); ?>
                <span class="<?= $lateMin !== null ? 'warn-pill' : 'done-pill' ?>">Taken<?= $lateMin !== null ? ' (' . formatLate($lateMin) . ')' : '' ?></span>
              <?php elseif ((string) ($dose['status'] ?? '') === 'skipped'): ?>
                <span class="warn-pill">Skipped</span>
              <?php endif; ?>
            </div>
            <div class="row-actions">
              <?php
                $isCompleted = in_array((string) ($dose['status'] ?? ''), ['taken', 'skipped'], true);
                $rawPostponedUntil = is_string($dose['postponed_until'] ?? null) && (string) $dose['postponed_until'] !== '' ? (string) $dose['postponed_until'] : null;
                $snoozeActive = $rawPostponedUntil !== null && new DateTimeImmutable($rawPostponedUntil) > new DateTimeImmutable('now');
              ?>
              <?php if ($snoozeActive): ?>
                <span class="done-pill">Snoozed until <?= e(to12h((new DateTimeImmutable($rawPostponedUntil))->format('H:i'))) ?></span>
              <?php endif; ?>
              <div class="schedule-actions-buttons">
                <form method="post" action="index.php"><?= csrf_field() ?><input type="hidden" name="action" value="mark_dose"><input type="hidden" name="medication_id" value="<?= e((string) $dose['medication_id']) ?>"><input type="hidden" name="scheduled_date" value="<?= e($today) ?>"><input type="hidden" name="scheduled_time" value="<?= e((string) $dose['reminder_time']) ?>:00"><input type="hidden" name="status" value="taken"><?php if ($dose['group_id'] !== null): ?><input type="hidden" name="group_id" value="<?= e((string) $dose['group_id']) ?>"><?php endif; ?><button type="submit" class="btn-take" data-take-dose data-medication-id="<?= e((string) $dose['medication_id']) ?>" data-medication-name="<?= e((string) $dose['name']) ?>" data-scheduled-date="<?= e($today) ?>" data-scheduled-time="<?= e((string) $dose['reminder_time']) ?>:00" data-track-dose-feedback="<?= (($dose['feedback_type'] ?? ($dose['track_dose_feedback'] ? 'pain' : 'none')) !== 'none') ? '1' : '0' ?>" data-feedback-type="<?= e((string) ($dose['feedback_type'] ?? ($dose['track_dose_feedback'] ? 'pain' : 'none'))) ?>" data-dose-status="<?= e((string) ($dose['status'] ?? '')) ?>" data-grace-minutes="<?= e((string) $graceMinutes) ?>" data-postponed-until="<?= $rawPostponedUntil !== null ? e($rawPostponedUntil) : '' ?>"<?= $isCompleted ? ' disabled' : '' ?>>Take</button></form>
                <form method="post" action="index.php" data-confirm="Confirm skipped dose?"><?= csrf_field() ?><input type="hidden" name="action" value="mark_dose"><input type="hidden" name="medication_id" value="<?= e((string) $dose['medication_id']) ?>"><input type="hidden" name="scheduled_date" value="<?= e($today) ?>"><input type="hidden" name="scheduled_time" value="<?= e((string) $dose['reminder_time']) ?>:00"><input type="hidden" name="status" value="skipped"><input type="hidden" name="note" value="Skipped dose"><button type="submit" class="secondary"<?= $isCompleted ? ' disabled' : '' ?>>Skipped</button></form>
                <?php if (!$isCompleted): ?>
                  <button type="button" class="secondary" data-open-postpone-modal data-medication-id="<?= e((string) $dose['medication_id']) ?>" data-scheduled-date="<?= e($today) ?>" data-scheduled-time="<?= e((string) $dose['reminder_time']) ?>:00"<?= $snoozeActive ? ' disabled' : '' ?>>Snooze</button>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="schedule-view-full">
        <a href="index.php?page=calendar" class="panel-link">View full schedule</a>
      </div>
    </article>

    <aside class="dashboard-sidebar">
      <div class="panel quick-actions-panel">
        <h2 class="sidebar-panel-heading">Quick actions</h2>
        <a href="index.php?page=medications&open=add" class="quick-action-row">
          <span class="quick-action-icon quick-action-icon--add"><i class="fa-solid fa-plus" aria-hidden="true"></i></span>
          <span class="quick-action-label">Add medication</span>
          <i class="fa-solid fa-chevron-right quick-action-chevron" aria-hidden="true"></i>
        </a>
        <a href="index.php?page=pain-tracking" class="quick-action-row">
          <span class="quick-action-icon quick-action-icon--log"><i class="fa-solid fa-chart-line" aria-hidden="true"></i></span>
          <span class="quick-action-label">Pain tracking</span>
          <i class="fa-solid fa-chevron-right quick-action-chevron" aria-hidden="true"></i>
        </a>
        <a href="index.php?page=mood-wellbeing" class="quick-action-row">
          <span class="quick-action-icon quick-action-icon--mood"><i class="fa-solid fa-face-smile" aria-hidden="true"></i></span>
          <span class="quick-action-label">Mood &amp; Wellbeing</span>
          <i class="fa-solid fa-chevron-right quick-action-chevron" aria-hidden="true"></i>
        </a>
        <a href="index.php?page=medications" class="quick-action-row">
          <span class="quick-action-icon quick-action-icon--manage"><i class="fa-solid fa-pills" aria-hidden="true"></i></span>
          <span class="quick-action-label">Manage medications</span>
          <i class="fa-solid fa-chevron-right quick-action-chevron" aria-hidden="true"></i>
        </a>
      </div>

      <div class="panel medications-overview-panel">
        <h2 class="sidebar-panel-heading"><i class="fa-regular fa-rectangle-list" aria-hidden="true"></i> Medications overview</h2>
        <div class="medications-overview-list">
          <div class="medications-overview-row">
            <span>Active medications</span>
            <span class="medications-overview-value"><?= e((string) count($medications ?? [])) ?></span>
          </div>
          <div class="medications-overview-row">
            <span>Today's doses</span>
            <span class="medications-overview-value"><?= e((string) count($requiredRows)) ?></span>
          </div>
          <div class="medications-overview-row">
            <span>Doses taken</span>
            <span class="medications-overview-value medications-overview-value--taken"><?= e((string) $takenTodayCount) ?></span>
          </div>
          <div class="medications-overview-row">
            <span>Doses missed</span>
            <span class="medications-overview-value medications-overview-value--missed"><?= e((string) $missedCount) ?></span>
          </div>
          <button type="button" class="medications-overview-row medications-overview-row--link" data-open-required-doses-modal>
            <span><i class="fa-solid fa-list-check" aria-hidden="true"></i> View required doses list</span>
            <i class="fa-solid fa-chevron-right medications-overview-row-chevron" aria-hidden="true"></i>
          </button>
        </div>
        <a href="index.php?page=medications" class="panel-link medications-overview-link">View all medications</a>
      </div>
    </aside>
  </section>

  <section class="panel history-panel" data-history-panel>
    <div class="panel-heading">
      <h2>Today's history <button type="button" class="history-sort-btn" data-history-sort aria-label="Sort: newest first" title="Sort: newest first"><i class="fa-solid fa-arrow-down-wide-short" aria-hidden="true"></i></button></h2>
      <a href="index.php?page=calendar" class="panel-heading-link">View all history</a>
    </div>
    <ol class="history-list" data-history-list>
      <?php if ($recentLogs === []): ?>
        <li class="history-empty">No doses logged today yet.</li>
      <?php endif; ?>
      <?php foreach ($recentLogs as $log): ?>
        <li data-sort-time="<?= e((string) $log['scheduled_for_date'] . ' ' . (string) $log['scheduled_time']) ?>">
          <span><span class="history-time"><?= e(to12h((string) $log['scheduled_time'])) ?></span></span>
          <div>
            <strong><?= e((string) $log['name']) ?></strong><?php if (formattedDose($log) !== ''): ?> <span class="dose-inline"><?= e(formattedDose($log)) ?></span><?php endif; ?>
            <p>
              <?php if ((string) $log['status'] === 'taken'): ?>
                <?php $lateMin = minutesLate($log, $graceMinutes); ?>
                <span class="<?= $lateMin !== null ? 'warn-pill' : 'done-pill' ?>">Taken<?= $lateMin !== null ? ' (' . formatLate($lateMin) . ')' : '' ?></span>
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
        <p class="refill-modal-subtitle"><span class="refill-med-name-pill" data-refill-med-name></span> <span class="refill-med-dose" data-refill-med-dose></span></p>
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

<div class="modal-overlay" data-instructions-modal>
  <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="instructions-modal-title">
    <div class="modal-header">
      <h2 id="instructions-modal-title" data-instructions-modal-name>Instructions</h2>
      <button type="button" class="icon-button" data-close-instructions-modal aria-label="Close instructions">&#10005;</button>
    </div>
    <div class="modal-scroll">
      <div class="instructions-content-wrap">
        <div class="instructions-content-box">
          <p data-instructions-modal-body></p>
          <textarea data-instructions-modal-edit rows="4" style="display:none"></textarea>
        </div>
        <button type="button" class="icon-button med-actions-trigger instructions-edit-btn" data-edit-instructions aria-label="Edit instructions" title="Edit instructions"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>
      </div>
    </div>
    <div class="refill-form-actions" data-instructions-modal-save-row style="display:none; padding: 0 1rem 1rem;">
      <button type="button" data-save-instructions>Save</button>
      <button type="button" class="secondary" data-cancel-instructions-edit>Cancel</button>
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

<div class="modal-overlay" data-slot-picker-modal>
  <div class="modal-dialog slot-picker-dialog">
    <div class="modal-header">
      <h2 class="modal-title" data-slot-picker-title>Log dose</h2>
      <button type="button" class="modal-close" data-close-slot-picker aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    </div>
    <div class="modal-body slot-picker-body">
      <p class="slot-picker-hint">Select which scheduled dose you are logging:</p>
      <div class="slot-picker-list" data-slot-picker-list></div>
      <div class="slot-late-question" data-slot-late-question hidden>
        <p>This dose time has already passed. When you actually took it:</p>
        <label class="slot-late-option"><input type="radio" name="slot_timing" value="on_time" checked> I took it on time &mdash; just logging it now</label>
        <label class="slot-late-option"><input type="radio" name="slot_timing" value="late"> I took it late (after the scheduled window)</label>
      </div>
      <div class="slot-free-time" data-slot-free-time hidden>
        <p style="margin-bottom:0.5rem;font-size:0.875rem;color:var(--rx-text-muted);">All scheduled times are logged. Log at a different time:</p>
        <input type="time" data-slot-free-time-input class="form-control" style="width:100%;">
      </div>
    </div>
    <div class="modal-footer slot-picker-footer">
      <button type="button" class="secondary" data-close-slot-picker>Cancel</button>
      <button type="button" data-slot-picker-confirm disabled>Log dose</button>
    </div>
  </div>
</div>

<div class="modal-overlay" data-missed-dose-modal>
  <div class="modal-dialog slot-picker-dialog">
    <div class="modal-header">
      <h2 class="modal-title" data-missed-dose-title>Log missed dose</h2>
      <button type="button" class="modal-close" data-close-missed-dose-modal aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    </div>
    <div class="modal-body slot-picker-body">
      <p class="slot-picker-hint">When did you take this dose?</p>
      <form method="post" action="index.php" data-missed-dose-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action"         value="mark_dose">
        <input type="hidden" name="status"         value="taken">
        <input type="hidden" name="json_response"  value="1">
        <input type="hidden" name="note"           data-missed-dose-note-hidden value="Marked taken (was missed)">
        <input type="hidden" name="pain_level"     data-missed-dose-pain-level  value="">
        <input type="hidden" name="mood_level"     data-missed-dose-mood-level  value="">
        <input type="hidden" name="medication_id"  data-missed-dose-med-id     value="">
        <input type="hidden" name="scheduled_date" data-missed-dose-date        value="">
        <input type="hidden" name="scheduled_time" data-missed-dose-sched-time  value="">
        <div class="form-row" style="margin-top:1rem;">
          <label for="missed-dose-actual-time" class="form-label">Time taken</label>
          <input type="time" id="missed-dose-actual-time" name="actual_taken_time"
                 data-missed-dose-actual-time class="form-control" style="margin-top:.375rem;width:100%;">
        </div>
        <div data-missed-dose-pain-section hidden style="margin-top:1.25rem;">
          <p class="feedback-pain-label">Pain level <span class="feedback-pain-hint">(1 = minimal &mdash; 10 = severe)</span></p>
          <div class="pain-level-selector" role="group" aria-label="Select pain level" style="margin-top:.4rem;">
            <?php for ($i = 1; $i <= 10; $i++): ?>
              <button type="button" class="missed-pain-btn" data-missed-pain="<?= $i ?>" aria-label="Pain level <?= $i ?>"><?= $i ?></button>
            <?php endfor; ?>
          </div>
        </div>
        <div data-missed-dose-mood-section hidden style="margin-top:1.25rem;">
          <p class="feedback-pain-label">Mood level <span class="feedback-pain-hint">(1 = very low &mdash; 10 = excellent)</span></p>
          <div class="pain-level-selector" role="group" aria-label="Select mood level" style="margin-top:.4rem;">
            <?php for ($i = 1; $i <= 10; $i++): ?>
              <button type="button" class="missed-mood-btn" data-missed-mood="<?= $i ?>" aria-label="Mood level <?= $i ?>"><?= $i ?></button>
            <?php endfor; ?>
          </div>
        </div>
        <div data-missed-dose-note-section hidden style="margin-top:.75rem;">
          <label style="display:block;">Notes <span class="field-optional">(optional)</span>
            <textarea data-missed-dose-note-text rows="2" maxlength="250"
                      placeholder="Any notes about this dose?"
                      style="margin-top:.375rem;width:100%;"></textarea>
          </label>
        </div>
      </form>
    </div>
    <div class="modal-footer slot-picker-footer">
      <button type="button" class="secondary" data-close-missed-dose-modal>Cancel</button>
      <button type="button" data-missed-dose-confirm>Log dose</button>
    </div>
  </div>
</div>

<div class="modal-overlay" data-log-past-dose-modal>
  <div class="modal-dialog slot-picker-dialog" role="dialog" aria-modal="true" aria-labelledby="log-past-dose-title">
    <div class="modal-header">
      <h2 class="modal-title" id="log-past-dose-title" data-log-past-dose-title>Log past dose</h2>
      <button type="button" class="modal-close" data-close-log-past-dose aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    </div>
    <div class="modal-body slot-picker-body">
      <p class="slot-picker-hint">Which day and dose was this?</p>
      <form method="post" action="index.php" data-log-past-dose-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action"         value="mark_dose">
        <input type="hidden" name="status"          value="taken">
        <input type="hidden" name="json_response"   value="1">
        <input type="hidden" name="pain_level"       data-log-past-dose-pain-level value="">
        <input type="hidden" name="mood_level"       data-log-past-dose-mood-level value="">
        <input type="hidden" name="medication_id"    data-log-past-dose-med-id     value="">

        <div class="form-row" style="margin-top:1rem;">
          <label for="log-past-dose-date" class="form-label">Date</label>
          <input type="date" id="log-past-dose-date" name="scheduled_date"
                 data-log-past-dose-date class="form-control" style="margin-top:.375rem;width:100%;">
        </div>

        <div class="form-row" data-log-past-dose-slot-section style="margin-top:.75rem;">
          <label class="form-label">Which dose?</label>
          <div class="slot-picker-list" data-log-past-dose-slot-list style="margin-top:.375rem;"></div>
        </div>

        <div class="form-row" data-log-past-dose-free-time-section hidden style="margin-top:.75rem;">
          <label for="log-past-dose-free-time" class="form-label">Time</label>
          <input type="time" id="log-past-dose-free-time" name="scheduled_time"
                 data-log-past-dose-free-time class="form-control" style="margin-top:.375rem;width:100%;">
        </div>

        <div class="form-row" style="margin-top:.75rem;">
          <label for="log-past-dose-actual-time" class="form-label">Time actually taken <span class="field-optional">(optional — leave blank to log as "just now")</span></label>
          <input type="time" id="log-past-dose-actual-time" name="actual_taken_time"
                 data-log-past-dose-actual-time class="form-control" style="margin-top:.375rem;width:100%;">
        </div>

        <div data-log-past-dose-pain-section hidden style="margin-top:1.25rem;">
          <p class="feedback-pain-label">Pain level <span class="feedback-pain-hint">(1 = minimal &mdash; 10 = severe)</span></p>
          <div class="pain-level-selector" role="group" aria-label="Select pain level" style="margin-top:.4rem;">
            <?php for ($i = 1; $i <= 10; $i++): ?>
              <button type="button" class="log-past-dose-pain-btn" data-log-past-dose-pain="<?= $i ?>" aria-label="Pain level <?= $i ?>"><?= $i ?></button>
            <?php endfor; ?>
          </div>
        </div>

        <div data-log-past-dose-mood-section hidden style="margin-top:1.25rem;">
          <p class="feedback-pain-label">Mood level <span class="feedback-pain-hint">(1 = very low &mdash; 10 = excellent)</span></p>
          <div class="pain-level-selector" role="group" aria-label="Select mood level" style="margin-top:.4rem;">
            <?php for ($i = 1; $i <= 10; $i++): ?>
              <button type="button" class="log-past-dose-mood-btn" data-log-past-dose-mood="<?= $i ?>" aria-label="Mood level <?= $i ?>"><?= $i ?></button>
            <?php endfor; ?>
          </div>
        </div>

        <label style="margin-top:.75rem;display:block;">Notes <span class="field-optional">(optional)</span>
          <textarea name="note" data-log-past-dose-note rows="2" maxlength="250"
                    placeholder="Any notes about this dose?"
                    style="margin-top:.375rem;width:100%;"></textarea>
        </label>
      </form>
    </div>
    <div class="modal-footer slot-picker-footer">
      <button type="button" class="secondary" data-close-log-past-dose>Cancel</button>
      <button type="button" data-log-past-dose-confirm disabled>Log dose</button>
    </div>
  </div>
</div>

<div class="modal-overlay" data-free-log-modal>
  <div class="modal-dialog slot-picker-dialog" role="dialog" aria-modal="true" aria-labelledby="free-log-title">
    <div class="modal-header">
      <h2 class="modal-title" id="free-log-title" data-free-log-title>Log dose</h2>
      <button type="button" class="modal-close" data-close-free-log aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    </div>
    <div class="modal-body slot-picker-body">
      <p class="slot-picker-hint">Select the time you are taking this dose:</p>
      <div class="form-row" style="margin-top:0.75rem;">
        <label for="free-log-time" class="form-label">Time taken</label>
        <input type="time" id="free-log-time" data-free-log-time class="form-control" style="margin-top:.375rem;width:100%;">
      </div>
    </div>
    <div class="modal-footer slot-picker-footer">
      <button type="button" class="secondary" data-close-free-log>Cancel</button>
      <button type="button" data-free-log-confirm>Log dose</button>
    </div>
  </div>
</div>

<div class="modal-overlay" data-required-doses-modal>
  <div class="modal-dialog required-doses-dialog" role="dialog" aria-modal="true" aria-labelledby="required-doses-modal-title">
    <div class="modal-header">
      <h2 id="required-doses-modal-title" class="modal-title"><i class="fa-solid fa-list-check" aria-hidden="true"></i> Required doses — today</h2>
      <button type="button" class="modal-close" data-close-required-doses-modal aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
    </div>
    <div class="modal-scroll">
      <?php if (empty($requiredByMed)): ?>
        <p class="empty-state-text">No required doses scheduled for today.</p>
      <?php else: ?>
        <ul class="required-doses-list">
          <?php foreach ($requiredByMed as $doses): ?>
            <li class="required-doses-med">
              <details class="required-doses-details">
                <summary class="required-doses-summary">
                  <span class="required-doses-med-name">
                    <strong><?= e((string) $doses[0]['name']) ?></strong>
                    <?php if (formattedDose($doses[0]) !== ''): ?><span class="dose-inline"><?= e(formattedDose($doses[0])) ?></span><?php endif; ?>
                  </span>
                  <span class="required-doses-view-label">View dose times <i class="fa-solid fa-chevron-down required-doses-chevron" aria-hidden="true"></i></span>
                </summary>
                <ul class="required-doses-times">
                  <?php foreach ($doses as $dose): ?>
                    <li class="required-doses-time-row">
                      <span class="required-doses-time"><?= e(to12h((string) $dose['reminder_time'])) ?></span>
                      <?php
                        $rdStatus    = (string) ($dose['status'] ?? '');
                        $rdPostponed = is_string($dose['postponed_until'] ?? null) && (string) $dose['postponed_until'] !== '';
                        if ($rdStatus === 'taken'):
                          $rdLate = minutesLate($dose, $graceMinutes);
                      ?>
                        <span class="<?= $rdLate !== null ? 'warn-pill' : 'done-pill' ?>">Taken<?= $rdLate !== null ? ' (' . formatLate($rdLate) . ')' : '' ?></span>
                      <?php elseif ($rdStatus === 'missed'): ?>
                        <span class="alert-pill">Missed</span>
                      <?php elseif ($rdStatus === 'skipped'): ?>
                        <span class="warn-pill">Skipped</span>
                      <?php elseif ($rdPostponed): ?>
                        <span class="done-pill">Snoozed until <?= e(to12h((new DateTimeImmutable((string) $dose['postponed_until']))->format('H:i'))) ?></span>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </details>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
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
      <button type="button" class="secondary alarm-individual-btn" data-alarm-individual hidden>Manage Each</button>
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
    <template id="alarm-item-actions-tpl">
      <div class="alarm-item-actions">
        <button type="button" class="alarm-item-take-btn" data-item-take>Take</button>
        <button type="button" class="secondary" data-item-skip>Skip</button>
        <button type="button" class="secondary" data-item-snooze>Snooze</button>
      </div>
    </template>
  </div>
</div>
<?php include __DIR__ . '/../includes/bottom-nav.php'; ?>
</body>
</html>
