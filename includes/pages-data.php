<?php

declare(strict_types=1);

// Shared partial: per-request data prep (today's schedule, adherence, hero next-dose,
// notifications, low-supply flags) used by the dashboard/medications/pain-tracking/
// mood-wellbeing/settings/calendar/export/help pages.
/** @var MedicationRepository $repository */
/** @var string $today */
/** @var string $currentTime */
$graceMinutes = $repository->getMissedGraceMinutes();
$snoozeMinutes = $repository->getSnoozeMinutes();
$moodChartScheme = $repository->getMoodChartScheme();
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

$draftId = (int) ($_GET['draft'] ?? 0);
$draftMedication = $draftId > 0 ? $repository->findMedicationDraft($draftId) : null;
$medicationDrafts = $repository->listMedicationDrafts();

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

