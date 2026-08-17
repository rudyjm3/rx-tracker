<?php
// Shared partial: postpone/snooze, refill, adjust-quantity, discontinue, resume,
// instructions/notes, update-dose, refill-history, slot-picker, missed-dose,
// log-past-dose, free-log, required-doses-list, and alarm-overlay modals, plus the
// bottom nav and closing document tags.
// Used by dashboard/medications/pain-tracking/mood-wellbeing, after pages-shell-epilogue.php.
// Expects the variables computed by includes/pages-data.php to be in scope.
?>
<div class="modal-overlay" data-postpone-modal>
  <div class="modal-dialog postpone-dialog" role="dialog" aria-modal="true" aria-labelledby="postpone-modal-title">
    <div class="modal-header">
      <h2 id="postpone-modal-title">Snooze reminder</h2>
      <button type="button" class="modal-close-btn" data-close-postpone-modal aria-label="Close postpone modal">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
    </div>
    <div class="modal-scroll">
    <form method="post" action="index.php" class="stacked-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="postpone_dose">
      <input type="hidden" name="medication_id" data-postpone-medication-id>
      <input type="hidden" name="scheduled_date" data-postpone-scheduled-date>
      <input type="hidden" name="scheduled_time" data-postpone-scheduled-time>
      <label>Snooze for
        <select name="postpone_minutes" required>
          <option value="5">5 minutes</option>
          <option value="10">10 minutes</option>
          <option value="15">15 minutes</option>
          <option value="30">30 minutes</option>
        </select>
      </label>
      <div class="modal-footer">
        <button type="submit">Snooze</button>
      </div>
    </form>
    </div>
  </div>
</div>

<div class="modal-overlay" data-refill-modal>
  <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="refill-modal-title">
    <div class="modal-header">
      <div>
        <h2 id="refill-modal-title">Log Refill</h2>
        <p class="refill-modal-subtitle"><strong data-refill-med-name></strong> <span class="dose-inline" data-refill-med-dose></span></p>
      </div>
      <button type="button" class="modal-close-btn" data-close-refill-modal aria-label="Close refill modal">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
    </div>
    <div class="modal-scroll">
    <form class="stacked-form" data-refill-form>
      <input type="hidden" name="medication_id" data-refill-medication-id value="">
      <label>Refill date
        <input type="date" name="refill_date" data-refill-date required>
      </label>
      <label>Amount
        <span class="input-with-unit">
          <input type="number" step="0.001" min="0.001" name="amount" required placeholder="e.g. 30">
          <span data-refill-amount-unit>tablets</span>
        </span>
      </label>
      <label>Note <span class="field-optional">(optional)</span>
        <input name="note" placeholder="e.g. 30-day supply" maxlength="255">
      </label>
      <div class="refill-form-actions modal-footer">
        <button type="submit">Log refill</button>
        <button type="button" class="button-link button-link--cancel" data-close-refill-modal>Cancel</button>
      </div>
    </form>
    </div>
  </div>
</div>

<div class="modal-overlay" data-adjust-qty-modal>
  <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="adjust-qty-modal-title">
    <div class="modal-header">
      <div>
        <h2 id="adjust-qty-modal-title">Adjust Quantity</h2>
        <p class="refill-modal-subtitle"><strong data-adjust-qty-med-name></strong> <span class="dose-inline" data-adjust-qty-med-dose></span></p>
      </div>
      <button type="button" class="modal-close-btn" data-close-adjust-qty-modal aria-label="Close adjust quantity modal">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
    </div>
    <div class="modal-scroll">
    <form class="stacked-form" data-adjust-qty-form>
      <input type="hidden" name="medication_id" data-adjust-qty-medication-id value="">
      <p class="pill-meta">Corrects the on-hand count without resetting the supply bar. Current count: <strong data-adjust-qty-current></strong></p>
      <label>Corrected count
        <span class="input-with-unit">
          <input type="number" step="0.001" min="0" name="new_count" data-adjust-qty-input required>
          <span data-adjust-qty-unit>tablets</span>
        </span>
      </label>
      <label>Reason <span class="field-optional">(optional)</span>
        <input name="note" placeholder="e.g. recount, dropped a pill" maxlength="255">
      </label>
      <div class="refill-form-actions modal-footer">
        <button type="submit">Save adjustment</button>
        <button type="button" class="button-link button-link--cancel" data-close-adjust-qty-modal>Cancel</button>
      </div>
    </form>
    </div>
  </div>
</div>

<div class="modal-overlay" data-discontinue-modal>
  <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="discontinue-modal-title">
    <div class="modal-header">
      <div>
        <h2 id="discontinue-modal-title">Discontinue Use</h2>
        <p class="refill-modal-subtitle"><strong data-discontinue-med-name></strong></p>
      </div>
      <button type="button" class="modal-close-btn" data-close-discontinue-modal aria-label="Close discontinue modal">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
    </div>
    <div class="modal-scroll">
    <form class="stacked-form" method="post" action="index.php" data-discontinue-form>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="deactivate_medication">
      <input type="hidden" name="medication_id" data-discontinue-medication-id value="">
      <fieldset class="form-section discontinue-reasons">
        <legend>Why are you discontinuing this medication?</legend>
        <label class="discontinue-reason-option">
          <input type="radio" name="reason" value="End of regimen" required>
          End of regimen
        </label>
        <label class="discontinue-reason-option">
          <input type="radio" name="reason" value="Side effects (moderate to severe)">
          Side effects (moderate to severe)
        </label>
        <label class="discontinue-reason-option">
          <input type="radio" name="reason" value="Doctor's orders">
          Doctor's orders
        </label>
        <label class="discontinue-reason-option">
          <input type="radio" name="reason" value="Other">
          Other
        </label>
      </fieldset>
      <label>Comment <span class="field-optional">(optional)</span>
        <textarea name="comment" rows="3" maxlength="500" placeholder="Add more detail about why you're stopping this medication"></textarea>
      </label>
      <div class="refill-form-actions modal-footer">
        <button type="submit" class="danger">Discontinue Use</button>
        <button type="button" class="button-link button-link--cancel" data-close-discontinue-modal>Cancel</button>
      </div>
    </form>
    </div>
  </div>
</div>

<div class="modal-overlay" data-resume-modal>
  <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="resume-modal-title">
    <div class="modal-header">
      <div>
        <h2 id="resume-modal-title">Resume Use</h2>
        <p class="refill-modal-subtitle"><strong data-resume-med-name></strong></p>
      </div>
      <button type="button" class="modal-close-btn" data-close-resume-modal aria-label="Close resume modal">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
    </div>
    <div class="modal-scroll">
    <form class="stacked-form" method="post" action="index.php" data-resume-form>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="activate_medication">
      <input type="hidden" name="medication_id" data-resume-medication-id value="">
      <fieldset class="form-section discontinue-reasons">
        <legend>Why are you resuming this medication?</legend>
        <label class="discontinue-reason-option">
          <input type="radio" name="reason" value="Doctor's orders" required>
          Doctor's orders
        </label>
        <label class="discontinue-reason-option">
          <input type="radio" name="reason" value="Symptoms returned">
          Symptoms returned
        </label>
        <label class="discontinue-reason-option">
          <input type="radio" name="reason" value="Retrying after side effects subsided">
          Retrying after side effects subsided
        </label>
        <label class="discontinue-reason-option">
          <input type="radio" name="reason" value="Restarting regimen">
          Restarting regimen
        </label>
        <label class="discontinue-reason-option">
          <input type="radio" name="reason" value="Other">
          Other
        </label>
      </fieldset>
      <label>Comment <span class="field-optional">(optional)</span>
        <textarea name="comment" rows="3" maxlength="500" placeholder="Add more detail about why you're restarting this medication"></textarea>
      </label>
      <div class="refill-form-actions modal-footer">
        <button type="submit">Resume Use</button>
        <button type="button" class="button-link button-link--cancel" data-close-resume-modal>Cancel</button>
      </div>
    </form>
    </div>
  </div>
</div>

<div class="modal-overlay" data-instructions-modal>
  <div class="modal-dialog modal-dialog--wide" role="dialog" aria-modal="true" aria-labelledby="instructions-modal-title">
    <div class="modal-header">
      <div>
        <h2 id="instructions-modal-title"><span data-instructions-modal-name></span> <span class="dose-inline" data-instructions-modal-dose></span></h2>
      </div>
      <button type="button" class="modal-close-btn" data-close-instructions-modal aria-label="Close instructions">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
    </div>
    <div class="modal-scroll" style="padding:1rem">
      <div data-notes-dose-history></div>
      <div class="notes-section">
        <div data-notes-list class="notes-list">
          <p class="pain-graph-loading" data-notes-loading>Loading&#8230;</p>
        </div>
        <button type="button" class="add-note-link" data-open-add-note>+ Add new note</button>
      </div>
      <div data-add-note-form hidden class="note-edit-form" style="margin-top:.75rem">
        <textarea data-add-note-textarea rows="4" maxlength="5000" placeholder="Enter note&#8230;" style="width:100%"></textarea>
        <div class="refill-form-actions" style="padding:.5rem 0 0">
          <button type="button" data-save-add-note>Save note</button>
          <button type="button" class="button-link button-link--cancel" data-cancel-add-note>Cancel</button>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal-overlay" data-update-dose-modal>
  <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="update-dose-title">
    <div class="modal-header">
      <h2 id="update-dose-title">Update Prescribed Dose</h2>
      <button type="button" class="modal-close-btn" data-close-update-dose-modal aria-label="Close">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
    </div>
    <div class="modal-scroll" style="padding:1rem">
      <p style="margin-bottom:.25rem"><strong data-update-dose-med-name></strong></p>
      <p class="muted" style="margin-bottom:1.25rem">Current dose: <span data-update-dose-current></span></p>
      <label style="display:block;margin-bottom:.75rem">New dose amount
        <div style="display:flex;gap:.5rem;margin-top:.25rem">
          <input type="number" step="0.001" min="0" data-update-dose-amount placeholder="e.g. 15" style="flex:1">
          <select data-update-dose-unit>
            <?php foreach (dose_unit_options() as $u): ?>
            <option value="<?= e($u) ?>"><?= e($u) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </label>
      <label style="display:block">Reason <span class="field-optional">(optional)</span>
        <textarea data-update-dose-reason rows="3" maxlength="500" placeholder="e.g. Doctor increased dose at last visit" style="width:100%;margin-top:.25rem"></textarea>
      </label>
    </div>
    <div class="refill-form-actions modal-footer">
      <button type="button" data-save-update-dose>Save dose change</button>
      <button type="button" class="button-link button-link--cancel" data-close-update-dose-modal>Cancel</button>
    </div>
  </div>
</div>

<div class="modal-overlay" data-refill-history-modal>
  <div class="modal-dialog refill-history-dialog" role="dialog" aria-modal="true" aria-labelledby="refill-history-title">
    <div class="modal-header">
      <div>
        <h2 id="refill-history-title">Refill History</h2>
        <p class="refill-modal-subtitle"><strong data-refill-history-med-name></strong></p>
      </div>
      <button type="button" class="modal-close-btn" data-close-refill-history aria-label="Close refill history">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
    </div>
    <div class="modal-scroll">
      <div class="refill-history-body" data-refill-history-body>
        <p class="pain-graph-loading">Loading&hellip;</p>
      </div>
    </div>
  </div>
</div>

<div class="modal-overlay" data-slot-picker-modal>
  <div class="modal-dialog slot-picker-dialog">
    <div class="modal-header">
      <h2 class="modal-title" data-slot-picker-title>Log dose</h2>
      <button type="button" class="modal-close-btn" data-close-slot-picker aria-label="Close">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
    </div>
    <div class="modal-body slot-picker-body">
      <p class="slot-picker-hint">Select which scheduled dose you are logging:</p>
      <div class="slot-picker-list" data-slot-picker-list></div>
      <div class="slot-late-question" data-slot-late-question hidden>
        <p>This dose time has already passed. When you actually took it:</p>
        <label class="slot-late-option"><input type="radio" name="slot_timing" value="on_time" checked> I took it on time &mdash; just logging it now</label>
        <label class="slot-late-option"><input type="radio" name="slot_timing" value="late"> I took it late (after the scheduled window)</label>
        <div class="slot-late-time" data-slot-late-time hidden style="margin-top:.75rem;">
          <label for="slot-late-time-input" class="form-label">Time taken</label>
          <input type="time" id="slot-late-time-input" data-slot-late-time-input class="form-control" style="width:100%;margin-top:.375rem;">
        </div>
      </div>
      <div class="slot-free-time" data-slot-free-time hidden>
        <p style="margin-bottom:0.5rem;font-size:0.875rem;color:var(--rx-text-muted);">All scheduled times are logged. Log at a different time:</p>
        <input type="time" data-slot-free-time-input class="form-control" style="width:100%;">
      </div>
    </div>
    <div class="modal-footer slot-picker-footer">
      <button type="button" class="button-link button-link--cancel" data-close-slot-picker>Cancel</button>
      <button type="button" data-slot-picker-confirm disabled>Log dose</button>
    </div>
  </div>
</div>

<div class="modal-overlay" data-missed-dose-modal>
  <div class="modal-dialog slot-picker-dialog">
    <div class="modal-header">
      <h2 class="modal-title" data-missed-dose-title>Log missed dose</h2>
      <button type="button" class="modal-close-btn" data-close-missed-dose-modal aria-label="Close">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
    </div>
    <div class="modal-body slot-picker-body">
      <p class="slot-picker-hint">When did you take this dose?</p>
      <form method="post" action="index.php" data-missed-dose-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action"         value="mark_dose">
        <input type="hidden" name="status"         value="taken">
        <input type="hidden" name="json_response"  value="1">
        <input type="hidden" name="note"           data-missed-dose-note-hidden value="Marked taken (was missed)">
        <input type="hidden" name="pain_level"     data-missed-dose-pain-level  value="">
        <input type="hidden" name="mood_level"     data-missed-dose-mood-level  value="">
        <input type="hidden" name="medication_id"  data-missed-dose-med-id     value="">
        <input type="hidden" name="scheduled_date" data-missed-dose-date        value="">
        <input type="hidden" name="scheduled_time" data-missed-dose-sched-time  value="">
        <input type="hidden" name="group_id"       data-missed-dose-group-id    value="">
        <div class="form-row" style="margin-top:1rem;">
          <label for="missed-dose-actual-time" class="form-label">Time taken</label>
          <input type="time" id="missed-dose-actual-time" name="actual_taken_time"
                 data-missed-dose-actual-time class="form-control" style="margin-top:.375rem;width:100%;">
        </div>
        <div data-missed-dose-pain-section hidden style="margin-top:1.25rem;">
          <p class="feedback-pain-label">Pain level <span class="feedback-pain-hint">(1 = minimal &mdash; 10 = severe)</span></p>
          <div class="pain-level-selector" role="group" aria-label="Select pain level" style="margin-top:.4rem;">
            <?php for ($i = 1; $i <= 10; $i++): ?>
              <button type="button" class="missed-pain-btn" data-missed-pain="<?= $i ?>" aria-label="Pain level <?= $i ?>"><?= $i ?></button>
            <?php endfor; ?>
          </div>
        </div>
        <div data-missed-dose-mood-section hidden style="margin-top:1.25rem;">
          <p class="feedback-pain-label">Mood level <span class="feedback-pain-hint">(1 = very low &mdash; 10 = excellent)</span></p>
          <div class="pain-level-selector" role="group" aria-label="Select mood level" style="margin-top:.4rem;">
            <?php for ($i = 1; $i <= 10; $i++): ?>
              <button type="button" class="missed-mood-btn" data-missed-mood="<?= $i ?>" aria-label="Mood level <?= $i ?>"><?= $i ?></button>
            <?php endfor; ?>
          </div>
        </div>
        <div data-missed-dose-note-section hidden style="margin-top:.75rem;">
          <label style="display:block;">Notes <span class="field-optional">(optional)</span>
            <textarea data-missed-dose-note-text rows="2" maxlength="250"
                      placeholder="Any notes about this dose?"
                      style="margin-top:.375rem;width:100%;"></textarea>
          </label>
        </div>
      </form>
    </div>
    <div class="modal-footer slot-picker-footer">
      <button type="button" class="button-link button-link--cancel" data-close-missed-dose-modal>Cancel</button>
      <button type="button" data-missed-dose-confirm>Log dose</button>
    </div>
  </div>
</div>

<div class="modal-overlay" data-log-past-dose-modal>
  <div class="modal-dialog slot-picker-dialog" role="dialog" aria-modal="true" aria-labelledby="log-past-dose-title">
    <div class="modal-header">
      <h2 class="modal-title" id="log-past-dose-title" data-log-past-dose-title>Log past dose</h2>
      <button type="button" class="modal-close-btn" data-close-log-past-dose aria-label="Close">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
    </div>
    <div class="modal-body slot-picker-body">
      <p class="slot-picker-hint">Which day and dose was this?</p>
      <form method="post" action="index.php" data-log-past-dose-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action"         value="mark_dose">
        <input type="hidden" name="status"          value="taken">
        <input type="hidden" name="json_response"   value="1">
        <input type="hidden" name="pain_level"       data-log-past-dose-pain-level value="">
        <input type="hidden" name="mood_level"       data-log-past-dose-mood-level value="">
        <input type="hidden" name="medication_id"    data-log-past-dose-med-id     value="">

        <div class="form-row" style="margin-top:1rem;">
          <label for="log-past-dose-date" class="form-label">Date</label>
          <input type="date" id="log-past-dose-date" name="scheduled_date"
                 data-log-past-dose-date class="form-control" style="margin-top:.375rem;width:100%;">
        </div>

        <div class="form-row" data-log-past-dose-slot-section style="margin-top:.75rem;">
          <label class="form-label">Which dose?</label>
          <div class="slot-picker-list" data-log-past-dose-slot-list style="margin-top:.375rem;"></div>
        </div>

        <div class="form-row" data-log-past-dose-free-time-section hidden style="margin-top:.75rem;">
          <label for="log-past-dose-free-time" class="form-label">Time</label>
          <input type="time" id="log-past-dose-free-time" name="scheduled_time"
                 data-log-past-dose-free-time class="form-control" style="margin-top:.375rem;width:100%;">
        </div>

        <div class="form-row" style="margin-top:.75rem;">
          <label for="log-past-dose-actual-time" class="form-label">Time actually taken <span class="field-optional">(optional — leave blank to log as "just now")</span></label>
          <input type="time" id="log-past-dose-actual-time" name="actual_taken_time"
                 data-log-past-dose-actual-time class="form-control" style="margin-top:.375rem;width:100%;">
        </div>

        <div data-log-past-dose-pain-section hidden style="margin-top:1.25rem;">
          <p class="feedback-pain-label">Pain level <span class="feedback-pain-hint">(1 = minimal &mdash; 10 = severe)</span></p>
          <div class="pain-level-selector" role="group" aria-label="Select pain level" style="margin-top:.4rem;">
            <?php for ($i = 1; $i <= 10; $i++): ?>
              <button type="button" class="log-past-dose-pain-btn" data-log-past-dose-pain="<?= $i ?>" aria-label="Pain level <?= $i ?>"><?= $i ?></button>
            <?php endfor; ?>
          </div>
        </div>

        <div data-log-past-dose-mood-section hidden style="margin-top:1.25rem;">
          <p class="feedback-pain-label">Mood level <span class="feedback-pain-hint">(1 = very low &mdash; 10 = excellent)</span></p>
          <div class="pain-level-selector" role="group" aria-label="Select mood level" style="margin-top:.4rem;">
            <?php for ($i = 1; $i <= 10; $i++): ?>
              <button type="button" class="log-past-dose-mood-btn" data-log-past-dose-mood="<?= $i ?>" aria-label="Mood level <?= $i ?>"><?= $i ?></button>
            <?php endfor; ?>
          </div>
        </div>

        <label style="margin-top:.75rem;display:block;">Notes <span class="field-optional">(optional)</span>
          <textarea name="note" data-log-past-dose-note rows="2" maxlength="250"
                    placeholder="Any notes about this dose?"
                    style="margin-top:.375rem;width:100%;"></textarea>
        </label>
      </form>
    </div>
    <div class="modal-footer slot-picker-footer">
      <button type="button" class="button-link button-link--cancel" data-close-log-past-dose>Cancel</button>
      <button type="button" data-log-past-dose-confirm disabled>Log dose</button>
    </div>
  </div>
</div>

<div class="modal-overlay" data-free-log-modal>
  <div class="modal-dialog slot-picker-dialog" role="dialog" aria-modal="true" aria-labelledby="free-log-title">
    <div class="modal-header">
      <h2 class="modal-title" id="free-log-title" data-free-log-title>Log dose</h2>
      <button type="button" class="modal-close-btn" data-close-free-log aria-label="Close">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
    </div>
    <div class="modal-body slot-picker-body">
      <p class="slot-picker-hint">Select the time you are taking this dose:</p>
      <div class="form-row" style="margin-top:0.75rem;">
        <label for="free-log-time" class="form-label">Time taken</label>
        <input type="time" id="free-log-time" data-free-log-time class="form-control" style="margin-top:.375rem;width:100%;">
      </div>
    </div>
    <div class="modal-footer slot-picker-footer">
      <button type="button" class="button-link button-link--cancel" data-close-free-log>Cancel</button>
      <button type="button" data-free-log-confirm>Log dose</button>
    </div>
  </div>
</div>

<div class="modal-overlay" data-required-doses-modal>
  <div class="modal-dialog required-doses-dialog" role="dialog" aria-modal="true" aria-labelledby="required-doses-modal-title">
    <div class="modal-header">
      <h2 id="required-doses-modal-title" class="modal-title"><i class="fa-solid fa-list-check" aria-hidden="true"></i> Required doses — today</h2>
      <button type="button" class="modal-close-btn" data-close-required-doses-modal aria-label="Close">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
    </div>
    <div class="modal-scroll">
      <?php if (empty($requiredByMed)): ?>
        <p class="empty-state-text">No required doses scheduled for today.</p>
      <?php else: ?>
        <ul class="required-doses-list">
          <?php foreach ($requiredByMed as $doses): ?>
            <li class="required-doses-med">
              <details class="required-doses-details">
                <summary class="required-doses-summary">
                  <span class="required-doses-med-name">
                    <strong><?= e((string) $doses[0]['name']) ?></strong>
                    <?php if (formattedDose($doses[0]) !== ''): ?><span class="dose-inline"><?= e(formattedDose($doses[0])) ?></span><?php endif; ?>
                  </span>
                  <span class="required-doses-view-label">View dose times <i class="fa-solid fa-chevron-down required-doses-chevron" aria-hidden="true"></i></span>
                </summary>
                <ul class="required-doses-times">
                  <?php foreach ($doses as $dose): ?>
                    <li class="required-doses-time-row">
                      <span class="required-doses-time"><?= e(to12h((string) $dose['reminder_time'])) ?></span>
                      <?php
                        $rdStatus    = (string) ($dose['status'] ?? '');
                        $rdPostponed = is_string($dose['postponed_until'] ?? null) && (string) $dose['postponed_until'] !== '';
                        if ($rdStatus === 'taken'):
                          $rdLate = minutesLate($dose, $graceMinutes);
                      ?>
                        <span class="<?= $rdLate !== null ? 'warn-pill' : 'done-pill' ?>">Taken<?= $rdLate !== null ? ' (' . formatLate($rdLate) . ')' : '' ?></span>
                      <?php elseif ($rdStatus === 'missed'): ?>
                        <span class="alert-pill">Missed</span>
                      <?php elseif ($rdStatus === 'skipped'): ?>
                        <span class="warn-pill">Skipped</span>
                      <?php elseif ($rdPostponed): ?>
                        <span class="done-pill">Snoozed until <?= e(to12h((new DateTimeImmutable((string) $dose['postponed_until']))->format('H:i'))) ?></span>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </details>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="alarm-overlay" data-alarm-overlay aria-modal="true" role="alertdialog" aria-labelledby="alarm-title">
  <div class="alarm-dialog">
    <div class="alarm-pulse-ring"></div>
    <p class="alarm-eyebrow" data-alarm-eyebrow>Dose Due Now</p>

    <!-- Single medication mode -->
    <div data-alarm-single-mode>
      <h2 id="alarm-title" class="alarm-med-name" data-alarm-med-name></h2>
      <p class="alarm-med-dose" data-alarm-med-dose></p>
    </div>

    <!-- Group mode -->
    <div data-alarm-group-mode hidden>
      <h2 id="alarm-title-group" class="alarm-med-name" data-alarm-group-name></h2>
      <ul class="alarm-group-list" data-alarm-group-list></ul>
    </div>

    <div class="alarm-actions">
      <button type="button" class="alarm-take-btn" data-alarm-take>Take Now</button>
      <button type="button" class="secondary alarm-skip-btn" data-alarm-skip>Skip</button>
      <button type="button" class="secondary alarm-individual-btn" data-alarm-individual hidden>Manage Each</button>
      <div class="alarm-snooze-row">
        <select data-alarm-snooze-minutes class="alarm-snooze-select">
          <option value="5"<?= $snoozeMinutes === 5 ? ' selected' : '' ?>>5 min</option>
          <option value="10"<?= $snoozeMinutes === 10 ? ' selected' : '' ?>>10 min</option>
          <option value="15"<?= $snoozeMinutes === 15 ? ' selected' : '' ?>>15 min</option>
          <option value="30"<?= $snoozeMinutes === 30 ? ' selected' : '' ?>>30 min</option>
        </select>
        <button type="button" class="secondary" data-alarm-snooze>Snooze</button>
      </div>
    </div>
    <template id="alarm-item-actions-tpl">
      <div class="alarm-item-actions">
        <button type="button" class="alarm-item-take-btn" data-item-take>Take</button>
        <button type="button" class="secondary" data-item-skip>Skip</button>
        <button type="button" class="secondary" data-item-snooze>Snooze</button>
      </div>
    </template>
  </div>
</div>
<?php include __DIR__ . '/../includes/bottom-nav.php'; ?>
</body>
</html>
