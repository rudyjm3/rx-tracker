<?php

declare(strict_types=1);

// Regression tests for cross-tenant (IDOR) authorization on medication groups
// and on the medication schedule-rewrite path. See docs/CODE_REVIEW.md findings
// #1 and #2.

require __DIR__ . '/../includes/MedicationRepository.php';

function assertOwn(mixed $expected, mixed $actual, string $message): void
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

// Two separate tenants sharing the same database.
$repoA = new MedicationRepository($db, 1, null);
$repoB = new MedicationRepository($db, 2, null);

// ── Setup: each user owns one medication and one group ────────────────────────
$repoA->createMedication('A Med', '', 'fixed_times', ['08:00:00'], null, null, false, 2, false, '', 'prescription', 5.0, 'mg', 'tablet', 'pills', 10.0, 1.0);
$medA = (int) $repoA->activeMedications()[0]['id'];
$groupA = $repoA->createGroup('A Group', '08:00:00');

$repoB->createMedication('B Med', '', 'fixed_times', ['09:00:00'], null, null, false, 2, false, '', 'prescription', 5.0, 'mg', 'tablet', 'pills', 10.0, 1.0);
$medB = (int) $repoB->activeMedications()[0]['id'];
$groupB = $repoB->createGroup('B Group', '09:00:00');

// ── findGroup / deleteGroup / updateGroup are tenant-scoped ───────────────────
assertOwn(null, $repoB->findGroup($groupA), 'User B must not read User A\'s group.');
assertOwn('A Group', (string) $repoA->findGroup($groupA)['name'], 'User A can read own group.');

$repoB->deleteGroup($groupA);
assertOwn(true, $repoA->findGroup($groupA) !== null, 'User B must not delete User A\'s group.');

$repoB->updateGroup($groupA, 'Hacked', '00:00:00');
assertOwn('A Group', (string) $repoA->findGroup($groupA)['name'], 'User B must not rename User A\'s group.');

// ── addMedicationToGroup: both group and medication must be owned ─────────────
$repoB->addMedicationToGroup($groupA, $medB); // B's med into A's group — blocked
assertOwn(0, count($repoA->findGroup($groupA)['members']), 'User B must not add a med to User A\'s group.');

$repoB->addMedicationToGroup($groupB, $medA); // A's med into B's group — blocked
assertOwn(0, count($repoB->findGroup($groupB)['members']), 'User B must not add User A\'s med to own group.');

$repoA->addMedicationToGroup($groupA, $medA); // legit
assertOwn(1, count($repoA->findGroup($groupA)['members']), 'User A can add own med to own group.');

// ── removeMedicationFromGroup is scoped to owned medications ─────────────────
$repoB->removeMedicationFromGroup($medA); // B trying to unlink A's med — blocked
assertOwn(1, count($repoA->findGroup($groupA)['members']), 'User B must not remove User A\'s membership.');

$repoA->removeMedicationFromGroup($medA); // legit
assertOwn(0, count($repoA->findGroup($groupA)['members']), 'User A can remove own membership.');

// ── updateMedication must not rewrite another tenant\'s schedule ──────────────
$threw = false;
try {
    $repoB->updateMedication($medA, 'Pwned', '', 'fixed_times', ['22:00:00'], null, null, false, 2, false, '', 'prescription', 5.0, 'mg', 'tablet', 'pills', 10.0, 1.0);
} catch (RuntimeException) {
    $threw = true;
}
assertOwn(true, $threw, 'User B updating User A\'s medication should throw.');

$aMed = $repoA->findMedication($medA);
assertOwn('A Med', (string) $aMed['name'], 'User A\'s medication name must be unchanged.');
assertOwn(['08:00'], $aMed['times'], 'User A\'s schedule times must NOT be rewritten by User B.');

// Sanity: the owner can still update their own schedule.
$repoA->updateMedication($medA, 'A Med v2', '', 'fixed_times', ['07:30:00'], null, null, false, 2, false, '', 'prescription', 5.0, 'mg', 'tablet', 'pills', 10.0, 1.0);
assertOwn(['07:30'], $repoA->findMedication($medA)['times'], 'Owner can rewrite own schedule.');

echo "OwnershipTest passed.\n";
