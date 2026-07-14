<?php

declare(strict_types=1);

// These classes may already be loaded by index.php — use require_once to avoid re-declaration
require_once __DIR__ . '/../includes/InventoryEstimator.php';
require_once __DIR__ . '/../includes/OnboardingService.php';

/** @var MedicationRepository $repository */
/** @var AuthService $auth */

header('Content-Type: application/json; charset=utf-8');

try {
    if (!verify_csrf_token(post_string('csrf_token'))) {
        throw new RuntimeException('Session expired. Refresh and try again.');
    }

    $action  = post_string('action');
    $service = new OnboardingService($repository);

    // ── Step 1: Add a draft medication ───────────────────────────────────────

    if ($action === 'add_draft_medication') {
        $name = trim(post_string('name'));
        if ($name === '') {
            throw new RuntimeException('Medication name is required.');
        }
        $doseAmountRaw = post_string('dose_amount');
        $doseAmount    = $doseAmountRaw !== '' ? (float) $doseAmountRaw : null;
        $doseUnit      = post_string('dose_unit') ?: null;
        $doseForm      = post_string('dose_form') ?: null;
        $setId         = substr(trim(post_string('set_id')), 0, 64);
        $asNeeded      = post_string('as_needed') === '1';

        $medicationType = post_string('medication_type');
        if (!in_array($medicationType, ['prescription', 'otc', 'supplement'], true)) {
            $medicationType = 'prescription';
        }

        $id = $repository->createDraftMedication($name, $doseAmount, $doseUnit, $doseForm, $medicationType, $setId, $asNeeded);

        echo json_encode([
            'ok'        => true,
            'id'        => $id,
            'name'      => $name,
            'dose'      => trim(($doseAmount !== null ? (string)(float)$doseAmount : '') . ' ' . ($doseUnit ?? '')),
            'form'      => $doseForm ?? '',
            'type'      => $medicationType,
            'as_needed' => $asNeeded,
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    // ── Step 1: Update a draft medication ────────────────────────────────────

    if ($action === 'update_draft_medication') {
        $id = (int) post_string('medication_id');
        if ($id <= 0) {
            throw new RuntimeException('Invalid medication.');
        }
        $name = trim(post_string('name'));
        if ($name === '') {
            throw new RuntimeException('Medication name is required.');
        }
        $doseAmountRaw = post_string('dose_amount');
        $doseAmount    = $doseAmountRaw !== '' ? (float) $doseAmountRaw : null;
        $doseUnit      = post_string('dose_unit') ?: null;
        $doseForm      = post_string('dose_form') ?: null;
        $setId         = substr(trim(post_string('set_id')), 0, 64);
        $asNeeded      = post_string('as_needed') === '1';

        $medicationType = post_string('medication_type');
        if (!in_array($medicationType, ['prescription', 'otc', 'supplement'], true)) {
            $medicationType = 'prescription';
        }

        $repository->updateDraftMedication($id, $name, $doseAmount, $doseUnit, $doseForm, $medicationType, $setId, $asNeeded);

        echo json_encode([
            'ok'        => true,
            'id'        => $id,
            'name'      => $name,
            'dose'      => trim(($doseAmount !== null ? (string)(float)$doseAmount : '') . ' ' . ($doseUnit ?? '')),
            'form'      => $doseForm ?? '',
            'type'      => $medicationType,
            'as_needed' => $asNeeded,
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    // ── Step 1: Delete a draft medication ────────────────────────────────────

    if ($action === 'delete_draft_medication') {
        $id = (int) post_string('medication_id');
        if ($id <= 0) {
            throw new RuntimeException('Invalid medication.');
        }
        $repository->deleteDraftMedication($id);
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
        exit;
    }

    // ── Step 2: Save tracking preferences ────────────────────────────────────

    if ($action === 'save_tracking_preferences') {
        $prefsData = $_POST['prefs'] ?? [];
        if (!is_array($prefsData)) {
            throw new RuntimeException('Invalid preferences data.');
        }
        foreach ($prefsData as $medId => $prefs) {
            $medId = (int) $medId;
            if ($medId <= 0) {
                continue;
            }
            $repository->updateTrackingPreferences($medId, (array) $prefs);
        }
        $repository->upsertOnboardingProgress('in_progress', 'schedule');
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
        exit;
    }

    // ── Step 3: Save schedule for a single draft medication ──────────────────

    if ($action === 'save_draft_schedule') {
        $medId     = (int) post_string('medication_id');
        $asNeeded  = post_string('as_needed') === '1';
        $doseTimes = [];
        $doseQtys  = [];

        if (!$asNeeded) {
            $rawTimes = $_POST['dose_times'] ?? [];
            $rawQtys  = $_POST['dose_qtys'] ?? [];
            if (!is_array($rawTimes)) {
                $rawTimes = array_filter(array_map('trim', explode(',', (string) $rawTimes)));
            }
            $seen = [];
            foreach (array_values($rawTimes) as $i => $t) {
                $t = trim((string) $t);
                if ($t === '') {
                    continue;
                }
                try {
                    $parsed = parseTimeValue($t);
                } catch (RuntimeException) {
                    continue;
                }
                if (!in_array($parsed, $seen, true)) {
                    $seen[]      = $parsed;
                    $doseTimes[] = $parsed;
                    $doseQtys[]  = $rawQtys[$i] ?? '';
                }
            }
        }

        $repository->setDraftSchedule($medId, $doseTimes, $doseQtys);

        // Also update as_needed flag
        if ($asNeeded) {
            $repository->updateTrackingPreferences($medId, [
                'dashboard_enabled'  => true,
                'reminders_enabled'  => false,
                'adherence_enabled'  => false,
                'inventory_enabled'  => false,
            ]);
        }

        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
        exit;
    }

    // ── Step 4: Estimate inventory ────────────────────────────────────────────

    if ($action === 'estimate_inventory') {
        $startedUsingAt    = post_string('started_using_at');
        $asOfDatetime      = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $quantityDispensed = (float) post_string('quantity_dispensed');
        $carryover         = max(0.0, (float) post_string('carryover'));
        $scheduleMode      = post_string('schedule_mode');
        $timesRaw          = $_POST['times'] ?? [];
        $times             = is_array($timesRaw) ? array_values(array_filter($timesRaw)) : [];
        $defaultQtyPerDose = max(0.001, (float) (post_string('default_qty_per_dose') ?: '1'));
        $intervalHoursRaw  = post_string('interval_hours');
        $intervalHours     = $intervalHoursRaw !== '' ? (int) $intervalHoursRaw : null;
        $asNeeded          = post_string('as_needed') === '1';

        if ($startedUsingAt === '' || $quantityDispensed <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Fill date and quantity are required.'], JSON_THROW_ON_ERROR);
            exit;
        }

        try {
            $startDt = new DateTimeImmutable($startedUsingAt);
            $result  = InventoryEstimator::estimate(
                $startDt->format('Y-m-d H:i:s'),
                $asOfDatetime,
                $quantityDispensed,
                $carryover,
                $scheduleMode !== '' ? $scheduleMode : 'fixed_times',
                $times,
                [],
                $defaultQtyPerDose,
                $intervalHours,
                $asNeeded
            );
            echo json_encode(array_merge(['ok' => true], $result), JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'Could not compute estimate: ' . $e->getMessage()], JSON_THROW_ON_ERROR);
        }
        exit;
    }

    // ── Step 4: Save inventory for a draft medication ─────────────────────────

    if ($action === 'save_draft_inventory') {
        $medId     = (int) post_string('medication_id');
        $method    = post_string('count_method');
        if (!in_array($method, ['counted', 'estimated', 'skip'], true)) {
            $method = 'skip';
        }

        if ($method === 'skip') {
            // Disable inventory tracking for this medication
            $repository->updateTrackingPreferences($medId, [
                'dashboard_enabled' => true,
                'reminders_enabled' => true,
                'adherence_enabled' => true,
                'inventory_enabled' => false,
            ]);
            echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
            exit;
        }

        $qty   = max(0.0, (float) post_string('quantity'));
        $asOf  = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        $repository->setDraftInventory($medId, $qty, $method, $asOf);
        $repository->updateTrackingPreferences($medId, [
            'dashboard_enabled' => true,
            'reminders_enabled' => true,
            'adherence_enabled' => true,
            'inventory_enabled' => true,
        ]);
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
        exit;
    }

    // ── Step 5: Reconcile today ───────────────────────────────────────────────

    if ($action === 'reconcile_doses') {
        $doses = $_POST['doses'] ?? [];
        if (!is_array($doses)) {
            throw new RuntimeException('Invalid dose data.');
        }
        $today  = (new DateTimeImmutable('now'))->format('Y-m-d');
        $logged = 0;
        foreach ($doses as $entry) {
            $medId = (int) ($entry['medication_id'] ?? 0);
            $time  = trim((string) ($entry['time'] ?? ''));
            $status = (string) ($entry['status'] ?? 'taken');
            if ($medId <= 0 || $time === '' || $status !== 'taken') {
                continue;
            }
            // Normalize time to HH:MM:SS
            if (preg_match('/^\d{2}:\d{2}$/', $time)) {
                $time .= ':00';
            }
            try {
                $repository->recordDoseStatus($medId, $today, $time, 'taken', 'Reconciled at setup');
                $logged++;
            } catch (Throwable) {
                // Non-fatal: if a slot fails just skip it
            }
        }
        echo json_encode(['ok' => true, 'logged' => $logged], JSON_THROW_ON_ERROR);
        exit;
    }

    // ── Step 6: Activate all draft medications ────────────────────────────────

    if ($action === 'activate_onboarding') {
        $trackingStartedAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $count = $service->activateAll($trackingStartedAt);
        echo json_encode(['ok' => true, 'activated' => $count], JSON_THROW_ON_ERROR);
        exit;
    }

    throw new RuntimeException('Unknown onboarding action: ' . $action);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR);
    exit;
}
