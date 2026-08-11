<?php
// Shared partial: page <head>, top nav, user menu, profile-context banners, dashboard
// hero card, notice/error banners, and the shared add/edit-medication + pain/mood graph +
// dose-feedback + medication-detail + side-effect + image-lightbox modals.
// Expects the variables computed by includes/pages-data.php, plus $page, $error, $notice,
// $auth, $familyProfiles, $activeProfile, $activeProfileId, $showResumeBanner,
// $serverNowMs, $serverTzOffsetMinutes to already be in scope.
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
  <script src="assets/js/medication-wizard.js?v=<?= filemtime(__DIR__ . '/../assets/js/medication-wizard.js') ?>" defer></script>
  <script src="assets/js/side-effect-modal.js?v=<?= filemtime(__DIR__ . '/../assets/js/side-effect-modal.js') ?>" defer></script>
  <script src="assets/js/export-pdf-feedback.js?v=<?= filemtime(__DIR__ . '/../assets/js/export-pdf-feedback.js') ?>" defer></script>
  <script src="assets/js/timezone-detect.js?v=<?= filemtime(__DIR__ . '/../assets/js/timezone-detect.js') ?>" defer></script>
</head>
<body data-mood-chart-scheme="<?= e($moodChartScheme) ?>" data-server-today="<?= e($today) ?>" data-server-time="<?= e($currentTime) ?>" data-server-epoch-ms="<?= e((string) $serverNowMs) ?>" data-server-tz-offset-minutes="<?= e((string) $serverTzOffsetMinutes) ?>">
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
      <a href="index.php?page=pain-tracking"<?= $page === 'pain-tracking' ? ' class="is-active"' : '' ?>>Pain Tracking</a>
      <a href="index.php?page=mood-wellbeing"<?= $page === 'mood-wellbeing' ? ' class="is-active"' : '' ?>>Mood &amp; Wellbeing</a>
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
          <?= render_avatar((string) ($activeProfile['profile_picture'] ?? $currentUser['profile_picture'] ?? '') ?: null, $navAvatarLetter, $navAvatarColor, 'nav-user-avatar') ?>
        </button>
        <div class="nav-user-menu-panel" data-user-menu-panel hidden>
          <a href="index.php?page=profile" class="nav-user-menu-link nav-user-menu-link--top">
            <i class="fa-solid fa-circle-user" aria-hidden="true"></i>
            My Profile
          </a>
          <a href="index.php?page=family" class="nav-user-menu-link nav-user-menu-link--manage">
            <i class="fa-solid fa-users" aria-hidden="true"></i>
            Manage Family
          </a>
          <?php if (!empty($familyProfiles)): ?>
          <form method="post" action="index.php?page=profile" class="nav-user-menu-switcher-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="switch_family_profile">
            <input type="hidden" name="redirect_to" value="<?= e($_SERVER['REQUEST_URI'] ?? 'index.php') ?>">
            <button type="submit" name="profile_id" value="0"
                    class="profile-option nav-user-menu-owner-option<?= $activeProfileId === null ? ' is-active' : '' ?>">
              <?= render_avatar((string) ($currentUser['profile_picture'] ?? '') ?: null, mb_strtoupper(mb_substr((string) ($currentUser['display_name'] ?? 'U'), 0, 1)), '#6366f1', 'profile-option-avatar') ?>
              <?= e((string) ($currentUser['display_name'] ?? 'Me')) ?>
            </button>
            <div class="nav-user-menu-section-label">Family Members</div>
            <?php foreach ($familyProfiles as $fp): ?>
            <button type="submit" name="profile_id" value="<?= (int) $fp['id'] ?>"
                    class="profile-option<?= $activeProfileId === (int) $fp['id'] ? ' is-active' : '' ?>">
              <?= render_avatar((string) ($fp['profile_picture'] ?? '') ?: null, mb_strtoupper(mb_substr((string) $fp['display_name'], 0, 1)), (string) ($fp['avatar_color'] ?? '#6366f1'), 'profile-option-avatar') ?>
              <?= e((string) $fp['display_name']) ?>
              <?php if ($fp['relationship']): ?>
                <span class="profile-option-rel"><?= e((string) $fp['relationship']) ?></span>
              <?php endif; ?>
            </button>
            <?php endforeach; ?>
          </form>
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
    <?= render_avatar((string) ($activeProfile['profile_picture'] ?? '') ?: null, mb_strtoupper(mb_substr((string) $activeProfile['display_name'], 0, 1)), (string) ($activeProfile['avatar_color'] ?? '#6366f1'), 'profile-context-avatar') ?>
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

  <?php if (!empty($showResumeBanner)): ?>
  <div class="notice notice--info" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.75rem 1.25rem;margin:.75rem 1rem 0;">
    <span><strong>No medications added yet.</strong> Complete setup to start tracking your doses.</span>
    <a href="index.php?page=onboarding" class="btn btn-sm btn-primary" style="white-space:nowrap">Resume setup</a>
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

  <div class="modal-overlay<?= ($editing || $draftMedication) ? ' is-open' : '' ?>" data-medication-modal>
    <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="medication-modal-title">
      <div class="modal-header">
        <h2 id="medication-modal-title"><?= $editing ? 'Edit medication' : 'Add medication' ?></h2>
        <button type="button" class="modal-close-btn" data-close-medication-modal aria-label="Close modal">
          <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>
      </div>
      <div class="modal-scroll">
      <?php if ($editing): ?>
      <form class="medication-form" method="post" action="index.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_medication">
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
          <label>Start date <span class="field-optional">(defaults to today — set a future date if you haven't started taking this yet, so we won't log missed doses before then)</span>
            <input type="date" name="start_date" value="<?= e((string) ($editing['start_date'] ?? '')) ?>">
          </label>
          <label>Dose amount
            <input type="number" step="0.001" min="0" name="dose_amount" data-dailymed-dose-amount data-initial-dose-amount="<?= e($editing && ($editing['dose_amount'] ?? '') !== '' ? (string)(float)$editing['dose_amount'] : '') ?>" value="<?= e($editing && ($editing['dose_amount'] ?? '') !== '' ? (string)(float)$editing['dose_amount'] : '') ?>">
          </label>
          <label>Dose unit
            <select name="dose_unit" data-dailymed-dose-unit data-initial-dose-unit="<?= e((string) ($editing['dose_unit'] ?? 'mg')) ?>">
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
              <input type="number" name="dose_qtys[]" min="0.001" step="any" placeholder="Qty (default)" class="dose-qty-field">
              <button type="button" class="btn-icon remove-dose-time" aria-label="Remove time">−</button>
            </div>
          <?php else: ?>
            <?php foreach ($editingTimes as $t): ?>
            <div class="dose-time-row">
              <input type="text" name="dose_times[]" placeholder="8:00 AM" class="dose-time-field" autocomplete="off" value="<?= e(to12h($t)) ?>">
              <input type="number" name="dose_qtys[]" min="0.001" step="any" placeholder="Qty (default)" class="dose-qty-field" value="<?= e((string) ($editingTimeDoses[$t] ?? '')) ?>">
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
              <input type="number" step="0.001" min="0" name="starting_quantity" value="<?= e((string)(float)($editing['starting_quantity'] ?? $editing['starting_pill_count'] ?? 0)) ?>">
              <span data-inv-unit-label><?= e((string) ($editing['inventory_unit'] ?? 'tablets')) ?></span>
            </span>
          </label>

          <label data-inv-liquid-label style="display:none">Bottle amount
            <span class="input-with-unit">
              <?php
              $storedMl = (float) ($editing['starting_quantity'] ?? 0);
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
        <div class="modal-footer">
          <button type="submit">Save changes</button>
        </div>
      </form>
      <?php else: ?>
      <?php
        $wz = $draftMedication ?? [];
        $wzGroupId = (int) ($wz['group_id'] ?? 0);
        $wzTimes = $wz['dose_times'] ?? [];
        $wzQtys  = $wz['dose_qtys'] ?? [];
        $wzInitialStep = max(1, min(4, (int) ($wz['current_step'] ?? 1)));
        $wzFurthestStep = max($wzInitialStep, min(4, (int) ($wz['furthest_step'] ?? 1)));
      ?>
      <div class="med-wizard-progress" data-med-wizard-progress data-med-wizard-furthest="<?= e((string) $wzFurthestStep) ?>">
        <button type="button" class="med-wizard-seg" data-med-wizard-step-nav="1">
          <span class="med-wizard-seg-fill"></span>
          <span class="med-wizard-seg-label">Medication Info</span>
        </button>
        <button type="button" class="med-wizard-seg" data-med-wizard-step-nav="2">
          <span class="med-wizard-seg-fill"></span>
          <span class="med-wizard-seg-label">Inventory</span>
        </button>
        <button type="button" class="med-wizard-seg" data-med-wizard-step-nav="3">
          <span class="med-wizard-seg-fill"></span>
          <span class="med-wizard-seg-label">Schedule</span>
        </button>
        <button type="button" class="med-wizard-seg" data-med-wizard-step-nav="4">
          <span class="med-wizard-seg-fill"></span>
          <span class="med-wizard-seg-label">Feedback</span>
        </button>
      </div>
      <form class="medication-form med-wizard-form" method="post" action="index.php" data-med-wizard-initial-step="<?= e((string) $wzInitialStep) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_medication">
        <input type="hidden" name="medication_id" value="0">
        <input type="hidden" name="set_id" data-set-id-input value="">
        <input type="hidden" name="redirect_page" value="<?= e($page) ?>">
        <input type="hidden" name="draft_id" data-med-wizard-draft-id value="<?= e((string) ($wz['draft_id'] ?? '')) ?>">

        <div class="med-wizard-step" data-med-wizard-step="1">
          <label class="autocomplete-wrap">Name
            <input name="name" required autocomplete="off" data-med-name-input value="<?= e((string) ($wz['name'] ?? '')) ?>">
            <ul class="autocomplete-dropdown" data-autocomplete-dropdown hidden></ul>
          </label>

          <fieldset class="form-section">
            <legend>Dose info</legend>
            <label>Dose amount
              <input type="number" step="0.001" min="0" name="dose_amount" data-dailymed-dose-amount value="<?= e((string) ($wz['dose_amount'] ?? '')) ?>">
            </label>
            <label>Dose unit
              <select name="dose_unit" data-dailymed-dose-unit>
                <?php
                $wzDoseUnits = ['mg', 'mcg', 'g', 'mL', 'tsp', 'tbsp', 'oz', 'IU', 'units', 'drops', 'puffs', 'patches'];
                $wzSelectedDoseUnit = (string) ($wz['dose_unit'] ?? 'mg');
                foreach ($wzDoseUnits as $u): ?>
                <option value="<?= e($u) ?>" <?= $wzSelectedDoseUnit === $u ? 'selected' : '' ?>><?= e($u) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Dose form <span class="field-optional">(optional)</span>
              <select name="dose_form" data-dailymed-dose-form>
                <?php
                $wzDoseForms = ['', 'tablet', 'capsule', 'liquid', 'inhaler', 'injection', 'patch', 'drops', 'other'];
                $wzDoseFormLabels = ['' => '-- select --', 'tablet' => 'Tablet', 'capsule' => 'Capsule', 'liquid' => 'Liquid', 'inhaler' => 'Inhaler', 'injection' => 'Injection', 'patch' => 'Patch', 'drops' => 'Drops', 'other' => 'Other'];
                $wzSelectedDoseForm = (string) ($wz['dose_form'] ?? '');
                foreach ($wzDoseForms as $f): ?>
                <option value="<?= e($f) ?>" <?= $wzSelectedDoseForm === $f ? 'selected' : '' ?>><?= e($wzDoseFormLabels[$f]) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Medication type
              <select name="medication_type">
                <option value="prescription" <?= (($wz['medication_type'] ?? 'prescription') === 'prescription') ? 'selected' : '' ?>>Prescription</option>
                <option value="otc"          <?= (($wz['medication_type'] ?? '') === 'otc')          ? 'selected' : '' ?>>OTC Medication</option>
                <option value="supplement"   <?= (($wz['medication_type'] ?? '') === 'supplement')   ? 'selected' : '' ?>>Vitamin / Supplement</option>
              </select>
            </label>
            <label>Start date <span class="field-optional">(defaults to today — set a future date if you haven't started taking this yet, so we won't log missed doses before then)</span>
              <input type="date" name="start_date" value="<?= e((string) ($wz['start_date'] ?? '')) ?>">
            </label>
          </fieldset>

          <?php $wzHasEndDate = trim((string) ($wz['end_date'] ?? '')) !== ''; ?>
          <button type="button" class="btn-text" data-add-end-date<?= $wzHasEndDate ? ' hidden' : '' ?>>+ Add End Date</button>
          <label data-end-date-field<?= $wzHasEndDate ? '' : ' hidden' ?>>End date
            <input type="date" name="end_date" value="<?= e((string) ($wz['end_date'] ?? '')) ?>">
          </label>

          <?php $wzHasNotes = trim((string) ($wz['instructions'] ?? '')) !== ''; ?>
          <button type="button" class="btn-text" data-add-notes<?= $wzHasNotes ? ' hidden' : '' ?>>+ Add Notes</button>
          <label data-notes-field<?= $wzHasNotes ? '' : ' hidden' ?>>Instructions and Notes
            <textarea name="instructions" rows="3"><?= e((string) ($wz['instructions'] ?? '')) ?></textarea>
          </label>
        </div>

        <div class="med-wizard-step" data-med-wizard-step="2" hidden>
          <fieldset class="form-section" data-inventory-section>
            <legend>Inventory</legend>
            <label data-inv-qty-label>Starting quantity
              <span class="input-with-unit">
                <input type="number" step="0.001" min="0" name="starting_quantity" value="<?= e((string) ($wz['starting_quantity'] ?? '0')) ?>">
                <span data-inv-unit-label>tablets</span>
              </span>
            </label>

            <label data-inv-liquid-label style="display:none">Bottle amount
              <span class="input-with-unit">
                <input type="number" step="0.001" min="0" name="bottle_amount" data-bottle-amount-input value="<?= e((string) ($wz['bottle_amount'] ?? '')) ?>">
                <?php $wzBottleUnit = (string) ($wz['bottle_unit'] ?? 'mL'); ?>
                <select name="bottle_unit" data-bottle-unit-select>
                  <option value="mL" <?= $wzBottleUnit === 'mL' ? 'selected' : '' ?>>mL</option>
                  <option value="oz" <?= $wzBottleUnit === 'oz' ? 'selected' : '' ?>>oz</option>
                </select>
              </span>
            </label>

            <label>Dose reduces inventory by
              <span class="input-with-unit">
                <input type="number" step="0.001" min="0.001" name="quantity_per_dose" value="<?= e((string) ($wz['quantity_per_dose'] ?? '1')) ?>">
                <span data-inv-unit-label>tablets</span>
              </span>
            </label>

            <label>Low supply alert at
              <span class="input-with-unit">
                <input type="number" step="0.001" min="0" name="low_supply_threshold" value="<?= e((string) ($wz['low_supply_threshold'] ?? '0')) ?>">
                <span data-inv-unit-label>tablets</span>
              </span>
            </label>
          </fieldset>
        </div>

        <div class="med-wizard-step" data-med-wizard-step="3" hidden>
          <label>Schedule type
            <select name="schedule_mode">
              <option value="fixed_times" <?= (($wz['schedule_mode'] ?? '') === 'fixed_times') ? 'selected' : '' ?>>Fixed times</option>
              <option value="interval" <?= (($wz['schedule_mode'] ?? '') === 'interval') ? 'selected' : '' ?>>Every X hours</option>
            </select>
          </label>
          <div data-dose-times-section>
            <div class="dose-times-label">Dose times <span class="field-optional">(one per row)</span></div>
            <div data-dose-time-rows>
            <?php if ($wzTimes === []): ?>
              <div class="dose-time-row">
                <input type="text" name="dose_times[]" placeholder="8:00 AM" class="dose-time-field" autocomplete="off">
                <input type="number" name="dose_qtys[]" min="0.001" step="any" placeholder="Qty (default)" class="dose-qty-field">
                <button type="button" class="btn-icon remove-dose-time" aria-label="Remove time">−</button>
              </div>
            <?php else: ?>
              <?php foreach ($wzTimes as $wzi => $wzt): ?>
              <div class="dose-time-row">
                <input type="text" name="dose_times[]" placeholder="8:00 AM" class="dose-time-field" autocomplete="off" value="<?= e((string) $wzt) ?>">
                <input type="number" name="dose_qtys[]" min="0.001" step="any" placeholder="Qty (default)" class="dose-qty-field" value="<?= e((string) ($wzQtys[$wzi] ?? '')) ?>">
                <button type="button" class="btn-icon remove-dose-time" aria-label="Remove time">−</button>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>
            </div>
            <button type="button" class="btn-text" data-add-dose-time>+ Add time</button>
          </div>
          <label>Interval hours
            <input type="number" min="1" max="24" name="interval_hours" value="<?= e((string) ($wz['interval_hours'] ?? '')) ?>">
          </label>
          <label>First dose time
            <input name="first_dose_time" placeholder="8:00 AM" value="<?= e((string) ($wz['first_dose_time'] ?? '')) ?>">
          </label>
          <label>As needed (PRN)
            <select name="as_needed">
              <option value="0" <?= ((int) ($wz['as_needed'] ?? 0) === 0) ? 'selected' : '' ?>>No</option>
              <option value="1" <?= ((int) ($wz['as_needed'] ?? 0) === 1) ? 'selected' : '' ?>>Yes</option>
            </select>
            <small class="field-hint">If Yes, excluded from the dashboard's required dose count.</small>
          </label>
          <label>Medication group <span class="field-optional">(optional)</span>
            <select name="group_id">
              <option value="0"<?= $wzGroupId === 0 ? ' selected' : '' ?>>No group (individual)</option>
              <?php foreach ($groups as $grp): ?>
                <option value="<?= e((string) $grp['id']) ?>"<?= $wzGroupId === (int) $grp['id'] ? ' selected' : '' ?>><?= e($grp['name']) ?> &mdash; <?= e(to12h($grp['scheduled_time'])) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>

        <div class="med-wizard-step" data-med-wizard-step="4" hidden>
          <?php $wzFeedbackType = (string) ($wz['feedback_type'] ?? 'none'); ?>
          <label>Track dose feedback
            <select name="feedback_type">
              <option value="none" <?= $wzFeedbackType === 'none' ? 'selected' : '' ?>>No tracking</option>
              <option value="mood" <?= $wzFeedbackType === 'mood' ? 'selected' : '' ?>>Mood level</option>
              <option value="pain" <?= $wzFeedbackType === 'pain' ? 'selected' : '' ?>>Pain level</option>
              <option value="both" <?= $wzFeedbackType === 'both' ? 'selected' : '' ?>>Both pain and mood</option>
            </select>
          </label>
        </div>

        <div class="modal-footer med-wizard-footer">
          <button type="button" class="button-link button-link--cancel" data-med-wizard-cancel>Cancel</button>
          <button type="button" class="secondary" data-med-wizard-back hidden>Back</button>
          <button type="button" class="secondary" data-med-wizard-save-draft>Save draft</button>
          <button type="button" data-med-wizard-next>Next</button>
          <button type="submit" data-med-wizard-submit hidden>Save Medication</button>
        </div>
      </form>
      <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Exit-without-saving confirmation for the Add medication wizard -->
  <div class="modal-overlay" data-med-wizard-exit-modal>
    <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="med-wizard-exit-title">
      <div class="modal-header">
        <h2 id="med-wizard-exit-title">Discard this medication?</h2>
      </div>
      <div class="modal-scroll">
        <p>Your progress won&rsquo;t be saved. Use &ldquo;Save draft&rdquo; first if you want to come back to it later.</p>
        <div class="modal-footer">
          <button type="button" class="danger" data-med-wizard-exit-confirm>Discard and exit</button>
          <button type="button" class="button-link button-link--cancel" data-med-wizard-exit-cancel>Keep editing</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal-overlay" data-pain-graph-modal>
    <div class="modal-dialog pain-graph-dialog" role="dialog" aria-modal="true" aria-labelledby="pain-graph-title">
      <div class="modal-header">
        <h2 id="pain-graph-title" data-pain-graph-title>Pain Level Trend</h2>
        <button type="button" class="modal-close-btn" data-close-pain-graph aria-label="Close pain graph">
          <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>
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
        <p class="mood-graph-day-banner" data-pain-graph-day-banner hidden>
          <span data-pain-graph-day-label></span>
          <button type="button" class="mood-graph-day-back" data-pain-graph-day-back>&larr; Back to trend</button>
        </p>
        <div class="pain-graph-body" data-pain-graph-body></div>
        <p class="pain-graph-empty" data-pain-graph-empty hidden>No pain level data recorded for this period.</p>
        <p class="graph-hint">Tip: hover over a point to see each pain score logged that day. On a multi-day view, click a point to see that day&rsquo;s pain levels throughout the day.</p>
      </div>
    </div>
  </div>

  <div class="modal-overlay" data-mood-graph-modal>
    <div class="modal-dialog pain-graph-dialog" role="dialog" aria-modal="true" aria-labelledby="mood-graph-title">
      <div class="modal-header">
        <h2 id="mood-graph-title" data-mood-graph-title>Mood Trend</h2>
        <button type="button" class="modal-close-btn" data-close-mood-graph aria-label="Close mood graph">
          <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>
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
        <p class="mood-graph-day-banner" data-mood-graph-day-banner hidden>
          <span data-mood-graph-day-label></span>
          <button type="button" class="mood-graph-day-back" data-mood-graph-day-back>&larr; Back to trend</button>
        </p>
        <div class="pain-graph-body" data-mood-graph-body></div>
        <p class="pain-graph-empty" data-mood-graph-empty hidden>No mood data recorded for this period.</p>
        <p class="graph-hint">Tip: hover over a point to see each mood score logged that day. On a multi-day view, click a point to see that day&rsquo;s mood levels throughout the day.</p>
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
        <button type="button" class="modal-close-btn" data-close-feedback-modal aria-label="Close feedback modal">
          <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>
      </div>
      <div class="modal-scroll">
      <form method="post" action="index.php" class="stacked-form" data-feedback-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="mark_dose">
        <input type="hidden" name="status" value="taken">
        <input type="hidden" name="medication_id" data-feedback-medication-id value="">
        <input type="hidden" name="scheduled_date" data-feedback-scheduled-date value="">
        <input type="hidden" name="scheduled_time" data-feedback-scheduled-time value="">
        <input type="hidden" name="actual_taken_time" data-feedback-actual-time value="">
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

        <div class="feedback-actions modal-footer">
          <button type="submit">Log dose</button>
          <button type="button" class="secondary" data-skip-feedback>Take without comment</button>
        </div>
      </form>
      </div>
    </div>
  </div>

  <!-- Zero pill count interstitial -->
  <div class="modal-overlay" data-zero-pill-modal>
    <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="zero-pill-modal-title">
      <div class="modal-header">
        <h2 id="zero-pill-modal-title">Out of refills</h2>
        <button type="button" class="modal-close-btn" data-close-zero-pill-modal aria-label="Close">
          <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>
      </div>
      <div class="modal-scroll">
        <p data-zero-pill-message></p>
        <div class="zero-pill-adjust">
          <p class="pill-meta">Adjust count</p>
          <div class="zero-pill-stepper">
            <button type="button" class="zero-pill-step-btn" data-zero-pill-step="-1" aria-label="Decrease by 1">&minus;</button>
            <input type="number" step="1" value="0" data-zero-pill-delta aria-label="Amount to add or remove">
            <button type="button" class="zero-pill-step-btn" data-zero-pill-step="1" aria-label="Increase by 1">+</button>
            <button type="button" class="secondary" data-zero-pill-apply>Apply</button>
          </div>
        </div>
        <div class="zero-pill-actions modal-footer">
          <button type="button" data-zero-pill-refill>Log refill</button>
          <button type="button" class="button-link button-link--cancel" data-zero-pill-not-now>Not now</button>
        </div>
        <div class="zero-pill-cancel-row">
          <button type="button" class="secondary zero-pill-cancel-btn" data-zero-pill-cancel-dose>Cancel &mdash; don&rsquo;t log this dose</button>
          <p class="zero-pill-cancel-hint">This won&rsquo;t be recorded as taken and your pill count won&rsquo;t change.</p>
        </div>
      </div>
    </div>
  </div>

  <!-- Medication detail modal -->
  <div class="modal-overlay" data-med-detail-modal>
    <div class="modal-dialog med-detail-dialog" role="dialog" aria-modal="true" aria-labelledby="med-detail-title">
      <div class="modal-header">
        <h2 id="med-detail-title" data-med-detail-title></h2>
        <button type="button" class="modal-close-btn" data-close-med-detail aria-label="Close">
          <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>
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
        <button type="button" class="modal-close-btn" data-close-se-modal aria-label="Close">
          <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>
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
        <div class="modal-footer">
          <button type="submit">Log side effect</button>
          <button type="button" class="button-link button-link--cancel" data-close-se-modal>Cancel</button>
        </div>
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

