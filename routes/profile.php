<?php

declare(strict_types=1);

/** @var AuthService $auth */

$userId = $auth->currentUserId();

$stmt = db()->prepare('SELECT id, email, display_name, created_at FROM users WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $userId]);
$userRow = $stmt->fetch();
if (!is_array($userRow)) {
    header('Location: index.php?page=login');
    exit;
}

$flashSuccess = trim((string) ($_GET['success'] ?? ''));
$flashError   = trim((string) ($_GET['error'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token(post_string('csrf_token'))) {
        header('Location: index.php?page=profile&error=' . urlencode('Session expired. Please try again.'));
        exit;
    }

    $action = post_string('action');

    if ($action === 'update_display_name') {
        $newName = post_string('display_name');
        if ($newName === '') {
            header('Location: index.php?page=profile&error=' . urlencode('Display name cannot be empty.'));
            exit;
        }
        if (strlen($newName) > 100) {
            header('Location: index.php?page=profile&error=' . urlencode('Display name must be 100 characters or fewer.'));
            exit;
        }
        db()->prepare('UPDATE users SET display_name = :name WHERE id = :id')
            ->execute(['name' => $newName, 'id' => $userId]);
        header('Location: index.php?page=profile&success=' . urlencode('Display name updated.'));
        exit;
    }

    if ($action === 'change_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword     = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if ($newPassword !== $confirmPassword) {
            header('Location: index.php?page=profile&error=' . urlencode('New passwords do not match.'));
            exit;
        }
        if (strlen($newPassword) < 8) {
            header('Location: index.php?page=profile&error=' . urlencode('New password must be at least 8 characters.'));
            exit;
        }
        if (!$auth->changePassword($userId, $currentPassword, $newPassword)) {
            header('Location: index.php?page=profile&error=' . urlencode('Current password is incorrect.'));
            exit;
        }
        header('Location: index.php?page=profile&success=' . urlencode('Password changed successfully.'));
        exit;
    }

    if ($action === 'revoke_other_sessions') {
        $currentToken = (string) ($_COOKIE['rx_remember'] ?? '');
        if ($currentToken !== '') {
            $stmt = db()->prepare(
                'DELETE FROM user_sessions WHERE user_id = :uid AND session_token != :token'
            );
            $stmt->execute(['uid' => $userId, 'token' => $currentToken]);
        } else {
            $stmt = db()->prepare('DELETE FROM user_sessions WHERE user_id = :uid');
            $stmt->execute(['uid' => $userId]);
        }
        $count = $stmt->rowCount();
        $label = $count === 1 ? '1 other device' : "{$count} other devices";
        header('Location: index.php?page=profile&success=' . urlencode("Signed out of {$label}."));
        exit;
    }

    if ($action === 'delete_account') {
        $confirmEmail = strtolower(post_string('confirm_email'));
        if ($confirmEmail !== strtolower((string) $userRow['email'])) {
            header('Location: index.php?page=profile&error=' . urlencode('Email confirmation did not match. Account not deleted.'));
            exit;
        }
        $auth->deleteAccount($userId);
        header('Location: index.php?page=login&deleted=1');
        exit;
    }

    header('Location: index.php?page=profile');
    exit;
}

// Active remember-me sessions
$sessStmt = db()->prepare(
    'SELECT id, session_token, user_agent, ip_address, created_at, expires_at FROM user_sessions
     WHERE user_id = :uid AND expires_at > NOW()
     ORDER BY created_at DESC'
);
$sessStmt->execute(['uid' => $userId]);
$activeSessions = $sessStmt->fetchAll();
$currentToken   = (string) ($_COOKIE['rx_remember'] ?? '');

$memberSince = '';
if (isset($userRow['created_at']) && $userRow['created_at'] !== '') {
    try {
        $memberSince = (new DateTimeImmutable((string) $userRow['created_at']))->format('F j, Y');
    } catch (Throwable) {
        $memberSince = '';
    }
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#0754A8">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <title>My Profile — RxTracker</title>
  <link rel="stylesheet" href="assets/css/styles.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous">
  <link rel="icon" type="image/x-icon" href="assets/icons/favicon.ico">
  <link rel="icon" type="image/png" sizes="192x192" href="assets/icons/icon-192.png">
  <link rel="apple-touch-icon" href="assets/icons/icon-192.png">
  <link rel="manifest" href="manifest.json">
</head>
<body>
<main class="app-shell">
  <nav class="top-nav">
    <a class="nav-brand" href="index.php">
      <img src="assets/icons/icon-192.png" alt="" class="nav-logo" aria-hidden="true" width="48" height="48">
      RxTracker
    </a>
    <div class="nav-links">
      <a href="index.php">Dashboard</a>
      <a href="index.php?page=medications">Medications</a>
      <a href="index.php?page=calendar">Calendar</a>
      <a href="index.php?page=export">Export</a>
      <a href="index.php?page=settings">Settings</a>
      <a href="index.php?page=help">Help</a>
    </div>
    <div class="nav-actions">
      <button class="nav-bell-btn" aria-label="Notifications">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        <span class="nav-bell-badge" aria-label="0 notifications" hidden>0</span>
      </button>
      <a class="nav-user-btn is-active" href="index.php?page=profile"
         title="<?= e((string) $userRow['email']) ?>"
         aria-label="My profile">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      </a>
      <span class="nav-user-name"><?= e((string) ($userRow['display_name'] ?? $userRow['email'])) ?></span>
      <form method="post" action="index.php?page=logout" class="nav-logout-form">
        <?= csrf_field() ?>
        <button type="submit" class="nav-logout-btn" aria-label="Sign out">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        </button>
      </form>
    </div>
    <button class="nav-hamburger" aria-label="Menu" aria-expanded="false" data-nav-toggle>&#9776;</button>
  </nav>

  <section class="profile-page">

    <div class="profile-page-header">
      <h1>My Profile</h1>
    </div>

    <?php if ($flashSuccess !== ''): ?>
      <div class="auth-success profile-flash" role="status"><?= e($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($flashError !== ''): ?>
      <div class="auth-error profile-flash" role="alert"><?= e($flashError) ?></div>
    <?php endif; ?>

    <div class="profile-grid">

      <!-- Profile Information -->
      <div class="panel">
        <div class="panel-heading">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <h2>Profile Information</h2>
        </div>

        <div class="profile-info-row">
          <span class="profile-info-label">Email</span>
          <span class="profile-info-value"><?= e((string) $userRow['email']) ?></span>
        </div>
        <?php if ($memberSince !== ''): ?>
        <div class="profile-info-row">
          <span class="profile-info-label">Member since</span>
          <span class="profile-info-value"><?= e($memberSince) ?></span>
        </div>
        <?php endif; ?>

        <hr class="profile-divider">

        <form method="post" action="index.php?page=profile" class="stacked-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_display_name">
          <div class="form-group">
            <label for="display_name">Display name</label>
            <input
              type="text"
              id="display_name"
              name="display_name"
              value="<?= e((string) ($userRow['display_name'] ?? '')) ?>"
              maxlength="100"
              placeholder="Your name"
              autocomplete="name"
              required
            >
          </div>
          <button type="submit" class="secondary">Save name</button>
        </form>
      </div>

      <!-- Change Password -->
      <div class="panel">
        <div class="panel-heading">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          <h2>Change Password</h2>
        </div>
        <form method="post" action="index.php?page=profile" class="stacked-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="change_password">
          <div class="form-group">
            <label for="current_password">Current password</label>
            <div class="password-input-wrapper">
              <input
                type="password"
                id="current_password"
                name="current_password"
                autocomplete="current-password"
                required
              >
              <button type="button" class="password-toggle" aria-label="Show password">
                <svg class="pw-eye" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="pw-eye-off" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
              </button>
            </div>
          </div>
          <div class="form-group">
            <label for="new_password">New password</label>
            <div class="password-input-wrapper">
              <input
                type="password"
                id="new_password"
                name="new_password"
                autocomplete="new-password"
                minlength="8"
                required
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
                minlength="8"
                required
              >
              <button type="button" class="password-toggle" aria-label="Show password">
                <svg class="pw-eye" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <svg class="pw-eye-off" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
              </button>
            </div>
          </div>
          <button type="submit" class="secondary">Change password</button>
        </form>
      </div>

      <!-- Active Sessions -->
      <div class="panel">
        <div class="panel-heading">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
          <h2>Active Sessions</h2>
        </div>

        <?php if ($activeSessions === []): ?>
          <p class="muted">No active remember-me sessions. Sessions are created when you check "Remember me" at login.</p>
        <?php else: ?>
          <ul class="sessions-list">
            <?php foreach ($activeSessions as $sess): ?>
              <?php $isCurrent = $currentToken !== '' && (string) $sess['session_token'] === $currentToken; ?>
              <li class="session-row">
                <div class="session-info">
                  <span class="session-agent"><?= e(substr((string) $sess['user_agent'], 0, 80)) ?></span>
                  <span class="session-meta">
                    <?= e((string) $sess['ip_address']) ?>
                    &middot;
                    Expires <?= e((new DateTimeImmutable((string) $sess['expires_at']))->format('M j, Y')) ?>
                  </span>
                </div>
                <?php if ($isCurrent): ?>
                  <span class="session-badge">Current</span>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>

          <?php $otherCount = count(array_filter($activeSessions, fn($s) => (string) $s['session_token'] !== $currentToken)); ?>
          <?php if ($otherCount > 0): ?>
            <form method="post" action="index.php?page=profile" style="margin-top: 1rem;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="revoke_other_sessions">
              <button type="submit" class="secondary">Sign out all other devices</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <!-- Data & Privacy -->
      <div class="panel">
        <div class="panel-heading">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          <h2>Data &amp; Privacy</h2>
        </div>

        <p class="muted" style="margin-bottom: 1rem;">Export a copy of all your medication and dose history data.</p>
        <a href="index.php?page=export" class="secondary btn-inline">Go to Export</a>

        <hr class="profile-divider">

        <div class="danger-zone">
          <h3 class="danger-zone-heading">Delete Account</h3>
          <p class="muted">This permanently deletes your account and all data — medications, dose history, and settings. This cannot be undone.</p>

          <details class="danger-details">
            <summary class="danger-summary">I want to delete my account</summary>
            <form method="post" action="index.php?page=profile" class="stacked-form danger-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_account">
              <div class="form-group">
                <label for="confirm_email">Type your email address to confirm</label>
                <input
                  type="email"
                  id="confirm_email"
                  name="confirm_email"
                  placeholder="<?= e((string) $userRow['email']) ?>"
                  autocomplete="off"
                  required
                >
              </div>
              <button type="submit" class="btn-danger">Permanently delete my account</button>
            </form>
          </details>
        </div>
      </div>

    </div><!-- /.profile-grid -->

  </section>

</main>
<nav class="bottom-nav" aria-label="Main navigation">
  <a href="index.php" class="bottom-nav-item" aria-label="Dashboard">
    <i class="fa-solid fa-house" aria-hidden="true"></i>
    Dashboard
  </a>
  <a href="index.php?page=medications" class="bottom-nav-item" aria-label="Medications">
    <i class="fa-solid fa-pills" aria-hidden="true"></i>
    Medications
  </a>
  <a href="index.php?page=calendar" class="bottom-nav-item" aria-label="Calendar">
    <i class="fa-regular fa-calendar" aria-hidden="true"></i>
    Calendar
  </a>
  <a href="index.php?page=export" class="bottom-nav-item" aria-label="Export">
    <i class="fa-solid fa-file-export" aria-hidden="true"></i>
    Export
  </a>
  <button type="button" class="bottom-nav-item" aria-label="More" onclick="document.getElementById('more-menu').classList.add('is-open')">
    <i class="fa-solid fa-ellipsis" aria-hidden="true"></i>
    More
  </button>
</nav>
<div id="more-menu" class="more-menu">
  <div class="more-menu__backdrop" onclick="document.getElementById('more-menu').classList.remove('is-open')"></div>
  <div class="more-menu__sheet">
    <a href="index.php?page=settings" class="more-menu__item">
      <i class="fa-solid fa-gear" aria-hidden="true"></i>
      Settings
    </a>
    <a href="index.php?page=help" class="more-menu__item">
      <i class="fa-solid fa-circle-question" aria-hidden="true"></i>
      Help
    </a>
    <a href="index.php?page=profile" class="more-menu__item">
      <i class="fa-solid fa-user" aria-hidden="true"></i>
      My Profile
    </a>
  </div>
</div>
</body>
</html>
