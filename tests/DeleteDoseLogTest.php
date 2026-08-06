<?php

declare(strict_types=1);

require __DIR__ . '/../includes/MedicationRepository.php';

if (!function_exists('assertSameValue')) {
    function assertSameValue(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
        }
    }
}

if (!function_exists('assertFloatEquals')) {
    function assertFloatEquals(float $expected, float $actual, string $message, float $epsilon = 0.0005): void
    {
        if (abs($expected - $actual) > $epsilon) {
            throw new RuntimeException($message . "\nExpected: {$expected}\nActual: {$actual}");
        }
    }
}

if (!function_exists('assertThrows')) {
    function assertThrows(callable $fn, string $message): void
    {
        try {
            $fn();
        } catch (Throwable) {
            return;
        }
        throw new RuntimeException($message);
    }
}

/**
 * Coverage for MedicationRepository::deleteDoseLog() — the general-purpose
 * "edit/delete history entry" deletion, as opposed to revertTakenDose()
 * (which is scoped to the immediate zero-pill-undo flow). A taken dose must
 * restore whatever inventory it deducted (or restore a pre-existing row it
 * stood in for); a skipped/missed dose carries no inventory impact and is
 * simply removed.
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
$yesterday = (new DateTimeImmutable('yesterday'))->format('Y-m-d');

// ── Scenario 1: deleting a taken dose restores inventory and removes the log ──
[$db1, $repo1] = freshRepo();
$repo1->createMedication('Ibuprofen', '', 'fixed_times', ['09:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 30.0, 1.0);
$ibuId = (int) findByName($repo1->activeMedications(), 'Ibuprofen')['id'];

$logId = $repo1->recordDoseStatus($ibuId, $today, '09:00:00', 'taken', '', null, null);
assertFloatEquals(29.0, (float) $repo1->findMedication($ibuId)['current_quantity'], 'Sanity check: taking the dose should decrement inventory by one.');

$repo1->deleteDoseLog($logId);

assertFloatEquals(30.0, (float) $repo1->findMedication($ibuId)['current_quantity'], 'Deleting a taken dose must restore the exact amount it deducted.');
$remaining = $db1->query("SELECT COUNT(*) FROM dose_logs WHERE id = {$logId}")->fetchColumn();
assertSameValue(0, (int) $remaining, 'Deleting a taken dose must remove its dose_logs row entirely.');

// ── Scenario 2: deleting a skipped dose from a previous day is a plain delete, no inventory change ──
[$db2, $repo2] = freshRepo();
$repo2->createMedication('Metformin', '', 'fixed_times', ['08:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 30.0, 1.0);
$metId = (int) findByName($repo2->activeMedications(), 'Metformin')['id'];
$skippedLogId = $repo2->recordDoseStatus($metId, $yesterday, '08:00:00', 'skipped', 'Skipped dose', null, null);

$repo2->deleteDoseLog($skippedLogId);

assertFloatEquals(30.0, (float) $repo2->findMedication($metId)['current_quantity'], 'Deleting a skipped dose must not touch inventory.');
$remainingSkipped = $db2->query("SELECT COUNT(*) FROM dose_logs WHERE id = {$skippedLogId}")->fetchColumn();
assertSameValue(0, (int) $remainingSkipped, 'Deleting a skipped dose must remove its dose_logs row.');

// ── Scenario 3: deleting a missed dose is likewise a plain delete ──────────
[$db3, $repo3] = freshRepo();
$repo3->createMedication('Lisinopril', '', 'fixed_times', ['07:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 30.0, 1.0);
$lisId = (int) findByName($repo3->activeMedications(), 'Lisinopril')['id'];
$missedLogId = $repo3->recordDoseStatus($lisId, $yesterday, '07:00:00', 'missed', 'Auto-marked missed', null, null);

$repo3->deleteDoseLog($missedLogId);

assertFloatEquals(30.0, (float) $repo3->findMedication($lisId)['current_quantity'], 'Deleting a missed dose must not touch inventory.');
$remainingMissed = $db3->query("SELECT COUNT(*) FROM dose_logs WHERE id = {$missedLogId}")->fetchColumn();
assertSameValue(0, (int) $remainingMissed, 'Deleting a missed dose must remove its dose_logs row.');

// ── Scenario 4: deleting an unknown log id must throw ───────────────────────
[$db4, $repo4] = freshRepo();
assertThrows(
    static fn () => $repo4->deleteDoseLog(999999),
    'Deleting a nonexistent dose log id must throw.'
);

// ── Scenario 5: deleting a retroactive missed→taken restores the missed record ──
[$db5, $repo5] = freshRepo();
$repo5->createMedication('Atorvastatin', '', 'fixed_times', ['21:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 30.0, 1.0);
$atoId = (int) findByName($repo5->activeMedications(), 'Atorvastatin')['id'];

$missedLogId2 = $repo5->recordDoseStatus($atoId, $yesterday, '21:00:00', 'missed', 'Auto-marked missed');
$takenLogId2 = $repo5->recordDoseStatus($atoId, $yesterday, '21:00:00', 'taken', 'Marked taken (was missed)');
assertSameValue($missedLogId2, $takenLogId2, 'The retroactive take should update the existing missed row, not insert a second one.');

$repo5->deleteDoseLog($takenLogId2);

assertFloatEquals(30.0, (float) $repo5->findMedication($atoId)['current_quantity'], 'Deleting the retroactive take must restore the inventory it deducted.');
$restoredRow = $db5->query("SELECT status, note FROM dose_logs WHERE id = {$takenLogId2}")->fetch(PDO::FETCH_ASSOC);
assertSameValue(1, is_array($restoredRow) ? 1 : 0, 'The original missed row must still exist after delete — not removed.');
assertSameValue('missed', (string) $restoredRow['status'], 'Deleting the take must restore the row to its pre-take status (missed), not delete history that predates the take.');

// ── Scenario 6: deleting the same taken log twice must not double-credit inventory ──
[$db6, $repo6] = freshRepo();
$repo6->createMedication('Losartan', '', 'fixed_times', ['06:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 30.0, 1.0);
$losId = (int) findByName($repo6->activeMedications(), 'Losartan')['id'];
$losLogId = $repo6->recordDoseStatus($losId, $today, '06:00:00', 'taken', '', null, null);

$repo6->deleteDoseLog($losLogId);
assertFloatEquals(30.0, (float) $repo6->findMedication($losId)['current_quantity'], 'First delete should restore inventory once.');

assertThrows(
    static fn () => $repo6->deleteDoseLog($losLogId),
    'A second delete of the same (already-deleted) log id must throw rather than silently no-op.'
);
assertFloatEquals(30.0, (float) $repo6->findMedication($losId)['current_quantity'], 'A rejected second delete must not restore inventory again.');

// ── Scenario 7: deleting a legacy taken log with a NULL deducted_quantity still restores inventory ──
// Rows created before the deducted_quantity column existed have it NULL.
// deleteDoseLog() must still credit back a dose (falling back to the
// medication's current quantity_per_dose), not silently skip restoration.
[$db7, $repo7] = freshRepo();
$repo7->createMedication('Simvastatin', '', 'fixed_times', ['22:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 30.0, 1.0);
$simId = (int) findByName($repo7->activeMedications(), 'Simvastatin')['id'];
$db7->exec("UPDATE medications SET current_quantity = 29.0 WHERE id = {$simId}");
$db7->exec("INSERT INTO dose_logs (medication_id, scheduled_for_date, scheduled_time, status, note, taken_at, deducted_quantity) VALUES ({$simId}, '{$yesterday}', '22:00:00', 'taken', '', '{$yesterday} 22:00:00', NULL)");
$legacyLogId = (int) $db7->lastInsertId();

$repo7->deleteDoseLog($legacyLogId);

assertFloatEquals(30.0, (float) $repo7->findMedication($simId)['current_quantity'], 'Deleting a legacy taken log with a NULL deducted_quantity must still restore inventory, falling back to quantity_per_dose.');

echo "DeleteDoseLogTest passed.\n";
