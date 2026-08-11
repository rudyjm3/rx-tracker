<?php

declare(strict_types=1);

/** @var AuthService $auth */

$userId     = $auth->currentUserId();
$familyRepo = new FamilyProfileRepository(db());

$profileId = (int) ($_GET['id'] ?? 0);
$fp        = $profileId > 0 ? $familyRepo->findProfile($profileId, $userId) : null;
if ($fp === null) {
    header('Location: index.php?page=family');
    exit;
}

$stmt = db()->prepare('SELECT id, email, display_name, profile_picture FROM users WHERE id = :id LIMIT 1');
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
        header('Location: index.php?page=family-member&id=' . $profileId . '&error=' . urlencode('Session expired. Please try again.'));
        exit;
    }

    $action = post_string('action');

    if ($action === 'add_profile_allergy') {
        try {
            $allergyRepo  = new AllergyRepository(db(), $userId);
            $catalogIdRaw = post_string('allergy_catalog_id');
            $catalogId    = $catalogIdRaw !== '' ? (int) $catalogIdRaw : null;
            $newName      = trim(post_string('new_allergy_name')) ?: null;
            $allergyRepo->addAllergy(
                $profileId,
                $catalogId,
                $newName,
                post_string('allergy_type') ?: 'allergy',
                isset($_POST['life_threatening']),
                trim(post_string('severity')) ?: null,
                trim(post_string('category')) ?: null,
                post_string('notes') ?: null
            );
            header('Location: index.php?page=family-member&id=' . $profileId . '&success=' . urlencode('Allergy added.') . '&open=allergies');
        } catch (RuntimeException $e) {
            header('Location: index.php?page=family-member&id=' . $profileId . '&error=' . urlencode($e->getMessage()) . '&open=allergies');
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
                $profileId,
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
            header('Location: index.php?page=family-member&id=' . $profileId . '&success=' . urlencode('Allergy updated.') . '&open=allergies');
        } catch (RuntimeException $e) {
            header('Location: index.php?page=family-member&id=' . $profileId . '&error=' . urlencode($e->getMessage()) . '&open=allergies');
        }
        exit;
    }

    if ($action === 'remove_profile_allergy') {
        $allergyRepo = new AllergyRepository(db(), $userId);
        $allergyRepo->removeAllergy($profileId, (int) ($_POST['allergy_id'] ?? 0));
        header('Location: index.php?page=family-member&id=' . $profileId . '&success=' . urlencode('Allergy removed.'));
        exit;
    }

    header('Location: index.php?page=family-member&id=' . $profileId);
    exit;
}

$familyProfiles = $familyRepo->profilesForUser($userId);

$memberSince = isset($fp['created_at']) && $fp['created_at'] !== ''
    ? format_member_since((string) $fp['created_at'])
    : '';
$memberAge = calculate_age(
    $fp['birth_date'] !== null ? (string) $fp['birth_date'] : null,
    $fp['birth_year'] !== null ? (int) $fp['birth_year'] : null
);

$allergyRepo    = new AllergyRepository(db(), $userId);
$memberAllergies = $allergyRepo->allergiesForProfile($profileId);
$memberActiveAllergies = array_values(array_filter($memberAllergies, static fn(array $a): bool => (int) $a['is_active'] === 1));
$allergyCatalog  = $allergyRepo->catalogForUser();

$memberMedRepo      = new MedicationRepository(db(), $userId, $profileId);
$memberActiveMeds   = $memberMedRepo->activeMedications();
$memberInactiveMeds = $memberMedRepo->inactiveMedications();

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
  <title><?= e((string) $fp['display_name']) ?> — RxTracker</title>
  <link rel="stylesheet" href="assets/css/styles.css?v=<?= filemtime(__DIR__ . '/../assets/css/styles.css') ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous">
  <link rel="icon" type="image/x-icon" href="assets/icons/favicon.ico">
  <link rel="icon" type="image/png" sizes="192x192" href="assets/icons/icon-192.png">
  <link rel="apple-touch-icon" href="assets/icons/icon-192.png">
  <link rel="manifest" href="manifest.json">
  <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>" defer></script>
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
          <?= render_avatar((string) ($navActiveProfile['profile_picture'] ?? $userRow['profile_picture'] ?? '') ?: null, $navAvatarLetter, $navAvatarColor, 'nav-user-avatar') ?>
        </button>
        <div class="nav-user-menu-panel" data-user-menu-panel hidden>
          <a href="index.php?page=profile" class="nav-user-menu-link nav-user-menu-link--top">
            <i class="fa-solid fa-circle-user" aria-hidden="true"></i>
            My Profile
          </a>
          <a href="index.php?page=family" class="nav-user-menu-link nav-user-menu-link--manage">
            <i class="fa-solid fa-users" aria-hidden="true"></i>
            Manage Family
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
            <div class="nav-user-menu-section-label">Family Members</div>
            <?php foreach ($familyProfiles as $navFp): ?>
            <button type="submit" name="profile_id" value="<?= (int) $navFp['id'] ?>"
                    class="profile-option<?= $navActiveProfileId === (int) $navFp['id'] ? ' is-active' : '' ?>">
              <?= render_avatar((string) ($navFp['profile_picture'] ?? '') ?: null, mb_strtoupper(mb_substr((string) $navFp['display_name'], 0, 1)), (string) ($navFp['avatar_color'] ?? '#6366f1'), 'profile-option-avatar') ?>
              <?= e((string) $navFp['display_name']) ?>
              <?php if ($navFp['relationship']): ?>
                <span class="profile-option-rel"><?= e((string) $navFp['relationship']) ?></span>
              <?php endif; ?>
            </button>
            <?php endforeach; ?>
          </form>
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
      <h1><?= e((string) $fp['display_name']) ?></h1>
      <a href="index.php?page=family" class="secondary btn-inline">
        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
        Back to Manage Family
      </a>
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
          <a href="index.php?page=family" class="btn-text" style="margin-left:auto">Edit</a>
        </div>

        <?php if (!empty($fp['first_name']) || !empty($fp['last_name'])): ?>
        <div class="profile-info-row">
          <span class="profile-info-label">Name</span>
          <span class="profile-info-value"><?= e(trim((string) ($fp['first_name'] ?? '') . ' ' . (string) ($fp['last_name'] ?? ''))) ?></span>
        </div>
        <?php endif; ?>
        <div class="profile-info-row">
          <span class="profile-info-label">Display name</span>
          <span class="profile-info-value"><?= e((string) $fp['display_name']) ?></span>
        </div>
        <?php if (!empty($fp['relationship'])): ?>
        <div class="profile-info-row">
          <span class="profile-info-label">Relationship</span>
          <span class="profile-info-value"><?= e((string) $fp['relationship']) ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($fp['birth_date'])): ?>
        <div class="profile-info-row">
          <span class="profile-info-label">Birthdate</span>
          <span class="profile-info-value">
            <?= e((new DateTimeImmutable((string) $fp['birth_date']))->format('F j, Y')) ?>
            <?php if ($memberAge !== null): ?> (<?= $memberAge ?> years old)<?php endif; ?>
          </span>
        </div>
        <?php elseif ($memberAge !== null): ?>
        <div class="profile-info-row">
          <span class="profile-info-label">Age</span>
          <span class="profile-info-value">~<?= $memberAge ?> years old (from birth year)</span>
        </div>
        <?php endif; ?>
        <?php if (!empty($fp['height_value'])): ?>
        <div class="profile-info-row">
          <span class="profile-info-label">Height</span>
          <span class="profile-info-value">
            <?php $memberHeightInches = height_to_inches((float) $fp['height_value'], (string) $fp['height_unit']); ?>
            <?= e(rtrim(rtrim(number_format((float) $fp['height_value'], 1), '0'), '.')) ?> <?= e((string) $fp['height_unit']) ?>
            (<?= e(format_feet_inches($memberHeightInches)) ?>)
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

      <!-- Medications and Supplements -->
      <div class="panel">
        <button type="button" class="summary-card" data-open-meds-modal>
          <span class="summary-card-icon">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.5 20.5 3.5 13.5a5 5 0 0 1 7-7l7 7a5 5 0 0 1-7 7Z"/><path d="m8.5 8.5 7 7"/></svg>
          </span>
          <span class="summary-card-body">
            <span class="summary-card-title">Medications and Supplements</span>
            <span class="summary-card-meta"><?= count($memberActiveMeds) ?> active med<?= count($memberActiveMeds) === 1 ? '' : 's' ?></span>
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
            <span class="summary-card-meta"><?= count($memberActiveAllergies) ?> allerg<?= count($memberActiveAllergies) === 1 ? 'y' : 'ies' ?></span>
          </span>
          <i class="fa-solid fa-chevron-right summary-card-chevron" aria-hidden="true"></i>
        </button>
      </div>

    </div><!-- /.profile-grid -->

  </section>

</main>

<?php
$modalProfileId        = $profileId;
$modalAllergies        = $memberAllergies;
$modalAllergyCatalog   = $allergyCatalog;
$modalActionUrl        = 'index.php?page=family-member&id=' . $profileId;
require __DIR__ . '/../includes/allergies-modal.php';

$modalActiveMeds   = $memberActiveMeds;
$modalInactiveMeds = $memberInactiveMeds;
require __DIR__ . '/../includes/medications-modal.php';
?>

<?php include __DIR__ . '/../includes/bottom-nav.php'; ?>
<script src="assets/js/profile-cards-modal.js?v=<?= filemtime(__DIR__ . '/../assets/js/profile-cards-modal.js') ?>" defer></script>
</body>
</html>
