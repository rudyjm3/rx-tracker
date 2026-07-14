<?php

declare(strict_types=1);

/** @var AuthService $auth */

$token = trim((string) ($_GET['token'] ?? ''));

if ($token !== '' && $auth->verifyEmail($token)) {
    header('Location: index.php?page=login&verified=1');
    exit;
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#0754A8">
  <title>Email Verification — RxTracker</title>
  <link rel="stylesheet" href="assets/css/styles.css?v=<?= filemtime(__DIR__ . '/../assets/css/styles.css') ?>">
  <link rel="icon" type="image/x-icon" href="assets/icons/favicon.ico">
</head>
<body>

<div class="auth-shell">
  <div class="auth-panel--left">
    <div>
      <div class="auth-brand">
        <img src="assets/icons/icon-192.png" alt="" class="auth-logo" aria-hidden="true">
        <span class="auth-brand-name">RxTracker</span>
      </div>
    </div>
  </div>

  <div class="auth-panel--right">
    <div class="auth-card">
      <h1>Verification link invalid</h1>
      <p class="auth-subtitle">This link may have expired or already been used.</p>
      <div class="auth-error" role="alert">
        Verification links are valid for 24 hours. Please request a new one from the sign-in page.
      </div>
      <p class="auth-footer-link" style="margin-top:2rem;">
        <a href="index.php?page=login">Back to sign in</a>
      </p>
    </div>
  </div>
</div>

</body>
</html>
