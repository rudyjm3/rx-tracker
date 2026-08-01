<?php

declare(strict_types=1);

require __DIR__ . '/../includes/MedicationRepository.php';

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function assertFloatEquals(float $expected, float $actual, string $message, float $epsilon = 0.0005): void
{
    if (abs($expected - $actual) > $epsilon) {
        throw new RuntimeException($message . "\nExpected: {$expected}\nActual: {$actual}");
    }
}

/**
 * Regression coverage for a grouped-reminder "Take All" bug report: clicking
 * Take All on a multi-medication reminder recorded nothing and left inventory
 * untouched, while taking the same medications individually worked fine. The
 * actual defect was client-side (assets/js/app.js's alarmTakeBtn handler read
 * alarmGroupItems *after* hideAlarmOverlay() had already reset it to [],
 * silently iterating zero items), but these tests lock in the server-side
 * contract every one of these flows depends on: recordDoseStatus() must
 * persist and deduct for every group member independently, regardless of
 * feedback tracking, a co-member's stock level, or the taking medication's
 * own pre-take quantity.
 */

function findByName(array $rows, string $name): array
{
    $match = array_values(array_filter($rows, static fn (array $r): bool => $r['name'] === $name));
    if ($match === []) {
        throw new RuntimeException("Medication not found: {$name}");
    }
    return $match[0];
}

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

$today = (new DateTimeImmutable('today'))->format('Y-m-d');

// ── Scenario 1: group take-all persists every member ────────────────────────
// Mirrors the alarmTakeBtn group handler: one recordDoseStatus call per
// medication in the group, same date/time/status, each carrying the group id.
[$db1, $repo1] = freshRepo();
$repo1->createMedication('Lisinopril', '', 'fixed_times', ['08:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 30.0, 1.0);
$repo1->createMedication('Metformin', '', 'fixed_times', ['08:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 30.0, 1.0);
$repo1->createMedication('Atorvastatin', '', 'fixed_times', ['08:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 30.0, 1.0);

$all1 = $repo1->activeMedications();
$lisId = (int) findByName($all1, 'Lisinopril')['id'];
$metId = (int) findByName($all1, 'Metformin')['id'];
$atoId = (int) findByName($all1, 'Atorvastatin')['id'];

$groupId1 = $repo1->createGroup('Morning Medication Group', '08:00:00');
$repo1->addMedicationToGroup($groupId1, $lisId);
$repo1->addMedicationToGroup($groupId1, $metId);
$repo1->addMedicationToGroup($groupId1, $atoId);

foreach ([$lisId, $metId, $atoId] as $memberId) {
    $repo1->recordDoseStatus($memberId, $today, '08:00:00', 'taken', '', null, $groupId1);
}

foreach (['Lisinopril' => $lisId, 'Metformin' => $metId, 'Atorvastatin' => $atoId] as $name => $memberId) {
    $status = $db1->query("SELECT status FROM dose_logs WHERE medication_id = {$memberId}")->fetchColumn();
    assertSameValue('taken', (string) $status, "{$name} should have a 'taken' dose_logs row after group take-all.");
    $current = (float) $repo1->findMedication($memberId)['current_quantity'];
    assertFloatEquals(29.0, $current, "{$name}'s inventory should be decremented by one dose after group take-all.");
}

// ── Scenario 2: a zero-stock group member doesn't block the others ─────────
[$db2, $repo2] = freshRepo();
$repo2->createMedication('Empty Med', '', 'fixed_times', ['08:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 0.0, 1.0);
$repo2->createMedication('Stocked Med A', '', 'fixed_times', ['08:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 30.0, 1.0);
$repo2->createMedication('Stocked Med B', '', 'fixed_times', ['08:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 30.0, 1.0);

$all2 = $repo2->activeMedications();
$emptyId = (int) findByName($all2, 'Empty Med')['id'];
$stockAId = (int) findByName($all2, 'Stocked Med A')['id'];
$stockBId = (int) findByName($all2, 'Stocked Med B')['id'];

$groupId2 = $repo2->createGroup('Evening Medication Group', '08:00:00');
$repo2->addMedicationToGroup($groupId2, $emptyId);
$repo2->addMedicationToGroup($groupId2, $stockAId);
$repo2->addMedicationToGroup($groupId2, $stockBId);

// Simulate the batch loop: the already-empty member is taken first and must
// not throw or otherwise stop the remaining members from being processed.
foreach ([$emptyId, $stockAId, $stockBId] as $memberId) {
    $repo2->recordDoseStatus($memberId, $today, '08:00:00', 'taken', '', null, $groupId2);
}

foreach (['Empty Med' => $emptyId, 'Stocked Med A' => $stockAId, 'Stocked Med B' => $stockBId] as $name => $memberId) {
    $status = $db2->query("SELECT status FROM dose_logs WHERE medication_id = {$memberId}")->fetchColumn();
    assertSameValue('taken', (string) $status, "{$name} should still be recorded as taken even though a co-member started at zero stock.");
}
assertFloatEquals(-1.0, (float) $repo2->findMedication($emptyId)['current_quantity'], 'The already-empty member is allowed to go negative rather than being blocked.');
assertFloatEquals(29.0, (float) $repo2->findMedication($stockAId)['current_quantity'], 'Stocked Med A must still be decremented normally.');
assertFloatEquals(29.0, (float) $repo2->findMedication($stockBId)['current_quantity'], 'Stocked Med B must still be decremented normally.');

// ── Scenario 3: a qty=1 medication commits the take unconditionally ────────
// The zero-pill/refill interstitial is decided from the *post-decrement*
// pill count (see includes/helpers.php's pill_status_payload), so a med at
// quantity 1 must still get its dose_logs row and its decrement to 0 — the
// interstitial is purely an after-the-fact prompt, never a gate on the take.
[$db3, $repo3] = freshRepo();
$repo3->createMedication('Ibuprofen', '', 'fixed_times', ['09:00:00'], null, null, false, 5, false, '', 'prescription', null, null, null, 'pills', 1.0, 1.0);
$ibuId = (int) findByName($repo3->activeMedications(), 'Ibuprofen')['id'];

$repo3->recordDoseStatus($ibuId, $today, '09:00:00', 'taken', '', null, null);

$status3 = $db3->query("SELECT status FROM dose_logs WHERE medication_id = {$ibuId}")->fetchColumn();
assertSameValue('taken', (string) $status3, 'A qty=1 medication must still be recorded as taken.');
assertFloatEquals(0.0, (float) $repo3->findMedication($ibuId)['current_quantity'], 'A qty=1 medication must be decremented to 0, not left uncommitted because the dose brings it to empty.');

echo "GroupTakeAllTest passed.\n";
