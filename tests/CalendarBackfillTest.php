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
CREATE TABLE medication_schedule_times (id INTEGER PRIMARY KEY AUTOINCREMENT, medication_id INTEGER, reminder_time TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE dose_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, medication_id INTEGER, scheduled_for_date TEXT, scheduled_time TEXT, status TEXT, note TEXT, taken_at TEXT DEFAULT CURRENT_TIMESTAMP, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE medication_status_events (id INTEGER PRIMARY KEY AUTOINCREMENT, medication_id INTEGER NOT NULL, event TEXT NOT NULL, event_at TEXT DEFAULT CURRENT_TIMESTAMP, reason TEXT NOT NULL DEFAULT '', comment TEXT NOT NULL DEFAULT '', created_at TEXT DEFAULT CURRENT_TIMESTAMP);");

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

// Simulate this schedule having existed unchanged since well before the blank
// day, so the schedule-stability guard doesn't block these (valid) backfills.
$db->exec("UPDATE medication_schedule_times SET created_at = '2026-07-01 00:00:00' WHERE medication_id = {$medId}");

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

// ── Test: a schedule change after a blank day must not fabricate wrong misses ──
// If a medication's schedule was edited AFTER a blank past day, todaySchedule()
// only knows the current (new) schedule — backfilling with it would insert
// missed doses for slots that never actually existed on that day. The
// medication's current schedule-times must predate the blank day for backfill
// to trust it.

$repo->createMedication(
    'ScheduleChanged',
    '',
    'fixed_times',
    ['07:00:00'],
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
    '2026-07-15'
);
$allSched = $repo->activeMedications();
$schedMedId = (int) array_values(array_filter($allSched, static fn(array $r): bool => $r['name'] === 'ScheduleChanged'))[0]['id'];

// Simulate the original 1x-daily schedule having existed well before the blank day.
$db->exec("UPDATE medication_schedule_times SET created_at = '2026-07-15 00:00:00' WHERE medication_id = {$schedMedId}");

// User later (2026-08-03) changes the medication to a 2x-daily schedule. This
// deletes the old schedule_times row and inserts new ones.
$repo->updateMedication(
    $schedMedId,
    'ScheduleChanged',
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
    '2026-07-15'
);
$db->exec("UPDATE medication_schedule_times SET created_at = '2026-08-03 12:00:00' WHERE medication_id = {$schedMedId}");

// 2026-08-01 predates the schedule change and was never finalized. Backfilling
// it now must NOT use the new 2x-daily schedule to invent misses for a slot
// (19:00) that didn't exist back when the day actually happened.
$repo->backfillMissedDosesForDates(['2026-08-01'], new DateTimeImmutable('2026-08-05 12:00:00'), 60);
$schedRowCount = (int) $db->query("SELECT COUNT(*) AS c FROM dose_logs WHERE medication_id = {$schedMedId} AND scheduled_for_date = '2026-08-01'")->fetch()['c'];
assertCB(0, $schedRowCount, 'Backfill skips a medication whose schedule changed after the target date, rather than fabricating doses');

echo "CalendarBackfillTest (schedule-change guard) passed.\n";

// ── Test: a medication discontinued after a blank day still backfills ──────────
// Reproduces #80: activeMedications() only reflects the medication's *current*
// active status, so a medication discontinued after a blank historical day was
// silently excluded from backfill and that day stayed blank forever, even
// though the medication genuinely was active (and due) on that day.

$repo->createMedication(
    'DiscontinuedMed',
    '',
    'fixed_times',
    ['07:00:00'],
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
$allDisc = $repo->activeMedications();
$discMedId = (int) array_values(array_filter($allDisc, static fn(array $r): bool => $r['name'] === 'DiscontinuedMed'))[0]['id'];

// Simulate this schedule having existed unchanged since well before the blank day.
$db->exec("UPDATE medication_schedule_times SET created_at = '2026-07-01 00:00:00' WHERE medication_id = {$discMedId}");

// The medication is discontinued on 2026-08-11 — after the blank day (2026-08-10)
// we're about to backfill, so it was still active back then.
$repo->deactivateMedication($discMedId, 'no longer needed');
$db->exec("UPDATE medication_status_events SET event_at = '2026-08-11 09:00:00' WHERE medication_id = {$discMedId}");

$discRowCountBefore = (int) $db->query("SELECT COUNT(*) AS c FROM dose_logs WHERE medication_id = {$discMedId} AND scheduled_for_date = '2026-08-10'")->fetch()['c'];
assertCB(0, $discRowCountBefore, 'Untouched past day for a since-discontinued medication has no dose_logs rows yet');

// Other medications created by earlier tests (DailyMed, ScheduleChanged) are still
// active with open-ended schedules, so they legitimately also get missed doses
// backfilled for this date — scope assertions to DiscontinuedMed's own dose_logs
// rows rather than the month's aggregate markers.
$repo->backfillMissedDosesForDates(['2026-08-10'], new DateTimeImmutable('2026-08-12 12:00:00'), 60);

$discStatus = $db->query("SELECT status FROM dose_logs WHERE medication_id = {$discMedId} AND scheduled_for_date = '2026-08-10' AND scheduled_time = '07:00:00'")->fetch();
assertCB('missed', $discStatus['status'] ?? null, 'Backfill marks the discontinued medication\'s slot as missed for a date it was still active on');

// Re-running must stay idempotent.
$repo->backfillMissedDosesForDates(['2026-08-10'], new DateTimeImmutable('2026-08-12 12:00:00'), 60);
$discRowCount = (int) $db->query("SELECT COUNT(*) AS c FROM dose_logs WHERE medication_id = {$discMedId} AND scheduled_for_date = '2026-08-10'")->fetch()['c'];
assertCB(1, $discRowCount, 'Backfill for a discontinued medication is idempotent: re-running does not create duplicate rows');

// A date after the discontinuation must NOT get a fabricated missed dose.
$repo->backfillMissedDosesForDates(['2026-08-12'], new DateTimeImmutable('2026-08-13 12:00:00'), 60);
$discAfterRowCount = (int) $db->query("SELECT COUNT(*) AS c FROM dose_logs WHERE medication_id = {$discMedId} AND scheduled_for_date = '2026-08-12'")->fetch()['c'];
assertCB(0, $discAfterRowCount, 'Backfill does not fabricate a missed dose for a date after the medication was discontinued');

echo "CalendarBackfillTest (discontinued-medication backfill) passed.\n";

// ── Test: an inactive medication with no recorded status history stays blank ───
// A medication that's inactive now but has zero medication_status_events rows
// (e.g. discontinued via a legacy path, or before status-event tracking existed
// on an upgraded database) has genuinely unknown history. wasMedicationActiveOnDate()
// fails open to "active" when there's no event at all, which is correct for a
// currently-active medication with no history — but historicallyActiveMedications()
// must NOT rely on that default to include such a medication, or it would fabricate
// missed doses for arbitrarily old dates. It should stay excluded, leaving the day
// blank exactly as it did before this backfill improvement.

$repo->createMedication(
    'LegacyInactiveMed',
    '',
    'fixed_times',
    ['07:00:00'],
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
$allLegacy = $repo->activeMedications();
$legacyMedId = (int) array_values(array_filter($allLegacy, static fn(array $r): bool => $r['name'] === 'LegacyInactiveMed'))[0]['id'];

$db->exec("UPDATE medication_schedule_times SET created_at = '2026-07-01 00:00:00' WHERE medication_id = {$legacyMedId}");

// Deactivated directly (no medication_status_events row), simulating a medication
// whose discontinuation predates status-event tracking.
$db->exec("UPDATE medications SET active = 0 WHERE id = {$legacyMedId}");

$repo->backfillMissedDosesForDates(['2026-08-15'], new DateTimeImmutable('2026-08-16 12:00:00'), 60);
$legacyRowCount = (int) $db->query("SELECT COUNT(*) AS c FROM dose_logs WHERE medication_id = {$legacyMedId} AND scheduled_for_date = '2026-08-15'")->fetch()['c'];
assertCB(0, $legacyRowCount, 'An inactive medication with no status-event history is not backfilled (unknown history, stays blank)');

echo "CalendarBackfillTest (eventless inactive medication stays blank) passed.\n";

// ── Test: a day with SOME doses already logged still backfills the rest ────────
// Reproduces the reported bug: a day that already has a calendar marker (because
// one dose group was logged) was treated by the calendar page's self-heal as
// "fully accounted for" and skipped, permanently hiding any other scheduled slot
// on that same day that was never logged at all — e.g. a morning group logged as
// taken while an evening group is never touched.

$repo->createMedication(
    'PartialDayMed',
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
$allPartial = $repo->activeMedications();
$partialMedId = (int) array_values(array_filter($allPartial, static fn(array $r): bool => $r['name'] === 'PartialDayMed'))[0]['id'];
$db->exec("UPDATE medication_schedule_times SET created_at = '2026-07-01 00:00:00' WHERE medication_id = {$partialMedId}");

// Only the morning slot was ever logged (as taken) for 2026-08-20; the evening slot
// was never touched by the user and no finalize ever ran for that date.
$repo->recordDoseStatus($partialMedId, '2026-08-20', '07:00:00', 'taken', '');

// Other medications from earlier tests in this shared DB (DailyMed, ScheduleChanged,
// DiscontinuedMed) are still active with open-ended schedules, so they legitimately
// also get missed doses backfilled for 2026-08-20 — scope assertions to
// PartialDayMed's own dose_logs rows rather than the month's aggregate markers.
$partialBeforeStatuses = $db->query(
    "SELECT scheduled_time, status FROM dose_logs WHERE medication_id = {$partialMedId} AND scheduled_for_date = '2026-08-20' ORDER BY scheduled_time"
)->fetchAll();
assertCB([['scheduled_time' => '07:00:00', 'status' => 'taken']], $partialBeforeStatuses, 'Only the logged morning slot exists before backfill; evening slot is not yet reflected as missed');

// The calendar page's self-heal now backfills every past day in the visible month,
// not just marker-less ones -- re-running it against 2026-08-20 (which already has a
// marker from PartialDayMed's own morning dose) must still finalize the unlogged
// 19:00 slot as missed.
$repo->backfillMissedDosesForDates(['2026-08-20'], new DateTimeImmutable('2026-08-21 12:00:00'), 60);

$partialAfterStatuses = $db->query(
    "SELECT scheduled_time, status FROM dose_logs WHERE medication_id = {$partialMedId} AND scheduled_for_date = '2026-08-20' ORDER BY scheduled_time"
)->fetchAll();
assertCB(
    [['scheduled_time' => '07:00:00', 'status' => 'taken'], ['scheduled_time' => '19:00:00', 'status' => 'missed']],
    $partialAfterStatuses,
    'Previously-logged morning slot is untouched and the previously-unlogged evening slot is now finalized as missed'
);

$partialLogs = $repo->calendarLogsForMonth('2026-08-20', '2026-08-20');
$partialLogsForMed = array_values(array_filter($partialLogs, static fn(array $r): bool => (int) $r['medication_id'] === $partialMedId));
assertCB(2, count($partialLogsForMed), 'Day-detail data now includes both the taken and the missed slot');

// Re-running (e.g. viewing the calendar twice) must stay idempotent.
$repo->backfillMissedDosesForDates(['2026-08-20'], new DateTimeImmutable('2026-08-21 12:00:00'), 60);
$partialRowCount = (int) $db->query("SELECT COUNT(*) AS c FROM dose_logs WHERE medication_id = {$partialMedId} AND scheduled_for_date = '2026-08-20'")->fetch()['c'];
assertCB(2, $partialRowCount, 'Re-backfilling a partially-logged day is idempotent: no duplicate rows');

echo "CalendarBackfillTest (partially-logged day still backfills remaining slots) passed.\n";
