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

$error        = null;
$showResend   = false;
$resendEmail  = '';
$redirect     = trim((string) ($_GET['redirect'] ?? ''));

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
            if (!empty($_SESSION['login_locked'])) {
                unset($_SESSION['login_locked']);
                $error = 'Too many failed attempts — please try again in 15 minutes.';
            } elseif (!empty($_SESSION['login_unverified'])) {
                $resendEmail = (string) ($_SESSION['pending_verification_email'] ?? $email);
                unset($_SESSION['login_unverified'], $_SESSION['pending_verification_email']);
                $showResend = true;
                $error      = 'Please verify your email address before signing in.';
            } else {
                $error = 'Invalid email or password.';
            }
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
  <link rel="stylesheet" href="assets/css/styles.css?v=<?= filemtime(__DIR__ . '/../assets/css/styles.css') ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous">
  <link rel="icon" type="image/x-icon" href="assets/icons/favicon.ico">
  <?php if ($googleAuth->isConfigured()): ?>
  <script src="https://accounts.google.com/gsi/client" async defer></script>
  <script src="assets/js/google-auth.js?v=<?= filemtime(__DIR__ . '/../assets/js/google-auth.js') ?>" defer></script>
  <?php endif; ?>
  <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>" defer></script>
</head>
<body data-google-client-id="<?= e(env_value('GOOGLE_CLIENT_ID', '')) ?>" data-google-auth-mode="login">

<div class="auth-shell">

  <!-- Left: gradient branding panel -->
  <div class="auth-panel--left">
    <div>
      <div class="auth-brand">
        <img src="assets/icons/icon-192.png" alt="" class="auth-logo" aria-hidden="true">
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
        <img src="assets/icons/icon-192.png" alt="" class="auth-logo" aria-hidden="true">
        <span class="auth-brand-name" style="color: var(--rx-navy);">RxTracker</span>
      </div>

      <h1>Welcome back!</h1>
      <p class="auth-subtitle">Sign in to your account</p>

      <?php if ($error !== null): ?>
        <div class="auth-error" role="alert"><?= e($error) ?></div>
      <?php endif; ?>

      <?php if ($showResend): ?>
        <div style="margin-bottom:1rem;">
          <form method="post" action="index.php?page=resend-verification">
            <?= csrf_field() ?>
            <input type="hidden" name="email" value="<?= e($resendEmail) ?>">
            <button type="submit" class="auth-link-btn">Resend verification email</button>
          </form>
        </div>
      <?php endif; ?>

      <?php if (isset($_GET['verified']) && $_GET['verified'] === '1'): ?>
        <div class="auth-success">Email verified! You can now sign in.</div>
      <?php endif; ?>

      <?php if (isset($_GET['resent']) && $_GET['resent'] === '1'): ?>
        <div class="auth-success">Verification email resent — please check your inbox.</div>
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
              <i class="fa-solid fa-eye pw-eye" aria-hidden="true"></i>
              <i class="fa-solid fa-eye-slash pw-eye-off" aria-hidden="true" style="display:none"></i>
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
      <?php if ($googleAuth->isConfigured()): ?>
        <div class="auth-divider"><span>or</span></div>
        <button type="button" class="google-auth-btn" data-google-auth-button aria-label="Continue with Google">
          <span class="google-auth-icon" aria-hidden="true">G</span>
          <span data-google-auth-text>Continue with Google</span>
        </button>
        <div class="auth-error google-auth-message" data-google-auth-message role="alert" hidden></div>
      <?php endif; ?>
      </form>

      <p class="auth-footer-link">
        Don't have an account? <a href="index.php?page=register">Sign up</a>
      </p>

      <p class="auth-footer-link" style="margin-top:0.5rem;font-size:0.8rem;">
        <a href="index.php?page=terms">Terms of Use</a> &middot; <a href="index.php?page=privacy">Privacy Policy</a>
      </p>

    </div>
  </div>

</div>

</body>
</html>
