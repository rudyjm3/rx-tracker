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
    $monthParam = (string) ($_GET['m'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/', $monthParam)) {
        $monthParam = date('Y-m');
    }
    $monthStart = $monthParam . '-01';
    $calMonthDt = new DateTimeImmutable($monthStart);
    $monthEnd = $calMonthDt->modify('last day of this month')->format('Y-m-d');
    $calendarMarkers = $repository->calendarMarkersForMonth($monthStart, $monthEnd);
    $todayForBackfill = date('Y-m-d');
    $missingPastDates = [];
    $daysInMonthForBackfill = (int) $calMonthDt->modify('last day of this month')->format('j');
    for ($bfDay = 1; $bfDay <= $daysInMonthForBackfill; $bfDay++) {
        $bfDate = sprintf('%s-%02d', $monthParam, $bfDay);
        if ($bfDate < $todayForBackfill && !isset($calendarMarkers[$bfDate])) {
            $missingPastDates[] = $bfDate;
        }
    }
    if ($missingPastDates !== []) {
        $repository->backfillMissedDosesForDates($missingPastDates, new DateTimeImmutable('now'), $graceMinutes);
        $calendarMarkers = $repository->calendarMarkersForMonth($monthStart, $monthEnd);
    }
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
            'logId'         => (int) $log['id'],
            'medicationId'  => $cdMedId,
            'scheduledDate' => $cdDate,
            'scheduledTime' => (string) $log['scheduled_time'],
            'takenAt'       => (string) $log['taken_at'],
            'note'          => (string) $log['note'],
            'painLevel'     => $log['pain_level'] !== null ? (int) $log['pain_level'] : null,
            'moodLevel'     => $log['mood_level'] !== null ? (int) $log['mood_level'] : null,
            'isActive'      => (bool) $log['active'],
            'displayTime'   => to12h((string) $log['scheduled_time']),
            'status'        => $cdStatus,
            'isLate'        => $cdLateMin !== null,
            'lateLabel'     => $cdLateMin !== null ? formatLate($cdLateMin) : null,
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
      <button type="button" class="modal-close-btn" data-close-calendar-day-modal aria-label="Close">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
    </div>
    <div class="modal-scroll" data-calendar-day-modal-body></div>
  </div>
</div>

</body>
</html>
<?php exit; ?>

