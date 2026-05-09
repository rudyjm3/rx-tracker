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
$db->exec("CREATE TABLE medications (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, dose TEXT, instructions TEXT, schedule_mode TEXT, time_format TEXT DEFAULT '24h', interval_hours INTEGER, first_dose_time TEXT, as_needed INTEGER DEFAULT 0, starting_pill_count INTEGER DEFAULT 0, pill_count INTEGER DEFAULT 0, low_supply_threshold INTEGER DEFAULT 5, active INTEGER DEFAULT 1);
CREATE TABLE medication_schedule_times (id INTEGER PRIMARY KEY AUTOINCREMENT, medication_id INTEGER, reminder_time TEXT);
CREATE TABLE dose_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, medication_id INTEGER, scheduled_for_date TEXT, scheduled_time TEXT, status TEXT, note TEXT, taken_at TEXT DEFAULT CURRENT_TIMESTAMP, created_at TEXT DEFAULT CURRENT_TIMESTAMP);");

$repo = new MedicationRepository($db);
$today = (new DateTimeImmutable())->format('Y-m-d');

$repo->createMedication('Fixed Med', '5 mg', '', 'fixed_times', '24h', ['08:00:00', '20:00:00'], null, null, false, 10, 2);
$repo->createMedication('PRN Med', '10 mg', '', 'interval', '12h', [], 4, '06:00:00', true, 12, 3);

$all = $repo->activeMedications();
assertSameValue(2, count($all), 'Two meds expected');
assertSameValue(10, (int) $all[0]['starting_pill_count'], 'Create should set starting count');
assertSameValue(10, (int) $all[0]['pill_count'], 'Create should set current count');

$fixedId = (int) $all[0]['id'];
$repo->updateMedication($fixedId, 'Fixed Med Updated', '5 mg', '', 'fixed_times', '12h', ['09:00:00'], null, null, false, 9, 2);
$edited = $repo->findMedication($fixedId);
assertSameValue('Fixed Med Updated', $edited['name'], 'Edit should update name');
assertSameValue('12h', $edited['time_format'], 'Edit should update time format');
assertSameValue(9, (int) $edited['starting_pill_count'], 'Update should reset starting count');
assertSameValue(9, (int) $edited['pill_count'], 'Update should reset current count');

$schedule = $repo->todaySchedule($today);
assertSameValue(true, count($schedule) >= 2, 'Schedule should include generated rows');

$repo->recordDoseStatus($fixedId, $today, '09:00:00', 'taken', 'ok');
$recent = $repo->recentLogs();
assertSameValue('taken', $recent[0]['status'], 'Taken log should save');
$afterTaken = $repo->findMedication($fixedId);
assertSameValue(9, (int) $afterTaken['starting_pill_count'], 'Starting count should not decrement');
assertSameValue(8, (int) $afterTaken['pill_count'], 'Current count should decrement after taken dose');

$prnId = (int) $all[1]['id'];
$repo->logDoseNow($prnId, 'PRN now');
$threw = false;
try {
    $repo->logDoseNow($prnId, 'too soon');
} catch (RuntimeException $exception) {
    $threw = true;
}
assertSameValue(true, $threw, 'Interval medication should block too-early dose.');

echo "MedicationRepository tests passed.\n";
