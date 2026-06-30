<?php

declare(strict_types=1);

/** @var AuthService $auth */

$userId     = $auth->currentUserId();
$familyRepo = new FamilyProfileRepository(db());

$stmt = db()->prepare('SELECT id, email, display_name, google_id, profile_picture, password_hash, created_at FROM users WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $userId]);
$userRow = $stmt->fetch();
if (!is_array($userRow)) {
    header('Location: index.php?page=login');
    exit;
}

$flashSuccess = trim((string) ($_GET['success'] ?? ''));
$flashError   = trim((string) ($_GET['error'] ?? ''));

$profileRepo      = new MedicationRepository(db(), $userId);
$navNotifications = $profileRepo->getNotificationsForUser();
$navUnreadCount   = count(array_filter($navNotifications, static fn(array $n): bool => !(bool) $n['is_read']));

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

    if ($action === 'switch_family_profile') {
        $profileId  = (int) ($_POST['profile_id'] ?? 0);
        $redirectTo = trim((string) ($_POST['redirect_to'] ?? 'index.php'));
        if (!str_starts_with($redirectTo, 'index.php')) {
            $redirectTo = 'index.php';
        }
        if ($profileId > 0) {
            $target = $familyRepo->findProfile($profileId, $userId);
            if ($target !== null) {
                $auth->setActiveProfile($profileId);
            }
        } else {
            $auth->setActiveProfile(null);
        }
        header('Location: ' . $redirectTo);
        exit;
    }

    if ($action === 'create_family_profile') {
        try {
            $displayName  = trim(post_string('display_name'));
            $avatarColor  = trim(post_string('avatar_color')) ?: null;
            $relationship = trim(post_string('relationship')) ?: null;
            $birthYearRaw = trim(post_string('birth_year'));
            $birthYear    = $birthYearRaw !== '' ? (int) $birthYearRaw : null;
            $familyRepo->createProfile($userId, $displayName, $avatarColor, $relationship, $birthYear);
            header('Location: index.php?page=profile&success=' . urlencode($displayName . ' was added.'));
        } catch (RuntimeException $e) {
            header('Location: index.php?page=profile&error=' . urlencode($e->getMessage()));
        }
        exit;
    }

    if ($action === 'update_family_profile') {
        try {
            $profileId    = (int) ($_POST['profile_id'] ?? 0);
            $displayName  = trim(post_string('display_name'));
            $avatarColor  = trim(post_string('avatar_color')) ?: null;
            $relationship = trim(post_string('relationship')) ?: null;
            $birthYearRaw = trim(post_string('birth_year'));
            $birthYear    = $birthYearRaw !== '' ? (int) $birthYearRaw : null;
            $familyRepo->updateProfile($profileId, $userId, $displayName, $avatarColor, $relationship, $birthYear);
            header('Location: index.php?page=profile&success=' . urlencode($displayName . '\'s profile was updated.'));
        } catch (RuntimeException $e) {
            header('Location: index.php?page=profile&error=' . urlencode($e->getMessage()));
        }
        exit;
    }

    if ($action === 'delete_family_profile') {
        $profileId = (int) ($_POST['profile_id'] ?? 0);
        if ($profileId > 0) {
            $familyRepo->deleteProfile($profileId, $userId);
            if ($auth->activeProfileId() === $profileId) {
                $auth->setActiveProfile(null);
            }
        }
        header('Location: index.php?page=profile&success=' . urlencode('Family member removed.'));
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

$familyProfiles     = $familyRepo->profilesForUser($userId);
$relationships      = FamilyProfileRepository::allowedRelationships();
$familyEditId       = (int) ($_GET['family_edit'] ?? 0);
$familyEditProfile  = $familyEditId > 0 ? $familyRepo->findProfile($familyEditId, $userId) : null;
$palette            = ['#6366f1', '#ec4899', '#10b981', '#f59e0b', '#3b82f6', '#ef4444'];

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
  <link rel="stylesheet" href="assets/css/styles.css?v=<?= filemtime(__DIR__ . '/../assets/css/styles.css') ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous">
  <link rel="icon" type="image/x-icon" href="assets/icons/favicon.ico">
  <link rel="icon" type="image/png" sizes="192x192" href="assets/icons/icon-192.png">
  <link rel="apple-touch-icon" href="assets/icons/icon-192.png">
  <link rel="manifest" href="manifest.json">
  <?php if ($googleAuth->isConfigured()): ?>
  <script src="https://accounts.google.com/gsi/client" async defer></script>
  <script src="assets/js/google-auth.js?v=<?= filemtime(__DIR__ . '/../assets/js/google-auth.js') ?>" defer></script>
  <?php endif; ?>
  <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>" defer></script>
</head>
<body data-google-client-id="<?= e(env_value('GOOGLE_CLIENT_ID', '')) ?>" data-google-auth-mode="connect">
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
    </div>
    <div class="nav-actions">
      <?php $navShowRefillBtn = false; require __DIR__ . '/../includes/nav-bell.php'; ?>
      <?php
        $navActiveProfileId = $auth->activeProfileId();
        $navActiveProfile   = $navActiveProfileId !== null ? $familyRepo->findProfile($navActiveProfileId, $userId) : null;
        $navAvatarColor     = (string) ($navActiveProfile['avatar_color'] ?? '#6366f1');
        $navAvatarLetter    = mb_strtoupper(mb_substr((string) ($navActiveProfile['display_name'] ?? ($userRow['display_name'] ?? 'U')), 0, 1));
      ?>
      <div class="nav-user-menu" data-user-menu>
        <button type="button" class="nav-user-btn" aria-haspopup="true" aria-expanded="false" data-user-menu-btn
                title="<?= e((string) $userRow['email']) ?>" aria-label="My profile">
          <span class="nav-user-avatar" style="background:<?= e($navAvatarColor) ?>"><?= e($navAvatarLetter) ?></span>
        </button>
        <div class="nav-user-menu-panel" data-user-menu-panel hidden>
          <a href="index.php?page=profile" class="nav-user-menu-link nav-user-menu-link--top is-active">
            <i class="fa-solid fa-circle-user" aria-hidden="true"></i>
            My Profile
          </a>
          <?php if (!empty($familyProfiles)): ?>
          <div class="nav-user-menu-section-label">Family Members</div>
          <form method="post" action="index.php?page=profile" class="nav-user-menu-switcher-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="switch_family_profile">
            <input type="hidden" name="redirect_to" value="<?= e($_SERVER['REQUEST_URI'] ?? 'index.php') ?>">
            <button type="submit" name="profile_id" value="0"
                    class="profile-option<?= $navActiveProfileId === null ? ' is-active' : '' ?>">
              <span class="profile-option-avatar" style="background:#6366f1">
                <?= e(mb_strtoupper(mb_substr((string) ($userRow['display_name'] ?? 'U'), 0, 1))) ?>
              </span>
              <?= e((string) ($userRow['display_name'] ?? 'Me')) ?>
            </button>
            <?php foreach ($familyProfiles as $fp): ?>
            <button type="submit" name="profile_id" value="<?= (int) $fp['id'] ?>"
                    class="profile-option<?= $navActiveProfileId === (int) $fp['id'] ? ' is-active' : '' ?>">
              <span class="profile-option-avatar" style="background:<?= e((string) ($fp['avatar_color'] ?? '#6366f1')) ?>">
                <?= e(mb_strtoupper(mb_substr((string) $fp['display_name'], 0, 1))) ?>
              </span>
              <?= e((string) $fp['display_name']) ?>
              <?php if ($fp['relationship']): ?>
                <span class="profile-option-rel"><?= e((string) $fp['relationship']) ?></span>
              <?php endif; ?>
            </button>
            <?php endforeach; ?>
          </form>
          <a href="index.php?page=profile#family" class="nav-user-menu-link nav-user-menu-link--manage">
            <i class="fa-solid fa-users" aria-hidden="true"></i>
            Manage Family
          </a>
          <?php endif; ?>
        </div>
      </div>
      <a href="index.php?page=settings" class="nav-icon-link" aria-label="Settings" title="Settings">
        <i class="fa-solid fa-gear" aria-hidden="true"></i>
      </a>
      <a href="index.php?page=help" class="nav-icon-link" aria-label="Help" title="Help">
        <i class="fa-solid fa-circle-question" aria-hidden="true"></i>
      </a>
    </div>
    <button class="nav-hamburger" aria-label="Menu" aria-expanded="false" data-nav-toggle>&#9776;</button>
  </nav>

  <section class="profile-page">

    <div class="profile-page-header">
      <h1>My Profile</h1>
      <form method="post" action="index.php?page=logout">
        <?= csrf_field() ?>
        <button type="submit" class="profile-signout-btn">
          <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
          Sign out
        </button>
      </form>
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

      <!-- Connected Accounts -->
      <div class="panel">
        <div class="panel-heading">
          <i class="fa-brands fa-google" aria-hidden="true"></i>
          <h2>Connected Accounts</h2>
        </div>
        <div class="connected-account-row">
          <div class="connected-account-info">
            <strong>Google</strong>
            <?php if (!empty($userRow['google_id'])): ?>
              <span class="connected-account-status is-connected">Connected ✓</span>
            <?php else: ?>
              <span class="connected-account-status">Not connected</span>
            <?php endif; ?>
          </div>
          <?php if (!empty($userRow['google_id'])): ?>
            <form method="post" action="index.php?page=google-unlink">
              <?= csrf_field() ?>
              <button type="submit" class="secondary" aria-label="Disconnect Google account">Disconnect</button>
            </form>
          <?php elseif ($googleAuth->isConfigured()): ?>
            <button type="button" class="google-auth-btn google-auth-btn--compact" data-google-auth-button aria-label="Connect Google Account">
              <span class="google-auth-icon" aria-hidden="true">G</span>
              <span data-google-auth-text>Connect Google Account</span>
            </button>
          <?php else: ?>
            <span class="muted">Google sign-in is not configured.</span>
          <?php endif; ?>
        </div>
        <div class="auth-error google-auth-message" data-google-auth-message role="alert" hidden></div>
        <?php if (!empty($userRow['google_id']) && empty($userRow['password_hash'])): ?>
          <p class="settings-subsection-hint">Create a password before disconnecting Google, so you do not lose access.</p>
        <?php endif; ?>
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
                <i class="fa-solid fa-eye pw-eye" aria-hidden="true"></i>
                <i class="fa-solid fa-eye-slash pw-eye-off" aria-hidden="true" style="display:none"></i>
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
                <i class="fa-solid fa-eye pw-eye" aria-hidden="true"></i>
                <i class="fa-solid fa-eye-slash pw-eye-off" aria-hidden="true" style="display:none"></i>
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
                <i class="fa-solid fa-eye pw-eye" aria-hidden="true"></i>
                <i class="fa-solid fa-eye-slash pw-eye-off" aria-hidden="true" style="display:none"></i>
              </button>
            </div>
          </div>
          <button type="submit" class="secondary">Change password</button>
        </form>
      </div>

      <!-- Family Members -->
      <div class="panel">
        <div class="panel-heading">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          <h2>Family Members</h2>
        </div>
        <p class="muted" style="margin-bottom:1rem">Track medications for family members under one account — no separate logins needed.</p>

        <?php if ($familyProfiles !== []): ?>
        <ul class="sessions-list" style="margin-bottom:1.25rem">
          <?php foreach ($familyProfiles as $fp): ?>
          <li class="session-row">
            <div class="session-info">
              <span class="session-agent">
                <span style="display:inline-flex;align-items:center;justify-content:center;width:1.6rem;height:1.6rem;border-radius:50%;background:<?= e((string)($fp['avatar_color'] ?? '#6366f1')) ?>;color:#fff;font-size:.75rem;font-weight:700;margin-right:.5rem">
                  <?= e(mb_strtoupper(mb_substr((string)$fp['display_name'], 0, 1))) ?>
                </span>
                <?= e((string)$fp['display_name']) ?>
              </span>
              <?php if ($fp['relationship'] || $fp['birth_year']): ?>
              <span class="session-meta">
                <?php if ($fp['relationship']): ?><?= e((string)$fp['relationship']) ?><?php endif; ?>
                <?php if ($fp['relationship'] && $fp['birth_year']): ?> · <?php endif; ?>
                <?php if ($fp['birth_year']): ?>b. <?= (int)$fp['birth_year'] ?><?php endif; ?>
              </span>
              <?php endif; ?>
            </div>
            <div style="display:flex;gap:.5rem;flex-shrink:0">
              <a href="index.php?page=profile&family_edit=<?= (int)$fp['id'] ?>" class="secondary" style="font-size:.8rem;padding:.25rem .6rem">Edit</a>
              <form method="post" action="index.php?page=profile"
                    onsubmit="return confirm('Remove <?= e(addslashes((string)$fp['display_name'])) ?> from your family members?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_family_profile">
                <input type="hidden" name="profile_id" value="<?= (int)$fp['id'] ?>">
                <button type="submit" class="btn-danger" style="font-size:.8rem;padding:.25rem .6rem">Remove</button>
              </form>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <?php if ($familyEditProfile !== null): ?>
        <hr class="profile-divider">
        <h3 style="margin-bottom:.75rem;font-size:.95rem">Edit <?= e((string)$familyEditProfile['display_name']) ?></h3>
        <form method="post" action="index.php?page=profile" class="stacked-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_family_profile">
          <input type="hidden" name="profile_id" value="<?= (int)$familyEditProfile['id'] ?>">
          <div class="form-group">
            <label for="edit_display_name">Name <span style="color:var(--danger)">*</span></label>
            <input type="text" id="edit_display_name" name="display_name" required maxlength="100"
                   value="<?= e((string)$familyEditProfile['display_name']) ?>">
          </div>
          <div class="form-group">
            <label for="edit_relationship">Relationship</label>
            <select id="edit_relationship" name="relationship">
              <option value="">— Optional —</option>
              <?php foreach ($relationships as $rel): ?>
              <option value="<?= e($rel) ?>"<?= $familyEditProfile['relationship'] === $rel ? ' selected' : '' ?>><?= e($rel) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="edit_birth_year">Birth Year</label>
            <input type="number" id="edit_birth_year" name="birth_year" min="1900" max="<?= (int)date('Y') ?>"
                   value="<?= $familyEditProfile['birth_year'] !== null ? (int)$familyEditProfile['birth_year'] : '' ?>">
          </div>
          <div class="form-group">
            <label><i class="fa-solid fa-palette" aria-hidden="true" style="margin-right:.35rem;color:var(--rx-deep-blue)"></i>Avatar Color</label>
            <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-top:.25rem">
              <?php
              $currentColor = (string)($familyEditProfile['avatar_color'] ?? '#6366f1');
              foreach ($palette as $color): ?>
              <label style="cursor:pointer;line-height:0">
                <input type="radio" name="avatar_color" value="<?= e($color) ?>"
                       <?= $currentColor === $color ? 'checked' : '' ?> style="position:absolute;opacity:0;width:0;height:0">
                <span data-color-swatch data-color="<?= e($color) ?>" style="display:block;width:1.6rem;height:1.6rem;border-radius:50%;background:<?= e($color) ?>;box-shadow:<?= $currentColor === $color ? '0 0 0 2px #fff, 0 0 0 4px #0d1b2e' : '0 0 0 1px rgba(0,0,0,.15)' ?>;transition:box-shadow .15s"></span>
              </label>
              <?php endforeach; ?>
              <input type="color" id="edit_color_custom" value="<?= e($currentColor) ?>" style="width:1.6rem;height:1.6rem;border-radius:50%;border:none;cursor:pointer;padding:0;box-shadow:0 0 0 1px rgba(0,0,0,.15)">
            </div>
            <input type="hidden" name="avatar_color_final" id="edit_avatar_color_final" value="<?= e($currentColor) ?>">
          </div>
          <div style="display:flex;gap:.5rem">
            <button type="submit" class="secondary">Save Changes</button>
            <a href="index.php?page=profile" class="secondary">Cancel</a>
          </div>
        </form>
        <hr class="profile-divider">
        <?php endif; ?>

        <h3 style="margin-bottom:.75rem;font-size:.95rem"><?= $familyEditProfile !== null ? 'Add Another Member' : 'Add a Family Member' ?></h3>
        <form method="post" action="index.php?page=profile" class="stacked-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create_family_profile">
          <div class="form-group">
            <label for="family_display_name">Name <span style="color:var(--danger)">*</span></label>
            <input type="text" id="family_display_name" name="display_name" required maxlength="100" placeholder="e.g. Sarah">
          </div>
          <div class="form-group">
            <label for="family_relationship">Relationship</label>
            <select id="family_relationship" name="relationship">
              <option value="">— Optional —</option>
              <?php foreach ($relationships as $rel): ?>
              <option value="<?= e($rel) ?>"><?= e($rel) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="family_birth_year">Birth Year</label>
            <input type="number" id="family_birth_year" name="birth_year" min="1900" max="<?= (int)date('Y') ?>" placeholder="e.g. 1985">
          </div>
          <div class="form-group">
            <label><i class="fa-solid fa-palette" aria-hidden="true" style="margin-right:.35rem;color:var(--rx-deep-blue)"></i>Avatar Color</label>
            <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-top:.25rem">
              <?php foreach ($palette as $i => $color): ?>
              <label style="cursor:pointer;line-height:0">
                <input type="radio" name="avatar_color" value="<?= e($color) ?>"
                       <?= $i === 0 ? 'checked' : '' ?> style="position:absolute;opacity:0;width:0;height:0">
                <span data-color-swatch data-color="<?= e($color) ?>" style="display:block;width:1.6rem;height:1.6rem;border-radius:50%;background:<?= e($color) ?>;box-shadow:<?= $i === 0 ? '0 0 0 2px #fff, 0 0 0 4px #0d1b2e' : '0 0 0 1px rgba(0,0,0,.15)' ?>;transition:box-shadow .15s"></span>
              </label>
              <?php endforeach; ?>
              <input type="color" id="family_color_custom" value="#6366f1" style="width:1.6rem;height:1.6rem;border-radius:50%;border:none;cursor:pointer;padding:0;box-shadow:0 0 0 1px rgba(0,0,0,.15)">
            </div>
            <input type="hidden" name="avatar_color_final" id="family_avatar_color_final" value="#6366f1">
          </div>
          <button type="submit" class="secondary">Add Family Member</button>
        </form>
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
<script>
(function () {
  function updateSwatches(form, activeColor) {
    form.querySelectorAll('[data-color-swatch]').forEach(function (sw) {
      var swColor = sw.dataset.color;
      sw.style.boxShadow = swColor === activeColor
        ? '0 0 0 2px #fff, 0 0 0 4px #0d1b2e'
        : '0 0 0 1px rgba(0,0,0,.15)';
    });
  }
  document.querySelectorAll('input[name="action"]').forEach(function (inp) {
    var isCreate = inp.value === 'create_family_profile';
    var isEdit   = inp.value === 'update_family_profile';
    if (!isCreate && !isEdit) { return; }
    var form = inp.closest('form');
    if (!form) { return; }
    var radios      = form.querySelectorAll('input[type="radio"][name="avatar_color"]');
    var finalInputId = isCreate ? 'family_avatar_color_final' : 'edit_avatar_color_final';
    var customId     = isCreate ? 'family_color_custom'       : 'edit_color_custom';
    var finalInput   = form.querySelector('#' + finalInputId);
    var customInput  = form.querySelector('#' + customId);
    if (!finalInput || !customInput) { return; }
    radios.forEach(function (radio) {
      radio.addEventListener('change', function () {
        finalInput.value = radio.value;
        updateSwatches(form, radio.value);
      });
    });
    customInput.addEventListener('input', function () {
      finalInput.value = customInput.value;
      updateSwatches(form, customInput.value);
    });
    form.addEventListener('submit', function () {
      radios.forEach(function (r) { r.disabled = true; });
      finalInput.name = 'avatar_color';
    });
  });
})();
</script>
</body>
</html>
