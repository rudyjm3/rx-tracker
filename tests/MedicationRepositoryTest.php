<?php

declare(strict_types=1);

require __DIR__ . '/../includes/MedicationRepository.php';

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "%s\nExpected: %s\nActual: %s",
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function testDatabase(): PDO
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec(
        'CREATE TABLE medications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            dose TEXT NOT NULL,
            reminder_time TEXT NOT NULL,
            instructions TEXT NOT NULL DEFAULT \'\',
            active INTEGER NOT NULL DEFAULT 1
        );
        CREATE TABLE dose_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            medication_id INTEGER NOT NULL,
            taken_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            note TEXT NOT NULL DEFAULT \'\'
        );'
    );

    return $db;
}

$db = testDatabase();
$repository = new MedicationRepository($db);

$repository->createMedication('Night Tablet', '5 mg', '21:00', 'Take with water');
$repository->createMedication('Morning Capsule', '10 mg', '08:00', 'Take with food');

$activeMedications = $repository->activeMedications();
assertSameValue(2, count($activeMedications), 'Two medications should be active after creation.');
assertSameValue('Morning Capsule', $activeMedications[0]['name'], 'Active medications should sort by reminder time.');

$morningMedicationId = (int) $activeMedications[0]['id'];
$repository->logDose($morningMedicationId, 'No side effects.');

$loggedMedicationIds = $repository->loggedMedicationIdsForDate((new DateTimeImmutable())->format('Y-m-d'));
assertSameValue([$morningMedicationId], $loggedMedicationIds, 'Logged IDs should include today\'s logged active medication.');

$recentLogs = $repository->recentLogs();
assertSameValue(1, count($recentLogs), 'Recent logs should include the dose log.');
assertSameValue('No side effects.', $recentLogs[0]['note'], 'Recent logs should include dose notes.');
assertSameValue('Morning Capsule', $recentLogs[0]['name'], 'Recent logs should join medication names.');

$repository->deactivateMedication($morningMedicationId);
$activeMedications = $repository->activeMedications();
assertSameValue(1, count($activeMedications), 'Deactivated medications should be removed from active plans.');
assertSameValue('Night Tablet', $activeMedications[0]['name'], 'The remaining active medication should be returned.');
assertSameValue([], $repository->loggedMedicationIdsForDate((new DateTimeImmutable())->format('Y-m-d')), 'Completed IDs should ignore deactivated medications.');

echo "MedicationRepository tests passed.\n";
