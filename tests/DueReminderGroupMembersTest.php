<?php

declare(strict_types=1);

require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/MedicationRepository.php';

function assertEqualsDR(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function findByNameDR(array $rows, string $name): array
{
    $match = array_values(array_filter($rows, static fn (array $r): bool => $r['name'] === $name));
    if ($match === []) {
        throw new RuntimeException("Medication not found: {$name}");
    }
    return $match[0];
}

function freshRepoDR(): array
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

$today = (new DateTimeImmutable('today'))->format('Y-m-d');

// ── Setup: a group with one required member and one as_needed member, both
// due at 08:00. The required member gets taken on time; the PRN member is
// left unresolved, as a PRN dose normally would be on a day it isn't taken.
[$db, $repo] = freshRepoDR();
$repo->createMedication('RequiredMed', '', 'fixed_times', ['08:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 30.0, 1.0);
$repo->createMedication('PRNMed', '', 'fixed_times', ['08:00:00'], null, null, true, 5, false, '', 'prescription', null, null, null, 'pills', 30.0, 1.0);

$all = $repo->activeMedications();
$requiredId = (int) findByNameDR($all, 'RequiredMed')['id'];
$prnId = (int) findByNameDR($all, 'PRNMed')['id'];

$groupId = $repo->createGroup('Morning Group', '08:00:00');
$repo->addMedicationToGroup($groupId, $requiredId);
$repo->addMedicationToGroup($groupId, $prnId);

$repo->recordDoseStatus($requiredId, $today, '08:00:00', 'taken', '', null, $groupId);

$graceMinutes = $repo->getMissedGraceMinutes();
assertEqualsDR(60, $graceMinutes, 'Default missed-dose grace should be 60 minutes.');

// ── Scenario 1: still within the grace window — the due-now item list should
// contain only the still-pending PRN member (the required member is already
// resolved and excluded), but that item's group_members should list BOTH
// medications so the alarm overlay/UI can show the group's real size and
// each member's actual status instead of silently dropping the resolved one.
$withinGrace = new DateTimeImmutable("{$today}T08:30:00");
$dueWithinGrace = $repo->dueReminderItems($withinGrace, $graceMinutes);

assertEqualsDR(1, count($dueWithinGrace), 'Only the still-pending PRN member should be in the due-now item list.');
assertEqualsDR('PRNMed', $dueWithinGrace[0]['name'], 'The due-now item should be the PRN member, not the already-taken required member.');

$members = $dueWithinGrace[0]['group_members'] ?? null;
if (!is_array($members)) {
    throw new RuntimeException('group_members should be present on a grouped due-now item.');
}
assertEqualsDR(2, count($members), 'group_members should list the group\'s full membership (both medications), not just the due-now subset.');

$requiredMember = findByNameDR($members, 'RequiredMed');
$prnMember = findByNameDR($members, 'PRNMed');
assertEqualsDR('taken', $requiredMember['status'], "The already-taken required member's status should be reported as 'taken' in group_members.");
assertEqualsDR('pending', $prnMember['status'], "The still-pending PRN member's status should be reported as 'pending' in group_members.");

// ── Scenario 2: hours later, well past the grace window. The required
// member already auto-finalized to missed in real usage (finalizeMissedDoses
// skips as_needed doses, so the PRN member never does) — without a grace
// bound on dueReminderItems, the PRN member would stay "due now" for the
// rest of the day, re-triggering the in-app alarm overlay (sound/vibration)
// and in-app alert every poll. With the grace bound applied, it should drop
// out of the due-now list once it's past grace, same as a required dose
// would once finalized.
$hoursLater = new DateTimeImmutable("{$today}T14:00:00");
$dueHoursLater = $repo->dueReminderItems($hoursLater, $graceMinutes);
assertEqualsDR(0, count($dueHoursLater), 'A PRN group member past the grace window should no longer count as due-now.');

// ── Scenario 3: the same hours-later call with no grace bound supplied
// (the prior, unbounded default) still returns the stale PRN item — this
// pins down the old behavior for callers that don't have a grace value
// handy, and demonstrates what scenario 2's bound actually fixes.
$dueHoursLaterUnbounded = $repo->dueReminderItems($hoursLater);
assertEqualsDR(1, count($dueHoursLaterUnbounded), 'Without a grace bound, the stale PRN item remains in the unbounded due-now list.');

echo "DueReminderGroupMembersTest passed.\n";
