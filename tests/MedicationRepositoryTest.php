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
assertSameValue(9.0, (float) $edited['current_quantity'], 'Update should reset current_quantity');

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

echo "MedicationRepository tests passed.\n";
