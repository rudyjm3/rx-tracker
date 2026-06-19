<?php

declare(strict_types=1);

/** @var AuthService $auth */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Already logged in — go to dashboard
if ($auth->currentUserId() > 0) {
    header('Location: index.php');
    exit;
}

$error    = null;
$redirect = trim((string) ($_GET['redirect'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $email    = post_string('email');
        $password = (string) ($_POST['password'] ?? '');
        $remember = isset($_POST['remember_me']);

        if ($email === '' || $password === '') {
            $error = 'Email and password are required.';
        } elseif (!$auth->login($email, $password, $remember)) {
            $error = 'Invalid email or password.';
        } else {
            $destination = 'index.php';
            if ($redirect !== '' && str_starts_with($redirect, '/') && !str_starts_with($redirect, '//')) {
                $destination = $redirect;
            }
            header('Location: ' . $destination);
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
  <title>Sign In — RxTracker</title>
  <link rel="stylesheet" href="assets/css/styles.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous">
  <link rel="icon" type="image/x-icon" href="assets/icons/favicon.ico">
  <script src="assets/js/app.js" defer></script>
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
      <p class="auth-tagline">Stay on track<br>with every dose.</p>
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

  <!-- Right: login form -->
  <div class="auth-panel--right">
    <div class="auth-card">

      <!-- Mobile-only logo header -->
      <div class="auth-mobile-header">
        <img src="assets/icons/logo-round.png" alt="" class="auth-logo" aria-hidden="true">
        <span class="auth-brand-name" style="color: var(--rx-navy);">RxTracker</span>
      </div>

      <h1>Welcome back!</h1>
      <p class="auth-subtitle">Sign in to your account</p>

      <?php if ($error !== null): ?>
        <div class="auth-error" role="alert"><?= e($error) ?></div>
      <?php endif; ?>

      <?php if (isset($_GET['reset']) && $_GET['reset'] === 'success'): ?>
        <div class="auth-success">Password reset successfully. You can now sign in.</div>
      <?php endif; ?>

      <?php if (isset($_GET['deleted']) && $_GET['deleted'] === '1'): ?>
        <div class="auth-success">Your account has been deleted. Sorry to see you go.</div>
      <?php endif; ?>

      <form method="post" action="index.php?page=login<?= $redirect !== '' ? '&redirect=' . urlencode($redirect) : '' ?>" class="stacked-form" novalidate>
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

        <div class="form-group">
          <label for="password">Password</label>
          <div class="password-input-wrapper">
            <input
              type="password"
              id="password"
              name="password"
              autocomplete="current-password"
              required
            >
            <button type="button" class="password-toggle" aria-label="Show password">
              <svg class="pw-eye" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg class="pw-eye-off" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
            </button>
          </div>
          <a href="index.php?page=forgot-password" class="auth-forgot-link">Forgot password?</a>
        </div>

        <div class="form-group auth-remember-row">
          <label class="auth-checkbox-label">
            <input type="checkbox" name="remember_me" value="1">
            Remember me for 30 days
          </label>
        </div>

        <button type="submit">Sign In</button>
      </form>

      <p class="auth-footer-link">
        Don't have an account? <a href="index.php?page=register">Sign up</a>
      </p>

    </div>
  </div>

</div>

</body>
</html>
