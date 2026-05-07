<?php

declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/MedicationRepository.php';

$repository = new MedicationRepository(db());
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_string('action');

    try {
        if ($action === 'add_medication') {
            $name = post_string('name');
            $dose = post_string('dose');
            $reminderTime = post_string('reminder_time');
            $instructions = post_string('instructions');

            if ($name === '' || $dose === '' || $reminderTime === '') {
                throw new RuntimeException('Medication name, dose, and reminder time are required.');
            }

            $repository->createMedication($name, $dose, $reminderTime, $instructions);
            redirect_home();
        }

        if ($action === 'log_dose') {
            $medicationId = (int) post_string('medication_id');

            if ($medicationId <= 0) {
                throw new RuntimeException('Choose a medication before logging a dose.');
            }

            $repository->logDose($medicationId, post_string('note'));
            redirect_home();
        }

        if ($action === 'deactivate_medication') {
            $medicationId = (int) post_string('medication_id');

            if ($medicationId > 0) {
                $repository->deactivateMedication($medicationId);
            }

            redirect_home();
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$medications = $repository->activeMedications();
$recentLogs = $repository->recentLogs();
$completedMedicationIds = $repository->loggedMedicationIdsForDate(today());
$completedCount = count($completedMedicationIds);
$activeCount = count($medications);
$adherence = $activeCount > 0 ? (int) round(($completedCount / $activeCount) * 100) : 0;
$nextMedication = null;

foreach ($medications as $medication) {
    if (!in_array((int) $medication['id'], $completedMedicationIds, true)) {
        $nextMedication = $medication;
        break;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Med Log helps people track medication doses, reminders, and adherence with PHP and MySQL.">
    <title>Med Log</title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <script src="assets/js/app.js" defer></script>
</head>
<body>
    <main class="app-shell">
        <section class="hero">
            <div>
                <p class="eyebrow">Medication tracking and reminders</p>
                <h1>Med Log keeps today's doses clear.</h1>
                <p class="hero-copy">
                    Track your medication plan, log doses, and review recent adherence from a simple
                    PHP and MySQL web app.
                </p>
            </div>
            <div class="hero-card" aria-label="Today's adherence summary">
                <span class="stat-label">Today's adherence</span>
                <strong><?= e((string) $adherence) ?>%</strong>
                <span><?= e((string) $completedCount) ?> of <?= e((string) $activeCount) ?> active medications logged</span>
            </div>
        </section>

        <?php if ($error !== null): ?>
            <div class="alert" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <section class="dashboard-grid" aria-label="Medication dashboard">
            <article class="panel next-dose">
                <div class="panel-heading">
                    <span aria-hidden="true">⏰</span>
                    <h2>Next dose</h2>
                </div>
                <?php if ($nextMedication !== null): ?>
                    <div class="next-dose-card">
                        <span><?= e(substr((string) $nextMedication['reminder_time'], 0, 5)) ?></span>
                        <h3><?= e((string) $nextMedication['name']) ?></h3>
                        <p><?= e((string) $nextMedication['dose']) ?></p>
                        <small><?= e((string) ($nextMedication['instructions'] ?: 'No special instructions')) ?></small>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <span aria-hidden="true">✓</span>
                        <p>All active medications have been logged today.</p>
                    </div>
                <?php endif; ?>
            </article>

            <article class="panel">
                <div class="panel-heading">
                    <span aria-hidden="true">💊</span>
                    <h2>Log a dose</h2>
                </div>
                <form class="stacked-form" method="post" action="index.php">
                    <input type="hidden" name="action" value="log_dose">
                    <label>
                        Medication
                        <select name="medication_id" required>
                            <option value="">Choose medication</option>
                            <?php foreach ($medications as $medication): ?>
                                <option value="<?= e((string) $medication['id']) ?>">
                                    <?= e((string) $medication['name']) ?> · <?= e((string) $medication['dose']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        Note (optional)
                        <input name="note" maxlength="255" placeholder="Example: taken after lunch">
                    </label>
                    <button type="submit">Log dose now</button>
                </form>
            </article>
        </section>

        <section class="content-grid">
            <article class="panel">
                <div class="panel-heading">
                    <span aria-hidden="true">＋</span>
                    <h2>Add medication</h2>
                </div>
                <form class="medication-form" method="post" action="index.php">
                    <input type="hidden" name="action" value="add_medication">
                    <label>
                        Name
                        <input name="name" maxlength="120" required placeholder="Medication name">
                    </label>
                    <label>
                        Dose
                        <input name="dose" maxlength="120" required placeholder="10 mg, 1 capsule, etc.">
                    </label>
                    <label>
                        Daily reminder time
                        <input type="time" name="reminder_time" required>
                    </label>
                    <label>
                        Instructions
                        <input name="instructions" maxlength="255" placeholder="Take with food">
                    </label>
                    <button type="submit">Add medication</button>
                </form>
            </article>

            <article class="panel medication-list-panel">
                <div class="panel-heading">
                    <span aria-hidden="true">📅</span>
                    <h2>Medication plan</h2>
                </div>
                <div class="medication-list">
                    <?php if ($medications === []): ?>
                        <p class="muted">No active medications yet. Add one to start tracking.</p>
                    <?php endif; ?>
                    <?php foreach ($medications as $medication): ?>
                        <div class="medication-row">
                            <div>
                                <strong><?= e((string) $medication['name']) ?></strong>
                                <p><?= e((string) $medication['dose']) ?> at <?= e(substr((string) $medication['reminder_time'], 0, 5)) ?></p>
                                <small><?= e((string) ($medication['instructions'] ?: 'No instructions added')) ?></small>
                            </div>
                            <div class="row-actions">
                                <?php if (in_array((int) $medication['id'], $completedMedicationIds, true)): ?>
                                    <span class="done-pill">Done</span>
                                <?php endif; ?>
                                <form method="post" action="index.php" data-confirm="Remove this medication from your active plan?">
                                    <input type="hidden" name="action" value="deactivate_medication">
                                    <input type="hidden" name="medication_id" value="<?= e((string) $medication['id']) ?>">
                                    <button type="submit" class="icon-button" aria-label="Remove <?= e((string) $medication['name']) ?>">×</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>
        </section>

        <section class="panel history-panel">
            <div class="panel-heading">
                <span aria-hidden="true">✓</span>
                <h2>Recent dose history</h2>
            </div>
            <?php if ($recentLogs !== []): ?>
                <ol class="history-list">
                    <?php foreach ($recentLogs as $log): ?>
                        <li>
                            <span><?= e((new DateTimeImmutable((string) $log['taken_at']))->format('g:i A')) ?></span>
                            <div>
                                <strong><?= e((string) $log['name']) ?></strong>
                                <p><?= e((string) $log['dose']) ?></p>
                                <?php if ($log['note'] !== ''): ?>
                                    <small><?= e((string) $log['note']) ?></small>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php else: ?>
                <p class="muted">No doses logged yet. Use the dose form to create the first record.</p>
            <?php endif; ?>
        </section>

        <p class="disclaimer">Med Log is a tracking aid only and does not provide medical advice or clinical decision support.</p>
    </main>
</body>
</html>
