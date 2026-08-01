<?php

declare(strict_types=1);

require __DIR__ . '/../includes/MedicationRepository.php';

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

/**
 * Covers the zero-pill-count interstitial's supporting logic:
 * - deductInventory / adjustQuantity no longer clamp at zero.
 * - dateInventoryCrossedZero reconstructs a chronological ledger (starting
 *   quantity + refills/adjustments + taken doses, ordered by the date each
 *   applies, not insertion order) and finds when the balance first went
 *   non-positive — including the case where a backdated refill, logged
 *   after the fact, reconciles the count and clears the "ran out" date.
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

// ── Scenario: no clamping on deduction/adjustment ───────────────────────────

$repo->createMedication('Depletable Med', '', 'fixed_times', ['08:00:00'], null, null, false, 0, false, '', 'prescription', null, null, null, 'pills', 0.0, 1.0);
$all = $repo->activeMedications();
$depletableId = (int) array_values(array_filter($all, static fn(array $r): bool => $r['name'] === 'Depletable Med'))[0]['id'];

$repo->recordDoseStatus($depletableId, '2026-01-02', '08:00:00', 'taken', '');
assertSameValue(-1.0, (float) $repo->findMedication($depletableId)['current_quantity'], 'Deducting from an already-empty supply should go negative.');

$repo->adjustQuantity($depletableId, -5.0, 'manual recount');
assertSameValue(-5.0, (float) $repo->findMedication($depletableId)['current_quantity'], 'adjustQuantity should accept a negative corrected count.');

// ── Scenario: simple depletion — ran-out date is the dose that tips it ≤ 0 ──

$repo->createMedication('Simple Depletion Med', '', 'fixed_times', ['08:00:00'], null, null, false, 0, false, '', 'prescription', null, null, null, 'pills', 2.0, 1.0);
$all = $repo->activeMedications();
$simpleId = (int) array_values(array_filter($all, static fn(array $r): bool => $r['name'] === 'Simple Depletion Med'))[0]['id'];
$db->prepare('UPDATE medications SET start_date = :d WHERE id = :id')->execute(['d' => '2026-01-01', 'id' => $simpleId]);

$repo->recordDoseStatus($simpleId, '2026-01-02', '08:00:00', 'taken', '');
$repo->recordDoseStatus($simpleId, '2026-01-03', '08:00:00', 'taken', '');
assertSameValue(0.0, (float) $repo->findMedication($simpleId)['current_quantity'], 'Two doses against a starting count of 2 should land exactly at 0.');
assertSameValue('2026-01-03', $repo->dateInventoryCrossedZero($simpleId), 'Should report the date of the dose that brought the balance to 0.');

$repo->recordDoseStatus($simpleId, '2026-01-04', '08:00:00', 'taken', '');
assertSameValue(-1.0, (float) $repo->findMedication($simpleId)['current_quantity'], 'A third dose should push the count negative.');
assertSameValue('2026-01-03', $repo->dateInventoryCrossedZero($simpleId), 'The crossing date should still be the first dose that tipped the balance, not the latest one.');

// ── Scenario: a backdated refill reconciles the count and clears the date ──

$repo->createMedication('Backdated Refill Med', '', 'fixed_times', ['08:00:00'], null, null, false, 0, false, '', 'prescription', null, null, null, 'pills', 5.0, 1.0);
$all = $repo->activeMedications();
$backdatedId = (int) array_values(array_filter($all, static fn(array $r): bool => $r['name'] === 'Backdated Refill Med'))[0]['id'];
$db->prepare('UPDATE medications SET start_date = :d WHERE id = :id')->execute(['d' => '2026-01-01', 'id' => $backdatedId]);

foreach (['2026-01-02', '2026-01-03', '2026-01-04', '2026-01-06', '2026-01-07'] as $date) {
    $repo->recordDoseStatus($backdatedId, $date, '08:00:00', 'taken', '');
}
assertSameValue(0.0, (float) $repo->findMedication($backdatedId)['current_quantity'], 'Five doses against a starting count of 5 should land at exactly 0.');
assertSameValue('2026-01-07', $repo->dateInventoryCrossedZero($backdatedId), 'Without any refill yet, the last dose should be reported as the day supply ran out.');

// User remembers a refill they forgot to log, dated in between the existing doses.
$repo->logRefill($backdatedId, '2026-01-05', 10.0, 'forgot to log this at the time');
assertSameValue(10.0, (float) $repo->findMedication($backdatedId)['current_quantity'], 'Refill should add to the current ledger total regardless of its backdated date.');
assertSameValue(null, $repo->dateInventoryCrossedZero($backdatedId), 'A backdated refill inserted before the depleting doses should fully reconcile the ledger and clear the ran-out date.');

// ── Regression: a normal (non-backdated) refill in the middle of history ───
// must not corrupt the reconstructed starting balance. logRefill() overwrites
// medications.starting_quantity with the refill amount, so naively trusting
// that column after a refill has happened would double-count.

$repo->createMedication('Mid-History Refill Med', '', 'fixed_times', ['08:00:00'], null, null, false, 0, false, '', 'prescription', null, null, null, 'pills', 5.0, 1.0);
$all = $repo->activeMedications();
$midId = (int) array_values(array_filter($all, static fn(array $r): bool => $r['name'] === 'Mid-History Refill Med'))[0]['id'];
$db->prepare('UPDATE medications SET start_date = :d WHERE id = :id')->execute(['d' => '2026-02-01', 'id' => $midId]);

foreach (['2026-02-02', '2026-02-03', '2026-02-04', '2026-02-05', '2026-02-06'] as $date) {
    $repo->recordDoseStatus($midId, $date, '08:00:00', 'taken', '');
}
assertSameValue(0.0, (float) $repo->findMedication($midId)['current_quantity'], 'Five doses against a starting count of 5 should land at exactly 0.');

$repo->logRefill($midId, '2026-02-07', 10.0, 'routine refill');
assertSameValue(10.0, (float) $repo->findMedication($midId)['current_quantity'], 'Refill should bring the count back up to 10.');

foreach (['2026-02-08', '2026-02-09', '2026-02-10', '2026-02-11', '2026-02-12', '2026-02-13', '2026-02-14', '2026-02-15', '2026-02-16', '2026-02-17'] as $date) {
    $repo->recordDoseStatus($midId, $date, '08:00:00', 'taken', '');
}
assertSameValue(0.0, (float) $repo->findMedication($midId)['current_quantity'], 'Ten more doses against the refilled count of 10 should land at exactly 0.');
assertSameValue(
    '2026-02-17',
    $repo->dateInventoryCrossedZero($midId),
    'Should report the most recent depleting dose, not null — a naive read of the (overwritten) starting_quantity column would wrongly reconstruct a positive balance and never find a crossing.'
);

// ── Regression: an initial zero balance resolved by a refill before any ────
// dose was ever logged must not be reported as the "ran out on" date once a
// later, real depletion happens — only the most recent unresolved crossing
// should count.

$repo->createMedication('Zero Then Refill Med', '', 'fixed_times', ['08:00:00'], null, null, false, 0, false, '', 'prescription', null, null, null, 'pills', 0.0, 1.0);
$all = $repo->activeMedications();
$zeroThenRefillId = (int) array_values(array_filter($all, static fn(array $r): bool => $r['name'] === 'Zero Then Refill Med'))[0]['id'];
$db->prepare('UPDATE medications SET start_date = :d WHERE id = :id')->execute(['d' => '2026-03-01', 'id' => $zeroThenRefillId]);

// Refilled right away, before ever taking a dose at the zero balance.
$repo->logRefill($zeroThenRefillId, '2026-03-02', 5.0, 'initial fill logged late');
assertSameValue(5.0, (float) $repo->findMedication($zeroThenRefillId)['current_quantity'], 'Refill should bring the count up to 5.');

foreach (['2026-03-05', '2026-03-06', '2026-03-07', '2026-03-08', '2026-03-09'] as $date) {
    $repo->recordDoseStatus($zeroThenRefillId, $date, '08:00:00', 'taken', '');
}
assertSameValue(0.0, (float) $repo->findMedication($zeroThenRefillId)['current_quantity'], 'Five doses against the refilled count of 5 should land at exactly 0.');
assertSameValue(
    '2026-03-09',
    $repo->dateInventoryCrossedZero($zeroThenRefillId),
    'Should report the real depletion date, not the medication\'s original (already-resolved) zero starting balance.'
);

echo "RanOutOnDateTest passed.\n";
