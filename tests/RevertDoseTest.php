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

function assertThrows(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException($message);
}

/**
 * Regression coverage for the zero-pill interstitial's "cancel this dose"
 * flow: MedicationRepository::revertTakenDose() must fully undo a take —
 * restore the inventory it deducted, and either delete the dose_logs row
 * entirely (if the take created it fresh) or restore it to whatever it was
 * before the take (if it pre-existed, e.g. an auto-marked 'missed' slot
 * taken retroactively) — never destroying history that predates the take
 * being undone. It must also be safe against being invoked twice for the
 * same log (a proxy for two racing requests — see Scenario 5).
 */

function freshRepo(): array
{
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

    return [$db, $repo];
}

function findByName(array $rows, string $name): array
{
    $match = array_values(array_filter($rows, static fn (array $r): bool => $r['name'] === $name));
    if ($match === []) {
        throw new RuntimeException("Medication not found: {$name}");
    }
    return $match[0];
}

$today = (new DateTimeImmutable('today'))->format('Y-m-d');

// ── Scenario 1: reverting a taken dose restores inventory and removes the log ──
[$db1, $repo1] = freshRepo();
$repo1->createMedication('Ibuprofen', '', 'fixed_times', ['09:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 30.0, 1.0);
$ibuId = (int) findByName($repo1->activeMedications(), 'Ibuprofen')['id'];

$logId = $repo1->recordDoseStatus($ibuId, $today, '09:00:00', 'taken', '', null, null);
assertFloatEquals(29.0, (float) $repo1->findMedication($ibuId)['current_quantity'], 'Sanity check: taking the dose should decrement inventory by one.');

$repo1->revertTakenDose($logId);

assertFloatEquals(30.0, (float) $repo1->findMedication($ibuId)['current_quantity'], 'Reverting a taken dose must restore the exact amount it deducted.');
$remaining = $db1->query("SELECT COUNT(*) FROM dose_logs WHERE id = {$logId}")->fetchColumn();
assertSameValue(0, (int) $remaining, 'Reverting a taken dose must delete its dose_logs row entirely, not just flip its status.');

// The slot should be fully pending again — a fresh take should insert a new
// row rather than update a leftover one.
$logId2 = $repo1->recordDoseStatus($ibuId, $today, '09:00:00', 'taken', '', null, null);
assertFloatEquals(29.0, (float) $repo1->findMedication($ibuId)['current_quantity'], 'A dose taken again after a revert should decrement inventory normally, from a clean slate.');
$rowCount = (int) $db1->query("SELECT COUNT(*) FROM dose_logs WHERE medication_id = {$ibuId}")->fetchColumn();
assertSameValue(1, $rowCount, 'Only one dose_logs row should exist for the slot after revert + retake.');

// ── Scenario 2: only a 'taken' dose can be reverted ─────────────────────────
[$db2, $repo2] = freshRepo();
$repo2->createMedication('Metformin', '', 'fixed_times', ['08:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 30.0, 1.0);
$metId = (int) findByName($repo2->activeMedications(), 'Metformin')['id'];
$skippedLogId = $repo2->recordDoseStatus($metId, $today, '08:00:00', 'skipped', 'Skipped dose', null, null);

assertThrows(
    static fn () => $repo2->revertTakenDose($skippedLogId),
    'Reverting a skipped (non-taken) dose log must throw rather than silently doing nothing.'
);

// ── Scenario 3: reverting an unknown log id must throw ──────────────────────
[$db3, $repo3] = freshRepo();
assertThrows(
    static fn () => $repo3->revertTakenDose(999999),
    'Reverting a nonexistent dose log id must throw.'
);

// ── Scenario 4: reverting a retroactive missed→taken restores the missed record ──
[$db4, $repo4] = freshRepo();
$repo4->createMedication('Lisinopril', '', 'fixed_times', ['07:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 30.0, 1.0);
$lisId = (int) findByName($repo4->activeMedications(), 'Lisinopril')['id'];

// Simulate finalizeMissedDoses() auto-marking the slot missed, then the user
// retroactively tapping Take on it via the missed-dose modal.
$missedLogId = $repo4->recordDoseStatus($lisId, $today, '07:00:00', 'missed', 'Auto-marked missed');
$takenLogId = $repo4->recordDoseStatus($lisId, $today, '07:00:00', 'taken', 'Marked taken (was missed)');
assertSameValue($missedLogId, $takenLogId, 'The retroactive take should update the existing missed row, not insert a second one.');
assertFloatEquals(29.0, (float) $repo4->findMedication($lisId)['current_quantity'], 'Sanity check: the retroactive take should still decrement inventory.');

$repo4->revertTakenDose($takenLogId);

assertFloatEquals(30.0, (float) $repo4->findMedication($lisId)['current_quantity'], 'Reverting the retroactive take must restore the inventory it deducted.');
$restoredRow = $db4->query("SELECT status, note, deducted_quantity, pre_take_status, pre_take_note, pre_take_taken_at FROM dose_logs WHERE id = {$takenLogId}")->fetch(PDO::FETCH_ASSOC);
assertSameValue(1, is_array($restoredRow) ? 1 : 0, 'The original missed row must still exist after revert — not deleted.');
assertSameValue('missed', (string) $restoredRow['status'], 'Reverting must restore the row to its pre-take status (missed), not delete history that predates the take.');
assertSameValue('Auto-marked missed', (string) $restoredRow['note'], 'Reverting must restore the pre-take note.');
assertSameValue(null, $restoredRow['deducted_quantity'], 'A restored missed row must not carry a deducted_quantity.');
assertSameValue(null, $restoredRow['pre_take_status'], 'The snapshot columns must be cleared once restored, so a future take snapshots fresh.');

// ── Scenario 5: reverting the same log twice must not double-credit inventory ──
// A single-threaded test can't reproduce true concurrent transactions, but it
// can confirm the guarded UPDATE/DELETE's outcome: once the first call has
// changed the row, a second call for the same log id must be rejected rather
// than restoring inventory a second time.
[$db5, $repo5] = freshRepo();
$repo5->createMedication('Atorvastatin', '', 'fixed_times', ['21:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 30.0, 1.0);
$atoId = (int) findByName($repo5->activeMedications(), 'Atorvastatin')['id'];
$atoLogId = $repo5->recordDoseStatus($atoId, $today, '21:00:00', 'taken', '', null, null);

$repo5->revertTakenDose($atoLogId);
assertFloatEquals(30.0, (float) $repo5->findMedication($atoId)['current_quantity'], 'First revert should restore inventory once.');

assertThrows(
    static fn () => $repo5->revertTakenDose($atoLogId),
    'A second revert of the same (already-reverted) log id must throw rather than silently no-op.'
);
assertFloatEquals(30.0, (float) $repo5->findMedication($atoId)['current_quantity'], 'A rejected second revert must not restore inventory again.');

echo "RevertDoseTest passed.\n";
