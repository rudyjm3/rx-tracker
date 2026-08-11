<?php

declare(strict_types=1);

/** @var AuthService $auth */

$userId     = $auth->currentUserId();
$familyRepo = new FamilyProfileRepository(db());

$stmt = db()->prepare('SELECT id, email, display_name, first_name, last_name, birth_date, google_id, profile_picture, password_hash, created_at, height_value, height_unit FROM users WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $userId]);
$userRow = $stmt->fetch();
if (!is_array($userRow)) {
    header('Location: index.php?page=login');
    exit;
}

$flashSuccess = trim((string) ($_GET['success'] ?? ''));
$flashError   = trim((string) ($_GET['error'] ?? ''));
$modalReopenAllergies = ($_GET['open'] ?? '') === 'allergies';

$profileRepo      = new MedicationRepository(db(), $userId);
$navNotifications = $profileRepo->getNotificationsForUser();
$navUnreadCount   = count(array_filter($navNotifications, static fn(array $n): bool => !(bool) $n['is_read']));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token(post_string('csrf_token'))) {
        header('Location: index.php?page=profile&error=' . urlencode('Session expired. Please try again.'));
        exit;
    }

    $action = post_string('action');

    if ($action === 'update_profile_info') {
        $newName   = post_string('display_name');
        $firstName = trim(post_string('first_name')) ?: null;
        $lastName  = trim(post_string('last_name')) ?: null;
        $birthDate = trim(post_string('birth_date')) ?: null;

        if ($newName === '') {
            $newName = fallback_display_name($firstName, $lastName);
        }
        if ($newName === '') {
            header('Location: index.php?page=profile&error=' . urlencode('Enter a display name or a first name.'));
            exit;
        }
        if (strlen($newName) > 100 || ($firstName !== null && mb_strlen($firstName) > 50) || ($lastName !== null && mb_strlen($lastName) > 50)) {
            header('Location: index.php?page=profile&error=' . urlencode('Name fields are too long.'));
            exit;
        }
        if ($birthDate !== null) {
            try {
                if (new DateTimeImmutable($birthDate) > new DateTimeImmutable()) {
                    header('Location: index.php?page=profile&error=' . urlencode('Birthdate cannot be in the future.'));
                    exit;
                }
            } catch (Throwable) {
                header('Location: index.php?page=profile&error=' . urlencode('Birthdate is not a valid date.'));
                exit;
            }
        }

        $heightValueRaw = trim(post_string('height_value'));
        $heightValue    = $heightValueRaw !== '' ? (float) $heightValueRaw : null;
        $heightUnit     = isset($_POST['height_unit_cm']) ? 'cm' : 'in';
        if ($heightValue !== null) {
            $bounds = $heightUnit === 'cm' ? [50.0, 274.0] : [20.0, 108.0];
            if ($heightValue < $bounds[0] || $heightValue > $bounds[1]) {
                header('Location: index.php?page=profile&error=' . urlencode('Height value is out of range.'));
                exit;
            }
        } else {
            $heightUnit = null;
        }

        $avatarService  = new AvatarUploadService();
        $profilePicture = (string) ($userRow['profile_picture'] ?? '') ?: null;
        if (isset($_POST['remove_profile_picture'])) {
            $avatarService->deleteIfLocal($profilePicture);
            $profilePicture = null;
        } elseif (($_FILES['profile_picture']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $newPicture = $avatarService->saveUpload($_FILES['profile_picture']);
                $avatarService->deleteIfLocal($profilePicture);
                $profilePicture = $newPicture;
            } catch (RuntimeException $e) {
                header('Location: index.php?page=profile&error=' . urlencode($e->getMessage()));
                exit;
            }
        }

        db()->prepare('UPDATE users SET display_name = :name, first_name = :first_name, last_name = :last_name, birth_date = :birth_date, height_value = :height_value, height_unit = :height_unit, profile_picture = :profile_picture WHERE id = :id')
            ->execute([
                'name'            => $newName,
                'first_name'      => $firstName,
                'last_name'       => $lastName,
                'birth_date'      => $birthDate,
                'height_value'    => $heightValue,
                'height_unit'     => $heightUnit,
                'profile_picture' => $profilePicture,
                'id'              => $userId,
            ]);
        header('Location: index.php?page=profile&success=' . urlencode('Profile updated.'));
        exit;
    }

    if ($action === 'add_profile_allergy') {
        try {
            $allergyRepo  = new AllergyRepository(db(), $userId);
            $catalogIdRaw = post_string('allergy_catalog_id');
            $catalogId    = $catalogIdRaw !== '' ? (int) $catalogIdRaw : null;
            $newName      = trim(post_string('new_allergy_name')) ?: null;
            $allergyRepo->addAllergy(
                null,
                $catalogId,
                $newName,
                post_string('allergy_type') ?: 'allergy',
                isset($_POST['life_threatening']),
                trim(post_string('severity')) ?: null,
                trim(post_string('category')) ?: null,
                post_string('notes') ?: null
            );
            header('Location: index.php?page=profile&success=' . urlencode('Allergy added.') . '&open=allergies');
        } catch (RuntimeException $e) {
            header('Location: index.php?page=profile&error=' . urlencode($e->getMessage()) . '&open=allergies');
        }
        exit;
    }

    if ($action === 'update_profile_allergy') {
        try {
            $allergyRepo  = new AllergyRepository(db(), $userId);
            $catalogIdRaw = post_string('allergy_catalog_id');
            $catalogId    = $catalogIdRaw !== '' ? (int) $catalogIdRaw : null;
            $newName      = trim(post_string('new_allergy_name')) ?: null;
            $allergyRepo->updateAllergy(
                null,
                (int) ($_POST['allergy_id'] ?? 0),
                $catalogId,
                $newName,
                post_string('allergy_type') ?: 'allergy',
                isset($_POST['life_threatening']),
                trim(post_string('severity')) ?: null,
                trim(post_string('category')) ?: null,
                post_string('notes') ?: null,
                post_string('is_active') !== '0'
            );
            header('Location: index.php?page=profile&success=' . urlencode('Allergy updated.') . '&open=allergies');
        } catch (RuntimeException $e) {
            header('Location: index.php?page=profile&error=' . urlencode($e->getMessage()) . '&open=allergies');
        }
        exit;
    }

    if ($action === 'remove_profile_allergy') {
        $allergyRepo = new AllergyRepository(db(), $userId);
        $allergyRepo->removeAllergy(null, (int) ($_POST['allergy_id'] ?? 0));
        header('Location: index.php?page=profile&success=' . urlencode('Allergy removed.'));
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

$memberSince = isset($userRow['created_at']) && $userRow['created_at'] !== ''
    ? format_member_since((string) $userRow['created_at'])
    : '';
$ownerAge = calculate_age($userRow['birth_date'] !== null ? (string) $userRow['birth_date'] : null);

$fullName = trim(trim((string) ($userRow['first_name'] ?? '')) . ' ' . trim((string) ($userRow['last_name'] ?? '')));
if ($fullName === '') {
    $fullName = (string) ($userRow['display_name'] ?? '');
}

$allergyRepo     = new AllergyRepository(db(), $userId);
$ownerAllergies  = $allergyRepo->allergiesForProfile(null);
$ownerActiveAllergies = array_values(array_filter($ownerAllergies, static fn(array $a): bool => (int) $a['is_active'] === 1));
$allergyCatalog  = $allergyRepo->catalogForUser();

$ownerMedRepo       = new MedicationRepository(db(), $userId, null);
$ownerActiveMeds    = $ownerMedRepo->activeMedications();
$ownerInactiveMeds  = $ownerMedRepo->inactiveMedications();

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
      <a href="index.php?page=pain-tracking">Pain Tracking</a>
      <a href="index.php?page=mood-wellbeing">Mood &amp; Wellbeing</a>
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
          <?= render_avatar((string) ($navActiveProfile ? ($navActiveProfile['profile_picture'] ?? '') : ($userRow['profile_picture'] ?? '')) ?: null, $navAvatarLetter, $navAvatarColor, 'nav-user-avatar') ?>
        </button>
        <div class="nav-user-menu-panel" data-user-menu-panel hidden>
          <a href="index.php?page=profile" class="nav-user-menu-link nav-user-menu-link--top is-active">
            <i class="fa-solid fa-circle-user" aria-hidden="true"></i>
            My Profile
          </a>
          <?php if (!empty($familyProfiles)): ?>
          <form method="post" action="index.php?page=profile" class="nav-user-menu-switcher-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="switch_family_profile">
            <input type="hidden" name="redirect_to" value="<?= e($_SERVER['REQUEST_URI'] ?? 'index.php') ?>">
            <button type="submit" name="profile_id" value="0"
                    class="profile-option nav-user-menu-owner-option<?= $navActiveProfileId === null ? ' is-active' : '' ?>">
              <?= render_avatar((string) ($userRow['profile_picture'] ?? '') ?: null, mb_strtoupper(mb_substr((string) ($userRow['display_name'] ?? 'U'), 0, 1)), '#6366f1', 'profile-option-avatar') ?>
              <?= e((string) ($userRow['display_name'] ?? 'Me')) ?>
            </button>
            <a href="index.php?page=family" class="nav-user-menu-link nav-user-menu-link--manage">
              <i class="fa-solid fa-users" aria-hidden="true"></i>
              Manage Family
            </a>
            <div class="nav-user-menu-section-label">Family Members</div>
            <?php foreach ($familyProfiles as $fp): ?>
            <button type="submit" name="profile_id" value="<?= (int) $fp['id'] ?>"
                    class="profile-option<?= $navActiveProfileId === (int) $fp['id'] ? ' is-active' : '' ?>">
              <?= render_avatar((string) ($fp['profile_picture'] ?? '') ?: null, mb_strtoupper(mb_substr((string) $fp['display_name'], 0, 1)), (string) ($fp['avatar_color'] ?? '#6366f1'), 'profile-option-avatar') ?>
              <?= e((string) $fp['display_name']) ?>
              <?php if ($fp['relationship']): ?>
                <span class="profile-option-rel"><?= e((string) $fp['relationship']) ?></span>
              <?php endif; ?>
            </button>
            <?php endforeach; ?>
          </form>
          <?php else: ?>
          <a href="index.php?page=family" class="nav-user-menu-link nav-user-menu-link--manage">
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

    <?php if ($flashSuccess !== '' && !$modalReopenAllergies): ?>
      <div class="auth-success profile-flash" role="status"><?= e($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($flashError !== '' && !$modalReopenAllergies): ?>
      <div class="auth-error profile-flash" role="alert"><?= e($flashError) ?></div>
    <?php endif; ?>

    <div class="profile-grid">

      <!-- Profile Information -->
      <div class="panel">
        <div class="panel-heading">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <h2>Profile Information</h2>
          <button type="button" class="panel-heading-link" data-open-profile-edit-modal aria-label="Edit profile">
            <i class="fa-solid fa-gear" aria-hidden="true"></i>
          </button>
        </div>

        <div class="profile-info-header">
          <span class="profile-info-avatar">
            <?= render_avatar(
              (string) ($userRow['profile_picture'] ?? '') ?: null,
              mb_strtoupper(mb_substr((string) ($userRow['display_name'] ?? 'U'), 0, 1)),
              '#6366f1',
              'profile-info-avatar-inner'
            ) ?>
          </span>
          <div class="profile-info-name"><?= e($fullName) ?></div>
        </div>

        <div class="profile-info-row">
          <span class="profile-info-label">Display name</span>
          <span class="profile-info-value"><?= e((string) ($userRow['display_name'] ?? '')) ?></span>
        </div>
        <div class="profile-info-row">
          <span class="profile-info-label">Email</span>
          <span class="profile-info-value"><?= e((string) $userRow['email']) ?></span>
        </div>
        <?php if (!empty($userRow['birth_date'])): ?>
        <div class="profile-info-row">
          <span class="profile-info-label">Birthdate</span>
          <span class="profile-info-value">
            <?= e((new DateTimeImmutable((string) $userRow['birth_date']))->format('F j, Y')) ?>
            <?php if ($ownerAge !== null): ?> (<?= $ownerAge ?> years old)<?php endif; ?>
          </span>
        </div>
        <?php endif; ?>
        <?php if (!empty($userRow['height_value'])): ?>
        <div class="profile-info-row">
          <span class="profile-info-label">Height</span>
          <span class="profile-info-value">
            <?php $ownerHeightInches = height_to_inches((float) $userRow['height_value'], (string) $userRow['height_unit']); ?>
            <?= e(rtrim(rtrim(number_format((float) $userRow['height_value'], 1), '0'), '.')) ?> <?= e((string) $userRow['height_unit']) ?>
            (<?= e(format_feet_inches($ownerHeightInches)) ?>)
          </span>
        </div>
        <?php endif; ?>
        <?php if ($memberSince !== ''): ?>
        <div class="profile-info-row">
          <span class="profile-info-label">Member since</span>
          <span class="profile-info-value"><?= e($memberSince) ?></span>
        </div>
        <?php endif; ?>
      </div>

      <div class="modal-overlay" data-profile-edit-modal>
        <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="profile-edit-modal-title">
          <div class="modal-header">
            <h2 id="profile-edit-modal-title">Edit Profile</h2>
            <button type="button" class="modal-close-btn" data-close-profile-edit-modal aria-label="Close">
              <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
          </div>
          <div class="modal-scroll">
            <form method="post" action="index.php?page=profile" class="stacked-form" enctype="multipart/form-data">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="update_profile_info">
              <div class="form-group">
                <label class="form-label">Profile Picture</label>
                <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
                  <span style="display:inline-flex;width:3rem;height:3rem;flex-shrink:0">
                    <?= render_avatar(
                      (string) ($userRow['profile_picture'] ?? '') ?: null,
                      mb_strtoupper(mb_substr((string) ($userRow['display_name'] ?? 'U'), 0, 1)),
                      '#6366f1',
                      'family-profile-card__avatar'
                    ) ?>
                  </span>
                  <div style="min-width:0">
                    <input type="file" name="profile_picture" accept="image/png,image/jpeg,image/webp">
                    <?php if (!empty($userRow['profile_picture'])): ?>
                    <label style="display:inline-flex;align-items:center;gap:.4rem;width:fit-content;margin-top:.35rem;font-size:.8rem;font-weight:400">
                      <input type="checkbox" name="remove_profile_picture" value="1"> Remove current photo
                    </label>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <div class="form-group">
                <label for="first_name">First name</label>
                <input type="text" id="first_name" name="first_name" value="<?= e((string) ($userRow['first_name'] ?? '')) ?>" maxlength="50" placeholder="First name" autocomplete="given-name">
              </div>
              <div class="form-group">
                <label for="last_name">Last name</label>
                <input type="text" id="last_name" name="last_name" value="<?= e((string) ($userRow['last_name'] ?? '')) ?>" maxlength="50" placeholder="Last name" autocomplete="family-name">
              </div>
              <div class="form-group">
                <label for="display_name">Display name <span class="field-optional">(optional — defaults to first name + last initial)</span></label>
                <input
                  type="text"
                  id="display_name"
                  name="display_name"
                  value="<?= e((string) ($userRow['display_name'] ?? '')) ?>"
                  maxlength="100"
                  placeholder="Your name"
                  autocomplete="name"
                >
              </div>
              <div class="form-group">
                <label for="birth_date">Birthdate</label>
                <input type="date" id="birth_date" name="birth_date" value="<?= e((string) ($userRow['birth_date'] ?? '')) ?>" max="<?= e(today()) ?>">
              </div>
              <div class="form-group">
                <label for="height_value">Height</label>
                <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
                  <input type="number" id="height_value" name="height_value" step="0.1" min="0" style="width:8rem;max-width:8rem" value="<?= e($userRow['height_value'] !== null ? (string) (float) $userRow['height_value'] : '') ?>">
                  <label class="toggle-control" for="height_unit_toggle">
                    <input type="checkbox" id="height_unit_toggle" name="height_unit_cm"<?= (string) ($userRow['height_unit'] ?? '') === 'cm' ? ' checked' : '' ?>>
                    <span class="toggle-slider" aria-hidden="true"></span>
                    <span class="toggle-label" data-height-unit-label><?= (string) ($userRow['height_unit'] ?? '') === 'cm' ? 'cm' : 'in' ?></span>
                  </label>
                </div>
              </div>
              <button type="submit" class="secondary">Save profile</button>
            </form>
          </div>
        </div>
      </div>

      <!-- Medications and Supplements -->
      <div class="panel">
        <button type="button" class="summary-card" data-open-meds-modal>
          <span class="summary-card-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.5 20.5 3.5 13.5a5 5 0 0 1 7-7l7 7a5 5 0 0 1-7 7Z"/><path d="m8.5 8.5 7 7"/></svg>
          </span>
          <span class="summary-card-body">
            <span class="summary-card-title">Medications and Supplements</span>
            <span class="summary-card-meta"><?= count($ownerActiveMeds) ?> active med<?= count($ownerActiveMeds) === 1 ? '' : 's' ?></span>
          </span>
          <i class="fa-solid fa-chevron-right summary-card-chevron" aria-hidden="true"></i>
        </button>
      </div>

      <!-- Allergies -->
      <div class="panel">
        <button type="button" class="summary-card" data-open-allergies-modal>
          <span class="summary-card-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2a5 5 0 0 0-5 5c0 3 2 4 2 7a5 5 0 0 0 10 0c0-3 2-4 2-7a5 5 0 0 0-5-5"/></svg>
          </span>
          <span class="summary-card-body">
            <span class="summary-card-title">Allergies &amp; Intolerances</span>
            <span class="summary-card-meta"><?= count($ownerActiveAllergies) ?> allerg<?= count($ownerActiveAllergies) === 1 ? 'y' : 'ies' ?></span>
          </span>
          <i class="fa-solid fa-chevron-right summary-card-chevron" aria-hidden="true"></i>
        </button>
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

<?php
$modalProfileId        = null;
$modalAllergies        = $ownerAllergies;
$modalAllergyCatalog   = $allergyCatalog;
$modalActionUrl        = 'index.php?page=profile';
require __DIR__ . '/../includes/allergies-modal.php';

$modalActiveMeds   = $ownerActiveMeds;
$modalInactiveMeds = $ownerInactiveMeds;
require __DIR__ . '/../includes/medications-modal.php';
?>

<?php include __DIR__ . '/../includes/bottom-nav.php'; ?>
<script src="assets/js/profile-cards-modal.js?v=<?= filemtime(__DIR__ . '/../assets/js/profile-cards-modal.js') ?>" defer></script>
</body>
</html>
