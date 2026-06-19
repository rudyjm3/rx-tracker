<?php

declare(strict_types=1);

/** @var AuthService $auth */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Already logged in
if ($auth->currentUserId() > 0) {
    header('Location: index.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $name     = post_string('display_name');
        $email    = post_string('email');
        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['confirm_password'] ?? '');

        if ($email === '' || $password === '') {
            $error = 'Email and password are required.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            try {
                $userId = $auth->register($email, $password, $name);
                $auth->login($email, $password);
                header('Location: index.php');
                exit;
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
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
  <title>Create Account — RxTracker</title>
  <link rel="stylesheet" href="assets/css/styles.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous">
  <link rel="icon" type="image/x-icon" href="assets/icons/favicon.ico">
</head>
<body>

<div class="auth-shell">

  <!-- Left: gradient branding panel -->
  <div class="auth-panel--left">
    <div>
      <div class="auth-brand">
        <img src="assets/icons/logo-round.png" alt="" class="auth-logo" aria-hidden="true">
        <span class="auth-brand-name">RxTracker</span>
      </div>
      <p class="auth-tagline">Take control<br>of your health.</p>
      <ul class="auth-features">
        <li>
          <span class="auth-feature-icon"><i class="fa-solid fa-check fa-xs" aria-hidden="true"></i></span>
          Track adherence
        </li>
        <li>
          <span class="auth-feature-icon"><i class="fa-solid fa-check fa-xs" aria-hidden="true"></i></span>
          Medication plans
        </li>
        <li>
          <span class="auth-feature-icon"><i class="fa-solid fa-check fa-xs" aria-hidden="true"></i></span>
          Refill reminders
        </li>
        <li>
          <span class="auth-feature-icon"><i class="fa-solid fa-check fa-xs" aria-hidden="true"></i></span>
          Adherence reports
        </li>
      </ul>
    </div>
    <img src="assets/images/blue-white-pill-graphic.png" alt="" class="auth-graphic" aria-hidden="true">
  </div>

  <!-- Right: registration form -->
  <div class="auth-panel--right">
    <div class="auth-card">

      <!-- Mobile-only logo header -->
      <div class="auth-mobile-header">
        <img src="assets/icons/logo-round.png" alt="" class="auth-logo" aria-hidden="true">
        <span class="auth-brand-name" style="color: var(--rx-navy);">RxTracker</span>
      </div>

      <h1>Create your account</h1>
      <p class="auth-subtitle">Start tracking your medications today</p>

      <?php if ($error !== null): ?>
        <div class="auth-error" role="alert"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="post" action="index.php?page=register" class="stacked-form" novalidate>
        <?= csrf_field() ?>

        <div class="form-group">
          <label for="display_name">Full name <span class="auth-optional">(optional)</span></label>
          <input
            type="text"
            id="display_name"
            name="display_name"
            value="<?= e(post_string('display_name')) ?>"
            autocomplete="name"
            autofocus
          >
        </div>

        <div class="form-group">
          <label for="email">Email address</label>
          <input
            type="email"
            id="email"
            name="email"
            value="<?= e(post_string('email')) ?>"
            autocomplete="email"
            required
          >
        </div>

        <div class="form-group">
          <label for="password">Password <span class="auth-optional">(min 8 characters)</span></label>
          <input
            type="password"
            id="password"
            name="password"
            autocomplete="new-password"
            required
          >
        </div>

        <div class="form-group">
          <label for="confirm_password">Confirm password</label>
          <input
            type="password"
            id="confirm_password"
            name="confirm_password"
            autocomplete="new-password"
            required
          >
        </div>

        <button type="submit">Create account</button>
      </form>

      <p class="auth-footer-link">
        Already have an account? <a href="index.php?page=login">Sign in</a>
      </p>

    </div>
  </div>

</div>

</body>
</html>
