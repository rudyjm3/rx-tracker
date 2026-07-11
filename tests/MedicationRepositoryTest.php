<?php

declare(strict_types=1);

require __DIR__ . '/../includes/MedicationRepository.php';

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec("CREATE TABLE medications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL DEFAULT 0,
    profile_id INTEGER NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    name TEXT,
    dose TEXT NOT NULL DEFAULT '',
    instructions TEXT,
    schedule_mode TEXT,
    time_format TEXT DEFAULT '12h',
    interval_hours INTEGER,
    first_dose_time TEXT,
    as_needed INTEGER DEFAULT 0,
    starting_pill_count INTEGER DEFAULT 0,
    pill_count INTEGER DEFAULT 0,
    low_supply_threshold INTEGER DEFAULT 5,
    active INTEGER DEFAULT 1,
    medication_type TEXT NOT NULL DEFAULT 'prescription',
    dose_amount REAL NULL,
    dose_unit TEXT NULL,
    dose_form TEXT NULL,
    inventory_type TEXT NOT NULL DEFAULT 'pills',
    inventory_unit TEXT NOT NULL DEFAULT 'tablets',
    starting_quantity REAL NULL,
    current_quantity REAL NULL,
    quantity_per_dose REAL NOT NULL DEFAULT 1.0
);
CREATE TABLE medication_schedule_times (id INTEGER PRIMARY KEY AUTOINCREMENT, medication_id INTEGER, reminder_time TEXT);
CREATE TABLE dose_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, medication_id INTEGER, scheduled_for_date TEXT, scheduled_time TEXT, status TEXT, note TEXT, taken_at TEXT DEFAULT CURRENT_TIMESTAMP, created_at TEXT DEFAULT CURRENT_TIMESTAMP);");

$repo = new MedicationRepository($db);
$today = (new DateTimeImmutable())->format('Y-m-d');

$repo->createMedication('Fixed Med', '', 'fixed_times', ['08:00:00', '20:00:00'], null, null, false, 2, false, '', 'prescription', 5.0, 'mg', 'tablet', 'pills', 10.0, 1.0);
$repo->createMedication('PRN Med', '', 'interval', [], 4, '06:00:00', true, 3, false, '', 'prescription', 10.0, 'mg', null, 'pills', 12.0, 1.0);

assertSameValue(60, $repo->getMissedGraceMinutes(), 'Default grace period should be 60.');
$repo->setMissedGraceMinutes(30);
assertSameValue(30, $repo->getMissedGraceMinutes(), 'Grace period should update to 30.');

$all = $repo->activeMedications();
assertSameValue(2, count($all), 'Two meds expected');
assertSameValue(10.0, (float) $all[0]['starting_quantity'], 'Create should set starting_quantity');
assertSameValue(10.0, (float) $all[0]['current_quantity'], 'Create should set current_quantity = starting');
assertSameValue('prescription', (string) $all[0]['medication_type'], 'Default medication_type should be prescription');
assertSameValue('pills', (string) $all[0]['inventory_type'], 'Default inventory_type should be pills');
assertSameValue('tablets', (string) $all[0]['inventory_unit'], 'Unit for pills should be tablets');
assertSameValue(5.0, (float) $all[0]['dose_amount'], 'Dose amount should be stored');
assertSameValue('mg', (string) $all[0]['dose_unit'], 'Dose unit should be stored');
assertSameValue('tablet', (string) $all[0]['dose_form'], 'Dose form should be stored');

$fixedId = (int) $all[0]['id'];
$repo->updateMedication($fixedId, 'Fixed Med Updated', '', 'fixed_times', ['09:00:00'], null, null, false, 2, false, '', 'prescription', 5.0, 'mg', 'tablet', 'pills', 9.0, 1.0);
$edited = $repo->findMedication($fixedId);
assertSameValue('Fixed Med Updated', $edited['name'], 'Edit should update name');
assertSameValue('12h', $edited['time_format'], 'time_format should always be 12h');
assertSameValue(9.0, (float) $edited['current_quantity'], 'Update with a changed starting quantity should re-baseline current_quantity');

// Medication type validation
$threw = false;
try {
    $repo->createMedication('Bad Type', '', 'fixed_times', ['08:00:00'], null, null, false, 0, false, '', 'invalid_type');
} catch (RuntimeException) {
    $threw = true;
}
assertSameValue(true, $threw, 'Invalid medication_type should throw RuntimeException.');

// Inventory type validation
$threw = false;
try {
    $repo->createMedication('Bad Inv', '', 'fixed_times', ['08:00:00'], null, null, false, 0, false, '', 'prescription', null, null, null, 'unknown_type');
} catch (RuntimeException) {
    $threw = true;
}
assertSameValue(true, $threw, 'Invalid inventory_type should throw RuntimeException.');

// OTC medication type round-trip
$repo->createMedication('Vitamin C', '', 'fixed_times', ['09:00:00'], null, null, false, 0, false, '', 'otc', 500.0, 'mg', 'tablet', 'pills', 60.0, 1.0);
$allAfterOtc = $repo->activeMedications();
$vitC = array_values(array_filter($allAfterOtc, static fn(array $r): bool => $r['name'] === 'Vitamin C'))[0] ?? null;
assertSameValue('otc', (string) $vitC['medication_type'], 'OTC medication_type should round-trip');

// Supplement inventory type
$repo->createMedication('Fish Oil', '', 'fixed_times', ['08:00:00'], null, null, false, 0, false, '', 'supplement', 1000.0, 'mg', 'capsule', 'pills', 90.0, 1.0);
$allAfterSup = $repo->activeMedications();
$fishOil = array_values(array_filter($allAfterSup, static fn(array $r): bool => $r['name'] === 'Fish Oil'))[0] ?? null;
assertSameValue('supplement', (string) $fishOil['medication_type'], 'Supplement medication_type should round-trip');

// Liquid inventory type
$repo->createMedication('Amoxicillin Liquid', '', 'fixed_times', ['08:00:00', '20:00:00'], null, null, false, 0, false, '', 'prescription', 250.0, 'mg', 'liquid', 'liquid', 118.294, 10.0);
$allAfterLiquid = $repo->activeMedications();
$liquid = array_values(array_filter($allAfterLiquid, static fn(array $r): bool => $r['name'] === 'Amoxicillin Liquid'))[0] ?? null;
assertSameValue('liquid', (string) $liquid['inventory_type'], 'Liquid inventory_type should round-trip');
assertSameValue('mL', (string) $liquid['inventory_unit'], 'Liquid unit should be mL');
assertSameValue(118.294, round((float) $liquid['current_quantity'], 3), 'Liquid quantity stored in mL');

// Dose deduction uses quantity_per_dose
$schedule = $repo->todaySchedule($today);
assertSameValue(true, count($schedule) >= 2, 'Schedule should include generated rows');

$repo->postponeDose($fixedId, $today, '09:00:00', 5);
$postpone = $repo->activePostponeForDose($fixedId, $today, '09:00:00');
assertSameValue(true, is_string($postpone) && $postpone !== '', 'Postpone should create active reminder.');

$repo->recordDoseStatus($fixedId, $today, '09:00:00', 'taken', 'ok');
$recent = $repo->recentLogs();
assertSameValue('taken', $recent[0]['status'], 'Taken log should save');
$afterTaken = $repo->findMedication($fixedId);
assertSameValue(8.0, (float) $afterTaken['current_quantity'], 'current_quantity should decrement by quantity_per_dose after taken dose');
assertSameValue(null, $repo->activePostponeForDose($fixedId, $today, '09:00:00'), 'Postpone should clear on taken.');

// Quantity_per_dose > 1 deduction
$repo->createMedication('Double Dose Med', '', 'fixed_times', ['08:00:00'], null, null, false, 0, false, '', 'prescription', null, null, null, 'pills', 10.0, 2.0);
$allForDouble = $repo->activeMedications();
$doubleMed = array_values(array_filter($allForDouble, static fn(array $r): bool => $r['name'] === 'Double Dose Med'))[0] ?? null;
$doubleId = (int) $doubleMed['id'];
$repo->recordDoseStatus($doubleId, $today, '08:00:00', 'taken', '');
$afterDouble = $repo->findMedication($doubleId);
assertSameValue(8.0, (float) $afterDouble['current_quantity'], 'Deduction should use quantity_per_dose=2.');

$prnId = (int) $all[1]['id'];
$repo->logDoseNow($prnId, 'PRN now');
$threw = false;
try {
    $repo->logDoseNow($prnId, 'too soon');
} catch (RuntimeException $exception) {
    $threw = true;
}
assertSameValue(true, $threw, 'Interval medication should block too-early dose.');

// An interval medication logged in two scheduled slots per day (e.g. via two
// medication groups exactly interval_hours apart) should not have its later
// on-time slot blocked just because the earlier slot was logged a minute late.
$repo->createMedication('Twice Daily Interval', '', 'interval', [], 12, '07:00:00', false, 0, false, '', 'prescription', 3.0, 'mg', 'tablet', 'pills', 39.0, 1.0);
$allTwice = $repo->activeMedications();
$twiceId = (int) array_values(array_filter($allTwice, static fn(array $r): bool => $r['name'] === 'Twice Daily Interval'))[0]['id'];
$repo->recordDoseStatus($twiceId, $today, '07:00:00', 'taken', '', null, null, $today . ' 07:01:00');
$eveningThrew = false;
try {
    $repo->recordDoseStatus($twiceId, $today, '19:00:00', 'taken', '');
} catch (RuntimeException $exception) {
    $eveningThrew = true;
}
assertSameValue(false, $eveningThrew, 'On-time evening slot should not be blocked by AM dose logged a minute late.');

$repo->createMedication('Missed Med', '', 'fixed_times', ['00:00:00'], null, null, false, 1, false, '', 'prescription', null, null, null, 'pills', 5.0, 1.0);
$allAfter = $repo->activeMedications();
$missedMedId = (int) array_values(array_filter($allAfter, static fn(array $row): bool => (string) $row['name'] === 'Missed Med'))[0]['id'];
$repo->setMissedGraceMinutes(30);
$repo->finalizeMissedDoses(new DateTimeImmutable('today 23:59:00'), 30);
$todayRows = $repo->todaySchedule($today);
$missedRow = array_values(array_filter($todayRows, static fn(array $row): bool => (int) $row['medication_id'] === $missedMedId))[0] ?? null;
assertSameValue('missed', $missedRow['status'] ?? null, 'Overdue dose should auto-mark missed.');

// ── Inventory adjustment, un-take restore, and edit-save preservation ─────────

$repo->createMedication('Adjust Med', '', 'fixed_times', ['08:00:00'], null, null, false, 4, false, '', 'prescription', null, null, null, 'pills', 30.0, 1.0);
$allAdjust = $repo->activeMedications();
$adjustId = (int) array_values(array_filter($allAdjust, static fn(array $r): bool => $r['name'] === 'Adjust Med'))[0]['id'];

$repo->recordDoseStatus($adjustId, $today, '08:00:00', 'taken', '');
$afterTake = $repo->findMedication($adjustId);
assertSameValue(29.0, (float) $afterTake['current_quantity'], 'Taken dose should deduct one.');

// Manual adjustment corrects the count without touching starting_quantity.
$repo->adjustQuantity($adjustId, 27.0, 'physical recount');
$afterAdjust = $repo->findMedication($adjustId);
assertSameValue(27.0, (float) $afterAdjust['current_quantity'], 'Adjustment should set current_quantity to the corrected count.');
assertSameValue(30.0, (float) $afterAdjust['starting_quantity'], 'Adjustment must not change starting_quantity.');

$adjustRows = $db->query("SELECT amount, pills_on_hand, entry_type FROM medication_refills WHERE medication_id = {$adjustId}")->fetchAll();
assertSameValue(1, count($adjustRows), 'Adjustment should log one history row.');
assertSameValue('adjustment', (string) $adjustRows[0]['entry_type'], 'Adjustment row should be flagged as adjustment.');
assertSameValue(-2.0, (float) $adjustRows[0]['amount'], 'Adjustment row should store the signed delta.');
assertSameValue(27.0, (float) $adjustRows[0]['pills_on_hand'], 'Adjustment row should store the resulting count.');

// Adjusting to the current count is a no-op (no history row).
$repo->adjustQuantity($adjustId, 27.0, 'no change');
$adjustCount = (int) $db->query("SELECT COUNT(*) FROM medication_refills WHERE medication_id = {$adjustId}")->fetchColumn();
assertSameValue(1, $adjustCount, 'No-op adjustment should not log a history row.');

// Refill-only stats and "last refill" ignore adjustments.
$statsYear = (int) (new DateTimeImmutable())->format('Y');
$adjustStats = $repo->refillSummaryStats($adjustId, $statsYear);
assertSameValue(0, $adjustStats['count'], 'Refill stats should not count adjustments.');
assertSameValue(null, $repo->lastRefillForMedication($adjustId), 'Last refill should ignore adjustments.');

// Refill history list includes the adjustment (with no days-since-prev).
$monthStart = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
$monthEnd = (new DateTimeImmutable('last day of this month'))->format('Y-m-d');
$historyRows = $repo->refillsForMonth($adjustId, $monthStart, $monthEnd);
assertSameValue(1, count($historyRows), 'History should include the adjustment.');
assertSameValue('adjustment', (string) $historyRows[0]['entry_type'], 'History row should carry entry_type.');
assertSameValue(null, $historyRows[0]['days_since_prev'], 'Adjustments should not report days since previous refill.');

// A plain edit-save (starting quantity unchanged) must not reset the count.
$repo->updateMedication($adjustId, 'Adjust Med', '', 'fixed_times', ['08:00:00'], null, null, false, 4, false, '', 'prescription', null, null, null, 'pills', 30.0, 1.0);
$afterPlainSave = $repo->findMedication($adjustId);
assertSameValue(27.0, (float) $afterPlainSave['current_quantity'], 'Plain save should preserve current_quantity.');
assertSameValue(30.0, (float) $afterPlainSave['starting_quantity'], 'Plain save should preserve starting_quantity.');

// Reverting a taken dose restores the deducted amount; re-taking deducts again.
$repo->recordDoseStatus($adjustId, $today, '08:00:00', 'skipped', 'logged by mistake');
$afterUntake = $repo->findMedication($adjustId);
assertSameValue(28.0, (float) $afterUntake['current_quantity'], 'Taken -> skipped should restore the deducted dose.');
$repo->recordDoseStatus($adjustId, $today, '08:00:00', 'taken', '');
$afterRetake = $repo->findMedication($adjustId);
assertSameValue(27.0, (float) $afterRetake['current_quantity'], 'Skipped -> taken should deduct again.');

// Deliberately changing the starting quantity still re-baselines the count.
$repo->updateMedication($adjustId, 'Adjust Med', '', 'fixed_times', ['08:00:00'], null, null, false, 4, false, '', 'prescription', null, null, null, 'pills', 50.0, 1.0);
$afterRebase = $repo->findMedication($adjustId);
assertSameValue(50.0, (float) $afterRebase['current_quantity'], 'Changed starting quantity should re-baseline current_quantity.');
assertSameValue(50.0, (float) $afterRebase['starting_quantity'], 'Changed starting quantity should be stored.');

// Refills are flagged as refill entries and still add to the count.
$repo->logRefill($adjustId, $today, 10.0, 'pharmacy');
$afterRefill = $repo->findMedication($adjustId);
assertSameValue(60.0, (float) $afterRefill['current_quantity'], 'Refill should add to current_quantity.');
assertSameValue(10.0, (float) $afterRefill['starting_quantity'], 'Refill should set starting_quantity to the refill amount.');
$refillStats = $repo->refillSummaryStats($adjustId, $statsYear);
assertSameValue(1, $refillStats['count'], 'Refill stats should count real refills.');
$lastRefill = $repo->lastRefillForMedication($adjustId);
assertSameValue(10.0, (float) $lastRefill['amount'], 'Last refill should surface the refill amount.');

// Once a refill exists, edit-save owns neither starting nor current quantity.
$repo->updateMedication($adjustId, 'Adjust Med', '', 'fixed_times', ['08:00:00'], null, null, false, 4, false, '', 'prescription', null, null, null, 'pills', 99.0, 1.0);
$afterLockedSave = $repo->findMedication($adjustId);
assertSameValue(10.0, (float) $afterLockedSave['starting_quantity'], 'Save after a refill should not change starting_quantity.');
assertSameValue(60.0, (float) $afterLockedSave['current_quantity'], 'Save after a refill should not reset current_quantity.');

// Adjustments still work after a refill exists.
$repo->adjustQuantity($adjustId, 58.0, 'two pills lost');
$afterPostRefillAdjust = $repo->findMedication($adjustId);
assertSameValue(58.0, (float) $afterPostRefillAdjust['current_quantity'], 'Adjustment after refill should set the corrected count.');
assertSameValue(10.0, (float) $afterPostRefillAdjust['starting_quantity'], 'Adjustment after refill must not change starting_quantity.');

// ── Reverting restores what was actually deducted, not the current config ─────

$repo->createMedication('Drift Med', '', 'fixed_times', ['06:00:00'], null, null, false, 0, false, '', 'prescription', null, null, null, 'pills', 20.0, 1.0);
$allDrift = $repo->activeMedications();
$driftId = (int) array_values(array_filter($allDrift, static fn(array $r): bool => $r['name'] === 'Drift Med'))[0]['id'];

$repo->recordDoseStatus($driftId, $today, '06:00:00', 'taken', '');
assertSameValue(19.0, (float) $repo->findMedication($driftId)['current_quantity'], 'Dose taken at qpd=1 should deduct 1.');
$storedDeducted = $db->query("SELECT deducted_quantity FROM dose_logs WHERE medication_id = {$driftId}")->fetchColumn();
assertSameValue(1.0, (float) $storedDeducted, 'Log should record the amount actually deducted.');

// Double quantity_per_dose AFTER the dose was taken, then revert the old log.
$repo->updateMedication($driftId, 'Drift Med', '', 'fixed_times', ['06:00:00'], null, null, false, 0, false, '', 'prescription', null, null, null, 'pills', 20.0, 2.0);
$repo->recordDoseStatus($driftId, $today, '06:00:00', 'skipped', 'wrong entry');
assertSameValue(20.0, (float) $repo->findMedication($driftId)['current_quantity'], 'Revert should restore the original 1, not the new qpd of 2.');
$clearedDeducted = $db->query("SELECT deducted_quantity FROM dose_logs WHERE medication_id = {$driftId}")->fetchColumn();
assertSameValue(null, $clearedDeducted, 'Revert should clear the stored deducted amount.');

// Re-taking uses (and records) the new configuration.
$repo->recordDoseStatus($driftId, $today, '06:00:00', 'taken', '');
assertSameValue(18.0, (float) $repo->findMedication($driftId)['current_quantity'], 'Re-take should deduct the new qpd of 2.');

// Out-of-stock: deducting from 0 stores 0, so a revert does not inflate the count.
$repo->createMedication('Empty Med', '', 'fixed_times', ['06:30:00'], null, null, false, 0, false, '', 'prescription', null, null, null, 'pills', 0.0, 1.0);
$allEmpty = $repo->activeMedications();
$emptyId = (int) array_values(array_filter($allEmpty, static fn(array $r): bool => $r['name'] === 'Empty Med'))[0]['id'];
$repo->recordDoseStatus($emptyId, $today, '06:30:00', 'taken', '');
assertSameValue(0.0, (float) $repo->findMedication($emptyId)['current_quantity'], 'Deducting from 0 stays 0.');
$repo->recordDoseStatus($emptyId, $today, '06:30:00', 'skipped', '');
assertSameValue(0.0, (float) $repo->findMedication($emptyId)['current_quantity'], 'Reverting a dose that deducted nothing restores nothing.');

echo "MedicationRepository tests passed.\n";
