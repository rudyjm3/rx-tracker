<?php

declare(strict_types=1);

require __DIR__ . '/../includes/MedicationRepository.php';

function assertAdh(mixed $expected, mixed $actual, string $message): void
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

// ── Test: no logs at all → zero totals ───────────────────────────────────────

$repo->createMedication('Alpha', '', 'fixed_times', ['08:00:00', '20:00:00'], null, null, false, 0, false, '', 'prescription', null, null, null, 'pills', 10.0, 1.0);

$empty = $repo->adherenceForDateRange('2026-06-01', '2026-06-30');
assertAdh(0,  $empty['overall_pct'],     'No logs → overall_pct should be 0');
assertAdh(0,  $empty['total_scheduled'], 'No logs → total_scheduled should be 0');
assertAdh(0,  $empty['total_taken'],     'No logs → total_taken should be 0');
assertAdh([], $empty['per_medication'],  'No logs → per_medication should be empty');

// ── Test: 3 taken + 1 missed → 75 % ─────────────────────────────────────────

$all   = $repo->activeMedications();
$alphaId = (int) $all[0]['id'];

$repo->recordDoseStatus($alphaId, '2026-07-01', '08:00:00', 'taken',  '');
$repo->recordDoseStatus($alphaId, '2026-07-01', '20:00:00', 'taken',  '');
$repo->recordDoseStatus($alphaId, '2026-07-02', '08:00:00', 'taken',  '');
$repo->recordDoseStatus($alphaId, '2026-07-02', '20:00:00', 'missed', '');

$result = $repo->adherenceForDateRange('2026-07-01', '2026-07-02');
assertAdh(75, $result['overall_pct'],    '3 taken + 1 missed → 75%');
assertAdh(4,  $result['total_scheduled'], '3 taken + 1 missed → 4 scheduled');
assertAdh(3,  $result['total_taken'],    '3 taken');
assertAdh(1,  $result['total_missed'],   '1 missed');
assertAdh(0,  $result['total_skipped'],  '0 skipped');
assertAdh(1,  count($result['per_medication']), 'One medication in breakdown');

$medRow = $result['per_medication'][0];
assertAdh($alphaId, (int) $medRow['id'],     'Per-med id matches');
assertAdh('Alpha',  (string) $medRow['name'], 'Per-med name matches');
assertAdh(75,       (int) $medRow['pct'],     'Per-med pct is 75');

// ── Test: skipped counts against adherence ────────────────────────────────────

$repo->createMedication('Beta', '', 'fixed_times', ['12:00:00'], null, null, false, 0, false, '', 'prescription', null, null, null, 'pills', 5.0, 1.0);
$allAfterBeta = $repo->activeMedications();
$betaId = (int) array_values(array_filter($allAfterBeta, static fn(array $r): bool => $r['name'] === 'Beta'))[0]['id'];

$repo->recordDoseStatus($betaId, '2026-07-03', '12:00:00', 'taken',   '');
$repo->recordDoseStatus($betaId, '2026-07-04', '12:00:00', 'skipped', '');

$skipResult = $repo->adherenceForDateRange('2026-07-03', '2026-07-04');
$betaRow    = array_values(array_filter($skipResult['per_medication'], static fn(array $r): bool => (int) $r['id'] === $betaId))[0];
assertAdh(50, (int) $betaRow['pct'],     'Skipped reduces adherence: 1 taken + 1 skipped → 50%');
assertAdh(1,  (int) $betaRow['skipped'], 'Skipped count is 1');

// ── Test: as_needed medication excluded from adherence ─────────────────────────

$repo->createMedication('PRN Med', '', 'fixed_times', ['09:00:00'], null, null, true, 0, false, '', 'prescription', null, null, null, 'pills', 0.0, 1.0);
$allAfterPrn = $repo->activeMedications();
$prnId = (int) array_values(array_filter($allAfterPrn, static fn(array $r): bool => $r['name'] === 'PRN Med'))[0]['id'];
$repo->recordDoseStatus($prnId, '2026-07-05', '09:00:00', 'missed', '');

$prnResult = $repo->adherenceForDateRange('2026-07-05', '2026-07-05');
$prnInBreakdown = array_filter($prnResult['per_medication'], static fn(array $r): bool => (int) $r['id'] === $prnId);
assertAdh([], array_values($prnInBreakdown), 'as_needed med excluded from adherence breakdown');

// ── Test: multiple medications — overall sums correctly ────────────────────────

// Alpha: date range 2026-07-10: 2 taken
$repo->recordDoseStatus($alphaId, '2026-07-10', '08:00:00', 'taken', '');
$repo->recordDoseStatus($alphaId, '2026-07-10', '20:00:00', 'taken', '');
// Beta: date range 2026-07-10: 1 missed
$repo->recordDoseStatus($betaId, '2026-07-10', '12:00:00', 'missed', '');

$multiResult = $repo->adherenceForDateRange('2026-07-10', '2026-07-10');
// Total: 2 taken + 1 missed = 3 scheduled → 66% (rounds from 66.67)
assertAdh(3,  $multiResult['total_scheduled'], 'Multi-med: 3 total scheduled');
assertAdh(2,  $multiResult['total_taken'],     'Multi-med: 2 total taken');
assertAdh(1,  $multiResult['total_missed'],    'Multi-med: 1 total missed');
assertAdh(67, $multiResult['overall_pct'],     'Multi-med: overall pct is 67 (rounded from 66.67)');
assertAdh(2,  count($multiResult['per_medication']), 'Multi-med: 2 meds in breakdown');

// ── Test: 100% adherence ──────────────────────────────────────────────────────

$repo->createMedication('Gamma', '', 'fixed_times', ['06:00:00'], null, null, false, 0, false, '', 'prescription', null, null, null, 'pills', 0.0, 1.0);
$allAfterGamma = $repo->activeMedications();
$gammaId = (int) array_values(array_filter($allAfterGamma, static fn(array $r): bool => $r['name'] === 'Gamma'))[0]['id'];
$repo->recordDoseStatus($gammaId, '2026-07-15', '06:00:00', 'taken', '');

$perfectResult = $repo->adherenceForDateRange('2026-07-15', '2026-07-15');
$gammaRow = array_values(array_filter($perfectResult['per_medication'], static fn(array $r): bool => (int) $r['id'] === $gammaId))[0];
assertAdh(100, (int) $gammaRow['pct'], '100% adherence when only taken');

// ── Test: missedDoseCount ─────────────────────────────────────────────────────

// Create a fixed-times med scheduled at 08:00 and 14:00
$repo->createMedication('Schedule Med', '', 'fixed_times', ['08:00:00', '14:00:00'], null, null, false, 0, false, '', 'prescription', null, null, null, 'pills', 0.0, 1.0);
$allForSched = $repo->activeMedications();
$schedId = (int) array_values(array_filter($allForSched, static fn(array $r): bool => $r['name'] === 'Schedule Med'))[0]['id'];

$checkDate = (new DateTimeImmutable())->format('Y-m-d');

// Pre-clear other active meds' today slots so they don't inflate the count
$repo->recordDoseStatus($alphaId, $checkDate, '08:00:00', 'skipped', '');
$repo->recordDoseStatus($alphaId, $checkDate, '20:00:00', 'skipped', '');
$repo->recordDoseStatus($betaId,  $checkDate, '12:00:00', 'skipped', '');
$repo->recordDoseStatus($gammaId, $checkDate, '06:00:00', 'skipped', '');

// At 15:00 today, both 08:00 and 14:00 are past — neither logged → count = 2
$countBefore = $repo->missedDoseCount($checkDate, '15:00:00');
assertAdh(2, $countBefore, 'Both schedule slots past 15:00 with no logs → missedDoseCount = 2');

// Mark 08:00 as taken
$repo->recordDoseStatus($schedId, $checkDate, '08:00:00', 'taken', '');
$countAfterTaken = $repo->missedDoseCount($checkDate, '15:00:00');
assertAdh(1, $countAfterTaken, 'After one taken, missedDoseCount drops to 1');

// Mark 14:00 as skipped — skipped is not in missed count
$repo->recordDoseStatus($schedId, $checkDate, '14:00:00', 'skipped', '');
$countAfterSkipped = $repo->missedDoseCount($checkDate, '15:00:00');
assertAdh(0, $countAfterSkipped, 'After taken + skipped, missedDoseCount = 0');

// Doses not yet due (future time) are not counted
$countFuture = $repo->missedDoseCount($checkDate, '07:00:00');
assertAdh(0, $countFuture, 'Doses with reminder_time >= currentTime are not counted as missed');

// ── Test: adherence_enabled = 0 exempts med from finalizeMissedDoses ──────────

// Create a med with setup_status = active and adherence_enabled = 0
// The column is added by ensureOnboardingColumns(); we set it via a direct update.
$repo->createMedication('No-Adherence Med', '', 'fixed_times', ['00:01:00'], null, null, false, 0, false, '', 'prescription', null, null, null, 'pills', 0.0, 1.0);
$allNoAdh = $repo->activeMedications();
$noAdhId  = (int) array_values(array_filter($allNoAdh, static fn(array $r): bool => $r['name'] === 'No-Adherence Med'))[0]['id'];
$db->exec("UPDATE medications SET adherence_enabled = 0 WHERE id = {$noAdhId}");

// finalizeMissedDoses at 23:59 should NOT create a missed log for this med
$repo->finalizeMissedDoses(new DateTimeImmutable('today 23:59:00'), 0);

$missedRows = $db->query(
    "SELECT * FROM dose_logs WHERE medication_id = {$noAdhId} AND status = 'missed'"
)->fetchAll(PDO::FETCH_ASSOC);
assertAdh([], $missedRows, 'adherence_enabled=0 med is exempt from finalizeMissedDoses');

echo "AdherenceTest passed.\n";
