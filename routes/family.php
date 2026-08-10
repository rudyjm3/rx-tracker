<?php

declare(strict_types=1);

/** @var AuthService $auth */

$userId     = $auth->currentUserId();
$familyRepo = new FamilyProfileRepository(db());

$stmt = db()->prepare('SELECT id, email, display_name, created_at FROM users WHERE id = :id LIMIT 1');
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
        header('Location: index.php?page=family&error=' . urlencode('Session expired. Please try again.'));
        exit;
    }

    $action = post_string('action');

    if ($action === 'create_family_profile') {
        try {
            $displayName  = trim(post_string('display_name'));
            $firstName    = trim(post_string('first_name')) ?: null;
            $lastName     = trim(post_string('last_name')) ?: null;
            $avatarColor  = trim(post_string('avatar_color')) ?: null;
            $relationship = trim(post_string('relationship')) ?: null;
            $birthDate    = trim(post_string('birth_date')) ?: null;
            $familyRepo->createProfile($userId, $displayName, $avatarColor, $relationship, null, $firstName, $lastName, $birthDate);
            header('Location: index.php?page=family&success=' . urlencode($displayName . ' was added.'));
        } catch (RuntimeException $e) {
            header('Location: index.php?page=family&error=' . urlencode($e->getMessage()));
        }
        exit;
    }

    if ($action === 'update_family_profile') {
        try {
            $profileId    = (int) ($_POST['profile_id'] ?? 0);
            $displayName  = trim(post_string('display_name'));
            $firstName    = trim(post_string('first_name')) ?: null;
            $lastName     = trim(post_string('last_name')) ?: null;
            $avatarColor  = trim(post_string('avatar_color')) ?: null;
            $relationship = trim(post_string('relationship')) ?: null;
            $birthDate    = trim(post_string('birth_date')) ?: null;
            $existing     = $familyRepo->findProfile($profileId, $userId);
            $birthYear    = $existing['birth_year'] ?? null;
            $familyRepo->updateProfile($profileId, $userId, $displayName, $avatarColor, $relationship, $birthYear !== null ? (int) $birthYear : null, $firstName, $lastName, $birthDate);
            header('Location: index.php?page=family&success=' . urlencode($displayName . '\'s profile was updated.'));
        } catch (RuntimeException $e) {
            header('Location: index.php?page=family&error=' . urlencode($e->getMessage()));
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
        header('Location: index.php?page=family&success=' . urlencode('Family member removed.'));
        exit;
    }

    header('Location: index.php?page=family');
    exit;
}

$familyProfiles = $familyRepo->profilesForUser($userId);
$relationships  = FamilyProfileRepository::allowedRelationships();
$palette        = ['#6366f1', '#ec4899', '#10b981', '#f59e0b', '#3b82f6', '#ef4444'];

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
  <title>Manage Family — RxTracker</title>
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
          <a href="index.php?page=family" class="nav-user-menu-link nav-user-menu-link--manage is-active">
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
      <h1>Manage Family</h1>
      <button type="button" class="secondary" data-open-family-add-modal>
        <i class="fa-solid fa-user-plus" aria-hidden="true"></i>
        Add Family Member
      </button>
    </div>

    <?php if ($flashSuccess !== ''): ?>
      <div class="auth-success profile-flash" role="status"><?= e($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($flashError !== ''): ?>
      <div class="auth-error profile-flash" role="alert"><?= e($flashError) ?></div>
    <?php endif; ?>

    <div class="panel">
      <div class="panel-heading">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        <h2>Family Members</h2>
      </div>
      <p class="muted" style="margin-bottom:1rem">Track medications for family members under one account — no separate logins needed.</p>

      <?php if ($familyProfiles !== []): ?>
      <ul class="sessions-list">
        <?php foreach ($familyProfiles as $fp): ?>
        <li class="session-row">
          <a href="index.php?page=family-member&id=<?= (int)$fp['id'] ?>" class="session-info" style="text-decoration:none;color:inherit">
            <span class="session-agent">
              <span style="display:inline-flex;align-items:center;justify-content:center;width:1.6rem;height:1.6rem;border-radius:50%;background:<?= e((string)($fp['avatar_color'] ?? '#6366f1')) ?>;color:#fff;font-size:.75rem;font-weight:700;margin-right:.5rem">
                <?= e(mb_strtoupper(mb_substr((string)$fp['display_name'], 0, 1))) ?>
              </span>
              <?= e((string)$fp['display_name']) ?>
            </span>
            <?php if ($fp['relationship'] || $fp['birth_year'] || $fp['birth_date']): ?>
            <span class="session-meta">
              <?php if ($fp['relationship']): ?><?= e((string)$fp['relationship']) ?><?php endif; ?>
              <?php if ($fp['relationship'] && ($fp['birth_year'] || $fp['birth_date'])): ?> · <?php endif; ?>
              <?php $age = calculate_age($fp['birth_date'] !== null ? (string)$fp['birth_date'] : null, $fp['birth_year'] !== null ? (int)$fp['birth_year'] : null); ?>
              <?php if ($age !== null): ?><?= $age ?> yrs<?php endif; ?>
            </span>
            <?php endif; ?>
          </a>
          <div style="display:flex;gap:.5rem;flex-shrink:0">
            <button type="button" class="secondary" style="font-size:.8rem;padding:.25rem .6rem" data-open-family-edit-modal="<?= (int)$fp['id'] ?>">Edit</button>
            <form method="post" action="index.php?page=family"
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
      <?php else: ?>
      <p class="muted">No family members yet. Click "Add Family Member" to add one.</p>
      <?php endif; ?>
    </div>

  </section>

</main>

<!-- Add Family Member modal -->
<div class="modal-overlay" data-family-add-modal>
  <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="family-add-title">
    <div class="modal-header">
      <h2 id="family-add-title">Add Family Member</h2>
      <button type="button" class="modal-close-btn" data-close-family-add-modal aria-label="Close">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
    </div>
    <div class="modal-scroll">
      <form method="post" action="index.php?page=family" class="stacked-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_family_profile">
        <div class="form-group">
          <label for="family_first_name">First Name</label>
          <input type="text" id="family_first_name" name="first_name" maxlength="50" placeholder="e.g. Sarah">
        </div>
        <div class="form-group">
          <label for="family_last_name">Last Name</label>
          <input type="text" id="family_last_name" name="last_name" maxlength="50" placeholder="e.g. Johnson">
        </div>
        <div class="form-group">
          <label for="family_display_name">Display Name <span style="color:var(--danger)">*</span></label>
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
          <label for="family_birth_date">Birth Date</label>
          <input type="date" id="family_birth_date" name="birth_date" max="<?= e(today()) ?>">
        </div>
        <div class="form-group">
          <label class="form-label"><i class="fa-solid fa-palette" aria-hidden="true" style="margin-right:.35rem;color:var(--rx-deep-blue)"></i>Avatar Color</label>
          <div class="avatar-color-picker">
            <?php foreach ($palette as $i => $color): ?>
            <label class="avatar-color-swatch">
              <input type="radio" name="avatar_color" value="<?= e($color) ?>"
                     <?= $i === 0 ? 'checked' : '' ?>>
              <span class="avatar-color-dot" style="background:<?= e($color) ?>"></span>
            </label>
            <?php endforeach; ?>
            <label class="avatar-color-swatch avatar-color-swatch--custom">
              <input type="radio" name="avatar_color" value="custom" id="family_color_custom_radio">
              <input type="color" id="family_color_custom" value="#6366f1" class="avatar-color-custom-input">
              <span class="avatar-color-custom-label">Custom color picker</span>
            </label>
          </div>
          <input type="hidden" name="avatar_color_final" id="family_avatar_color_final" value="#6366f1">
        </div>
        <div class="modal-footer">
          <button type="submit" class="secondary">Add Family Member</button>
          <button type="button" class="secondary" data-close-family-add-modal>Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php foreach ($familyProfiles as $fp): ?>
<div class="modal-overlay" data-family-edit-modal="<?= (int)$fp['id'] ?>">
  <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="family-edit-title-<?= (int)$fp['id'] ?>">
    <div class="modal-header">
      <h2 id="family-edit-title-<?= (int)$fp['id'] ?>">Edit <?= e((string)$fp['display_name']) ?></h2>
      <button type="button" class="modal-close-btn" data-close-family-edit-modal aria-label="Close">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
    </div>
    <div class="modal-scroll">
      <form method="post" action="index.php?page=family" class="stacked-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_family_profile">
        <input type="hidden" name="profile_id" value="<?= (int)$fp['id'] ?>">
        <div class="form-group">
          <label for="edit_first_name_<?= (int)$fp['id'] ?>">First Name</label>
          <input type="text" id="edit_first_name_<?= (int)$fp['id'] ?>" name="first_name" maxlength="50"
                 value="<?= e((string)($fp['first_name'] ?? '')) ?>">
        </div>
        <div class="form-group">
          <label for="edit_last_name_<?= (int)$fp['id'] ?>">Last Name</label>
          <input type="text" id="edit_last_name_<?= (int)$fp['id'] ?>" name="last_name" maxlength="50"
                 value="<?= e((string)($fp['last_name'] ?? '')) ?>">
        </div>
        <div class="form-group">
          <label for="edit_display_name_<?= (int)$fp['id'] ?>">Display Name <span style="color:var(--danger)">*</span></label>
          <input type="text" id="edit_display_name_<?= (int)$fp['id'] ?>" name="display_name" required maxlength="100"
                 value="<?= e((string)$fp['display_name']) ?>">
        </div>
        <div class="form-group">
          <label for="edit_relationship_<?= (int)$fp['id'] ?>">Relationship</label>
          <select id="edit_relationship_<?= (int)$fp['id'] ?>" name="relationship">
            <option value="">— Optional —</option>
            <?php foreach ($relationships as $rel): ?>
            <option value="<?= e($rel) ?>"<?= $fp['relationship'] === $rel ? ' selected' : '' ?>><?= e($rel) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="edit_birth_date_<?= (int)$fp['id'] ?>">Birth Date</label>
          <input type="date" id="edit_birth_date_<?= (int)$fp['id'] ?>" name="birth_date" max="<?= e(today()) ?>"
                 value="<?= e((string)($fp['birth_date'] ?? '')) ?>">
        </div>
        <div class="form-group">
          <label class="form-label"><i class="fa-solid fa-palette" aria-hidden="true" style="margin-right:.35rem;color:var(--rx-deep-blue)"></i>Avatar Color</label>
          <div class="avatar-color-picker">
            <?php $currentColor = (string)($fp['avatar_color'] ?? '#6366f1'); ?>
            <?php foreach ($palette as $color): ?>
            <label class="avatar-color-swatch">
              <input type="radio" name="avatar_color_edit_<?= (int)$fp['id'] ?>" value="<?= e($color) ?>"
                     <?= $currentColor === $color ? 'checked' : '' ?>>
              <span class="avatar-color-dot" style="background:<?= e($color) ?>"></span>
            </label>
            <?php endforeach; ?>
            <label class="avatar-color-swatch avatar-color-swatch--custom">
              <input type="radio" name="avatar_color_edit_<?= (int)$fp['id'] ?>" value="custom" id="edit_color_custom_radio_<?= (int)$fp['id'] ?>"
                     <?= !in_array($currentColor, $palette, true) ? 'checked' : '' ?>>
              <input type="color" id="edit_color_custom_<?= (int)$fp['id'] ?>" value="<?= e($currentColor) ?>" class="avatar-color-custom-input">
              <span class="avatar-color-custom-label">Custom color picker</span>
            </label>
          </div>
          <input type="hidden" name="avatar_color_final" id="edit_avatar_color_final_<?= (int)$fp['id'] ?>" value="<?= e($currentColor) ?>">
        </div>
        <div class="modal-footer">
          <button type="submit" class="secondary">Save Changes</button>
          <button type="button" class="secondary" data-close-family-edit-modal>Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endforeach; ?>

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
  function setupColorPicker(radioName, customInputId, finalInputId) {
    var customRadio = document.querySelector('input[name="' + radioName + '"][value="custom"]');
    var customInput = document.getElementById(customInputId);
    var finalInput  = document.getElementById(finalInputId);
    if (!customRadio || !customInput || !finalInput) return;
    document.querySelectorAll('input[name="' + radioName + '"]').forEach(function (radio) {
      radio.addEventListener('change', function () {
        finalInput.value = radio.value === 'custom' ? customInput.value : radio.value;
      });
    });
    customInput.addEventListener('input', function () {
      customRadio.checked = true;
      finalInput.value = customInput.value;
    });
    var form = customInput.closest('form');
    if (form) {
      form.addEventListener('submit', function () {
        document.querySelectorAll('input[name="' + radioName + '"]').forEach(function (r) { r.disabled = true; });
        finalInput.name = 'avatar_color';
        finalInput.disabled = false;
      });
    }
  }

  setupColorPicker('avatar_color', 'family_color_custom', 'family_avatar_color_final');
  <?php foreach ($familyProfiles as $fp): ?>
  setupColorPicker('avatar_color_edit_<?= (int) $fp['id'] ?>', 'edit_color_custom_<?= (int) $fp['id'] ?>', 'edit_avatar_color_final_<?= (int) $fp['id'] ?>');
  <?php endforeach; ?>

  document.querySelectorAll('[data-open-family-edit-modal]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var modal = document.querySelector('[data-family-edit-modal="' + btn.getAttribute('data-open-family-edit-modal') + '"]');
      if (modal) modal.classList.add('is-open');
    });
  });
  document.querySelectorAll('[data-close-family-edit-modal]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      btn.closest('.modal-overlay').classList.remove('is-open');
    });
  });
  document.querySelectorAll('[data-family-edit-modal]').forEach(function (overlay) {
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) overlay.classList.remove('is-open');
    });
  });

  document.querySelectorAll('[data-open-family-add-modal]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var modal = document.querySelector('[data-family-add-modal]');
      if (modal) modal.classList.add('is-open');
    });
  });
  document.querySelectorAll('[data-close-family-add-modal]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      btn.closest('.modal-overlay').classList.remove('is-open');
    });
  });
  var addModal = document.querySelector('[data-family-add-modal]');
  if (addModal) {
    addModal.addEventListener('click', function (e) {
      if (e.target === addModal) addModal.classList.remove('is-open');
    });
  }
})();
</script>
</body>
</html>
