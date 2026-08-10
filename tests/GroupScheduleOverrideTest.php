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

// ── Scenario 6: a group's own time change must not steal an unrelated dose ──
// (regression for a reviewer-caught bug in syncGroupScheduleTime())
$repo6 = freshGSORepo();
$repo6->createMedication('Simvastatin', '', 'fixed_times', ['07:00:00', '19:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 30.0, 1.0);
$medId6 = (int) gsoFindByName($repo6->activeMedications(), 'Simvastatin')['id'];
$groupId6 = $repo6->createGroup('Morning Group', '07:00:00');
$repo6->addMedicationToGroup($groupId6, $medId6);
$repo6->updateGroup($groupId6, 'Morning Group', '06:00:00');

$rows6 = array_values(array_filter($repo6->todaySchedule($today), static fn (array $r): bool => (int) $r['medication_id'] === $medId6));
usort($rows6, static fn (array $a, array $b): int => strcmp((string) $a['reminder_time'], (string) $b['reminder_time']));
assertGSO(2, count($rows6), 'Changing the group\'s time must not delete the medication\'s unrelated evening dose.');
assertGSO('06:00', $rows6[0]['reminder_time'], 'The group-owned row should move to the group\'s new time.');
assertGSO($groupId6, $rows6[0]['group_id'], 'The relocated row should still be tagged with the group.');
assertGSO('19:00', $rows6[1]['reminder_time'], 'The unrelated evening dose must survive the group\'s time change untouched.');
assertGSO(null, $rows6[1]['group_id'], 'The unrelated evening dose must remain untagged.');

// ── Scenario 7: deleting another tenant's group must not untag your rows ────
// (regression for a reviewer-caught cross-tenant bug in deleteGroup())
$db7 = new PDO('sqlite::memory:');
$db7->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db7->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db7->exec("CREATE TABLE medications (
    id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL DEFAULT 0, profile_id INTEGER NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP, name TEXT, dose TEXT NOT NULL DEFAULT '', instructions TEXT,
    schedule_mode TEXT, time_format TEXT DEFAULT '12h', interval_hours INTEGER, first_dose_time TEXT,
    as_needed INTEGER DEFAULT 0, starting_pill_count INTEGER DEFAULT 0, pill_count INTEGER DEFAULT 0,
    low_supply_threshold INTEGER DEFAULT 5, active INTEGER DEFAULT 1, medication_type TEXT NOT NULL DEFAULT 'prescription',
    dose_amount REAL NULL, dose_unit TEXT NULL, dose_form TEXT NULL, inventory_type TEXT NOT NULL DEFAULT 'pills',
    inventory_unit TEXT NOT NULL DEFAULT 'tablets', starting_quantity REAL NULL, current_quantity REAL NULL,
    quantity_per_dose REAL NOT NULL DEFAULT 1.0
);
CREATE TABLE medication_schedule_times (id INTEGER PRIMARY KEY AUTOINCREMENT, medication_id INTEGER, reminder_time TEXT);
CREATE TABLE dose_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, medication_id INTEGER, scheduled_for_date TEXT, scheduled_time TEXT, status TEXT, note TEXT, taken_at TEXT DEFAULT CURRENT_TIMESTAMP, created_at TEXT DEFAULT CURRENT_TIMESTAMP);");

$repoOwner = new MedicationRepository($db7, 1, null);
$repoAttacker = new MedicationRepository($db7, 2, null);
$repoOwner->createMedication('Owner Med', '', 'fixed_times', ['08:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 30.0, 1.0);
$ownerMedId = (int) gsoFindByName($repoOwner->activeMedications(), 'Owner Med')['id'];
$ownerGroupId = $repoOwner->createGroup('Owner Group', '07:00:00');
$repoOwner->addMedicationToGroup($ownerGroupId, $ownerMedId);

// Attacker (a different tenant) submits the owner's group id to their own deleteGroup() call.
$repoAttacker->deleteGroup($ownerGroupId);

$rows7 = array_values(array_filter($repoOwner->todaySchedule($today), static fn (array $r): bool => (int) $r['medication_id'] === $ownerMedId));
assertGSO(1, count($rows7), 'Owner\'s medication should still have its schedule row after a cross-tenant delete attempt.');
assertGSO($ownerGroupId, $rows7[0]['group_id'], 'A cross-tenant deleteGroup() call must not untag the real owner\'s group membership.');

// ── Scenario 8: editing a medication in two groups must not drop the other ──
// (regression for a reviewer-caught bug where replaceScheduleTimes() wiped every
// group_id, and the edit form's single group_id only re-synced one of them)
$repo8 = freshGSORepo();
$repo8->createMedication('Multigroup Med', '', 'fixed_times', ['08:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 30.0, 1.0);
$medId8 = (int) gsoFindByName($repo8->activeMedications(), 'Multigroup Med')['id'];
$groupA8 = $repo8->createGroup('Morning Group', '07:00:00');
$groupB8 = $repo8->createGroup('Midday Group', '12:00:00');
$repo8->addMedicationToGroup($groupA8, $medId8);
$repo8->addMedicationToGroup($groupB8, $medId8);

// Simulates the medication edit form: the user adds a third, unrelated individual dose
// (18:00) unconnected to either group. The form only knows about one group_id (A), and
// updateMedication() itself unconditionally rewrites the medication's individual schedule
// rows — group B is never mentioned by this call at all.
$repo8->updateMedication($medId8, 'Multigroup Med', '', 'fixed_times', ['18:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 30.0, 1.0, []);
$repo8->addMedicationToGroup($groupA8, $medId8);

$rows8 = array_values(array_filter($repo8->todaySchedule($today), static fn (array $r): bool => (int) $r['medication_id'] === $medId8));
usort($rows8, static fn (array $a, array $b): int => strcmp((string) $a['reminder_time'], (string) $b['reminder_time']));
assertGSO(3, count($rows8), 'Editing the medication should keep both group rows plus the new unrelated individual dose.');
assertGSO($groupA8, $rows8[0]['group_id'], 'Group A\'s row should survive the edit.');
assertGSO($groupB8, $rows8[1]['group_id'], 'Group B\'s row must survive even though the edit form only referenced group A.');
assertGSO(null, $rows8[2]['group_id'], 'The new unrelated individual dose should be untagged.');

echo "GroupScheduleOverrideTest passed.\n";
