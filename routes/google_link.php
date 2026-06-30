<?php

declare(strict_types=1);

/** @var AuthService $auth */
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
    if (!verify_csrf_token((string) ($payload['csrf_token'] ?? ''))) {
        throw new RuntimeException('Session expired. Please refresh and try again.');
    }
    $credential = trim((string) ($payload['credential'] ?? ''));
    if ($credential === '') {
        throw new RuntimeException('Google did not return a token.');
    }
    $googleAuth->linkForUser($auth->currentUserId(), $credential);
    echo json_encode(['ok' => true, 'redirect' => 'index.php?page=profile&success=' . urlencode('Google account connected.')]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
