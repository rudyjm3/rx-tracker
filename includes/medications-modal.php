<?php
/**
 * Medications and Supplements full-screen modal — read-only active/inactive
 * list; actual add/edit happens on the real Medications page (the footer
 * button switches the active profile to this one first, so a medication
 * added from there attaches to the right person).
 *
 * Expects in scope:
 *   $modalProfileId    ?int  null for the account owner, else a family member id
 *   $modalActiveMeds   array
 *   $modalInactiveMeds array
 */
?>
<div class="modal-overlay" data-meds-modal>
  <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="meds-modal-title">
    <div class="modal-header">
      <h2 id="meds-modal-title">Medications and Supplements</h2>
      <button type="button" class="modal-close-btn" data-close-meds-modal aria-label="Close">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
    </div>
    <div class="modal-scroll">
      <?php if ($modalActiveMeds === [] && $modalInactiveMeds === []): ?>
        <p class="muted">No medications yet.</p>
      <?php else: ?>
        <?php if ($modalActiveMeds !== []): ?>
        <h3 style="margin:0 0 .5rem;font-size:.85rem;color:var(--rx-text-muted)">Active</h3>
        <div class="medication-list" style="margin-bottom:1rem">
          <?php foreach ($modalActiveMeds as $med): ?>
            <?= render_simple_medication_line($med) ?>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($modalInactiveMeds !== []): ?>
        <h3 style="margin:0 0 .5rem;font-size:.85rem;color:var(--rx-text-muted)">No longer active</h3>
        <div class="medication-list">
          <?php foreach ($modalInactiveMeds as $med): ?>
            <?= render_simple_medication_line($med, true) ?>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      <?php endif; ?>
      <div class="modal-footer">
        <form method="post" action="index.php?page=profile">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="switch_family_profile">
          <input type="hidden" name="profile_id" value="<?= $modalProfileId === null ? '0' : (int) $modalProfileId ?>">
          <input type="hidden" name="redirect_to" value="index.php?page=medications">
          <button type="submit" class="secondary">Manage in Medications</button>
        </form>
      </div>
    </div>
  </div>
</div>
