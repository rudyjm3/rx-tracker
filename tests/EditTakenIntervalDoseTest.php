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

/**
 * Coverage for editing an already-taken dose (e.g. correcting its note or
 * time from the history views) on an interval-scheduled medication.
 * recordDoseStatus()'s interval guard (assertIntervalAllowed) anchors on
 * the medication's latest 'taken' scheduled slot — which, mid-edit, is the
 * very row being edited — so without a bypass for "already taken, staying
 * taken", re-submitting the same slot is wrongly rejected as "too early",
 * even though no new dose is being taken.
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

function findByName(array $rows, string $name): array
{
    $match = array_values(array_filter($rows, static fn (array $r): bool => $r['name'] === $name));
    if ($match === []) {
        throw new RuntimeException("Medication not found: {$name}");
    }
    return $match[0];
}

$today = (new DateTimeImmutable('today'))->format('Y-m-d');

$repo->createMedication('PRN Pain Med', '', 'interval', [], 6, '08:00:00', true, 5, false, '', 'prescription', null, null, null, 'pills', 100.0, 1.0);
$medId = (int) findByName($repo->activeMedications(), 'PRN Pain Med')['id'];

$logId = $repo->recordDoseStatus($medId, $today, '08:00:00', 'taken', 'Original note', null, null);

// Re-submitting the same slot as 'taken' again (e.g. saving an edited note
// or time via the history "Edit" control) must succeed — it is not a new
// take, so the interval guard must not reject it as "too early".
$editedLogId = $repo->recordDoseStatus($medId, $today, '08:00:00', 'taken', 'Edited note', null, null);

assertSameValue($logId, $editedLogId, 'Editing an already-taken dose should update the existing row, not insert a new one.');
$updatedNote = $db->query("SELECT note FROM dose_logs WHERE id = {$logId}")->fetchColumn();
assertSameValue('Edited note', (string) $updatedNote, 'The edit must have taken effect (note updated), confirming the interval guard did not block it.');

echo "EditTakenIntervalDoseTest passed.\n";
