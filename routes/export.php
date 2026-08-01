<?php

declare(strict_types=1);

/** @var MedicationRepository $repository */
/** @var AuthService $auth */
/** @var string $today */
/** @var string $currentTime */
/** @var string $page */
/** @var string|null $error */
/** @var string|null $notice */

require __DIR__ . '/../includes/pages-data.php';
require __DIR__ . '/../includes/pages-shell-top.php';
?>
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
      Used for the report below.
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
      <h2>Doctor Visit Report</h2>
    </div>
    <p style="color:var(--rx-text-muted);margin-bottom:1.25rem;font-size:0.9rem;">
      Generate a branded PDF summary of your medication history, adherence, pain trends, side effects, and (optionally) mood trends — ready to share with your doctor.
    </p>
    <form method="post" action="index.php" class="stacked-form" data-export-form="doctor-visit">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="generate_doctor_visit_report">
      <input type="hidden" name="download_token" data-download-token value="">
      <input type="hidden" name="report_start" data-report-start-mirror value="<?= e($reportStart) ?>">
      <input type="hidden" name="report_end" data-report-end-mirror value="<?= e($reportEnd) ?>">

      <div class="notification-toggle-row" style="margin-bottom:1.25rem;">
        <label class="toggle-control" for="include-mood-toggle">
          <input type="checkbox" name="include_mood" id="include-mood-toggle">
          <span class="toggle-slider" aria-hidden="true"></span>
          <span class="toggle-label">Include Mood &amp; Wellbeing tracking</span>
        </label>
      </div>

      <?php if ($trackedMedications !== []): ?>
      <fieldset style="border:1px solid var(--rx-border);border-radius:var(--rx-radius-sm);padding:1rem 1.25rem;margin-bottom:1.25rem;">
        <legend style="padding:0 0.5rem;font-weight:600;color:var(--rx-navy);">Pain-tracked medications</legend>
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
            <?php endif; ?>
            <input type="hidden" name="chart_days[<?= $mId ?>]" value="<?= $defR ?>">
          </div>
        <?php endforeach; ?>
      </fieldset>
      <?php else: ?>
      <p style="font-size:0.85rem;color:var(--rx-text-muted);margin-bottom:1.25rem;font-style:italic;">
        No medications are currently tracking pain levels.
      </p>
      <?php endif; ?>

      <div data-mood-fieldset>
        <?php if ($moodTrackedMedicationsExport !== []): ?>
        <fieldset style="border:1px solid var(--rx-border);border-radius:var(--rx-radius-sm);padding:1rem 1.25rem;margin-bottom:1.25rem;">
          <legend style="padding:0 0.5rem;font-weight:600;color:var(--rx-navy);">Mood-tracked medications</legend>
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
              <?php endif; ?>
              <input type="hidden" name="mood_chart_days[<?= $mmId ?>]" value="<?= $mDefR ?>">
            </div>
          <?php endforeach; ?>
        </fieldset>
        <?php else: ?>
        <p style="font-size:0.85rem;color:var(--rx-text-muted);margin-bottom:1.25rem;font-style:italic;">
          No medications are currently tracking mood levels.
        </p>
        <?php endif; ?>
      </div>

      <button type="submit" class="button-solo" data-export-btn>
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

