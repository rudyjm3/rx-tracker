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

$stmt = db()->prepare('SELECT id, email, display_name FROM users WHERE id = :id LIMIT 1');
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
            $allergyRepo->addAllergy($profileId, $catalogId, $newName);
            header('Location: index.php?page=family-member&id=' . $profileId . '&success=' . urlencode('Allergy added.'));
        } catch (RuntimeException $e) {
            header('Location: index.php?page=family-member&id=' . $profileId . '&error=' . urlencode($e->getMessage()));
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
          <span class="nav-user-avatar" style="background:<?= e($navAvatarColor) ?>"><?= e($navAvatarLetter) ?></span>
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
              <span class="profile-option-avatar" style="background:#6366f1">
                <?= e(mb_strtoupper(mb_substr((string) ($userRow['display_name'] ?? 'U'), 0, 1))) ?>
              </span>
              <?= e((string) ($userRow['display_name'] ?? 'Me')) ?>
            </button>
            <div class="nav-user-menu-section-label">Family Members</div>
            <?php foreach ($familyProfiles as $navFp): ?>
            <button type="submit" name="profile_id" value="<?= (int) $navFp['id'] ?>"
                    class="profile-option<?= $navActiveProfileId === (int) $navFp['id'] ? ' is-active' : '' ?>">
              <span class="profile-option-avatar" style="background:<?= e((string) ($navFp['avatar_color'] ?? '#6366f1')) ?>">
                <?= e(mb_strtoupper(mb_substr((string) $navFp['display_name'], 0, 1))) ?>
              </span>
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
        <?php if ($memberSince !== ''): ?>
        <div class="profile-info-row">
          <span class="profile-info-label">Member since</span>
          <span class="profile-info-value"><?= e($memberSince) ?></span>
        </div>
        <?php endif; ?>
      </div>

      <!-- Medications and Supplements -->
      <div class="panel">
        <div class="panel-heading">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.5 20.5 3.5 13.5a5 5 0 0 1 7-7l7 7a5 5 0 0 1-7 7Z"/><path d="m8.5 8.5 7 7"/></svg>
          <h2>Medications and Supplements</h2>
        </div>
        <?php if ($memberActiveMeds === [] && $memberInactiveMeds === []): ?>
          <p class="muted">No medications yet. <a href="index.php?page=medications">Add one</a> to see it here.</p>
        <?php else: ?>
          <?php if ($memberActiveMeds !== []): ?>
          <h3 style="margin:0 0 .5rem;font-size:.85rem;color:var(--rx-text-muted)">Active</h3>
          <div class="medication-list" style="margin-bottom:1rem">
            <?php foreach ($memberActiveMeds as $med): ?>
              <?= render_simple_medication_line($med) ?>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <?php if ($memberInactiveMeds !== []): ?>
          <h3 style="margin:0 0 .5rem;font-size:.85rem;color:var(--rx-text-muted)">No longer active</h3>
          <div class="medication-list">
            <?php foreach ($memberInactiveMeds as $med): ?>
              <?= render_simple_medication_line($med, true) ?>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <!-- Allergies -->
      <div class="panel">
        <div class="panel-heading">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2a5 5 0 0 0-5 5c0 3 2 4 2 7a5 5 0 0 0 10 0c0-3 2-4 2-7a5 5 0 0 0-5-5"/></svg>
          <h2>Allergies</h2>
          <button type="button" class="btn-text" style="margin-left:auto" data-open-allergy-add-modal>
            <i class="fa-solid fa-plus" aria-hidden="true"></i> Add Allergy
          </button>
        </div>
        <?php if ($memberAllergies === []): ?>
          <p class="muted">No known allergies on file.</p>
        <?php else: ?>
          <ul class="sessions-list">
            <?php foreach ($memberAllergies as $allergy): ?>
            <li class="session-row">
              <div class="session-info">
                <span class="session-agent"><?= e((string) $allergy['name']) ?></span>
              </div>
              <form method="post" action="index.php?page=family-member&id=<?= $profileId ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="remove_profile_allergy">
                <input type="hidden" name="allergy_id" value="<?= (int) $allergy['id'] ?>">
                <button type="submit" class="btn-danger" style="font-size:.8rem;padding:.25rem .6rem">Remove</button>
              </form>
            </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

    </div><!-- /.profile-grid -->

  </section>

</main>

<!-- Add Allergy modal -->
<div class="modal-overlay" data-allergy-add-modal>
  <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="allergy-add-title">
    <div class="modal-header">
      <h2 id="allergy-add-title">Add Allergy for <?= e((string) $fp['display_name']) ?></h2>
      <button type="button" class="modal-close-btn" data-close-allergy-add-modal aria-label="Close">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
    </div>
    <div class="modal-scroll">
      <form method="post" action="index.php?page=family-member&id=<?= $profileId ?>" class="stacked-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_profile_allergy">
        <div class="form-group">
          <label for="allergy_select">Allergy</label>
          <select id="allergy_select" name="allergy_catalog_id" data-allergy-select>
            <option value="">— Select —</option>
            <?php foreach ($allergyCatalog as $item): ?>
            <option value="<?= (int) $item['id'] ?>"><?= e((string) $item['name']) ?></option>
            <?php endforeach; ?>
            <option value="new">+ Add a new allergy…</option>
          </select>
        </div>
        <div class="form-group" data-allergy-new-wrap style="display:none">
          <label for="new_allergy_name">New allergy name</label>
          <input type="text" id="new_allergy_name" name="new_allergy_name" maxlength="150" placeholder="e.g. Amoxicillin">
        </div>
        <div class="modal-footer">
          <button type="submit" class="secondary">Add Allergy</button>
          <button type="button" class="secondary" data-close-allergy-add-modal>Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

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
  document.querySelectorAll('[data-open-allergy-add-modal]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var modal = document.querySelector('[data-allergy-add-modal]');
      if (modal) modal.classList.add('is-open');
    });
  });
  document.querySelectorAll('[data-close-allergy-add-modal]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      btn.closest('.modal-overlay').classList.remove('is-open');
    });
  });
  var allergyModal = document.querySelector('[data-allergy-add-modal]');
  if (allergyModal) {
    allergyModal.addEventListener('click', function (e) {
      if (e.target === allergyModal) allergyModal.classList.remove('is-open');
    });
  }
  var allergySelect = document.querySelector('[data-allergy-select]');
  var allergyNewWrap = document.querySelector('[data-allergy-new-wrap]');
  if (allergySelect && allergyNewWrap) {
    allergySelect.addEventListener('change', function () {
      allergyNewWrap.style.display = allergySelect.value === 'new' ? '' : 'none';
    });
  }
})();
</script>
</body>
</html>
