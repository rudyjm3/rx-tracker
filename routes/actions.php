<?php

declare(strict_types=1);

$jsonResponse = post_string('json_response') === '1';
try {
    if (!verify_csrf_token(post_string('csrf_token'))) {
        throw new RuntimeException('Session expired, refresh and retry.');
    }

    $action = post_string('action');

    if ($action === 'add_medication' || $action === 'update_medication') {
        $id = (int) post_string('medication_id');
        $name = post_string('name');
        $instructions = post_string('instructions');
        $scheduleMode = post_string('schedule_mode');
        $doseTimesPost = $_POST['dose_times'] ?? '';
        $doseQtysPost  = $_POST['dose_qtys'] ?? [];
        $doseQtysRaw   = is_array($doseQtysPost) ? array_values($doseQtysPost) : [];
        if (is_array($doseTimesPost)) {
            $seenTimes = [];
            $doseTimes = [];
            $doseQtys  = [];
            foreach ($doseTimesPost as $i => $t) {
                $t = trim((string) $t);
                if ($t === '') {
                    continue;
                }
                $parsed = parseTimeValue($t);
                if (!in_array($parsed, $seenTimes, true)) {
                    $seenTimes[] = $parsed;
                    $doseTimes[] = $parsed;
                    $doseQtys[]  = $doseQtysRaw[$i] ?? '';
                }
            }
        } else {
            $doseTimes = parseDoseTimes((string) $doseTimesPost);
            $doseQtys  = $doseQtysRaw;
        }
        $intervalHoursRaw = post_string('interval_hours');
        $intervalHours = $intervalHoursRaw === '' ? null : max(1, (int) $intervalHoursRaw);
        $firstDoseRaw = post_string('first_dose_time');
        $firstDoseTime = $firstDoseRaw === '' ? null : parseTimeValue($firstDoseRaw);
        $asNeeded = post_string('as_needed') === '1';
        $lowSupplyThreshold = max(0, (int) post_string('low_supply_threshold'));
        $trackDoseFeedback = post_string('track_dose_feedback') === '1';
        $setId = substr(trim(post_string('set_id')), 0, 64);
        $groupIdRaw = (int) post_string('group_id');

        $medicationType = post_string('medication_type');
        if (!in_array($medicationType, ['prescription', 'otc', 'supplement'], true)) {
            $medicationType = 'prescription';
        }

        $doseAmountRaw = post_string('dose_amount');
        $doseAmount = $doseAmountRaw !== '' ? (float) $doseAmountRaw : null;
        $doseUnit = post_string('dose_unit') ?: null;
        $doseForm = post_string('dose_form') ?: null;

        $doseFormToInvType = [
            'tablet'    => 'pills',
            'capsule'   => 'pills',
            'liquid'    => 'liquid',
            'inhaler'   => 'inhaler',
            'injection' => 'injection',
            'patch'     => 'patch',
            'drops'     => 'drops',
            'other'     => 'other',
        ];
        $inventoryType = $doseFormToInvType[$doseForm ?? ''] ?? 'pills';

        if ($inventoryType === 'liquid') {
            $bottleAmount = post_string('bottle_amount');
            $bottleUnit = post_string('bottle_unit');
            $startingQtyRaw = $bottleUnit === 'oz'
                ? (string) round((float) $bottleAmount * 29.5735, 3)
                : $bottleAmount;
            if ($bottleAmount !== '' && (float) $startingQtyRaw <= 0.0) {
                throw new RuntimeException('Bottle amount must be greater than 0.');
            }
        } else {
            $startingQtyRaw = post_string('starting_quantity');
        }
        $startingQuantity = max(0.0, (float) $startingQtyRaw);
        $quantityPerDoseRaw = post_string('quantity_per_dose');
        $quantityPerDose = $quantityPerDoseRaw !== '' ? max(0.001, (float) $quantityPerDoseRaw) : 1.0;

        if ($name === '') {
            throw new RuntimeException('Medication name is required.');
        }

        if ($scheduleMode === 'interval') {
            $doseTimes = [];
        }

        if ($action === 'add_medication') {
            $repository->createMedication($name, $instructions, $scheduleMode, $doseTimes, $intervalHours, $firstDoseTime, $asNeeded, $lowSupplyThreshold, $trackDoseFeedback, $setId, $medicationType, $doseAmount, $doseUnit, $doseForm, $inventoryType, $startingQuantity, $quantityPerDose, $doseQtys);
            $newMedicationId = $repository->lastInsertedMedicationId();
            if ($groupIdRaw > 0) {
                $repository->addMedicationToGroup($groupIdRaw, $newMedicationId);
            }
        } else {
            $repository->updateMedication($id, $name, $instructions, $scheduleMode, $doseTimes, $intervalHours, $firstDoseTime, $asNeeded, $lowSupplyThreshold, $trackDoseFeedback, $setId, $medicationType, $doseAmount, $doseUnit, $doseForm, $inventoryType, $startingQuantity, $quantityPerDose, $doseQtys);
            if ($groupIdRaw > 0) {
                $repository->addMedicationToGroup($groupIdRaw, $id);
            } else {
                $repository->removeMedicationFromGroup($id);
            }
        }

        $redirectPage = post_string('redirect_page');
        if ($redirectPage === 'medications') {
            header('Location: index.php?page=medications');
            exit;
        }
        redirect_home();
    }

    if ($action === 'create_group') {
        $groupName = trim(post_string('group_name'));
        $groupTime = post_string('group_time');
        if ($groupName === '') {
            throw new RuntimeException('Group name is required.');
        }
        $parsedTime = parseTimeValue($groupTime);
        $newGroupId = $repository->createGroup($groupName, $parsedTime);
        if ($jsonResponse) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'group_id' => $newGroupId,
                'group_name' => $groupName,
                'group_time_display' => to12h($parsedTime),
                'ungrouped' => $repository->ungroupedActiveMedications($newGroupId),
            ], JSON_THROW_ON_ERROR);
            exit;
        }
        redirect_home();
    }

    if ($action === 'update_group') {
        $groupId = (int) post_string('group_id');
        $groupName = trim(post_string('group_name'));
        $groupTime = post_string('group_time');
        if ($groupName === '') {
            throw new RuntimeException('Group name is required.');
        }
        $parsedTime = parseTimeValue($groupTime);
        $repository->updateGroup($groupId, $groupName, $parsedTime);
        if ($jsonResponse) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'group_id' => $groupId,
                'group_name' => $groupName,
                'group_time_display' => to12h($parsedTime),
            ], JSON_THROW_ON_ERROR);
            exit;
        }
        redirect_home();
    }

    if ($action === 'delete_group') {
        $repository->deleteGroup((int) post_string('group_id'));
        if ($jsonResponse) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
            exit;
        }
        redirect_home();
    }

    if ($action === 'add_medication_to_group') {
        $targetGroupId = (int) post_string('group_id');
        $qpdRaw = post_string('quantity_per_dose');
        $qpdOverride = $qpdRaw !== '' && (float) $qpdRaw > 0 ? (float) $qpdRaw : null;
        $repository->addMedicationToGroup($targetGroupId, (int) post_string('medication_id'), $qpdOverride);
        if ($jsonResponse) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'ungrouped' => $repository->ungroupedActiveMedications($targetGroupId),
            ], JSON_THROW_ON_ERROR);
            exit;
        }
        redirect_home();
    }

    if ($action === 'remove_medication_from_group') {
        $medId = (int) post_string('medication_id');
        $targetGroupId = post_string('group_id') !== '' ? (int) post_string('group_id') : null;
        $repository->removeMedicationFromGroup($medId, $targetGroupId);
        if ($jsonResponse) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'medication_id' => $medId,
                'ungrouped' => $repository->ungroupedActiveMedications($targetGroupId ?? 0),
            ], JSON_THROW_ON_ERROR);
            exit;
        }
        redirect_home();
    }

    if ($action === 'mark_dose') {
        $rawPainLevel = post_string('pain_level');
        $painLevel = $rawPainLevel !== '' ? (int) $rawPainLevel : null;
        $rawGroupId = post_string('group_id');
        $groupId = $rawGroupId !== '' && (int) $rawGroupId > 0 ? (int) $rawGroupId : null;
        $repository->recordDoseStatus((int) post_string('medication_id'), post_string('scheduled_date'), post_string('scheduled_time'), post_string('status'), post_string('note'), $painLevel, $groupId);
        if ($jsonResponse) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
            exit;
        }
        redirect_home();
    }

    if ($action === 'log_dose_now') {
        $medicationId  = (int) post_string('medication_id');
        $scheduledTime = post_string('scheduled_time') ?: null;
        $takenOnTime   = post_string('taken_on_time') === '1';
        if ($medicationId <= 0) {
            throw new RuntimeException('Choose a medication first.');
        }
        $repository->logDoseNow($medicationId, post_string('note'), $scheduledTime, $takenOnTime);
        if ($jsonResponse) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
            exit;
        }
        redirect_home();
    }

    if ($action === 'deactivate_medication') {
        $medId = (int) post_string('medication_id');
        $repository->deactivateMedication($medId);
        if ($jsonResponse) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'medication_id' => $medId], JSON_THROW_ON_ERROR);
            exit;
        }
        redirect_home();
    }

    if ($action === 'activate_medication') {
        $medId = (int) post_string('medication_id');
        $repository->activateMedication($medId);
        if ($jsonResponse) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'medication_id' => $medId], JSON_THROW_ON_ERROR);
            exit;
        }
        redirect_home();
    }

    if ($action === 'log_refill') {
        $medicationId = (int) post_string('medication_id');
        $refillDate = post_string('refill_date');
        $amount = (int) post_string('amount');
        $note = substr(trim(post_string('note')), 0, 255);
        if ($medicationId <= 0) {
            throw new RuntimeException('Invalid medication.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $refillDate)) {
            throw new RuntimeException('Invalid refill date.');
        }
        [$ry, $rm, $rd] = array_map('intval', explode('-', $refillDate));
        if (!checkdate($rm, $rd, $ry)) {
            throw new RuntimeException('Invalid refill date.');
        }
        if ($amount <= 0) {
            throw new RuntimeException('Refill amount must be greater than 0.');
        }
        $repository->logRefill($medicationId, $refillDate, $amount, $note);
        if ($jsonResponse) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
            exit;
        }
        redirect_home();
    }

    if ($action === 'postpone_dose') {
        $delayMinutes = (int) post_string('postpone_minutes');
        $repository->postponeDose(
            (int) post_string('medication_id'),
            post_string('scheduled_date'),
            post_string('scheduled_time'),
            $delayMinutes
        );
        if ($jsonResponse) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
            exit;
        }
        header('Location: index.php?notice=' . urlencode('Dose snoozed'));
        exit;
    }

    if ($action === 'save_settings') {
        $graceMinutes = (int) post_string('missed_grace_minutes');
        $repository->setMissedGraceMinutes($graceMinutes);
        $repository->setSnoozeMinutes((int) post_string('snooze_minutes'));
        header('Location: index.php?page=settings&notice=Settings saved');
        exit;
    }

    if ($action === 'save_push_subscription') {
        header('Content-Type: application/json; charset=utf-8');
        $endpoint = trim((string) ($_POST['endpoint'] ?? ''));
        $p256dh = trim((string) ($_POST['p256dh'] ?? ''));
        $auth = trim((string) ($_POST['auth'] ?? ''));
        $repository->upsertPushSubscription($endpoint, $p256dh, $auth, substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255));
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
        exit;
    }

    if ($action === 'send_test_push') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $service = PushNotificationService::fromEnv($repository);
            $sent = $service->sendTestPush();
            echo json_encode(['ok' => true, 'count' => $sent], JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR);
        }
        exit;
    }

    if ($action === 'remove_push_subscription') {
        header('Content-Type: application/json; charset=utf-8');
        $endpoint = trim((string) ($_POST['endpoint'] ?? ''));
        $repository->removePushSubscriptionByEndpoint($endpoint);
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
        exit;
    }
} catch (Throwable $exception) {
    $isPushAction = in_array(post_string('action'), ['save_push_subscription', 'remove_push_subscription', 'send_test_push'], true);
    if ($jsonResponse || $isPushAction) {
        header('Content-Type: application/json; charset=utf-8');
        if ($isPushAction) {
            http_response_code(400);
        }
        echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR);
        exit;
    }
    $error = $exception->getMessage();
}
