<?php

declare(strict_types=1);

/** @var AuthService $auth */
/** @var GoogleAuthService $googleAuth */

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token(post_string('csrf_token'))) {
    header('Location: index.php?page=profile&error=' . urlencode('Session expired. Please try again.'));
    exit;
}

try {
    $googleAuth->unlinkForUser($auth->currentUserId());
    header('Location: index.php?page=profile&success=' . urlencode('Google account disconnected.'));
} catch (RuntimeException $e) {
    header('Location: index.php?page=profile&error=' . urlencode($e->getMessage()));
}
exit;
