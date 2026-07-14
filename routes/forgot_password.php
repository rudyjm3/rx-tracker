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

$sent  = false;
$error = null;
$ip    = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Invalid form submission. Please try again.';
    } elseif ($auth->isIpRateLimited($ip)) {
        $error = 'Too many requests — please try again in 15 minutes.';
    } else {
        $email = strtolower(post_string('email'));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $auth->recordIpAttempt($ip);

            // Always show the same message to prevent email enumeration
            $sent = true;

            // Look up the user and issue a reset token
            $stmt = db()->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $stmt->execute(['email' => $email]);
            $userId = $stmt->fetchColumn();

            if ($userId !== false) {
                $token   = bin2hex(random_bytes(32));
                $expires = (new DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');

                db()->prepare(
                    'UPDATE users SET reset_token = :token, reset_token_expires_at = :expires WHERE id = :id'
                )->execute(['token' => $token, 'expires' => $expires, 'id' => (int) $userId]);

                try {
                    $mail = new MailService();
                    $mail->sendPasswordReset($email, $token);
                } catch (Throwable) {
                    // Silently swallow — user still sees the success message
                }
            }
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
  <title>Forgot Password — RxTracker</title>
  <link rel="stylesheet" href="assets/css/styles.css?v=<?= filemtime(__DIR__ . '/../assets/css/styles.css') ?>">
  <link rel="icon" type="image/x-icon" href="assets/icons/favicon.ico">
</head>
<body>

<div class="auth-shell auth-shell--centered">
  <div class="auth-panel--right" style="width: 100%;">
    <div class="auth-card">

      <div class="auth-mobile-header" style="display: flex;">
        <img src="assets/icons/logo-round.png" alt="" class="auth-logo" aria-hidden="true">
        <span class="auth-brand-name" style="color: var(--rx-navy);">RxTracker</span>
      </div>

      <h1>Forgot password?</h1>
      <p class="auth-subtitle">Enter your email and we'll send a reset link.</p>

      <?php if ($error !== null): ?>
        <div class="auth-error" role="alert"><?= e($error) ?></div>
      <?php endif; ?>

      <?php if ($sent): ?>
        <div class="auth-success">
          If an account exists for that email, a reset link has been sent. Check your inbox.
        </div>
        <p class="auth-footer-link"><a href="index.php?page=login">&larr; Back to sign in</a></p>
      <?php else: ?>
        <form method="post" action="index.php?page=forgot-password" class="stacked-form" novalidate>
          <?= csrf_field() ?>
          <div class="form-group">
            <label for="email">Email address</label>
            <input
              type="email"
              id="email"
              name="email"
              value="<?= e(post_string('email')) ?>"
              autocomplete="email"
              required
              autofocus
            >
          </div>
          <button type="submit">Send reset link</button>
        </form>
        <p class="auth-footer-link"><a href="index.php?page=login">&larr; Back to sign in</a></p>
      <?php endif; ?>

    </div>
  </div>
</div>

</body>
</html>
