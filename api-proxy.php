<?php
declare(strict_types=1);

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
