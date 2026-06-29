<?php
// Shared partial: medication plan tab panels.
// Expects the following variables to be in scope via include from pages.php:
/** @var int $medicationPlanCount */
/** @var int $inactiveMedicationCount */
/** @var array<int, array<string, mixed>> $medications */
/** @var array<int, array<string, mixed>> $inactiveMedications */
/** @var array<int, array<string, mixed>> $groups */
/** @var array<int, array<string, mixed>> $ungroupedMedications */
/** @var \MedicationRepository $repository */
/** @var int $graceMinutes */
?>
<div class="plan-tab-panel" id="active-medications-panel" data-plan-panel="active" role="tabpanel" aria-labelledby="active-medications-tab">
  <div class="med-filter-wrap" data-med-type-filter>
    <button type="button" class="med-filter-trigger" data-med-filter-trigger aria-label="Filter medications" aria-expanded="false">
      <i class="fa-solid fa-sliders" aria-hidden="true"></i>
    </button>
    <div class="med-filter-dropdown" data-med-filter-dropdown hidden>
      <ul class="med-filter-list">
        <li class="med-filter-option is-selected" data-filter-value="prescription">
          <i class="fa-solid fa-check med-filter-check" aria-hidden="true"></i>
          Rx
        </li>
        <li class="med-filter-option is-selected" data-filter-value="otc">
          <i class="fa-solid fa-check med-filter-check" aria-hidden="true"></i>
          OTC
        </li>
        <li class="med-filter-option is-selected" data-filter-value="supplement">
          <i class="fa-solid fa-check med-filter-check" aria-hidden="true"></i>
          Vitamin / Supplement
        </li>
      </ul>
      <button type="button" class="med-filter-apply" data-med-type-apply>Apply filters</button>
    </div>
  </div>
  <div class="medication-list">
    <?php if ($medicationPlanCount === 0): ?>
      <div class="empty-state"><p>No active medications yet.</p></div>
    <?php endif; ?>
    <?php foreach ($medications as $medication): ?>
      <?php $daysLeft = daysUntilRunout($medication); ?>
      <div class="medication-row medication-row-plan" data-med-type="<?= e((string) ($medication['medication_type'] ?? 'prescription')) ?>" data-med-id="<?= e((string) $medication['id']) ?>">
        <button type="button" class="drag-handle" aria-label="Drag to reorder" tabindex="-1"><i class="fa-solid fa-grip-vertical" aria-hidden="true"></i></button>
        <div class="product-label-wrap"
             data-product-label-wrap
             data-medication-id="<?= e((string) $medication['id']) ?>"
             data-set-id="<?= e((string) ($medication['set_id'] ?? '')) ?>"
             data-medication-name="<?= e((string) $medication['name']) ?>"
             data-dose="<?= e(formattedDose($medication)) ?>"
             data-instructions="<?= e((string) $medication['instructions']) ?>">
        </div>
        <div class="medication-content">
          <?php
            $medTypeLabels = ['prescription' => 'Rx', 'otc' => 'OTC', 'supplement' => 'Supplement'];
            $medTypeSlug   = (string) ($medication['medication_type'] ?? 'prescription');
            $medTypeLabel  = $medTypeLabels[$medTypeSlug] ?? 'Rx';
          ?>
          <strong><?= e((string) $medication['name']) ?></strong><span class="med-type-badge med-type-badge--<?= e($medTypeSlug) ?>"><?= e($medTypeLabel) ?></span>
          <?php if (formattedDose($medication) !== ''): ?>
          <p><?= e(formattedDose($medication)) ?><?= !empty($medication['dose_form']) ? ' ' . e((string) $medication['dose_form']) : '' ?></p>
          <?php endif; ?>
          <p>
            <?php if ((string) $medication['schedule_mode'] === 'interval'): ?>
              Every <?= e((string) $medication['interval_hours']) ?> hours from <?= e(to12h((string) $medication['first_dose_time'])) ?>
            <?php else: ?>
              <?= e(implode(', ', array_map(static fn(string $time): string => to12h($time), $medication['times']))) ?>
            <?php endif; ?>
            <?= ((int) $medication['as_needed'] === 1) ? '(As needed)' : '' ?>
          </p>
          <?php if (!empty($medication['start_date'])): ?>
          <p class="pill-meta">Started: <?= e((new DateTimeImmutable((string) $medication['start_date']))->format('M j, Y')) ?></p>
          <?php elseif (!empty($medication['created_at'])): ?>
          <p class="pill-meta">Started: <?= e((new DateTimeImmutable(date('Y-m-d', strtotime((string) $medication['created_at']))))->format('M j, Y')) ?> <span style="color:var(--rx-text-muted);font-size:0.8em;">(added to app)</span></p>
          <?php endif; ?>
          <?php
            $curQty   = (float) ($medication['current_quantity'] ?? $medication['pill_count'] ?? 0);
            $startQty = (float) ($medication['starting_quantity'] ?? $medication['starting_pill_count'] ?? 0);
            $invUnit  = (string) ($medication['inventory_unit'] ?? 'tablets');
            $lowThresh = (float) ($medication['low_supply_threshold'] ?? 0);
            $curQtyDisplay   = $curQty == (int) $curQty ? (string) (int) $curQty : rtrim(number_format($curQty, 3), '0');
            $startQtyDisplay = $startQty == (int) $startQty ? (string) (int) $startQty : rtrim(number_format($startQty, 3), '0');
          ?>
          <p class="pill-meta"><?= e($invUnit === 'tablets' ? 'Pills' : ucfirst($invUnit)) ?>: <?= e($curQtyDisplay) ?> / <?= e($startQtyDisplay) ?> | Refill alert at <?= e((string) $medication['low_supply_threshold']) ?> <?= e($invUnit) ?></p>
          <?php if ($startQty > 0): ?>
            <?php
              $supplyPercent = min(100, (int) round($curQty / $startQty * 100));
              $supplyBarClass = $supplyPercent <= 25 ? ' pill-supply-bar-fill--critical' : ($supplyPercent <= 50 ? ' pill-supply-bar-fill--low' : '');
            ?>
            <div class="pill-supply-bar" role="progressbar" aria-valuenow="<?= e((string) $supplyPercent) ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?= e((string) $supplyPercent) ?>% supply remaining">
              <div class="pill-supply-bar-fill<?= $supplyBarClass ?>" style="width:<?= e((string) $supplyPercent) ?>%"></div>
            </div>
          <?php endif; ?>
          <?php if ($daysLeft !== null): ?>
            <p class="pill-meta<?= $daysLeft <= 7 ? ' refill-soon' : '' ?>">~<?= e((string) $daysLeft) ?> days left &middot; runs out ~<?= e((new DateTime())->modify('+' . $daysLeft . ' days')->format('M j')) ?></p>
          <?php endif; ?>
          <?php if (!empty($medication['last_refill'])): ?>
            <p class="pill-meta refill-meta">Last refill: <?= e((new DateTimeImmutable((string) $medication['last_refill']['refill_date']))->format('M j, Y')) ?> &middot; <?= e((string) $medication['last_refill']['amount']) ?> <?= e($invUnit) ?></p>
          <?php endif; ?>
          <button type="button" class="view-details-link"
                  data-view-details
                  data-medication-name="<?= e((string) $medication['name']) ?>"
                  data-set-id="<?= e((string) ($medication['set_id'] ?? '')) ?>">View details</button>
        </div>
        <div class="row-actions medication-actions-top">
          <?php if ((int) $medication['track_dose_feedback'] === 1): ?>
          <button
            type="button"
            class="icon-button pain-graph-btn"
            data-open-pain-graph
            data-medication-id="<?= e((string) $medication['id']) ?>"
            data-medication-name="<?= e((string) $medication['name']) ?>"
            data-medication-dose="<?= e(formattedDose($medication)) ?>"
            aria-label="View pain level trend for <?= e((string) $medication['name']) ?>"
            title="Pain level trend"
          ><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="20" x2="22" y2="20"/></svg></button>
          <?php endif; ?>
          <div class="med-actions-menu" data-med-actions-menu>
            <button
              type="button"
              class="icon-button med-actions-trigger"
              data-med-actions-trigger
              aria-label="More actions for <?= e((string) $medication['name']) ?>"
              aria-expanded="false"
              aria-haspopup="true"
            ><i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i></button>
            <div class="med-actions-dropdown" data-med-actions-dropdown hidden>
              <a href="index.php?page=medications&edit=<?= e((string) $medication['id']) ?>" class="med-actions-item">
                <i class="fa-solid fa-pen" aria-hidden="true"></i>
                Edit
              </a>
              <button type="button" class="med-actions-item" data-open-refill-history data-medication-id="<?= e((string) $medication['id']) ?>" data-medication-name="<?= e((string) $medication['name']) ?>">
                <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
                Refill history
              </button>
              <form method="post" action="index.php" data-confirm="Move this medication to inactive?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="deactivate_medication">
                <input type="hidden" name="medication_id" value="<?= e((string) $medication['id']) ?>">
                <button type="submit" class="med-actions-item med-actions-item--danger">
                  <i class="fa-solid fa-power-off" aria-hidden="true"></i>
                  Deactivate
                </button>
              </form>
              <button type="button" class="med-actions-item" data-open-refill-modal data-medication-id="<?= e((string) $medication['id']) ?>" data-medication-name="<?= e((string) $medication['name']) ?>">
                <i class="fa-regular fa-calendar-plus" aria-hidden="true"></i>
                Log refill
              </button>
              <button type="button" class="med-actions-item log-se-btn med-menu-item--mobile-only" data-log-se data-medication-id="<?= e((string) $medication['id']) ?>" data-medication-name="<?= e((string) $medication['name']) ?>">
                <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                Log side effect
              </button>
            </div>
          </div>
        </div>
        <div class="row-actions medication-actions-bottom">
          <form method="post" action="index.php" data-log-dose-now-form>
            <?= csrf_field() ?>
            <input type="hidden" name="json_response" value="1">
            <input type="hidden" name="action" value="log_dose_now">
            <input type="hidden" name="medication_id" value="<?= e((string) $medication['id']) ?>">
            <input type="hidden" name="note" value="Logged now">
            <?php
              $medSlots = array_map(
                  static fn(string $t): array => [
                      'time'   => $t,
                      'status' => $todaySlotStatusMap[(int) $medication['id']][$t] ?? '',
                  ],
                  $medication['times']
              );
            ?>
            <button
              type="submit"
              class="secondary"
              data-log-dose-now
              data-medication-id="<?= e((string) $medication['id']) ?>"
              data-medication-name="<?= e((string) $medication['name']) ?>"
              data-track-dose-feedback="<?= (int) $medication['track_dose_feedback'] === 1 ? '1' : '0' ?>"
              data-slots="<?= e(json_encode($medSlots)) ?>"
              data-grace-minutes="<?= e((string) $graceMinutes) ?>"
            ><i class="fa-regular fa-circle-check" aria-hidden="true"></i> Log dose</button>
          </form>
          <button type="button" class="secondary log-se-btn med-action-desktop" data-log-se data-medication-id="<?= e((string) $medication['id']) ?>" data-medication-name="<?= e((string) $medication['name']) ?>"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Log side effect</button>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="plan-tab-panel" id="inactive-medications-panel" data-plan-panel="inactive" role="tabpanel" aria-labelledby="inactive-medications-tab" hidden>
  <div class="med-filter-wrap" data-med-type-filter>
    <button type="button" class="med-filter-trigger" data-med-filter-trigger aria-label="Filter medications" aria-expanded="false">
      <i class="fa-solid fa-sliders" aria-hidden="true"></i>
    </button>
    <div class="med-filter-dropdown" data-med-filter-dropdown hidden>
      <ul class="med-filter-list">
        <li class="med-filter-option is-selected" data-filter-value="prescription">
          <i class="fa-solid fa-check med-filter-check" aria-hidden="true"></i>
          Rx
        </li>
        <li class="med-filter-option is-selected" data-filter-value="otc">
          <i class="fa-solid fa-check med-filter-check" aria-hidden="true"></i>
          OTC
        </li>
        <li class="med-filter-option is-selected" data-filter-value="supplement">
          <i class="fa-solid fa-check med-filter-check" aria-hidden="true"></i>
          Vitamin / Supplement
        </li>
      </ul>
      <button type="button" class="med-filter-apply" data-med-type-apply>Apply filters</button>
    </div>
  </div>
  <div class="inactive-list">
    <?php if ($inactiveMedications === []): ?>
      <div class="empty-state"><p>No inactive medications.</p></div>
    <?php endif; ?>
    <?php foreach ($inactiveMedications as $medication): ?>
      <div class="medication-row" data-med-type="<?= e((string) ($medication['medication_type'] ?? 'prescription')) ?>">
        <div>
          <?php $inactiveMedTypeSlug = (string) ($medication['medication_type'] ?? 'prescription'); $inactiveMedTypeLabels = ['prescription' => 'Rx', 'otc' => 'OTC', 'supplement' => 'Supplement']; ?>
          <strong><?= e((string) $medication['name']) ?></strong><span class="med-type-badge med-type-badge--<?= e($inactiveMedTypeSlug) ?>"><?= e($inactiveMedTypeLabels[$inactiveMedTypeSlug] ?? 'Rx') ?></span>
          <?php if (formattedDose($medication) !== ''): ?>
          <p><?= e(formattedDose($medication)) ?></p>
          <?php endif; ?>
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

<div class="plan-tab-panel" id="groups-panel" data-plan-panel="groups" role="tabpanel" aria-labelledby="groups-tab" hidden>
  <div class="groups-list">
    <div class="groups-create-row">
      <button type="button" class="secondary" data-open-create-group-form>+ Create group</button>
    </div>

    <!-- Create/edit group inline form -->
    <div class="group-form-wrap" data-group-form-wrap>
      <form class="group-inline-form" method="post" action="index.php" data-group-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_group" data-group-form-action>
        <input type="hidden" name="group_id" value="" data-group-form-id>
        <label>Group name
          <input name="group_name" required placeholder="e.g. Morning Medications" data-group-form-name value="">
        </label>
        <label>Scheduled time
          <input name="group_time" required placeholder="8:00 AM" data-group-form-time value="">
        </label>
        <div class="group-form-actions">
          <button type="submit" data-group-form-submit>Create group</button>
          <button type="button" class="secondary" data-cancel-group-form>Cancel</button>
        </div>
      </form>
    </div>

    <?php if ($groups === []): ?>
      <div class="empty-state groups-empty-state"><p>No groups yet. Create a group to bundle medications taken at the same time.</p></div>
    <?php endif; ?>

    <div class="groups-sortable-list" data-groups-sortable>
    <?php foreach ($groups as $group): ?>
      <div class="group-card" data-group-card-id="<?= e((string) $group['id']) ?>">
        <button type="button" class="drag-handle drag-handle--group" aria-label="Drag to reorder" tabindex="-1"><i class="fa-solid fa-grip-vertical" aria-hidden="true"></i></button>
        <div class="group-card-body">
        <div class="group-card-header">
          <div class="group-card-title">
            <strong data-group-card-name><?= e($group['name']) ?></strong>
            <input type="text" class="group-name-input" data-group-name-input value="<?= e($group['name']) ?>" aria-label="Group name" hidden>
            <span class="group-time-badge" data-group-card-time><?= e(to12h($group['scheduled_time'])) ?></span>
            <input type="text" class="group-time-input" data-group-time-input value="<?= e(to12h($group['scheduled_time'])) ?>" aria-label="Scheduled time (e.g. 8:00 AM)" placeholder="e.g. 8:00 AM" hidden>
            <span class="count-badge" data-group-card-count><?= e((string) count($group['members'])) ?> med<?= count($group['members']) !== 1 ? 's' : '' ?></span>
          </div>
          <div class="med-actions-menu" data-group-actions-menu>
            <button type="button" class="icon-button med-actions-trigger" data-group-actions-trigger aria-expanded="false" aria-haspopup="true">
              <i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
            </button>
            <div class="med-actions-dropdown" data-group-actions-dropdown hidden>
              <button type="button" class="med-actions-item" data-edit-group
                data-group-id="<?= e((string) $group['id']) ?>"
                data-group-name="<?= e($group['name']) ?>"
                data-group-time="<?= e(to12h($group['scheduled_time'])) ?>">
                <i class="fa-solid fa-pen" aria-hidden="true"></i>
                Edit
              </button>
              <form method="post" action="index.php" data-confirm="Delete this group? Medications will become individual.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_group">
                <input type="hidden" name="group_id" value="<?= e((string) $group['id']) ?>">
                <button type="submit" class="med-actions-item med-actions-item--danger">
                  <i class="fa-solid fa-trash" aria-hidden="true"></i>
                  Delete
                </button>
              </form>
            </div>
          </div>
        </div>

        <div class="group-members-list" data-group-members>
          <?php if ($group['members'] === []): ?>
            <p class="group-empty-hint">No medications in this group yet.</p>
          <?php endif; ?>
          <?php foreach ($group['members'] as $member): ?>
            <div class="group-member-row">
              <?php $grpMedTypeSlug = (string) ($member['medication_type'] ?? 'prescription'); $grpMedTypeLabels = ['prescription' => 'Rx', 'otc' => 'OTC', 'supplement' => 'Supplement']; ?>
              <span class="group-member-name"><?= e((string) $member['name']) ?></span><?php if (formattedDose($member) !== ''): ?><span class="group-member-dose"><?= e(formattedDose($member)) ?></span><?php endif; ?><span class="med-type-badge med-type-badge--<?= e($grpMedTypeSlug) ?>"><?= e($grpMedTypeLabels[$grpMedTypeSlug] ?? 'Rx') ?></span>
              <?php if ($member['group_quantity_per_dose'] !== null): ?>
                <?php $overrideUnit = (string) ($member['inventory_unit'] ?? 'tablets'); ?>
                <span class="group-member-dose"><?= e((string) (float) $member['group_quantity_per_dose']) ?> <?= e($overrideUnit) ?> <em class="group-dose-override-hint">(override)</em></span>
              <?php endif; ?>
              <?php if ((int) $member['track_dose_feedback'] === 1): ?>
                <span class="group-feedback-badge">tracks feedback</span>
              <?php endif; ?>
              <form method="post" action="index.php" data-ajax-remove>
                <?= csrf_field() ?>
                <input type="hidden" name="json_response" value="1">
                <input type="hidden" name="action" value="remove_medication_from_group">
                <input type="hidden" name="group_id" value="<?= e((string) $group['id']) ?>">
                <input type="hidden" name="medication_id" value="<?= e((string) $member['medication_id']) ?>">
                <button type="submit" class="secondary group-remove-btn">&times; Remove</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>

        <?php $eligibleToAdd = $repository->ungroupedActiveMedications((int) $group['id']); ?>
        <?php if ($eligibleToAdd !== []): ?>
          <form class="group-add-med-form" method="post" action="index.php" data-ajax-add hidden>
            <?= csrf_field() ?>
            <input type="hidden" name="json_response" value="1">
            <input type="hidden" name="action" value="add_medication_to_group">
            <input type="hidden" name="group_id" value="<?= e((string) $group['id']) ?>">
            <select name="medication_id" class="group-add-select">
              <option value="">Add a medication&hellip;</option>
              <?php foreach ($eligibleToAdd as $eligible): ?>
                <?php $eligibleDose = formattedDose($eligible); ?>
                <?php $existingGroups = (string) ($eligible['existing_groups'] ?? ''); ?>
                <option value="<?= e((string) $eligible['id']) ?>" data-name="<?= e((string) $eligible['name']) ?>" data-dose="<?= e($eligibleDose) ?>"><?= e((string) $eligible['name']) ?><?= $eligibleDose !== '' ? ' &mdash; ' . e($eligibleDose) : '' ?><?= $existingGroups !== '' ? ' (also in: ' . e($existingGroups) . ')' : '' ?></option>
              <?php endforeach; ?>
            </select>
            <label class="group-dose-override-label">Dose qty for this group
              <input type="number" name="quantity_per_dose" min="0.25" step="0.25" placeholder="e.g. 2" class="group-dose-override-input">
              <span class="field-optional">(optional — leave blank to use default)</span>
            </label>
            <button type="submit" class="secondary group-add-btn">Add</button>
          </form>
        <?php endif; ?>
        <div class="group-card-edit-actions" data-group-edit-actions hidden>
          <button type="button" class="secondary" data-group-cancel-edit>Cancel</button>
          <button type="button" data-group-save-edit data-group-id="<?= e((string) $group['id']) ?>">Save changes</button>
        </div>
        </div><!-- /.group-card-body -->
      </div>
    <?php endforeach; ?>
    </div><!-- /.groups-sortable-list -->
  </div>
</div>
