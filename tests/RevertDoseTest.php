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
 * restore the inventory it deducted and delete the dose_logs row entirely
 * (not just flip its status) — so the dose returns to its normal pending
 * state, matching a request to make cancelling from the interstitial behave
 * as if Take was never tapped.
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

echo "RevertDoseTest passed.\n";
