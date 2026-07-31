<?php

declare(strict_types=1);

require __DIR__ . '/../includes/helpers.php';

function assertSame_(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

/**
 * Regression test: daysUntilRunout() for interval medications must derive
 * doses/day from the same slot count MedicationRepository::timesForDate()
 * generates, not round(24 / interval_hours), which drifts whenever the
 * interval doesn't divide 24 evenly (e.g. 5h -> 4 real slots/day, not 5).
 */

// 5h interval starting at 08:00 -> slots at 08:00, 13:00, 18:00, 23:00 = 4/day,
// not round(24 / 5) = 5/day.
$med5h = [
    'schedule_mode' => 'interval',
    'interval_hours' => 5,
    'first_dose_time' => '08:00:00',
    'quantity_per_dose' => 1.0,
    'current_quantity' => 40.0,
];
assertSame_(10, daysUntilRunout($med5h), '5h interval should use 4 real slots/day (40 / 4 = 10), not round(24/5)=5 (which would give 8).');

// 8h interval divides 24 evenly, so both approaches should agree: 3/day.
$med8h = [
    'schedule_mode' => 'interval',
    'interval_hours' => 8,
    'first_dose_time' => '06:00:00',
    'quantity_per_dose' => 1.0,
    'current_quantity' => 30.0,
];
assertSame_(10, daysUntilRunout($med8h), '8h interval divides 24 evenly, so 30 / 3 = 10 days.');

// A late first_dose_time shortens the last window, dropping a slot: 6h interval
// starting at 20:00 only fits 20:00 -> one slot before midnight, not 4.
$medLateStart = [
    'schedule_mode' => 'interval',
    'interval_hours' => 6,
    'first_dose_time' => '20:00:00',
    'quantity_per_dose' => 1.0,
    'current_quantity' => 10.0,
];
assertSame_(10, daysUntilRunout($medLateStart), 'Late first_dose_time (20:00, 6h interval) only fits 1 slot/day, so 10 / 1 = 10 days.');

// Missing first_dose_time means no slot generator can run; must not guess.
$medNoFirstDose = [
    'schedule_mode' => 'interval',
    'interval_hours' => 5,
    'first_dose_time' => '',
    'quantity_per_dose' => 1.0,
    'current_quantity' => 40.0,
];
assertSame_(null, daysUntilRunout($medNoFirstDose), 'Missing first_dose_time should return null rather than guessing doses/day.');

echo "DaysUntilRunoutTest passed: interval run-out estimate matches the actual slot generator.\n";
