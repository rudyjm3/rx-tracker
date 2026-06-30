<?php

declare(strict_types=1);

/** @var GoogleAuthService $googleAuth */

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid request.');
    }
    $credential = trim((string) ($payload['credential'] ?? ''));
    if ($credential === '') {
        throw new RuntimeException('Google sign-in was cancelled or did not return a token.');
    }
    $result = $googleAuth->authenticate($credential);
    echo json_encode(['ok' => true, 'redirect' => $result['redirect']]);
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
