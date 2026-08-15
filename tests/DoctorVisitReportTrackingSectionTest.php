<?php

declare(strict_types=1);

require __DIR__ . '/../includes/DoctorVisitReport.php';

function assertRptSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

// medsForTrackingSection() decides which medications get a pain/mood chart section in the
// Doctor Visit Report. It must not rely on a medication's *current* feedback_type/active
// state, since that would silently drop historical in-range data for a medication whose
// tracking was later turned off or that was later discontinued. Reached via reflection
// since it is a private static helper with no DB dependency.
$method = new ReflectionMethod(DoctorVisitReport::class, 'medsForTrackingSection');
$method->setAccessible(true);

$call = static function (array $active, array $inactive, string $start, string $end, callable $tracks, callable $trend) use ($method): array {
    return $method->invoke(null, $active, $inactive, $start, $end, $tracks, $trend);
};

// -- Case 1: active medication currently tracking pain — always included, regardless of data.
$activeTracking = ['id' => 1, 'name' => 'Ibuprofen', 'feedback_type' => 'pain'];
$result = $call(
    [$activeTracking],
    [],
    '2026-01-01',
    '2026-01-31',
    static fn(array $m): bool => ($m['feedback_type'] ?? 'none') === 'pain',
    static fn(int $id, string $s, string $e): array => []
);
assertRptSame([$activeTracking], $result, 'Active medication currently tracking pain should be included even with no logged data.');

// -- Case 2 (the bug): medication no longer tracking pain, but has in-range historical data —
// must still be included, whether it is currently active or discontinued.
$noLongerTrackingButHasData = ['id' => 2, 'name' => 'Amoxicillin', 'feedback_type' => 'none'];
$dataByMed = [2 => [['date' => '2026-01-10', 'pain_level' => 4]]];
$result = $call(
    [$noLongerTrackingButHasData],
    [],
    '2026-01-01',
    '2026-01-31',
    static fn(array $m): bool => ($m['feedback_type'] ?? 'none') === 'pain',
    static fn(int $id, string $s, string $e): array => $dataByMed[$id] ?? []
);
assertRptSame([$noLongerTrackingButHasData], $result, 'Medication with tracking turned off must still show in-range historical pain data.');

// -- Case 3 (the bug): discontinued medication with in-range historical data must be included,
// not just medications from the active list.
$discontinuedWithData = ['id' => 3, 'name' => 'Old Med', 'feedback_type' => 'none'];
$dataByMed = [3 => [['date' => '2026-01-15', 'pain_level' => 7]]];
$result = $call(
    [], // not in the active list
    [$discontinuedWithData],
    '2026-01-01',
    '2026-01-31',
    static fn(array $m): bool => ($m['feedback_type'] ?? 'none') === 'pain',
    static fn(int $id, string $s, string $e): array => $dataByMed[$id] ?? []
);
assertRptSame([$discontinuedWithData], $result, 'Discontinued medication with in-range historical pain data must still appear in the report.');

// -- Case 4: medication neither tracking currently nor having any in-range data — excluded.
$untracked = ['id' => 4, 'name' => 'Vitamin D', 'feedback_type' => 'none'];
$result = $call(
    [$untracked],
    [],
    '2026-01-01',
    '2026-01-31',
    static fn(array $m): bool => ($m['feedback_type'] ?? 'none') === 'pain',
    static fn(int $id, string $s, string $e): array => []
);
assertRptSame([], $result, 'Medication with no current tracking and no in-range data should be excluded.');

// -- Case 5: a medication present in both the active and inactive lists (id collision) is
// only counted once, keyed by id.
$dupe = ['id' => 5, 'name' => 'Dupe', 'feedback_type' => 'pain'];
$result = $call(
    [$dupe],
    [$dupe],
    '2026-01-01',
    '2026-01-31',
    static fn(array $m): bool => true,
    static fn(int $id, string $s, string $e): array => []
);
assertRptSame(1, count($result), 'A medication appearing in both active and inactive lists should be de-duplicated by id.');

echo "All DoctorVisitReport tracking-section tests passed.\n";
