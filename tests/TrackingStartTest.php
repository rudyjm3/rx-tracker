<?php

declare(strict_types=1);

require __DIR__ . '/../includes/MedicationRepository.php';

function assertTS(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
        );
    }
}

// ── SQLite in-memory DB ───────────────────────────────────────────────────────

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

// ── Test: medication added with a future start date is not marked missed ───────
// Reproduces the reported bug: user adds a new med in the afternoon (7/31) but
// hasn't started taking it yet — sets start_date to 8/1.

$repo->createMedication(
    'NewMed',
    '',
    'fixed_times',
    ['07:00:00'],
    null,
    null,
    false,
    0,
    false,
    '',
    'prescription',
    null,
    null,
    null,
    'pills',
    30.0,
    1.0,
    [],
    '2026-08-01'
);
$all = $repo->activeMedications();
$newMedId = (int) array_values(array_filter($all, static fn(array $r): bool => $r['name'] === 'NewMed'))[0]['id'];

// Later that same afternoon (creation day), the 7am slot has long passed —
// it must NOT be counted as missed because tracking hasn't started yet.
$countOnCreationDay = $repo->missedDoseCount('2026-07-31', '23:59:00');
assertTS(0, $countOnCreationDay, 'Future start date: slot not counted missed on creation day');

// The page-load finalizer must not write a 'missed' dose_logs row either.
$repo->finalizeMissedDoses(new DateTimeImmutable('2026-07-31 23:59:00'), 0);
$rowsOnCreationDay = $db->query("SELECT * FROM dose_logs WHERE medication_id = {$newMedId}")->fetchAll(PDO::FETCH_ASSOC);
assertTS([], $rowsOnCreationDay, 'Future start date: no dose_logs row written on creation day');

// On the actual start date, the slot behaves normally: due, then missed once past due.
$countBeforeDue = $repo->missedDoseCount('2026-08-01', '06:00:00');
assertTS(0, $countBeforeDue, 'Start date: slot not missed before its due time');

$countAfterDue = $repo->missedDoseCount('2026-08-01', '08:00:00');
assertTS(1, $countAfterDue, 'Start date: slot counted missed once due time has passed');

$repo->finalizeMissedDoses(new DateTimeImmutable('2026-08-01 08:00:00'), 0);
$rowsOnStartDate = $db->query("SELECT * FROM dose_logs WHERE medication_id = {$newMedId}")->fetchAll(PDO::FETCH_ASSOC);
assertTS(1, count($rowsOnStartDate), 'Start date: dose_logs row written once due time has passed');
assertTS('missed', (string) $rowsOnStartDate[0]['status'], 'Start date: row status is missed');

echo "TrackingStartTest (future start date) passed.\n";

// ── Test: correcting a medication's start date cleans up stale auto-misses ─────

$repo->createMedication(
    'CorrectMe',
    '',
    'fixed_times',
    ['07:00:00'],
    null,
    null,
    false,
    0,
    false,
    '',
    'prescription',
    null,
    null,
    null,
    'pills',
    30.0,
    1.0,
    [],
    '2026-07-31'
);
$allAfterCorrectMe = $repo->activeMedications();
$correctMeId = (int) array_values(array_filter($allAfterCorrectMe, static fn(array $r): bool => $r['name'] === 'CorrectMe'))[0]['id'];

// Auto-mark today's slot missed (start_date was mistakenly left as today).
$repo->finalizeMissedDoses(new DateTimeImmutable('2026-07-31 23:59:00'), 0);
$autoMarked = $db->query("SELECT * FROM dose_logs WHERE medication_id = {$correctMeId}")->fetchAll(PDO::FETCH_ASSOC);
assertTS(1, count($autoMarked), 'Auto-marked missed row exists before correction');
assertTS('Auto-marked missed', (string) $autoMarked[0]['note'], 'Row carries the auto-marker note');

// A separate, user-confirmed missed dose from further in the past must survive the cleanup.
$repo->recordDoseStatus($correctMeId, '2026-07-20', '07:00:00', 'missed', 'I forgot to take it');

// User realizes the mistake and edits the medication, pushing start_date to tomorrow.
$repo->updateMedication(
    $correctMeId,
    'CorrectMe',
    '',
    'fixed_times',
    ['07:00:00'],
    null,
    null,
    false,
    0,
    false,
    '',
    'prescription',
    null,
    null,
    null,
    'pills',
    30.0,
    1.0,
    [],
    '2026-08-01'
);

$afterCorrection = $db->query("SELECT * FROM dose_logs WHERE medication_id = {$correctMeId} ORDER BY scheduled_for_date")->fetchAll(PDO::FETCH_ASSOC);
assertTS(1, count($afterCorrection), 'Only the manually-confirmed missed dose remains after correction');
assertTS('2026-07-20', (string) $afterCorrection[0]['scheduled_for_date'], 'Remaining row is the manually-confirmed one');
assertTS('I forgot to take it', (string) $afterCorrection[0]['note'], 'Remaining row keeps its original note');

echo "TrackingStartTest (start date correction cleanup) passed.\n";

// ── Test: unrelated edits must not collapse a precise onboarding timestamp ─────
// activateOnboardingMedications() sets tracking_started_at to the exact moment
// the user finished onboarding (e.g. 3pm), not midnight. The edit form always
// re-posts the medication's existing start_date, so a later, unrelated edit
// (e.g. changing the dose amount) must not overwrite that precise timestamp.

$repo->createMedication(
    'Onboarded Med',
    '',
    'fixed_times',
    ['09:00:00'],
    null,
    null,
    false,
    0,
    false,
    '',
    'prescription',
    null,
    null,
    null,
    'pills',
    30.0,
    1.0,
    [],
    '2026-07-31'
);
$allOnboarded = $repo->activeMedications();
$onboardedId = (int) array_values(array_filter($allOnboarded, static fn(array $r): bool => $r['name'] === 'Onboarded Med'))[0]['id'];

// Simulate onboarding activation recording the precise activation time.
$db->exec("UPDATE medications SET tracking_started_at = '2026-07-31 15:00:00' WHERE id = {$onboardedId}");

// Morning dose (09:00) is before the 3pm activation and must stay excluded.
$countBeforeEdit = $repo->missedDoseCount('2026-07-31', '23:59:00');
assertTS(0, $countBeforeEdit, 'Onboarded med: morning slot excluded before activation time');

// User edits an unrelated field (dose amount); the form re-submits the same
// start_date ('2026-07-31') it was given.
$repo->updateMedication(
    $onboardedId,
    'Onboarded Med',
    '',
    'fixed_times',
    ['09:00:00'],
    null,
    null,
    false,
    0,
    false,
    '',
    'prescription',
    10.0,
    'mg',
    'tablet',
    'pills',
    30.0,
    1.0,
    [],
    '2026-07-31'
);

$trackingAfterEdit = (string) $repo->findMedication($onboardedId)['tracking_started_at'];
assertTS('2026-07-31 15:00:00', $trackingAfterEdit, 'Unrelated edit must not collapse the precise activation timestamp');

// The morning slot must still be excluded — it must not have reappeared and
// been auto-marked missed because of the edit.
$countAfterEdit = $repo->missedDoseCount('2026-07-31', '23:59:00');
assertTS(0, $countAfterEdit, 'Onboarded med: morning slot still excluded after unrelated edit');

echo "TrackingStartTest (unrelated edit preserves onboarding timestamp) passed.\n";
