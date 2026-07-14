<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/security_headers.php';

send_security_headers();

// Require an authenticated session to prevent unauthenticated upstream hammering.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$userId = (int) $_SESSION['user_id'];

// Per-user, per-minute rate limit: max 60 requests/minute.
$window = (int) (time() / 60);
try {
    db()->prepare(
        'INSERT INTO api_proxy_rate_limit (user_id, window_start, hits)
         VALUES (:uid, :win, 1)
         ON DUPLICATE KEY UPDATE hits = hits + 1'
    )->execute(['uid' => $userId, 'win' => $window]);

    $hitStmt = db()->prepare(
        'SELECT hits FROM api_proxy_rate_limit WHERE user_id = :uid AND window_start = :win'
    );
    $hitStmt->execute(['uid' => $userId, 'win' => $window]);
    $hits = (int) $hitStmt->fetchColumn();

    if ($hits > 60) {
        http_response_code(429);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Rate limit exceeded — max 60 requests per minute']);
        exit;
    }

    // Probabilistic cleanup of old rows (~1% of requests)
    if (random_int(0, 99) === 0) {
        db()->prepare(
            'DELETE FROM api_proxy_rate_limit WHERE window_start < :cutoff'
        )->execute(['cutoff' => $window - 5]);
    }
} catch (Throwable) {
    // Non-fatal — allow the request if the rate-limit table is unavailable
}

$allowedPrefixes = [
    'https://dailymed.nlm.nih.gov/dailymed/services/v2/',
    'https://api.fda.gov/drug/label.json',
    'https://rximage.nlm.nih.gov/api/rximage/',
];

$url = (string) ($_GET['url'] ?? '');

$allowed = false;
foreach ($allowedPrefixes as $prefix) {
    if (str_starts_with($url, $prefix)) {
        $allowed = true;
        break;
    }
}

if (!$allowed || $url === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'URL not allowed']);
    exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_USERAGENT      => 'rx-tracker/1.0',
    CURLOPT_SSL_VERIFYPEER => true,
]);
$body     = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$failed   = curl_errno($ch) !== 0;
curl_close($ch);

if ($failed || $body === false) {
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Upstream request failed']);
    exit;
}

// OpenFDA returns 404 when a search yields no results (not a real server error).
// Normalize it to 200 with an empty results array so the browser console stays clean.
if ($httpCode === 404 && str_starts_with($url, 'https://api.fda.gov/')) {
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['results' => []]);
    exit;
}

http_response_code($httpCode);
header('Content-Type: application/json');
echo $body;
