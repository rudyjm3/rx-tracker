<?php

declare(strict_types=1);

require __DIR__ . '/../includes/AdherenceRepository.php';

function assertMTMSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

// medsTrackingMetricInRange() decides which medications the pain/mood tracking pages'
// medication pill list should include for a given days-filter window. It is the same
// algorithm as DoctorVisitReport::medsForTrackingSection() (see
// DoctorVisitReportTrackingSectionTest.php), extracted to AdherenceRepository so both the
// report and the tracking pages share one source of truth. Active medications are included
// by current feedback_type OR by in-range data; discontinued medications are included ONLY
// by in-range data (their current feedback_type is not trusted).

$db = new PDO('sqlite::memory:');
$repo = new AdherenceRepository($db, 1, null);

$call = static function (array $active, array $inactive, string $start, string $end, callable $tracks, callable $trend) use ($repo): array {
    return $repo->medsTrackingMetricInRange($active, $inactive, $start, $end, $tracks, $trend);
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
assertMTMSame([$activeTracking], $result, 'Active medication currently tracking pain should be included even with no logged data.');

// -- Case 2: medication no longer tracking pain, but has in-range historical data — must
// still be included, whether it is currently active or discontinued.
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
assertMTMSame([$noLongerTrackingButHasData], $result, 'Medication with tracking turned off must still show in-range historical pain data.');

// -- Case 3: discontinued medication with in-range historical data must be included, not
// just medications from the active list.
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
assertMTMSame([$discontinuedWithData], $result, 'Discontinued medication with in-range historical pain data must still appear in the pill list.');

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
assertMTMSame([], $result, 'Medication with no current tracking and no in-range data should be excluded.');

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
assertMTMSame(1, count($result), 'A medication appearing in both active and inactive lists should be de-duplicated by id.');

// -- Case 6: narrowing the window (simulating switching from the 30-day filter to Today)
// drops a medication whose only data falls outside the new, narrower range.
$onlyOldData = ['id' => 6, 'name' => 'Aspirin', 'feedback_type' => 'none'];
$dataByMed = [6 => [['date' => '2026-01-05', 'pain_level' => 2]]];
$trend = static fn(int $id, string $s, string $e): array => array_values(array_filter(
    $dataByMed[$id] ?? [],
    static fn(array $row): bool => $row['date'] >= $s && $row['date'] <= $e
));
$todayOnly = $call([], [$onlyOldData], '2026-01-31', '2026-01-31', static fn(array $m): bool => false, $trend);
assertMTMSame([], $todayOnly, 'Medication whose only data predates the selected window should be excluded from the narrower filter.');
$thirtyDays = $call([], [$onlyOldData], '2026-01-01', '2026-01-31', static fn(array $m): bool => false, $trend);
assertMTMSame([$onlyOldData], $thirtyDays, 'Same medication should reappear once the selected window widens to cover its logged date.');

echo "All AdherenceRepository::medsTrackingMetricInRange tests passed.\n";
