<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf_token(?string $token): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    return isset($_SESSION['csrf_token']) && is_string($token) && hash_equals((string) $_SESSION['csrf_token'], $token);
}

function redirect_home(): never
{
    header('Location: index.php');
    exit;
}

// The dose-unit dropdown offered wherever a medication's dose amount/unit is
// entered or edited (add/edit medication, update-prescribed-dose modals,
// onboarding). Onboarding additionally offers '%' for concentration-based
// dosing (e.g. topical creams), which isn't relevant elsewhere.
function dose_unit_options(bool $includePercent = false): array
{
    $units = ['mg', 'mcg', 'g', 'mL', 'tsp', 'tbsp', 'oz', 'IU', 'units', 'drops', 'puffs', 'patches'];
    if ($includePercent) {
        $units[] = '%';
    }

    return $units;
}

function post_string(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

function today(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d');
}

function formattedDose(array $medication): string
{
    $amount = $medication['dose_amount'] ?? '';
    $amountStr = ($amount !== '' && $amount !== null && (float) $amount !== 0.0) ? (string) (float) $amount : '';
    $structured = trim($amountStr . ' ' . (string) ($medication['dose_unit'] ?? ''));

    return $structured !== '' ? $structured : (string) ($medication['dose'] ?? '');
}

function format_group_members_for_json(array $members): array
{
    foreach ($members as &$member) {
        $member['dose'] = formattedDose($member);
    }
    unset($member);

    return $members;
}

function calculate_age(?string $birthDate, ?int $birthYear = null): ?int
{
    if ($birthDate !== null && $birthDate !== '') {
        try {
            return (new DateTimeImmutable($birthDate))->diff(new DateTimeImmutable())->y;
        } catch (Throwable) {
            // fall through to the birth-year fallback below
        }
    }

    if ($birthYear !== null && $birthYear > 0) {
        return (int) date('Y') - $birthYear;
    }

    return null;
}

function format_member_since(string $createdAt): string
{
    try {
        $since = new DateTimeImmutable($createdAt);
    } catch (Throwable) {
        return '';
    }

    $interval = $since->diff(new DateTimeImmutable());

    $parts = [];
    if ($interval->y > 0) {
        $parts[] = $interval->y . ' year' . ($interval->y === 1 ? '' : 's');
    }
    if ($interval->m > 0) {
        $parts[] = $interval->m . ' month' . ($interval->m === 1 ? '' : 's');
    }
    $parts[] = $interval->d . ' day' . ($interval->d === 1 ? '' : 's');

    return $since->format('m/d/Y') . ' - ' . implode(', ', $parts);
}

function fallback_display_name(?string $firstName, ?string $lastName): string
{
    $first = trim((string) $firstName);
    $last  = trim((string) $lastName);

    if ($first === '') {
        return '';
    }
    if ($last === '') {
        return $first;
    }

    return $first . ' ' . mb_strtoupper(mb_substr($last, 0, 1)) . '.';
}

function allergy_severity_label(?string $severity): string
{
    $labels = ['low' => 'Low', 'moderate' => 'Moderate', 'high' => 'High', 'very_high' => 'Very High'];
    return $labels[$severity] ?? '';
}

function allergy_category_label(?string $category): string
{
    $labels = ['drug' => 'Drug', 'food' => 'Food', 'environment_animal' => 'Environment / Animal', 'other' => 'Other'];
    return $labels[$category] ?? '';
}

function allergy_severity_meter(int $rank): string
{
    $positionColors = [1 => 'low', 2 => 'moderate', 3 => 'high', 4 => 'very-high'];
    $segments = '';
    for ($i = 1; $i <= 4; $i++) {
        $classes = 'severity-meter-segment';
        if ($i <= $rank) {
            $classes .= ' is-lit severity-meter-segment--' . $positionColors[$i];
        }
        $segments .= '<span class="' . $classes . '"></span>';
    }
    return '<span class="severity-meter" aria-hidden="true">' . $segments . '</span>';
}

function render_allergy_row(array $allergy): string
{
    $lifeThreatening = (int) ($allergy['life_threatening'] ?? 0) === 1;
    $severityRanks   = ['low' => 1, 'moderate' => 2, 'high' => 3, 'very_high' => 4];

    if ($lifeThreatening) {
        $metaLabel  = '<strong style="color:var(--rx-danger)">Life-threatening</strong>';
        $rank       = 4;
    } else {
        $severity   = $allergy['severity'] ?? null;
        $label      = allergy_severity_label($severity);
        $metaLabel  = $label !== '' ? e($label) : '';
        $rank       = $severityRanks[$severity] ?? 0;
    }

    $metaHtml = '';
    if ($metaLabel !== '') {
        $metaHtml = '<span class="session-allergy-severity">'
            . '<span class="session-meta">' . $metaLabel . '</span>'
            . allergy_severity_meter($rank)
            . '</span>';
    }

    return '<li class="session-row session-row--allergy" role="button" tabindex="0" data-open-allergy-edit-view="' . (int) $allergy['id'] . '">'
        . '<span class="session-info session-info--allergy">'
        . '<span class="session-agent">' . e((string) $allergy['name']) . '</span>'
        . '</span>'
        . $metaHtml
        . '<i class="fa-solid fa-chevron-right session-row-chevron" aria-hidden="true"></i>'
        . '</li>';
}

function height_to_inches(float $value, string $unit): float
{
    return $unit === 'cm' ? $value / 2.54 : $value;
}

function format_feet_inches(float $totalInches): string
{
    $totalInches = max(0.0, $totalInches);
    $feet        = (int) floor($totalInches / 12);
    $inches      = (int) round($totalInches - ($feet * 12));
    if ($inches === 12) {
        $feet++;
        $inches = 0;
    }

    return $feet . "' " . $inches . '"';
}

function render_avatar(?string $pictureUrl, string $letter, string $color, string $cssClass): string
{
    if ($pictureUrl !== null && $pictureUrl !== '') {
        return '<img src="' . e($pictureUrl) . '" alt="" class="' . e($cssClass) . ' avatar-img">';
    }

    return '<span class="' . e($cssClass) . '" style="background:' . e($color) . '">' . e($letter) . '</span>';
}

function render_simple_medication_line(array $medication, bool $showStopDate = false): string
{
    $medTypeSlug   = (string) ($medication['medication_type'] ?? 'prescription');
    $medTypeLabels = ['prescription' => 'Rx', 'otc' => 'OTC', 'supplement' => 'Supplement'];
    $dose          = formattedDose($medication);

    $html  = '<div class="medication-row medication-row-simple" data-med-type="' . e($medTypeSlug) . '">';
    $html .= '<div>';
    $html .= '<div class="med-title"><strong>' . e((string) $medication['name']) . '</strong>';
    $html .= '<span class="med-type-badge med-type-badge--' . e($medTypeSlug) . '">' . e($medTypeLabels[$medTypeSlug] ?? 'Rx') . '</span></div>';
    if ($dose !== '') {
        $html .= '<p class="med-dose">' . e($dose) . '</p>';
    }
    if ($showStopDate) {
        $lastDiscontinued = $medication['last_discontinued'] ?? null;
        if (is_array($lastDiscontinued) && (string) $lastDiscontinued['event_at'] !== '') {
            $ts = strtotime((string) $lastDiscontinued['event_at']);
            $stoppedOn = $ts !== false ? date('M j, Y', $ts) : (string) $lastDiscontinued['event_at'];
            $html .= '<p class="inactive-discontinued-line">Stopped ' . e($stoppedOn) . '</p>';
        }
    }
    $html .= '</div>';
    $html .= '</div>';

    return $html;
}

function parseTimeValue(string $raw): string
{
    $value = trim($raw);

    if (!preg_match('/^(0?[1-9]|1[0-2]):([0-5]\d)\s*([AaPp][Mm])$/', $value, $matches)) {
        throw new RuntimeException('Time must be h:mm AM/PM (e.g. 8:00 AM, 2:30 PM).');
    }

    $hour = (int) $matches[1];
    $minute = (int) $matches[2];
    $period = strtoupper($matches[3]);

    if ($period === 'AM') {
        $hour = $hour === 12 ? 0 : $hour;
    } else {
        $hour = $hour === 12 ? 12 : $hour + 12;
    }

    return sprintf('%02d:%02d:00', $hour, $minute);
}

function parseDoseTimes(string $raw): array
{
    $segments = preg_split('/\s*,\s*/', trim($raw)) ?: [];
    $times = [];
    foreach ($segments as $segment) {
        if ($segment === '') {
            continue;
        }
        $times[] = parseTimeValue($segment);
    }
    $times = array_values(array_unique($times));
    sort($times);

    return $times;
}

function to12h(string $time): string
{
    $dt = DateTimeImmutable::createFromFormat('H:i', substr($time, 0, 5));
    return $dt ? $dt->format('g:i A') : $time;
}

function timeToMinutes(string $time): int
{
    [$hour, $minute] = array_map('intval', explode(':', substr($time, 0, 5)));
    return ($hour * 60) + $minute;
}

function isLate(array $log, int $graceMinutes): bool
{
    if ((string) $log['status'] !== 'taken') {
        return false;
    }
    $takenAt = (string) ($log['taken_at'] ?? '');
    $scheduledDate = (string) ($log['scheduled_for_date'] ?? '');
    $scheduledTime = (string) ($log['scheduled_time'] ?? '');
    if ($takenAt === '' || $scheduledDate === '' || $scheduledTime === '') {
        return false;
    }
    try {
        $scheduled = new DateTimeImmutable($scheduledDate . ' ' . $scheduledTime);
        $threshold = $scheduled->modify('+' . $graceMinutes . ' minutes');
        $taken = new DateTimeImmutable($takenAt);
        return $taken > $threshold;
    } catch (Throwable) {
        return false;
    }
}

function minutesLate(array $log, int $graceMinutes): ?int
{
    if ((string) ($log['status'] ?? '') !== 'taken') {
        return null;
    }
    $takenAt = (string) ($log['taken_at'] ?? '');
    $scheduledDate = (string) ($log['scheduled_for_date'] ?? '');
    $scheduledTime = (string) ($log['scheduled_time'] ?? '');
    if ($takenAt === '' || $scheduledDate === '' || $scheduledTime === '') {
        return null;
    }
    try {
        $scheduled = new DateTimeImmutable($scheduledDate . ' ' . $scheduledTime);
        $threshold = $scheduled->modify('+' . $graceMinutes . ' minutes');
        $taken = new DateTimeImmutable($takenAt);
        $diff = $taken->getTimestamp() - $threshold->getTimestamp();
        return $diff > 0 ? (int) ceil($diff / 60) : null;
    } catch (Throwable) {
        return null;
    }
}

function formatLate(int $minutes): string
{
    if ($minutes < 60) {
        return $minutes . 'mins late';
    }
    $hrs = intdiv($minutes, 60);
    $mins = $minutes % 60;
    return $mins > 0 ? $hrs . 'hr ' . $mins . 'mins late' : $hrs . 'hr late';
}

/**
 * Small "Resumed on [date] — [reason]" annotation (with optional truncated note) for an
 * active medication that was previously discontinued and resumed. Empty when the
 * medication has no resume history.
 */
function render_resumed_note(array $medication): string
{
    $lastResumed = $medication['last_resumed'] ?? null;
    if (!is_array($lastResumed)) {
        return '';
    }

    $ts = strtotime((string) $lastResumed['event_at']);
    $line = 'Resumed ' . ($ts !== false ? date('M j, Y', $ts) : (string) $lastResumed['event_at']);
    if ((string) $lastResumed['reason'] !== '') {
        $line .= ' — ' . (string) $lastResumed['reason'];
    }

    $html = '<p class="med-resumed-line">' . e($line) . '</p>';

    $comment = (string) $lastResumed['comment'];
    if ($comment !== '') {
        if (mb_strlen($comment) > 150) {
            $truncated = mb_substr($comment, 0, 150) . '…';
            $html .= '<p class="med-resumed-comment" data-discontinued-comment>';
            $html .= '<span class="discontinued-comment-short" data-comment-short>' . e($truncated) . '</span>';
            $html .= '<span class="discontinued-comment-full" data-comment-full hidden>' . e($comment) . '</span>';
            $html .= ' <button type="button" class="history-view-more discontinued-comment-toggle" data-toggle-discontinued-comment>View more</button>';
            $html .= '</p>';
        } else {
            $html .= '<p class="med-resumed-comment">' . e($comment) . '</p>';
        }
    }

    return $html;
}

function render_inactive_medication_row(array $medication): string
{
    $medTypeSlug   = (string) ($medication['medication_type'] ?? 'prescription');
    $medTypeLabels = ['prescription' => 'Rx', 'otc' => 'OTC', 'supplement' => 'Supplement'];
    $dose          = formattedDose($medication);
    $events        = (array) ($medication['status_events'] ?? []);
    $lastDiscontinued = $medication['last_discontinued'] ?? null;

    $formatEventDate = static function (string $eventAt): string {
        $ts = strtotime($eventAt);
        return $ts !== false ? date('M j, Y', $ts) : $eventAt;
    };

    $html  = '<div class="medication-row" data-med-type="' . e($medTypeSlug) . '" data-inactive-med-id="' . e((string) $medication['id']) . '">';
    $html .= '<div class="medication-content">';
    $html .= '<strong>' . e((string) $medication['name']) . '</strong>';
    $html .= '<span class="med-type-badge med-type-badge--' . e($medTypeSlug) . '">' . e($medTypeLabels[$medTypeSlug] ?? 'Rx') . '</span>';
    if ($dose !== '') {
        $html .= '<p>' . e($dose) . '</p>';
    }
    if (is_array($lastDiscontinued)) {
        $line = 'Discontinued ' . $formatEventDate((string) $lastDiscontinued['event_at']);
        if ((string) $lastDiscontinued['reason'] !== '') {
            $line .= ' — ' . (string) $lastDiscontinued['reason'];
        }
        $html .= '<p class="inactive-discontinued-line">' . e($line) . '</p>';
        $comment = (string) $lastDiscontinued['comment'];
        if ($comment !== '') {
            if (mb_strlen($comment) > 150) {
                $truncated = mb_substr($comment, 0, 150) . '…';
                $html .= '<p class="inactive-discontinued-comment" data-discontinued-comment>';
                $html .= '<span class="discontinued-comment-short" data-comment-short>' . e($truncated) . '</span>';
                $html .= '<span class="discontinued-comment-full" data-comment-full hidden>' . e($comment) . '</span>';
                $html .= ' <button type="button" class="history-view-more discontinued-comment-toggle" data-toggle-discontinued-comment>View more</button>';
                $html .= '</p>';
            } else {
                $html .= '<p class="inactive-discontinued-comment">' . e($comment) . '</p>';
            }
        }
    }
    if (count($events) > 1) {
        $html .= '<details class="inactive-history"><summary>Stop / resume history</summary><ul class="inactive-history-list">';
        foreach ($events as $event) {
            $isDiscontinued = (string) $event['event'] === 'discontinued';
            $entry = ($isDiscontinued ? 'Discontinued ' : 'Resumed ') . $formatEventDate((string) $event['event_at']);
            if ((string) $event['reason'] !== '') {
                $entry .= ' (' . (string) $event['reason'] . ')';
            }
            $html .= '<li>' . e($entry);
            if ((string) $event['comment'] !== '') {
                $html .= '<br><span class="inactive-history-comment">' . e((string) $event['comment']) . '</span>';
            }
            $html .= '</li>';
        }
        $html .= '</ul></details>';
    }
    $html .= '</div>';
    $html .= '<div class="row-actions">';
    $html .= '<button type="button" data-open-resume-modal data-medication-id="' . e((string) $medication['id']) . '" data-medication-name="' . e((string) $medication['name']) . '">Resume</button>';
    $html .= '</div>';
    $html .= '</div>';

    return $html;
}

function pill_status_payload(MedicationRepository $repository, int $medicationId, ?float $preActionQuantity = null): array
{
    $medication = $repository->findMedication($medicationId);
    if ($medication === null) {
        return ['pill_count' => null, 'ran_out_on' => null, 'inventory_unit' => null, 'already_out_before_dose' => false];
    }
    $pillCount = (float) ($medication['current_quantity'] ?? $medication['pill_count'] ?? 0);
    $ranOutOn = $pillCount <= 0 ? $repository->dateInventoryCrossedZero($medicationId) : null;

    return [
        'pill_count' => $pillCount,
        'ran_out_on' => $ranOutOn,
        'inventory_unit' => (string) ($medication['inventory_unit'] ?? 'tablets'),
        // Only true when the medication was already out *before* this action ran —
        // distinguishes "already empty" (show the refill/adjust prompt) from "this
        // exact dose is what emptied it" (just commit, no interstitial).
        'already_out_before_dose' => $preActionQuantity !== null && $preActionQuantity <= 0,
    ];
}

function daysUntilRunout(array $medication): ?int
{
    $qty = (float) ($medication['current_quantity'] ?? $medication['pill_count'] ?? 0);
    if ($qty <= 0) {
        return 0;
    }

    if ((string) $medication['schedule_mode'] === 'fixed_times') {
        // Use per-slot quantities when available (time_doses map) to get accurate daily use.
        $times     = $medication['times'] ?? [];
        $timeDoses = $medication['time_doses'] ?? [];
        $fallback  = max(0.001, (float) ($medication['quantity_per_dose'] ?? 1));

        if (count($times) === 0) {
            return null;
        }
        $dailyUse = 0.0;
        foreach ($times as $t) {
            $slotQty = isset($timeDoses[$t]) && $timeDoses[$t] !== null
                ? (float) $timeDoses[$t]
                : $fallback;
            $dailyUse += max(0.001, $slotQty);
        }
        return (int) floor($qty / $dailyUse);
    }

    if ((string) $medication['schedule_mode'] === 'interval') {
        $intervalHours = (int) ($medication['interval_hours'] ?? 0);
        if ($intervalHours <= 0) {
            return null;
        }
        $firstDoseTime = substr((string) ($medication['first_dose_time'] ?? ''), 0, 5);
        if ($firstDoseTime === '') {
            return null;
        }

        // Mirror MedicationRepository::timesForDate() so the run-out estimate
        // matches the actual number of dose slots generated per day, since
        // round(24 / $intervalHours) drifts for intervals that don't divide
        // 24 evenly (e.g. 5h).
        $stepMinutes  = $intervalHours * 60;
        $slotCount    = 0;
        for ($m = timeToMinutes($firstDoseTime); $m < 1440; $m += $stepMinutes) {
            $slotCount++;
        }

        $dosesPerDay = max(1, $slotCount);
        $qtyPerDose  = max(0.001, (float) ($medication['quantity_per_dose'] ?? 1));
        return (int) floor($qty / ($dosesPerDay * $qtyPerDose));
    }

    return null;
}
