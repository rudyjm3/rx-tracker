<?php

declare(strict_types=1);

/** @var AuthService $auth */

$userId     = $auth->currentUserId();
$familyRepo = new FamilyProfileRepository(db());

$flashSuccess = trim((string) ($_GET['success'] ?? ''));
$flashError   = trim((string) ($_GET['error'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token(post_string('csrf_token'))) {
        header('Location: index.php?page=family&error=' . urlencode('Session expired. Please try again.'));
        exit;
    }

    $action = post_string('action');

    if ($action === 'switch_family_profile') {
        $profileId   = (int) ($_POST['profile_id'] ?? 0);
        $redirectTo  = trim((string) ($_POST['redirect_to'] ?? 'index.php'));
        // Sanitize redirect target to prevent open redirect.
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
            $avatarColor  = trim(post_string('avatar_color')) ?: null;
            $relationship = trim(post_string('relationship')) ?: null;
            $birthYearRaw = trim(post_string('birth_year'));
            $birthYear    = $birthYearRaw !== '' ? (int) $birthYearRaw : null;

            $familyRepo->updateProfile($profileId, $userId, $displayName, $avatarColor, $relationship, $birthYear);
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
            // If the deleted profile was active, switch back to primary.
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

// GET: render Manage Family page.
$profiles = $familyRepo->profilesForUser($userId);

$navRepo          = new MedicationRepository(db(), $userId);
$navNotifications = $navRepo->getNotificationsForUser();
$navUnreadCount   = count(array_filter($navNotifications, static fn(array $n): bool => !(bool) $n['is_read']));
$familyProfiles   = $profiles;
$activeProfileId  = $auth->activeProfileId();
$activeProfile    = $activeProfileId !== null ? $familyRepo->findProfile($activeProfileId, $userId) : null;
$currentUser      = $auth->currentUser();
$relationships    = FamilyProfileRepository::allowedRelationships();

require __DIR__ . '/../includes/nav-bell.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Family — RxTracker</title>
    <link rel="stylesheet" href="assets/css/rxtracker-brand-tokens.css?v=<?= filemtime(__DIR__ . '/../assets/css/rxtracker-brand-tokens.css') ?>">
    <link rel="stylesheet" href="assets/css/styles.css?v=<?= filemtime(__DIR__ . '/../assets/css/styles.css') ?>">
    <link rel="manifest" href="manifest.json">
    <link rel="apple-touch-icon" href="assets/icons/icon-192.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
          integrity="sha512-Avb2QiuDEEvB4bZJYdft2mNjVShBftLdPG8FJ0V7irTLQ8Uo0qcPxh4Plh7eecyn6CafxmKowwEPKgF4KU14g=="
          crossorigin="anonymous" referrerpolicy="no-referrer">
</head>
<body>

<nav class="nav">
    <div class="nav-brand">
        <a href="index.php" class="nav-logo-link">
            <img src="assets/images/rx-icon.svg" alt="" class="nav-logo-img" aria-hidden="true">
            <span class="nav-logo-text">RxTracker</span>
        </a>
    </div>
    <div class="nav-actions">
        <?php if ($familyProfiles !== []): ?>
        <div class="profile-switcher" data-profile-switcher>
            <button type="button" class="profile-chip" aria-haspopup="true" aria-expanded="false" data-profile-chip>
                <span class="profile-chip-avatar" style="background:<?= e((string) ($activeProfile['avatar_color'] ?? '#6366f1')) ?>">
                    <?= e(mb_strtoupper(mb_substr((string) ($activeProfile['display_name'] ?? ($currentUser['display_name'] ?? 'M')), 0, 1))) ?>
                </span>
                <span class="profile-chip-name"><?= e((string) ($activeProfile['display_name'] ?? 'Me')) ?></span>
                <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
            </button>
            <div class="profile-switcher-dropdown" data-profile-dropdown hidden>
                <form method="post" action="index.php?page=family">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="switch_family_profile">
                    <input type="hidden" name="redirect_to" value="index.php?page=family">
                    <button type="submit" name="profile_id" value="0"
                            class="profile-option<?= $activeProfileId === null ? ' is-active' : '' ?>">
                        <span class="profile-option-avatar" style="background:#6366f1">
                            <?= e(mb_strtoupper(mb_substr((string) ($currentUser['display_name'] ?? 'M'), 0, 1))) ?>
                        </span>
                        <?= e((string) ($currentUser['display_name'] ?? 'Me')) ?>
                    </button>
                    <?php foreach ($familyProfiles as $fp): ?>
                    <button type="submit" name="profile_id" value="<?= (int) $fp['id'] ?>"
                            class="profile-option<?= $activeProfileId === (int) $fp['id'] ? ' is-active' : '' ?>">
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
            </div>
        </div>
        <?php endif; ?>
        <?php include __DIR__ . '/../includes/nav-bell.php'; ?>
        <a href="index.php?page=profile" class="nav-user-link" title="Profile">
            <i class="fa-solid fa-user-circle"></i>
            <span class="nav-username"><?= e((string) ($currentUser['display_name'] ?? $currentUser['email'] ?? '')) ?></span>
        </a>
        <button class="nav-toggle" aria-label="Toggle navigation" aria-expanded="false" data-nav-toggle>
            <i class="fa-solid fa-bars"></i>
        </button>
    </div>
    <div class="nav-links" data-nav-links>
        <a href="index.php" class="nav-link">Dashboard</a>
        <a href="index.php?page=medications" class="nav-link">Medications</a>
        <a href="index.php?page=calendar" class="nav-link">Calendar</a>
        <a href="index.php?page=export" class="nav-link">Export</a>
        <a href="index.php?page=settings" class="nav-link">Settings</a>
        <a href="index.php?page=profile" class="nav-link">Profile</a>
        <a href="index.php?page=family" class="nav-link nav-link--active">Family</a>
        <a href="index.php?page=logout" class="nav-link">Log out</a>
    </div>
</nav>

<?php if ($activeProfile !== null): ?>
<div class="profile-context-banner" role="status">
    <span class="profile-context-avatar" style="background:<?= e((string) ($activeProfile['avatar_color'] ?? '#6366f1')) ?>">
        <?= e(mb_strtoupper(mb_substr((string) $activeProfile['display_name'], 0, 1))) ?>
    </span>
    Viewing <strong><?= e((string) $activeProfile['display_name']) ?></strong>'s medications
    <form method="post" action="index.php?page=family" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="switch_family_profile">
        <input type="hidden" name="profile_id" value="0">
        <input type="hidden" name="redirect_to" value="index.php?page=family">
        <button type="submit" class="profile-context-switch-btn">Switch back to Me</button>
    </form>
</div>
<?php endif; ?>

<main class="container">

    <?php if ($flashSuccess !== ''): ?>
    <div class="alert alert--success"><?= e($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($flashError !== ''): ?>
    <div class="alert alert--error"><?= e($flashError) ?></div>
    <?php endif; ?>

    <h1 class="page-title">Family Members</h1>
    <p class="page-subtitle">Track medications for your family under one account — no separate logins needed.</p>

    <?php if ($profiles !== []): ?>
    <section class="profile-panel">
        <h2 class="panel-title">Your Family</h2>
        <div class="family-profiles-grid">
            <?php foreach ($profiles as $fp): ?>
            <div class="family-profile-card">
                <div class="family-profile-card__avatar" style="background:<?= e((string) ($fp['avatar_color'] ?? '#6366f1')) ?>">
                    <?= e(mb_strtoupper(mb_substr((string) $fp['display_name'], 0, 1))) ?>
                </div>
                <div class="family-profile-card__info">
                    <div class="family-profile-card__name"><?= e((string) $fp['display_name']) ?></div>
                    <?php if ($fp['relationship'] || $fp['birth_year']): ?>
                    <div class="family-profile-card__meta">
                        <?php if ($fp['relationship']): ?><?= e((string) $fp['relationship']) ?><?php endif; ?>
                        <?php if ($fp['relationship'] && $fp['birth_year']): ?> · <?php endif; ?>
                        <?php if ($fp['birth_year']): ?>b. <?= (int) $fp['birth_year'] ?><?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="family-profile-card__actions">
                    <form method="post" action="index.php?page=family" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="switch_family_profile">
                        <input type="hidden" name="profile_id" value="<?= (int) $fp['id'] ?>">
                        <input type="hidden" name="redirect_to" value="index.php">
                        <button type="submit" class="btn btn--sm btn--secondary"
                                title="View <?= e((string) $fp['display_name']) ?>'s medications">
                            Switch to
                        </button>
                    </form>
                    <button type="button" class="btn btn--sm btn--ghost" data-open-family-edit-modal="<?= (int) $fp['id'] ?>">Edit</button>
                    <form method="post" action="index.php?page=family" style="display:inline"
                          data-confirm="Remove <?= e((string) $fp['display_name']) ?> from your family members? Their medication records will be kept but unlinked from this profile.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_family_profile">
                        <input type="hidden" name="profile_id" value="<?= (int) $fp['id'] ?>">
                        <button type="submit" class="btn btn--sm btn--danger">Remove</button>
                    </form>
                </div>
            </div>

            <div class="modal-overlay" data-family-edit-modal="<?= (int) $fp['id'] ?>">
                <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="family-edit-title-<?= (int) $fp['id'] ?>">
                    <div class="modal-header">
                        <h2 id="family-edit-title-<?= (int) $fp['id'] ?>">Edit <?= e((string) $fp['display_name']) ?></h2>
                        <button type="button" class="icon-button" data-close-family-edit-modal aria-label="Close">&#10005;</button>
                    </div>
                    <div class="modal-scroll">
                        <form method="post" action="index.php?page=family" class="profile-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_family_profile">
                            <input type="hidden" name="profile_id" value="<?= (int) $fp['id'] ?>">

                            <div class="form-group">
                                <label for="edit_display_name_<?= (int) $fp['id'] ?>" class="form-label">Name <span class="required">*</span></label>
                                <input type="text" id="edit_display_name_<?= (int) $fp['id'] ?>" name="display_name" class="form-control" required
                                       maxlength="100" value="<?= e((string) $fp['display_name']) ?>">
                            </div>

                            <div class="form-group">
                                <label for="edit_relationship_<?= (int) $fp['id'] ?>" class="form-label">Relationship</label>
                                <select id="edit_relationship_<?= (int) $fp['id'] ?>" name="relationship" class="form-control">
                                    <option value="">— Optional —</option>
                                    <?php foreach ($relationships as $rel): ?>
                                    <option value="<?= e($rel) ?>"<?= $fp['relationship'] === $rel ? ' selected' : '' ?>><?= e($rel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="edit_birth_year_<?= (int) $fp['id'] ?>" class="form-label">Birth Year</label>
                                <input type="number" id="edit_birth_year_<?= (int) $fp['id'] ?>" name="birth_year" class="form-control"
                                       min="1900" max="<?= (int) date('Y') ?>"
                                       value="<?= $fp['birth_year'] !== null ? (int) $fp['birth_year'] : '' ?>">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Avatar Color</label>
                                <div class="avatar-color-picker">
                                    <?php
                                    $palette = ['#6366f1', '#ec4899', '#10b981', '#f59e0b', '#3b82f6', '#ef4444'];
                                    $currentColor = (string) ($fp['avatar_color'] ?? '#6366f1');
                                    foreach ($palette as $color): ?>
                                    <label class="avatar-color-swatch">
                                        <input type="radio" name="avatar_color_edit_<?= (int) $fp['id'] ?>" value="<?= e($color) ?>"
                                               <?= $currentColor === $color ? 'checked' : '' ?>>
                                        <span class="avatar-color-dot" style="background:<?= e($color) ?>"></span>
                                    </label>
                                    <?php endforeach; ?>
                                    <label class="avatar-color-swatch avatar-color-swatch--custom">
                                        <input type="radio" name="avatar_color_edit_<?= (int) $fp['id'] ?>" value="custom" id="edit_color_custom_radio_<?= (int) $fp['id'] ?>"
                                               <?= !in_array($currentColor, $palette, true) ? 'checked' : '' ?>>
                                        <input type="color" id="edit_color_custom_<?= (int) $fp['id'] ?>" value="<?= e($currentColor) ?>"
                                               class="avatar-color-custom-input">
                                        <span class="avatar-color-custom-label">Custom color picker</span>
                                    </label>
                                </div>
                                <input type="hidden" name="avatar_color_final" id="edit_avatar_color_final_<?= (int) $fp['id'] ?>"
                                       value="<?= e($currentColor) ?>">
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn--primary">Save Changes</button>
                                <button type="button" class="btn btn--ghost" data-close-family-edit-modal>Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="profile-panel">
        <h2 class="panel-title">Add a Family Member</h2>
        <form method="post" action="index.php?page=family" class="profile-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_family_profile">

            <div class="form-group">
                <label for="display_name" class="form-label">Name <span class="required">*</span></label>
                <input type="text" id="display_name" name="display_name" class="form-control" required
                       maxlength="100" placeholder="e.g. Sarah">
            </div>

            <div class="form-group">
                <label for="relationship" class="form-label">Relationship</label>
                <select id="relationship" name="relationship" class="form-control">
                    <option value="">— Optional —</option>
                    <?php foreach ($relationships as $rel): ?>
                    <option value="<?= e($rel) ?>"><?= e($rel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="birth_year" class="form-label">Birth Year</label>
                <input type="number" id="birth_year" name="birth_year" class="form-control"
                       min="1900" max="<?= (int) date('Y') ?>" placeholder="e.g. 1985">
            </div>

            <div class="form-group">
                <label class="form-label">Avatar Color</label>
                <div class="avatar-color-picker">
                    <?php
                    $palette = ['#6366f1', '#ec4899', '#10b981', '#f59e0b', '#3b82f6', '#ef4444'];
                    foreach ($palette as $i => $color): ?>
                    <label class="avatar-color-swatch">
                        <input type="radio" name="avatar_color" value="<?= e($color) ?>"
                               <?= $i === 0 ? 'checked' : '' ?>>
                        <span class="avatar-color-dot" style="background:<?= e($color) ?>"></span>
                    </label>
                    <?php endforeach; ?>
                    <label class="avatar-color-swatch avatar-color-swatch--custom">
                        <input type="radio" name="avatar_color" value="custom" id="color_custom_radio">
                        <input type="color" id="color_custom" value="#6366f1"
                               class="avatar-color-custom-input">
                        <span class="avatar-color-custom-label">Custom color picker</span>
                    </label>
                </div>
                <input type="hidden" name="avatar_color_final" id="avatar_color_final" value="#6366f1">
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn--primary">Add Family Member</button>
            </div>
        </form>
    </section>

</main>

<script>
(function () {
    // Profile switcher dropdown toggle
    var switcher = document.querySelector('[data-profile-switcher]');
    if (switcher) {
        var chip = switcher.querySelector('[data-profile-chip]');
        var dropdown = switcher.querySelector('[data-profile-dropdown]');
        if (chip && dropdown) {
            chip.addEventListener('click', function () {
                var expanded = chip.getAttribute('aria-expanded') === 'true';
                chip.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                dropdown.hidden = expanded;
            });
            document.addEventListener('click', function (e) {
                if (!switcher.contains(e.target)) {
                    chip.setAttribute('aria-expanded', 'false');
                    dropdown.hidden = true;
                }
            });
        }
    }

    // Confirm dialogs
    document.querySelectorAll('[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            var msg = form.getAttribute('data-confirm');
            if (!window.confirm(msg)) {
                e.preventDefault();
            }
        });
    });

    // Avatar color picker — sync custom color input into hidden field
    function setupColorPicker(radioName, customInputId, finalInputId) {
        var customRadio  = document.querySelector('input[name="' + radioName + '"][value="custom"]');
        var customInput  = document.getElementById(customInputId);
        var finalInput   = document.getElementById(finalInputId);
        if (!customRadio || !customInput || !finalInput) return;

        document.querySelectorAll('input[name="' + radioName + '"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                if (radio.value === 'custom') {
                    finalInput.value = customInput.value;
                } else {
                    finalInput.value = radio.value;
                }
            });
        });
        customInput.addEventListener('input', function () {
            customRadio.checked = true;
            finalInput.value = customInput.value;
        });
        // On form submit, replace avatar_color with the resolved value
        var form = customInput.closest('form');
        if (form) {
            form.addEventListener('submit', function () {
                document.querySelectorAll('input[name="' + radioName + '"]').forEach(function (r) { r.disabled = true; });
                finalInput.name = 'avatar_color';
                finalInput.disabled = false;
            });
        }
    }

    setupColorPicker('avatar_color', 'color_custom', 'avatar_color_final');
    <?php foreach ($profiles as $fp): ?>
    setupColorPicker('avatar_color_edit_<?= (int) $fp['id'] ?>', 'edit_color_custom_<?= (int) $fp['id'] ?>', 'edit_avatar_color_final_<?= (int) $fp['id'] ?>');
    <?php endforeach; ?>

    // Family member edit modal — open/close wiring
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
})();
</script>
</body>
</html>
