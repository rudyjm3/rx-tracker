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
        $feedbackType = post_string('feedback_type');
        if (!in_array($feedbackType, ['none', 'pain', 'mood', 'both'], true)) {
            // Back-compat: derive from the legacy checkbox when the new field is absent.
            $feedbackType = $trackDoseFeedback ? 'pain' : 'none';
        }
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

        $startDateRaw = post_string('start_date');
        $startDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDateRaw) ? $startDateRaw : null;

        if ($action === 'add_medication') {
            $repository->createMedication($name, $instructions, $scheduleMode, $doseTimes, $intervalHours, $firstDoseTime, $asNeeded, $lowSupplyThreshold, $trackDoseFeedback, $setId, $medicationType, $doseAmount, $doseUnit, $doseForm, $inventoryType, $startingQuantity, $quantityPerDose, $doseQtys, $startDate, $feedbackType);
            $newMedicationId = $repository->lastInsertedMedicationId();
            if ($groupIdRaw > 0) {
                $repository->addMedicationToGroup($groupIdRaw, $newMedicationId);
            }
        } else {
            $existingMed = $repository->findMedication($id);
            $repository->updateMedication($id, $name, $instructions, $scheduleMode, $doseTimes, $intervalHours, $firstDoseTime, $asNeeded, $lowSupplyThreshold, $trackDoseFeedback, $setId, $medicationType, $doseAmount, $doseUnit, $doseForm, $inventoryType, $startingQuantity, $quantityPerDose, $doseQtys, $startDate, $feedbackType);
            if ($existingMed !== null) {
                $oldAmountRaw = $existingMed['dose_amount'] ?? null;
                $oldAmount = ($oldAmountRaw !== null && $oldAmountRaw !== '') ? (float) $oldAmountRaw : null;
                $oldUnit = (string) ($existingMed['dose_unit'] ?? '');
                $newUnit = (string) ($doseUnit ?? '');
                $amountChanged = ($oldAmount === null) !== ($doseAmount === null)
                    || ($oldAmount !== null && $doseAmount !== null && abs($oldAmount - $doseAmount) > 0.0001);
                if ($amountChanged || ($oldUnit !== $newUnit && ($oldAmount !== null || $doseAmount !== null))) {
                    $doseChangeComment = substr(trim(post_string('dose_change_comment')), 0, 500);
                    $repository->recordDoseChange($id, $oldAmount, $oldUnit, $doseAmount, $newUnit, $doseChangeComment);
                }
            }
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

    if ($action === 'update_instructions') {
        $medId = (int) post_string('medication_id');
        $instructions = post_string('instructions');
        $repository->updateInstructions($medId, $instructions);
        if ($jsonResponse) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'medication_id' => $medId,
                'instructions' => $instructions,
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

    if ($action === 'reorder_medications') {
        $ids = json_decode(post_string('ids'), true);
        if (is_array($ids)) {
            $repository->reorderMedications(array_map('intval', $ids));
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
        exit;
    }

    if ($action === 'reorder_groups') {
        $ids = json_decode(post_string('ids'), true);
        if (is_array($ids)) {
            $repository->reorderGroups(array_map('intval', $ids));
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
        exit;
    }

    if ($action === 'mark_dose') {
        $rawPainLevel = post_string('pain_level');
        $painLevel = $rawPainLevel !== '' ? (int) $rawPainLevel : null;
        $rawMoodLevel = post_string('mood_level');
        $moodLevel = $rawMoodLevel !== '' ? (int) $rawMoodLevel : null;
        $rawGroupId = post_string('group_id');
        $groupId = $rawGroupId !== '' && (int) $rawGroupId > 0 ? (int) $rawGroupId : null;
        $actualTakenTime = post_string('actual_taken_time');
        $customTakenAt = null;
        if ($actualTakenTime !== '') {
            if (!preg_match('/^\d{2}:\d{2}$/', $actualTakenTime)) {
                throw new RuntimeException('Invalid time format.');
            }
            $candidateAt = new DateTimeImmutable(post_string('scheduled_date') . ' ' . $actualTakenTime . ':00');
            if ($candidateAt > new DateTimeImmutable('now')) {
                throw new RuntimeException('Taken time cannot be in the future.');
            }
            $customTakenAt = $candidateAt->format('Y-m-d H:i:s');
        } else {
            $scheduledDateStr = post_string('scheduled_date');
            $scheduledTimeStr = post_string('scheduled_time');
            if ($scheduledDateStr !== '' && $scheduledTimeStr !== '') {
                $graceMin    = $repository->getMissedGraceMinutes();
                $scheduledAt = new DateTimeImmutable($scheduledDateStr . ' ' . $scheduledTimeStr);
                $earliest    = $scheduledAt->modify('-' . $graceMin . ' minutes');
                if (new DateTimeImmutable('now') < $earliest) {
                    // Bypass the early-window block only when the snooze deadline
                    // has actually passed. An unresolved snooze still in the future
                    // means the user deferred the dose — they should not be able to
                    // take or skip it before that deadline any more than before they
                    // snoozed it.
                    $postponedUntil = $repository->activePostponeForDose(
                        (int) post_string('medication_id'),
                        $scheduledDateStr,
                        $scheduledTimeStr
                    );
                    $snoozeHasFired = $postponedUntil !== null
                        && new DateTimeImmutable($postponedUntil) <= new DateTimeImmutable('now');
                    if (!$snoozeHasFired) {
                        throw new RuntimeException('Too early to log this dose. It is scheduled for ' . $scheduledAt->format('g:i A') . '.');
                    }
                }
            }
        }
        $repository->recordDoseStatus((int) post_string('medication_id'), post_string('scheduled_date'), post_string('scheduled_time'), post_string('status'), post_string('note'), $painLevel, $groupId, $customTakenAt, $moodLevel);
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
        $reason = post_string('reason');
        $allowedReasons = ['End of regimen', 'Side effects (moderate to severe)', "Doctor's orders", 'Other'];
        if (!in_array($reason, $allowedReasons, true)) {
            $reason = '';
        }
        $comment = substr(trim(post_string('comment')), 0, 500);
        $repository->deactivateMedication($medId, $reason, $comment);
        if ($jsonResponse) {
            $inactiveRowHtml = '';
            $inactiveMed = $repository->findInactiveMedication($medId);
            if ($inactiveMed !== null) {
                $inactiveRowHtml = render_inactive_medication_row($inactiveMed);
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'                => true,
                'medication_id'     => $medId,
                'inactive_row_html' => $inactiveRowHtml,
            ], JSON_THROW_ON_ERROR);
            exit;
        }
        redirect_home();
    }

    if ($action === 'dose_change_history') {
        $medId = (int) post_string('medication_id');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'      => true,
            'changes' => $repository->doseChangesByMedicationId($medId),
        ], JSON_THROW_ON_ERROR);
        exit;
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
            $updatedNotifications = $repository->getNotificationsForUser();
            $unreadCount = count(array_filter($updatedNotifications, static fn(array $n): bool => !(bool) $n['is_read']));
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'unread_count' => $unreadCount], JSON_THROW_ON_ERROR);
            exit;
        }
        redirect_home();
    }

    if ($action === 'adjust_quantity') {
        $medicationId = (int) post_string('medication_id');
        $newCountRaw = post_string('new_count');
        $note = substr(trim(post_string('note')), 0, 255);
        if ($medicationId <= 0) {
            throw new RuntimeException('Invalid medication.');
        }
        if ($newCountRaw === '' || !is_numeric($newCountRaw)) {
            throw new RuntimeException('Corrected count is required.');
        }
        $newCount = (float) $newCountRaw;
        if ($newCount < 0) {
            throw new RuntimeException('Corrected count cannot be negative.');
        }
        $repository->adjustQuantity($medicationId, $newCount, $note);
        if ($jsonResponse) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
            exit;
        }
        redirect_home();
    }

    if ($action === 'mark_all_notifications_read') {
        $repository->markAllNotificationsRead();
        if ($jsonResponse) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
            exit;
        }
        redirect_home();
    }

    if ($action === 'dismiss_notification') {
        $notifId = (int) post_string('notification_id');
        if ($notifId > 0) {
            $repository->dismissNotification($notifId);
        }
        if ($jsonResponse) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
            exit;
        }
        redirect_home();
    }

    if ($action === 'mark_notification_read') {
        $notifId = (int) post_string('notification_id');
        if ($notifId > 0) {
            $repository->markNotificationRead($notifId);
        }
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

    if ($action === 'set_mood_chart_scheme') {
        $scheme = post_string('scheme');
        if (!in_array($scheme, ['classic', 'teal'], true)) {
            throw new RuntimeException('Invalid mood chart scheme.');
        }
        $repository->setMoodChartScheme($scheme);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'scheme' => $scheme], JSON_THROW_ON_ERROR);
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

    if ($action === 'log_side_effect') {
        $seRepo = new SideEffectRepository(db(), $auth->currentUserId(), $auth->activeProfileId());
        $medId  = (int) post_string('medication_id');
        $seDate = post_string('occurred_date');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $seDate)) {
            $seDate = date('Y-m-d');
        }
        $seDesc = substr(trim(post_string('description')), 0, 255);
        if ($seDesc === '') {
            throw new RuntimeException('Description is required.');
        }
        $seSeverity = post_string('severity');
        if (!in_array($seSeverity, ['mild', 'moderate', 'severe'], true)) {
            $seSeverity = 'mild';
        }
        $seNote = substr(trim(post_string('note')), 0, 500);
        $seRepo->logSideEffect($medId, $seDate, $seDesc, $seSeverity, $seNote);
        header('Location: index.php?page=medications&notice=' . urlencode('Side effect logged'));
        exit;
    }

    if ($action === 'generate_doctor_visit_report') {
        ini_set('memory_limit', '256M');
        set_time_limit(60);
        try {
            $reportStart = post_string('report_start');
            $reportEnd   = post_string('report_end');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportEnd)) {
                throw new RuntimeException('Invalid date range.');
            }
            if ($reportStart > $reportEnd) {
                throw new RuntimeException('Start date must be before end date.');
            }
            $chartDaysRaw = $_POST['chart_days'] ?? [];
            $chartDays = [];
            if (is_array($chartDaysRaw)) {
                foreach ($chartDaysRaw as $medId => $days) {
                    $chartDays[(int) $medId] = (int) $days;
                }
            }
            $seRepo = new SideEffectRepository(db(), $auth->currentUserId(), $auth->activeProfileId());
            $chart  = new PainChartRenderer();
            $moodChart = new MoodChartRenderer();
            $currentUser = $auth->currentUser();
            $patientName = $activeProfile !== null
                ? (string) ($activeProfile['display_name'] ?? $currentUser['display_name'] ?? 'Patient')
                : (string) ($currentUser['display_name'] ?? 'Patient');
            $report = new DoctorVisitReport($repository, $seRepo, $chart, $moodChart, $patientName);
            $pdf    = $report->generatePainReport($reportStart, $reportEnd, $chartDays);

            $dlToken = preg_replace('/[^a-zA-Z0-9]/', '', post_string('download_token'));
            if ($dlToken !== '') {
                setcookie('rx_dl_' . $dlToken, '1', time() + 60, '/');
            }
            header('Content-Type: application/pdf');
            $fileStart = date('n-j-Y', (int) strtotime($reportStart));
            $fileEnd   = date('n-j-Y', (int) strtotime($reportEnd));
            header('Content-Disposition: attachment; filename="doctor-visit-report-' . $fileStart . '-thru-' . $fileEnd . '.pdf"');
            header('Cache-Control: private, no-cache');
            header('Content-Length: ' . strlen($pdf));
            echo $pdf;
            exit;
        } catch (Throwable $e) {
            $error = 'Could not generate report: ' . $e->getMessage();
            // fall through so pages.php renders the export page with the error
        }
    }

    if ($action === 'generate_mood_report') {
        ini_set('memory_limit', '256M');
        set_time_limit(60);
        try {
            $reportStart = post_string('report_start');
            $reportEnd   = post_string('report_end');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportStart) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportEnd)) {
                throw new RuntimeException('Invalid date range.');
            }
            if ($reportStart > $reportEnd) {
                throw new RuntimeException('Start date must be before end date.');
            }
            $moodChartDaysRaw = $_POST['mood_chart_days'] ?? [];
            $moodChartDays = [];
            if (is_array($moodChartDaysRaw)) {
                foreach ($moodChartDaysRaw as $medId => $days) {
                    $moodChartDays[(int) $medId] = (int) $days;
                }
            }
            $seRepo = new SideEffectRepository(db(), $auth->currentUserId(), $auth->activeProfileId());
            $chart  = new PainChartRenderer();
            $moodChart = new MoodChartRenderer();
            $currentUser = $auth->currentUser();
            $patientName = $activeProfile !== null
                ? (string) ($activeProfile['display_name'] ?? $currentUser['display_name'] ?? 'Patient')
                : (string) ($currentUser['display_name'] ?? 'Patient');
            $report = new DoctorVisitReport($repository, $seRepo, $chart, $moodChart, $patientName);
            $pdf    = $report->generateMoodReport($reportStart, $reportEnd, $moodChartDays);

            $dlToken = preg_replace('/[^a-zA-Z0-9]/', '', post_string('download_token'));
            if ($dlToken !== '') {
                setcookie('rx_dl_' . $dlToken, '1', time() + 60, '/');
            }
            header('Content-Type: application/pdf');
            $fileStart = date('n-j-Y', (int) strtotime($reportStart));
            $fileEnd   = date('n-j-Y', (int) strtotime($reportEnd));
            header('Content-Disposition: attachment; filename="mood-wellbeing-report-' . $fileStart . '-thru-' . $fileEnd . '.pdf"');
            header('Cache-Control: private, no-cache');
            header('Content-Length: ' . strlen($pdf));
            echo $pdf;
            exit;
        } catch (Throwable $e) {
            $error = 'Could not generate report: ' . $e->getMessage();
            // fall through so pages.php renders the export page with the error
        }
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
