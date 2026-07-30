<?php

declare(strict_types=1);

require __DIR__ . '/../includes/MedicationRepository.php';

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function assertFloatEquals(float $expected, float $actual, string $message, float $epsilon = 0.0005): void
{
    if (abs($expected - $actual) > $epsilon) {
        throw new RuntimeException($message . "\nExpected: {$expected}\nActual: {$actual}");
    }
}

/**
 * Plays a 30-day timeline of realistic usage through the real repository
 * methods (take/skip/miss, revert, backfill, refill, adjustment, dose-amount
 * edits, PRN interval gating, medication groups, and double-submits) and
 * checks the resulting current_quantity against a value this script tracks
 * independently, so any drift — however it's introduced — fails loudly.
 * Regression test for a production report of a pill count reading 3 lower
 * than the physical count.
 */

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

$anchor = new DateTimeImmutable('today');
$dayDate = static fn (int $offset): string => $anchor->modify($offset . ' days')->format('Y-m-d');

function findByName(array $rows, string $name): array
{
    $match = array_values(array_filter($rows, static fn (array $r): bool => $r['name'] === $name));
    if ($match === []) {
        throw new RuntimeException("Medication not found: {$name}");
    }
    return $match[0];
}

// ── Create the 7 medications under test ─────────────────────────────────────

$repo->createMedication('Full Adherence Twice Daily', '', 'fixed_times', ['08:00:00', '20:00:00'], null, null, false, 10, false, '', 'prescription', 5.0, 'mg', 'tablet', 'pills', 100.0, 1.0);
$repo->createMedication('Mixed Adherence With Backfill', '', 'fixed_times', ['09:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 100.0, 1.0);
$repo->createMedication('Revert Churn Med', '', 'fixed_times', ['07:30:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 100.0, 1.0);
$repo->createMedication('PRN Pain Med', '', 'interval', [], 6, '08:00:00', true, 5, false, '', 'prescription', null, null, null, 'pills', 100.0, 1.0);
$repo->createMedication('Refill Adjust Med', '', 'fixed_times', ['10:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 40.0, 1.0);
$repo->createMedication('Group Overlap Med', '', 'fixed_times', ['08:15:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 100.0, 1.0);
$repo->createMedication('Double Submit Med', '', 'fixed_times', ['06:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 50.0, 1.0);
$repo->createMedication('Missed Slot Fallback Med', '', 'fixed_times', ['05:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 20.0, 1.0);

$all = $repo->activeMedications();
$id1 = (int) findByName($all, 'Full Adherence Twice Daily')['id'];
$id2 = (int) findByName($all, 'Mixed Adherence With Backfill')['id'];
$id3 = (int) findByName($all, 'Revert Churn Med')['id'];
$id4 = (int) findByName($all, 'PRN Pain Med')['id'];
$id5 = (int) findByName($all, 'Refill Adjust Med')['id'];
$id6 = (int) findByName($all, 'Group Overlap Med')['id'];
$id7 = (int) findByName($all, 'Double Submit Med')['id'];
$id8 = (int) findByName($all, 'Missed Slot Fallback Med')['id'];

// Medication 6 belongs to a group whose scheduled_time matches its own slot —
// confirms todaySchedule() doesn't list the same medication/time twice.
$groupId6 = $repo->createGroup('Overlap Group', '08:15:00');
$repo->addMedicationToGroup($groupId6, $id6);
$overlapRows = array_values(array_filter($repo->todaySchedule($dayDate(0)), static fn (array $r): bool => (int) $r['medication_id'] === $id6));
assertSameValue(1, count($overlapRows), 'Group-overlapping medication should appear exactly once in todaySchedule.');

// ── Expected running balances, tracked independently of the DB ─────────────

$expected1 = 100.0;
$expected2 = 100.0;
$expected3 = 100.0;
$qtyPerDose3 = 1.0;
$deductedByDate3 = [];
$driftDate3 = null;
$expected4 = 100.0;
$expected5 = 40.0;
$expected6 = 100.0;
$expected7 = 50.0;
$expected8 = 20.0;

$plan2 = ['taken', 'taken', 'taken', 'skipped', 'missed', 'taken', 'missed_backfill'];
$missedBackfillDates2 = [];

for ($i = 0; $i < 30; $i++) {
    $offset = -29 + $i;
    $date = $dayDate($offset);

    // --- Medication 1: full adherence, twice daily ---
    foreach (['08:00:00', '20:00:00'] as $time) {
        $repo->recordDoseStatus($id1, $date, $time, 'taken', '', null, null, $date . ' ' . $time);
        $expected1 -= 1.0;
    }

    // --- Medication 2: mixed adherence, some auto-missed, some backfilled later ---
    $action2 = $plan2[$i % count($plan2)];
    if ($action2 === 'taken') {
        $repo->recordDoseStatus($id2, $date, '09:00:00', 'taken', '', null, null, $date . ' 09:00:00');
        $expected2 -= 1.0;
    } elseif ($action2 === 'skipped') {
        $repo->recordDoseStatus($id2, $date, '09:00:00', 'skipped', 'skipped by user');
    } elseif ($action2 === 'missed_backfill') {
        $missedBackfillDates2[] = $date;
    }
    // 'missed' entries are deliberately left unresolved for finalizeMissedDoses below.

    // --- Medication 3: revert churn, with a mid-timeline quantity_per_dose edit ---
    if ($i === 15) {
        // User edits the dose amount partway through the month.
        $repo->updateMedication($id3, 'Revert Churn Med', '', 'fixed_times', ['07:30:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 100.0, 2.0);
        $qtyPerDose3 = 2.0;
    }
    $repo->recordDoseStatus($id3, $date, '07:30:00', 'taken', '', null, null, $date . ' 07:30:00');
    $expected3 -= $qtyPerDose3;
    $deductedByDate3[$date] = $qtyPerDose3;
    if ($i === 5) {
        $driftDate3 = $date;
    }
    if ($i === 15) {
        // Revert a dose taken before the edit — must restore what was actually
        // deducted back then (1.0), not the newly configured 2.0.
        $repo->recordDoseStatus($id3, $driftDate3, '07:30:00', 'skipped', 'reverted for drift test');
        $expected3 += $deductedByDate3[$driftDate3];
        // Re-taking it now should use the new configured quantity (2.0).
        $repo->recordDoseStatus($id3, $driftDate3, '07:30:00', 'taken', '', null, null, $driftDate3 . ' 07:30:05');
        $expected3 -= $qtyPerDose3;
        $deductedByDate3[$driftDate3] = $qtyPerDose3;
    }

    // --- Medication 4: PRN interval medication ---
    $repo->recordDoseStatus($id4, $date, '08:00:00', 'taken', '', null, null, $date . ' 08:00:00');
    $expected4 -= 1.0;
    if ($i === 29) {
        // Today: a too-soon second dose must be rejected and must not deduct.
        $threw4 = false;
        try {
            $repo->recordDoseStatus($id4, $date, '09:00:00', 'taken', '');
        } catch (RuntimeException) {
            $threw4 = true;
        }
        assertSameValue(true, $threw4, 'PRN medication should reject a too-soon second dose.');
    }

    // --- Medication 5: refill + manual adjustment mid-timeline ---
    if ($i === 15) {
        $repo->logRefill($id5, $date, 30.0, 'pharmacy pickup');
        $expected5 += 30.0;
    }
    if ($i === 22) {
        $newCount5 = $expected5 - 2.0;
        $repo->adjustQuantity($id5, $newCount5, 'physical recount found 2 fewer');
        $expected5 = $newCount5;
    }
    $repo->recordDoseStatus($id5, $date, '10:00:00', 'taken', '', null, null, $date . ' 10:00:00');
    $expected5 -= 1.0;

    // --- Medication 6: group-overlapping medication ---
    $repo->recordDoseStatus($id6, $date, '08:15:00', 'taken', '', null, $groupId6, $date . ' 08:15:00');
    $expected6 -= 1.0;

    // --- Medication 7: double-submit and cron-idempotency stress ---
    if ($i === 10) {
        $repo->recordDoseStatus($id7, $date, '06:00:00', 'taken', '', null, null, $date . ' 06:00:00');
        $expected7 -= 1.0;
        // Rapid duplicate submit for the same slot must not deduct again.
        $repo->recordDoseStatus($id7, $date, '06:00:00', 'taken', 'duplicate submit', null, null, $date . ' 06:00:01');
    } elseif ($i === 20) {
        // Left unresolved on purpose; finalizeMissedDoses below marks it missed.
    } elseif ($i === 29) {
        $repo->logDoseNow($id7, 'today dose');
        $expected7 -= 1.0;
        $threw7 = false;
        try {
            $repo->logDoseNow($id7, 'duplicate submit');
        } catch (RuntimeException) {
            $threw7 = true;
        }
        assertSameValue(true, $threw7, 'Duplicate logDoseNow for an already-logged slot should be rejected.');
    } else {
        $repo->recordDoseStatus($id7, $date, '06:00:00', 'taken', '', null, null, $date . ' 06:00:00');
        $expected7 -= 1.0;
    }

    // --- Medication 8: never logged; every day's slot is left for the cron
    // sweep below to auto-mark missed. Regression coverage for the non-JS
    // fallback form, which calls logDoseNow() with no scheduled_time.

    // --- Simulated nightly cron sweep for missed doses (all medications) ---
    $cutoff = new DateTimeImmutable($date . ' 23:59:00');
    $repo->finalizeMissedDoses($cutoff, 30);
    if ($i === 20) {
        // Simulate an overlapping/duplicate cron run for the same day.
        $repo->finalizeMissedDoses($cutoff, 30);
    }
}

// Today's slot for medication 8 was just auto-marked missed by the cron sweep
// above. The non-JS fallback form logs a dose with no scheduled_time, relying
// on logDoseNow()'s auto-detect (bestUnloggedSlotTime()) to find it; a missed
// slot must still be selectable there, not treated as already logged.
$repo->logDoseNow($id8, 'fallback log, no scheduled_time');
$expected8 -= 1.0;

// Backfill medication 2's deliberately-missed-then-later-logged slots.
foreach ($missedBackfillDates2 as $backfillDate) {
    $repo->recordDoseStatus($id2, $backfillDate, '09:00:00', 'taken', 'backfilled', null, null, $backfillDate . ' 21:00:00');
    $expected2 -= 1.0;
}

// ── Reconciliation ───────────────────────────────────────────────────────────

$current1 = (float) $repo->findMedication($id1)['current_quantity'];
assertFloatEquals($expected1, $current1, 'Medication 1 (full adherence) current_quantity drifted from expectation.');
$deductedSum1 = (float) $db->query("SELECT COALESCE(SUM(deducted_quantity), 0) FROM dose_logs WHERE medication_id = {$id1} AND status = 'taken'")->fetchColumn();
assertFloatEquals(60.0, $deductedSum1, 'Medication 1 should have exactly 60 deducted-dose entries (30 days x 2/day).');
assertFloatEquals(100.0 - $deductedSum1, $current1, 'Medication 1 ledger (starting - deducted) should equal current_quantity.');
$logCount1 = (int) $db->query("SELECT COUNT(*) FROM dose_logs WHERE medication_id = {$id1}")->fetchColumn();
assertSameValue(60, $logCount1, 'Medication 1 should have exactly one dose_logs row per scheduled slot, no duplicates.');

$current2 = (float) $repo->findMedication($id2)['current_quantity'];
assertFloatEquals($expected2, $current2, 'Medication 2 (mixed adherence + backfill) current_quantity drifted from expectation.');
// 'missed' plan entries are deliberately left permanently missed; only
// 'missed_backfill' entries get retroactively logged taken afterward.
$expectedPermanentlyMissed2 = 0;
for ($i = 0; $i < 30; $i++) {
    if ($plan2[$i % count($plan2)] === 'missed') {
        $expectedPermanentlyMissed2++;
    }
}
$missedCount2 = (int) $db->query("SELECT COUNT(*) FROM dose_logs WHERE medication_id = {$id2} AND status = 'missed'")->fetchColumn();
assertSameValue($expectedPermanentlyMissed2, $missedCount2, 'Only the deliberately-permanent missed slots should remain missed; backfilled ones must have flipped to taken.');
$backfilledStillMissed2 = 0;
foreach ($missedBackfillDates2 as $backfillDate) {
    $status = $db->query("SELECT status FROM dose_logs WHERE medication_id = {$id2} AND scheduled_for_date = '{$backfillDate}' AND scheduled_time = '09:00:00'")->fetchColumn();
    if ($status !== 'taken') {
        $backfilledStillMissed2++;
    }
}
assertSameValue(0, $backfilledStillMissed2, 'Every backfilled slot should have flipped from missed to taken.');
$missedDeducted2 = $db->query("SELECT COUNT(*) FROM dose_logs WHERE medication_id = {$id2} AND status IN ('missed', 'skipped') AND deducted_quantity IS NOT NULL")->fetchColumn();
assertSameValue(0, (int) $missedDeducted2, 'Skipped/missed dose_logs rows must never carry a deducted_quantity.');

$current3 = (float) $repo->findMedication($id3)['current_quantity'];
assertFloatEquals($expected3, $current3, 'Medication 3 (revert churn across a dose-amount edit) current_quantity drifted from expectation.');

$current4 = (float) $repo->findMedication($id4)['current_quantity'];
assertFloatEquals($expected4, $current4, 'Medication 4 (PRN interval) current_quantity drifted from expectation.');

$current5 = (float) $repo->findMedication($id5)['current_quantity'];
assertFloatEquals($expected5, $current5, 'Medication 5 (refill + adjustment) current_quantity drifted from expectation.');
$refillRows5 = $db->query("SELECT entry_type, amount FROM medication_refills WHERE medication_id = {$id5} ORDER BY id ASC")->fetchAll();
assertSameValue(2, count($refillRows5), 'Medication 5 should have exactly one refill row and one adjustment row.');
assertSameValue('refill', (string) $refillRows5[0]['entry_type'], 'First medication_refills row should be the refill.');
assertSameValue('adjustment', (string) $refillRows5[1]['entry_type'], 'Second medication_refills row should be the adjustment.');

$current6 = (float) $repo->findMedication($id6)['current_quantity'];
assertFloatEquals($expected6, $current6, 'Medication 6 (group-overlapping schedule) current_quantity drifted from expectation.');
$logCount6 = (int) $db->query("SELECT COUNT(*) FROM dose_logs WHERE medication_id = {$id6}")->fetchColumn();
assertSameValue(30, $logCount6, 'Medication 6 should have exactly one dose_logs row per day despite the group overlap.');

$current7 = (float) $repo->findMedication($id7)['current_quantity'];
assertFloatEquals($expected7, $current7, 'Medication 7 (double-submit + cron idempotency) current_quantity drifted from expectation.');
$missedDate7 = $dayDate(-29 + 20);
$missedRow7 = $db->query("SELECT status, deducted_quantity FROM dose_logs WHERE medication_id = {$id7} AND scheduled_for_date = '{$missedDate7}' AND scheduled_time = '06:00:00'")->fetch();
assertSameValue('missed', (string) $missedRow7['status'], 'Medication 7s deliberately-unresolved day should be auto-marked missed.');
assertSameValue(null, $missedRow7['deducted_quantity'], 'A missed dose_logs row must not carry a deducted_quantity.');

$current8 = (float) $repo->findMedication($id8)['current_quantity'];
assertFloatEquals($expected8, $current8, 'Medication 8 (missed-slot fallback logDoseNow) current_quantity drifted from expectation.');
$todayDate8 = $dayDate(0);
$todayRow8 = $db->query("SELECT status, deducted_quantity FROM dose_logs WHERE medication_id = {$id8} AND scheduled_for_date = '{$todayDate8}' AND scheduled_time = '05:00:00'")->fetch();
assertSameValue('taken', (string) $todayRow8['status'], 'A missed slot must still be selectable by logDoseNow()\'s auto-detect and flip to taken.');
assertFloatEquals(1.0, (float) $todayRow8['deducted_quantity'], 'The fallback-logged dose should deduct exactly once.');
$missedCount8 = (int) $db->query("SELECT COUNT(*) FROM dose_logs WHERE medication_id = {$id8} AND status = 'missed'")->fetchColumn();
assertSameValue(29, $missedCount8, 'Medication 8s other 29 days should remain missed; only today should have flipped to taken.');

echo "InventorySimulationTest passed: 30-day pill-count simulation reconciled for all 8 medications.\n";
