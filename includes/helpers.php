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
    $html .= '<div>';
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
        if ((string) $lastDiscontinued['comment'] !== '') {
            $html .= '<p class="inactive-discontinued-comment">' . e((string) $lastDiscontinued['comment']) . '</p>';
        }
    }
    if (count($events) > 1) {
        $html .= '<details class="inactive-history"><summary>Stop / resume history</summary><ul class="inactive-history-list">';
        foreach ($events as $event) {
            $isDiscontinued = (string) $event['event'] === 'discontinued';
            $entry = ($isDiscontinued ? 'Discontinued ' : 'Resumed ') . $formatEventDate((string) $event['event_at']);
            if ($isDiscontinued && (string) $event['reason'] !== '') {
                $entry .= ' (' . (string) $event['reason'] . ')';
            }
            $html .= '<li>' . e($entry);
            if ($isDiscontinued && (string) $event['comment'] !== '') {
                $html .= '<br><span class="inactive-history-comment">' . e((string) $event['comment']) . '</span>';
            }
            $html .= '</li>';
        }
        $html .= '</ul></details>';
    }
    $html .= '</div>';
    $html .= '<div class="row-actions">';
    $html .= '<form method="post" action="index.php">';
    $html .= csrf_field();
    $html .= '<input type="hidden" name="action" value="activate_medication">';
    $html .= '<input type="hidden" name="medication_id" value="' . e((string) $medication['id']) . '">';
    $html .= '<button type="submit">Activate</button>';
    $html .= '</form>';
    $html .= '</div>';
    $html .= '</div>';

    return $html;
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
        $dosesPerDay = max(1, round(24 / $intervalHours));
        $qtyPerDose  = max(0.001, (float) ($medication['quantity_per_dose'] ?? 1));
        return (int) floor($qty / ($dosesPerDay * $qtyPerDose));
    }

    return null;
}
