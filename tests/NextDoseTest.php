<?php

declare(strict_types=1);

require __DIR__ . '/../includes/MedicationRepository.php';

function assertNext(mixed $expected, mixed $actual, string $message): void
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

$repo  = new MedicationRepository($db);
$today = (new DateTimeImmutable())->format('Y-m-d');

// ── Fixed-times schedule: N configured times → N slots per day ───────────────

$repo->createMedication('Fixed 3x', '', 'fixed_times', ['08:00:00', '14:00:00', '21:00:00'], null, null, false, 0, false, '', 'prescription', null, null, null, 'pills', 0.0, 1.0);
$all3    = $repo->activeMedications();
$fixed3Id = (int) $all3[0]['id'];

$schedule3 = $repo->todaySchedule($today);
$fixed3Rows = array_values(array_filter($schedule3, static fn(array $r): bool => (int) $r['medication_id'] === $fixed3Id));
assertNext(3, count($fixed3Rows), 'Fixed 3x/day med generates exactly 3 schedule slots');
$times3 = array_column($fixed3Rows, 'reminder_time');
sort($times3);
assertNext(['08:00', '14:00', '21:00'], $times3, 'Fixed 3x/day slots are at the configured times');

// ── Fixed-times schedule: single daily dose ───────────────────────────────────

$repo->createMedication('Fixed 1x', '', 'fixed_times', ['09:00:00'], null, null, false, 0, false, '', 'prescription', null, null, null, 'pills', 0.0, 1.0);
$all1   = $repo->activeMedications();
$fixed1Id = (int) array_values(array_filter($all1, static fn(array $r): bool => $r['name'] === 'Fixed 1x'))[0]['id'];

$schedule1  = $repo->todaySchedule($today);
$fixed1Rows = array_values(array_filter($schedule1, static fn(array $r): bool => (int) $r['medication_id'] === $fixed1Id));
assertNext(1, count($fixed1Rows), 'Fixed 1x/day med generates exactly 1 slot');
assertNext('09:00', $fixed1Rows[0]['reminder_time'], 'Single slot is at 09:00');

// ── Interval schedule: every 4 hours from 08:00 → 4 slots (08/12/16/20) ──────

$repo->createMedication('Interval 4h', '', 'interval', [], 4, '08:00:00', false, 0, false, '', 'prescription', null, null, null, 'pills', 0.0, 1.0);
$allInterval = $repo->activeMedications();
$int4Id = (int) array_values(array_filter($allInterval, static fn(array $r): bool => $r['name'] === 'Interval 4h'))[0]['id'];

$scheduleInterval = $repo->todaySchedule($today);
$int4Rows = array_values(array_filter($scheduleInterval, static fn(array $r): bool => (int) $r['medication_id'] === $int4Id));
assertNext(4, count($int4Rows), 'Every-4h med starting at 08:00 generates 4 slots in 24h (08/12/16/20)');

$int4Times = array_column($int4Rows, 'reminder_time');
sort($int4Times);
assertNext(['08:00', '12:00', '16:00', '20:00'], $int4Times, 'Every-4h slots are at 08:00, 12:00, 16:00, 20:00');

// ── Interval schedule: every 8 hours from 06:00 → 3 slots (06/14/22) ─────────

$repo->createMedication('Interval 8h', '', 'interval', [], 8, '06:00:00', false, 0, false, '', 'prescription', null, null, null, 'pills', 0.0, 1.0);
$allInt8 = $repo->activeMedications();
$int8Id  = (int) array_values(array_filter($allInt8, static fn(array $r): bool => $r['name'] === 'Interval 8h'))[0]['id'];

$scheduleInt8 = $repo->todaySchedule($today);
$int8Rows = array_values(array_filter($scheduleInt8, static fn(array $r): bool => (int) $r['medication_id'] === $int8Id));
assertNext(3, count($int8Rows), 'Every-8h med starting at 06:00 generates 3 slots (06/14/22)');

$int8Times = array_column($int8Rows, 'reminder_time');
sort($int8Times);
assertNext(['06:00', '14:00', '22:00'], $int8Times, 'Every-8h slots are at 06:00, 14:00, 22:00');

// ── Interval blocking: cannot log again before interval_hours elapsed ─────────

$repo->logDoseNow($int4Id, 'first dose');
$tooSoonThrew = false;
try {
    $repo->logDoseNow($int4Id, 'too soon');
} catch (RuntimeException) {
    $tooSoonThrew = true;
}
assertNext(true, $tooSoonThrew, 'Interval med blocks second logDoseNow before interval_hours elapsed');

// ── Interval schedule: every 24 hours → 1 slot per day ───────────────────────

$repo->createMedication('Once Daily Interval', '', 'interval', [], 24, '07:00:00', false, 0, false, '', 'prescription', null, null, null, 'pills', 0.0, 1.0);
$allDaily = $repo->activeMedications();
$dailyId  = (int) array_values(array_filter($allDaily, static fn(array $r): bool => $r['name'] === 'Once Daily Interval'))[0]['id'];

$dailySchedule = $repo->todaySchedule($today);
$dailyRows = array_values(array_filter($dailySchedule, static fn(array $r): bool => (int) $r['medication_id'] === $dailyId));
assertNext(1, count($dailyRows), 'Every-24h interval med generates exactly 1 slot per day');
assertNext('07:00', $dailyRows[0]['reminder_time'], '24h interval slot is at first_dose_time 07:00');

// ── Interval schedule: first_dose_time = midnight → slot at 00:00 ────────────

$repo->createMedication('Midnight Start', '', 'interval', [], 12, '00:00:00', false, 0, false, '', 'prescription', null, null, null, 'pills', 0.0, 1.0);
$allMid = $repo->activeMedications();
$midId  = (int) array_values(array_filter($allMid, static fn(array $r): bool => $r['name'] === 'Midnight Start'))[0]['id'];

$midSchedule = $repo->todaySchedule($today);
$midRows = array_values(array_filter($midSchedule, static fn(array $r): bool => (int) $r['medication_id'] === $midId));
assertNext(2, count($midRows), 'Every-12h med starting at midnight generates 2 slots (00:00 and 12:00)');

$midTimes = array_column($midRows, 'reminder_time');
sort($midTimes);
assertNext(['00:00', '12:00'], $midTimes, 'Midnight-start 12h slots are at 00:00 and 12:00');

// ── Inactive med excluded from todaySchedule ──────────────────────────────────

$repo->createMedication('Active Med', '', 'fixed_times', ['10:00:00'], null, null, false, 0, false, '', 'prescription', null, null, null, 'pills', 0.0, 1.0);
$allActive = $repo->activeMedications();
$activeId  = (int) array_values(array_filter($allActive, static fn(array $r): bool => $r['name'] === 'Active Med'))[0]['id'];
$db->exec("UPDATE medications SET active = 0 WHERE id = {$activeId}");

$afterDeactivate = $repo->todaySchedule($today);
$deactivatedRows = array_filter($afterDeactivate, static fn(array $r): bool => (int) $r['medication_id'] === $activeId);
assertNext([], array_values($deactivatedRows), 'Inactive med is excluded from todaySchedule');

echo "NextDoseTest passed.\n";
