// Multi-step "Add medication" wizard: step navigation, animated segmented progress bar,
// forward-navigation gating, Save draft, and the exit-confirmation flow. Only active when the
// wizard form is present in the DOM (i.e. not editing an existing medication — see
// includes/pages-shell-top.php, which renders today's single-page form for edits).
(() => {
  const form = document.querySelector('.med-wizard-form');
  if (!form) return;

  const steps = [...form.querySelectorAll('[data-med-wizard-step]')];
  const progress = document.querySelector('[data-med-wizard-progress]');
  const segs = progress ? [...progress.querySelectorAll('[data-med-wizard-step-nav]')] : [];
  const backBtn = form.querySelector('[data-med-wizard-back]');
  const nextBtn = form.querySelector('[data-med-wizard-next]');
  const submitBtn = form.querySelector('[data-med-wizard-submit]');
  const saveDraftBtn = form.querySelector('[data-med-wizard-save-draft]');
  const cancelBtn = form.querySelector('[data-med-wizard-cancel]');
  const draftIdInput = form.querySelector('[data-med-wizard-draft-id]');
  const nameInput = form.querySelector('[data-med-name-input]');
  const exitModal = document.querySelector('[data-med-wizard-exit-modal]');
  const exitConfirmBtn = exitModal?.querySelector('[data-med-wizard-exit-confirm]');
  const exitCancelBtn = exitModal?.querySelector('[data-med-wizard-exit-cancel]');
  const addEndDateBtn = form.querySelector('[data-add-end-date]');
  const endDateField = form.querySelector('[data-end-date-field]');
  const addNotesBtn = form.querySelector('[data-add-notes]');
  const notesField = form.querySelector('[data-notes-field]');

  let currentStep = Math.min(4, Math.max(1, parseInt(form.dataset.medWizardInitialStep || '1', 10)));
  let furthestStep = Math.min(4, Math.max(currentStep, parseInt(progress?.dataset.medWizardFurthest || '1', 10)));

  const stepEl = (n) => steps.find((el) => Number(el.dataset.medWizardStep) === n);

  const updateProgress = () => {
    segs.forEach((seg) => {
      const n = Number(seg.dataset.medWizardStepNav);
      const locked = n > furthestStep;
      seg.classList.toggle('is-active', n === currentStep);
      seg.classList.toggle('is-done', n !== currentStep && n <= furthestStep);
      seg.classList.toggle('is-locked', locked);
      seg.disabled = locked;
    });
  };

  const showStep = (n) => {
    steps.forEach((el) => {
      el.hidden = Number(el.dataset.medWizardStep) !== n;
    });
    currentStep = n;
    if (backBtn) backBtn.hidden = n === 1;
    if (nextBtn) nextBtn.hidden = n === 4;
    if (submitBtn) submitBtn.hidden = n !== 4;
    updateProgress();
    form.closest('.modal-scroll')?.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const goToStep = (n) => {
    if (n === currentStep || n > furthestStep) return;
    showStep(n);
  };

  segs.forEach((seg) => {
    seg.addEventListener('click', () => goToStep(Number(seg.dataset.medWizardStepNav)));
  });

  backBtn?.addEventListener('click', () => goToStep(Math.max(1, currentStep - 1)));

  const parse12h = (value) => {
    const match = value.trim().match(/^(0?[1-9]|1[0-2]):([0-5]\d)\s*([AaPp][Mm])$/);
    return match !== null;
  };
  const parse24h = (value) => /^([01]\d|2[0-3]):([0-5]\d)$/.test(value.trim());
  const isValidTimeToken = (value) => parse12h(value) || parse24h(value);

  const validateScheduleStep = () => {
    const el = stepEl(3);
    if (!el) return true;
    const scheduleMode = el.querySelector('select[name="schedule_mode"]')?.value;
    if (scheduleMode === 'interval') return true;
    const timeFields = [...el.querySelectorAll('.dose-time-field')];
    const filled = timeFields.filter((f) => f.value.trim() !== '');
    if (filled.length === 0) {
      window.alert('Add at least one dose time, or switch to "Every X hours" schedule.');
      return false;
    }
    for (const field of filled) {
      if (!isValidTimeToken(field.value.trim())) {
        window.alert('Invalid dose time "' + field.value + '". Use h:MM AM/PM format (e.g. 8:00 AM).');
        field.focus();
        return false;
      }
    }
    return true;
  };

  const validateStep = (n) => {
    const el = stepEl(n);
    if (el) {
      const fields = [...el.querySelectorAll('input, select, textarea')];
      for (const field of fields) {
        if (!field.checkValidity()) {
          field.reportValidity();
          return false;
        }
      }
    }
    if (n === 3 && !validateScheduleStep()) return false;
    return true;
  };

  nextBtn?.addEventListener('click', () => {
    if (!validateStep(currentStep)) return;
    const next = Math.min(4, currentStep + 1);
    furthestStep = Math.max(furthestStep, next);
    showStep(next);
  });

  addEndDateBtn?.addEventListener('click', () => {
    if (endDateField) endDateField.hidden = false;
    addEndDateBtn.hidden = true;
    endDateField?.querySelector('input')?.focus();
  });

  addNotesBtn?.addEventListener('click', () => {
    if (notesField) notesField.hidden = false;
    addNotesBtn.hidden = true;
    notesField?.querySelector('textarea')?.focus();
  });

  const updateSaveDraftState = () => {
    if (saveDraftBtn) saveDraftBtn.disabled = nameInput?.value.trim() === '';
  };
  nameInput?.addEventListener('input', updateSaveDraftState);
  updateSaveDraftState();

  saveDraftBtn?.addEventListener('click', async () => {
    if (nameInput?.value.trim() === '') {
      window.alert('Enter a medication name before saving a draft.');
      return;
    }
    saveDraftBtn.disabled = true;
    const originalLabel = saveDraftBtn.textContent;
    saveDraftBtn.textContent = 'Saving…';
    try {
      const formData = new FormData(form);
      formData.set('action', 'save_medication_draft');
      formData.set('json_response', '1');
      formData.set('current_step', String(currentStep));
      formData.set('furthest_step', String(furthestStep));
      const response = await fetch('index.php', { method: 'POST', body: formData });
      const data = await response.json();
      if (!data.ok) {
        window.alert(data.error || 'Could not save draft.');
        saveDraftBtn.disabled = false;
        saveDraftBtn.textContent = originalLabel;
        return;
      }
      if (draftIdInput) draftIdInput.value = data.draft_id;
      window.location.href = 'index.php?page=medications&notice=' + encodeURIComponent('Draft saved. Resume it any time from the Medication Plan list.');
    } catch (err) {
      window.alert('Could not save draft. Check your connection and try again.');
      saveDraftBtn.disabled = false;
      saveDraftBtn.textContent = originalLabel;
    }
  });

  const performExit = () => {
    if (window.location.search.includes('draft=')) {
      window.location.href = 'index.php?page=medications';
      return;
    }
    window.closeMedicationModal?.();
    // No page reload happens for a brand-new (non-draft) add, so reset the wizard back to a
    // clean slate in case the same modal is reopened later in this page's lifetime.
    form.reset();
    // form.reset() restores values but doesn't fire `change`, and app.js drives the
    // schedule-mode/dose-form visibility toggles off `change` listeners — dispatch synthetic
    // events so those handlers recompute visibility against the reset values.
    ['schedule_mode', 'dose_form', 'bottle_unit'].forEach((name) => {
      const field = form.querySelector(`[name="${name}"]`);
      field?.dispatchEvent(new Event('change', { bubbles: true }));
    });
    furthestStep = 1;
    showStep(1);
    if (addEndDateBtn) addEndDateBtn.hidden = false;
    if (endDateField) endDateField.hidden = true;
    if (addNotesBtn) addNotesBtn.hidden = false;
    if (notesField) notesField.hidden = true;
  };

  const openExitModal = () => {
    if (!exitModal) {
      performExit();
      return;
    }
    exitModal.classList.add('is-open');
    window.lockBodyScroll?.();
  };

  const closeExitModal = () => {
    if (!exitModal) return;
    exitModal.classList.remove('is-open');
    window.unlockBodyScroll?.();
  };

  cancelBtn?.addEventListener('click', openExitModal);
  exitCancelBtn?.addEventListener('click', closeExitModal);
  exitConfirmBtn?.addEventListener('click', () => {
    closeExitModal();
    performExit();
  });

  // Intercept the modal's (X) close button so it goes through the same confirmation.
  window.medWizardInterceptClose = () => {
    openExitModal();
    return true;
  };

  showStep(currentStep);
})();
