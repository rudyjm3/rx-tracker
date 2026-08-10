<?php

declare(strict_types=1);

require __DIR__ . '/../includes/MedicationRepository.php';

/**
 * Regression coverage for a reported bug: a medication with its own individually
 * configured dose time, when added to a group scheduled at a different time,
 * silently dropped out of that group's alert (its own time "won" the lookup and
 * no slot was ever generated at the group's time). The fix makes the group's
 * scheduled_time authoritative for a group-owned schedule row, while leaving any
 * of the medication's other, unrelated individual doses untouched — and lets a
 * medication belong to more than one group, each firing its own alert.
 */

function assertGSO(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function freshGSORepo(): MedicationRepository
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

    return new MedicationRepository($db);
}

function gsoFindByName(array $rows, string $name): array
{
    $match = array_values(array_filter($rows, static fn (array $r): bool => $r['name'] === $name));
    if ($match === []) {
        throw new RuntimeException("Medication not found: {$name}");
    }
    return $match[0];
}

$today = (new DateTimeImmutable('today'))->format('Y-m-d');

// ── Scenario 1: mismatched individual time is overridden, not excluded ──────
$repo1 = freshGSORepo();
$repo1->createMedication('Lisinopril', '', 'fixed_times', ['08:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 30.0, 1.0);
$medId1 = (int) gsoFindByName($repo1->activeMedications(), 'Lisinopril')['id'];
$groupId1 = $repo1->createGroup('Morning Group', '07:00:00');
$repo1->addMedicationToGroup($groupId1, $medId1);

$rows1 = array_values(array_filter($repo1->todaySchedule($today), static fn (array $r): bool => (int) $r['medication_id'] === $medId1));
assertGSO(1, count($rows1), 'Medication should have exactly one schedule row after joining a mismatched-time group, not two.');
assertGSO('07:00', $rows1[0]['reminder_time'], 'The lone schedule row should fire at the group\'s time, not the medication\'s stale individual time.');
assertGSO($groupId1, $rows1[0]['group_id'], 'The schedule row should be tagged with the group it was absorbed into.');

// ── Scenario 2: a second, unrelated individual dose is left alone ───────────
$repo2 = freshGSORepo();
$repo2->createMedication('Metformin', '', 'fixed_times', ['08:00:00', '19:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 30.0, 1.0);
$medId2 = (int) gsoFindByName($repo2->activeMedications(), 'Metformin')['id'];
$groupId2 = $repo2->createGroup('Morning Group', '07:00:00');
$repo2->addMedicationToGroup($groupId2, $medId2);

$rows2 = array_values(array_filter($repo2->todaySchedule($today), static fn (array $r): bool => (int) $r['medication_id'] === $medId2));
usort($rows2, static fn (array $a, array $b): int => strcmp((string) $a['reminder_time'], (string) $b['reminder_time']));
assertGSO(2, count($rows2), 'Twice-daily medication should still have exactly two schedule rows after grouping.');
assertGSO('07:00', $rows2[0]['reminder_time'], 'The morning dose (earliest) should be absorbed into the group\'s time.');
assertGSO($groupId2, $rows2[0]['group_id'], 'The absorbed morning dose should be tagged with the group.');
assertGSO('19:00', $rows2[1]['reminder_time'], 'The unrelated evening dose should keep firing at its own original time.');
assertGSO(null, $rows2[1]['group_id'], 'The unrelated evening dose should remain untagged.');

// ── Scenario 3: a medication in two groups is included in both alerts ───────
$repo3 = freshGSORepo();
$repo3->createMedication('Atorvastatin', '', 'fixed_times', ['08:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 30.0, 1.0);
$medId3 = (int) gsoFindByName($repo3->activeMedications(), 'Atorvastatin')['id'];
$groupA = $repo3->createGroup('Morning Group', '07:00:00');
$groupB = $repo3->createGroup('Midday Group', '12:00:00');
$repo3->addMedicationToGroup($groupA, $medId3);
$repo3->addMedicationToGroup($groupB, $medId3);

$rows3 = array_values(array_filter($repo3->todaySchedule($today), static fn (array $r): bool => (int) $r['medication_id'] === $medId3));
usort($rows3, static fn (array $a, array $b): int => strcmp((string) $a['reminder_time'], (string) $b['reminder_time']));
assertGSO(2, count($rows3), 'Medication belonging to two groups should have one row per group.');
assertGSO('07:00', $rows3[0]['reminder_time'], 'First row should fire at group A\'s time.');
assertGSO($groupA, $rows3[0]['group_id'], 'First row should be tagged to group A.');
assertGSO('12:00', $rows3[1]['reminder_time'], 'Second row should fire at group B\'s time.');
assertGSO($groupB, $rows3[1]['group_id'], 'Second row should be tagged to group B.');

// ── Scenario 4: editing a group's time re-syncs its members ─────────────────
$repo4 = freshGSORepo();
$repo4->createMedication('Warfarin', '', 'fixed_times', ['08:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 30.0, 1.0);
$repo4->createMedication('Aspirin', '', 'fixed_times', ['09:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 30.0, 1.0);
$warfarinId = (int) gsoFindByName($repo4->activeMedications(), 'Warfarin')['id'];
$aspirinId = (int) gsoFindByName($repo4->activeMedications(), 'Aspirin')['id'];
$groupId4 = $repo4->createGroup('Evening Group', '20:00:00');
$repo4->addMedicationToGroup($groupId4, $warfarinId);
$repo4->addMedicationToGroup($groupId4, $aspirinId);
$repo4->updateGroup($groupId4, 'Evening Group', '21:00:00');

foreach ([$warfarinId, $aspirinId] as $memberId) {
    $rows = array_values(array_filter($repo4->todaySchedule($today), static fn (array $r): bool => (int) $r['medication_id'] === $memberId));
    assertGSO(1, count($rows), "Member {$memberId} should still have exactly one schedule row after the group's time changed.");
    assertGSO('21:00', $rows[0]['reminder_time'], "Member {$memberId} should follow the group's updated time.");
    assertGSO($groupId4, $rows[0]['group_id'], "Member {$memberId} should still be tagged with the group.");
}

// ── Scenario 5: removing a medication from a group keeps its dose, untagged ─
$repo5 = freshGSORepo();
$repo5->createMedication('Levothyroxine', '', 'fixed_times', ['08:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 30.0, 1.0);
$medId5 = (int) gsoFindByName($repo5->activeMedications(), 'Levothyroxine')['id'];
$groupId5 = $repo5->createGroup('Morning Group', '07:00:00');
$repo5->addMedicationToGroup($groupId5, $medId5);
$repo5->removeMedicationFromGroup($medId5, $groupId5);

$rows5 = array_values(array_filter($repo5->todaySchedule($today), static fn (array $r): bool => (int) $r['medication_id'] === $medId5));
assertGSO(1, count($rows5), 'Medication should keep its dose after being removed from the group.');
assertGSO('07:00', $rows5[0]['reminder_time'], 'The dose stays at whatever time it was synced to while grouped.');
assertGSO(null, $rows5[0]['group_id'], 'The dose should no longer be tagged with the group after removal.');

echo "GroupScheduleOverrideTest passed.\n";
