<?php
/**
 * Allergies & Intolerances full-screen modal: a collapsible list view (Active/Past
 * tabs), an Add view, and one pre-rendered Edit view per allergy — all toggled by
 * JS within a single modal, matching the app's existing full-screen-modal pattern.
 *
 * Expects in scope:
 *   $modalProfileId      ?int   null for the account owner, else a family member id
 *   $modalAllergies      array  rows from AllergyRepository::allergiesForProfile()
 *   $modalAllergyCatalog array  rows from AllergyRepository::catalogForUser()
 *   $modalActionUrl      string e.g. 'index.php?page=profile' or 'index.php?page=family-member&id=5'
 */

$modalActiveAllergies = array_values(array_filter($modalAllergies, static fn(array $a): bool => (int) $a['is_active'] === 1));
$modalPastAllergies   = array_values(array_filter($modalAllergies, static fn(array $a): bool => (int) $a['is_active'] === 0));
$modalAllergyTypes      = AllergyRepository::allowedTypes();
$modalAllergySeverities = AllergyRepository::allowedSeverities();
$modalAllergyCategories = AllergyRepository::allowedCategories();
?>
<div class="modal-overlay" data-allergies-modal>
  <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="allergies-modal-title">
    <div class="modal-header">
      <h2 id="allergies-modal-title">Allergies &amp; Intolerances</h2>
      <button type="button" class="modal-close-btn" data-close-allergies-modal aria-label="Close">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
    </div>
    <div class="modal-scroll">

      <div data-allergies-view="list">
        <div class="pill-tabs">
          <button type="button" class="pill-tab is-active" data-allergy-tab="active">Active (<?= count($modalActiveAllergies) ?>)</button>
          <button type="button" class="pill-tab" data-allergy-tab="past">Past (<?= count($modalPastAllergies) ?>)</button>
        </div>
        <div data-allergy-tab-panel="active">
          <?php if ($modalActiveAllergies === []): ?>
            <p class="muted">No active allergies.</p>
          <?php else: ?>
            <ul class="sessions-list"><?php foreach ($modalActiveAllergies as $a): ?><?= render_allergy_row($a) ?><?php endforeach; ?></ul>
          <?php endif; ?>
        </div>
        <div data-allergy-tab-panel="past" hidden>
          <?php if ($modalPastAllergies === []): ?>
            <p class="muted">No past allergies.</p>
          <?php else: ?>
            <ul class="sessions-list"><?php foreach ($modalPastAllergies as $a): ?><?= render_allergy_row($a) ?><?php endforeach; ?></ul>
          <?php endif; ?>
        </div>
        <button type="button" class="secondary" style="margin-top:1rem" data-open-allergy-add-view>
          <i class="fa-solid fa-plus" aria-hidden="true"></i> Add
        </button>
      </div>

      <div data-allergies-view="add" hidden>
        <form method="post" action="<?= e($modalActionUrl) ?>" class="stacked-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_profile_allergy">
          <div class="form-group">
            <label for="allergy_add_select">Substance</label>
            <select id="allergy_add_select" name="allergy_catalog_id" data-allergy-select>
              <option value="">— Select —</option>
              <?php foreach ($modalAllergyCatalog as $item): ?>
              <option value="<?= (int) $item['id'] ?>"><?= e((string) $item['name']) ?></option>
              <?php endforeach; ?>
              <option value="new">+ Add a new allergy…</option>
            </select>
          </div>
          <div class="form-group" data-allergy-new-wrap style="display:none">
            <label for="allergy_add_new_name">New allergy name</label>
            <input type="text" id="allergy_add_new_name" name="new_allergy_name" maxlength="150" placeholder="e.g. Amoxicillin">
          </div>
          <div class="form-group">
            <label class="form-label">Type</label>
            <div class="pill-radio-group">
              <?php foreach ($modalAllergyTypes as $type): ?>
              <label class="pill-radio">
                <input type="radio" name="allergy_type" value="<?= e($type) ?>"<?= $type === 'allergy' ? ' checked' : '' ?>>
                <span class="pill-radio-label"><?= e(ucfirst($type)) ?></span>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="form-group">
            <label class="pill-radio pill-radio--danger">
              <input type="checkbox" name="life_threatening" value="1">
              <span class="pill-radio-label">Life-threatening (e.g. Anaphylaxis)</span>
            </label>
          </div>
          <div class="form-group">
            <label class="form-label">Estimated Severity</label>
            <div class="pill-radio-group">
              <?php foreach ($modalAllergySeverities as $sev): ?>
              <label class="pill-radio">
                <input type="radio" name="severity" value="<?= e($sev) ?>">
                <span class="pill-radio-label"><?= e(allergy_severity_label($sev)) ?></span>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Category</label>
            <div class="pill-radio-group">
              <?php foreach ($modalAllergyCategories as $cat): ?>
              <label class="pill-radio">
                <input type="radio" name="category" value="<?= e($cat) ?>">
                <span class="pill-radio-label"><?= e(allergy_category_label($cat)) ?></span>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="form-group">
            <label for="allergy_add_notes">Notes</label>
            <textarea id="allergy_add_notes" name="notes" rows="3" maxlength="1000"></textarea>
          </div>
          <div class="modal-footer">
            <button type="submit" class="secondary">Add</button>
            <button type="button" class="secondary" data-back-to-allergy-list>Cancel</button>
          </div>
        </form>
      </div>

      <?php foreach ($modalAllergies as $a): ?>
      <div data-allergies-view="edit" data-allergy-edit-id="<?= (int) $a['id'] ?>" hidden>
        <form method="post" action="<?= e($modalActionUrl) ?>" class="stacked-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_profile_allergy">
          <input type="hidden" name="allergy_id" value="<?= (int) $a['id'] ?>">
          <div class="form-group">
            <label class="form-label">I currently have this</label>
            <div class="pill-radio-group">
              <label class="pill-radio">
                <input type="radio" name="is_active" value="1"<?= (int) $a['is_active'] === 1 ? ' checked' : '' ?>>
                <span class="pill-radio-label">Yes</span>
              </label>
              <label class="pill-radio">
                <input type="radio" name="is_active" value="0"<?= (int) $a['is_active'] === 0 ? ' checked' : '' ?>>
                <span class="pill-radio-label">No</span>
              </label>
            </div>
          </div>
          <div class="form-group">
            <label for="allergy_edit_select_<?= (int) $a['id'] ?>">Substance</label>
            <select id="allergy_edit_select_<?= (int) $a['id'] ?>" name="allergy_catalog_id" data-allergy-select>
              <option value="">— Select —</option>
              <?php foreach ($modalAllergyCatalog as $item): ?>
              <option value="<?= (int) $item['id'] ?>"<?= (int) $item['id'] === (int) $a['allergy_catalog_id'] ? ' selected' : '' ?>><?= e((string) $item['name']) ?></option>
              <?php endforeach; ?>
              <option value="new">+ Add a new allergy…</option>
            </select>
          </div>
          <div class="form-group" data-allergy-new-wrap style="display:none">
            <label for="allergy_edit_new_name_<?= (int) $a['id'] ?>">New allergy name</label>
            <input type="text" id="allergy_edit_new_name_<?= (int) $a['id'] ?>" name="new_allergy_name" maxlength="150" placeholder="e.g. Amoxicillin">
          </div>
          <div class="form-group">
            <label class="form-label">Type</label>
            <div class="pill-radio-group">
              <?php foreach ($modalAllergyTypes as $type): ?>
              <label class="pill-radio">
                <input type="radio" name="allergy_type" value="<?= e($type) ?>"<?= $a['allergy_type'] === $type ? ' checked' : '' ?>>
                <span class="pill-radio-label"><?= e(ucfirst($type)) ?></span>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="form-group">
            <label class="pill-radio pill-radio--danger">
              <input type="checkbox" name="life_threatening" value="1"<?= (int) $a['life_threatening'] === 1 ? ' checked' : '' ?>>
              <span class="pill-radio-label">Life-threatening (e.g. Anaphylaxis)</span>
            </label>
          </div>
          <div class="form-group">
            <label class="form-label">Estimated Severity</label>
            <div class="pill-radio-group">
              <?php foreach ($modalAllergySeverities as $sev): ?>
              <label class="pill-radio">
                <input type="radio" name="severity" value="<?= e($sev) ?>"<?= $a['severity'] === $sev ? ' checked' : '' ?>>
                <span class="pill-radio-label"><?= e(allergy_severity_label($sev)) ?></span>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Category</label>
            <div class="pill-radio-group">
              <?php foreach ($modalAllergyCategories as $cat): ?>
              <label class="pill-radio">
                <input type="radio" name="category" value="<?= e($cat) ?>"<?= $a['category'] === $cat ? ' checked' : '' ?>>
                <span class="pill-radio-label"><?= e(allergy_category_label($cat)) ?></span>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="form-group">
            <label for="allergy_edit_notes_<?= (int) $a['id'] ?>">Notes</label>
            <textarea id="allergy_edit_notes_<?= (int) $a['id'] ?>" name="notes" rows="3" maxlength="1000"><?= e((string) ($a['notes'] ?? '')) ?></textarea>
          </div>
          <div class="modal-footer" style="justify-content:space-between">
            <button type="submit" class="btn-text" style="color:var(--rx-danger)" form="allergy_delete_form_<?= (int) $a['id'] ?>">Delete</button>
            <div style="display:flex;gap:.5rem">
              <button type="button" class="secondary" data-back-to-allergy-list>Cancel</button>
              <button type="submit" class="secondary">Update</button>
            </div>
          </div>
        </form>
        <form method="post" action="<?= e($modalActionUrl) ?>" id="allergy_delete_form_<?= (int) $a['id'] ?>"
              onsubmit="return confirm('Remove <?= e(addslashes((string) $a['name'])) ?> from the allergy list?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="remove_profile_allergy">
          <input type="hidden" name="allergy_id" value="<?= (int) $a['id'] ?>">
        </form>
      </div>
      <?php endforeach; ?>

    </div>
  </div>
</div>
