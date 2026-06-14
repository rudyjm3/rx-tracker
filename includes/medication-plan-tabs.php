<?php
// Shared partial: medication plan tab panels.
// Expects: $medicationPlanCount, $inactiveMedicationCount, $groups, $medications,
//          $inactiveMedications, $ungroupedMedications to be in scope.
?>
<div class="plan-tab-panel" id="active-medications-panel" data-plan-panel="active" role="tabpanel" aria-labelledby="active-medications-tab">
  <div class="medication-list">
    <?php if ($medicationPlanCount === 0): ?>
      <div class="empty-state"><p>No active medications yet.</p></div>
    <?php endif; ?>
    <?php foreach ($medications as $medication): ?>
      <?php $daysLeft = daysUntilRunout($medication); ?>
      <div class="medication-row medication-row-plan">
        <div class="product-label-wrap"
             data-product-label-wrap
             data-medication-id="<?= e((string) $medication['id']) ?>"
             data-set-id="<?= e((string) ($medication['set_id'] ?? '')) ?>"
             data-medication-name="<?= e((string) $medication['name']) ?>">
        </div>
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
          <p class="pill-meta">Pills: <?= e((string) $medication['pill_count']) ?> / <?= e((string) $medication['starting_pill_count']) ?> | Refill alert at <?= e((string) $medication['low_supply_threshold']) ?> pills</p>
          <?php if ((int) $medication['starting_pill_count'] > 0): ?>
            <?php
              $supplyPercent = min(100, (int) round((int) $medication['pill_count'] / (int) $medication['starting_pill_count'] * 100));
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
            <p class="pill-meta refill-meta">Last refill: <?= e((new DateTimeImmutable((string) $medication['last_refill']['refill_date']))->format('M j, Y')) ?> &middot; <?= e((string) $medication['last_refill']['amount']) ?> pills</p>
          <?php endif; ?>
          <button type="button" class="view-details-link"
                  data-view-details
                  data-medication-name="<?= e((string) $medication['name']) ?>"
                  data-set-id="<?= e((string) ($medication['set_id'] ?? '')) ?>">View details</button>
        </div>
        <div class="row-actions medication-actions-top">
          <a class="secondary modal-edit-link" href="index.php?edit=<?= e((string) $medication['id']) ?>">Edit</a>
          <?php if ((int) $medication['track_dose_feedback'] === 1): ?>
          <button
            type="button"
            class="icon-button pain-graph-btn"
            data-open-pain-graph
            data-medication-id="<?= e((string) $medication['id']) ?>"
            data-medication-name="<?= e((string) $medication['name']) ?>"
            aria-label="View pain level trend for <?= e((string) $medication['name']) ?>"
            title="Pain level trend"
          ><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="20" x2="22" y2="20"/></svg></button>
          <?php endif; ?>
        </div>
        <div class="row-actions medication-actions-bottom">
          <form method="post" action="index.php" data-log-dose-now-form>
            <?= csrf_field() ?>
            <input type="hidden" name="json_response" value="1">
            <input type="hidden" name="action" value="log_dose_now">
            <input type="hidden" name="medication_id" value="<?= e((string) $medication['id']) ?>">
            <input type="hidden" name="note" value="Logged now">
            <button
              type="submit"
              class="secondary"
              data-log-dose-now
              data-medication-id="<?= e((string) $medication['id']) ?>"
              data-track-dose-feedback="<?= (int) $medication['track_dose_feedback'] === 1 ? '1' : '0' ?>"
            >Log dose now</button>
          </form>
          <button type="button" class="secondary" data-open-refill-modal data-medication-id="<?= e((string) $medication['id']) ?>" data-medication-name="<?= e((string) $medication['name']) ?>">Log refill</button>
          <button type="button" class="secondary" data-open-refill-history data-medication-id="<?= e((string) $medication['id']) ?>" data-medication-name="<?= e((string) $medication['name']) ?>">Refill history</button>
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

<div class="plan-tab-panel" id="inactive-medications-panel" data-plan-panel="inactive" role="tabpanel" aria-labelledby="inactive-medications-tab" hidden>
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

<div class="plan-tab-panel" id="groups-panel" data-plan-panel="groups" role="tabpanel" aria-labelledby="groups-tab" hidden>
  <div class="groups-list">
    <div class="groups-create-row">
      <button type="button" class="secondary" data-open-create-group-form>+ Create group</button>
    </div>

    <!-- Create/edit group inline form -->
    <div class="group-form-wrap" data-group-form-wrap hidden>
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

    <?php foreach ($groups as $group): ?>
      <div class="group-card" data-group-card-id="<?= e((string) $group['id']) ?>">
        <div class="group-card-header">
          <div class="group-card-title">
            <strong data-group-card-name><?= e($group['name']) ?></strong>
            <span class="group-time-badge" data-group-card-time><?= e(to12h($group['scheduled_time'])) ?></span>
            <span class="count-badge" data-group-card-count><?= e((string) count($group['members'])) ?> med<?= count($group['members']) !== 1 ? 's' : '' ?></span>
          </div>
          <div class="row-actions">
            <button type="button" class="secondary" data-edit-group data-group-id="<?= e((string) $group['id']) ?>" data-group-name="<?= e($group['name']) ?>" data-group-time="<?= e(to12h($group['scheduled_time'])) ?>">Edit</button>
            <form method="post" action="index.php" data-confirm="Delete this group? Medications will become individual.">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_group">
              <input type="hidden" name="group_id" value="<?= e((string) $group['id']) ?>">
              <button type="submit" class="secondary">Delete</button>
            </form>
          </div>
        </div>

        <div class="group-members-list">
          <?php if ($group['members'] === []): ?>
            <p class="group-empty-hint">No medications in this group yet.</p>
          <?php endif; ?>
          <?php foreach ($group['members'] as $member): ?>
            <div class="group-member-row">
              <span class="group-member-name"><?= e((string) $member['name']) ?></span>
              <span class="group-member-dose"><?= e((string) $member['dose']) ?></span>
              <?php if ((int) $member['track_dose_feedback'] === 1): ?>
                <span class="group-feedback-badge">tracks feedback</span>
              <?php endif; ?>
              <form method="post" action="index.php" data-ajax-remove>
                <?= csrf_field() ?>
                <input type="hidden" name="json_response" value="1">
                <input type="hidden" name="action" value="remove_medication_from_group">
                <input type="hidden" name="medication_id" value="<?= e((string) $member['medication_id']) ?>">
                <button type="submit" class="secondary group-remove-btn">&times; Remove</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>

        <?php
          $eligibleToAdd = array_values(array_filter($ungroupedMedications, static fn(array $m): bool =>
              !in_array((int) $m['id'], array_column($group['members'], 'medication_id'), true)
          ));
        ?>
        <?php if ($eligibleToAdd !== []): ?>
          <form class="group-add-med-form" method="post" action="index.php" data-ajax-add>
            <?= csrf_field() ?>
            <input type="hidden" name="json_response" value="1">
            <input type="hidden" name="action" value="add_medication_to_group">
            <input type="hidden" name="group_id" value="<?= e((string) $group['id']) ?>">
            <select name="medication_id" class="group-add-select">
              <option value="">Add a medication&hellip;</option>
              <?php foreach ($eligibleToAdd as $ungrouped): ?>
                <option value="<?= e((string) $ungrouped['id']) ?>" data-name="<?= e((string) $ungrouped['name']) ?>" data-dose="<?= e((string) $ungrouped['dose']) ?>"><?= e((string) $ungrouped['name']) ?> &mdash; <?= e((string) $ungrouped['dose']) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="secondary group-add-btn">Add</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
