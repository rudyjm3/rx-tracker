<?php

declare(strict_types=1);

/** @var AuthService $auth */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Redirect logged-in users away from this page
if ($auth->currentUserId() > 0) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
    header('Location: index.php?page=login');
    exit;
}

$email = strtolower(trim((string) ($_POST['email'] ?? '')));
if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    // sendVerificationEmail() is a no-op for unknown emails, so no enumeration risk
    $auth->sendVerificationEmail($email);
}

// Always redirect to a success message to prevent email enumeration
header('Location: index.php?page=login&resent=1');
exit;
