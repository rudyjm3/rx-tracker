const apiProxy = (url) => `api-proxy.php?url=${encodeURIComponent(url)}`;

document.querySelectorAll('[data-confirm]').forEach((form) => {
  form.addEventListener('submit', (event) => {
    const message = form.getAttribute('data-confirm');

    if (message && !window.confirm(message)) {
      event.preventDefault();
    }
  });
});

const firstInvalidField = document.querySelector('input:invalid, select:invalid');

if (firstInvalidField) {
  firstInvalidField.addEventListener('invalid', () => {
    firstInvalidField.classList.add('field-error');
  });
}

// ── Nav hamburger ─────────────────────────────────────────────────────────────

const navToggle = document.querySelector('[data-nav-toggle]');
const topNav = document.querySelector('.top-nav');

navToggle?.addEventListener('click', (event) => {
  event.stopPropagation();
  const isOpen = topNav?.classList.toggle('is-open');
  navToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
});

document.addEventListener('click', (event) => {
  if (topNav?.classList.contains('is-open') && !topNav.contains(event.target)) {
    topNav.classList.remove('is-open');
    navToggle?.setAttribute('aria-expanded', 'false');
  }
});

// ── Scroll lock (iOS-safe) ─────────────────────────────────────────────────────

let scrollLockDepth = 0;
let scrollLockY = 0;

const lockBodyScroll = () => {
  if (scrollLockDepth === 0) {
    scrollLockY = window.scrollY;
    document.body.style.overflow = 'hidden';
    document.body.style.position = 'fixed';
    document.body.style.top = `-${scrollLockY}px`;
    document.body.style.width = '100%';
  }
  scrollLockDepth++;
};

const unlockBodyScroll = () => {
  scrollLockDepth = Math.max(0, scrollLockDepth - 1);
  if (scrollLockDepth === 0) {
    document.body.style.overflow = '';
    document.body.style.position = '';
    document.body.style.top = '';
    document.body.style.width = '';
    window.scrollTo(0, scrollLockY);
  }
};

const medicationModal = document.querySelector('[data-medication-modal]');
const closeMedicationModalButton = document.querySelector('[data-close-medication-modal]');
const postponeModal = document.querySelector('[data-postpone-modal]');
const closePostponeModalButton = document.querySelector('[data-close-postpone-modal]');
const postponeMedicationId = document.querySelector('[data-postpone-medication-id]');
const postponeScheduledDate = document.querySelector('[data-postpone-scheduled-date]');
const postponeScheduledTime = document.querySelector('[data-postpone-scheduled-time]');

const doseFeedbackModal = document.querySelector('[data-dose-feedback-modal]');
const closeFeedbackModalButton = document.querySelector('[data-close-feedback-modal]');
const feedbackForm = document.querySelector('[data-feedback-form]');
const feedbackMedicationIdEl = document.querySelector('[data-feedback-medication-id]');
const feedbackScheduledDateEl = document.querySelector('[data-feedback-scheduled-date]');
const feedbackScheduledTimeEl = document.querySelector('[data-feedback-scheduled-time]');
const feedbackPainLevelEl = document.querySelector('[data-feedback-pain-level]');
const feedbackMoodLevelEl = document.querySelector('[data-feedback-mood-level]');
const feedbackNoteEl = document.querySelector('[data-feedback-note]');
const feedbackCharCounter = document.querySelector('[data-feedback-char-counter]');
const feedbackPainSection = document.querySelector('[data-feedback-pain-section]');
const feedbackMoodSection = document.querySelector('[data-feedback-mood-section]');
const skipFeedbackBtn = document.querySelector('[data-skip-feedback]');

// Resolve which feedback sections to show for a given feedback_type value,
// falling back to legacy boolean-only track_dose_feedback semantics (pain-only).
const feedbackSectionsFor = (feedbackType) => ({
  pain: feedbackType === 'pain' || feedbackType === 'both',
  mood: feedbackType === 'mood' || feedbackType === 'both',
});

// ── Free-log modal (no scheduled slots — interval/PRN meds) ──────────────────

const freeLogModal   = document.querySelector('[data-free-log-modal]');
const freeLogTitle   = document.querySelector('[data-free-log-title]');
const freeLogTimeEl  = document.querySelector('[data-free-log-time]');
const freeLogConfirm = document.querySelector('[data-free-log-confirm]');
let   freeLogState   = { sourceForm: null, trackFeedback: false, feedbackType: 'none' };

const openFreeLogModal = ({ medName, sourceForm, trackFeedback, feedbackType = 'none' }) => {
  if (!freeLogModal) return;
  freeLogState = { sourceForm, trackFeedback, feedbackType };
  if (freeLogTitle) freeLogTitle.textContent = `Log dose for ${medName}`;
  if (freeLogTimeEl) {
    const now = new Date();
    freeLogTimeEl.value = `${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')}`;
  }
  freeLogModal.classList.add('is-open');
  lockBodyScroll();
};

const closeFreeLogModal = () => {
  if (!freeLogModal) return;
  freeLogModal.classList.remove('is-open');
  unlockBodyScroll();
};

document.querySelectorAll('[data-close-free-log]').forEach((btn) =>
  btn.addEventListener('click', closeFreeLogModal)
);
freeLogModal?.addEventListener('click', (e) => {
  if (e.target === freeLogModal) closeFreeLogModal();
});

freeLogConfirm?.addEventListener('click', async () => {
  const { sourceForm, trackFeedback, feedbackType } = freeLogState;
  if (!sourceForm || !freeLogTimeEl) return;
  const takenTime = freeLogTimeEl.value;
  if (!takenTime) { freeLogTimeEl.focus(); return; }

  if (trackFeedback) {
    const today = new Date();
    const dateStr = `${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-${String(today.getDate()).padStart(2,'0')}`;
    closeFreeLogModal();
    openDoseFeedbackModal(
      sourceForm.querySelector('[name="medication_id"]')?.value ?? '',
      dateStr,
      takenTime + ':00',
      feedbackType ?? 'pain',
      false
    );
    return;
  }

  if (freeLogConfirm) { freeLogConfirm.disabled = true; freeLogConfirm.textContent = 'Logging…'; }
  try {
    const fd = new FormData(sourceForm);
    fd.set('scheduled_time', takenTime);
    fd.set('taken_on_time', '1');
    const res  = await fetch('index.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Failed to log dose.');
    closeFreeLogModal();
    window.location.reload();
  } catch (err) {
    alert(err.message ?? 'Something went wrong.');
    if (freeLogConfirm) { freeLogConfirm.disabled = false; freeLogConfirm.textContent = 'Log dose'; }
  }
});

// ── Slot picker modal ─────────────────────────────────────────────────────────

const slotPickerModal   = document.querySelector('[data-slot-picker-modal]');
const slotPickerTitle   = document.querySelector('[data-slot-picker-title]');
const slotPickerList    = document.querySelector('[data-slot-picker-list]');
const slotFreeTime      = document.querySelector('[data-slot-free-time]');
const slotFreeTimeInput = document.querySelector('[data-slot-free-time-input]');

// ── Required doses modal ──────────────────────────────────────────────────────

const requiredDosesModal = document.querySelector('[data-required-doses-modal]');

const openRequiredDosesModal = () => {
  if (!requiredDosesModal) return;
  requiredDosesModal.classList.add('is-open');
  lockBodyScroll();
};
const closeRequiredDosesModal = () => {
  if (!requiredDosesModal) return;
  requiredDosesModal.classList.remove('is-open');
  unlockBodyScroll();
};

document.querySelector('[data-open-required-doses-modal]')
  ?.addEventListener('click', openRequiredDosesModal);
document.querySelectorAll('[data-close-required-doses-modal]')
  .forEach((btn) => btn.addEventListener('click', closeRequiredDosesModal));
requiredDosesModal?.addEventListener('click', (e) => {
  if (e.target === requiredDosesModal) closeRequiredDosesModal();
});

// ── Missed dose modal ─────────────────────────────────────────────────────────

const missedDoseModal        = document.querySelector('[data-missed-dose-modal]');
const missedDoseTitle        = document.querySelector('[data-missed-dose-title]');
const missedDoseMedId        = document.querySelector('[data-missed-dose-med-id]');
const missedDoseDate         = document.querySelector('[data-missed-dose-date]');
const missedDoseSchedTime    = document.querySelector('[data-missed-dose-sched-time]');
const missedDoseActualTime   = document.querySelector('[data-missed-dose-actual-time]');
const missedDoseForm         = document.querySelector('[data-missed-dose-form]');
const missedDosePainSection  = document.querySelector('[data-missed-dose-pain-section]');
const missedDosePainLevelEl  = document.querySelector('[data-missed-dose-pain-level]');
const missedDoseMoodSection  = document.querySelector('[data-missed-dose-mood-section]');
const missedDoseMoodLevelEl  = document.querySelector('[data-missed-dose-mood-level]');
const missedDoseNoteSection  = document.querySelector('[data-missed-dose-note-section]');
const missedDoseNoteHidden   = document.querySelector('[data-missed-dose-note-hidden]');
const missedDoseNoteText     = document.querySelector('[data-missed-dose-note-text]');
let   missedDosePainMode     = false;
const slotLateQuestion  = document.querySelector('[data-slot-late-question]');
const slotPickerConfirm = document.querySelector('[data-slot-picker-confirm]');

let slotPickerState = { medicationId: null, selectedSlot: null, graceMinutes: 30, trackFeedback: false, feedbackType: 'none', sourceForm: null, today: '' };

const slotTo12h = (hhmm) => {
  const [h, m] = hhmm.split(':').map(Number);
  const ampm = h >= 12 ? 'PM' : 'AM';
  return `${h % 12 || 12}:${String(m).padStart(2, '0')} ${ampm}`;
};

const openSlotPickerModal = ({ medicationId, medName, sourceForm, slots, graceMinutes, trackFeedback, feedbackType = 'none' }) => {
  if (!slotPickerModal) return;
  const now = new Date();
  const nowMinutes = now.getHours() * 60 + now.getMinutes();
  const pad = (n) => String(n).padStart(2, '0');
  const today = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;

  slotPickerState = { medicationId, selectedSlot: null, graceMinutes, trackFeedback, feedbackType, sourceForm, today };

  if (slotPickerTitle) slotPickerTitle.textContent = `Log dose for ${medName}`;
  if (slotPickerConfirm) slotPickerConfirm.disabled = true;
  if (slotLateQuestion) slotLateQuestion.hidden = true;

  if (slotPickerList) {
    slotPickerList.innerHTML = '';
    slots.forEach((slot) => {
      const [h, m] = slot.time.split(':').map(Number);
      const slotMinutes = h * 60 + m;
      const isLogged   = slot.status === 'taken' || slot.status === 'skipped';
      const isOverdue  = !isLogged && slotMinutes < nowMinutes && (nowMinutes - slotMinutes) > graceMinutes;
      const isUpcoming = slotMinutes > nowMinutes;

      const row = document.createElement('label');
      row.className = 'slot-picker-row' + (isLogged ? ' slot-picker-row--logged' : '');

      const radio = document.createElement('input');
      radio.type = 'radio';
      radio.name = 'slot_pick';
      radio.value = slot.time;
      radio.disabled = isLogged;

      radio.addEventListener('change', () => {
        slotPickerState.selectedSlot = slot.time;
        if (slotPickerConfirm) slotPickerConfirm.disabled = false;
        if (slotLateQuestion) {
          slotLateQuestion.hidden = !isOverdue || trackFeedback;
          const onTimeRadio = slotLateQuestion.querySelector('input[value="on_time"]');
          if (onTimeRadio) onTimeRadio.checked = true;
        }
      });

      let badgeClass, badgeText;
      if      (slot.status === 'taken')   { badgeClass = 'taken';    badgeText = '✓ Taken'; }
      else if (slot.status === 'skipped') { badgeClass = 'skipped';  badgeText = 'Skipped'; }
      else if (slot.status === 'missed')  { badgeClass = 'missed';   badgeText = 'Missed'; }
      else if (isOverdue)                 { badgeClass = 'overdue';  badgeText = 'Overdue'; }
      else if (isUpcoming)                { badgeClass = 'upcoming'; badgeText = 'Upcoming'; }
      else                                { badgeClass = 'upcoming'; badgeText = 'Due now'; }

      const timeSpan  = document.createElement('span');
      timeSpan.className = 'slot-time';
      timeSpan.textContent = slotTo12h(slot.time);

      const badge = document.createElement('span');
      badge.className = `slot-badge slot-badge--${badgeClass}`;
      badge.textContent = badgeText;

      row.append(radio, timeSpan, badge);
      slotPickerList.appendChild(row);
    });

    // If all slots are already logged, show a free-time input as a fallback
    const allLogged = slots.every((s) => s.status === 'taken' || s.status === 'skipped');
    if (slotFreeTime) {
      slotFreeTime.hidden = !allLogged;
      if (allLogged && slotFreeTimeInput) {
        const now = new Date();
        slotFreeTimeInput.value = `${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')}`;
        if (slotPickerConfirm) slotPickerConfirm.disabled = false;
      }
    }
  }

  slotPickerModal.classList.add('is-open');
  lockBodyScroll();
};

const closeSlotPickerModal = () => {
  if (!slotPickerModal) return;
  slotPickerModal.classList.remove('is-open');
  unlockBodyScroll();
};

document.querySelectorAll('[data-close-slot-picker]').forEach((btn) =>
  btn.addEventListener('click', closeSlotPickerModal)
);
slotPickerModal?.addEventListener('click', (e) => {
  if (e.target === slotPickerModal) closeSlotPickerModal();
});

slotPickerConfirm?.addEventListener('click', async () => {
  const { medicationId, selectedSlot, trackFeedback, feedbackType, sourceForm, today, graceMinutes } = slotPickerState;

  // When free-time section is visible (all slots logged), use its time input instead
  const usingFreeTime = slotFreeTime && !slotFreeTime.hidden;
  if (usingFreeTime) {
    const freeTime = slotFreeTimeInput?.value;
    if (!freeTime) { slotFreeTimeInput?.focus(); return; }
    if (slotPickerConfirm) { slotPickerConfirm.disabled = true; slotPickerConfirm.textContent = 'Logging…'; }
    try {
      const fd = new FormData(sourceForm);
      fd.set('scheduled_time', freeTime);
      fd.set('taken_on_time', '1');
      const res  = await fetch('index.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed to log dose.');
      closeSlotPickerModal();
      window.location.reload();
    } catch (err) {
      alert(err.message ?? 'Something went wrong.');
      if (slotPickerConfirm) { slotPickerConfirm.disabled = false; slotPickerConfirm.textContent = 'Log dose'; }
    }
    return;
  }

  if (!selectedSlot) return;

  const slotTooEarly = (() => {
    const [h, m]   = selectedSlot.split(':').map(Number);
    const [y, mo, d] = today.split('-').map(Number);
    const scheduled  = new Date(y, mo - 1, d, h, m, 0);
    const earliest   = new Date(scheduled.getTime() - graceMinutes * 60000);
    return Date.now() < earliest.getTime();
  })();

  if (slotTooEarly) {
    const label = selectedSlot.substring(0, 5);
    alert(`This dose isn't scheduled until ${slotTo12h(label)}. You can log it up to ${graceMinutes} minutes early.`);
    return;
  }

  if (trackFeedback) {
    closeSlotPickerModal();
    openDoseFeedbackModal(medicationId, today, selectedSlot + ':00', feedbackType ?? 'pain', false);
    return;
  }

  const isOverdueSlot = slotLateQuestion && !slotLateQuestion.hidden;
  const takenOnTime   = isOverdueSlot
    ? (slotLateQuestion.querySelector('input[name="slot_timing"]:checked')?.value === 'on_time')
    : true;

  if (slotPickerConfirm) { slotPickerConfirm.disabled = true; slotPickerConfirm.textContent = 'Logging…'; }

  try {
    const fd = new FormData(sourceForm);
    fd.set('scheduled_time', selectedSlot);
    fd.set('taken_on_time', takenOnTime ? '1' : '0');
    const res  = await fetch('index.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Failed to log dose.');
    closeSlotPickerModal();
    window.location.reload();
  } catch (err) {
    alert(err.message ?? 'Something went wrong.');
    if (slotPickerConfirm) { slotPickerConfirm.disabled = false; slotPickerConfirm.textContent = 'Log dose'; }
  }
});

const openMedicationModal = () => {
  if (!medicationModal) return;
  closeMedPlanModal();
  medicationModal.classList.add('is-open');
  lockBodyScroll();
};

const closeMedicationModal = () => {
  if (!medicationModal) return;
  if (!medicationModal.classList.contains('is-open')) return;
  if (window.location.search.includes('edit=')) {
    window.location.href = 'index.php?page=medications';
    return;
  }
  medicationModal.classList.remove('is-open');
  unlockBodyScroll();
  if (window.location.search.includes('edit=')) {
    window.history.replaceState({}, '', 'index.php');
  }
};

document.querySelectorAll('[data-open-medication-modal]').forEach((btn) => {
  btn.addEventListener('click', openMedicationModal);
});
closeMedicationModalButton?.addEventListener('click', closeMedicationModal);

const openPostponeModal = (medicationId, scheduledDate, scheduledTime) => {
  if (!postponeModal) return;
  if (postponeMedicationId) postponeMedicationId.value = medicationId;
  if (postponeScheduledDate) postponeScheduledDate.value = scheduledDate;
  if (postponeScheduledTime) postponeScheduledTime.value = scheduledTime;
  postponeModal.classList.add('is-open');
  lockBodyScroll();
};

const closePostponeModal = () => {
  if (!postponeModal) return;
  if (!postponeModal.classList.contains('is-open')) return;
  postponeModal.classList.remove('is-open');
  unlockBodyScroll();
};

document.querySelectorAll('[data-open-postpone-modal]').forEach((button) => {
  button.addEventListener('click', () => {
    const medicationId = button.getAttribute('data-medication-id') ?? '';
    const scheduledDate = button.getAttribute('data-scheduled-date') ?? '';
    const scheduledTime = button.getAttribute('data-scheduled-time') ?? '';
    if (!medicationId || !scheduledDate || !scheduledTime) return;
    openPostponeModal(medicationId, scheduledDate, scheduledTime);
  });
});

closePostponeModalButton?.addEventListener('click', closePostponeModal);

postponeModal?.addEventListener('click', (event) => {
  if (event.target === postponeModal) {
    closePostponeModal();
  }
});

// ── Missed dose modal ─────────────────────────────────────────────────────────

const openMissedDoseModal = ({ medicationId, medicationName, scheduledDate, scheduledTime, trackFeedback = false, feedbackType = 'none', title = null }) => {
  if (!missedDoseModal) return;
  missedDosePainMode = trackFeedback;
  const { pain: showPain, mood: showMood } = feedbackSectionsFor(feedbackType ?? (trackFeedback ? 'pain' : 'none'));
  if (missedDoseTitle) missedDoseTitle.textContent = title ?? `Log missed dose — ${medicationName}`;
  if (missedDoseMedId) missedDoseMedId.value = medicationId;
  if (missedDoseDate) missedDoseDate.value = scheduledDate;
  if (missedDoseSchedTime) missedDoseSchedTime.value = scheduledTime;
  // Pre-fill the time input with the scheduled time, stripping seconds ("HH:MM:SS" → "HH:MM")
  if (missedDoseActualTime) missedDoseActualTime.value = scheduledTime.substring(0, 5);
  // Show/hide pain/mood sections and reset state
  if (missedDosePainSection) missedDosePainSection.hidden = !showPain;
  if (missedDoseMoodSection) missedDoseMoodSection.hidden = !showMood;
  if (missedDoseNoteSection) missedDoseNoteSection.hidden = !trackFeedback;
  if (missedDosePainLevelEl) missedDosePainLevelEl.value = '';
  if (missedDoseMoodLevelEl) missedDoseMoodLevelEl.value = '';
  if (missedDoseNoteText) missedDoseNoteText.value = '';
  missedDoseModal.querySelectorAll('.missed-pain-btn').forEach((b) => b.classList.remove('is-selected'));
  missedDoseModal.querySelectorAll('.missed-mood-btn').forEach((b) => b.classList.remove('is-selected'));
  if (missedDoseNoteHidden) missedDoseNoteHidden.value = trackFeedback ? '' : 'Marked taken (was missed)';
  missedDoseModal.classList.add('is-open');
  lockBodyScroll();
};

const closeMissedDoseModal = () => {
  if (!missedDoseModal) return;
  missedDoseModal.classList.remove('is-open');
  unlockBodyScroll();
};

document.querySelectorAll('[data-close-missed-dose-modal]').forEach((btn) =>
  btn.addEventListener('click', closeMissedDoseModal)
);
missedDoseModal?.addEventListener('click', (e) => {
  if (e.target === missedDoseModal) closeMissedDoseModal();
});

missedDoseModal?.querySelectorAll('.missed-pain-btn').forEach((btn) => {
  btn.addEventListener('click', () => {
    missedDoseModal.querySelectorAll('.missed-pain-btn').forEach((b) => b.classList.remove('is-selected'));
    btn.classList.add('is-selected');
    if (missedDosePainLevelEl) missedDosePainLevelEl.value = btn.dataset.missedPain ?? '';
  });
});

missedDoseModal?.querySelectorAll('.missed-mood-btn').forEach((btn) => {
  btn.addEventListener('click', () => {
    missedDoseModal.querySelectorAll('.missed-mood-btn').forEach((b) => b.classList.remove('is-selected'));
    btn.classList.add('is-selected');
    if (missedDoseMoodLevelEl) missedDoseMoodLevelEl.value = btn.dataset.missedMood ?? '';
  });
});

missedDoseModal?.querySelector('[data-missed-dose-confirm]')?.addEventListener('click', async () => {
  const confirmBtn = missedDoseModal.querySelector('[data-missed-dose-confirm]');
  if (confirmBtn) { confirmBtn.disabled = true; confirmBtn.textContent = 'Logging…'; }
  // When in pain mode, sync the note textarea into the hidden note field
  if (missedDosePainMode && missedDoseNoteText && missedDoseNoteHidden) {
    missedDoseNoteHidden.value = missedDoseNoteText.value;
  }
  try {
    const fd = new FormData(missedDoseForm);
    const res = await fetch('index.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Failed to log dose.');
    closeMissedDoseModal();
    window.location.reload();
  } catch (err) {
    alert(err.message ?? 'Something went wrong.');
    if (confirmBtn) { confirmBtn.disabled = false; confirmBtn.textContent = 'Log dose'; }
  }
});

// ── Log past dose modal ───────────────────────────────────────────────────────

const logPastDoseModal           = document.querySelector('[data-log-past-dose-modal]');
const logPastDoseTitle           = document.querySelector('[data-log-past-dose-title]');
const logPastDoseForm            = document.querySelector('[data-log-past-dose-form]');
const logPastDoseMedId           = document.querySelector('[data-log-past-dose-med-id]');
const logPastDoseDateEl          = document.querySelector('[data-log-past-dose-date]');
const logPastDoseSlotSection     = document.querySelector('[data-log-past-dose-slot-section]');
const logPastDoseSlotList        = document.querySelector('[data-log-past-dose-slot-list]');
const logPastDoseFreeTimeSection = document.querySelector('[data-log-past-dose-free-time-section]');
const logPastDoseFreeTimeInput   = document.querySelector('[data-log-past-dose-free-time]');
const logPastDoseActualTime      = document.querySelector('[data-log-past-dose-actual-time]');
const logPastDosePainSection     = document.querySelector('[data-log-past-dose-pain-section]');
const logPastDosePainLevelEl     = document.querySelector('[data-log-past-dose-pain-level]');
const logPastDoseMoodSection     = document.querySelector('[data-log-past-dose-mood-section]');
const logPastDoseMoodLevelEl     = document.querySelector('[data-log-past-dose-mood-level]');
const logPastDoseNoteEl          = document.querySelector('[data-log-past-dose-note]');
const logPastDoseConfirm         = document.querySelector('[data-log-past-dose-confirm]');

let logPastDoseState = { medicationId: null, hasFixedSlots: false, selectedSlot: null };

const localDateStr = (d) => {
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};

const openLogPastDoseModal = ({ medicationId, medicationName, slots, scheduleMode, trackFeedback, feedbackType = 'none' }) => {
  if (!logPastDoseModal) return;

  logPastDoseForm?.reset();
  logPastDoseState = { medicationId, hasFixedSlots: scheduleMode === 'fixed_times' && slots.length > 0, selectedSlot: null };

  const { pain: showPastPain, mood: showPastMood } = feedbackSectionsFor(feedbackType ?? (trackFeedback ? 'pain' : 'none'));

  if (logPastDoseTitle) logPastDoseTitle.textContent = `Log past dose — ${medicationName}`;
  if (logPastDoseMedId) logPastDoseMedId.value = medicationId;
  if (logPastDosePainLevelEl) logPastDosePainLevelEl.value = '';
  if (logPastDoseMoodLevelEl) logPastDoseMoodLevelEl.value = '';
  if (logPastDoseNoteEl) logPastDoseNoteEl.value = '';
  if (logPastDoseActualTime) logPastDoseActualTime.value = '';
  logPastDoseModal.querySelectorAll('.log-past-dose-pain-btn').forEach((b) => b.classList.remove('is-selected'));
  logPastDoseModal.querySelectorAll('.log-past-dose-mood-btn').forEach((b) => b.classList.remove('is-selected'));
  if (logPastDosePainSection) logPastDosePainSection.hidden = !showPastPain;
  if (logPastDoseMoodSection) logPastDoseMoodSection.hidden = !showPastMood;

  const now = new Date();
  const today = localDateStr(now);
  const yesterday = localDateStr(new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1));
  const minDate = localDateStr(new Date(now.getFullYear(), now.getMonth(), now.getDate() - 30));
  if (logPastDoseDateEl) {
    logPastDoseDateEl.max = today;
    logPastDoseDateEl.min = minDate;
    logPastDoseDateEl.value = yesterday;
  }

  if (logPastDoseConfirm) logPastDoseConfirm.disabled = true;

  if (logPastDoseState.hasFixedSlots) {
    if (logPastDoseSlotSection) logPastDoseSlotSection.hidden = false;
    if (logPastDoseFreeTimeSection) logPastDoseFreeTimeSection.hidden = true;
    if (logPastDoseSlotList) {
      logPastDoseSlotList.innerHTML = '';
      slots.forEach((slot) => {
        const time = typeof slot === 'string' ? slot : slot.time;
        const row = document.createElement('label');
        row.className = 'slot-picker-row';

        const radio = document.createElement('input');
        radio.type = 'radio';
        radio.name = 'slot_pick';
        radio.value = time;
        radio.addEventListener('change', () => {
          logPastDoseState.selectedSlot = time;
          if (logPastDoseConfirm) logPastDoseConfirm.disabled = false;
        });

        const timeSpan = document.createElement('span');
        timeSpan.className = 'slot-time';
        timeSpan.textContent = slotTo12h(time);

        row.append(radio, timeSpan);
        logPastDoseSlotList.appendChild(row);
      });
    }
  } else {
    if (logPastDoseSlotSection) logPastDoseSlotSection.hidden = true;
    if (logPastDoseFreeTimeSection) logPastDoseFreeTimeSection.hidden = false;
  }

  logPastDoseModal.classList.add('is-open');
  lockBodyScroll();
};

const closeLogPastDoseModal = () => {
  if (!logPastDoseModal) return;
  logPastDoseModal.classList.remove('is-open');
  unlockBodyScroll();
};

document.querySelectorAll('[data-open-log-past-dose]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const medicationId = btn.dataset.medicationId ?? '';
    const medicationName = btn.dataset.medicationName ?? 'medication';
    const trackFeedback = btn.dataset.trackDoseFeedback === '1';
    const feedbackType = btn.dataset.feedbackType ?? (trackFeedback ? 'pain' : 'none');
    const scheduleMode = btn.dataset.scheduleMode ?? '';
    let slots = [];
    try { slots = JSON.parse(btn.dataset.slots ?? '[]'); } catch { slots = []; }
    openLogPastDoseModal({ medicationId, medicationName, slots, scheduleMode, trackFeedback, feedbackType });
  });
});

document.querySelectorAll('[data-close-log-past-dose]').forEach((btn) =>
  btn.addEventListener('click', closeLogPastDoseModal)
);
logPastDoseModal?.addEventListener('click', (e) => {
  if (e.target === logPastDoseModal) closeLogPastDoseModal();
});

logPastDoseModal?.querySelectorAll('.log-past-dose-pain-btn').forEach((btn) => {
  btn.addEventListener('click', () => {
    logPastDoseModal.querySelectorAll('.log-past-dose-pain-btn').forEach((b) => b.classList.remove('is-selected'));
    btn.classList.add('is-selected');
    if (logPastDosePainLevelEl) logPastDosePainLevelEl.value = btn.dataset.logPastDosePain ?? '';
  });
});

logPastDoseModal?.querySelectorAll('.log-past-dose-mood-btn').forEach((btn) => {
  btn.addEventListener('click', () => {
    logPastDoseModal.querySelectorAll('.log-past-dose-mood-btn').forEach((b) => b.classList.remove('is-selected'));
    btn.classList.add('is-selected');
    if (logPastDoseMoodLevelEl) logPastDoseMoodLevelEl.value = btn.dataset.logPastDoseMood ?? '';
  });
});

logPastDoseFreeTimeInput?.addEventListener('input', () => {
  if (logPastDoseConfirm) logPastDoseConfirm.disabled = !logPastDoseFreeTimeInput.value;
});

logPastDoseConfirm?.addEventListener('click', async () => {
  if (!logPastDoseForm) return;
  const dateVal = logPastDoseDateEl?.value ?? '';
  if (!dateVal) { logPastDoseDateEl?.focus(); return; }
  if (logPastDoseDateEl && (dateVal > logPastDoseDateEl.max || dateVal < logPastDoseDateEl.min)) {
    alert('Please choose a date within the last 30 days.');
    return;
  }

  const slotValue = logPastDoseState.hasFixedSlots
    ? logPastDoseState.selectedSlot
    : logPastDoseFreeTimeInput?.value;
  if (!slotValue) return;

  if (logPastDoseConfirm) { logPastDoseConfirm.disabled = true; logPastDoseConfirm.textContent = 'Logging…'; }
  try {
    const fd = new FormData(logPastDoseForm);
    fd.set('scheduled_time', slotValue + ':00');
    const res  = await fetch('index.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Failed to log dose.');
    closeLogPastDoseModal();
    window.location.reload();
  } catch (err) {
    alert(err.message ?? 'Something went wrong.');
    if (logPastDoseConfirm) { logPastDoseConfirm.disabled = false; logPastDoseConfirm.textContent = 'Log dose'; }
  }
});

// ── Dose feedback modal ───────────────────────────────────────────────────────

let feedbackAlarmContext = false;
// When Take is pressed in Manage Each mode for a feedback-tracked med, this holds
// the callback to call after the user submits (or skips) the feedback modal.
let manageEachFeedbackMeta = null;

const openDoseFeedbackModal = (medicationId, scheduledDate, scheduledTime, feedbackType = 'pain', fromAlarm = false) => {
  if (!doseFeedbackModal) return;
  feedbackAlarmContext = fromAlarm;
  const { pain: showPain, mood: showMood } = feedbackSectionsFor(feedbackType);
  if (feedbackMedicationIdEl) feedbackMedicationIdEl.value = medicationId;
  if (feedbackScheduledDateEl) feedbackScheduledDateEl.value = scheduledDate;
  if (feedbackScheduledTimeEl) feedbackScheduledTimeEl.value = scheduledTime;
  if (feedbackPainLevelEl) feedbackPainLevelEl.value = '';
  if (feedbackMoodLevelEl) feedbackMoodLevelEl.value = '';
  if (feedbackNoteEl) feedbackNoteEl.value = '';
  if (feedbackCharCounter) feedbackCharCounter.textContent = '[0/250]';
  document.querySelectorAll('.pain-level-btn').forEach((b) => b.classList.remove('is-selected'));
  document.querySelectorAll('.mood-level-btn').forEach((b) => b.classList.remove('is-selected'));
  if (feedbackPainSection) {
    feedbackPainSection.classList.toggle('is-hidden', !showPain);
  }
  if (feedbackMoodSection) {
    feedbackMoodSection.classList.toggle('is-hidden', !showMood);
  }
  doseFeedbackModal.classList.add('is-open');
  lockBodyScroll();
};

const closeDoseFeedbackModal = (cancelQueue = false) => {
  if (!doseFeedbackModal) return;
  if (!doseFeedbackModal.classList.contains('is-open')) return;
  doseFeedbackModal.classList.remove('is-open');
  // Restore alarm overlay if it was lowered for manage-each feedback.
  if (alarmOverlay) alarmOverlay.classList.remove('is-behind-modal');
  if (manageEachFeedbackMeta && cancelQueue) {
    manageEachFeedbackMeta = null;
  }
  if (feedbackQueueProgressEl) feedbackQueueProgressEl.hidden = true;
  if (cancelQueue && feedbackQueueMode) {
    feedbackQueue = [];
    feedbackQueueMode = false;
    unlockBodyScroll();
    window.location.reload();
    return;
  }
  unlockBodyScroll();
};

document.querySelectorAll('.pain-level-btn').forEach((btn) => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.pain-level-btn').forEach((b) => b.classList.remove('is-selected'));
    btn.classList.add('is-selected');
    if (feedbackPainLevelEl) feedbackPainLevelEl.value = btn.dataset.painLevel ?? '';
  });
});

document.querySelectorAll('.mood-level-btn').forEach((btn) => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.mood-level-btn').forEach((b) => b.classList.remove('is-selected'));
    btn.classList.add('is-selected');
    if (feedbackMoodLevelEl) feedbackMoodLevelEl.value = btn.dataset.moodLevel ?? '';
  });
});

const submitFeedbackAsAlarmAction = async () => {
  const note = feedbackNoteEl?.value ?? '';
  const painLevel = feedbackPainLevelEl?.value ?? '';
  const moodLevel = feedbackMoodLevelEl?.value ?? '';
  closeDoseFeedbackModal();
  hideAlarmOverlay();
  await alarmAction('mark_dose', { status: 'taken', note, pain_level: painLevel, mood_level: moodLevel });
};

feedbackForm?.addEventListener('submit', async (event) => {
  if (feedbackQueueMode) {
    event.preventDefault();
    const note = feedbackNoteEl?.value ?? '';
    const painLevel = feedbackPainLevelEl?.value ?? '';
    const moodLevel = feedbackMoodLevelEl?.value ?? '';
    await submitCurrentQueueItem(note, painLevel, moodLevel);
  } else if (manageEachFeedbackMeta) {
    event.preventDefault();
    const note = feedbackNoteEl?.value ?? '';
    const painLevel = feedbackPainLevelEl?.value ?? '';
    const moodLevel = feedbackMoodLevelEl?.value ?? '';
    const meta = manageEachFeedbackMeta;
    manageEachFeedbackMeta = null;
    closeDoseFeedbackModal();
    const medId = feedbackMedicationIdEl?.value ?? '';
    const date = feedbackScheduledDateEl?.value ?? '';
    const time = feedbackScheduledTimeEl?.value ?? '';
    const result = await postDose(medId, date, time, 'taken', note, painLevel, meta.groupId, moodLevel);
    if (result.ok) {
      meta.markDone('Taken ✓');
    } else {
      meta.markDone('Failed ✗');
      alert(result.error || 'Failed to log dose.');
    }
  } else if (feedbackAlarmContext) {
    event.preventDefault();
    await submitFeedbackAsAlarmAction();
  }
});

skipFeedbackBtn?.addEventListener('click', async () => {
  if (feedbackQueueMode) {
    await submitCurrentQueueItem('', '');
  } else if (manageEachFeedbackMeta) {
    const meta = manageEachFeedbackMeta;
    manageEachFeedbackMeta = null;
    const medId = feedbackMedicationIdEl?.value ?? '';
    const date = feedbackScheduledDateEl?.value ?? '';
    const time = feedbackScheduledTimeEl?.value ?? '';
    closeDoseFeedbackModal();
    const result = await postDose(medId, date, time, 'taken', '', '', meta.groupId);
    if (result.ok) {
      meta.markDone('Taken ✓');
    } else {
      meta.markDone('Failed ✗');
      alert(result.error || 'Failed to log dose.');
    }
  } else if (feedbackAlarmContext) {
    closeDoseFeedbackModal();
    await alarmAction('mark_dose', { status: 'taken', note: '' });
  } else {
    if (feedbackMedicationIdEl && feedbackScheduledDateEl && feedbackScheduledTimeEl) {
      if (feedbackPainLevelEl) feedbackPainLevelEl.value = '';
      if (feedbackNoteEl) feedbackNoteEl.value = '';
      feedbackForm?.submit();
    }
  }
});

feedbackNoteEl?.addEventListener('input', () => {
  const len = feedbackNoteEl.value.length;
  if (feedbackCharCounter) feedbackCharCounter.textContent = `[${len}/250]`;
});

closeFeedbackModalButton?.addEventListener('click', () => closeDoseFeedbackModal(true));

doseFeedbackModal?.addEventListener('click', (event) => {
  if (event.target === doseFeedbackModal) {
    closeDoseFeedbackModal(true);
  }
});

// ── Medication actions "more" dropdown (mobile) ───────────────────────────────

document.querySelectorAll('.med-actions-more-trigger').forEach((trigger) => {
  const wrap = trigger.closest('.med-actions-more-wrap');
  if (!wrap) return;
  const dropdown = wrap.querySelector('.med-actions-more-dropdown');
  if (!dropdown) return;

  trigger.addEventListener('click', (e) => {
    e.stopPropagation();
    const isOpen = !dropdown.hidden;
    dropdown.hidden = isOpen;
    trigger.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
  });
});

document.addEventListener('click', () => {
  document.querySelectorAll('.med-actions-more-dropdown').forEach((d) => {
    d.hidden = true;
    const trigger = d.closest('.med-actions-more-wrap')?.querySelector('.med-actions-more-trigger');
    trigger?.setAttribute('aria-expanded', 'false');
  });
});

document.querySelectorAll('[data-take-dose]').forEach((btn) => {
  btn.addEventListener('click', (event) => {
    if (btn.disabled) return;

    const doseStatus    = btn.dataset.doseStatus ?? '';
    const scheduledDate = btn.dataset.scheduledDate ?? '';
    const scheduledTime = btn.dataset.scheduledTime ?? '';
    const graceMinutes  = parseInt(btn.dataset.graceMinutes || '30', 10);

    const [schedY, schedMo, schedD] = scheduledDate ? scheduledDate.split('-').map(Number) : [0,0,0];
    const [schedH, schedM]          = scheduledTime ? scheduledTime.split(':').map(Number) : [0,0];
    const scheduledMs = scheduledDate && scheduledTime
      ? new Date(schedY, schedMo - 1, schedD, schedH, schedM, 0).getTime()
      : null;

    // If the dose was snoozed and the snooze has now expired, allow taking it
    // regardless of how far before the scheduled time we are.
    // The DB emits YYYY-MM-DD HH:MM:SS; replace the space with 'T' so
    // Safari/iOS (which reject the non-ISO form) parses it correctly.
    const postponedUntil = btn.dataset.postponedUntil ?? '';
    const snoozeExpired = (() => {
      if (postponedUntil === '') return false;
      const t = new Date(postponedUntil.replace(' ', 'T')).getTime();
      return !isNaN(t) && Date.now() >= t;
    })();

    const isEffectivelyMissed = (() => {
      if (doseStatus === 'missed') return true;
      if (scheduledMs === null) return false;
      return Date.now() > scheduledMs + graceMinutes * 60000;
    })();

    const isPastScheduledTime = scheduledMs !== null && Date.now() > scheduledMs;

    const isTooEarly = (() => {
      if (snoozeExpired) return false; // snooze fired, honour it regardless of schedule
      if (scheduledMs === null) return false;
      return Date.now() < scheduledMs - graceMinutes * 60000;
    })();

    if (isTooEarly) {
      event.preventDefault();
      const label = scheduledTime.substring(0, 5);
      alert(`This dose isn't scheduled until ${slotTo12h(label)}. You can log it up to ${graceMinutes} minutes early.`);
      return;
    }

    if (isEffectivelyMissed) {
      event.preventDefault();
      openMissedDoseModal({
        medicationId:   btn.dataset.medicationId ?? '',
        medicationName: btn.dataset.medicationName ?? 'medication',
        scheduledDate,
        scheduledTime,
        trackFeedback:  btn.dataset.trackDoseFeedback === '1',
        feedbackType:   btn.dataset.feedbackType ?? (btn.dataset.trackDoseFeedback === '1' ? 'pain' : 'none'),
      });
      return;
    }

    if (isPastScheduledTime) {
      event.preventDefault();
      openMissedDoseModal({
        medicationId:   btn.dataset.medicationId ?? '',
        medicationName: btn.dataset.medicationName ?? 'medication',
        scheduledDate,
        scheduledTime,
        trackFeedback:  btn.dataset.trackDoseFeedback === '1',
        feedbackType:   btn.dataset.feedbackType ?? (btn.dataset.trackDoseFeedback === '1' ? 'pain' : 'none'),
        title: `Log dose — ${btn.dataset.medicationName ?? 'medication'}`,
      });
      return;
    }

    if (btn.dataset.trackDoseFeedback === '1') {
      event.preventDefault();
      const medicationId = btn.dataset.medicationId ?? '';
      if (!medicationId || !scheduledDate || !scheduledTime) return;
      openDoseFeedbackModal(medicationId, scheduledDate, scheduledTime, btn.dataset.feedbackType ?? 'pain', false);
    }
  });
});

document.querySelectorAll('[data-log-dose-now-form]').forEach((form) => {
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const btn = form.querySelector('[data-log-dose-now]');
    if (!btn) return;
    let slots;
    try {
      slots = JSON.parse(btn.dataset.slots || '[]');
    } catch {
      slots = [];
    }
    const medName      = btn.dataset.medicationName ?? 'medication';
    const trackFeedback = btn.dataset.trackDoseFeedback === '1';
    const feedbackType = btn.dataset.feedbackType ?? (trackFeedback ? 'pain' : 'none');
    if (slots.length === 0) {
      openFreeLogModal({ medName, sourceForm: form, trackFeedback, feedbackType });
      return;
    }
    openSlotPickerModal({
      medicationId:  btn.dataset.medicationId ?? '',
      medName,
      sourceForm:    form,
      slots,
      graceMinutes:  parseInt(btn.dataset.graceMinutes || '30', 10),
      trackFeedback,
      feedbackType,
    });
  });
});

document.querySelectorAll('.modal-edit-link').forEach((link) => {
  link.addEventListener('click', () => {
    openMedicationModal();
  });
});

medicationModal?.addEventListener('click', (event) => {
  if (event.target === medicationModal) {
    closeMedicationModal();
  }
});

const medPlanModal = document.querySelector('#med-plan-modal');
const openMedPlanModalBtns = document.querySelectorAll('[data-open-med-plan-modal]');
const closeMedPlanModalBtn = document.querySelector('[data-close-med-plan-modal]');

const openMedPlanModal = () => {
  if (!medPlanModal) return;
  medPlanModal.hidden = false;
  lockBodyScroll();
};

const closeMedPlanModal = () => {
  if (!medPlanModal) return;
  if (medPlanModal.hidden) return;
  medPlanModal.hidden = true;
  unlockBodyScroll();
};

if (new URLSearchParams(window.location.search).get('open') === 'add') {
  openMedicationModal();
  history.replaceState(null, '', 'index.php?page=medications');
}

openMedPlanModalBtns.forEach((btn) => btn.addEventListener('click', openMedPlanModal));
closeMedPlanModalBtn?.addEventListener('click', closeMedPlanModal);

medPlanModal?.addEventListener('click', (event) => {
  if (event.target === medPlanModal) closeMedPlanModal();
});

// ── Med plan modal: deactivate / activate AJAX ────────────────────────────────

document.addEventListener('submit', async (e) => {
  const form = e.target.closest('form');
  if (!form) return;
  const action = form.querySelector('input[name="action"]')?.value;
  if (action !== 'deactivate_medication' && action !== 'activate_medication') return;

  // ── Discontinue (deactivate) ────────────────────────────────────────────────
  if (action === 'deactivate_medication') {
    if (e.defaultPrevented) return;
    e.preventDefault();
    const medId = form.querySelector('input[name="medication_id"]')?.value ?? '';
    const reason = form.querySelector('input[name="reason"]:checked')?.value ?? '';
    const comment = form.querySelector('textarea[name="comment"]')?.value ?? '';
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    try {
      const params = new URLSearchParams();
      params.set('action', 'deactivate_medication');
      params.set('csrf_token', getCsrfToken());
      params.set('json_response', '1');
      params.set('medication_id', medId);
      params.set('reason', reason);
      params.set('comment', comment);

      const res = await fetch('index.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString(),
      });
      const json = await res.json();
      if (!json.ok) throw new Error('Failed to discontinue medication.');

      const activeCard = document
        .querySelector(`[data-open-discontinue-modal][data-medication-id="${CSS.escape(medId)}"]`)
        ?.closest('.medication-row-plan');
      activeCard?.remove();

      // Update active tab count
      const n = document.querySelectorAll('[data-plan-panel="active"] .medication-row-plan').length;
      document.querySelectorAll('[data-plan-tab="active"]').forEach((t) => { t.textContent = `Active (${n})`; });

      // Add the server-rendered row to the inactive panel
      const inactiveList = document.querySelector('.inactive-list');
      inactiveList?.querySelector('.empty-state')?.remove();
      if (inactiveList && json.inactive_row_html) {
        inactiveList.insertAdjacentHTML('beforeend', json.inactive_row_html);
      }

      const ni = document.querySelectorAll('[data-plan-panel="inactive"] .medication-row').length;
      document.querySelectorAll('[data-plan-tab="inactive"]').forEach((t) => { t.textContent = `Inactive (${ni})`; });

      closeDiscontinueModal();
      form.reset();
    } catch (err) {
      alert(err.message ?? 'Something went wrong.');
    } finally {
      if (submitBtn) submitBtn.disabled = false;
    }
    return;
  }

  // ── Activate ────────────────────────────────────────────────────────────────
  if (action === 'activate_medication') {
    e.preventDefault();
    const medId = form.querySelector('input[name="medication_id"]')?.value ?? '';
    const inactiveRow = form.closest('.medication-row');
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    try {
      const params = new URLSearchParams();
      params.set('action', 'activate_medication');
      params.set('csrf_token', getCsrfToken());
      params.set('json_response', '1');
      params.set('medication_id', medId);

      const res = await fetch('index.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString(),
      });
      const json = await res.json();
      if (!json.ok) throw new Error('Failed to activate medication.');

      inactiveRow?.remove();

      const ni2 = document.querySelectorAll('[data-plan-panel="inactive"] .medication-row').length;
      document.querySelectorAll('[data-plan-tab="inactive"]').forEach((t) => { t.textContent = `Inactive (${ni2})`; });
      if (ni2 === 0) {
        document.querySelector('.inactive-list')?.insertAdjacentHTML('afterbegin', '<div class="empty-state"><p>No inactive medications.</p></div>');
      }

      // On the medications page just reload; in the modal reopen on the active tab
      if (!document.querySelector('.medications-page')) {
        sessionStorage.setItem('medPlanReopen', 'active');
      }
      window.location.reload();
    } catch (err) {
      alert(err.message ?? 'Something went wrong.');
      if (submitBtn) submitBtn.disabled = false;
    }
  }
});


document.addEventListener('keydown', (event) => {
  if (event.key !== 'Escape') return;
  // Priority cascade: lightbox > refill history > refill modal > detail modal > plan modal > notif panel
  if (imageLightbox?.classList.contains('is-open')) {
    closeImageLightbox();
  } else if (refillHistoryModal?.classList.contains('is-open')) {
    closeRefillHistoryModal();
  } else if (refillModal?.classList.contains('is-open')) {
    closeRefillModal();
  } else if (adjustQtyModal?.classList.contains('is-open')) {
    closeAdjustQtyModal();
  } else if (discontinueModal?.classList.contains('is-open')) {
    closeDiscontinueModal();
  } else if (medDetailModal?.classList.contains('is-open')) {
    closeMedDetailModal();
  } else if (medPlanModal && !medPlanModal.hidden) {
    closeMedPlanModal();
  } else if (notifPanel && !notifPanel.hidden) {
    closeNotifPanel();
  }
});

const planTabs = document.querySelectorAll('[data-plan-tab]');

const medPlanHeaderActions = document.querySelectorAll('[data-med-plan-action]');

const setPlanTab = (target) => {
  planTabs.forEach((tab) => {
    const isSelected = tab.getAttribute('data-plan-tab') === target;
    tab.classList.toggle('is-active', isSelected);
    tab.setAttribute('aria-selected', isSelected ? 'true' : 'false');
  });
  document.querySelectorAll('[data-plan-panel]').forEach((panel) => {
    panel.hidden = panel.dataset.planPanel !== target;
  });
  medPlanHeaderActions.forEach((btn) => {
    const action = btn.dataset.medPlanAction;
    if (action === 'groups') btn.hidden = target !== 'groups';
    else if (action === 'medication') btn.hidden = target === 'groups';
  });
};

planTabs.forEach((tab) => {
  tab.addEventListener('click', () => {
    const target = tab.getAttribute('data-plan-tab');
    if (!target) return;
    setPlanTab(target);
  });
});

const medPlanReopenTab = sessionStorage.getItem('medPlanReopen');
if (medPlanReopenTab) {
  sessionStorage.removeItem('medPlanReopen');
  openMedPlanModal();
  setPlanTab(medPlanReopenTab);
} else {
  setPlanTab('active');
}

// ── Pain level trend graph ────────────────────────────────────────────────────

const painGraphModal = document.querySelector('[data-pain-graph-modal]');
const painGraphTitle = document.querySelector('[data-pain-graph-title]');
const painGraphBody = document.querySelector('[data-pain-graph-body]');
const painGraphEmpty = document.querySelector('[data-pain-graph-empty]');
const painGraphDayBanner = document.querySelector('[data-pain-graph-day-banner]');
const painGraphDayLabel = document.querySelector('[data-pain-graph-day-label]');

let painGraphMedId = null;
let painGraphDays = 7;
let painGraphDate = null;     // set when a multi-day point is clicked (single-day view)
let painGraphPrevDays = 7;    // range to restore when leaving the single-day view

const openPainGraphModal = (medicationId, medicationName, medicationDose = '') => {
  if (!painGraphModal) return;
  painGraphMedId = medicationId;
  painGraphDays = 0;
  painGraphDate = null;
  painGraphPrevDays = 7;
  const titleBase = medicationDose ? `${medicationName} ${medicationDose}` : medicationName;
  if (painGraphTitle) painGraphTitle.textContent = titleBase + ' — Pain Trend';
  painGraphModal.querySelectorAll('.range-tab').forEach((t) => {
    t.classList.toggle('is-active', t.dataset.range === '0');
  });
  closeMedPlanModal();
  painGraphModal.classList.add('is-open');
  lockBodyScroll();
  loadPainGraph();
};

const closePainGraphModal = () => {
  if (!painGraphModal) return;
  if (!painGraphModal.classList.contains('is-open')) return;
  painGraphModal.classList.remove('is-open');
  unlockBodyScroll();
};

const painLevelColor = (level) => {
  if (level <= 3) return '#2a9d49';
  if (level <= 6) return '#d97706';
  if (level <= 8) return '#e05b30';
  return '#c9213c';
};

const escSvg = (str) => String(str)
  .replace(/&/g, '&amp;')
  .replace(/</g, '&lt;')
  .replace(/>/g, '&gt;')
  .replace(/"/g, '&quot;')
  .replace(/'/g, '&#39;');

const formatGraphDay = (d) =>
  new Date(`${d}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });

const renderPainChart = (container, data) => {
  const W = 500, H = 200;
  const ml = 44, mr = 12, mt = 12, mb = 36;
  const chartW = W - ml - mr;
  const chartH = H - mt - mb;

  const yMin = 1, yMax = 10;
  const yScale = (v) => mt + chartH - ((v - yMin) / (yMax - yMin)) * chartH;

  const dates = data.map((d) => d.date);
  const uniqueDates = [...new Set(dates)];
  const n = uniqueDates.length;
  const xScale = (i) => ml + (n <= 1 ? chartW / 2 : (i / (n - 1)) * chartW);

  // Group by date (average if multiple doses same day)
  const byDate = uniqueDates.map((date) => {
    const pts = data.filter((d) => d.date === date);
    const avg = pts.reduce((s, d) => s + parseInt(d.pain_level, 10), 0) / pts.length;
    return { date, level: avg, pts };
  });

  // Grid lines + Y labels
  let gridLines = '';
  [1, 3, 5, 7, 10].forEach((v) => {
    const y = yScale(v).toFixed(1);
    gridLines += `<line x1="${ml}" y1="${y}" x2="${W - mr}" y2="${y}" stroke="#e2e8f0" stroke-width="1"/>`;
    gridLines += `<text x="${ml - 4}" y="${y}" text-anchor="end" dominant-baseline="middle" font-size="9" fill="#94a3b8">${v}</text>`;
  });

  // X-axis date labels — show at most 6, evenly spaced
  let xLabels = '';
  const step = Math.max(1, Math.ceil(n / 6));
  byDate.forEach(({ date }, i) => {
    if (i % step !== 0 && i !== n - 1) return;
    const x = xScale(i).toFixed(1);
    const label = date.slice(5); // MM-DD
    xLabels += `<text x="${x}" y="${H - mb + 14}" text-anchor="middle" font-size="9" fill="#94a3b8">${label}</text>`;
  });

  // Polyline
  const points = byDate.map(({ level }, i) => `${xScale(i).toFixed(1)},${yScale(level).toFixed(1)}`).join(' ');

  // Circles with tooltip; clicking one drills into that day's levels
  let circles = '';
  byDate.forEach(({ date, level, pts }, i) => {
    const x = xScale(i).toFixed(1);
    const y = yScale(level).toFixed(1);
    const color = painLevelColor(Math.round(level));
    const allStandalone = pts.every((p) => p.source === 'standalone');
    const fill   = allStandalone ? '#fff'   : color;
    const stroke = allStandalone ? color    : '#fff';
    const sw     = allStandalone ? '2.5'    : '1.5';
    const tipLines = pts.map((p) => `${escSvg(p.time.slice(0, 5))}: Pain ${escSvg(p.pain_level)}/10${p.note ? ' — ' + escSvg(p.note) : ''}${p.source === 'standalone' ? ' (standalone)' : ''}`).join('&#10;');
    circles += `<circle cx="${x}" cy="${y}" r="20" fill="transparent" data-date="${escSvg(date)}" style="cursor:pointer"/>`;
    circles += `<circle cx="${x}" cy="${y}" r="5" fill="${fill}" stroke="${stroke}" stroke-width="${sw}" data-date="${escSvg(date)}" style="cursor:pointer"><title>${escSvg(date)}&#10;${tipLines}&#10;Click to view this day</title></circle>`;
  });

  const yAxisCY = (mt + chartH / 2).toFixed(1);
  container.innerHTML = `<svg viewBox="0 0 ${W} ${H}" width="100%" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Pain level trend chart">
    <text transform="rotate(-90)" x="-${yAxisCY}" y="11" text-anchor="middle" font-size="8.5" fill="#94a3b8">Pain Level</text>
    ${gridLines}
    <line x1="${ml}" y1="${mt}" x2="${ml}" y2="${H - mb}" stroke="#cbd5e1" stroke-width="1"/>
    <line x1="${ml}" y1="${H - mb}" x2="${W - mr}" y2="${H - mb}" stroke="#cbd5e1" stroke-width="1"/>
    ${xLabels}
    <polyline points="${points}" fill="none" stroke="#6b7a96" stroke-width="2" stroke-linejoin="round"/>
    ${circles}
  </svg>`;
};

const renderPainChartToday = (container, data) => {
  const W = 500, H = 200;
  const ml = 44, mr = 12, mt = 12, mb = 36;
  const chartW = W - ml - mr;
  const chartH = H - mt - mb;

  const yMin = 1, yMax = 10;
  const yScale = (v) => mt + chartH - ((v - yMin) / (yMax - yMin)) * chartH;

  const n = data.length;
  const xScale = (i) => ml + (n <= 1 ? chartW / 2 : (i / (n - 1)) * chartW);

  let gridLines = '';
  [1, 3, 5, 7, 10].forEach((v) => {
    const y = yScale(v).toFixed(1);
    gridLines += `<line x1="${ml}" y1="${y}" x2="${W - mr}" y2="${y}" stroke="#e2e8f0" stroke-width="1"/>`;
    gridLines += `<text x="${ml - 4}" y="${y}" text-anchor="end" dominant-baseline="middle" font-size="9" fill="#94a3b8">${v}</text>`;
  });

  let xLabels = '';
  const step = Math.max(1, Math.ceil(n / 6));
  data.forEach((d, i) => {
    if (i % step !== 0 && i !== n - 1) return;
    const x = xScale(i).toFixed(1);
    xLabels += `<text x="${x}" y="${H - mb + 14}" text-anchor="middle" font-size="9" fill="#94a3b8">${escSvg(d.time.slice(0, 5))}</text>`;
  });

  const points = data.map((d, i) => `${xScale(i).toFixed(1)},${yScale(parseInt(d.pain_level, 10)).toFixed(1)}`).join(' ');

  let circles = '';
  data.forEach((d, i) => {
    const x = xScale(i).toFixed(1);
    const y = yScale(parseInt(d.pain_level, 10)).toFixed(1);
    const color = painLevelColor(parseInt(d.pain_level, 10));
    const isStandalone = d.source === 'standalone';
    const fill   = isStandalone ? '#fff'  : color;
    const stroke = isStandalone ? color   : '#fff';
    const sw     = isStandalone ? '2.5'   : '1.5';
    const tip = `${escSvg(d.time.slice(0, 5))}: Pain ${escSvg(d.pain_level)}/10${d.note ? ' — ' + escSvg(d.note) : ''}${isStandalone ? ' (standalone)' : ''}`;
    const entryId = d.source && d.id ? `${d.source}-${d.id}` : null;
    const entryAttr = entryId ? ` data-entry-id="${escSvg(entryId)}"` : '';
    circles += `<circle cx="${x}" cy="${y}" r="20" fill="transparent"${entryAttr} style="cursor:pointer"/>`;
    circles += `<circle cx="${x}" cy="${y}" r="5" fill="${fill}" stroke="${stroke}" stroke-width="${sw}"${entryAttr}><title>${tip}</title></circle>`;
  });

  const yAxisCYToday = (mt + chartH / 2).toFixed(1);
  container.innerHTML = `<svg viewBox="0 0 ${W} ${H}" width="100%" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Pain level trend chart">
    <text transform="rotate(-90)" x="-${yAxisCYToday}" y="11" text-anchor="middle" font-size="8.5" fill="#94a3b8">Pain Level</text>
    ${gridLines}
    <line x1="${ml}" y1="${mt}" x2="${ml}" y2="${H - mb}" stroke="#cbd5e1" stroke-width="1"/>
    <line x1="${ml}" y1="${H - mb}" x2="${W - mr}" y2="${H - mb}" stroke="#cbd5e1" stroke-width="1"/>
    ${xLabels}
    <polyline points="${points}" fill="none" stroke="#6b7a96" stroke-width="2" stroke-linejoin="round"/>
    ${circles}
  </svg>`;
};

// ── Mood level chart (inverted color scale: low = red, high = green) ─────────

const moodLevelColor = (level) => {
  if (level <= 3) return '#c9213c';
  if (level <= 6) return '#d97706';
  if (level <= 8) return '#8bb04a';
  return '#2a9d49';
};

// Build a smooth SVG path `d` string through a series of points using cubic
// bezier segments (Catmull-Rom-ish midpoint control points), instead of the
// straight-line polylines used by the pain chart.
const buildSmoothPathD = (points) => {
  if (points.length === 0) return '';
  if (points.length === 1) return `M ${points[0].x},${points[0].y}`;
  let d = `M ${points[0].x.toFixed(1)},${points[0].y.toFixed(1)}`;
  for (let i = 0; i < points.length - 1; i++) {
    const p0 = points[i === 0 ? i : i - 1];
    const p1 = points[i];
    const p2 = points[i + 1];
    const p3 = points[i + 2 < points.length ? i + 2 : i + 1];
    const cp1x = p1.x + (p2.x - p0.x) / 6;
    const cp1y = p1.y + (p2.y - p0.y) / 6;
    const cp2x = p2.x - (p3.x - p1.x) / 6;
    const cp2y = p2.y - (p3.y - p1.y) / 6;
    d += ` C ${cp1x.toFixed(1)},${cp1y.toFixed(1)} ${cp2x.toFixed(1)},${cp2y.toFixed(1)} ${p2.x.toFixed(1)},${p2.y.toFixed(1)}`;
  }
  return d;
};

const getMoodChartScheme = () =>
  document.body.dataset.moodChartScheme === 'teal' ? 'teal' : 'classic';

const renderMoodChartCommon = (container, points, gridLines, xLabels, W, H, ml, mr, mt, mb) => {
  const gradId = `mood-gradient-${Math.random().toString(36).slice(2, 9)}`;
  const curveD = buildSmoothPathD(points);
  const baseline = H - mb;
  const areaD = points.length > 0
    ? `${curveD} L ${points[points.length - 1].x.toFixed(1)},${baseline} L ${points[0].x.toFixed(1)},${baseline} Z`
    : '';

  let circles = '';
  points.forEach((p) => {
    const isStandalone = !!p.isStandalone;
    const fill   = isStandalone ? '#fff'   : p.color;
    const stroke = isStandalone ? p.color  : '#fff';
    const sw     = isStandalone ? '2.5'    : '1.5';
    const dateAttr  = p.date    ? ` data-date="${escSvg(p.date)}"` : '';
    const entryAttr = p.entryId ? ` data-entry-id="${escSvg(p.entryId)}"` : '';
    const cursor = (p.date || p.entryId) ? ' style="cursor:pointer"' : '';
    circles += `<circle cx="${p.x.toFixed(1)}" cy="${p.y.toFixed(1)}" r="20" fill="transparent"${dateAttr}${entryAttr}${cursor}/>`;
    circles += `<circle cx="${p.x.toFixed(1)}" cy="${p.y.toFixed(1)}" r="5" fill="${fill}" stroke="${stroke}" stroke-width="${sw}"${dateAttr}${entryAttr}><title>${p.tip}</title></circle>`;
  });

  // Two selectable schemes: classic red-to-green, or teal matching the PDF
  // report (darker teal = higher mood, lighter teal = lower mood).
  const teal = getMoodChartScheme() === 'teal';
  const gradientStops = teal
    ? `<stop offset="0%" stop-color="#028AA9"/>
        <stop offset="100%" stop-color="#BFE4EE"/>`
    : `<stop offset="0%" stop-color="#2a9d49"/>
        <stop offset="55%" stop-color="#d97706"/>
        <stop offset="100%" stop-color="#c9213c"/>`;
  const strokeColor = teal ? '#028AA9' : '#6b7a96';

  const yAxisCY = (mt + (H - mt - mb) / 2).toFixed(1);
  container.innerHTML = `<svg viewBox="0 0 ${W} ${H}" width="100%" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Mood level trend chart">
    <defs>
      <linearGradient id="${gradId}" x1="0" y1="0" x2="0" y2="1">
        ${gradientStops}
      </linearGradient>
    </defs>
    <text transform="rotate(-90)" x="-${yAxisCY}" y="11" text-anchor="middle" font-size="8.5" fill="#94a3b8">Mood Level</text>
    ${gridLines}
    <line x1="${ml}" y1="${mt}" x2="${ml}" y2="${H - mb}" stroke="#cbd5e1" stroke-width="1"/>
    <line x1="${ml}" y1="${H - mb}" x2="${W - mr}" y2="${H - mb}" stroke="#cbd5e1" stroke-width="1"/>
    ${xLabels}
    ${areaD ? `<path d="${areaD}" fill="url(#${gradId})" fill-opacity="0.4" stroke="none"/>` : ''}
    <path d="${curveD}" fill="none" stroke="${strokeColor}" stroke-width="2.25" stroke-linejoin="round" stroke-linecap="round"/>
    ${circles}
  </svg>`;
};

const renderMoodChart = (container, data) => {
  const W = 500, H = 200;
  const ml = 44, mr = 12, mt = 12, mb = 36;
  const chartW = W - ml - mr;
  const chartH = H - mt - mb;

  const yMin = 1, yMax = 10;
  const yScale = (v) => mt + chartH - ((v - yMin) / (yMax - yMin)) * chartH;

  const dates = data.map((d) => d.date);
  const uniqueDates = [...new Set(dates)];
  const n = uniqueDates.length;
  const xScale = (i) => ml + (n <= 1 ? chartW / 2 : (i / (n - 1)) * chartW);

  const byDate = uniqueDates.map((date) => {
    const pts = data.filter((d) => d.date === date);
    const avg = pts.reduce((s, d) => s + parseInt(d.mood_level, 10), 0) / pts.length;
    return { date, level: avg, pts };
  });

  let gridLines = '';
  [1, 3, 5, 7, 10].forEach((v) => {
    const y = yScale(v).toFixed(1);
    gridLines += `<line x1="${ml}" y1="${y}" x2="${W - mr}" y2="${y}" stroke="#e2e8f0" stroke-width="1"/>`;
    gridLines += `<text x="${ml - 4}" y="${y}" text-anchor="end" dominant-baseline="middle" font-size="9" fill="#94a3b8">${v}</text>`;
  });

  let xLabels = '';
  const step = Math.max(1, Math.ceil(n / 6));
  byDate.forEach(({ date }, i) => {
    if (i % step !== 0 && i !== n - 1) return;
    const x = xScale(i).toFixed(1);
    const label = date.slice(5);
    xLabels += `<text x="${x}" y="${H - mb + 14}" text-anchor="middle" font-size="9" fill="#94a3b8">${label}</text>`;
  });

  const points = byDate.map(({ date, level, pts }, i) => {
    const allStandalone = pts.every((p) => p.source === 'standalone');
    const tipLines = pts.map((p) => `${escSvg(p.time.slice(0, 5))}: Mood ${escSvg(p.mood_level)}/10${p.note ? ' — ' + escSvg(p.note) : ''}${p.source === 'standalone' ? ' (standalone)' : ''}`).join('&#10;');
    return {
      x: xScale(i),
      y: yScale(level),
      color: moodLevelColor(Math.round(level)),
      tip: `${escSvg(date)}&#10;${tipLines}&#10;Click to view this day`,
      date,
      isStandalone: allStandalone,
      entryId: null,
    };
  });

  renderMoodChartCommon(container, points, gridLines, xLabels, W, H, ml, mr, mt, mb);
};

const renderMoodChartToday = (container, data) => {
  const W = 500, H = 200;
  const ml = 44, mr = 12, mt = 12, mb = 36;
  const chartW = W - ml - mr;
  const chartH = H - mt - mb;

  const yMin = 1, yMax = 10;
  const yScale = (v) => mt + chartH - ((v - yMin) / (yMax - yMin)) * chartH;

  const n = data.length;
  const xScale = (i) => ml + (n <= 1 ? chartW / 2 : (i / (n - 1)) * chartW);

  let gridLines = '';
  [1, 3, 5, 7, 10].forEach((v) => {
    const y = yScale(v).toFixed(1);
    gridLines += `<line x1="${ml}" y1="${y}" x2="${W - mr}" y2="${y}" stroke="#e2e8f0" stroke-width="1"/>`;
    gridLines += `<text x="${ml - 4}" y="${y}" text-anchor="end" dominant-baseline="middle" font-size="9" fill="#94a3b8">${v}</text>`;
  });

  let xLabels = '';
  const step = Math.max(1, Math.ceil(n / 6));
  data.forEach((d, i) => {
    if (i % step !== 0 && i !== n - 1) return;
    const x = xScale(i).toFixed(1);
    xLabels += `<text x="${x}" y="${H - mb + 14}" text-anchor="middle" font-size="9" fill="#94a3b8">${escSvg(d.time.slice(0, 5))}</text>`;
  });

  const points = data.map((d, i) => {
    const level = parseInt(d.mood_level, 10);
    const isStandalone = d.source === 'standalone';
    const tip = `${escSvg(d.time.slice(0, 5))}: Mood ${escSvg(d.mood_level)}/10${d.note ? ' — ' + escSvg(d.note) : ''}${isStandalone ? ' (standalone)' : ''}`;
    const entryId = d.source && d.id ? `${d.source}-${d.id}` : null;
    return { x: xScale(i), y: yScale(level), color: moodLevelColor(level), tip, isStandalone, entryId };
  });

  renderMoodChartCommon(container, points, gridLines, xLabels, W, H, ml, mr, mt, mb);
};

const loadPainGraph = async () => {
  if (!painGraphBody || !painGraphEmpty) return;
  painGraphBody.innerHTML = '<p class="pain-graph-loading">Loading…</p>';
  painGraphEmpty.hidden = true;

  const singleDay = painGraphDate !== null || painGraphDays === 0;
  if (painGraphDayBanner) {
    painGraphDayBanner.hidden = painGraphDate === null;
    if (painGraphDate !== null && painGraphDayLabel) {
      painGraphDayLabel.textContent = `Showing ${formatGraphDay(painGraphDate)}`;
    }
  }

  try {
    const dateParam = painGraphDate !== null ? `&date=${encodeURIComponent(painGraphDate)}` : '';
    const resp = await window.fetch(`index.php?action=pain_trend&medication_id=${encodeURIComponent(painGraphMedId ?? '')}&days=${singleDay ? 0 : painGraphDays}${dateParam}`);
    const payload = await resp.json();
    if (!payload.ok || payload.data.length === 0) {
      painGraphBody.innerHTML = '';
      painGraphEmpty.hidden = false;
      return;
    }
    if (singleDay) {
      renderPainChartToday(painGraphBody, payload.data);
    } else {
      renderPainChart(painGraphBody, payload.data);
    }
  } catch {
    painGraphBody.innerHTML = '';
    painGraphEmpty.hidden = false;
  }
};

painGraphModal?.querySelectorAll('.range-tab').forEach((tab) => {
  tab.addEventListener('click', () => {
    painGraphModal.querySelectorAll('.range-tab').forEach((t) => t.classList.remove('is-active'));
    tab.classList.add('is-active');
    painGraphDays = parseInt(tab.dataset.range ?? '7', 10);
    painGraphDate = null;
    loadPainGraph();
  });
});

// Clicking a point on a multi-day pain chart drills into that day's levels
painGraphBody?.addEventListener('click', (event) => {
  const dot = event.target.closest('circle[data-date]');
  if (!dot) return;
  painGraphPrevDays = painGraphDays;
  painGraphDate = dot.dataset.date ?? null;
  painGraphModal?.querySelectorAll('.range-tab').forEach((t) => t.classList.remove('is-active'));
  loadPainGraph();
});

document.querySelector('[data-pain-graph-day-back]')?.addEventListener('click', () => {
  painGraphDate = null;
  painGraphDays = painGraphPrevDays;
  painGraphModal?.querySelectorAll('.range-tab').forEach((t) => {
    t.classList.toggle('is-active', parseInt(t.dataset.range ?? '-1', 10) === painGraphDays);
  });
  loadPainGraph();
});

document.querySelectorAll('[data-open-pain-graph]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const medId   = btn.dataset.medicationId ?? '';
    const medName = btn.dataset.medicationName ?? '';
    const medDose = btn.dataset.medicationDose ?? '';
    if (!medId) return;
    openPainGraphModal(medId, medName, medDose);
  });
});

document.querySelector('[data-close-pain-graph]')?.addEventListener('click', closePainGraphModal);

painGraphModal?.addEventListener('click', (event) => {
  if (event.target === painGraphModal) closePainGraphModal();
});

document.querySelector('[data-pain-graph-print]')?.addEventListener('click', () => {
  const svg = painGraphBody?.querySelector('svg');
  if (!svg) return;
  const title = painGraphTitle?.textContent ?? 'Pain Level Trend';
  const rangeLabel = painGraphDate !== null
    ? formatGraphDay(painGraphDate)
    : (painGraphDays === 0 ? 'Today' : `Last ${painGraphDays} days`);
  const isMobile = window.matchMedia('(pointer: coarse)').matches;
  const win = window.open('', '_blank', 'width=1200,height=800');
  if (!win) return;
  const printScript = isMobile
    ? ''
    : `<script>window.onafterprint=function(){window.close();};window.onload=function(){window.print();}<\/script>`;
  const mobileHint = isMobile
    ? `<p style="background:#f1f5f9;border-radius:8px;color:#475569;font-size:0.82rem;margin:0.5rem 0 1rem;padding:0.6rem 0.9rem;">Use your browser&rsquo;s print or share button to print this chart.</p>`
    : '';
  win.document.write(`<!DOCTYPE html><html><head><title>${title}</title><style>
    @media print{body{padding:0;}}
    body{font-family:sans-serif;padding:1.5rem;color:#172033;}
    h2{font-size:1.1rem;margin:0 0 0.2rem;}
    p{font-size:0.82rem;color:#64748b;margin:0 0 1rem;}
    svg{width:90%;max-width:none;display:block;margin:0 auto;}
  </style></head><body>
  <h2>${title}</h2><p>${rangeLabel}</p>
  ${mobileHint}
  ${svg.outerHTML}
  ${printScript}
  </body></html>`);
  win.document.close();
});

// ── Mood trend graph (medication card quick view) ────────────────────────────

const moodGraphModal = document.querySelector('[data-mood-graph-modal]');
const moodGraphTitle = document.querySelector('[data-mood-graph-title]');
const moodGraphBody = document.querySelector('[data-mood-graph-body]');
const moodGraphEmpty = document.querySelector('[data-mood-graph-empty]');
const moodGraphDayBanner = document.querySelector('[data-mood-graph-day-banner]');
const moodGraphDayLabel = document.querySelector('[data-mood-graph-day-label]');

let moodGraphMedId = null;
let moodGraphDays = 7;
let moodGraphDate = null;     // set when a multi-day point is clicked (single-day view)
let moodGraphPrevDays = 7;    // range to restore when leaving the single-day view

const openMoodGraphModal = (medicationId, medicationName, medicationDose = '') => {
  if (!moodGraphModal) return;
  moodGraphMedId = medicationId;
  moodGraphDays = 0;
  moodGraphDate = null;
  moodGraphPrevDays = 7;
  const titleBase = medicationDose ? `${medicationName} ${medicationDose}` : medicationName;
  if (moodGraphTitle) moodGraphTitle.textContent = titleBase + ' — Mood Trend';
  moodGraphModal.querySelectorAll('.range-tab').forEach((t) => {
    t.classList.toggle('is-active', t.dataset.range === '0');
  });
  closeMedPlanModal();
  moodGraphModal.classList.add('is-open');
  lockBodyScroll();
  loadMoodGraph();
};

const closeMoodGraphModal = () => {
  if (!moodGraphModal) return;
  if (!moodGraphModal.classList.contains('is-open')) return;
  moodGraphModal.classList.remove('is-open');
  unlockBodyScroll();
};

const loadMoodGraph = async () => {
  if (!moodGraphBody || !moodGraphEmpty) return;
  moodGraphBody.innerHTML = '<p class="pain-graph-loading">Loading…</p>';
  moodGraphEmpty.hidden = true;

  const singleDay = moodGraphDate !== null || moodGraphDays === 0;
  if (moodGraphDayBanner) {
    moodGraphDayBanner.hidden = moodGraphDate === null;
    if (moodGraphDate !== null && moodGraphDayLabel) {
      moodGraphDayLabel.textContent = `Showing ${formatGraphDay(moodGraphDate)}`;
    }
  }

  try {
    const dateParam = moodGraphDate !== null ? `&date=${encodeURIComponent(moodGraphDate)}` : '';
    const resp = await window.fetch(`index.php?action=mood_trend&medication_id=${encodeURIComponent(moodGraphMedId ?? '')}&days=${singleDay ? 0 : moodGraphDays}${dateParam}`);
    const payload = await resp.json();
    if (!payload.ok || payload.data.length === 0) {
      moodGraphBody.innerHTML = '';
      moodGraphEmpty.hidden = false;
      return;
    }
    if (singleDay) {
      renderMoodChartToday(moodGraphBody, payload.data);
    } else {
      renderMoodChart(moodGraphBody, payload.data);
    }
  } catch {
    moodGraphBody.innerHTML = '';
    moodGraphEmpty.hidden = false;
  }
};

moodGraphModal?.querySelectorAll('.range-tab').forEach((tab) => {
  tab.addEventListener('click', () => {
    moodGraphModal.querySelectorAll('.range-tab').forEach((t) => t.classList.remove('is-active'));
    tab.classList.add('is-active');
    moodGraphDays = parseInt(tab.dataset.range ?? '7', 10);
    moodGraphDate = null;
    loadMoodGraph();
  });
});

// Clicking a point on a multi-day mood chart drills into that day's levels
moodGraphBody?.addEventListener('click', (event) => {
  const dot = event.target.closest('circle[data-date]');
  if (!dot) return;
  moodGraphPrevDays = moodGraphDays;
  moodGraphDate = dot.dataset.date ?? null;
  moodGraphModal?.querySelectorAll('.range-tab').forEach((t) => t.classList.remove('is-active'));
  loadMoodGraph();
});

document.querySelector('[data-mood-graph-day-back]')?.addEventListener('click', () => {
  moodGraphDate = null;
  moodGraphDays = moodGraphPrevDays;
  moodGraphModal?.querySelectorAll('.range-tab').forEach((t) => {
    t.classList.toggle('is-active', parseInt(t.dataset.range ?? '-1', 10) === moodGraphDays);
  });
  loadMoodGraph();
});

document.querySelectorAll('[data-open-mood-graph]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const medId   = btn.dataset.medicationId ?? '';
    const medName = btn.dataset.medicationName ?? '';
    const medDose = btn.dataset.medicationDose ?? '';
    if (!medId) return;
    openMoodGraphModal(medId, medName, medDose);
  });
});

document.querySelector('[data-close-mood-graph]')?.addEventListener('click', closeMoodGraphModal);

moodGraphModal?.addEventListener('click', (event) => {
  if (event.target === moodGraphModal) closeMoodGraphModal();
});

document.querySelector('[data-mood-graph-print]')?.addEventListener('click', () => {
  const svg = moodGraphBody?.querySelector('svg');
  if (!svg) return;
  const title = moodGraphTitle?.textContent ?? 'Mood Trend';
  const rangeLabel = moodGraphDate !== null
    ? formatGraphDay(moodGraphDate)
    : (moodGraphDays === 0 ? 'Today' : `Last ${moodGraphDays} days`);
  const isMobile = window.matchMedia('(pointer: coarse)').matches;
  const win = window.open('', '_blank', 'width=1200,height=800');
  if (!win) return;
  const printScript = isMobile
    ? ''
    : `<script>window.onafterprint=function(){window.close();};window.onload=function(){window.print();}<\/script>`;
  const mobileHint = isMobile
    ? `<p style="background:#f1f5f9;border-radius:8px;color:#475569;font-size:0.82rem;margin:0.5rem 0 1rem;padding:0.6rem 0.9rem;">Use your browser&rsquo;s print or share button to print this chart.</p>`
    : '';
  win.document.write(`<!DOCTYPE html><html><head><title>${title}</title><style>
    @media print{body{padding:0;}}
    body{font-family:sans-serif;padding:1.5rem;color:#172033;}
    h2{font-size:1.1rem;margin:0 0 0.2rem;}
    p{font-size:0.82rem;color:#64748b;margin:0 0 1rem;}
    svg{width:90%;max-width:none;display:block;margin:0 auto;}
  </style></head><body>
  <h2>${title}</h2><p>${rangeLabel}</p>
  ${mobileHint}
  ${svg.outerHTML}
  ${printScript}
  </body></html>`);
  win.document.close();
});

const formatEntryDatetime = (isoString) => {
  if (!isoString) return '';
  const d = new Date(isoString.replace(' ', 'T'));
  if (isNaN(d.getTime())) return isoString;
  return d.toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
};

// Pain tracking dedicated page
const painPageBody = document.querySelector('[data-pain-page-body]');
const painPageEmpty = document.querySelector('[data-pain-page-empty]');
const painPageMedName = document.querySelector('[data-pain-page-med-name]');

if (painPageBody) {
  let painPageMedId = 0;
  let painPageDays = 0;
  let painPageDate = null;
  let painPagePrevDays = 0;
  const painPageDayBanner = document.querySelector('[data-pain-page-day-banner]');
  const painPageDayLabel = document.querySelector('[data-pain-page-day-label]');

  const loadPainPageGraph = async () => {
    if (!painPageMedId) return;
    painPageBody.innerHTML = '<p class="pain-graph-loading">Loading…</p>';
    painPageEmpty.hidden = true;

    const singleDay = painPageDate !== null || painPageDays === 0;
    if (painPageDayBanner) {
      painPageDayBanner.hidden = painPageDate === null;
      if (painPageDate !== null && painPageDayLabel) {
        painPageDayLabel.textContent = `Showing ${formatGraphDay(painPageDate)}`;
      }
    }

    try {
      const dateParam = painPageDate !== null ? `&date=${encodeURIComponent(painPageDate)}` : '';
      const resp = await window.fetch(`index.php?action=pain_trend&medication_id=${encodeURIComponent(painPageMedId)}&days=${singleDay ? 0 : painPageDays}${dateParam}`);
      const payload = await resp.json();
      if (!payload.ok || payload.data.length === 0) {
        painPageBody.innerHTML = '';
        painPageEmpty.hidden = false;
        return;
      }
      if (singleDay) {
        renderPainChartToday(painPageBody, payload.data);
      } else {
        renderPainChart(painPageBody, payload.data);
      }
    } catch {
      painPageBody.innerHTML = '';
      painPageEmpty.hidden = false;
    }
  };

  // Log form elements
  const painLogPanel      = document.querySelector('[data-pain-log-panel]');
  const painLogFormWrap   = document.querySelector('[data-pain-log-form-wrap]');
  const painLogForm       = document.querySelector('[data-pain-log-form]');
  const painLogToggle     = document.querySelector('[data-pain-log-toggle]');
  const painLogCancel     = document.querySelector('[data-pain-log-cancel]');
  const painLogMedIdInput = document.querySelector('[data-pain-log-medication-id]');
  const painLogLevelInput = document.querySelector('[data-pain-log-level]');
  const painLogNoteInput  = document.querySelector('[data-pain-log-note]');
  const painLogError      = document.querySelector('[data-pain-log-error]');

  // History elements
  const painHistoryPanel        = document.querySelector('[data-pain-history-panel]');
  const painHistoryList         = document.querySelector('[data-pain-history-list]');
  const painHistoryViewMoreBtns = document.querySelectorAll('[data-pain-history-view-more]');
  const painHistoryLoading      = document.querySelector('[data-pain-history-loading]');
  const painHistoryEmpty        = document.querySelector('[data-pain-history-empty]');

  let painPageHistoryLoaded = false;
  let painHistoryExpanded   = false;
  let painPageMedDose       = '';

  const setPainHistoryViewMoreLabel = () => {
    const label = painHistoryExpanded ? 'View less' : 'View more';
    painHistoryViewMoreBtns.forEach((btn) => { btn.textContent = label; });
  };

  const resetPainLogForm = () => {
    if (painLogLevelInput) painLogLevelInput.value = '';
    if (painLogNoteInput)  painLogNoteInput.value  = '';
    if (painLogError)     { painLogError.hidden = true; painLogError.textContent = ''; }
    document.querySelectorAll('.pain-log-level-btn').forEach((b) => b.classList.remove('is-selected'));
  };

  document.querySelectorAll('.pain-log-level-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.pain-log-level-btn').forEach((b) => b.classList.remove('is-selected'));
      btn.classList.add('is-selected');
      if (painLogLevelInput) painLogLevelInput.value = btn.dataset.painLevel ?? '';
    });
  });

  painLogToggle?.addEventListener('click', () => {
    if (!painLogFormWrap) return;
    painLogFormWrap.hidden = false;
    painLogToggle.hidden   = true;
    if (painLogMedIdInput) painLogMedIdInput.value = String(painPageMedId);
    resetPainLogForm();
  });

  painLogCancel?.addEventListener('click', () => {
    if (painLogFormWrap) painLogFormWrap.hidden = true;
    if (painLogToggle)   painLogToggle.hidden   = false;
    resetPainLogForm();
  });

  painLogForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const level = parseInt(painLogLevelInput?.value ?? '', 10);
    if (!level || level < 1 || level > 10) {
      if (painLogError) { painLogError.textContent = 'Please select a pain level.'; painLogError.hidden = false; }
      return;
    }
    const note = (painLogNoteInput?.value ?? '').trim();
    const submitBtn = painLogForm.querySelector('[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;
    const result = await postStandalonePainMoodLog({ medicationId: painPageMedId, logType: 'pain', painLevel: level, moodLevel: null, note });
    if (submitBtn) submitBtn.disabled = false;
    if (!result.ok) {
      if (painLogError) { painLogError.textContent = result.error ?? 'Could not save log. Please try again.'; painLogError.hidden = false; }
      return;
    }
    if (painLogFormWrap) painLogFormWrap.hidden = true;
    if (painLogToggle)   painLogToggle.hidden   = false;
    resetPainLogForm();
    loadPainPageGraph();
    painPageHistoryLoaded = false;
    loadPainHistory();
  });

  const painScoreMod = (level) => (level <= 3 ? 'low' : level <= 6 ? 'mid' : level <= 8 ? 'high' : 'severe');

  const buildPainHistoryEntryHtml = (entry) => {
    const entryId   = entry.entry_id ?? '';
    const source    = entry.source ?? 'dose';
    const level     = parseInt(entry.pain_level, 10);
    const mod       = painScoreMod(level);
    const dateStr   = entry.date ? formatGraphDay(entry.date) : '';
    const timeStr   = entry.time ? slotTo12h(entry.time.slice(0, 5)) : '';
    const noteEsc   = (entry.note ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    const sourceCls = source === 'standalone' ? 'history-entry-source--standalone' : 'history-entry-source--dose';
    const sourceLbl = source === 'standalone' ? 'Standalone' : 'Dose';
    const medHtml = source === 'dose' && painPageMedName?.textContent
      ? `<strong>${escSvg(painPageMedName.textContent)}</strong>${painPageMedDose ? ` <span class="dose-inline">${escSvg(painPageMedDose)}</span>` : ''}`
      : '';
    const editedHtml = entry.edited_at
      ? `<small class="history-entry-edited">Last edited ${formatEntryDatetime(entry.edited_at)}</small>`
      : '';
    const noteHtml = noteEsc
      ? `<small class="history-note"><span class="history-note-label">Comments:</span> ${noteEsc}</small>`
      : '';
    return `<li id="pain-entry-${entryId}" data-entry-id="${entryId}" data-entry-date="${entry.date ?? ''}" data-entry-time="${entry.time ?? ''}" class="pain-history-entry">
  <span><span class="history-date">${dateStr}</span><span class="history-time">${timeStr}</span></span>
  <div>
    ${medHtml}
    <p class="history-entry-badges">
      <span class="history-entry-source ${sourceCls}">${sourceLbl}</span>
      <span class="history-pain-label">Pain Score</span> <span class="history-pain-badge history-pain-badge--${mod}">${level}/10</span>
      <button type="button" class="history-entry-edit-btn" data-entry-id="${entryId}" data-source="${source}" data-pain-level="${level}" data-note="${noteEsc}">Edit</button>
    </p>
    ${noteHtml}
    ${editedHtml}
  </div>
</li>`;
  };

  const renderPainHistoryEntries = (entries) => {
    if (!painHistoryList) return;
    if (!entries || entries.length === 0) {
      painHistoryList.innerHTML = '';
      if (painHistoryEmpty) painHistoryEmpty.hidden = false;
      return;
    }
    if (painHistoryEmpty) painHistoryEmpty.hidden = true;
    painHistoryList.innerHTML = entries.map(buildPainHistoryEntryHtml).join('');
  };

  const loadPainHistory = async () => {
    if (!painHistoryList || !painPageMedId) return;
    if (painPageHistoryLoaded) return;
    if (painHistoryLoading) painHistoryLoading.hidden = false;
    if (painHistoryEmpty)   painHistoryEmpty.hidden   = true;
    painHistoryList.innerHTML = '';
    try {
      const dateParam = painHistoryExpanded ? '' : `&date=${encodeURIComponent(localDateStr(new Date()))}`;
      const resp = await window.fetch(`index.php?action=pain_log&medication_id=${encodeURIComponent(painPageMedId)}&days=365${dateParam}`);
      const payload = await resp.json();
      if (painHistoryLoading) painHistoryLoading.hidden = true;
      if (payload.ok) {
        renderPainHistoryEntries(payload.entries);
        painPageHistoryLoaded = true;
      }
    } catch {
      if (painHistoryLoading) painHistoryLoading.hidden = true;
    }
  };

  painHistoryList?.addEventListener('click', async (e) => {
    const editBtn = e.target.closest('.history-entry-edit-btn');
    if (!editBtn) return;
    const li = editBtn.closest('li.pain-history-entry');
    if (!li) return;
    const entryId  = editBtn.dataset.entryId  ?? '';
    const source   = editBtn.dataset.source   ?? 'dose';
    const idPart   = entryId.split('-').pop();
    const curLevel = parseInt(editBtn.dataset.painLevel ?? '0', 10);
    const curNote  = editBtn.dataset.note ?? '';
    const originalHtml = li.innerHTML;

    const levelBtns = Array.from({ length: 10 }, (_, k) => {
      const v = k + 1;
      const sel = v === curLevel ? ' is-selected' : '';
      return `<button type="button" class="pain-log-level-btn${sel}" data-pain-level="${v}">${v}</button>`;
    }).join('');

    li.innerHTML = `<form class="history-entry-edit-form">
  <p class="feedback-pain-label">Pain level <span class="feedback-pain-hint">(1 = minimal &mdash; 10 = severe)</span></p>
  <div class="pain-level-selector" role="group" aria-label="Select pain level">${levelBtns}</div>
  <label class="stacked-label">Comments <span class="field-optional">(optional)</span>
    <textarea name="note" rows="2" maxlength="255"></textarea>
  </label>
  <p class="history-entry-edit-error" hidden></p>
  <div class="feedback-actions">
    <button type="submit" class="button primary small">Save</button>
    <button type="button" class="button secondary small history-entry-edit-cancel">Cancel</button>
  </div>
</form>`;
    const painEditTA = li.querySelector('textarea[name="note"]');
    if (painEditTA) painEditTA.value = curNote.replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"');

    let editLevel = curLevel;
    li.querySelectorAll('.pain-log-level-btn').forEach((b) => {
      b.addEventListener('click', () => {
        li.querySelectorAll('.pain-log-level-btn').forEach((x) => x.classList.remove('is-selected'));
        b.classList.add('is-selected');
        editLevel = parseInt(b.dataset.painLevel ?? '0', 10);
      });
    });

    li.querySelector('.history-entry-edit-cancel')?.addEventListener('click', () => {
      li.innerHTML = originalHtml;
    });

    li.querySelector('form.history-entry-edit-form')?.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      if (!editLevel) {
        const errEl = li.querySelector('.history-entry-edit-error');
        if (errEl) { errEl.textContent = 'Select a level.'; errEl.hidden = false; }
        return;
      }
      const noteVal = (li.querySelector('textarea[name="note"]')?.value ?? '').trim();
      const saveBtn = li.querySelector('[type="submit"]');
      if (saveBtn) saveBtn.disabled = true;
      const result = await editPainMoodLog({ source, id: idPart, painLevel: editLevel, moodLevel: null, note: noteVal });
      if (saveBtn) saveBtn.disabled = false;
      if (!result.ok) {
        const errEl = li.querySelector('.history-entry-edit-error');
        if (errEl) { errEl.textContent = result.error ?? 'Could not save. Please try again.'; errEl.hidden = false; }
        return;
      }
      // Re-render entry with updated data
      const now = new Date().toISOString().replace('T', ' ').slice(0, 19);
      const updatedEntry = {
        entry_id: entryId,
        source,
        pain_level: String(editLevel),
        date: li.dataset.entryDate ?? '',
        time: li.dataset.entryTime ?? '',
        note: noteVal,
        edited_at: now,
      };
      li.outerHTML = buildPainHistoryEntryHtml(updatedEntry);
      painPageHistoryLoaded = false;
      loadPainPageGraph();
    });
  });

  const expandPainHistoryAndScrollToEntry = async (entryId) => {
    if (!painHistoryPanel || !painHistoryList) return;
    if (!painHistoryExpanded) {
      painHistoryExpanded = true;
      painHistoryPanel.classList.add('is-expanded');
      setPainHistoryViewMoreLabel();
      painPageHistoryLoaded = false;
    }
    await loadPainHistory();
    const target = document.getElementById(`pain-entry-${entryId}`);
    if (!target) return;
    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    target.classList.add('is-highlighted');
    setTimeout(() => target.classList.remove('is-highlighted'), 2000);
  };

  const expandPainHistoryAndScrollToDate = async (date) => {
    if (!painHistoryPanel || !painHistoryList) return;
    if (!painHistoryExpanded) {
      painHistoryExpanded = true;
      painHistoryPanel.classList.add('is-expanded');
      setPainHistoryViewMoreLabel();
      painPageHistoryLoaded = false;
    }
    await loadPainHistory();
    const target = painHistoryList.querySelector(`[data-entry-date="${CSS.escape(date)}"]`);
    if (!target) return;
    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    target.classList.add('is-highlighted');
    setTimeout(() => target.classList.remove('is-highlighted'), 2000);
  };

  painHistoryViewMoreBtns.forEach((btn) => {
    btn.addEventListener('click', () => {
      painHistoryExpanded = !painHistoryExpanded;
      painHistoryPanel?.classList.toggle('is-expanded', painHistoryExpanded);
      setPainHistoryViewMoreLabel();
      painPageHistoryLoaded = false;
      loadPainHistory();
    });
  });

  // Replace click with pointerup + drag guard for mobile touch support
  let painPagePointerMoved = false;
  painPageBody.addEventListener('pointerdown', () => { painPagePointerMoved = false; });
  painPageBody.addEventListener('pointermove', () => { painPagePointerMoved = true; });
  painPageBody.addEventListener('pointerup', async (event) => {
    if (painPagePointerMoved) return;
    const entryDot = event.target.closest('circle[data-entry-id]');
    if (entryDot?.dataset.entryId) {
      expandPainHistoryAndScrollToEntry(entryDot.dataset.entryId);
      return;
    }
    const dot = event.target.closest('circle[data-date]');
    if (!dot) return;
    painPagePrevDays = painPageDays;
    painPageDate = dot.dataset.date ?? null;
    document.querySelectorAll('.pain-page-range-tab').forEach((t) => t.classList.remove('is-active'));
    await loadPainPageGraph();
    if (painPageDate) expandPainHistoryAndScrollToDate(painPageDate);
  });

  document.querySelector('[data-pain-page-day-back]')?.addEventListener('click', () => {
    painPageDate = null;
    painPageDays = painPagePrevDays;
    document.querySelectorAll('.pain-page-range-tab').forEach((t) => {
      t.classList.toggle('is-active', parseInt(t.dataset.range ?? '-1', 10) === painPageDays);
    });
    loadPainPageGraph();
  });

  document.querySelectorAll('[data-select-medication]').forEach((btn) => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('[data-select-medication]').forEach((b) => b.classList.remove('is-active'));
      btn.classList.add('is-active');
      painPageMedId = parseInt(btn.dataset.medicationId ?? '0', 10);
      painPageMedDose = btn.dataset.medicationDose ?? '';
      if (painPageMedName) painPageMedName.textContent = btn.dataset.medicationName ?? '';
      painPageDays = 0;
      painPageDate = null;
      painPageHistoryLoaded = false;
      painHistoryExpanded = false;
      painHistoryPanel?.classList.remove('is-expanded');
      setPainHistoryViewMoreLabel();
      if (painLogFormWrap) painLogFormWrap.hidden = true;
      if (painLogToggle)   painLogToggle.hidden   = false;
      resetPainLogForm();
      document.querySelectorAll('.pain-page-range-tab').forEach((t) =>
        t.classList.toggle('is-active', parseInt(t.dataset.range ?? '0', 10) === 0)
      );
      loadPainPageGraph();
      loadPainHistory();
    });
  });

  document.querySelectorAll('.pain-page-range-tab').forEach((tab) => {
    tab.addEventListener('click', () => {
      document.querySelectorAll('.pain-page-range-tab').forEach((t) => t.classList.remove('is-active'));
      tab.classList.add('is-active');
      painPageDays = parseInt(tab.dataset.range ?? '0', 10);
      painPageDate = null;
      loadPainPageGraph();
    });
  });

  document.querySelector('[data-select-medication]')?.click();
}

// Mood & Wellbeing dedicated page
const moodPageBody = document.querySelector('[data-mood-page-body]');
const moodPageEmpty = document.querySelector('[data-mood-page-empty]');
const moodPageMedName = document.querySelector('[data-mood-page-med-name]');

if (moodPageBody) {
  let moodPageMedId = 0;
  let moodPageDays = 0;
  let moodPageDate = null;
  let moodPagePrevDays = 0;
  const moodPageDayBanner = document.querySelector('[data-mood-page-day-banner]');
  const moodPageDayLabel = document.querySelector('[data-mood-page-day-label]');

  const loadMoodPageGraph = async () => {
    if (!moodPageMedId) return;
    moodPageBody.innerHTML = '<p class="pain-graph-loading">Loading…</p>';
    moodPageEmpty.hidden = true;

    const singleDay = moodPageDate !== null || moodPageDays === 0;
    if (moodPageDayBanner) {
      moodPageDayBanner.hidden = moodPageDate === null;
      if (moodPageDate !== null && moodPageDayLabel) {
        moodPageDayLabel.textContent = `Showing ${formatGraphDay(moodPageDate)}`;
      }
    }

    try {
      const dateParam = moodPageDate !== null ? `&date=${encodeURIComponent(moodPageDate)}` : '';
      const resp = await window.fetch(`index.php?action=mood_trend&medication_id=${encodeURIComponent(moodPageMedId)}&days=${singleDay ? 0 : moodPageDays}${dateParam}`);
      const payload = await resp.json();
      if (!payload.ok || payload.data.length === 0) {
        moodPageBody.innerHTML = '';
        moodPageEmpty.hidden = false;
        return;
      }
      if (singleDay) {
        renderMoodChartToday(moodPageBody, payload.data);
      } else {
        renderMoodChart(moodPageBody, payload.data);
      }
    } catch {
      moodPageBody.innerHTML = '';
      moodPageEmpty.hidden = false;
    }
  };

  // Log form elements
  const moodLogPanel      = document.querySelector('[data-mood-log-panel]');
  const moodLogFormWrap   = document.querySelector('[data-mood-log-form-wrap]');
  const moodLogForm       = document.querySelector('[data-mood-log-form]');
  const moodLogToggle     = document.querySelector('[data-mood-log-toggle]');
  const moodLogCancel     = document.querySelector('[data-mood-log-cancel]');
  const moodLogMedIdInput = document.querySelector('[data-mood-log-medication-id]');
  const moodLogLevelInput = document.querySelector('[data-mood-log-level]');
  const moodLogNoteInput  = document.querySelector('[data-mood-log-note]');
  const moodLogError      = document.querySelector('[data-mood-log-error]');

  // History elements
  const moodHistoryPanel        = document.querySelector('[data-mood-history-panel]');
  const moodHistoryList         = document.querySelector('[data-mood-history-list]');
  const moodHistoryViewMoreBtns = document.querySelectorAll('[data-mood-history-view-more]');
  const moodHistoryLoading      = document.querySelector('[data-mood-history-loading]');
  const moodHistoryEmpty        = document.querySelector('[data-mood-history-empty]');

  let moodPageHistoryLoaded = false;
  let moodHistoryExpanded   = false;
  let moodPageMedDose       = '';

  const setMoodHistoryViewMoreLabel = () => {
    const label = moodHistoryExpanded ? 'View less' : 'View more';
    moodHistoryViewMoreBtns.forEach((btn) => { btn.textContent = label; });
  };

  const resetMoodLogForm = () => {
    if (moodLogLevelInput) moodLogLevelInput.value = '';
    if (moodLogNoteInput)  moodLogNoteInput.value  = '';
    if (moodLogError)     { moodLogError.hidden = true; moodLogError.textContent = ''; }
    document.querySelectorAll('.mood-log-level-btn').forEach((b) => b.classList.remove('is-selected'));
  };

  document.querySelectorAll('.mood-log-level-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.mood-log-level-btn').forEach((b) => b.classList.remove('is-selected'));
      btn.classList.add('is-selected');
      if (moodLogLevelInput) moodLogLevelInput.value = btn.dataset.moodLevel ?? '';
    });
  });

  moodLogToggle?.addEventListener('click', () => {
    if (!moodLogFormWrap) return;
    moodLogFormWrap.hidden = false;
    moodLogToggle.hidden   = true;
    if (moodLogMedIdInput) moodLogMedIdInput.value = String(moodPageMedId);
    resetMoodLogForm();
  });

  moodLogCancel?.addEventListener('click', () => {
    if (moodLogFormWrap) moodLogFormWrap.hidden = true;
    if (moodLogToggle)   moodLogToggle.hidden   = false;
    resetMoodLogForm();
  });

  moodLogForm?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const level = parseInt(moodLogLevelInput?.value ?? '', 10);
    if (!level || level < 1 || level > 10) {
      if (moodLogError) { moodLogError.textContent = 'Please select a mood level.'; moodLogError.hidden = false; }
      return;
    }
    const note = (moodLogNoteInput?.value ?? '').trim();
    const submitBtn = moodLogForm.querySelector('[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;
    const result = await postStandalonePainMoodLog({ medicationId: moodPageMedId, logType: 'mood', painLevel: null, moodLevel: level, note });
    if (submitBtn) submitBtn.disabled = false;
    if (!result.ok) {
      if (moodLogError) { moodLogError.textContent = result.error ?? 'Could not save log. Please try again.'; moodLogError.hidden = false; }
      return;
    }
    if (moodLogFormWrap) moodLogFormWrap.hidden = true;
    if (moodLogToggle)   moodLogToggle.hidden   = false;
    resetMoodLogForm();
    loadMoodPageGraph();
    moodPageHistoryLoaded = false;
    loadMoodHistory();
  });

  const moodScoreMod = (level) => (level <= 3 ? 'poor' : level <= 6 ? 'fair' : level <= 8 ? 'good' : 'great');

  const buildMoodHistoryEntryHtml = (entry) => {
    const entryId   = entry.entry_id ?? '';
    const source    = entry.source ?? 'dose';
    const level     = parseInt(entry.mood_level, 10);
    const mod       = moodScoreMod(level);
    const dateStr   = entry.date ? formatGraphDay(entry.date) : '';
    const timeStr   = entry.time ? slotTo12h(entry.time.slice(0, 5)) : '';
    const noteEsc   = (entry.note ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    const sourceCls = source === 'standalone' ? 'history-entry-source--standalone' : 'history-entry-source--dose';
    const sourceLbl = source === 'standalone' ? 'Standalone' : 'Dose';
    const medHtml = source === 'dose' && moodPageMedName?.textContent
      ? `<strong>${escSvg(moodPageMedName.textContent)}</strong>${moodPageMedDose ? ` <span class="dose-inline">${escSvg(moodPageMedDose)}</span>` : ''}`
      : '';
    const editedHtml = entry.edited_at
      ? `<small class="history-entry-edited">Last edited ${formatEntryDatetime(entry.edited_at)}</small>`
      : '';
    const noteHtml = noteEsc
      ? `<small class="history-note"><span class="history-note-label">Comments:</span> ${noteEsc}</small>`
      : '';
    return `<li id="mood-entry-${entryId}" data-entry-id="${entryId}" data-entry-date="${entry.date ?? ''}" data-entry-time="${entry.time ?? ''}" class="mood-history-entry">
  <span><span class="history-date">${dateStr}</span><span class="history-time">${timeStr}</span></span>
  <div>
    ${medHtml}
    <p class="history-entry-badges">
      <span class="history-entry-source ${sourceCls}">${sourceLbl}</span>
      <span class="history-mood-label">Mood Score</span> <span class="history-mood-badge history-mood-badge--${mod}">${level}/10</span>
      <button type="button" class="history-entry-edit-btn" data-entry-id="${entryId}" data-source="${source}" data-mood-level="${level}" data-note="${noteEsc}">Edit</button>
    </p>
    ${noteHtml}
    ${editedHtml}
  </div>
</li>`;
  };

  const renderMoodHistoryEntries = (entries) => {
    if (!moodHistoryList) return;
    if (!entries || entries.length === 0) {
      moodHistoryList.innerHTML = '';
      if (moodHistoryEmpty) moodHistoryEmpty.hidden = false;
      return;
    }
    if (moodHistoryEmpty) moodHistoryEmpty.hidden = true;
    moodHistoryList.innerHTML = entries.map(buildMoodHistoryEntryHtml).join('');
  };

  const loadMoodHistory = async () => {
    if (!moodHistoryList || !moodPageMedId) return;
    if (moodPageHistoryLoaded) return;
    if (moodHistoryLoading) moodHistoryLoading.hidden = false;
    if (moodHistoryEmpty)   moodHistoryEmpty.hidden   = true;
    moodHistoryList.innerHTML = '';
    try {
      const dateParam = moodHistoryExpanded ? '' : `&date=${encodeURIComponent(localDateStr(new Date()))}`;
      const resp = await window.fetch(`index.php?action=mood_log&medication_id=${encodeURIComponent(moodPageMedId)}&days=365${dateParam}`);
      const payload = await resp.json();
      if (moodHistoryLoading) moodHistoryLoading.hidden = true;
      if (payload.ok) {
        renderMoodHistoryEntries(payload.entries);
        moodPageHistoryLoaded = true;
      }
    } catch {
      if (moodHistoryLoading) moodHistoryLoading.hidden = true;
    }
  };

  moodHistoryList?.addEventListener('click', async (e) => {
    const editBtn = e.target.closest('.history-entry-edit-btn');
    if (!editBtn) return;
    const li = editBtn.closest('li.mood-history-entry');
    if (!li) return;
    const entryId  = editBtn.dataset.entryId  ?? '';
    const source   = editBtn.dataset.source   ?? 'dose';
    const idPart   = entryId.split('-').pop();
    const curLevel = parseInt(editBtn.dataset.moodLevel ?? '0', 10);
    const curNote  = editBtn.dataset.note ?? '';
    const originalHtml = li.innerHTML;

    const levelBtns = Array.from({ length: 10 }, (_, k) => {
      const v = k + 1;
      const sel = v === curLevel ? ' is-selected' : '';
      return `<button type="button" class="mood-log-level-btn${sel}" data-mood-level="${v}">${v}</button>`;
    }).join('');

    li.innerHTML = `<form class="history-entry-edit-form">
  <p class="feedback-pain-label">Mood level <span class="feedback-pain-hint">(1 = very low &mdash; 10 = excellent)</span></p>
  <div class="pain-level-selector" role="group" aria-label="Select mood level">${levelBtns}</div>
  <label class="stacked-label">Comments <span class="field-optional">(optional)</span>
    <textarea name="note" rows="2" maxlength="255"></textarea>
  </label>
  <p class="history-entry-edit-error" hidden></p>
  <div class="feedback-actions">
    <button type="submit" class="button primary small">Save</button>
    <button type="button" class="button secondary small history-entry-edit-cancel">Cancel</button>
  </div>
</form>`;
    const moodEditTA = li.querySelector('textarea[name="note"]');
    if (moodEditTA) moodEditTA.value = curNote.replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"');

    let editLevel = curLevel;
    li.querySelectorAll('.mood-log-level-btn').forEach((b) => {
      b.addEventListener('click', () => {
        li.querySelectorAll('.mood-log-level-btn').forEach((x) => x.classList.remove('is-selected'));
        b.classList.add('is-selected');
        editLevel = parseInt(b.dataset.moodLevel ?? '0', 10);
      });
    });

    li.querySelector('.history-entry-edit-cancel')?.addEventListener('click', () => {
      li.innerHTML = originalHtml;
    });

    li.querySelector('form.history-entry-edit-form')?.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      if (!editLevel) {
        const errEl = li.querySelector('.history-entry-edit-error');
        if (errEl) { errEl.textContent = 'Select a level.'; errEl.hidden = false; }
        return;
      }
      const noteVal = (li.querySelector('textarea[name="note"]')?.value ?? '').trim();
      const saveBtn = li.querySelector('[type="submit"]');
      if (saveBtn) saveBtn.disabled = true;
      const result = await editPainMoodLog({ source, id: idPart, painLevel: null, moodLevel: editLevel, note: noteVal });
      if (saveBtn) saveBtn.disabled = false;
      if (!result.ok) {
        const errEl = li.querySelector('.history-entry-edit-error');
        if (errEl) { errEl.textContent = result.error ?? 'Could not save. Please try again.'; errEl.hidden = false; }
        return;
      }
      const now = new Date().toISOString().replace('T', ' ').slice(0, 19);
      const updatedEntry = {
        entry_id: entryId,
        source,
        mood_level: String(editLevel),
        date: li.dataset.entryDate ?? '',
        time: li.dataset.entryTime ?? '',
        note: noteVal,
        edited_at: now,
      };
      li.outerHTML = buildMoodHistoryEntryHtml(updatedEntry);
      moodPageHistoryLoaded = false;
      loadMoodPageGraph();
    });
  });

  const expandMoodHistoryAndScrollToEntry = async (entryId) => {
    if (!moodHistoryPanel || !moodHistoryList) return;
    if (!moodHistoryExpanded) {
      moodHistoryExpanded = true;
      moodHistoryPanel.classList.add('is-expanded');
      setMoodHistoryViewMoreLabel();
      moodPageHistoryLoaded = false;
    }
    await loadMoodHistory();
    const target = document.getElementById(`mood-entry-${entryId}`);
    if (!target) return;
    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    target.classList.add('is-highlighted');
    setTimeout(() => target.classList.remove('is-highlighted'), 2000);
  };

  const expandMoodHistoryAndScrollToDate = async (date) => {
    if (!moodHistoryPanel || !moodHistoryList) return;
    if (!moodHistoryExpanded) {
      moodHistoryExpanded = true;
      moodHistoryPanel.classList.add('is-expanded');
      setMoodHistoryViewMoreLabel();
      moodPageHistoryLoaded = false;
    }
    await loadMoodHistory();
    const target = moodHistoryList.querySelector(`[data-entry-date="${CSS.escape(date)}"]`);
    if (!target) return;
    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    target.classList.add('is-highlighted');
    setTimeout(() => target.classList.remove('is-highlighted'), 2000);
  };

  moodHistoryViewMoreBtns.forEach((btn) => {
    btn.addEventListener('click', () => {
      moodHistoryExpanded = !moodHistoryExpanded;
      moodHistoryPanel?.classList.toggle('is-expanded', moodHistoryExpanded);
      setMoodHistoryViewMoreLabel();
      moodPageHistoryLoaded = false;
      loadMoodHistory();
    });
  });

  // Replace click with pointerup + drag guard for mobile touch support
  let moodPagePointerMoved = false;
  moodPageBody.addEventListener('pointerdown', () => { moodPagePointerMoved = false; });
  moodPageBody.addEventListener('pointermove', () => { moodPagePointerMoved = true; });
  moodPageBody.addEventListener('pointerup', async (event) => {
    if (moodPagePointerMoved) return;
    const entryDot = event.target.closest('circle[data-entry-id]');
    if (entryDot?.dataset.entryId) {
      expandMoodHistoryAndScrollToEntry(entryDot.dataset.entryId);
      return;
    }
    const dot = event.target.closest('circle[data-date]');
    if (!dot) return;
    moodPagePrevDays = moodPageDays;
    moodPageDate = dot.dataset.date ?? null;
    document.querySelectorAll('.mood-page-range-tab').forEach((t) => t.classList.remove('is-active'));
    await loadMoodPageGraph();
    if (moodPageDate) expandMoodHistoryAndScrollToDate(moodPageDate);
  });

  document.querySelector('[data-mood-page-day-back]')?.addEventListener('click', () => {
    moodPageDate = null;
    moodPageDays = moodPagePrevDays;
    document.querySelectorAll('.mood-page-range-tab').forEach((t) => {
      t.classList.toggle('is-active', parseInt(t.dataset.range ?? '-1', 10) === moodPageDays);
    });
    loadMoodPageGraph();
  });

  document.querySelectorAll('[data-select-mood-medication]').forEach((btn) => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('[data-select-mood-medication]').forEach((b) => b.classList.remove('is-active'));
      btn.classList.add('is-active');
      moodPageMedId = parseInt(btn.dataset.medicationId ?? '0', 10);
      moodPageMedDose = btn.dataset.medicationDose ?? '';
      if (moodPageMedName) moodPageMedName.textContent = btn.dataset.medicationName ?? '';
      moodPageDays = 0;
      moodPageDate = null;
      moodPageHistoryLoaded = false;
      moodHistoryExpanded = false;
      moodHistoryPanel?.classList.remove('is-expanded');
      setMoodHistoryViewMoreLabel();
      if (moodLogFormWrap) moodLogFormWrap.hidden = true;
      if (moodLogToggle)   moodLogToggle.hidden   = false;
      resetMoodLogForm();
      document.querySelectorAll('.mood-page-range-tab').forEach((t) =>
        t.classList.toggle('is-active', parseInt(t.dataset.range ?? '0', 10) === 0)
      );
      loadMoodPageGraph();
      loadMoodHistory();
    });
  });

  document.querySelectorAll('.mood-page-range-tab').forEach((tab) => {
    tab.addEventListener('click', () => {
      document.querySelectorAll('.mood-page-range-tab').forEach((t) => t.classList.remove('is-active'));
      tab.classList.add('is-active');
      moodPageDays = parseInt(tab.dataset.range ?? '0', 10);
      moodPageDate = null;
      loadMoodPageGraph();
    });
  });

  document.querySelector('[data-select-mood-medication]')?.click();
}

const historyPanel = document.querySelector('[data-history-panel]');
const historyList = document.querySelector('[data-history-list]');
const historyToggle = document.querySelector('[data-history-toggle]');

historyToggle?.addEventListener('click', () => {
  if (!historyPanel || !historyList) return;
  const expanded = historyPanel.classList.toggle('is-expanded');
  historyToggle.textContent = expanded ? 'View less' : 'View more';
});

const historySort     = document.querySelector('[data-history-sort]');
const historySortIcon = historySort?.querySelector('i');
let   historySortDir  = 'desc';

historySort?.addEventListener('click', () => {
  historySortDir = historySortDir === 'desc' ? 'asc' : 'desc';
  const label = historySortDir === 'desc' ? 'Sort: newest first' : 'Sort: oldest first';
  historySort.setAttribute('aria-label', label);
  historySort.setAttribute('title', label);
  if (historySortIcon) {
    historySortIcon.className = historySortDir === 'desc'
      ? 'fa-solid fa-arrow-down-wide-short'
      : 'fa-solid fa-arrow-up-wide-short';
  }
  if (!historyList) return;
  const items = Array.from(historyList.querySelectorAll('li'));
  items.sort((a, b) => {
    const ta = a.dataset.sortTime ?? '';
    const tb = b.dataset.sortTime ?? '';
    return historySortDir === 'desc' ? tb.localeCompare(ta) : ta.localeCompare(tb);
  });
  items.forEach((item) => historyList.appendChild(item));
});

// ── Calendar day modal ────────────────────────────────────────────────────────

const calendarDayModal      = document.querySelector('[data-calendar-day-modal]');
const calendarDayModalTitle = document.querySelector('[data-calendar-day-modal-title]');
const calendarDayModalBody  = document.querySelector('[data-calendar-day-modal-body]');

const closeCalendarDayModal = () => {
  if (!calendarDayModal) return;
  calendarDayModal.classList.remove('is-open');
  unlockBodyScroll();
};

document.querySelectorAll('[data-close-calendar-day-modal]')
  .forEach((btn) => btn.addEventListener('click', closeCalendarDayModal));
calendarDayModal?.addEventListener('click', (e) => {
  if (e.target === calendarDayModal) closeCalendarDayModal();
});

document.querySelectorAll('[data-calendar-day]').forEach((cell) => {
  cell.addEventListener('click', () => {
    const dateStr = cell.dataset.date;
    const day = (typeof calendarDayData !== 'undefined') && calendarDayData[dateStr];
    if (!day || !calendarDayModal) return;
    calendarDayModalTitle.textContent =
      `${day.dayName}, ${day.displayDate} — Medications: ${day.medications.length}`;
    calendarDayModalBody.innerHTML = buildCalendarDayHtml(day.medications);
    calendarDayModal.classList.add('is-open');
    lockBodyScroll();
  });
});

function buildCalendarDayHtml(meds) {
  if (!meds.length) return '<p class="empty-state-text">No dose data for this day.</p>';
  return '<ul class="cal-day-med-list">' + meds.map((med) => {
    const doseStr = med.doseFormatted
      ? ` <span class="dose-inline">${escHtml(med.doseFormatted)}</span>`
      : '';
    const slots = med.slots.map((slot) => {
      let badge = '';
      if (slot.status === 'taken' && slot.isLate) {
        badge = `<span class="warn-pill">Taken (${escHtml(slot.lateLabel)})</span>`;
      } else if (slot.status === 'taken') {
        badge = '<span class="done-pill">Taken</span>';
      } else if (slot.status === 'skipped') {
        badge = '<span class="warn-pill">Skipped</span>';
      } else if (slot.status === 'missed') {
        badge = '<span class="alert-pill">Missed</span>';
      }
      return `<li class="cal-day-slot-row"><span class="cal-day-slot-time">${escHtml(slot.displayTime)}</span>${badge}</li>`;
    }).join('');
    return `<li class="cal-day-med-item">
      <div class="cal-day-med-name"><strong>${escHtml(med.name)}</strong>${doseStr}</div>
      <div class="cal-day-med-summary">Total doses ${med.total} — Taken: ${med.taken} / Late: ${med.late} — Skipped: ${med.skipped} — Missed: ${med.missed}</div>
      <details class="cal-day-med-details">
        <summary class="cal-day-med-view-label">View details <i class="fa-solid fa-chevron-down cal-day-chevron" aria-hidden="true"></i></summary>
        <ul class="cal-day-slots">${slots}</ul>
      </details>
    </li>`;
  }).join('') + '</ul>';
}

// ── Alarm engine ──────────────────────────────────────────────────────────────

const alarmOverlay = document.querySelector('[data-alarm-overlay]');
const alarmMedNameEl = document.querySelector('[data-alarm-med-name]');
const alarmMedDoseEl = document.querySelector('[data-alarm-med-dose]');
const alarmGroupNameEl = document.querySelector('[data-alarm-group-name]');
const alarmGroupListEl = document.querySelector('[data-alarm-group-list]');
const alarmSingleModeEl = document.querySelector('[data-alarm-single-mode]');
const alarmGroupModeEl = document.querySelector('[data-alarm-group-mode]');
const alarmEyebrowEl = document.querySelector('[data-alarm-eyebrow]');
const alarmSnoozeMinutesEl = document.querySelector('[data-alarm-snooze-minutes]');
const alarmTakeBtn = document.querySelector('[data-alarm-take]');
const alarmSkipBtn = document.querySelector('[data-alarm-skip]');
const alarmSnoozeBtn = document.querySelector('[data-alarm-snooze]');
const alarmIndividualBtn = document.querySelector('[data-alarm-individual]');
const alarmSnoozeRow = alarmSnoozeBtn?.closest('.alarm-snooze-row') ?? null;

let alarmGroupItems = [];

let alarmAudioCtx = null;
let alarmBeepTimer = null;
let alarmVibrateTimer = null;
let userHasGestured = false;

// Unlock AudioContext on the first user gesture so it's ready before alarms fire.
const onUserGesture = () => {
  userHasGestured = true;
  if (!alarmAudioCtx || alarmAudioCtx.state === 'closed') {
    try { alarmAudioCtx = new AudioContext(); } catch { /* unavailable */ }
  }
};
['pointerdown', 'keydown', 'touchstart'].forEach((evt) =>
  document.addEventListener(evt, onUserGesture, { passive: true })
);
let swRegistration = null;

const getCsrfToken = () =>
  document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

const urlBase64ToUint8Array = (base64String) => {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = window.atob(base64);
  const outputArray = new Uint8Array(rawData.length);
  for (let i = 0; i < rawData.length; i += 1) {
    outputArray[i] = rawData.charCodeAt(i);
  }
  return outputArray;
};

const registerServiceWorker = async () => {
  if (!('serviceWorker' in navigator)) return null;
  swRegistration = await navigator.serviceWorker.register('sw.js');
  return swRegistration;
};

const fetchPushPublicKey = async () => {
  const response = await window.fetch('index.php?action=push_public_key', { credentials: 'same-origin' });
  if (!response.ok) return '';
  const payload = await response.json();
  if (!payload || payload.ok !== true || typeof payload.public_key !== 'string') return '';
  return payload.public_key;
};

const savePushSubscription = async (subscription) => {
  const json = subscription.toJSON();
  const params = new URLSearchParams();
  params.set('csrf_token', getCsrfToken());
  params.set('action', 'save_push_subscription');
  params.set('endpoint', json.endpoint ?? '');
  params.set('p256dh', json.keys?.p256dh ?? '');
  params.set('auth', json.keys?.auth ?? '');
  const response = await window.fetch('index.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
    body: params.toString(),
  });
  if (!response.ok) {
    let errorMessage = 'Failed to save push subscription.';
    try {
      const payload = await response.json();
      if (payload && typeof payload.error === 'string' && payload.error) {
        errorMessage = payload.error;
      }
    } catch (error) {
      // ignore JSON parse issues
    }
    throw new Error(errorMessage);
  }
};

const removePushSubscription = async (endpoint) => {
  const params = new URLSearchParams();
  params.set('csrf_token', getCsrfToken());
  params.set('action', 'remove_push_subscription');
  params.set('endpoint', endpoint);
  const response = await window.fetch('index.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
    body: params.toString(),
  });
  if (!response.ok) {
    let errorMessage = 'Failed to remove push subscription.';
    try {
      const payload = await response.json();
      if (payload && typeof payload.error === 'string' && payload.error) {
        errorMessage = payload.error;
      }
    } catch (error) {
      // ignore JSON parse issues
    }
    throw new Error(errorMessage);
  }
};

const setReminderToggleState = (enabled) => {
  if (!enableRemindersButton) return;
  enableRemindersButton.checked = enabled;
  if (!reminderStatus) return;
  reminderStatus.textContent = enabled
    ? 'Background push reminders are enabled on this device.'
    : 'Background push reminders are currently disabled on this device.';
};

const currentPushSubscription = async () => {
  if (!('serviceWorker' in navigator)) return null;
  if (!swRegistration) {
    await registerServiceWorker();
  }
  if (!swRegistration) return null;
  return swRegistration.pushManager.getSubscription();
};

const SEEN_EXPIRY_MS = 5 * 60 * 1000;

const readSeenMap = () => {
  try {
    return JSON.parse(window.localStorage.getItem('rxtracker_reminder_seen') ?? '{}');
  } catch {
    return {};
  }
};

const writeSeenMap = (map) => {
  window.localStorage.setItem('rxtracker_reminder_seen', JSON.stringify(map));
};

const isSoundEnabled = () =>
  window.localStorage.getItem('rxtracker_sound_enabled') !== '0';

const isVibrationEnabled = () =>
  window.localStorage.getItem('rxtracker_vibration_enabled') !== '0';

const scheduleBeep = (ctx, startTime, freq, duration) => {
  const osc = ctx.createOscillator();
  const gain = ctx.createGain();
  osc.connect(gain);
  gain.connect(ctx.destination);
  osc.frequency.value = freq;
  gain.gain.setValueAtTime(0.25, startTime);
  gain.gain.exponentialRampToValueAtTime(0.001, startTime + duration);
  osc.start(startTime);
  osc.stop(startTime + duration + 0.01);
};

const playAlarmPattern = () => {
  if (!alarmAudioCtx) return;
  try {
    const now = alarmAudioCtx.currentTime;
    scheduleBeep(alarmAudioCtx, now, 880, 0.18);
    scheduleBeep(alarmAudioCtx, now + 0.25, 880, 0.18);
    scheduleBeep(alarmAudioCtx, now + 0.5, 1100, 0.28);
    alarmBeepTimer = window.setTimeout(playAlarmPattern, 1400);
  } catch {
    // audio context may have closed
  }
};

const startAlarmAudio = () => {
  if (!alarmAudioCtx || alarmAudioCtx.state === 'closed') {
    try { alarmAudioCtx = new AudioContext(); } catch { return; }
  }
  if (alarmAudioCtx.state === 'suspended') {
    alarmAudioCtx.resume().then(playAlarmPattern).catch(() => {});
  } else {
    playAlarmPattern();
  }
};

const stopAlarmAudio = () => {
  if (alarmBeepTimer) {
    clearTimeout(alarmBeepTimer);
    alarmBeepTimer = null;
  }
  if (alarmAudioCtx && alarmAudioCtx.state === 'running') {
    alarmAudioCtx.suspend().catch(() => {});
  }
};

const VIBRATE_PATTERN = [400, 200, 400, 200, 400];

const startAlarmVibration = () => {
  if (!('vibrate' in navigator)) return;
  if (!isVibrationEnabled()) return;
  navigator.vibrate(VIBRATE_PATTERN);
  alarmVibrateTimer = window.setInterval(() => {
    if (!isVibrationEnabled()) {
      stopAlarmVibration();
      return;
    }
    navigator.vibrate(VIBRATE_PATTERN);
  }, 3000);
};

const stopAlarmVibration = () => {
  if (alarmVibrateTimer) {
    window.clearInterval(alarmVibrateTimer);
    alarmVibrateTimer = null;
  }
  if ('vibrate' in navigator) navigator.vibrate(0);
};

const showAlarmOverlay = (item) => {
  if (!alarmOverlay) return;
  alarmGroupItems = [];
  if (alarmSingleModeEl) alarmSingleModeEl.hidden = false;
  if (alarmGroupModeEl) alarmGroupModeEl.hidden = true;
  if (alarmEyebrowEl) alarmEyebrowEl.textContent = `${slotTo12h(item.scheduled_time)} - Dose Due Now`;
  if (alarmTakeBtn) alarmTakeBtn.textContent = 'Take Now';
  if (alarmSkipBtn) alarmSkipBtn.textContent = 'Skip';
  if (alarmIndividualBtn) alarmIndividualBtn.hidden = true;
  if (alarmMedNameEl) alarmMedNameEl.textContent = item.name;
  if (alarmMedDoseEl) alarmMedDoseEl.textContent = item.dose;
  alarmOverlay.dataset.alarmMedicationId = item.medication_id;
  alarmOverlay.dataset.alarmScheduledDate = item.scheduled_date;
  alarmOverlay.dataset.alarmScheduledTime = item.scheduled_time;
  alarmOverlay.dataset.alarmTrackDoseFeedback = item.track_dose_feedback ? '1' : '0';
  alarmOverlay.dataset.alarmFeedbackType = item.feedback_type ?? (item.track_dose_feedback ? 'pain' : 'none');
  alarmOverlay.classList.add('is-active');
  lockBodyScroll();
  if (isSoundEnabled()) startAlarmAudio();
  startAlarmVibration();
};

const showGroupAlarmOverlay = (groupItems) => {
  if (!alarmOverlay || groupItems.length === 0) return;
  alarmGroupItems = groupItems;
  if (alarmSingleModeEl) alarmSingleModeEl.hidden = true;
  if (alarmGroupModeEl) alarmGroupModeEl.hidden = false;
  if (alarmEyebrowEl) alarmEyebrowEl.textContent = `${slotTo12h(groupItems[0].scheduled_time)} - Group Dose Due Now`;
  if (alarmTakeBtn) { alarmTakeBtn.textContent = 'Take All'; alarmTakeBtn.hidden = false; }
  if (alarmSkipBtn) { alarmSkipBtn.textContent = 'Skip All'; alarmSkipBtn.hidden = false; }
  if (alarmIndividualBtn) alarmIndividualBtn.hidden = false;
  if (alarmSnoozeRow) alarmSnoozeRow.hidden = false;
  if (alarmGroupNameEl) alarmGroupNameEl.textContent = groupItems[0].group_name ?? 'Medication Group';
  if (alarmGroupListEl) {
    alarmGroupListEl.innerHTML = '';
    groupItems.forEach((item) => {
      const li = document.createElement('li');
      li.className = 'alarm-group-list-item';
      li.dataset.medicationId = String(item.medication_id);
      li.dataset.scheduledDate = String(item.scheduled_date);
      li.dataset.scheduledTime = String(item.scheduled_time);
      li.dataset.groupId = String(item.group_id ?? '');
      li.dataset.trackDoseFeedback = item.track_dose_feedback ? '1' : '0';
      li.dataset.feedbackType = item.feedback_type ?? (item.track_dose_feedback ? 'pain' : 'none');
      li.textContent = `${item.name} — ${item.dose}`;
      alarmGroupListEl.appendChild(li);
    });
  }
  alarmOverlay.classList.add('is-active');
  lockBodyScroll();
  if (isSoundEnabled()) startAlarmAudio();
  startAlarmVibration();
};

const hideAlarmOverlay = () => {
  if (!alarmOverlay) return;
  if (!alarmOverlay.classList.contains('is-active')) return;
  alarmOverlay.classList.remove('is-active');
  alarmGroupItems = [];
  if (alarmIndividualBtn) alarmIndividualBtn.hidden = true;
  unlockBodyScroll();
  stopAlarmAudio();
  stopAlarmVibration();
};

const isAnyModalOpen = () =>
  [medicationModal, postponeModal, doseFeedbackModal, medPlanModal, painGraphModal, moodGraphModal,
   imageLightbox, medDetailModal, refillModal, refillHistoryModal, missedDoseModal, instructionsModal,
   discontinueModal, adjustQtyModal]
    .some((m) => m?.classList.contains('is-open')) ||
  (groupFormWrap != null && groupFormWrap.classList.contains('is-open')) ||
  (notifPanel != null && !notifPanel.hidden);

const alarmAction = async (action, extra = {}) => {
  const medicationId = alarmOverlay?.dataset.alarmMedicationId ?? '';
  const scheduledDate = alarmOverlay?.dataset.alarmScheduledDate ?? '';
  const scheduledTime = alarmOverlay?.dataset.alarmScheduledTime ?? '';

  const params = new URLSearchParams({
    csrf_token: getCsrfToken(),
    json_response: '1',
    action,
    medication_id: medicationId,
    scheduled_date: scheduledDate,
    scheduled_time: scheduledTime,
    ...extra,
  });

  try {
    const resp = await window.fetch('index.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString(),
    });
    const data = await resp.json();
    if (data.ok) {
      hideAlarmOverlay();
      if (!isAnyModalOpen()) window.location.reload();
      return;
    }
  } catch {
    // fall through
  }
  hideAlarmOverlay();
  if (!isAnyModalOpen()) window.location.reload();
};

// ── Sequential feedback queue ─────────────────────────────────────────────────

let feedbackQueue = [];
let feedbackQueueMode = false;
let feedbackQueueFailures = [];
const feedbackQueueProgressEl = document.querySelector('[data-feedback-queue-progress]');

const postDose = async (medicationId, scheduledDate, scheduledTime, status, note, painLevel = '', groupId = '', moodLevel = '') => {
  const params = new URLSearchParams({
    csrf_token: getCsrfToken(),
    json_response: '1',
    action: 'mark_dose',
    medication_id: medicationId,
    scheduled_date: scheduledDate,
    scheduled_time: scheduledTime,
    status,
    note,
    pain_level: painLevel,
    mood_level: moodLevel,
  });
  if (groupId) params.set('group_id', String(groupId));
  try {
    const res = await window.fetch('index.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString(),
    });
    const data = await res.json();
    return { ok: !!data.ok, error: data.error ?? null };
  } catch {
    return { ok: false, error: null };
  }
};

const postStandalonePainMoodLog = async ({ medicationId, logType, painLevel, moodLevel, note }) => {
  const params = new URLSearchParams({
    csrf_token: getCsrfToken(),
    json_response: '1',
    action: 'log_pain_mood',
    medication_id: String(medicationId),
    log_type: logType,
    note: note ?? '',
  });
  if (painLevel != null) params.set('pain_level', String(painLevel));
  if (moodLevel != null) params.set('mood_level', String(moodLevel));
  try {
    const res = await window.fetch('index.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString(),
    });
    const data = await res.json();
    return { ok: !!data.ok, id: data.id ?? null, error: data.error ?? null };
  } catch {
    return { ok: false, id: null, error: null };
  }
};

const editPainMoodLog = async ({ source, id, painLevel, moodLevel, note }) => {
  const params = new URLSearchParams({
    csrf_token: getCsrfToken(),
    json_response: '1',
    action: 'edit_pain_mood_log',
    source,
    id: String(id),
    note: note ?? '',
  });
  if (painLevel != null) params.set('pain_level', String(painLevel));
  if (moodLevel != null) params.set('mood_level', String(moodLevel));
  try {
    const res = await window.fetch('index.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString(),
    });
    const data = await res.json();
    return { ok: !!data.ok, error: data.error ?? null };
  } catch {
    return { ok: false, error: null };
  }
};

const alertDoseFailures = (failures) => {
  if (failures.length === 0) return;
  const lines = failures.map((f) => `${f.name}: ${f.error || 'Failed to log dose.'}`);
  alert(`Some doses could not be logged:\n${lines.join('\n')}`);
};

const postPostpone = async (medicationId, scheduledDate, scheduledTime, delayMinutes) => {
  const params = new URLSearchParams({
    csrf_token: getCsrfToken(),
    json_response: '1',
    action: 'postpone_dose',
    medication_id: medicationId,
    scheduled_date: scheduledDate,
    scheduled_time: scheduledTime,
    postpone_minutes: delayMinutes,
  });
  try {
    await window.fetch('index.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString(),
    });
  } catch {
    // best-effort
  }
};

const processNextFeedbackQueueItem = () => {
  if (feedbackQueue.length === 0) {
    feedbackQueueMode = false;
    alertDoseFailures(feedbackQueueFailures);
    feedbackQueueFailures = [];
    if (!isAnyModalOpen()) window.location.reload();
    return;
  }
  const item = feedbackQueue[0];
  const total = item.totalInBatch;
  const pos = item.positionInBatch;
  if (feedbackQueueProgressEl) {
    feedbackQueueProgressEl.textContent = `${item.name} (${pos} of ${total})`;
    feedbackQueueProgressEl.hidden = false;
  }
  feedbackQueueMode = true;
  const itemFeedbackType = item.feedback_type ?? (item.track_dose_feedback ? 'pain' : 'none');
  openDoseFeedbackModal(item.medication_id, item.scheduled_date, item.scheduled_time, itemFeedbackType, false);
};

const submitCurrentQueueItem = async (note, painLevel, moodLevel = '') => {
  const item = feedbackQueue.shift();
  if (!item) return;
  const result = await postDose(item.medication_id, item.scheduled_date, item.scheduled_time, 'taken', note, painLevel, item.group_id ?? '', moodLevel);
  if (!result.ok) feedbackQueueFailures.push({ name: item.name, error: result.error });
  closeDoseFeedbackModal();
  if (feedbackQueueProgressEl) feedbackQueueProgressEl.hidden = true;
  processNextFeedbackQueueItem();
};

// ── Alarm button handlers ─────────────────────────────────────────────────────

alarmTakeBtn?.addEventListener('click', async () => {
  if (alarmGroupItems.length > 0) {
    // Group mode: log non-feedback meds immediately, queue feedback meds
    const nonFeedback = alarmGroupItems.filter((i) => !i.track_dose_feedback);
    const withFeedback = alarmGroupItems.filter((i) => i.track_dose_feedback);

    stopAlarmAudio();
    hideAlarmOverlay();

    const failures = [];
    for (const item of nonFeedback) {
      const result = await postDose(item.medication_id, item.scheduled_date, item.scheduled_time, 'taken', '', '', item.group_id ?? '');
      if (!result.ok) failures.push({ name: item.name, error: result.error });
    }

    if (withFeedback.length === 0) {
      alertDoseFailures(failures);
      window.location.reload();
      return;
    }

    feedbackQueueFailures = failures;
    feedbackQueue = withFeedback.map((item, idx) => ({
      ...item,
      positionInBatch: idx + 1,
      totalInBatch: withFeedback.length,
    }));
    processNextFeedbackQueueItem();
  } else {
    // Single mode
    const trackFeedback = alarmOverlay?.dataset.alarmTrackDoseFeedback === '1';
    if (trackFeedback) {
      const medicationId = alarmOverlay?.dataset.alarmMedicationId ?? '';
      const scheduledDate = alarmOverlay?.dataset.alarmScheduledDate ?? '';
      const scheduledTime = alarmOverlay?.dataset.alarmScheduledTime ?? '';
      stopAlarmAudio();
      hideAlarmOverlay();
      openDoseFeedbackModal(medicationId, scheduledDate, scheduledTime, alarmOverlay?.dataset.alarmFeedbackType ?? 'pain', true);
    } else {
      alarmAction('mark_dose', { status: 'taken', note: '' });
    }
  }
});

alarmSkipBtn?.addEventListener('click', async () => {
  if (alarmGroupItems.length > 0) {
    const items = [...alarmGroupItems];
    hideAlarmOverlay();
    const failures = [];
    for (const item of items) {
      const result = await postDose(item.medication_id, item.scheduled_date, item.scheduled_time, 'skipped', 'Skipped dose');
      if (!result.ok) failures.push({ name: item.name, error: result.error });
    }
    alertDoseFailures(failures);
    window.location.reload();
  } else {
    alarmAction('mark_dose', { status: 'skipped', note: 'Skipped dose' });
  }
});

alarmSnoozeBtn?.addEventListener('click', async () => {
  const minutes = alarmSnoozeMinutesEl?.value ?? '5';
  if (alarmGroupItems.length > 0) {
    const items = [...alarmGroupItems];
    hideAlarmOverlay();
    for (const item of items) {
      await postPostpone(item.medication_id, item.scheduled_date, item.scheduled_time, minutes);
    }
    window.location.reload();
  } else {
    alarmAction('postpone_dose', { postpone_minutes: minutes });
  }
});

// ── Group alarm: individual medication management ─────────────────────────────

alarmIndividualBtn?.addEventListener('click', () => {
  if (alarmGroupItems.length === 0 || !alarmGroupListEl) return;

  stopAlarmAudio();
  stopAlarmVibration();

  // Hide bulk-action buttons; leave overlay open so user can act per item.
  if (alarmTakeBtn) alarmTakeBtn.hidden = true;
  if (alarmSkipBtn) alarmSkipBtn.hidden = true;
  if (alarmIndividualBtn) alarmIndividualBtn.hidden = true;
  if (alarmSnoozeRow) alarmSnoozeRow.hidden = true;

  const tpl = document.getElementById('alarm-item-actions-tpl');
  const snoozeMinutes = alarmSnoozeMinutesEl?.value ?? '5';

  alarmGroupListEl.querySelectorAll('.alarm-group-list-item').forEach((li) => {
    const medicationId = li.dataset.medicationId ?? '';
    const scheduledDate = li.dataset.scheduledDate ?? '';
    const scheduledTime = li.dataset.scheduledTime ?? '';
    const groupId = li.dataset.groupId ?? '';
    const trackFeedback = li.dataset.trackDoseFeedback === '1';
    const feedbackType = li.dataset.feedbackType ?? (trackFeedback ? 'pain' : 'none');

    const actions = tpl ? (tpl.content.cloneNode(true)) : null;
    if (!actions) return;

    const takeBtn = actions.querySelector('[data-item-take]');
    const skipBtn = actions.querySelector('[data-item-skip]');
    const snoozeBtn = actions.querySelector('[data-item-snooze]');

    const markDone = (label) => {
      li.querySelectorAll('.alarm-item-actions').forEach((el) => el.remove());
      const status = document.createElement('span');
      status.className = 'alarm-item-status';
      status.textContent = label;
      li.appendChild(status);
    };

    takeBtn?.addEventListener('click', async () => {
      if (trackFeedback) {
        stopAlarmAudio();
        // Lower the alarm overlay so the feedback modal (z-index 1000) is visible.
        if (alarmOverlay) alarmOverlay.classList.add('is-behind-modal');
        manageEachFeedbackMeta = { groupId, markDone };
        openDoseFeedbackModal(medicationId, scheduledDate, scheduledTime, feedbackType, false);
        // markDone is deferred until feedback is submitted or skipped.
      } else {
        const result = await postDose(medicationId, scheduledDate, scheduledTime, 'taken', '', '', groupId);
        if (result.ok) {
          markDone('Taken ✓');
        } else {
          markDone('Failed ✗');
          alert(result.error || 'Failed to log dose.');
        }
      }
    });

    skipBtn?.addEventListener('click', async () => {
      const result = await postDose(medicationId, scheduledDate, scheduledTime, 'skipped', 'Skipped dose');
      if (result.ok) {
        markDone('Skipped');
      } else {
        markDone('Failed ✗');
        alert(result.error || 'Failed to log dose.');
      }
    });

    snoozeBtn?.addEventListener('click', async () => {
      await postPostpone(medicationId, scheduledDate, scheduledTime, snoozeMinutes);
      markDone(`Snoozed ${snoozeMinutes}m`);
    });

    li.appendChild(actions);
  });

  // Add a Done button to dismiss after per-item actions.
  const doneBtn = document.createElement('button');
  doneBtn.type = 'button';
  doneBtn.className = 'alarm-take-btn alarm-individual-done-btn';
  doneBtn.textContent = 'Done';
  doneBtn.addEventListener('click', () => {
    hideAlarmOverlay();
    window.location.reload();
  });
  alarmGroupListEl.after(doneBtn);
});

// ── Reminders & polling ───────────────────────────────────────────────────────

const enableRemindersButton = document.querySelector('[data-enable-reminders]');
const soundToggle = document.querySelector('[data-sound-toggle]');
const vibrationToggle = document.querySelector('[data-vibration-toggle]');
const inAppAlert = document.querySelector('[data-in-app-alert]');
const reminderStatus = document.querySelector('[data-reminder-status]');

const showFallbackAlert = (items) => {
  if (!inAppAlert) return;
  if (items.length === 0) {
    inAppAlert.hidden = true;
    inAppAlert.textContent = '';
    return;
  }
  const top = items[0];
  if (top.group_id) {
    const groupItems = items.filter((i) => i.group_id === top.group_id);
    inAppAlert.textContent = `Dose reminder: ${top.group_name} (${groupItems.length} medication${groupItems.length !== 1 ? 's' : ''}) is due now.`;
  } else {
    inAppAlert.textContent = `Dose reminder: ${top.name} ${top.dose} is due now.`;
  }
  inAppAlert.hidden = false;
};

const notifyItems = (items) => {
  if (items.length === 0) {
    showFallbackAlert([]);
    return;
  }

  const seenMap = readSeenMap();
  const now = Date.now();
  const nowIso = new Date(now).toISOString();

  const itemKey = (item) =>
    `${item.medication_id}|${item.scheduled_date}|${item.scheduled_time}|${item.postponed_until ?? ''}`;

  const unseen = items.filter((item) => {
    const key = itemKey(item);
    const lastSeen = seenMap[key];
    if (!lastSeen) return true;
    return now - new Date(lastSeen).getTime() > SEEN_EXPIRY_MS;
  });

  if (unseen.length === 0) {
    showFallbackAlert([]);
    return;
  }

  unseen.forEach((item) => {
    seenMap[itemKey(item)] = nowIso;
  });
  writeSeenMap(seenMap);

  if ('Notification' in window && Notification.permission === 'granted') {
    const notifiedGroupIds = new Set();
    unseen.forEach((item) => {
      if (item.group_id) {
        if (notifiedGroupIds.has(item.group_id)) return;
        notifiedGroupIds.add(item.group_id);
        const groupItems = unseen.filter((i) => i.group_id === item.group_id);
        const body = groupItems.map((i) => `${i.name} ${i.dose}`).join(', ');
        if (swRegistration) {
          swRegistration.showNotification(item.group_name ?? 'Medication Group', { body });
        } else {
          new Notification(item.group_name ?? 'Medication Group', { body });
        }
      } else {
        const dueText = item.postponed_until ? 'Snoozed dose due now' : 'Dose due now';
        const title = `${item.name} (${item.dose})`;
        if (swRegistration) {
          swRegistration.showNotification(title, { body: dueText });
        } else {
          new Notification(title, { body: dueText });
        }
      }
    });
    showFallbackAlert([]);
  } else {
    showFallbackAlert(unseen);
  }

  if (!alarmOverlay?.classList.contains('is-active')) {
    // Show group alarm if first unseen item belongs to a group with 2+ members due
    const firstGroupId = unseen[0]?.group_id;
    if (firstGroupId) {
      const groupItems = unseen.filter((i) => i.group_id === firstGroupId);
      if (groupItems.length >= 2) {
        showGroupAlarmOverlay(groupItems);
      } else {
        showAlarmOverlay(unseen[0]);
      }
    } else {
      showAlarmOverlay(unseen[0]);
    }
  }
};

const pollDueReminders = async () => {
  try {
    const response = await window.fetch('index.php?action=poll_due', { credentials: 'same-origin' });
    if (!response.ok) return;
    const payload = await response.json();
    if (!payload || payload.ok !== true || !Array.isArray(payload.items)) return;
    notifyItems(payload.items);
  } catch {
    // swallow polling errors
  }
};

enableRemindersButton?.addEventListener('change', async () => {
  try {
    if (!window.isSecureContext) {
      window.alert('Reminders require a secure context (HTTPS or localhost).');
      return;
    }

    const existing = await currentPushSubscription();
    if (existing && !enableRemindersButton.checked) {
      const endpoint = existing.endpoint;
      await existing.unsubscribe();
      await removePushSubscription(endpoint);
      setReminderToggleState(false);
      window.alert('Background reminders disabled for this device and browser profile.');
      return;
    }
    if (existing && enableRemindersButton.checked) {
      setReminderToggleState(true);
      return;
    }

    if (!('Notification' in window)) {
      window.alert('Notifications are not supported in this browser. In-app reminders will still appear.');
      pollDueReminders();
      return;
    }
    const permission = Notification.permission === 'default'
      ? await Notification.requestPermission()
      : Notification.permission;
    if (permission !== 'granted') {
      if (permission === 'denied') {
        window.alert('Notifications are blocked for this site. Enable browser/site notification permission first.');
        return;
      }
      window.alert('Notifications were not enabled. In-app reminders will still appear while this page is open.');
      pollDueReminders();
      return;
    }
    const activeRegistration = await registerServiceWorker();
    if (!activeRegistration) {
      window.alert('Reminders enabled (in-app alarm active). Push notifications require a service worker.');
      pollDueReminders();
      return;
    }
    const vapidPublicKey = await fetchPushPublicKey();
    if (!vapidPublicKey) {
      window.alert('Reminders enabled (in-app alarm active). Push key not yet configured on the server.');
      pollDueReminders();
      return;
    }
    const existingAfterRegister = await activeRegistration.pushManager.getSubscription();
    const subscription = existingAfterRegister ?? await activeRegistration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
    });
    await savePushSubscription(subscription);
    setReminderToggleState(true);
    window.alert('Reminders enabled for this device and browser profile.');
  } catch (error) {
    const subscription = await currentPushSubscription().catch(() => null);
    setReminderToggleState(Boolean(subscription));
    const detail = error instanceof Error && error.message ? `\n\nDetails: ${error.message}` : '';
    window.alert(`Could not update reminders on this device.${detail}`);
  }
  pollDueReminders();
});

const initAlarmToggles = () => {
  if (soundToggle) soundToggle.checked = isSoundEnabled();
  if (vibrationToggle) vibrationToggle.checked = isVibrationEnabled();
};

soundToggle?.addEventListener('change', () => {
  if (soundToggle.checked) {
    window.localStorage.removeItem('rxtracker_sound_enabled');
  } else {
    window.localStorage.setItem('rxtracker_sound_enabled', '0');
    stopAlarmAudio();
  }
});

vibrationToggle?.addEventListener('change', () => {
  if (vibrationToggle.checked) {
    window.localStorage.removeItem('rxtracker_vibration_enabled');
  } else {
    window.localStorage.setItem('rxtracker_vibration_enabled', '0');
  }
});

const initializeReminderToggle = async () => {
  if (!enableRemindersButton) return;
  try {
    const subscription = await currentPushSubscription();
    setReminderToggleState(Boolean(subscription));
  } catch (error) {
    setReminderToggleState(false);
  }
};

initAlarmToggles();
initializeReminderToggle();

registerServiceWorker().catch(() => {
  // keep reminder polling working even if SW registration fails
});
pollDueReminders();
window.setInterval(pollDueReminders, 30000);

document.addEventListener('visibilitychange', () => {
  if (!document.hidden) pollDueReminders();
});

// Unlock AudioContext on first user interaction (required on mobile browsers)
let audioUnlocked = false;
const unlockAudioOnInteraction = () => {
  if (audioUnlocked) return;
  audioUnlocked = true;
  try {
    const ctx = new AudioContext();
    ctx.resume().then(() => ctx.close()).catch(() => {});
  } catch {}
};
document.addEventListener('touchend', unlockAudioOnInteraction, { passive: true, once: true });
document.addEventListener('click', unlockAudioOnInteraction, { once: true });

if (medicationModal?.classList.contains('is-open')) {
  lockBodyScroll();
}

// ── Medication form schedule/time-format UI ───────────────────────────────────

const medicationForm = document.querySelector('.medication-form');

if (medicationForm) {
  const scheduleMode = medicationForm.querySelector('select[name="schedule_mode"]');
  const doseTimesSection = medicationForm.querySelector('[data-dose-times-section]');
  const doseTimeRows = medicationForm.querySelector('[data-dose-time-rows]');
  const addDoseTimeBtn = medicationForm.querySelector('[data-add-dose-time]');
  const intervalHoursInput = medicationForm.querySelector('input[name="interval_hours"]');
  const firstDoseInput = medicationForm.querySelector('input[name="first_dose_time"]');
  const intervalLabel = intervalHoursInput?.closest('label');
  const firstDoseLabel = firstDoseInput?.closest('label');

  const parse12h = (value) => {
    const match = value.trim().match(/^(0?[1-9]|1[0-2]):([0-5]\d)\s*([AaPp][Mm])$/);
    if (!match) return null;
    let hour = Number.parseInt(match[1], 10);
    const minute = Number.parseInt(match[2], 10);
    const period = match[3].toUpperCase();
    if (period === 'AM') {
      hour = hour === 12 ? 0 : hour;
    } else {
      hour = hour === 12 ? 12 : hour + 12;
    }
    return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
  };

  const parse24h = (value) => {
    const match = value.trim().match(/^([01]\d|2[0-3]):([0-5]\d)$/);
    if (!match) return null;
    return `${match[1]}:${match[2]}`;
  };

  const to12h = (value) => {
    const parsed = parse24h(value);
    if (!parsed) return null;
    const [hourStr, minuteStr] = parsed.split(':');
    const hour = Number.parseInt(hourStr, 10);
    const minute = Number.parseInt(minuteStr, 10);
    const period = hour >= 12 ? 'PM' : 'AM';
    const hour12 = hour % 12 === 0 ? 12 : hour % 12;
    return `${hour12}:${String(minute).padStart(2, '0')} ${period}`;
  };

  const normalizeToken = (token) => {
    const from12 = parse12h(token);
    if (from12) return to12h(from12);
    const from24 = parse24h(token);
    if (from24) return to12h(from24);
    return null;
  };

  const normalizeCommaTimes = (raw) => {
    const parts = raw.split(',').map((part) => part.trim()).filter(Boolean);
    const normalized = [];
    for (const part of parts) {
      const value = normalizeToken(part);
      if (!value) return null;
      normalized.push(value);
    }
    return normalized.join(', ');
  };

  const buildDoseTimeRow = () => {
    const row = document.createElement('div');
    row.className = 'dose-time-row';
    row.innerHTML = `
      <input type="text" name="dose_times[]" placeholder="8:00 AM" class="dose-time-field" autocomplete="off">
      <input type="number" name="dose_qtys[]" min="0.25" step="0.25" placeholder="Qty (default)" class="dose-qty-field">
      <button type="button" class="btn-icon remove-dose-time" aria-label="Remove time">−</button>
    `;
    const timeInput = row.querySelector('.dose-time-field');
    if (timeInput) setupTimeAutoColon(timeInput, false);
    return row;
  };

  if (addDoseTimeBtn && doseTimeRows) {
    addDoseTimeBtn.addEventListener('click', () => {
      doseTimeRows.appendChild(buildDoseTimeRow());
    });
  }

  if (doseTimeRows) {
    doseTimeRows.addEventListener('click', (e) => {
      const btn = e.target.closest('.remove-dose-time');
      if (!btn) return;
      const rows = doseTimeRows.querySelectorAll('.dose-time-row');
      if (rows.length <= 1) {
        const timeInput = btn.closest('.dose-time-row')?.querySelector('.dose-time-field');
        const qtyInput = btn.closest('.dose-time-row')?.querySelector('.dose-qty-field');
        if (timeInput) timeInput.value = '';
        if (qtyInput) qtyInput.value = '';
      } else {
        btn.closest('.dose-time-row')?.remove();
      }
    });

  }

  const applyScheduleVisibility = () => {
    const intervalMode = scheduleMode?.value === 'interval';
    if (doseTimesSection) doseTimesSection.style.display = intervalMode ? 'none' : '';
    if (intervalLabel) intervalLabel.style.display = intervalMode ? '' : 'none';
    if (firstDoseLabel) firstDoseLabel.style.display = intervalMode ? '' : 'none';
    if (intervalHoursInput) intervalHoursInput.required = intervalMode;
    if (firstDoseInput) firstDoseInput.required = intervalMode;
  };

  scheduleMode?.addEventListener('change', applyScheduleVisibility);

  applyScheduleVisibility();

  medicationForm.addEventListener('submit', (event) => {
    const intervalMode = scheduleMode?.value === 'interval';

    if (!intervalMode && doseTimeRows) {
      const timeFields = doseTimeRows.querySelectorAll('.dose-time-field');
      const filledFields = [...timeFields].filter((f) => f.value.trim() !== '');
      if (filledFields.length === 0) {
        event.preventDefault();
        window.alert('Add at least one dose time, or switch to "Every X hours" schedule.');
        return;
      }
      for (const field of filledFields) {
        const normalized = normalizeToken(field.value.trim());
        if (!normalized) {
          event.preventDefault();
          window.alert('Invalid dose time "' + field.value + '". Use h:MM AM/PM format (e.g. 8:00 AM).');
          field.focus();
          return;
        }
        field.value = normalized;
      }
    }

    if (intervalMode && firstDoseInput && firstDoseInput.value.trim() !== '') {
      const normalized = normalizeToken(firstDoseInput.value);
      if (!normalized) {
        event.preventDefault();
        window.alert('Invalid first dose time. Use h:MM AM/PM format (e.g. 8:00 AM).');
        return;
      }
      firstDoseInput.value = normalized;
    }
  });
}

// ── Inventory type dynamic fields ────────────────────────────────────────────

if (medicationForm) {
  const doseFormSelect    = medicationForm.querySelector('[data-dailymed-dose-form]');
  const invQtyLabel       = medicationForm.querySelector('[data-inv-qty-label]');
  const invLiquidLabel    = medicationForm.querySelector('[data-inv-liquid-label]');
  const invUnitLabels     = medicationForm.querySelectorAll('[data-inv-unit-label]');
  const bottleUnitSelect  = medicationForm.querySelector('[data-bottle-unit-select]');

  const DOSE_FORM_TO_INV = {
    tablet: 'pills', capsule: 'pills',
    liquid: 'liquid', inhaler: 'inhaler',
    injection: 'injection', patch: 'patch', drops: 'drops', other: 'other',
  };

  const inventoryUnits = {
    pills:     'tablets',
    liquid:    'mL',
    inhaler:   'puffs',
    injection: 'units',
    patch:     'patches',
    drops:     'drops',
    other:     'units',
  };

  const applyInventoryVisibility = () => {
    const invType = DOSE_FORM_TO_INV[doseFormSelect?.value ?? ''] ?? 'pills';
    const isLiquid = invType === 'liquid';
    if (invQtyLabel)    invQtyLabel.style.display    = isLiquid ? 'none' : '';
    if (invLiquidLabel) invLiquidLabel.style.display = isLiquid ? ''     : 'none';
    const unit = isLiquid ? (bottleUnitSelect?.value ?? 'mL') : (inventoryUnits[invType] ?? 'units');
    invUnitLabels.forEach((el) => { el.textContent = unit; });
  };

  doseFormSelect?.addEventListener('change', applyInventoryVisibility);
  bottleUnitSelect?.addEventListener('change', applyInventoryVisibility);
  applyInventoryVisibility();
}

// ── Drug name autocomplete ────────────────────────────────────────────────────

const medNameInput = document.querySelector('[data-med-name-input]');
const autocompleteDropdown = document.querySelector('[data-autocomplete-dropdown]');
const setIdInput = document.querySelector('[data-set-id-input]');
let autocompleteTimer = null;

const hideDrugDropdown = () => {
  if (autocompleteDropdown) autocompleteDropdown.hidden = true;
};

// Matches a dose suffix like "50 MG", "0.5 MCG", "10 ML", "100 UNITS", "20 IU", "5%"
const DOSE_SUFFIX_RE = /^(.*?)\s+(\d[\d.]*\s*(?:MG|MCG|ML|UNITS?|IU|%)\b.*)$/i;

const showDrugDropdown = (items) => {
  if (!autocompleteDropdown) return;
  autocompleteDropdown.innerHTML = '';
  if (!items.length) { hideDrugDropdown(); return; }
  items.forEach(({ name }) => {
    const li = document.createElement('li');
    li.className = 'autocomplete-item';

    const nameSpan = document.createElement('span');
    nameSpan.textContent = name;
    li.appendChild(nameSpan);

    li.addEventListener('mousedown', (e) => {
      e.preventDefault();
      const doseMatch = name.match(DOSE_SUFFIX_RE);
      const baseName = doseMatch ? doseMatch[1] : name;
      const parsedDose = doseMatch ? doseMatch[2].trim() : null;
      if (medNameInput) medNameInput.value = baseName;
      if (parsedDose) {
        const form = medNameInput?.closest('form');
        const doseAmountInput = form?.querySelector('[data-dailymed-dose-amount]');
        const doseUnitSelect  = form?.querySelector('[data-dailymed-dose-unit]');
        if (doseAmountInput && doseUnitSelect && !doseAmountInput.value.trim()) {
          const dosePartMatch = parsedDose.match(/^([\d.]+)\s*([A-Z%]+)/i);
          if (dosePartMatch) {
            doseAmountInput.value = dosePartMatch[1];
            const rawUnit = dosePartMatch[2].toUpperCase();
            const unitMap = { MG: 'mg', MCG: 'mcg', G: 'g', ML: 'mL', TSP: 'tsp', TBSP: 'tbsp', OZ: 'oz', IU: 'IU', UNITS: 'units', UNIT: 'units', DROPS: 'drops', PUFFS: 'puffs', PATCHES: 'patches' };
            const mappedUnit = unitMap[rawUnit];
            if (mappedUnit) {
              const opt = Array.from(doseUnitSelect.options).find((o) => o.value === mappedUnit);
              if (opt) doseUnitSelect.value = mappedUnit;
            }
          }
        }
      }
      hideDrugDropdown();
      fetchAndSetSplId(name); // use full name (with dose) for the most specific SPL match
    });
    autocompleteDropdown.appendChild(li);
  });
  autocompleteDropdown.hidden = false;
};

const fetchDrugSuggestions = async (query) => {
  try {
    const q = encodeURIComponent(query + '*');
    const res = await fetch(
      apiProxy(`https://api.fda.gov/drug/label.json?search=(openfda.brand_name:${q}+OR+openfda.generic_name:${q})&limit=10`)
    );
    if (!res.ok) { hideDrugDropdown(); return; }
    const data = await res.json();
    const seen = new Set();
    const names = [];
    const queryUpper = query.toUpperCase();
    for (const result of (data?.results ?? [])) {
      for (const name of [...(result.openfda?.brand_name ?? []), ...(result.openfda?.generic_name ?? [])]) {
        const key = name.toUpperCase();
        if (!seen.has(key) && key.includes(queryUpper)) { seen.add(key); names.push(name); }
      }
    }
    if (!names.length) { hideDrugDropdown(); return; }
    showDrugDropdown(names.map((name) => ({ name })));
  } catch { hideDrugDropdown(); }
};

const SPL_FORM_MAP = {
  tablet: 'tablet', tablets: 'tablet',
  capsule: 'capsule', capsules: 'capsule',
  solution: 'liquid', liquid: 'liquid', syrup: 'liquid', suspension: 'liquid', elixir: 'liquid',
  inhaler: 'inhaler', inhalation: 'inhaler', aerosol: 'inhaler',
  injection: 'injection', injectable: 'injection',
  patch: 'patch', transdermal: 'patch',
  drops: 'drops', ophthalmic: 'drops', otic: 'drops',
};

const fetchAndSetSplId = async (name) => {
  if (!setIdInput) return;
  setIdInput.value = '';
  try {
    const res = await fetch(
      apiProxy(`https://dailymed.nlm.nih.gov/dailymed/services/v2/spls.json?drug_name=${encodeURIComponent(name)}&pagesize=1`)
    );
    if (!res.ok) return;
    const data = await res.json();
    const first = data?.data?.[0];
    setIdInput.value = first?.setid ?? '';

    // Try to pre-fill dose_form from the SPL title (e.g. "IBUPROFEN 200 MG Oral Tablet")
    const title = (first?.title ?? '').toLowerCase();
    if (title) {
      const form = setIdInput.closest('form');
      const doseFormSelect = form?.querySelector('[data-dailymed-dose-form]');
      if (doseFormSelect && !doseFormSelect.value) {
        for (const [keyword, mapped] of Object.entries(SPL_FORM_MAP)) {
          if (title.includes(keyword)) {
            const opt = Array.from(doseFormSelect.options).find((o) => o.value === mapped);
            if (opt) { doseFormSelect.value = mapped; doseFormSelect.dispatchEvent(new Event('change')); break; }
          }
        }
      }
    }
  } catch {}
};

if (medNameInput) {
  medNameInput.addEventListener('input', () => {
    clearTimeout(autocompleteTimer);
    // Any manual edit to the name invalidates the previously-fetched set_id
    if (setIdInput) setIdInput.value = '';
    const v = medNameInput.value.trim();
    if (v.length < 3) { hideDrugDropdown(); return; }
    autocompleteTimer = setTimeout(() => fetchDrugSuggestions(v), 300);
  });
  medNameInput.addEventListener('blur', () => { setTimeout(hideDrugDropdown, 150); });
  medNameInput.addEventListener('keydown', (e) => { if (e.key === 'Escape') hideDrugDropdown(); });
}

// ── Dose-form icons ───────────────────────────────────────────────────────────

const _SVG_ATTRS = 'xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';

const ICON_TABLET  = `<svg ${_SVG_ATTRS}><circle cx="12" cy="12" r="9"/><line x1="3" y1="12" x2="21" y2="12"/></svg>`;
const ICON_CAPSULE = `<svg ${_SVG_ATTRS}><path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"/><line x1="8.5" y1="8.5" x2="15.5" y2="15.5"/></svg>`;
const ICON_BOTTLE  = `<svg ${_SVG_ATTRS}><rect x="9" y="1" width="6" height="3" rx="1"/><path d="M8 4h8l1 3v14a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1V7l1-3z"/><line x1="7" y1="14" x2="17" y2="14"/></svg>`;
const ICON_SPRAY   = `<svg ${_SVG_ATTRS}><path d="M7 3h4v5H7z"/><path d="M11 5h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h1"/><path d="M15 5c1 0 3-1 3-3"/><path d="M18 5l1.5-1.5M18 8l1.5 1.5M20 6.5h1.5"/></svg>`;
const ICON_DROPS   = `<svg ${_SVG_ATTRS}><path d="M12 2C8 8 6 11 6 14a6 6 0 0 0 12 0c0-3-2-6-6-12z"/></svg>`;
const ICON_INHALER = `<svg ${_SVG_ATTRS}><rect x="8" y="2" width="8" height="14" rx="3"/><path d="M8 10H5a1 1 0 0 0-1 1v3a1 1 0 0 0 1 1h3"/><path d="M5 16v3"/><line x1="12" y1="16" x2="12" y2="22"/></svg>`;
const ICON_SYRINGE = `<svg ${_SVG_ATTRS}><path d="m18 2 4 4"/><path d="m17 7 3-3"/><path d="M19 9 8.7 19.3c-1 1-2.5 1-3.4 0l-.6-.6c-1-1-1-2.5 0-3.4L15 5"/><path d="m9 11 4 4"/><path d="m5 19-3 3"/><path d="m14 4 6 6"/></svg>`;
const ICON_CREAM   = `<svg ${_SVG_ATTRS}><path d="M14 2H6a2 2 0 0 0-2 2v3h16V4a2 2 0 0 0-2-2h-4z"/><path d="M4 7v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7H4z"/><path d="M9 13c1 1 3 1 4 0"/><path d="M9 17c1 1 3 1 4 0"/></svg>`;

const ICON_MAP = {
  tablet:  ICON_TABLET,
  capsule: ICON_CAPSULE,
  bottle:  ICON_BOTTLE,
  spray:   ICON_SPRAY,
  drops:   ICON_DROPS,
  inhaler: ICON_INHALER,
  syringe: ICON_SYRINGE,
  cream:   ICON_CREAM,
};

// Resolve text to an icon key. Checks specific forms before generic liquid/capsule/tablet.
const resolveIconKey = (text) => {
  const t = (text ?? '').toLowerCase();
  if (/\bsyringe\b|inject|subcutaneous|intramuscular|intravenous/.test(t)) return 'syringe';
  if (/\binhaler\b|inhalation|aerosol|nebulizer|nebuliser/.test(t))         return 'inhaler';
  if (/\bspray\b|nasal spray|pump spray/.test(t))                           return 'spray';
  if (/\bdrops?\b|ophthalmic|otic|eye drop|ear drop/.test(t))               return 'drops';
  if (/cream|ointment|\bgel\b|lotion|topical|patch/.test(t))                return 'cream';
  if (/\/ml|ml\b|liquid|solution|suspension|syrup|elixir/.test(t))          return 'bottle';
  if (/capsule|softgel|gel\s*cap/.test(t))                                  return 'capsule';
  return 'tablet';
};

// Instant keyword-match on user-entered fields.
const iconFromLocalFields = (name, dose, instructions) =>
  ICON_MAP[resolveIconKey(`${name} ${dose} ${instructions}`)];

// ── Product label image (DailyMed) — used by View Details modal ───────────────

const PRODUCT_LABEL_CACHE_PREFIX = 'rxtracker_pillimg_v2_';
const PRODUCT_LABEL_TTL = 86400000;

// Key by setId when available so a medication edited to a different drug
// immediately resolves its own cache entry rather than reusing the old one.
const productLabelCacheKey = (medicationId, setId) => setId ? `s_${setId}` : `m_${medicationId}`;

const readProductLabelCache = (medicationId, setId) => {
  const key = PRODUCT_LABEL_CACHE_PREFIX + productLabelCacheKey(medicationId, setId);
  try {
    const raw = localStorage.getItem(key);
    if (!raw) return undefined;
    const { url, ts } = JSON.parse(raw);
    if (Date.now() - ts > PRODUCT_LABEL_TTL) { localStorage.removeItem(key); return undefined; }
    return url;
  } catch { return undefined; }
};

const writeProductLabelCache = (medicationId, setId, url) => {
  try { localStorage.setItem(PRODUCT_LABEL_CACHE_PREFIX + productLabelCacheKey(medicationId, setId), JSON.stringify({ url, ts: Date.now() })); } catch {}
};

const fetchProductLabelFromSetId = async (sid) => {
  try {
    const res = await fetch(
      apiProxy(`https://dailymed.nlm.nih.gov/dailymed/services/v2/spls/${encodeURIComponent(sid)}/media.json`)
    );
    if (!res.ok) return null;
    const data = await res.json();
    // Response: { data: { media: [{mime_type, url, name}, ...], setid, title, ... } }
    const mediaItems = data?.data?.media ?? [];
    // Prefer product label images over molecular figure or structure diagrams
    const item = mediaItems.find((d) =>
      (d.mime_type ?? '').startsWith('image/') && !/figure|structure|diagram/i.test(d.name ?? '')
    ) ?? mediaItems.find((d) => (d.mime_type ?? '').startsWith('image/'));
    return item?.url ?? null;
  } catch { return null; }
};

const fetchProductLabelUrl = async (setId, medicationName) => {
  // 1. DailyMed SPL media via stored setId
  if (setId) return fetchProductLabelFromSetId(setId);

  // 2. DailyMed name search — for manually-added meds without a setId
  if (!medicationName) return null;
  try {
    const res = await fetch(
      apiProxy(`https://dailymed.nlm.nih.gov/dailymed/services/v2/spls.json?drug_name=${encodeURIComponent(medicationName)}&pagesize=1`)
    );
    if (!res.ok) return null;
    const data = await res.json();
    const foundSetId = data?.data?.[0]?.setid;
    return foundSetId ? fetchProductLabelFromSetId(foundSetId) : null;
  } catch { return null; }
};

// ── Dose-form icon rendering for medication list rows ─────────────────────────

const DOSE_FORM_CACHE_PREFIX = 'rxtracker_doseform_v2_';
const DOSE_FORM_CACHE_TTL    = 604800000; // 7 days

const loadProductLabels = () => {
  document.querySelectorAll('[data-product-label-wrap]').forEach(async (wrap) => {
    const { setId = '', medicationName = '', dose = '', instructions = '' } = wrap.dataset;

    const setIcon = (icon) => {
      wrap.innerHTML = `<span class="product-label-placeholder">${icon}</span>`;
    };

    // 1. Instant: keyword match on user-entered fields
    setIcon(iconFromLocalFields(medicationName, dose, instructions));

    if (!setId) return;

    // 2. Check localStorage for a previously resolved icon key
    const cacheKey = DOSE_FORM_CACHE_PREFIX + setId;
    try {
      const raw = localStorage.getItem(cacheKey);
      if (raw) {
        const { iconKey, ts } = JSON.parse(raw);
        if (Date.now() - ts < DOSE_FORM_CACHE_TTL) {
          const icon = ICON_MAP[iconKey];
          if (icon) setIcon(icon);
          return;
        }
        localStorage.removeItem(cacheKey);
      }
    } catch {}

    // 3. Background fetch from FDA OpenData; combine prose fields for accurate matching
    try {
      const res  = await fetch(apiProxy(`https://api.fda.gov/drug/label.json?search=openfda.set_id:"${encodeURIComponent(setId)}"&limit=1`));
      if (!res.ok) return;
      const data = await res.json();
      const result = data?.results?.[0] ?? {};
      const productText = [
        result.how_supplied?.[0],
        result.dosage_forms_and_strengths?.[0],
        result.description?.[0],
        result.openfda?.dosage_form?.[0],
      ].filter(Boolean).join(' ');
      const iconKey = resolveIconKey(productText);
      try { localStorage.setItem(cacheKey, JSON.stringify({ iconKey, ts: Date.now() })); } catch {}
      setIcon(ICON_MAP[iconKey] ?? ICON_TABLET);
    } catch {}
  });
};

loadProductLabels();

// ── Pill image lightbox ───────────────────────────────────────────────────────

const imageLightbox = document.querySelector('[data-image-lightbox]');
const imageLightboxImg = document.querySelector('[data-lightbox-img]');
const imageLightboxCaption = document.querySelector('[data-lightbox-caption]');

const openImageLightbox = (imageUrl, medicationName) => {
  if (!imageLightbox || !imageLightboxImg) return;
  imageLightboxImg.src = imageUrl;
  imageLightboxImg.alt = medicationName;
  if (imageLightboxCaption) imageLightboxCaption.textContent = medicationName;
  imageLightbox.classList.add('is-open');
  lockBodyScroll();
};

const closeImageLightbox = () => {
  if (!imageLightbox?.classList.contains('is-open')) return;
  imageLightbox.classList.remove('is-open');
  unlockBodyScroll();
};

document.querySelector('[data-close-lightbox]')?.addEventListener('click', closeImageLightbox);
imageLightbox?.addEventListener('click', (e) => { if (e.target === imageLightbox) closeImageLightbox(); });

// ── Medication detail modal ───────────────────────────────────────────────────

const medDetailModal = document.querySelector('[data-med-detail-modal]');
const medDetailTitle = document.querySelector('[data-med-detail-title]');
const medDetailBody = document.querySelector('[data-med-detail-body]');
const medDetailCache = {};

const openMedDetailModal = () => {
  if (!medDetailModal) return;
  medDetailModal.classList.add('is-open');
  lockBodyScroll();
};

const closeMedDetailModal = () => {
  if (!medDetailModal?.classList.contains('is-open')) return;
  medDetailModal.classList.remove('is-open');
  unlockBodyScroll();
};

document.querySelector('[data-close-med-detail]')?.addEventListener('click', closeMedDetailModal);
medDetailModal?.addEventListener('click', (e) => { if (e.target === medDetailModal) closeMedDetailModal(); });

const getOfdaField = (data, ...fields) => {
  const result = data?.results?.[0];
  if (!result) return null;
  for (const f of fields) {
    const v = result[f];
    if (Array.isArray(v) && v[0]) return v[0];
  }
  return null;
};

const safeHtml = (str) => String(str ?? '')
  .replace(/&/g, '&amp;')
  .replace(/</g, '&lt;')
  .replace(/>/g, '&gt;');

const textBlock = (raw) => {
  if (!raw) return '';
  return raw.trim().split(/\n{2,}/).map((p) => `<p>${safeHtml(p.trim())}</p>`).join('');
};

const renderDetailContent = (name, setId, ofda, productLabelUrl) => {
  const sections = [];

  if (productLabelUrl) {
    sections.push(`<div class="med-detail-images">
      <figure class="med-detail-image-wrap" data-detail-img-url="${safeHtml(productLabelUrl)}">
        <img src="${safeHtml(productLabelUrl)}" alt="${safeHtml(name)} product label" loading="lazy">
        <figcaption>Product label</figcaption>
      </figure>
    </div>`);
  }

  const boxedWarning   = getOfdaField(ofda, 'boxed_warning');
  const indications    = getOfdaField(ofda, 'indications_and_usage');
  const activeIng      = getOfdaField(ofda, 'active_ingredient');
  const inactiveIng    = getOfdaField(ofda, 'inactive_ingredient');
  const adverseRx      = getOfdaField(ofda, 'adverse_reactions');
  const warnCautions   = getOfdaField(ofda, 'warnings_and_cautions');
  const dosageAdmin    = getOfdaField(ofda, 'dosage_and_administration');
  const warnings       = getOfdaField(ofda, 'warnings');
  const contraind      = getOfdaField(ofda, 'contraindications');
  const description    = getOfdaField(ofda, 'description');
  const howSupplied    = getOfdaField(ofda, 'how_supplied');
  const dosageStrength = getOfdaField(ofda, 'dosage_forms_and_strengths');

  const tabletInfo = howSupplied || dosageStrength || description;
  if (tabletInfo) {
    sections.push(`<details class="med-detail-section" open><summary class="med-detail-section-title">Tablet / Product Info</summary><div class="med-detail-section-body">${textBlock(tabletInfo)}</div></details>`);
  }

  if (indications) {
    sections.push(`<details class="med-detail-section"><summary class="med-detail-section-title">What it&rsquo;s used for</summary><div class="med-detail-section-body">${textBlock(indications)}</div></details>`);
  }

  if (activeIng || inactiveIng) {
    let ing = '';
    if (activeIng) ing += `<p><strong>Active:</strong></p>${textBlock(activeIng)}`;
    if (inactiveIng) ing += `<p><strong>Inactive:</strong></p>${textBlock(inactiveIng)}`;
    sections.push(`<details class="med-detail-section"><summary class="med-detail-section-title">Ingredients</summary><div class="med-detail-section-body">${ing}</div></details>`);
  }

  const sideEffects = adverseRx || warnCautions;
  if (sideEffects) {
    sections.push(`<details class="med-detail-section"><summary class="med-detail-section-title">Side Effects</summary><div class="med-detail-section-body">${textBlock(sideEffects)}</div></details>`);
  }

  if (dosageAdmin) {
    sections.push(`<details class="med-detail-section"><summary class="med-detail-section-title">How to Take This Medication</summary><div class="med-detail-section-body">${textBlock(dosageAdmin)}<p class="muted" style="font-size:0.82rem;margin-top:0.35rem">Always follow your prescriber&rsquo;s specific instructions.</p></div></details>`);
  }

  if (boxedWarning || warnings || contraind) {
    let warnHtml = '';
    if (boxedWarning) warnHtml += `<div class="boxed-warning-banner"><strong>&#9888; Boxed Warning</strong>${textBlock(boxedWarning)}</div>`;
    if (warnings) warnHtml += textBlock(warnings);
    if (contraind) warnHtml += `<p><strong>Contraindications:</strong></p>${textBlock(contraind)}`;
    sections.push(`<details class="med-detail-section"><summary class="med-detail-section-title">Warnings</summary><div class="med-detail-section-body">${warnHtml}</div></details>`);
  }

  const dailyMedLink = setId
    ? `<a href="https://dailymed.nlm.nih.gov/dailymed/lookup.cfm?setid=${encodeURIComponent(setId)}" target="_blank" rel="noopener">View full label on DailyMed &#8599;</a>`
    : '';

  if (!sections.length) {
    return `<p class="muted">Detailed information is not available for this medication.</p>${dailyMedLink ? `<div class="med-detail-links">${dailyMedLink}</div>` : ''}`;
  }

  return sections.join('') + `<div class="med-detail-links"><p class="disclaimer">This information is for general reference only. Always follow your doctor&rsquo;s or pharmacist&rsquo;s instructions.</p>${dailyMedLink}</div>`;
};

const fetchDoseChangeHistoryHtml = async (medicationId) => {
  if (!medicationId) return '';
  try {
    const params = new URLSearchParams({
      csrf_token: getCsrfToken(),
      json_response: '1',
      action: 'dose_change_history',
      medication_id: medicationId,
    });
    const res = await fetch('index.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString(),
    });
    const json = await res.json();
    if (!json.ok || !Array.isArray(json.changes) || json.changes.length === 0) return '';
    const fmtDose = (amount, unit) => {
      if (amount === null || amount === '' || amount === undefined) return '—';
      return `${parseFloat(amount)} ${unit || ''}`.trim();
    };
    const items = json.changes.map((c) => {
      const date = c.changed_at ? new Date(c.changed_at.replace(' ', 'T')).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' }) : '';
      const comment = c.comment ? `<br><span class="muted">${escHtml(c.comment)}</span>` : '';
      return `<li>${escHtml(date)}: ${escHtml(fmtDose(c.old_dose_amount, c.old_dose_unit))} &rarr; <strong>${escHtml(fmtDose(c.new_dose_amount, c.new_dose_unit))}</strong>${comment}</li>`;
    }).join('');
    return `<details class="med-detail-section" open><summary class="med-detail-section-title">Dose Change History</summary><div class="med-detail-section-body"><ul class="dose-change-history-list">${items}</ul></div></details>`;
  } catch {
    return '';
  }
};

document.querySelectorAll('[data-view-details]').forEach((btn) => {
  btn.addEventListener('click', async () => {
    const { medicationId = '', medicationName = '', setId = '' } = btn.dataset;
    if (medDetailTitle) medDetailTitle.textContent = medicationName;
    if (medDetailBody) medDetailBody.innerHTML = '<p class="pain-graph-loading">Loading&hellip;</p>';
    openMedDetailModal();

    const cacheKey = setId || `name_${medicationName}`;
    if (!medDetailCache[cacheKey]) {
      const [ofdaResult, imgResult] = await Promise.allSettled([
        fetch(apiProxy(`https://api.fda.gov/drug/label.json?search=openfda.brand_name:"${encodeURIComponent(medicationName)}"&limit=1`))
          .then((r) => (r.ok ? r.json() : null))
          .catch(() => null),
        fetchProductLabelUrl(setId, medicationName),
      ]);
      medDetailCache[cacheKey] = {
        ofda:           ofdaResult.status === 'fulfilled' ? ofdaResult.value : null,
        productLabelUrl:  imgResult.status  === 'fulfilled' ? imgResult.value  : null,
      };
    }

    if (medDetailBody) {
      try {
        const { ofda, productLabelUrl } = medDetailCache[cacheKey];
        medDetailBody.innerHTML = renderDetailContent(medicationName, setId, ofda, productLabelUrl);
        // Wire up lightbox on both images
        medDetailBody.querySelectorAll('[data-detail-img-url]').forEach((fig) => {
          fig.style.cursor = 'zoom-in';
          fig.addEventListener('click', () => openImageLightbox(fig.dataset.detailImgUrl, medicationName));
        });
      } catch {
        medDetailBody.innerHTML = `<p class="muted">Detailed information is not available for this medication.</p>`;
      }
    }
  });
});

// ── Group management UI ───────────────────────────────────────────────────────

const groupFormWrap = document.querySelector('[data-group-form-wrap]');
const groupForm = document.querySelector('[data-group-form]');
const groupFormAction = document.querySelector('[data-group-form-action]');
const groupFormId = document.querySelector('[data-group-form-id]');
const groupFormName = document.querySelector('[data-group-form-name]');
const groupFormTime = document.querySelector('[data-group-form-time]');
const groupFormSubmit = document.querySelector('[data-group-form-submit]');

const openGroupForm = (mode, id = '', name = '', time = '') => {
  if (!groupFormWrap) return;
  if (groupFormAction) groupFormAction.value = mode === 'edit' ? 'update_group' : 'create_group';
  if (groupFormId) groupFormId.value = id;
  if (groupFormName) groupFormName.value = name;
  if (groupFormTime) groupFormTime.value = time;
  if (groupFormSubmit) groupFormSubmit.textContent = mode === 'edit' ? 'Save changes' : 'Create group';
  groupFormWrap.classList.add('is-open');
  groupFormName?.focus();
};

const closeGroupForm = () => {
  if (groupFormWrap) groupFormWrap.classList.remove('is-open');
};

const exitGroupEditMode = (card) => {
  if (!card) return;
  card.classList.remove('is-editing');
  const nameView   = card.querySelector('[data-group-card-name]');
  const nameInput  = card.querySelector('[data-group-name-input]');
  if (nameView)  nameView.hidden  = false;
  if (nameInput) {
    nameInput.hidden = true;
    nameInput.value  = nameView?.textContent ?? nameInput.value;
  }
  const timeBadge = card.querySelector('[data-group-card-time]');
  const timeInput = card.querySelector('[data-group-time-input]');
  if (timeBadge) timeBadge.hidden = false;
  if (timeInput) {
    timeInput.hidden = true;
    timeInput.value  = timeBadge?.textContent ?? timeInput.value;
  }
  const addForm     = card.querySelector('.group-add-med-form');
  const editActions = card.querySelector('[data-group-edit-actions]');
  if (addForm)     addForm.hidden     = true;
  if (editActions) editActions.hidden = true;
};

document.querySelectorAll('[data-open-create-group-form], [data-open-create-group-form-header]').forEach((btn) => {
  btn.addEventListener('click', () => {
    setPlanTab('groups');
    openGroupForm('create');
  });
});

document.querySelector('[data-cancel-group-form]')?.addEventListener('click', closeGroupForm);

groupForm?.addEventListener('submit', async (e) => {
  const action = groupFormAction?.value;
  if (action !== 'create_group' && action !== 'update_group') return;
  e.preventDefault();

  const params = new URLSearchParams();
  params.set('action', action);
  params.set('csrf_token', getCsrfToken());
  params.set('json_response', '1');
  params.set('group_name', groupFormName?.value ?? '');
  params.set('group_time', groupFormTime?.value ?? '');
  if (action === 'update_group') params.set('group_id', groupFormId?.value ?? '');

  if (groupFormSubmit) groupFormSubmit.disabled = true;

  try {
    const res = await fetch('index.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString(),
    });
    const json = await res.json();
    if (!json.ok) throw new Error(json.error ?? 'Something went wrong.');

    closeGroupForm();

    const groupsList = document.querySelector('.groups-list');

    if (action === 'create_group') {
      groupsList?.querySelector('.groups-empty-state')?.remove();
      const card = buildGroupCard(json.group_id, json.group_name, json.group_time_display, json.ungrouped);
      const createRow = groupsList?.querySelector('.groups-create-row');
      createRow ? createRow.after(card) : groupsList?.appendChild(card);
      card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

      const ng = document.querySelectorAll('.group-card').length;
      document.querySelectorAll('[data-plan-tab="groups"]').forEach((t) => { t.textContent = `Groups (${ng})`; });
    } else {
      const card = groupsList?.querySelector(`[data-group-card-id="${json.group_id}"]`);
      if (card) {
        const nameEl = card.querySelector('[data-group-card-name]');
        const timeEl = card.querySelector('[data-group-card-time]');
        const editBtn = card.querySelector('[data-edit-group]');
        if (nameEl) nameEl.textContent = json.group_name;
        if (timeEl) timeEl.textContent = json.group_time_display;
        if (editBtn) {
          editBtn.dataset.groupName = json.group_name;
          editBtn.dataset.groupTime = json.group_time_display;
        }
      }
    }
  } catch (err) {
    alert(err.message ?? 'Something went wrong.');
  } finally {
    if (groupFormSubmit) groupFormSubmit.disabled = false;
  }
});

const closeAllGroupMenus = () => {
  document.querySelectorAll('[data-group-actions-menu]').forEach((menu) => {
    const dropdown = menu.querySelector('[data-group-actions-dropdown]');
    const trigger = menu.querySelector('[data-group-actions-trigger]');
    if (dropdown) {
      dropdown.hidden = true;
      dropdown.style.top = '';
      dropdown.style.bottom = '';
      dropdown.style.right = '';
      dropdown.style.left = '';
    }
    if (trigger) trigger.setAttribute('aria-expanded', 'false');
  });
};

document.querySelector('[data-plan-panel="groups"]')?.addEventListener('click', (e) => {
  const trigger = e.target.closest('[data-group-actions-trigger]');
  if (trigger) {
    e.stopPropagation();
    const menu = trigger.closest('[data-group-actions-menu]');
    const dropdown = menu?.querySelector('[data-group-actions-dropdown]');
    if (!dropdown) return;
    const isOpen = !dropdown.hidden;
    closeAllGroupMenus();
    closeAllMedMenus();
    if (!isOpen) {
      positionDropdownFixed(trigger, dropdown);
      dropdown.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
    }
    return;
  }

  const editBtn = e.target.closest('[data-edit-group]');
  if (editBtn) {
    const card = editBtn.closest('.group-card');
    closeAllGroupMenus();
    card?.classList.add('is-editing');
    const nameView  = card?.querySelector('[data-group-card-name]');
    const nameInput = card?.querySelector('[data-group-name-input]');
    if (nameView)  nameView.hidden  = true;
    if (nameInput) nameInput.hidden = false;
    const timeBadge = card?.querySelector('[data-group-card-time]');
    const timeInput = card?.querySelector('[data-group-time-input]');
    if (timeBadge) timeBadge.hidden = true;
    if (timeInput) timeInput.hidden = false;
    const addForm     = card?.querySelector('.group-add-med-form');
    const editActions = card?.querySelector('[data-group-edit-actions]');
    if (addForm)     addForm.hidden     = false;
    if (editActions) editActions.hidden = false;
    card?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    return;
  }

  const cancelEditBtn = e.target.closest('[data-group-cancel-edit]');
  if (cancelEditBtn) {
    exitGroupEditMode(cancelEditBtn.closest('.group-card'));
    return;
  }

  const saveEditBtn = e.target.closest('[data-group-save-edit]');
  if (saveEditBtn) {
    const card    = saveEditBtn.closest('.group-card');
    const groupId = saveEditBtn.dataset.groupId ?? '';
    const name    = (card?.querySelector('[data-group-name-input]')?.value ?? '').trim();
    const time    = (card?.querySelector('[data-group-time-input]')?.value ?? '').trim();
    if (!name) { card?.querySelector('[data-group-name-input]')?.focus(); return; }
    if (!time) { card?.querySelector('[data-group-time-input]')?.focus(); return; }
    saveEditBtn.disabled = true;
    const params = new URLSearchParams();
    params.set('action', 'update_group');
    params.set('csrf_token', getCsrfToken());
    params.set('json_response', '1');
    params.set('group_id', groupId);
    params.set('group_name', name);
    params.set('group_time', time);
    fetch('index.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
      .then((r) => r.json())
      .then((json) => {
        if (!json.ok) throw new Error(json.error ?? 'Save failed.');
        const nameView    = card?.querySelector('[data-group-card-name]');
        const nameInput   = card?.querySelector('[data-group-name-input]');
        const timeBadge   = card?.querySelector('[data-group-card-time]');
        const timeInput   = card?.querySelector('[data-group-time-input]');
        const editDataBtn = card?.querySelector('[data-edit-group]');
        if (nameView)    nameView.textContent          = json.group_name;
        if (nameInput)   nameInput.value               = json.group_name;
        if (timeBadge)   timeBadge.textContent         = json.group_time_display;
        if (timeInput)   timeInput.value               = json.group_time_display;
        if (editDataBtn) editDataBtn.dataset.groupName = json.group_name;
        exitGroupEditMode(card);
      })
      .catch((err) => {
        alert(err.message ?? 'Something went wrong.');
      })
      .finally(() => {
        saveEditBtn.disabled = false;
      });
    return;
  }
});

const escHtml = (str) =>
  String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

const buildMedOptions = (ungrouped) =>
  ungrouped.map((m) => {
    const hint = m.existing_groups ? ` (also in: ${escHtml(m.existing_groups)})` : '';
    return `<option value="${escHtml(String(m.id))}" data-name="${escHtml(m.name)}" data-dose="${escHtml(m.dose)}">${escHtml(m.name)} &mdash; ${escHtml(m.dose)}${hint}</option>`;
  }).join('');

const buildGroupCard = (groupId, groupName, groupTimeDisplay, ungrouped) => {
  const card = document.createElement('div');
  card.className = 'group-card';
  card.dataset.groupCardId = String(groupId);

  const addMedFormHtml = ungrouped.length > 0
    ? `<form class="group-add-med-form" method="post" action="index.php" data-ajax-add hidden>
        <input type="hidden" name="action" value="add_medication_to_group">
        <input type="hidden" name="group_id" value="${groupId}">
        <select name="medication_id" class="group-add-select">
          <option value="">Add a medication&hellip;</option>
          ${buildMedOptions(ungrouped)}
        </select>
        <label class="group-dose-override-label">Dose qty for this group
          <input type="number" name="quantity_per_dose" min="0.25" step="0.25" placeholder="e.g. 2" class="group-dose-override-input">
          <span class="field-optional">(optional)</span>
        </label>
        <button type="submit" class="secondary group-add-btn">Add</button>
      </form>`
    : '';

  card.innerHTML = `
    <button type="button" class="drag-handle drag-handle--group" aria-label="Drag to reorder" tabindex="-1"><i class="fa-solid fa-grip-vertical" aria-hidden="true"></i></button>
    <div class="group-card-body">
    <div class="group-card-header">
      <div class="group-card-title">
        <strong data-group-card-name>${escHtml(groupName)}</strong>
        <input type="text" class="group-name-input" data-group-name-input value="${escHtml(groupName)}" aria-label="Group name" hidden>
        <span class="group-time-badge" data-group-card-time>${escHtml(groupTimeDisplay)}</span>
        <input type="text" class="group-time-input" data-group-time-input value="${escHtml(groupTimeDisplay)}" aria-label="Scheduled time (e.g. 8:00 AM)" placeholder="e.g. 8:00 AM" hidden>
        <span class="count-badge" data-group-card-count>0 meds</span>
      </div>
      <div class="med-actions-menu" data-group-actions-menu>
        <button type="button" class="icon-button med-actions-trigger" data-group-actions-trigger aria-expanded="false" aria-haspopup="true">
          <i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
        </button>
        <div class="med-actions-dropdown" data-group-actions-dropdown hidden>
          <button type="button" class="med-actions-item" data-edit-group
            data-group-id="${groupId}"
            data-group-name="${escHtml(groupName)}"
            data-group-time="${escHtml(groupTimeDisplay)}">
            <i class="fa-solid fa-pen" aria-hidden="true"></i>
            Edit
          </button>
          <form method="post" action="index.php" data-confirm="Delete this group? Medications will become individual.">
            <input type="hidden" name="csrf_token" value="${escHtml(getCsrfToken())}">
            <input type="hidden" name="action" value="delete_group">
            <input type="hidden" name="group_id" value="${groupId}">
            <button type="submit" class="med-actions-item med-actions-item--danger">
              <i class="fa-solid fa-trash" aria-hidden="true"></i>
              Delete
            </button>
          </form>
        </div>
      </div>
    </div>
    <div class="group-members-list" data-group-members>
      <p class="group-empty-hint">No medications in this group yet.</p>
    </div>
    ${addMedFormHtml}
    <div class="group-card-edit-actions" data-group-edit-actions hidden>
      <button type="button" class="secondary" data-group-cancel-edit>Cancel</button>
      <button type="button" data-group-save-edit data-group-id="${groupId}">Save changes</button>
    </div>
    </div>`;

  card.querySelector('[data-confirm]')?.addEventListener('submit', (e) => {
    if (!window.confirm(e.currentTarget.getAttribute('data-confirm') ?? '')) e.preventDefault();
  });

  const timeInput = card.querySelector('[data-group-time-input]');
  if (timeInput) setupTimeAutoColon(timeInput, false);

  return card;
};

// ── Group panel delegated AJAX (add-med + delete) ─────────────────────────────

document.querySelector('[data-plan-panel="groups"]')?.addEventListener('submit', async (e) => {
  // ── Add medication to group ───────────────────────────────────────────────
  const addForm = e.target.closest('.group-add-med-form[data-ajax-add]');
  if (addForm) {
    e.preventDefault();
    const select = addForm.querySelector('.group-add-select');
    if (!select?.value) return;

    const selectedOption = select.options[select.selectedIndex];
    const medId = select.value;
    const medName = selectedOption.dataset.name ?? selectedOption.textContent.split('—')[0].trim();
    const medDose = selectedOption.dataset.dose ?? selectedOption.textContent.split('—')[1]?.trim() ?? '';
    const groupId = addForm.querySelector('input[name="group_id"]')?.value ?? '';
    const qpdInput = addForm.querySelector('input[name="quantity_per_dose"]');
    const qpdValue = qpdInput?.value ?? '';

    const submitBtn = addForm.querySelector('.group-add-btn');
    if (submitBtn) submitBtn.disabled = true;

    try {
      const params = new URLSearchParams();
      params.set('action', 'add_medication_to_group');
      params.set('csrf_token', getCsrfToken());
      params.set('json_response', '1');
      params.set('group_id', groupId);
      params.set('medication_id', medId);
      if (qpdValue) params.set('quantity_per_dose', qpdValue);

      const res = await fetch('index.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString(),
      });
      const json = await res.json();
      if (!json.ok) throw new Error('Failed to add medication.');

      const card = addForm.closest('.group-card');
      const membersList = card?.querySelector('[data-group-members]');
      if (membersList) {
        membersList.querySelector('.group-empty-hint')?.remove();
        const row = document.createElement('div');
        row.className = 'group-member-row';
        row.innerHTML = `
          <span class="group-member-name">${escHtml(medName)}</span>
          <span class="group-member-dose">${escHtml(medDose)}</span>
          <form method="post" action="index.php" data-ajax-remove>
            <input type="hidden" name="csrf_token" value="${escHtml(getCsrfToken())}">
            <input type="hidden" name="json_response" value="1">
            <input type="hidden" name="action" value="remove_medication_from_group">
            <input type="hidden" name="group_id" value="${escHtml(groupId)}">
            <input type="hidden" name="medication_id" value="${escHtml(medId)}">
            <button type="submit" class="secondary group-remove-btn">&times; Remove</button>
          </form>`;
        membersList.appendChild(row);
      }

      const countBadge = card?.querySelector('[data-group-card-count]');
      if (countBadge) {
        const n = card?.querySelectorAll('.group-member-row').length ?? 0;
        countBadge.textContent = `${n} med${n !== 1 ? 's' : ''}`;
      }

      if (qpdInput) qpdInput.value = '';
      if (json.ungrouped.length === 0) {
        addForm.remove();
      } else {
        select.innerHTML = `<option value="">Add a medication&hellip;</option>${buildMedOptions(json.ungrouped)}`;
        select.value = '';
      }
    } catch (err) {
      alert(err.message ?? 'Something went wrong.');
    } finally {
      if (submitBtn) submitBtn.disabled = false;
    }
    return;
  }

  // ── Remove medication from group ──────────────────────────────────────────────
  const removeForm = e.target.closest('form[data-ajax-remove]');
  if (removeForm) {
    e.preventDefault();
    const medId = removeForm.querySelector('input[name="medication_id"]')?.value ?? '';
    const groupIdForRemove = removeForm.querySelector('input[name="group_id"]')?.value ?? '';
    const card = removeForm.closest('.group-card');
    const memberRow = removeForm.closest('.group-member-row');
    const submitBtn = removeForm.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    try {
      const params = new URLSearchParams();
      params.set('action', 'remove_medication_from_group');
      params.set('csrf_token', getCsrfToken());
      params.set('json_response', '1');
      params.set('medication_id', medId);
      if (groupIdForRemove) params.set('group_id', groupIdForRemove);

      const res = await fetch('index.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString(),
      });
      const json = await res.json();
      if (!json.ok) throw new Error('Failed to remove medication.');

      memberRow?.remove();

      const membersList = card?.querySelector('[data-group-members]');
      if (membersList && (card?.querySelectorAll('.group-member-row').length ?? 0) === 0) {
        const hint = document.createElement('p');
        hint.className = 'group-empty-hint';
        hint.textContent = 'No medications in this group yet.';
        membersList.appendChild(hint);
      }

      const countBadge = card?.querySelector('[data-group-card-count]');
      if (countBadge) {
        const n = card?.querySelectorAll('.group-member-row').length ?? 0;
        countBadge.textContent = `${n} med${n !== 1 ? 's' : ''}`;
      }

      const existingAddForm = card?.querySelector('.group-add-med-form[data-ajax-add]');
      if (existingAddForm && json.ungrouped.length > 0) {
        const select = existingAddForm.querySelector('.group-add-select');
        if (select) select.innerHTML = `<option value="">Add a medication…</option>${buildMedOptions(json.ungrouped)}`;
        existingAddForm.hidden = !card?.classList.contains('is-editing');
      } else if (!existingAddForm && json.ungrouped.length > 0 && card) {
        const groupId = card.dataset.groupCardId ?? '';
        const newForm = document.createElement('form');
        newForm.className = 'group-add-med-form';
        newForm.setAttribute('method', 'post');
        newForm.setAttribute('action', 'index.php');
        newForm.dataset.ajaxAdd = '';
        newForm.hidden = !card.classList.contains('is-editing');
        newForm.innerHTML = `<input type="hidden" name="action" value="add_medication_to_group">
          <input type="hidden" name="group_id" value="${escHtml(groupId)}">
          <select name="medication_id" class="group-add-select">
            <option value="">Add a medication…</option>${buildMedOptions(json.ungrouped)}
          </select>
          <button type="submit" class="secondary group-add-btn">Add</button>`;
        const editActionsEl = card.querySelector('[data-group-edit-actions]');
        editActionsEl ? card.insertBefore(newForm, editActionsEl) : card.appendChild(newForm);
      }
    } catch (err) {
      alert(err.message ?? 'Something went wrong.');
      if (submitBtn) submitBtn.disabled = false;
    }
    return;
  }

  // ── Delete group ──────────────────────────────────────────────────────────
  const deleteForm = e.target.closest('form');
  if (deleteForm?.querySelector('input[name="action"]')?.value !== 'delete_group') return;
  if (e.defaultPrevented) return; // user cancelled confirm dialog

  e.preventDefault();
  const groupId = deleteForm.querySelector('input[name="group_id"]')?.value;
  if (!groupId) return;

  try {
    const params = new URLSearchParams();
    params.set('action', 'delete_group');
    params.set('csrf_token', getCsrfToken());
    params.set('json_response', '1');
    params.set('group_id', groupId);

    const res = await fetch('index.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString(),
    });
    const json = await res.json();
    if (!json.ok) throw new Error('Failed to delete group.');

    const card = deleteForm.closest('.group-card');
    card?.remove();

    const ng2 = document.querySelectorAll('.group-card').length;
    document.querySelectorAll('[data-plan-tab="groups"]').forEach((t) => { t.textContent = `Groups (${ng2})`; });

    if (ng2 === 0) {
      const groupsList = document.querySelector('.groups-list');
      const emptyState = document.createElement('div');
      emptyState.className = 'empty-state groups-empty-state';
      emptyState.innerHTML = '<p>No groups yet. Create a group to bundle medications taken at the same time.</p>';
      groupsList?.appendChild(emptyState);
    }
  } catch (err) {
    alert(err.message ?? 'Something went wrong.');
  }
});

// ── Refill modal ──────────────────────────────────────────────────────────────

const refillModal = document.querySelector('[data-refill-modal]');
const refillMedNameEl = document.querySelector('[data-refill-med-name]');
const refillMedDoseEl = document.querySelector('[data-refill-med-dose]');
const refillMedicationIdEl = document.querySelector('[data-refill-medication-id]');
const refillDateInput = document.querySelector('[data-refill-date]');
const refillForm = document.querySelector('[data-refill-form]');

const openRefillModal = (medicationId, medicationName, medicationDose = '') => {
  if (!refillModal) return;
  if (refillForm) refillForm.reset();
  if (refillMedNameEl) refillMedNameEl.textContent = medicationName;
  if (refillMedDoseEl) refillMedDoseEl.textContent = medicationDose;
  if (refillMedicationIdEl) refillMedicationIdEl.value = medicationId;
  if (refillDateInput) {
    const today = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    refillDateInput.value = `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}`;
  }
  closeMedPlanModal();
  refillModal.classList.add('is-open');
  lockBodyScroll();
};

const closeRefillModal = () => {
  if (!refillModal) return;
  if (!refillModal.classList.contains('is-open')) return;
  refillModal.classList.remove('is-open');
  unlockBodyScroll();
};

document.querySelectorAll('[data-open-refill-modal]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const { medicationId = '', medicationName = '', medicationDose = '' } = btn.dataset;
    if (!medicationId) return;
    openRefillModal(medicationId, medicationName, medicationDose);
  });
});

document.querySelectorAll('[data-close-refill-modal]').forEach((btn) => {
  btn.addEventListener('click', closeRefillModal);
});

refillModal?.addEventListener('click', (event) => {
  if (event.target === refillModal) closeRefillModal();
});

// ── Adjust quantity modal ─────────────────────────────────────────────────────

const adjustQtyModal = document.querySelector('[data-adjust-qty-modal]');
const adjustQtyMedNameEl = document.querySelector('[data-adjust-qty-med-name]');
const adjustQtyMedDoseEl = document.querySelector('[data-adjust-qty-med-dose]');
const adjustQtyMedicationIdEl = document.querySelector('[data-adjust-qty-medication-id]');
const adjustQtyCurrentEl = document.querySelector('[data-adjust-qty-current]');
const adjustQtyUnitEl = document.querySelector('[data-adjust-qty-unit]');
const adjustQtyInput = document.querySelector('[data-adjust-qty-input]');
const adjustQtyForm = document.querySelector('[data-adjust-qty-form]');

const formatQty = (value) => {
  const num = parseFloat(value);
  return Number.isNaN(num) ? '0' : String(num);
};

const openAdjustQtyModal = (medicationId, medicationName, medicationDose, currentQuantity, inventoryUnit) => {
  if (!adjustQtyModal) return;
  if (adjustQtyForm) adjustQtyForm.reset();
  if (adjustQtyMedNameEl) adjustQtyMedNameEl.textContent = medicationName;
  if (adjustQtyMedDoseEl) adjustQtyMedDoseEl.textContent = medicationDose;
  if (adjustQtyMedicationIdEl) adjustQtyMedicationIdEl.value = medicationId;
  if (adjustQtyCurrentEl) adjustQtyCurrentEl.textContent = `${formatQty(currentQuantity)} ${inventoryUnit}`;
  if (adjustQtyUnitEl) adjustQtyUnitEl.textContent = inventoryUnit;
  if (adjustQtyInput) adjustQtyInput.value = formatQty(currentQuantity);
  closeMedPlanModal();
  adjustQtyModal.classList.add('is-open');
  lockBodyScroll();
};

const closeAdjustQtyModal = () => {
  if (!adjustQtyModal) return;
  if (!adjustQtyModal.classList.contains('is-open')) return;
  adjustQtyModal.classList.remove('is-open');
  unlockBodyScroll();
};

document.querySelectorAll('[data-open-adjust-qty-modal]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const { medicationId = '', medicationName = '', medicationDose = '', currentQuantity = '0', inventoryUnit = 'tablets' } = btn.dataset;
    if (!medicationId) return;
    openAdjustQtyModal(medicationId, medicationName, medicationDose, currentQuantity, inventoryUnit);
  });
});

document.querySelectorAll('[data-close-adjust-qty-modal]').forEach((btn) => {
  btn.addEventListener('click', closeAdjustQtyModal);
});

adjustQtyModal?.addEventListener('click', (event) => {
  if (event.target === adjustQtyModal) closeAdjustQtyModal();
});

adjustQtyForm?.addEventListener('submit', async (event) => {
  event.preventDefault();
  const submitBtn = adjustQtyForm.querySelector('[type="submit"]');
  if (submitBtn) submitBtn.disabled = true;
  try {
    const params = new URLSearchParams({
      csrf_token: getCsrfToken(),
      json_response: '1',
      action: 'adjust_quantity',
      medication_id: adjustQtyMedicationIdEl?.value ?? '',
      new_count: adjustQtyInput?.value ?? '',
      note: adjustQtyForm.querySelector('[name="note"]')?.value ?? '',
    });
    const resp = await window.fetch('index.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString(),
    });
    const data = await resp.json();
    if (!data.ok) throw new Error(data.error ?? 'Failed to adjust quantity.');
    closeAdjustQtyModal();
    window.location.reload();
  } catch (err) {
    alert(err.message || 'Failed to adjust quantity. Please try again.');
    if (submitBtn) submitBtn.disabled = false;
  }
});

// ── Discontinue Use modal ─────────────────────────────────────────────────────

const discontinueModal = document.querySelector('[data-discontinue-modal]');
const discontinueMedNameEl = document.querySelector('[data-discontinue-med-name]');
const discontinueMedicationIdEl = document.querySelector('[data-discontinue-medication-id]');
const discontinueForm = document.querySelector('[data-discontinue-form]');

const openDiscontinueModal = (medicationId, medicationName) => {
  if (!discontinueModal) return;
  if (discontinueForm) discontinueForm.reset();
  if (discontinueMedNameEl) discontinueMedNameEl.textContent = medicationName;
  if (discontinueMedicationIdEl) discontinueMedicationIdEl.value = medicationId;
  closeMedPlanModal();
  discontinueModal.classList.add('is-open');
  lockBodyScroll();
};

const closeDiscontinueModal = () => {
  if (!discontinueModal) return;
  if (!discontinueModal.classList.contains('is-open')) return;
  discontinueModal.classList.remove('is-open');
  unlockBodyScroll();
};

document.addEventListener('click', (event) => {
  const trigger = event.target.closest('[data-open-discontinue-modal]');
  if (!trigger) return;
  const { medicationId = '', medicationName = '' } = trigger.dataset;
  if (!medicationId) return;
  openDiscontinueModal(medicationId, medicationName);
});

document.querySelectorAll('[data-close-discontinue-modal]').forEach((btn) => {
  btn.addEventListener('click', closeDiscontinueModal);
});

discontinueModal?.addEventListener('click', (event) => {
  if (event.target === discontinueModal) closeDiscontinueModal();
});

// ── Mood chart color scheme toggle (saved to account) ────────────────────────

document.querySelector('[data-mood-scheme-toggle]')?.addEventListener('change', async (event) => {
  const toggle = event.target;
  const scheme = toggle.checked ? 'teal' : 'classic';
  try {
    const params = new URLSearchParams({
      csrf_token: getCsrfToken(),
      json_response: '1',
      action: 'set_mood_chart_scheme',
      scheme,
    });
    const res = await fetch('index.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString(),
    });
    const json = await res.json();
    if (!json.ok) throw new Error('Failed to save chart color scheme.');
    document.body.dataset.moodChartScheme = scheme;
  } catch (err) {
    toggle.checked = !toggle.checked;
    alert(err.message ?? 'Something went wrong.');
  }
});


refillForm?.addEventListener('submit', async (event) => {
  event.preventDefault();
  const submitBtn = refillForm.querySelector('[type="submit"]');
  if (submitBtn) submitBtn.disabled = true;
  try {
    const params = new URLSearchParams({
      csrf_token: getCsrfToken(),
      json_response: '1',
      action: 'log_refill',
      medication_id: refillMedicationIdEl?.value ?? '',
      refill_date: refillDateInput?.value ?? '',
      amount: refillForm.querySelector('[name="amount"]')?.value ?? '',
      note: refillForm.querySelector('[name="note"]')?.value ?? '',
    });
    const resp = await window.fetch('index.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString(),
    });
    const data = await resp.json();
    if (!data.ok) throw new Error(data.error ?? 'Failed to log refill.');
    closeRefillModal();
    window.location.reload();
  } catch (err) {
    alert(err.message || 'Failed to log refill. Please try again.');
    if (submitBtn) submitBtn.disabled = false;
  }
});

// ── Notes & Dose History modal ────────────────────────────────────────────────

const instructionsModal = document.querySelector('[data-instructions-modal]');
const instructionsModalNameEl = document.querySelector('[data-instructions-modal-name]');
const instructionsModalDoseEl = document.querySelector('[data-instructions-modal-dose]');
const notesDoseHistoryEl = document.querySelector('[data-notes-dose-history]');
const notesListEl = document.querySelector('[data-notes-list]');
const addNoteFormEl = document.querySelector('[data-add-note-form]');
const addNoteTextareaEl = document.querySelector('[data-add-note-textarea]');

let notesModalMedicationId = '';

const fmtNoteDate = (ts) => {
  if (!ts) return '';
  const d = new Date(ts.replace ? ts.replace(' ', 'T') : ts);
  return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
    + ' at ' + d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
};

const buildNoteCard = (note) => {
  const card = document.createElement('div');
  card.className = 'note-card';
  card.dataset.noteId = String(note.id);

  const createdFmt = fmtNoteDate(note.created_at);
  const updatedFmt = fmtNoteDate(note.updated_at);
  const wasEdited = note.updated_at && note.updated_at !== note.created_at;

  card.innerHTML = `
    <div class="note-card-body">
      <p class="note-text">${escHtml(note.note)}</p>
      <div class="note-edit-form" hidden>
        <textarea class="note-edit-textarea" rows="4" maxlength="5000">${escHtml(note.note)}</textarea>
        <div class="note-edit-actions">
          <button type="button" class="note-save-edit-btn">Save</button>
          <button type="button" class="secondary note-cancel-edit-btn">Cancel</button>
        </div>
      </div>
      <p class="note-meta muted">Added ${escHtml(createdFmt)}${wasEdited ? ` · Edited ${escHtml(updatedFmt)}` : ''}</p>
    </div>
    <div class="note-actions-menu">
      <button type="button" class="icon-button note-actions-trigger" aria-label="Note options" aria-haspopup="true" aria-expanded="false">&#8942;</button>
      <div class="note-actions-dropdown" hidden>
        <button type="button" class="note-action-item note-edit-btn"><i class="fa-solid fa-pen" aria-hidden="true"></i> Edit</button>
        <button type="button" class="note-action-item note-action-item--danger note-delete-btn"><i class="fa-solid fa-trash" aria-hidden="true"></i> Delete note</button>
      </div>
    </div>`;

  const textEl = card.querySelector('.note-text');
  const editFormEl = card.querySelector('.note-edit-form');
  const editTextareaEl = card.querySelector('.note-edit-textarea');
  const metaEl = card.querySelector('.note-meta');
  const trigger = card.querySelector('.note-actions-trigger');
  const dropdown = card.querySelector('.note-actions-dropdown');

  trigger.addEventListener('click', (e) => {
    e.stopPropagation();
    const isOpen = !dropdown.hidden;
    document.querySelectorAll('.note-actions-dropdown').forEach((d) => { d.hidden = true; });
    dropdown.hidden = isOpen;
    trigger.setAttribute('aria-expanded', String(!isOpen));
  });

  card.querySelector('.note-edit-btn').addEventListener('click', () => {
    dropdown.hidden = true;
    textEl.style.display = 'none';
    editFormEl.hidden = false;
    editTextareaEl.focus();
  });

  card.querySelector('.note-cancel-edit-btn').addEventListener('click', () => {
    editTextareaEl.value = textEl.textContent;
    editFormEl.hidden = true;
    textEl.style.display = '';
  });

  card.querySelector('.note-save-edit-btn').addEventListener('click', async (evt) => {
    const saveBtn = evt.currentTarget;
    const newText = editTextareaEl.value.trim();
    if (!newText) { alert('Note cannot be empty.'); return; }
    saveBtn.disabled = true;
    try {
      const res = await window.fetch('index.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          csrf_token: getCsrfToken(),
          json_response: '1',
          action: 'update_note',
          note_id: String(note.id),
          medication_id: notesModalMedicationId,
          note: newText,
        }).toString(),
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error ?? 'Failed to save note.');
      textEl.textContent = newText;
      editTextareaEl.value = newText;
      note.updated_at = data.updated_at;
      const nowEdited = data.updated_at && data.updated_at !== note.created_at;
      metaEl.innerHTML = `Added ${escHtml(fmtNoteDate(note.created_at))}${nowEdited ? ` · Edited ${escHtml(fmtNoteDate(data.updated_at))}` : ''}`;
      editFormEl.hidden = true;
      textEl.style.display = '';
    } catch (err) {
      alert(err.message || 'Failed to save note.');
    } finally {
      saveBtn.disabled = false;
    }
  });

  card.querySelector('.note-delete-btn').addEventListener('click', async () => {
    dropdown.hidden = true;
    if (!confirm('Delete this note?')) return;
    try {
      const res = await window.fetch('index.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          csrf_token: getCsrfToken(),
          json_response: '1',
          action: 'delete_note',
          note_id: String(note.id),
          medication_id: notesModalMedicationId,
        }).toString(),
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error ?? 'Failed to delete note.');
      card.remove();
    } catch (err) {
      alert(err.message || 'Failed to delete note.');
    }
  });

  return card;
};

const openNotesModal = async (medicationId, medicationName, medicationDose) => {
  if (!instructionsModal) return;
  notesModalMedicationId = medicationId;
  if (instructionsModalNameEl) instructionsModalNameEl.textContent = medicationName;
  if (instructionsModalDoseEl) instructionsModalDoseEl.textContent = medicationDose || '';
  if (notesDoseHistoryEl) notesDoseHistoryEl.innerHTML = '';
  if (notesListEl) notesListEl.innerHTML = '<p class="pain-graph-loading">Loading…</p>';
  if (addNoteFormEl) addNoteFormEl.hidden = true;
  if (addNoteTextareaEl) addNoteTextareaEl.value = '';
  instructionsModal.classList.add('is-open');
  lockBodyScroll();

  const [notesResult, historyResult] = await Promise.allSettled([
    window.fetch('index.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ csrf_token: getCsrfToken(), json_response: '1', action: 'get_notes', medication_id: medicationId }).toString(),
    }).then((r) => r.json()),
    fetchDoseChangeHistoryHtml(medicationId),
  ]);

  if (notesDoseHistoryEl) {
    notesDoseHistoryEl.innerHTML = historyResult.status === 'fulfilled' ? (historyResult.value || '') : '';
  }

  if (notesListEl) {
    notesListEl.innerHTML = '';
    if (notesResult.status === 'fulfilled' && notesResult.value.ok && Array.isArray(notesResult.value.notes)) {
      const notes = notesResult.value.notes;
      const legacy = (notesResult.value.legacy_instructions || '').trim();
      if (legacy) {
        const legacyDiv = document.createElement('div');
        legacyDiv.className = 'note-legacy-banner';
        legacyDiv.innerHTML = `<p class="note-meta muted">Instructions (from medication record)</p><p class="note-text">${escHtml(legacy)}</p>`;
        notesListEl.appendChild(legacyDiv);
      }
      if (notes.length === 0 && !legacy) {
        const emptyP = document.createElement('p');
        emptyP.className = 'muted';
        emptyP.style.marginBottom = '.75rem';
        emptyP.textContent = 'No notes yet. Add one below.';
        notesListEl.appendChild(emptyP);
      } else {
        notes.forEach((n) => notesListEl.appendChild(buildNoteCard(n)));
      }
    } else {
      notesListEl.innerHTML = '<p class="muted">Could not load notes.</p>';
    }
  }
};

const closeInstructionsModal = () => {
  if (!instructionsModal) return;
  if (!instructionsModal.classList.contains('is-open')) return;
  instructionsModal.classList.remove('is-open');
  unlockBodyScroll();
  document.querySelectorAll('.note-actions-dropdown').forEach((d) => { d.hidden = true; });
};

document.querySelectorAll('[data-view-instructions]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const { medicationId = '', medicationName = '', medicationDose = '' } = btn.dataset;
    openNotesModal(medicationId, medicationName, medicationDose);
  });
});

document.querySelectorAll('[data-close-instructions-modal]').forEach((btn) => {
  btn.addEventListener('click', closeInstructionsModal);
});

instructionsModal?.addEventListener('click', (event) => {
  if (event.target === instructionsModal) closeInstructionsModal();
});

document.querySelector('[data-open-add-note]')?.addEventListener('click', () => {
  if (!addNoteFormEl) return;
  addNoteFormEl.hidden = false;
  addNoteTextareaEl?.focus();
});

document.querySelector('[data-cancel-add-note]')?.addEventListener('click', () => {
  if (addNoteFormEl) addNoteFormEl.hidden = true;
  if (addNoteTextareaEl) addNoteTextareaEl.value = '';
});

document.querySelector('[data-save-add-note]')?.addEventListener('click', async (event) => {
  const saveBtn = event.currentTarget;
  const text = addNoteTextareaEl?.value.trim() ?? '';
  if (!text) { alert('Note cannot be empty.'); return; }
  saveBtn.disabled = true;
  try {
    const res = await window.fetch('index.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        csrf_token: getCsrfToken(),
        json_response: '1',
        action: 'add_note',
        medication_id: notesModalMedicationId,
        note: text,
      }).toString(),
    });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error ?? 'Failed to save note.');
    if (notesListEl) {
      const emptyMsg = notesListEl.querySelector('.muted');
      if (emptyMsg) emptyMsg.remove();
      notesListEl.appendChild(buildNoteCard(data.note));
    }
    if (addNoteFormEl) addNoteFormEl.hidden = true;
    if (addNoteTextareaEl) addNoteTextareaEl.value = '';
  } catch (err) {
    alert(err.message || 'Failed to save note.');
  } finally {
    saveBtn.disabled = false;
  }
});

document.addEventListener('click', () => {
  document.querySelectorAll('.note-actions-dropdown').forEach((d) => { d.hidden = true; });
});

// ── Update Prescribed Dose modal ──────────────────────────────────────────────

const updateDoseModal = document.querySelector('[data-update-dose-modal]');
const updateDoseMedNameEl = document.querySelector('[data-update-dose-med-name]');
const updateDoseCurrentEl = document.querySelector('[data-update-dose-current]');
const updateDoseAmountEl = document.querySelector('[data-update-dose-amount]');
const updateDoseUnitEl = document.querySelector('[data-update-dose-unit]');
const updateDoseReasonEl = document.querySelector('[data-update-dose-reason]');

let updateDoseMedicationId = '';

const openUpdateDoseModal = (medicationId, medicationName, doseAmount, doseUnit, formattedDose) => {
  if (!updateDoseModal) return;
  updateDoseMedicationId = medicationId;
  if (updateDoseMedNameEl) updateDoseMedNameEl.textContent = medicationName;
  if (updateDoseCurrentEl) updateDoseCurrentEl.textContent = formattedDose || '—';
  if (updateDoseAmountEl) updateDoseAmountEl.value = doseAmount || '';
  if (updateDoseUnitEl) updateDoseUnitEl.value = doseUnit || 'mg';
  if (updateDoseReasonEl) updateDoseReasonEl.value = '';
  updateDoseModal.classList.add('is-open');
  lockBodyScroll();
  updateDoseAmountEl?.focus();
};

const closeUpdateDoseModal = () => {
  if (!updateDoseModal) return;
  updateDoseModal.classList.remove('is-open');
  unlockBodyScroll();
};

document.querySelectorAll('[data-open-update-dose-modal]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const { medicationId = '', medicationName = '', doseAmount = '', doseUnit = 'mg', medicationDose = '' } = btn.dataset;
    openUpdateDoseModal(medicationId, medicationName, doseAmount, doseUnit, medicationDose);
  });
});

document.querySelectorAll('[data-close-update-dose-modal]').forEach((btn) => {
  btn.addEventListener('click', closeUpdateDoseModal);
});

updateDoseModal?.addEventListener('click', (event) => {
  if (event.target === updateDoseModal) closeUpdateDoseModal();
});

document.querySelector('[data-save-update-dose]')?.addEventListener('click', async (event) => {
  const saveBtn = event.currentTarget;
  const newAmount = updateDoseAmountEl?.value ?? '';
  const newUnit = updateDoseUnitEl?.value ?? 'mg';
  const reason = updateDoseReasonEl?.value.trim() ?? '';
  if (!newAmount) { alert('Please enter a dose amount.'); return; }
  saveBtn.disabled = true;
  try {
    const res = await window.fetch('index.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        csrf_token: getCsrfToken(),
        json_response: '1',
        action: 'update_dose',
        medication_id: updateDoseMedicationId,
        dose_amount: newAmount,
        dose_unit: newUnit,
        comment: reason,
      }).toString(),
    });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error ?? 'Failed to update dose.');
    closeUpdateDoseModal();
    window.location.reload();
  } catch (err) {
    alert(err.message || 'Failed to update dose. Please try again.');
  } finally {
    saveBtn.disabled = false;
  }
});

// ── Refill history modal ──────────────────────────────────────────────────────

const refillHistoryModal = document.querySelector('[data-refill-history-modal]');
const refillHistoryMedNameEl = document.querySelector('[data-refill-history-med-name]');
const refillHistoryBody = document.querySelector('[data-refill-history-body]');

let refillHistoryMedId = 0;
let refillHistoryYear = new Date().getFullYear();
let refillHistoryMonth = new Date().getMonth() + 1;

const MONTH_NAMES = [
  'January', 'February', 'March', 'April', 'May', 'June',
  'July', 'August', 'September', 'October', 'November', 'December',
];

const openRefillHistoryModal = (medicationId, medicationName) => {
  if (!refillHistoryModal) return;
  refillHistoryMedId = parseInt(medicationId, 10);
  refillHistoryYear = new Date().getFullYear();
  refillHistoryMonth = new Date().getMonth() + 1;
  if (refillHistoryMedNameEl) refillHistoryMedNameEl.textContent = medicationName;
  closeMedPlanModal();
  refillHistoryModal.classList.add('is-open');
  lockBodyScroll();
  loadRefillHistory();
};

const closeRefillHistoryModal = () => {
  if (!refillHistoryModal) return;
  if (!refillHistoryModal.classList.contains('is-open')) return;
  refillHistoryModal.classList.remove('is-open');
  unlockBodyScroll();
};

document.querySelectorAll('[data-open-refill-history]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const { medicationId = '', medicationName = '' } = btn.dataset;
    if (!medicationId) return;
    openRefillHistoryModal(medicationId, medicationName);
  });
});

document.querySelectorAll('[data-close-refill-history]').forEach((btn) => {
  btn.addEventListener('click', closeRefillHistoryModal);
});

refillHistoryModal?.addEventListener('click', (event) => {
  if (event.target === refillHistoryModal) closeRefillHistoryModal();
});

const loadRefillHistory = async () => {
  if (!refillHistoryBody) return;
  refillHistoryBody.innerHTML = '<p class="pain-graph-loading">Loading&hellip;</p>';
  try {
    const url = `index.php?action=refill_history&medication_id=${refillHistoryMedId}&year=${refillHistoryYear}&month=${refillHistoryMonth}`;
    const resp = await window.fetch(url, { credentials: 'same-origin' });
    const data = await resp.json();
    if (!data.ok) throw new Error(data.error ?? 'Failed to load refill history.');

    const { refills, stats, year, month } = data;
    const monthName = MONTH_NAMES[month - 1];

    let prevYear = year, prevMonth = month - 1;
    if (prevMonth < 1) { prevMonth = 12; prevYear--; }
    let nextYear = year, nextMonth = month + 1;
    if (nextMonth > 12) { nextMonth = 1; nextYear++; }

    const statText = stats.count === 0
      ? `No refills recorded in ${year}`
      : stats.avg_days !== null
        ? `${stats.count} refill${stats.count !== 1 ? 's' : ''} in ${year} · avg every ${stats.avg_days} days`
        : `${stats.count} refill${stats.count !== 1 ? 's' : ''} in ${year}`;

    let listHtml = '';
    if (refills.length === 0) {
      listHtml = '<p class="empty-state-text">No refills recorded this month.</p>';
    } else {
      listHtml = '<ol class="refill-history-list">';
      for (const r of refills) {
        const dateObj = new Date(r.refill_date + 'T00:00:00');
        const dateStr = dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        const daysBetweenHtml = r.days_since_prev !== null
          ? `<span class="refill-days-between">${r.days_since_prev} days since prev</span>`
          : '';
        const noteHtml = r.note
          ? `<span class="refill-note">${escHtml(r.note)}</span>`
          : '';
        const isAdjustment = r.entry_type === 'adjustment';
        const amountNum = parseFloat(r.amount);
        const amountStr = `${amountNum >= 0 ? '+' : '−'}${formatQty(Math.abs(amountNum))}`;
        const badgeHtml = isAdjustment
          ? '<span class="refill-entry-badge">Adjustment</span>'
          : '';
        listHtml += `<li class="refill-history-entry${isAdjustment ? ' refill-history-entry--adjustment' : ''}">
          <span class="refill-entry-date">${escHtml(dateStr)}</span>
          <div class="refill-entry-meta">
            ${badgeHtml}
            <span class="refill-entry-amount">${escHtml(amountStr)} pills</span>
            <span class="refill-entry-hand">${escHtml(formatQty(r.pills_on_hand))} on hand after</span>
            ${daysBetweenHtml}
            ${noteHtml}
          </div>
        </li>`;
      }
      listHtml += '</ol>';
    }

    refillHistoryBody.innerHTML = `
      <div class="refill-stats-banner">${escHtml(statText)}</div>
      <div class="refill-month-nav">
        <button type="button" class="secondary refill-nav-btn" data-refill-prev-month data-year="${prevYear}" data-month="${prevMonth}">&lsaquo; ${escHtml(MONTH_NAMES[prevMonth - 1])}</button>
        <strong class="refill-month-label">${escHtml(monthName)} ${escHtml(String(year))}</strong>
        <button type="button" class="secondary refill-nav-btn" data-refill-next-month data-year="${nextYear}" data-month="${nextMonth}">${escHtml(MONTH_NAMES[nextMonth - 1])} &rsaquo;</button>
      </div>
      ${listHtml}
    `;

    refillHistoryBody.querySelector('[data-refill-prev-month]')?.addEventListener('click', (e) => {
      refillHistoryYear = parseInt(e.currentTarget.dataset.year, 10);
      refillHistoryMonth = parseInt(e.currentTarget.dataset.month, 10);
      loadRefillHistory();
    });
    refillHistoryBody.querySelector('[data-refill-next-month]')?.addEventListener('click', (e) => {
      refillHistoryYear = parseInt(e.currentTarget.dataset.year, 10);
      refillHistoryMonth = parseInt(e.currentTarget.dataset.month, 10);
      loadRefillHistory();
    });
  } catch (err) {
    if (refillHistoryBody) {
      refillHistoryBody.innerHTML = `<p class="alert">${escHtml(err.message || 'Failed to load refill history.')}</p>`;
    }
  }
};

// ── Dose history: preserve scroll position on filter/pagination ───────────────

document.querySelector('[data-history-filter]')?.addEventListener('submit', (e) => {
  e.preventDefault();
  const params = new URLSearchParams(new FormData(e.currentTarget));
  window.location.href = `index.php?${params.toString()}#dose-history`;
});

// ── Hero next dose: group meds toggle ─────────────────────────────────────────

document.querySelectorAll('[data-group-meds-toggle]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const list = btn.nextElementSibling;
    if (!list) return;
    const isHidden = list.hidden;
    list.hidden = !isHidden;
    btn.textContent = isHidden ? 'hide group meds' : 'view group meds';
  });
});

// ── PWA install prompt ─────────────────────────────────────────────────────────

let deferredInstallPrompt = null;
const installBanner = document.getElementById('pwa-install-banner');

window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault();
  deferredInstallPrompt = e;
  if (installBanner && !sessionStorage.getItem('rxtracker_install_dismissed')) {
    installBanner.hidden = false;
  }
});

document.getElementById('pwa-install-btn')?.addEventListener('click', async () => {
  if (!deferredInstallPrompt) return;
  deferredInstallPrompt.prompt();
  const { outcome } = await deferredInstallPrompt.userChoice;
  deferredInstallPrompt = null;
  if (installBanner) installBanner.hidden = true;
});

document.getElementById('pwa-install-dismiss')?.addEventListener('click', () => {
  if (installBanner) installBanner.hidden = true;
  sessionStorage.setItem('rxtracker_install_dismissed', '1');
});

window.addEventListener('appinstalled', () => {
  if (installBanner) installBanner.hidden = true;
  deferredInstallPrompt = null;
});

// ── Adherence ring animation ──────────────────────────────────────────────────

const adherenceRingFill = document.querySelector('.adherence-ring-fill');
const adherenceNum = document.querySelector('[data-adherence-num]');
if (adherenceRingFill && adherenceNum) {
  const pct = Math.min(100, Math.max(0, parseInt(adherenceRingFill.dataset.adherencePct, 10) || 0));
  const circumference = 263.89;
  const duration = 1100;
  let startTime = null;

  const tick = (timestamp) => {
    if (!startTime) startTime = timestamp;
    const progress = Math.min((timestamp - startTime) / duration, 1);
    const eased = 1 - Math.pow(1 - progress, 3);
    adherenceRingFill.style.strokeDashoffset = circumference * (1 - (eased * pct) / 100);
    adherenceNum.textContent = Math.round(eased * pct) + '%';
    if (progress < 1) requestAnimationFrame(tick);
  };

  requestAnimationFrame(tick);
}

// ── Push notification status panel (Settings page) ────────────────────────────

const pushStatusPanel = document.querySelector('[data-push-status-panel]');

const applyCheckResult = (rowEl, ok, hintText) => {
  if (!rowEl) return;
  const icon = rowEl.querySelector('.push-check-icon');
  const hint = rowEl.querySelector('[data-check-hint]');
  if (icon) {
    icon.textContent = ok ? '✓' : '✗';
    icon.className = `push-check-icon ${ok ? 'push-check-ok' : 'push-check-fail'}`;
  }
  if (hint) {
    hint.textContent = hintText || '';
    hint.hidden = !hintText;
  }
};

const initPushStatusPanel = async () => {
  if (!pushStatusPanel) return;

  const swRow = pushStatusPanel.querySelector('[data-check-sw]');
  const permRow = pushStatusPanel.querySelector('[data-check-permission]');
  const subRow = pushStatusPanel.querySelector('[data-check-subscription]');
  const testBtn = pushStatusPanel.querySelector('[data-test-push-btn]');
  const testStatus = pushStatusPanel.querySelector('[data-test-push-status]');

  // 1. Service worker
  const hasSW = 'serviceWorker' in navigator;
  let swOk = false;
  if (hasSW) {
    try {
      const reg = await navigator.serviceWorker.getRegistration();
      swOk = Boolean(reg);
    } catch { /* ignore */ }
  }
  applyCheckResult(swRow, swOk,
    !hasSW ? 'Service workers are not supported in this browser.' :
    !swOk ? 'Service worker not yet registered. Open the dashboard once over HTTPS to register it automatically.' : '');

  // 2. Notification permission
  const hasPerm = 'Notification' in window;
  const permission = hasPerm ? Notification.permission : 'unavailable';
  const permOk = permission === 'granted';
  applyCheckResult(permRow, permOk,
    !hasPerm ? 'Notifications are not supported in this browser.' :
    permission === 'denied' ? 'Permission was blocked. Open browser site settings and reset notifications for this site, then re-enable the toggle above.' :
    !permOk ? 'Enable the "Background reminders" toggle above to grant permission.' : '');

  // 3. Push subscription
  let subOk = false;
  if (swOk) {
    try {
      const reg = await navigator.serviceWorker.getRegistration();
      const sub = reg ? await reg.pushManager.getSubscription() : null;
      subOk = Boolean(sub);
    } catch { /* ignore */ }
  }
  applyCheckResult(subRow, subOk,
    !subOk ? 'No active subscription on this device. Enable the "Background reminders" toggle above.' : '');

  // Enable test button only when there is an active subscription
  if (testBtn) testBtn.disabled = !subOk;

  testBtn?.addEventListener('click', async () => {
    if (!testStatus) return;
    testBtn.disabled = true;
    testStatus.textContent = 'Sending…';
    try {
      const params = new URLSearchParams({
        action: 'send_test_push',
        csrf_token: getCsrfToken(),
      });
      const res = await fetch('index.php?page=settings', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: params.toString(),
      });
      const json = await res.json();
      testStatus.textContent = json.ok
        ? `Test sent to ${json.count} device(s) — check your notifications now.`
        : `Failed: ${json.error || 'Unknown error.'}`;
    } catch {
      testStatus.textContent = 'Request failed — check the browser console.';
    } finally {
      testBtn.disabled = !subOk;
    }
  });
};

// ── Time input: auto-insert colon as user types ───────────────────────────────

const autoInsertTimeColon = (token) => {
  if (token.includes(':')) return token;
  const match = token.match(/^(\d+)([\s\S]*)$/);
  if (!match) return token;
  const digits = match[1];
  const tail   = match[2];

  // Bare hour + AM/PM with no minutes (e.g. "8am", "12pm") → expand to "8:00 AM"
  const ampmOnly = tail.match(/^\s*([ap]m)$/i);
  if (ampmOnly) {
    const ampm = /pm/i.test(ampmOnly[1]) ? 'PM' : 'AM';
    const firstTwo = parseInt(digits.slice(0, 2), 10);
    const isTwoDigitHour = digits.length >= 2 && firstTwo >= 10 && firstTwo <= 12;
    const hour = isTwoDigitHour ? digits.slice(0, 2) : digits.slice(0, 1);
    return hour + ':00 ' + ampm;
  }

  if (digits.length < 2) return token;
  const firstTwo = parseInt(digits.slice(0, 2), 10);
  const isTwoDigitHour = firstTwo >= 10 && firstTwo <= 12;
  if (isTwoDigitHour) {
    if (digits.length < 3) return token; // e.g. "10" alone — wait for minute digit
    return digits.slice(0, 2) + ':' + digits.slice(2) + tail;
  }
  return digits.slice(0, 1) + ':' + digits.slice(1) + tail;
};

const setupTimeAutoColon = (input, isMulti = false) => {
  input.addEventListener('input', (e) => {
    if (e.inputType?.startsWith('delete')) return;
    if (isMulti) {
      const val = input.value;
      const lastCommaIdx = val.lastIndexOf(',');
      if (lastCommaIdx === -1) {
        const formatted = autoInsertTimeColon(val);
        if (formatted !== val) input.value = formatted;
      } else {
        const prefix      = val.slice(0, lastCommaIdx + 1);
        const afterComma  = val.slice(lastCommaIdx + 1);
        const leadSpace   = afterComma.match(/^(\s*)/)[1];
        const lastToken   = afterComma.trimStart();
        const formatted   = autoInsertTimeColon(lastToken);
        if (formatted !== lastToken) input.value = prefix + leadSpace + formatted;
      }
    } else {
      const formatted = autoInsertTimeColon(input.value);
      if (formatted !== input.value) input.value = formatted;
    }
  });
};

document.querySelectorAll('[name="first_dose_time"], [data-group-form-time], [data-group-time-input]').forEach((el) => {
  setupTimeAutoColon(el, false);
});
document.querySelectorAll('[data-dose-time-rows] .dose-time-field').forEach((el) => {
  setupTimeAutoColon(el, false);
});

// Medication type filter (handles multiple panels independently)
document.querySelectorAll('[data-med-type-filter]').forEach((filterWrap) => {
  const panel    = filterWrap.closest('.plan-tab-panel');
  if (!panel) return;

  const trigger  = filterWrap.querySelector('[data-med-filter-trigger]');
  const dropdown = filterWrap.querySelector('[data-med-filter-dropdown]');
  const options  = filterWrap.querySelectorAll('[data-filter-value]');
  const applyBtn = filterWrap.querySelector('[data-med-type-apply]');

  const closeDropdown = () => {
    dropdown.hidden = true;
    trigger?.setAttribute('aria-expanded', 'false');
  };

  trigger?.addEventListener('click', (e) => {
    e.stopPropagation();
    const opening = dropdown.hidden;
    dropdown.hidden = !opening;
    trigger.setAttribute('aria-expanded', String(opening));
  });

  options.forEach((opt) => {
    opt.addEventListener('click', () => opt.classList.toggle('is-selected'));
  });

  const listContainer = filterWrap.nextElementSibling;

  applyBtn?.addEventListener('click', () => {
    const selected = [...options]
      .filter((o) => o.classList.contains('is-selected'))
      .map((o) => o.dataset.filterValue ?? '');

    const rows = panel.querySelectorAll('[data-med-type]');
    let visibleCount = 0;
    rows.forEach((row) => {
      const hide = selected.length > 0 && !selected.includes(row.dataset.medType ?? '');
      row.style.display = hide ? 'none' : '';
      if (!hide) visibleCount++;
    });

    let emptyMsg = listContainer?.querySelector('.med-filter-empty-msg');
    if (visibleCount === 0 && rows.length > 0) {
      if (!emptyMsg && listContainer) {
        emptyMsg = document.createElement('p');
        emptyMsg.className = 'med-filter-empty-msg';
        listContainer.appendChild(emptyMsg);
      }
      if (emptyMsg) {
        emptyMsg.textContent = 'No medications match the selected filter.';
        emptyMsg.hidden = false;
      }
      if (listContainer) listContainer.style.minHeight = '200px';
    } else {
      if (emptyMsg) emptyMsg.hidden = true;
      if (listContainer) listContainer.style.minHeight = '';
    }

    closeDropdown();
  });

  document.addEventListener('click', (e) => {
    if (!filterWrap.contains(e.target)) closeDropdown();
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeDropdown();
  });
});

// Medication card action menu (ellipsis trigger)

// Position a dropdown using fixed coords so it escapes overflow:hidden clipping.
// Opens downward by default; flips upward when there isn't enough space below.
function positionDropdownFixed(trigger, dropdown) {
  const rect = trigger.getBoundingClientRect();
  const estimatedHeight = dropdown.querySelectorAll('.med-actions-item').length * 44 + 8;
  const spaceBelow = window.innerHeight - rect.bottom;

  // Clamp right so the dropdown never overflows the left viewport edge
  const dropdownWidth = Math.max(dropdown.offsetWidth, 160);
  const rawRight = window.innerWidth - rect.right;
  const maxRight = window.innerWidth - dropdownWidth - 8;
  dropdown.style.right = Math.min(rawRight, maxRight) + 'px';
  dropdown.style.left = 'auto';

  if (spaceBelow < estimatedHeight && rect.top > spaceBelow) {
    dropdown.style.top = 'auto';
    dropdown.style.bottom = (window.innerHeight - rect.top + 4) + 'px';
  } else {
    dropdown.style.top = (rect.bottom + 4) + 'px';
    dropdown.style.bottom = 'auto';
  }
}

const closeAllMedMenus = () => {
  document.querySelectorAll('[data-med-actions-menu]').forEach((menu) => {
    const dropdown = menu.querySelector('[data-med-actions-dropdown]');
    const trigger = menu.querySelector('[data-med-actions-trigger]');
    if (dropdown) {
      dropdown.hidden = true;
      dropdown.style.top = '';
      dropdown.style.bottom = '';
      dropdown.style.right = '';
      dropdown.style.left = '';
    }
    if (trigger) trigger.setAttribute('aria-expanded', 'false');
  });
};

document.querySelectorAll('[data-med-actions-trigger]').forEach((trigger) => {
  trigger.addEventListener('click', (event) => {
    event.stopPropagation();
    const menu = trigger.closest('[data-med-actions-menu]');
    const dropdown = menu?.querySelector('[data-med-actions-dropdown]');
    if (!dropdown) return;
    const isOpen = !dropdown.hidden;
    closeAllMedMenus();
    closeAllGroupMenus();
    if (!isOpen) {
      positionDropdownFixed(trigger, dropdown);
      dropdown.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
    }
  });
});

document.addEventListener('click', () => { closeAllMedMenus(); closeAllGroupMenus(); });
document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') { closeAllMedMenus(); closeAllGroupMenus(); }
});
document.addEventListener('scroll', () => { closeAllMedMenus(); closeAllGroupMenus(); }, true);

initPushStatusPanel();

// ── Password visibility toggle ─────────────────────────────────────────────

document.querySelectorAll('.password-toggle').forEach((btn) => {
  btn.addEventListener('click', () => {
    const wrapper = btn.closest('.password-input-wrapper');
    const input = wrapper?.querySelector('input');
    const isHidden = input.type === 'password';
    input.type = isHidden ? 'text' : 'password';
    btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
    btn.querySelector('.pw-eye').style.display = isHidden ? 'none' : '';
    btn.querySelector('.pw-eye-off').style.display = isHidden ? '' : 'none';
  });
});

// ── Drag-and-drop reordering (Medication Plan page only) ──────────────────────
if (typeof Sortable !== 'undefined') {
  const postReorder = (action, ids) => {
    const params = new URLSearchParams();
    params.set('action', action);
    params.set('csrf_token', getCsrfToken());
    params.set('ids', JSON.stringify(ids));
    fetch('index.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
      .catch(() => {});
  };

  const medList = document.querySelector('[data-plan-panel="active"] .medication-list');
  if (medList) {
    Sortable.create(medList, {
      handle: '.drag-handle',
      animation: 150,
      ghostClass: 'sortable-ghost',
      filter: '.empty-state',
      onEnd() {
        const ids = [...medList.querySelectorAll('[data-med-id]')].map((el) => el.dataset.medId);
        postReorder('reorder_medications', ids);
      },
    });
  }

  const groupsList = document.querySelector('[data-groups-sortable]');
  if (groupsList) {
    Sortable.create(groupsList, {
      handle: '.drag-handle--group',
      animation: 150,
      ghostClass: 'sortable-ghost',
      onEnd() {
        const ids = [...groupsList.querySelectorAll('[data-group-card-id]')].map((el) => el.dataset.groupCardId);
        postReorder('reorder_groups', ids);
      },
    });
  }
}

// ── Notification panel ────────────────────────────────────────────────────────

const notifPanel     = document.querySelector('[data-notif-panel]');
const bellBtn        = document.querySelector('[data-bell-btn]');
const bellBadge      = document.querySelector('.nav-bell-badge');

const updateBellBadge = (count) => {
  if (!bellBadge) return;
  bellBadge.textContent = String(count);
  bellBadge.setAttribute(
    'aria-label',
    `${count} notification${count !== 1 ? 's' : ''}`
  );
  bellBadge.hidden = count === 0;
};

const closeNotifPanel = () => {
  if (!notifPanel) return;
  notifPanel.hidden = true;
  bellBtn?.setAttribute('aria-expanded', 'false');
};

const markAllNotificationsReadAsync = async () => {
  document.querySelectorAll('.notif-item--unread').forEach((el) => {
    el.classList.remove('notif-item--unread');
  });
  updateBellBadge(0);
  try {
    const params = new URLSearchParams({
      csrf_token: getCsrfToken(),
      json_response: '1',
      action: 'mark_all_notifications_read',
    });
    await window.fetch('index.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString(),
    });
  } catch {
    // Non-critical; badge corrects itself on next page load
  }
};

if (bellBtn && notifPanel) {
  bellBtn.addEventListener('click', (event) => {
    event.stopPropagation();
    if (notifPanel.hidden) {
      notifPanel.hidden = false;
      bellBtn.setAttribute('aria-expanded', 'true');
      markAllNotificationsReadAsync();
    } else {
      closeNotifPanel();
    }
  });
}

document.addEventListener('click', (event) => {
  if (!notifPanel || notifPanel.hidden) return;
  if (!notifPanel.contains(event.target) && event.target !== bellBtn && !bellBtn?.contains(event.target)) {
    closeNotifPanel();
  }
});

document.querySelector('[data-notif-mark-all]')?.addEventListener('click', (event) => {
  event.stopPropagation();
  markAllNotificationsReadAsync();
});

document.querySelector('[data-notif-panel-body]')?.addEventListener('click', async (event) => {
  const dismissBtn = event.target.closest('[data-notif-dismiss]');
  if (!dismissBtn) return;

  const notifId  = dismissBtn.dataset.notifDismiss;
  const notifItem = dismissBtn.closest('[data-notif-item]');

  notifItem?.remove();

  const remaining = document.querySelectorAll('[data-notif-item]').length;
  if (remaining === 0) {
    const body = document.querySelector('[data-notif-panel-body]');
    if (body) body.innerHTML = '<p class="notif-empty">You\'re all set! No notifications.</p>';
    document.querySelector('[data-notif-mark-all]')?.remove();
  }

  const unreadRemaining = document.querySelectorAll('.notif-item--unread').length;
  updateBellBadge(unreadRemaining);

  try {
    const params = new URLSearchParams({
      csrf_token: getCsrfToken(),
      json_response: '1',
      action: 'dismiss_notification',
      notification_id: notifId,
    });
    await window.fetch('index.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString(),
    });
  } catch {
    // Non-critical
  }
});

// Close notification panel when Refill button is clicked (refill modal takes over)
document.querySelector('[data-notif-panel-body]')?.addEventListener('click', (event) => {
  if (event.target.closest('[data-open-refill-modal]')) {
    closeNotifPanel();
  }
});

// ── Side-effect modal ─────────────────────────────────────────────────────────

(function () {
  const modal    = document.querySelector('[data-se-modal]');
  const medIdEl  = document.querySelector('#se-medication-id');
  const titleEl  = document.querySelector('#se-modal-title');
  if (!modal) return;

  const openSeModal = ({ medicationId, medicationName }) => {
    if (medIdEl) medIdEl.value = medicationId;
    if (titleEl) titleEl.textContent = `Log Side Effect — ${medicationName}`;
    modal.classList.add('is-open');
    lockBodyScroll();
  };
  const closeSeModal = () => {
    modal.classList.remove('is-open');
    unlockBodyScroll();
  };

  document.querySelectorAll('[data-log-se]').forEach((btn) => {
    btn.addEventListener('click', () => openSeModal({
      medicationId:   btn.dataset.medicationId,
      medicationName: btn.dataset.medicationName,
    }));
  });

  document.querySelectorAll('[data-close-se-modal]').forEach((btn) => {
    btn.addEventListener('click', closeSeModal);
  });

  modal.addEventListener('click', (e) => {
    if (e.target === modal) closeSeModal();
  });
})();

// Unified user menu dropdown
(function () {
  const menu   = document.querySelector('[data-user-menu]');
  if (!menu) return;
  const btn    = menu.querySelector('[data-user-menu-btn]');
  const panel  = menu.querySelector('[data-user-menu-panel]');
  if (!btn || !panel) return;

  btn.addEventListener('click', () => {
    const expanded = btn.getAttribute('aria-expanded') === 'true';
    btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
    panel.hidden = expanded;
  });

  document.addEventListener('click', (e) => {
    if (!menu.contains(e.target)) {
      btn.setAttribute('aria-expanded', 'false');
      panel.hidden = true;
    }
  });
})();

// ── Export PDF download feedback ──────────────────────────────────────────────

(function () {
  document.querySelectorAll('[data-export-form]').forEach((form) => {
    const btn      = form.querySelector('[data-export-btn]');
    const tokenEl  = form.querySelector('[data-download-token]');
    const notice   = form.querySelector('[data-export-notice]');
    const viewLink = form.querySelector('[data-view-pdf-link]');
    if (!btn || !tokenEl) return;

    if (viewLink) {
      viewLink.addEventListener('click', (e) => {
        e.preventDefault();
        const prev = form.target;
        form.target = '_blank';
        form.submit();
        requestAnimationFrame(() => { form.target = prev; });
      });
    }

    form.addEventListener('submit', () => {
      const token = Math.random().toString(36).slice(2) + Date.now().toString(36);
      tokenEl.value = token;

      const originalHtml = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Generating PDF…';

      const cookieName = 'rx_dl_' + token;
      let attempts = 0;
      const poll = setInterval(() => {
        attempts++;
        if (document.cookie.includes(cookieName) || attempts > 60) {
          clearInterval(poll);
          document.cookie = cookieName + '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
          btn.disabled = false;
          btn.innerHTML = originalHtml;
          if (notice) notice.style.display = 'flex';
          if (viewLink) viewLink.hidden = false;
        }
      }, 500);
    });
  });
})();

// ── Export PDF: sync shared reporting-period inputs into each report form ────

(function () {
  const startEl = document.getElementById('report-start-shared');
  const endEl   = document.getElementById('report-end-shared');
  if (!startEl || !endEl) return;

  startEl.addEventListener('input', () => {
    document.querySelectorAll('[data-report-start-mirror]').forEach((el) => { el.value = startEl.value; });
  });
  endEl.addEventListener('input', () => {
    document.querySelectorAll('[data-report-end-mirror]').forEach((el) => { el.value = endEl.value; });
  });
})();
