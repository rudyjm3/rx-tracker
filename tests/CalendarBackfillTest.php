<?php

declare(strict_types=1);

require __DIR__ . '/../includes/MedicationRepository.php';

function assertCB(mixed $expected, mixed $actual, string $message): void
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

// ── Test: a past day nobody ever finalized shows blank, then self-heals ────────
// Reproduces the reported bug: finalizeMissedDoses() only ever evaluated
// "today" when it ran, so a day with no page-load/cron activity at all never
// got any dose_logs rows and stayed permanently blank on the calendar.

$repo->createMedication(
    'DailyMed',
    '',
    'fixed_times',
    ['07:00:00', '19:00:00'],
    null,
    null,
    false,
    0,
    false,
    '',
    'prescription',
    null,
    null,
    null,
    'pills',
    30.0,
    1.0,
    [],
    '2026-08-01'
);
$all = $repo->activeMedications();
$medId = (int) array_values(array_filter($all, static fn(array $r): bool => $r['name'] === 'DailyMed'))[0]['id'];

// Neither finalizeMissedDoses() nor any user action ever touched 2026-08-04 —
// simulating a day the owner never opened the app and no cron ran.
$markersBefore = $repo->calendarMarkersForMonth('2026-08-01', '2026-08-31');
assertCB(false, isset($markersBefore['2026-08-04']), 'Untouched past day has no calendar marker at all (blank cell)');

// The calendar page's self-heal runs backfill for any blank past date in the
// displayed month once "now" has moved past it.
$repo->backfillMissedDosesForDates(['2026-08-04'], new DateTimeImmutable('2026-08-05 12:00:00'), 60);

$markersAfter = $repo->calendarMarkersForMonth('2026-08-01', '2026-08-31');
assertCB(true, isset($markersAfter['2026-08-04']), 'Backfilled day now has a calendar marker');
assertCB(2, $markersAfter['2026-08-04']['missed'], 'Backfilled day shows both slots as missed');
assertCB(0, $markersAfter['2026-08-04']['taken'], 'Backfilled day has no taken doses');

// Re-running the backfill (e.g. viewing the calendar twice) must not duplicate rows.
$repo->backfillMissedDosesForDates(['2026-08-04'], new DateTimeImmutable('2026-08-05 12:00:00'), 60);
$rowCount = (int) $db->query("SELECT COUNT(*) AS c FROM dose_logs WHERE medication_id = {$medId} AND scheduled_for_date = '2026-08-04'")->fetch()['c'];
assertCB(2, $rowCount, 'Backfill is idempotent: re-running does not create duplicate rows');

// A day the user genuinely had nothing scheduled for (before start_date) must
// stay correctly blank rather than being force-populated.
$repo->backfillMissedDosesForDates(['2026-07-31'], new DateTimeImmutable('2026-08-05 12:00:00'), 60);
$markersBeforeStart = $repo->calendarMarkersForMonth('2026-07-01', '2026-07-31');
assertCB(false, isset($markersBeforeStart['2026-07-31']), 'Day before medication start_date stays blank after backfill');

echo "CalendarBackfillTest passed.\n";
