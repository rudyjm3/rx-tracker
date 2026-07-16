<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/OnboardingService.php';
require_once __DIR__ . '/../includes/InventoryEstimator.php';

/** @var MedicationRepository $repository */
/** @var AuthService $auth */

$service  = new OnboardingService($repository);
$progress = $service->getOrCreateProgress();

// If already completed, go to dashboard
if ((string) $progress['status'] === 'completed' && $repository->activeMedicationCount() > 0) {
    header('Location: index.php');
    exit;
}

$drafts     = $repository->draftMedications();
$draftCount = count($drafts);

// Compute past slots today for reconcile step
$now         = new DateTimeImmutable('now');
$todayDate   = $now->format('Y-m-d');
$currentHhmm = $now->format('H:i');
$pastSlots   = [];

foreach ($drafts as $med) {
    if ((int) ($med['as_needed'] ?? 0) || !(int) ($med['adherence_enabled'] ?? 1)) {
        continue;
    }
    $times = $med['times'] ?? [];
    foreach ($times as $t) {
        $slotHhmm = substr($t, 0, 5);
        if ($slotHhmm < $currentHhmm) {
            $pastSlots[] = [
                'medication_id'   => $med['id'],
                'name'            => $med['name'],
                'dose'            => formattedDose($med),
                'time'            => $t,
                'time_display'    => to12h($t),
            ];
        }
    }
}

// Sort past slots by time
usort($pastSlots, static fn($a, $b) => strcmp((string) $a['time'], (string) $b['time']));

$hasInventoryEnabled = false;
foreach ($drafts as $med) {
    if ((int) ($med['inventory_enabled'] ?? 0)) {
        $hasInventoryEnabled = true;
        break;
    }
}

$currentStep = (string) ($progress['current_step'] ?? 'medications');
$stepIndex   = match($currentStep) {
    'medications' => 1,
    'tracking'    => 2,
    'schedule'    => 3,
    'inventory'   => 4,
    'reconcile'   => 5,
    'activate'    => 6,
    default       => 1,
};

// Step labels
$steps = [
    1 => 'Medications',
    2 => 'Tracking',
    3 => 'Schedule',
    4 => 'Inventory',
    5 => 'Today',
    6 => 'Activate',
];

// Shared dose unit options
$doseUnits = ['mg', 'mcg', 'g', 'mL', 'tsp', 'tbsp', 'oz', 'IU', 'units', 'drops', 'puffs', 'patches', '%'];
$doseForms = ['tablet', 'capsule', 'liquid', 'inhaler', 'injection', 'patch', 'drops', 'other'];

$csrfToken = csrf_token();

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#0754A8">
  <meta name="csrf-token" content="<?= e($csrfToken) ?>">
  <title>Setup — RxTracker</title>
  <link rel="stylesheet" href="assets/css/styles.css?v=<?= filemtime(__DIR__ . '/../assets/css/styles.css') ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous">
  <link rel="icon" type="image/x-icon" href="assets/icons/favicon.ico">
  <link rel="icon" type="image/png" sizes="192x192" href="assets/icons/icon-192.png">
  <link rel="apple-touch-icon" href="assets/icons/icon-192.png">
  <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>" defer></script>
</head>
<body class="onboarding-body">

<div class="onboarding-shell">

  <!-- Header -->
  <div class="onboarding-header">
    <div class="onboarding-brand">
      <img src="assets/icons/icon-192.png" alt="" class="onboarding-logo" width="36" height="36">
      <span>RxTracker</span>
    </div>
    <button type="button" class="onboarding-skip-link" id="ob-skip-btn">Skip setup &rarr;</button>
  </div>

  <!-- Progress indicator -->
  <div class="onboarding-progress" aria-label="Setup progress">
    <?php foreach ($steps as $num => $label): ?>
    <div class="onboarding-progress-step<?= $num === $stepIndex ? ' is-active' : ($num < $stepIndex ? ' is-done' : '') ?>"
         data-step="<?= $num ?>" role="button" tabindex="0">
      <div class="onboarding-progress-dot">
        <?php if ($num < $stepIndex): ?>
          <i class="fa-solid fa-check" aria-hidden="true"></i>
        <?php else: ?>
          <?= $num ?>
        <?php endif; ?>
      </div>
      <span class="onboarding-progress-label"><?= e($label) ?></span>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Wizard content -->
  <div class="onboarding-content">

    <!-- ── Step 1: Medications ─────────────────────────────────────────── -->
    <div class="onboarding-step" data-onboarding-step="1" <?= $stepIndex !== 1 ? 'hidden' : '' ?>>
      <h1 class="onboarding-step-title">Your medications</h1>
      <p class="onboarding-step-desc">Add each medication you take. Don't worry about schedules or inventory yet — just the basics.</p>

      <!-- Add medication form -->
      <div class="ob-med-form-card" id="ob-add-form">
        <h2 class="ob-form-title"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add medication</h2>
        <div class="ob-form-row">
          <div class="ob-field ob-field--wide" style="position:relative">
            <label for="ob-med-name">Medication name <span aria-hidden="true">*</span></label>
            <input type="text" id="ob-med-name" name="name" autocomplete="off"
                   placeholder="e.g. Lisinopril, Vitamin D"
                   data-ob-name-input data-med-name-input>
            <input type="hidden" id="ob-set-id" data-set-id-input data-ob-set-id>
            <ul class="autocomplete-dropdown" data-autocomplete-dropdown hidden aria-label="Drug suggestions"></ul>
          </div>
        </div>
        <div class="ob-form-row ob-form-row--3col">
          <div class="ob-field">
            <label for="ob-dose-amount">Strength</label>
            <input type="number" id="ob-dose-amount" name="dose_amount" step="any" min="0" placeholder="e.g. 10"
                   data-dailymed-dose-amount>
          </div>
          <div class="ob-field">
            <label for="ob-dose-unit">Unit</label>
            <select id="ob-dose-unit" name="dose_unit" data-dailymed-dose-unit>
              <option value="">— unit —</option>
              <?php foreach ($doseUnits as $u): ?>
              <option value="<?= e($u) ?>"><?= e($u) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="ob-field">
            <label for="ob-dose-form">Form</label>
            <select id="ob-dose-form" name="dose_form" data-ob-dose-form>
              <option value="">— form —</option>
              <?php foreach ($doseForms as $f): ?>
              <option value="<?= e($f) ?>"><?= ucfirst(e($f)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="ob-form-row ob-form-row--2col">
          <div class="ob-field">
            <label for="ob-med-type">Type</label>
            <select id="ob-med-type" name="medication_type">
              <option value="prescription">Prescription (Rx)</option>
              <option value="otc">Over-the-counter</option>
              <option value="supplement">Supplement</option>
            </select>
          </div>
          <div class="ob-field ob-field--checkbox-row">
            <label class="ob-checkbox-label">
              <input type="checkbox" id="ob-as-needed" name="as_needed" value="1">
              As needed (PRN)
            </label>
            <p class="ob-field-hint">Take only when required, no fixed schedule</p>
          </div>
        </div>
        <div class="ob-form-actions">
          <button type="button" class="btn btn-primary" data-ob-add-medication>
            <i class="fa-solid fa-plus" aria-hidden="true"></i> Add to list
          </button>
          <button type="button" class="btn btn-ghost" data-ob-cancel-edit hidden>Cancel</button>
        </div>
        <div class="ob-form-error" data-ob-form-error hidden></div>
      </div>

      <!-- Draft medications list -->
      <div class="ob-med-list" id="ob-med-list">
        <?php if ($draftCount === 0): ?>
        <p class="ob-empty-state" data-ob-empty>No medications added yet. Add your first one above.</p>
        <?php endif; ?>
        <?php foreach ($drafts as $med): ?>
        <div class="ob-med-item" data-ob-med-id="<?= (int) $med['id'] ?>">
          <div class="ob-med-item-info">
            <strong><?= e((string) $med['name']) ?></strong>
            <?php $dose = formattedDose($med); ?>
            <?php if ($dose !== ''): ?>
            <span class="ob-med-item-dose"><?= e($dose) ?></span>
            <?php endif; ?>
            <?php if ((int) ($med['as_needed'] ?? 0)): ?>
            <span class="ob-badge ob-badge--prn">PRN</span>
            <?php endif; ?>
            <?php
              $typeLabels = ['prescription' => 'Rx', 'otc' => 'OTC', 'supplement' => 'Supplement'];
              $typeSlug   = (string) ($med['medication_type'] ?? 'prescription');
            ?>
            <span class="med-type-badge med-type-badge--<?= e($typeSlug) ?>"><?= e($typeLabels[$typeSlug] ?? 'Rx') ?></span>
          </div>
          <div class="ob-med-item-actions">
            <button type="button" class="btn-icon" data-ob-edit-med="<?= (int) $med['id'] ?>"
                    title="Edit"
                    data-med-name="<?= e((string) $med['name']) ?>"
                    data-med-dose-amount="<?= e((string) ($med['dose_amount'] ?? '')) ?>"
                    data-med-dose-unit="<?= e((string) ($med['dose_unit'] ?? '')) ?>"
                    data-med-dose-form="<?= e((string) ($med['dose_form'] ?? '')) ?>"
                    data-med-type="<?= e($typeSlug) ?>"
                    data-med-set-id="<?= e((string) ($med['set_id'] ?? '')) ?>"
                    data-med-as-needed="<?= (int) ($med['as_needed'] ?? 0) ?>">
              <i class="fa-solid fa-pen" aria-hidden="true"></i>
            </button>
            <button type="button" class="btn-icon btn-icon--danger" data-ob-delete-med="<?= (int) $med['id'] ?>"
                    title="Remove">
              <i class="fa-solid fa-trash" aria-hidden="true"></i>
            </button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ── Step 2: Tracking Preferences ───────────────────────────────── -->
    <div class="onboarding-step" data-onboarding-step="2" <?= $stepIndex !== 2 ? 'hidden' : '' ?>>
      <h1 class="onboarding-step-title">What do you want to track?</h1>
      <p class="onboarding-step-desc">Choose what RxTracker should monitor for each medication. You can change these anytime.</p>

      <div class="ob-tracking-table-wrap">
        <table class="ob-tracking-table" id="ob-tracking-table">
          <thead>
            <tr>
              <th class="ob-tracking-col--name">Medication</th>
              <th class="ob-tracking-col--check">
                <span>Reminders</span>
                <button type="button" class="ob-col-toggle" data-col-toggle="reminders_enabled" title="Toggle all">All</button>
              </th>
              <th class="ob-tracking-col--check">
                <span>Adherence</span>
                <button type="button" class="ob-col-toggle" data-col-toggle="adherence_enabled" title="Toggle all">All</button>
              </th>
              <th class="ob-tracking-col--check">
                <span>Inventory</span>
                <button type="button" class="ob-col-toggle" data-col-toggle="inventory_enabled" title="Toggle all">All</button>
              </th>
            </tr>
          </thead>
          <tbody id="ob-tracking-tbody">
            <?php foreach ($drafts as $med): ?>
            <?php $medId = (int) $med['id']; $isAsNeeded = (int) ($med['as_needed'] ?? 0); ?>
            <tr data-tracking-med-id="<?= $medId ?>" <?= $isAsNeeded ? 'class="ob-tracking-row--prn"' : '' ?>>
              <td class="ob-tracking-col--name">
                <strong><?= e((string) $med['name']) ?></strong>
                <?php if ($isAsNeeded): ?>
                <span class="ob-badge ob-badge--prn">PRN</span>
                <?php endif; ?>
              </td>
              <td class="ob-tracking-col--check">
                <input type="checkbox" name="reminders_enabled[<?= $medId ?>]"
                       class="ob-tracking-check" data-col="reminders_enabled"
                       value="1" <?= (!$isAsNeeded && (int)($med['reminders_enabled'] ?? 1)) ? 'checked' : '' ?>
                       <?= $isAsNeeded ? 'disabled' : '' ?>>
              </td>
              <td class="ob-tracking-col--check">
                <input type="checkbox" name="adherence_enabled[<?= $medId ?>]"
                       class="ob-tracking-check" data-col="adherence_enabled"
                       value="1" <?= (!$isAsNeeded && (int)($med['adherence_enabled'] ?? 1)) ? 'checked' : '' ?>
                       <?= $isAsNeeded ? 'disabled' : '' ?>>
              </td>
              <td class="ob-tracking-col--check">
                <input type="checkbox" name="inventory_enabled[<?= $medId ?>]"
                       class="ob-tracking-check" data-col="inventory_enabled"
                       value="1" <?= (int)($med['inventory_enabled'] ?? 0) ? 'checked' : '' ?>>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <p class="ob-tracking-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i>
          PRN (as-needed) medications have reminders and adherence tracking disabled automatically.</p>
      </div>
    </div>

    <!-- ── Step 3: Schedule ────────────────────────────────────────────── -->
    <div class="onboarding-step" data-onboarding-step="3" <?= $stepIndex !== 3 ? 'hidden' : '' ?>>
      <h1 class="onboarding-step-title">Set your schedule</h1>
      <p class="onboarding-step-desc">When do you take each medication? Add the times and optional quantity per dose.</p>

      <div class="ob-schedule-list" id="ob-schedule-list">
        <?php foreach ($drafts as $med): ?>
        <?php $medId = (int) $med['id']; $isAsNeeded = (int) ($med['as_needed'] ?? 0); ?>
        <div class="ob-schedule-card" data-schedule-med-id="<?= $medId ?>">
          <div class="ob-schedule-card-header">
            <strong><?= e((string) $med['name']) ?></strong>
            <?php $dose = formattedDose($med); ?>
            <?php if ($dose !== ''): ?><span class="ob-med-item-dose"><?= e($dose) ?></span><?php endif; ?>
            <?php if ($isAsNeeded): ?><span class="ob-badge ob-badge--prn">PRN — no fixed schedule</span><?php endif; ?>
          </div>
          <?php if (!$isAsNeeded): ?>
          <div class="ob-schedule-card-body">
            <div class="ob-preset-btns">
              <span class="ob-preset-label">Quick add:</span>
              <button type="button" class="btn-tag" data-add-time="8:00 AM">Morning 8am</button>
              <button type="button" class="btn-tag" data-add-time="12:00 PM">Noon</button>
              <button type="button" class="btn-tag" data-add-time="6:00 PM">Evening 6pm</button>
              <button type="button" class="btn-tag" data-add-time="10:00 PM">Bedtime 10pm</button>
            </div>
            <div class="ob-times-list" data-times-list>
              <?php foreach ($med['times'] as $i => $t): ?>
              <?php
                $slotQty = isset($med['time_doses'][$t]) && $med['time_doses'][$t] !== null
                    ? (float) $med['time_doses'][$t]
                    : (float) ($med['quantity_per_dose'] ?? 1);
              ?>
              <div class="ob-time-row" data-time-row>
                <input type="hidden" name="dose_times[]" value="<?= e(to12h($t)) ?>" class="ob-time-hidden">
                <span class="ob-time-pill"><?= e(to12h($t)) ?></span>
                <div class="ob-time-qty">
                  <label>Qty:</label>
                  <input type="number" step="any" min="0.001" value="<?= e((string) $slotQty) ?>"
                         class="ob-qty-input" name="dose_qtys[]">
                </div>
                <button type="button" class="btn-icon btn-icon--danger ob-remove-time" title="Remove">
                  <i class="fa-solid fa-times" aria-hidden="true"></i>
                </button>
              </div>
              <?php endforeach; ?>
            </div>
            <div class="ob-add-custom-time">
              <input type="text" class="ob-custom-time-input" placeholder="e.g. 9:30 AM" autocomplete="off">
              <button type="button" class="btn btn-sm btn-outline" data-add-custom-time>+ Add time</button>
            </div>
          </div>
          <div class="ob-schedule-save-row">
            <button type="button" class="btn btn-sm btn-primary ob-save-schedule-btn"
                    data-med-id="<?= $medId ?>"
                    data-qty-per-dose="<?= (float) ($med['quantity_per_dose'] ?? 1) ?>">
              Save schedule
            </button>
            <span class="ob-schedule-saved-indicator" data-schedule-saved hidden>
              <i class="fa-solid fa-check" aria-hidden="true"></i> Saved
            </span>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ── Step 4: Inventory ──────────────────────────────────────────── -->
    <div class="onboarding-step" data-onboarding-step="4" <?= $stepIndex !== 4 ? 'hidden' : '' ?>>
      <h1 class="onboarding-step-title">Set inventory counts</h1>
      <p class="onboarding-step-desc">Tell us how many pills/doses you have now so RxTracker can warn you before you run out.</p>

      <?php if (!$hasInventoryEnabled): ?>
      <div class="ob-notice ob-no-inventory-notice">
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        No medications have inventory tracking enabled. You can enable it in Step 2 or skip this step.
      </div>
      <?php else: ?>
      <div class="ob-inventory-list" id="ob-inventory-list">
        <?php foreach ($drafts as $med): ?>
        <?php if (!(int) ($med['inventory_enabled'] ?? 0)) continue; ?>
        <?php $medId = (int) $med['id']; ?>
        <?php $doseQty = max(0.001, (float) ($med['quantity_per_dose'] ?? 1)); ?>
        <?php $times = $med['times'] ?? []; ?>
        <div class="ob-inventory-card" data-inventory-med-id="<?= $medId ?>">
          <div class="ob-inventory-card-header">
            <strong><?= e((string) $med['name']) ?></strong>
            <?php $dose = formattedDose($med); ?>
            <?php if ($dose !== ''): ?><span class="ob-med-item-dose"><?= e($dose) ?></span><?php endif; ?>
          </div>

          <!-- Method selector -->
          <div class="ob-inventory-method-tabs">
            <button type="button" class="ob-method-tab is-active" data-method-tab="counted">
              <i class="fa-solid fa-calculator" aria-hidden="true"></i> Count now
            </button>
            <button type="button" class="ob-method-tab" data-method-tab="estimated">
              <i class="fa-solid fa-vial" aria-hidden="true"></i> Estimate from fill
            </button>
            <button type="button" class="ob-method-tab" data-method-tab="skip">
              <i class="fa-solid fa-forward" aria-hidden="true"></i> Skip
            </button>
          </div>

          <!-- Count now panel -->
          <div class="ob-method-panel" data-method-panel="counted">
            <label class="ob-field-label">How many do you have right now?</label>
            <div class="ob-qty-row">
              <input type="number" step="any" min="0" class="ob-inv-count-input"
                     placeholder="e.g. 30" data-counted-qty>
              <span><?= e((string) ($med['dose_unit'] ?? 'tablets')) ?></span>
            </div>
          </div>

          <!-- Estimate from fill panel -->
          <div class="ob-method-panel" data-method-panel="estimated" hidden>
            <div class="ob-form-row ob-form-row--2col">
              <div class="ob-field">
                <label>Fill date</label>
                <input type="date" class="ob-fill-date" max="<?= e($todayDate) ?>"
                       data-fill-date value="<?= e($todayDate) ?>">
              </div>
              <div class="ob-field">
                <label>Quantity dispensed</label>
                <input type="number" step="any" min="1" class="ob-fill-qty" placeholder="e.g. 90"
                       data-fill-qty>
              </div>
            </div>
            <div class="ob-form-row">
              <div class="ob-field">
                <label>Carryover from previous fill (optional)</label>
                <input type="number" step="any" min="0" value="0" class="ob-carryover-qty"
                       data-carryover-qty>
              </div>
            </div>
            <div class="ob-estimate-result" data-estimate-result hidden>
              <div class="ob-estimate-value" data-estimate-value></div>
              <div class="ob-estimate-confidence" data-estimate-confidence></div>
              <div class="ob-estimate-warnings" data-estimate-warnings></div>
            </div>
            <button type="button" class="btn btn-sm btn-outline ob-calc-btn" data-calc-estimate
                    data-med-id="<?= $medId ?>"
                    data-schedule-mode="<?= e((string) ($med['schedule_mode'] ?? 'fixed_times')) ?>"
                    data-times="<?= e(json_encode($times)) ?>"
                    data-qty-per-dose="<?= e((string) $doseQty) ?>">
              Calculate estimate
            </button>
          </div>

          <!-- Skip panel -->
          <div class="ob-method-panel" data-method-panel="skip" hidden>
            <p class="ob-notice">Inventory tracking will be disabled for this medication. You can set it up later from the medication settings.</p>
          </div>

          <div class="ob-inventory-save-row">
            <button type="button" class="btn btn-sm btn-primary ob-save-inventory-btn"
                    data-med-id="<?= $medId ?>">
              Save
            </button>
            <span class="ob-schedule-saved-indicator" data-inventory-saved hidden>
              <i class="fa-solid fa-check" aria-hidden="true"></i> Saved
            </span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── Step 5: Reconcile Today ─────────────────────────────────────── -->
    <div class="onboarding-step" data-onboarding-step="5" <?= $stepIndex !== 5 ? 'hidden' : '' ?>>
      <h1 class="onboarding-step-title">Earlier doses today</h1>
      <?php if (empty($pastSlots)): ?>
      <p class="onboarding-step-desc">No scheduled doses have passed yet today. Nothing to reconcile — continue to activate.</p>
      <?php else: ?>
      <p class="onboarding-step-desc">Did you already take these doses today? Mark them now so your history stays accurate.
         If you're not sure, just skip — they will <strong>not</strong> be marked as missed.</p>

      <div class="ob-reconcile-list" id="ob-reconcile-list">
        <?php
          // Group by time
          $slotsByTime = [];
          foreach ($pastSlots as $slot) {
              $slotsByTime[$slot['time_display']][] = $slot;
          }
        ?>
        <?php foreach ($slotsByTime as $timeDisplay => $slots): ?>
        <div class="ob-reconcile-group">
          <div class="ob-reconcile-group-header">
            <h3><?= e($timeDisplay) ?></h3>
            <div class="ob-reconcile-group-actions">
              <button type="button" class="btn btn-sm btn-primary" data-mark-group-taken>
                <i class="fa-solid fa-check" aria-hidden="true"></i> All taken
              </button>
              <button type="button" class="btn btn-sm btn-ghost" data-skip-group>
                Skip
              </button>
            </div>
          </div>
          <div class="ob-reconcile-meds">
            <?php foreach ($slots as $slot): ?>
            <div class="ob-reconcile-med" data-reconcile-med
                 data-med-id="<?= (int) $slot['medication_id'] ?>"
                 data-time="<?= e(substr($slot['time'], 0, 5)) ?>">
              <label class="ob-reconcile-check-label">
                <input type="checkbox" class="ob-reconcile-check" value="1" checked>
                <span>
                  <strong><?= e($slot['name']) ?></strong>
                  <?php if ($slot['dose'] !== ''): ?>
                  <span class="ob-med-item-dose"><?= e($slot['dose']) ?></span>
                  <?php endif; ?>
                </span>
              </label>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="ob-reconcile-footer">
        <button type="button" class="btn btn-primary" id="ob-submit-reconcile"
                data-reconcile-submit>
          <i class="fa-solid fa-check-double" aria-hidden="true"></i> Save dose history
        </button>
        <button type="button" class="btn btn-ghost" data-ob-skip-reconcile>
          Skip — start fresh from now
        </button>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── Step 6: Activate ────────────────────────────────────────────── -->
    <div class="onboarding-step" data-onboarding-step="6" <?= $stepIndex !== 6 ? 'hidden' : '' ?>>
      <h1 class="onboarding-step-title">Ready to activate</h1>
      <p class="onboarding-step-desc">Here's a summary of what you've set up. Click Activate to start tracking.</p>

      <div class="ob-summary" id="ob-summary">
        <div class="ob-summary-grid">
          <div class="ob-summary-stat">
            <div class="ob-summary-stat-value" id="ob-summary-med-count"><?= $draftCount ?></div>
            <div class="ob-summary-stat-label">Medications</div>
          </div>
          <div class="ob-summary-stat">
            <div class="ob-summary-stat-value" id="ob-summary-adherence-count">
              <?= count(array_filter($drafts, static fn($m) => (int)($m['adherence_enabled'] ?? 1) && !(int)($m['as_needed'] ?? 0))) ?>
            </div>
            <div class="ob-summary-stat-label">With adherence</div>
          </div>
          <div class="ob-summary-stat">
            <div class="ob-summary-stat-value" id="ob-summary-inventory-count">
              <?= count(array_filter($drafts, static fn($m) => (int)($m['inventory_enabled'] ?? 0))) ?>
            </div>
            <div class="ob-summary-stat-label">With inventory</div>
          </div>
          <div class="ob-summary-stat">
            <div class="ob-summary-stat-value" id="ob-summary-reminder-count">
              <?= count(array_filter($drafts, static fn($m) => (int)($m['reminders_enabled'] ?? 1) && !(int)($m['as_needed'] ?? 0))) ?>
            </div>
            <div class="ob-summary-stat-label">With reminders</div>
          </div>
        </div>

        <div class="ob-summary-meds">
          <?php foreach ($drafts as $med): ?>
          <?php $medId = (int) $med['id']; ?>
          <div class="ob-summary-med-row">
            <div class="ob-summary-med-info">
              <strong><?= e((string) $med['name']) ?></strong>
              <?php $dose = formattedDose($med); ?>
              <?php if ($dose !== ''): ?><span class="ob-med-item-dose"><?= e($dose) ?></span><?php endif; ?>
              <?php if ((int)($med['as_needed'] ?? 0)): ?>
              <span class="ob-badge ob-badge--prn">PRN</span>
              <?php else: ?>
              <?php $times = $med['times'] ?? []; ?>
              <?php if (count($times) > 0): ?>
              <span class="ob-summary-times"><?= e(implode(', ', array_map('to12h', $times))) ?></span>
              <?php else: ?>
              <span class="ob-summary-no-schedule">No schedule set</span>
              <?php endif; ?>
              <?php endif; ?>
            </div>
            <div class="ob-summary-med-flags">
              <?php if ((int)($med['reminders_enabled'] ?? 1) && !(int)($med['as_needed'] ?? 0)): ?>
              <span class="ob-flag ob-flag--on" title="Reminders"><i class="fa-solid fa-bell" aria-hidden="true"></i></span>
              <?php endif; ?>
              <?php if ((int)($med['adherence_enabled'] ?? 1) && !(int)($med['as_needed'] ?? 0)): ?>
              <span class="ob-flag ob-flag--on" title="Adherence"><i class="fa-solid fa-chart-line" aria-hidden="true"></i></span>
              <?php endif; ?>
              <?php if ((int)($med['inventory_enabled'] ?? 0)): ?>
              <span class="ob-flag ob-flag--on" title="Inventory"><i class="fa-solid fa-pills" aria-hidden="true"></i></span>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <p class="ob-summary-note">
          <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
          Pharmacy, prescriber, and refill info can be added later from each medication's settings.
        </p>
      </div>

      <div class="ob-activate-row">
        <button type="button" class="btn btn-primary btn-lg" id="ob-activate-btn" data-ob-activate>
          <i class="fa-solid fa-rocket" aria-hidden="true"></i>
          Activate & go to dashboard
        </button>
        <div class="ob-activate-error" data-ob-activate-error hidden></div>
      </div>
    </div>

  </div><!-- /.onboarding-content -->

  <!-- Footer nav -->
  <div class="onboarding-footer">
    <button type="button" class="btn btn-ghost" id="ob-prev-btn" data-ob-prev
            <?= $stepIndex === 1 ? 'hidden' : '' ?>>
      <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back
    </button>
    <div class="ob-footer-center">
      <span class="ob-step-counter" data-ob-step-counter>Step <?= $stepIndex ?> of <?= count($steps) ?></span>
    </div>
    <button type="button" class="btn btn-primary" id="ob-next-btn" data-ob-next
            <?= $draftCount === 0 && $stepIndex === 1 ? 'disabled' : '' ?>>
      <?= $stepIndex < count($steps) ? 'Next <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>' : 'Activate <i class="fa-solid fa-rocket" aria-hidden="true"></i>' ?>
    </button>
  </div>

</div><!-- /.onboarding-shell -->

<script>
document.getElementById('ob-skip-btn')?.addEventListener('click', async () => {
  const fd = new FormData();
  fd.append('action', 'skip_setup');
  fd.append('csrf_token', <?= json_encode($csrfToken) ?>);
  try {
    const res = await fetch('index.php?page=onboarding', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) window.location.href = data.redirect;
  } catch {
    window.location.href = 'index.php';
  }
});
// Onboarding state passed from PHP
window.rxOnboarding = {
  csrfToken: <?= json_encode($csrfToken) ?>,
  currentStep: <?= $stepIndex ?>,
  totalSteps: <?= count($steps) ?>,
  draftCount: <?= $draftCount ?>,
  hasInventoryEnabled: <?= $hasInventoryEnabled ? 'true' : 'false' ?>,
  pastSlots: <?= json_encode($pastSlots, JSON_HEX_TAG) ?>,
  drafts: <?= json_encode(array_map(static fn($m) => [
    'id'                => (int) $m['id'],
    'name'              => $m['name'],
    'dose_amount'       => $m['dose_amount'] ?? null,
    'dose_unit'         => $m['dose_unit'] ?? null,
    'dose_form'         => $m['dose_form'] ?? null,
    'medication_type'   => $m['medication_type'] ?? 'prescription',
    'set_id'            => $m['set_id'] ?? '',
    'as_needed'         => (bool) ($m['as_needed'] ?? false),
    'reminders_enabled' => (bool) ($m['reminders_enabled'] ?? true),
    'adherence_enabled' => (bool) ($m['adherence_enabled'] ?? true),
    'inventory_enabled' => (bool) ($m['inventory_enabled'] ?? false),
    'times'             => $m['times'] ?? [],
    'schedule_mode'     => $m['schedule_mode'] ?? 'fixed_times',
    'qty_per_dose'      => max(0.001, (float) ($m['quantity_per_dose'] ?? 1)),
  ], $drafts), JSON_HEX_TAG) ?>,
};
</script>
</body>
</html>
