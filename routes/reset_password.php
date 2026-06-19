<?php

declare(strict_types=1);

/** @var AuthService $auth */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($auth->currentUserId() > 0) {
    header('Location: index.php');
    exit;
}

$token = trim((string) ($_GET['token'] ?? ($_POST['token'] ?? '')));
$error = null;

// Validate token
$validUser = null;
if ($token !== '') {
    $stmt = db()->prepare(
        'SELECT id, email FROM users
         WHERE reset_token = :token
           AND reset_token_expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute(['token' => $token]);
    $row = $stmt->fetch();
    if (is_array($row)) {
        $validUser = $row;
    }
}

if ($validUser === null) {
    $error = 'This password reset link is invalid or has expired. Please request a new one.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validUser !== null) {
    if (!verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['confirm_password'] ?? '');

        if (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            db()->prepare(
                'UPDATE users
                 SET password_hash = :hash, reset_token = NULL, reset_token_expires_at = NULL
                 WHERE id = :id'
            )->execute([
                'hash' => password_hash($password, PASSWORD_BCRYPT),
                'id'   => (int) $validUser['id'],
            ]);
            header('Location: index.php?page=login&reset=success');
            exit;
        }
    }
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#0754A8">
  <title>Reset Password — RxTracker</title>
  <link rel="stylesheet" href="assets/css/styles.css">
  <link rel="icon" type="image/x-icon" href="assets/icons/favicon.ico">
  <script src="assets/js/app.js" defer></script>
</head>
<body>

<div class="auth-shell auth-shell--centered">
  <div class="auth-panel--right" style="width: 100%;">
    <div class="auth-card">

      <div class="auth-mobile-header" style="display: flex;">
        <img src="assets/icons/logo-round.png" alt="" class="auth-logo" aria-hidden="true">
        <span class="auth-brand-name" style="color: var(--rx-navy);">RxTracker</span>
      </div>

      <h1>Set new password</h1>
      <p class="auth-subtitle">Choose a strong password for your account.</p>

      <?php if ($error !== null): ?>
        <div class="auth-error" role="alert"><?= e($error) ?></div>
        <?php if ($validUser === null): ?>
          <p class="auth-footer-link"><a href="index.php?page=forgot-password">Request a new reset link</a></p>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($validUser !== null): ?>
        <form method="post" action="index.php?page=reset-password" class="stacked-form" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="token" value="<?= e($token) ?>">

          <div class="form-group">
            <label for="password">New password <span class="auth-optional">(min 8 characters)</span></label>
            <div class="password-input-wrapper">
              <input
                type="password"
                id="password"
                name="password"
                autocomplete="new-password"
                required
                autofocus
              >
              <button type="button" class="password-toggle" aria-label="Show password">
                <svg class="pw-eye" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="pw-eye-off" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
              </button>
            </div>
          </div>

          <div class="form-group">
            <label for="confirm_password">Confirm new password</label>
            <div class="password-input-wrapper">
              <input
                type="password"
                id="confirm_password"
                name="confirm_password"
                autocomplete="new-password"
                required
              >
              <button type="button" class="password-toggle" aria-label="Show password">
                <svg class="pw-eye" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="pw-eye-off" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
              </button>
            </div>
          </div>

          <button type="submit">Reset password</button>
        </form>
      <?php endif; ?>

    </div>
  </div>
</div>

</body>
</html>
