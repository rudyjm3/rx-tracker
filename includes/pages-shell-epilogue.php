<?php
// Shared partial: low-supply banner, PWA install banner, and the dashboard "today's
// schedule" / "today's history" grid (the grid only renders on the dashboard page).
// Used by dashboard/medications/pain-tracking/mood-wellbeing, after their own content.
// Expects the variables computed by includes/pages-data.php to be in scope, plus $page.
?>
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
                <form method="post" action="index.php"><?= csrf_field() ?><input type="hidden" name="action" value="mark_dose"><input type="hidden" name="medication_id" value="<?= e((string) $dose['medication_id']) ?>"><input type="hidden" name="scheduled_date" value="<?= e($today) ?>"><input type="hidden" name="scheduled_time" value="<?= e((string) $dose['reminder_time']) ?>:00"><input type="hidden" name="status" value="taken"><?php if ($dose['group_id'] !== null): ?><input type="hidden" name="group_id" value="<?= e((string) $dose['group_id']) ?>"><?php endif; ?><button type="submit" class="btn-take" data-take-dose data-medication-id="<?= e((string) $dose['medication_id']) ?>" data-medication-name="<?= e((string) $dose['name']) ?>" data-medication-dose="<?= e(formattedDose($dose)) ?>" data-scheduled-date="<?= e($today) ?>" data-scheduled-time="<?= e((string) $dose['reminder_time']) ?>:00" data-track-dose-feedback="<?= (($dose['feedback_type'] ?? ($dose['track_dose_feedback'] ? 'pain' : 'none')) !== 'none') ? '1' : '0' ?>" data-feedback-type="<?= e((string) ($dose['feedback_type'] ?? ($dose['track_dose_feedback'] ? 'pain' : 'none'))) ?>" data-dose-status="<?= e((string) ($dose['status'] ?? '')) ?>" data-grace-minutes="<?= e((string) $graceMinutes) ?>" data-postponed-until="<?= $rawPostponedUntil !== null ? e($rawPostponedUntil) : '' ?>"<?= $isCompleted ? ' disabled' : '' ?>>Take</button></form>
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
        <a href="index.php?page=pain-tracking&open=log" class="quick-action-row">
          <span class="quick-action-icon quick-action-icon--log"><i class="fa-solid fa-chart-line" aria-hidden="true"></i></span>
          <span class="quick-action-label">Pain tracking</span>
          <i class="fa-solid fa-chevron-right quick-action-chevron" aria-hidden="true"></i>
        </a>
        <a href="index.php?page=mood-wellbeing&open=log" class="quick-action-row">
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
              <?php if (isset($log['mood_level']) && $log['mood_level'] !== null): ?>
                <?php $ml = (int) $log['mood_level']; $moodMod = $ml <= 3 ? 'poor' : ($ml <= 6 ? 'fair' : ($ml <= 8 ? 'good' : 'great')); ?>
                <span class="history-mood-label">Mood Score</span> <span class="history-mood-badge history-mood-badge--<?= $moodMod ?>"><?= e((string) $log['mood_level']) ?>/10</span>
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
