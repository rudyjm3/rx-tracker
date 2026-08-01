(function () {
  const ob = window.rxOnboarding;
  if (!ob) return; // Not on the onboarding page

  // escHtml is defined in app.js and exposed via window.escHtml

  const shell        = document.querySelector('.onboarding-shell');
  const nextBtn      = document.getElementById('ob-next-btn');
  const prevBtn      = document.getElementById('ob-prev-btn');
  const stepCounter  = document.querySelector('[data-ob-step-counter]');
  const progressDots = document.querySelectorAll('.onboarding-progress-step');

  let currentStep = ob.currentStep;
  const totalSteps = ob.totalSteps;

  // ── Helpers ──────────────────────────────────────────────────────────────

  const postJson = async (body) => {
    const params = new URLSearchParams({ ...body, csrf_token: ob.csrfToken });
    const res = await fetch('index.php?page=onboarding', { method: 'POST', body: params });
    return res.json();
  };

  const showStep = (n) => {
    document.querySelectorAll('[data-onboarding-step]').forEach((el) => {
      el.hidden = parseInt(el.dataset.onboardingStep, 10) !== n;
    });

    progressDots.forEach((dot) => {
      const num = parseInt(dot.dataset.step, 10);
      dot.classList.toggle('is-active', num === n);
      dot.classList.toggle('is-done', num < n);
    });

    if (prevBtn) prevBtn.hidden = n === 1;
    if (stepCounter) stepCounter.textContent = `Step ${n} of ${totalSteps}`;

    if (nextBtn) {
      if (n === totalSteps) {
        nextBtn.innerHTML = 'Activate <i class="fa-solid fa-rocket" aria-hidden="true"></i>';
      } else {
        nextBtn.innerHTML = 'Next <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>';
      }
      nextBtn.disabled = n === 1 && ob.draftCount === 0;
    }

    currentStep = n;
    // Re-derive inventory enabled from live tracking checkboxes
    if (n === 4) {
      const enabledChecks = Array.from(
        trackingTbody?.querySelectorAll('[data-col="inventory_enabled"]:checked') || []
      );
      ob.hasInventoryEnabled = enabledChecks.length > 0;
      if (!ob.hasInventoryEnabled && nextBtn) nextBtn.disabled = false;

      // Sync step-4 DOM to reflect the current checkbox state
      const step4 = document.querySelector('[data-onboarding-step="4"]');
      if (step4) {
        const notice = step4.querySelector('.ob-no-inventory-notice');
        let invList = document.getElementById('ob-inventory-list');

        if (ob.hasInventoryEnabled) {
          if (notice) notice.hidden = true;

          if (!invList) {
            invList = document.createElement('div');
            invList.className = 'ob-inventory-list';
            invList.id = 'ob-inventory-list';
            step4.appendChild(invList);
          }
          invList.hidden = false;

          const enabledIds = new Set(
            enabledChecks
              .map((chk) => chk.closest('tr')?.dataset.trackingMedId)
              .filter(Boolean)
          );

          // Remove cards for meds no longer inventory-enabled
          invList.querySelectorAll('[data-inventory-med-id]').forEach((card) => {
            if (!enabledIds.has(card.dataset.inventoryMedId)) card.remove();
          });

          // Add cards for newly enabled meds
          enabledIds.forEach((medId) => {
            if (!invList.querySelector(`[data-inventory-med-id="${medId}"]`)) {
              const draft = ob.drafts.find((m) => String(m.id) === String(medId));
              if (draft) {
                const dose = [draft.dose_amount, draft.dose_unit].filter(Boolean).join(' ');
                addInventoryCard({
                  id: draft.id,
                  name: draft.name,
                  dose,
                  times: draft.times ?? [],
                  schedule_mode: draft.schedule_mode ?? 'fixed_times',
                  qty_per_dose: draft.qty_per_dose ?? 1,
                });
              }
            }
          });
        } else {
          if (notice) notice.hidden = false;
          if (invList) invList.hidden = true;
        }
      }
    }
    // Step 5: if no past slots just allow continuing
    if (n === 5 && ob.pastSlots.length === 0) {
      if (nextBtn) nextBtn.disabled = false;
    }
  };

  const goNext = () => {
    // Skip step 4 if no inventory-enabled meds
    let next = currentStep + 1;
    if (next === 4 && !ob.hasInventoryEnabled) next = 5;
    if (next === 5 && ob.pastSlots.length === 0) next = 6;
    if (next > totalSteps) return;
    showStep(next);
  };

  const goPrev = () => {
    let prev = currentStep - 1;
    if (prev === 5 && ob.pastSlots.length === 0) prev = 4;
    if (prev === 4 && !ob.hasInventoryEnabled) prev = 3;
    if (prev < 1) return;
    showStep(prev);
  };

  if (nextBtn) {
    nextBtn.addEventListener('click', () => {
      if (currentStep === totalSteps) {
        document.getElementById('ob-activate-btn')?.click();
      } else {
        goNext();
      }
    });
  }

  if (prevBtn) {
    prevBtn.addEventListener('click', goPrev);
  }

  // Click progress steps to go back
  progressDots.forEach((dot) => {
    dot.addEventListener('click', () => {
      const target = parseInt(dot.dataset.step, 10);
      if (target < currentStep) showStep(target);
    });
    dot.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        const target = parseInt(dot.dataset.step, 10);
        if (target < currentStep) showStep(target);
      }
    });
  });

  // ── Step 1: Medication CRUD ───────────────────────────────────────────────

  const medList       = document.getElementById('ob-med-list');
  const addMedBtn     = document.querySelector('[data-ob-add-medication]');
  const cancelEditBtn = document.querySelector('[data-ob-cancel-edit]');
  const formError     = document.querySelector('[data-ob-form-error]');
  const nameInput     = document.getElementById('ob-med-name');
  const doseAmtInput  = document.getElementById('ob-dose-amount');
  const doseUnitSel   = document.getElementById('ob-dose-unit');
  const doseFormSel   = document.getElementById('ob-dose-form');
  const medTypeSel    = document.getElementById('ob-med-type');
  const asNeededChk   = document.getElementById('ob-as-needed');
  const setIdInput    = document.getElementById('ob-set-id');
  const emptyState    = document.querySelector('[data-ob-empty]');

  let editingMedId = null;

  const resetForm = () => {
    if (nameInput) nameInput.value = '';
    if (doseAmtInput) doseAmtInput.value = '';
    if (doseUnitSel) doseUnitSel.value = '';
    if (doseFormSel) doseFormSel.value = '';
    if (medTypeSel) medTypeSel.value = 'prescription';
    if (asNeededChk) asNeededChk.checked = false;
    if (setIdInput) setIdInput.value = '';
    if (formError) { formError.hidden = true; formError.textContent = ''; }
    editingMedId = null;
    if (cancelEditBtn) cancelEditBtn.hidden = true;
    const formCard = document.getElementById('ob-add-form');
    if (formCard) formCard.querySelector('.ob-form-title').innerHTML = '<i class="fa-solid fa-plus" aria-hidden="true"></i> Add medication';
    if (addMedBtn) addMedBtn.innerHTML = '<i class="fa-solid fa-plus" aria-hidden="true"></i> Add to list';
  };

  const updateDraftCount = () => {
    const count = medList ? medList.querySelectorAll('[data-ob-med-id]').length : 0;
    ob.draftCount = count;
    if (nextBtn) nextBtn.disabled = count === 0 && currentStep === 1;
    if (emptyState) emptyState.hidden = count > 0;
  };

  const buildMedRow = (med) => {
    const typeLabels = { prescription: 'Rx', otc: 'OTC', supplement: 'Supplement' };
    const typeSlug   = med.medication_type || 'prescription';
    const dose       = [med.dose_amount ? parseFloat(med.dose_amount) : '', med.dose_unit || ''].filter(Boolean).join(' ');

    const el = document.createElement('div');
    el.className = 'ob-med-item';
    el.dataset.obMedId = med.id;
    el.innerHTML = `
      <div class="ob-med-item-info">
        <strong>${window.escHtml(med.name)}</strong>
        ${dose ? `<span class="ob-med-item-dose">${window.escHtml(dose)}</span>` : ''}
        ${med.as_needed ? '<span class="ob-badge ob-badge--prn">PRN</span>' : ''}
        <span class="med-type-badge med-type-badge--${window.escHtml(typeSlug)}">${window.escHtml(typeLabels[typeSlug] || 'Rx')}</span>
      </div>
      <div class="ob-med-item-actions">
        <button type="button" class="btn-icon" data-ob-edit-med="${med.id}" title="Edit"
                data-med-name="${window.escHtml(med.name)}"
                data-med-dose-amount="${window.escHtml(String(med.dose_amount ?? ''))}"
                data-med-dose-unit="${window.escHtml(med.dose_unit ?? '')}"
                data-med-dose-form="${window.escHtml(med.dose_form ?? '')}"
                data-med-type="${window.escHtml(typeSlug)}"
                data-med-set-id="${window.escHtml(med.set_id ?? '')}"
                data-med-as-needed="${med.as_needed ? 1 : 0}">
          <i class="fa-solid fa-pen" aria-hidden="true"></i>
        </button>
        <button type="button" class="btn-icon btn-icon--danger" data-ob-delete-med="${med.id}" title="Remove">
          <i class="fa-solid fa-trash" aria-hidden="true"></i>
        </button>
      </div>`;
    return el;
  };

  if (addMedBtn) {
    addMedBtn.addEventListener('click', async () => {
      if (formError) formError.hidden = true;
      const name = nameInput?.value.trim() || '';
      if (!name) {
        if (formError) { formError.textContent = 'Medication name is required.'; formError.hidden = false; }
        nameInput?.focus();
        return;
      }

      addMedBtn.disabled = true;
      addMedBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>';

      const body = {
        action: editingMedId ? 'update_draft_medication' : 'add_draft_medication',
        name,
        dose_amount: doseAmtInput?.value.trim() || '',
        dose_unit: doseUnitSel?.value || '',
        dose_form: doseFormSel?.value || '',
        medication_type: medTypeSel?.value || 'prescription',
        set_id: setIdInput?.value.trim() || '',
        as_needed: asNeededChk?.checked ? '1' : '0',
      };
      if (editingMedId) body.medication_id = String(editingMedId);

      try {
        const data = await postJson(body);
        if (!data.ok) throw new Error(data.error || 'Failed to save.');

        if (editingMedId) {
          const existing = medList?.querySelector(`[data-ob-med-id="${editingMedId}"]`);
          if (existing) existing.replaceWith(buildMedRow(data));
          const draftIdx = ob.drafts.findIndex((m) => m.id === editingMedId);
          if (draftIdx !== -1) {
            ob.drafts[draftIdx] = Object.assign(ob.drafts[draftIdx], {
              name: data.name,
              dose_amount: data.dose ?? null,
              dose_unit: null,
              as_needed: !!data.as_needed,
            });
          }
        } else {
          if (emptyState) emptyState.hidden = true;
          medList?.appendChild(buildMedRow(data));
          addScheduleCard(data);
          addTrackingRow(data);
          addInventoryCard(data);
          ob.drafts.push({
            id: data.id,
            name: data.name,
            dose_amount: data.dose ?? null,
            dose_unit: null,
            dose_form: data.form ?? null,
            medication_type: data.type ?? 'prescription',
            set_id: '',
            as_needed: !!data.as_needed,
            reminders_enabled: true,
            adherence_enabled: true,
            inventory_enabled: false,
            times: [],
            schedule_mode: 'fixed_times',
            qty_per_dose: 1,
          });
        }
        resetForm();
        updateDraftCount();
        nameInput?.focus();
      } catch (err) {
        if (formError) { formError.textContent = err.message; formError.hidden = false; }
      } finally {
        addMedBtn.disabled = false;
        addMedBtn.innerHTML = '<i class="fa-solid fa-plus" aria-hidden="true"></i> ' + (editingMedId ? 'Save changes' : 'Add to list');
      }
    });
  }

  if (cancelEditBtn) {
    cancelEditBtn.addEventListener('click', resetForm);
  }

  document.addEventListener('click', async (e) => {
    // Edit button
    const editBtn = e.target.closest('[data-ob-edit-med]');
    if (editBtn) {
      editingMedId = parseInt(editBtn.dataset.obEditMed, 10);
      if (nameInput) nameInput.value = editBtn.dataset.medName || '';
      if (doseAmtInput) doseAmtInput.value = editBtn.dataset.medDoseAmount || '';
      if (doseUnitSel) doseUnitSel.value = editBtn.dataset.medDoseUnit || '';
      if (doseFormSel) doseFormSel.value = editBtn.dataset.medDoseForm || '';
      if (medTypeSel) medTypeSel.value = editBtn.dataset.medType || 'prescription';
      if (setIdInput) setIdInput.value = editBtn.dataset.medSetId || '';
      if (asNeededChk) asNeededChk.checked = editBtn.dataset.medAsNeeded === '1';
      if (cancelEditBtn) cancelEditBtn.hidden = false;
      const formCard = document.getElementById('ob-add-form');
      if (formCard) formCard.querySelector('.ob-form-title').innerHTML = '<i class="fa-solid fa-pen" aria-hidden="true"></i> Edit medication';
      if (addMedBtn) addMedBtn.innerHTML = '<i class="fa-solid fa-save" aria-hidden="true"></i> Save changes';
      nameInput?.focus();
      formCard?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      return;
    }

    // Delete button
    const deleteBtn = e.target.closest('[data-ob-delete-med]');
    if (deleteBtn) {
      const id = parseInt(deleteBtn.dataset.obDeleteMed, 10);
      if (!confirm('Remove this medication?')) return;
      try {
        const data = await postJson({ action: 'delete_draft_medication', medication_id: String(id) });
        if (!data.ok) throw new Error(data.error || 'Could not delete.');
        medList?.querySelector(`[data-ob-med-id="${id}"]`)?.remove();
        document.querySelector(`[data-schedule-med-id="${id}"]`)?.remove();
        document.querySelector(`[data-tracking-med-id="${id}"]`)?.remove();
        document.querySelector(`[data-inventory-med-id="${id}"]`)?.remove();
        const delIdx = ob.drafts.findIndex((m) => m.id === id);
        if (delIdx !== -1) ob.drafts.splice(delIdx, 1);
        updateDraftCount();
      } catch (err) {
        alert(err.message);
      }
    }
  });

  // ── Step 2: Tracking preferences helpers ─────────────────────────────────

  const trackingTbody = document.getElementById('ob-tracking-tbody');

  const addTrackingRow = (med) => {
    if (!trackingTbody) return;
    const typeLabels = { prescription: 'Rx', otc: 'OTC', supplement: 'Supplement' };
    const isAsNeeded = med.as_needed ? 1 : 0;
    const tr = document.createElement('tr');
    tr.dataset.trackingMedId = med.id;
    if (isAsNeeded) tr.className = 'ob-tracking-row--prn';
    tr.innerHTML = `
      <td class="ob-tracking-col--name">
        <strong>${window.escHtml(med.name)}</strong>
        ${isAsNeeded ? '<span class="ob-badge ob-badge--prn">PRN</span>' : ''}
      </td>
      <td class="ob-tracking-col--check">
        <input type="checkbox" class="ob-tracking-check" data-col="reminders_enabled"
               value="1" ${(!isAsNeeded && med.reminders_enabled !== false) ? 'checked' : ''}
               ${isAsNeeded ? 'disabled' : ''}>
      </td>
      <td class="ob-tracking-col--check">
        <input type="checkbox" class="ob-tracking-check" data-col="adherence_enabled"
               value="1" ${(!isAsNeeded && med.adherence_enabled !== false) ? 'checked' : ''}
               ${isAsNeeded ? 'disabled' : ''}>
      </td>
      <td class="ob-tracking-col--check">
        <input type="checkbox" class="ob-tracking-check" data-col="inventory_enabled"
               value="1" ${med.inventory_enabled ? 'checked' : ''}>
      </td>`;
    trackingTbody.appendChild(tr);
  };

  // Save tracking preferences when leaving step 2
  const saveTrackingPreferences = async () => {
    const rows = trackingTbody?.querySelectorAll('tr[data-tracking-med-id]') || [];
    const prefs = {};
    rows.forEach((row) => {
      const id = row.dataset.trackingMedId;
      prefs[id] = {};
      row.querySelectorAll('.ob-tracking-check').forEach((chk) => {
        prefs[id][chk.dataset.col] = chk.checked ? '1' : '0';
      });
      // Also sync inventory enabled back into ob.hasInventoryEnabled
    });
    // Build flat POST body
    const body = { action: 'save_tracking_preferences' };
    Object.entries(prefs).forEach(([medId, p]) => {
      Object.entries(p).forEach(([col, val]) => {
        body[`prefs[${medId}][${col}]`] = val;
      });
    });
    await postJson(body);
    // Refresh hasInventoryEnabled
    ob.hasInventoryEnabled = Array.from(trackingTbody?.querySelectorAll('[data-col="inventory_enabled"]:checked') || []).length > 0;
  };

  // Column toggle buttons
  document.querySelectorAll('[data-col-toggle]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const col = btn.dataset.colToggle;
      const checks = trackingTbody?.querySelectorAll(`[data-col="${col}"]:not(:disabled)`) || [];
      const allChecked = Array.from(checks).every((c) => c.checked);
      checks.forEach((c) => { c.checked = !allChecked; });
    });
  });

  // Intercept Next on step 2
  const origNext = nextBtn?.addEventListener;

  // ── Step 3: Schedule card helpers ────────────────────────────────────────

  const scheduleList = document.getElementById('ob-schedule-list');

  const addScheduleCard = (med) => {
    if (!scheduleList) return;
    if (med.as_needed) return; // PRN meds don't need a schedule card
    const card = document.createElement('div');
    card.className = 'ob-schedule-card';
    card.dataset.scheduleMedId = med.id;
    card.innerHTML = `
      <div class="ob-schedule-card-header">
        <strong>${window.escHtml(med.name)}</strong>
      </div>
      <div class="ob-schedule-card-body">
        <div class="ob-preset-btns">
          <span class="ob-preset-label">Quick add:</span>
          <button type="button" class="btn-tag" data-add-time="8:00 AM">Morning 8am</button>
          <button type="button" class="btn-tag" data-add-time="12:00 PM">Noon</button>
          <button type="button" class="btn-tag" data-add-time="6:00 PM">Evening 6pm</button>
          <button type="button" class="btn-tag" data-add-time="10:00 PM">Bedtime 10pm</button>
        </div>
        <div class="ob-times-list" data-times-list></div>
        <div class="ob-add-custom-time">
          <input type="text" class="ob-custom-time-input" placeholder="e.g. 9:30 AM" autocomplete="off">
          <button type="button" class="btn btn-sm btn-outline" data-add-custom-time>+ Add time</button>
        </div>
      </div>
      <div class="ob-schedule-save-row">
        <button type="button" class="btn btn-sm btn-primary ob-save-schedule-btn"
                data-med-id="${med.id}" data-qty-per-dose="${med.dose_amount || 1}">
          Save schedule
        </button>
        <span class="ob-schedule-saved-indicator" data-schedule-saved hidden>
          <i class="fa-solid fa-check" aria-hidden="true"></i> Saved
        </span>
      </div>`;
    scheduleList.appendChild(card);
    wireScheduleCard(card);
  };

  const addTimeRow = (timesList, timeDisplay, qty) => {
    const row = document.createElement('div');
    row.className = 'ob-time-row';
    row.dataset.timeRow = '';
    row.innerHTML = `
      <input type="hidden" name="dose_times[]" value="${window.escHtml(timeDisplay)}" class="ob-time-hidden">
      <span class="ob-time-pill">${window.escHtml(timeDisplay)}</span>
      <div class="ob-time-qty">
        <label>Qty:</label>
        <input type="number" step="any" min="0.001" value="${qty}" class="ob-qty-input" name="dose_qtys[]">
      </div>
      <button type="button" class="btn-icon btn-icon--danger ob-remove-time" title="Remove">
        <i class="fa-solid fa-times" aria-hidden="true"></i>
      </button>`;
    row.querySelector('.ob-remove-time').addEventListener('click', () => row.remove());
    timesList.appendChild(row);
    return row;
  };

  const wireScheduleCard = (card) => {
    const timesList = card.querySelector('[data-times-list]');

    card.querySelectorAll('[data-add-time]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const t = btn.dataset.addTime;
        const defaultQty = parseFloat(card.querySelector('[data-qty-per-dose]')?.dataset.qtyPerDose || 1);
        addTimeRow(timesList, t, isNaN(defaultQty) ? 1 : defaultQty);
      });
    });

    const customInput = card.querySelector('.ob-custom-time-input');
    const customBtn   = card.querySelector('[data-add-custom-time]');
    if (customBtn && customInput) {
      const addCustom = () => {
        const v = customInput.value.trim();
        if (!v) return;
        const defaultQty = parseFloat(card.querySelector('[data-qty-per-dose]')?.dataset.qtyPerDose || 1);
        addTimeRow(timesList, v, isNaN(defaultQty) ? 1 : defaultQty);
        customInput.value = '';
      };
      customBtn.addEventListener('click', addCustom);
      customInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); addCustom(); } });
    }

    const saveBtn   = card.querySelector('.ob-save-schedule-btn');
    const savedInd  = card.querySelector('[data-schedule-saved]');
    if (saveBtn) {
      saveBtn.addEventListener('click', async () => {
        const medId = saveBtn.dataset.medId;
        const rows  = timesList?.querySelectorAll('[data-time-row]') || [];
        const times = [];
        const qtys  = [];
        rows.forEach((r) => {
          times.push(r.querySelector('.ob-time-hidden')?.value || '');
          qtys.push(r.querySelector('.ob-qty-input')?.value || '1');
        });

        saveBtn.disabled = true;
        try {
          const body = { action: 'save_draft_schedule', medication_id: medId };
          times.forEach((t, i) => {
            body[`dose_times[${i}]`] = t;
            body[`dose_qtys[${i}]`] = qtys[i];
          });
          const data = await postJson(body);
          if (!data.ok) throw new Error(data.error || 'Could not save schedule.');
          if (savedInd) { savedInd.hidden = false; setTimeout(() => { savedInd.hidden = true; }, 2500); }
        } catch (err) {
          alert(err.message);
        } finally {
          saveBtn.disabled = false;
        }
      });
    }
  };

  // Wire existing schedule cards (server-rendered)
  document.querySelectorAll('.ob-schedule-card').forEach(wireScheduleCard);

  // Remove time button on server-rendered rows
  document.querySelectorAll('.ob-remove-time').forEach((btn) => {
    btn.addEventListener('click', () => btn.closest('[data-time-row]')?.remove());
  });

  // ── Step 4: Inventory ─────────────────────────────────────────────────────

  const wireInventoryCard = (card) => {
    const tabs   = card.querySelectorAll('.ob-method-tab');
    const panels = card.querySelectorAll('.ob-method-panel');

    tabs.forEach((tab) => {
      tab.addEventListener('click', () => {
        const method = tab.dataset.methodTab;
        tabs.forEach((t) => t.classList.toggle('is-active', t === tab));
        panels.forEach((p) => { p.hidden = p.dataset.methodPanel !== method; });
      });
    });

    // Estimate calculator
    const calcBtn = card.querySelector('[data-calc-estimate]');
    if (calcBtn) {
      calcBtn.addEventListener('click', async () => {
        const medId     = calcBtn.dataset.medId;
        const fillDate  = card.querySelector('[data-fill-date]')?.value;
        const fillQty   = card.querySelector('[data-fill-qty]')?.value;
        const carryover = card.querySelector('[data-carryover-qty]')?.value || '0';
        const times     = JSON.parse(calcBtn.dataset.times || '[]');
        const qtyPer    = parseFloat(calcBtn.dataset.qtyPerDose || '1');
        const schedMode = calcBtn.dataset.scheduleMode || 'fixed_times';

        if (!fillDate || !fillQty) { alert('Please enter fill date and quantity.'); return; }

        calcBtn.disabled = true;
        try {
          const body = {
            action: 'estimate_inventory',
            started_using_at: fillDate,
            quantity_dispensed: fillQty,
            carryover,
            schedule_mode: schedMode,
            default_qty_per_dose: String(qtyPer),
          };
          times.forEach((t, i) => { body[`times[${i}]`] = t; });

          const data = await postJson(body);
          const resultEl = card.querySelector('[data-estimate-result]');
          if (data.ok && resultEl) {
            card.querySelector('[data-estimate-value]').textContent =
              `Estimated remaining: ~${Math.max(0, data.estimated_remaining)} tablets`;
            card.querySelector('[data-estimate-confidence]').textContent =
              `Confidence: ${data.confidence}`;
            const warnings = card.querySelector('[data-estimate-warnings]');
            warnings.textContent = (data.warnings || []).join(' ');
            resultEl.hidden = false;
            card.dataset.estimatedQty = String(data.estimated_remaining);
          } else {
            alert(data.error || 'Could not calculate.');
          }
        } finally {
          calcBtn.disabled = false;
        }
      });
    }

    // Save inventory
    const saveBtn  = card.querySelector('.ob-save-inventory-btn');
    const savedInd = card.querySelector('[data-inventory-saved]');
    if (saveBtn) {
      saveBtn.addEventListener('click', async () => {
        const medId  = saveBtn.dataset.medId;
        const active = card.querySelector('.ob-method-tab.is-active')?.dataset.methodTab || 'skip';

        let qty = 0;
        let method = active;

        if (active === 'counted') {
          qty = parseFloat(card.querySelector('[data-counted-qty]')?.value || '0');
          if (isNaN(qty) || qty < 0) { alert('Please enter a valid quantity.'); return; }
        } else if (active === 'estimated') {
          qty = parseFloat(card.dataset.estimatedQty || '0');
          if (isNaN(qty) || qty < 0) qty = 0;
        }

        saveBtn.disabled = true;
        try {
          const data = await postJson({
            action: 'save_draft_inventory',
            medication_id: medId,
            count_method: method,
            quantity: String(qty),
          });
          if (!data.ok) throw new Error(data.error || 'Could not save.');
          if (savedInd) { savedInd.hidden = false; setTimeout(() => { savedInd.hidden = true; }, 2500); }
        } catch (err) {
          alert(err.message);
        } finally {
          saveBtn.disabled = false;
        }
      });
    }
  };

  const addInventoryCard = (med) => {
    const list = document.getElementById('ob-inventory-list');
    if (!list) return;
    const today = new Date().toISOString().slice(0, 10);
    const card = document.createElement('div');
    card.className = 'ob-inventory-card';
    card.dataset.inventoryMedId = med.id;
    card.innerHTML = `
      <div class="ob-inventory-card-header">
        <strong>${window.escHtml(med.name)}</strong>
        ${med.dose ? `<span class="ob-med-item-dose">${window.escHtml(med.dose)}</span>` : ''}
      </div>
      <div class="ob-inventory-method-tabs">
        <button type="button" class="ob-method-tab is-active" data-method-tab="counted">
          <i class="fa-solid fa-calculator" aria-hidden="true"></i> Count now
        </button>
        <button type="button" class="ob-method-tab" data-method-tab="estimated">
          <i class="fa-solid fa-vial" aria-hidden="true"></i> Estimate from fill
        </button>
        <button type="button" class="ob-method-tab" data-method-tab="skip">
          <i class="fa-solid fa-forward" aria-hidden="true"></i> Skip
        </button>
      </div>
      <div class="ob-method-panel" data-method-panel="counted">
        <label class="ob-field-label">How many do you have right now?</label>
        <div class="ob-qty-row">
          <input type="number" step="any" min="0" class="ob-inv-count-input"
                 placeholder="e.g. 30" data-counted-qty>
          <span>tablets</span>
        </div>
      </div>
      <div class="ob-method-panel" data-method-panel="estimated" hidden>
        <div class="ob-form-row ob-form-row--2col">
          <div class="ob-field">
            <label>Fill date</label>
            <input type="date" class="ob-fill-date" max="${window.escHtml(today)}" data-fill-date value="${window.escHtml(today)}">
          </div>
          <div class="ob-field">
            <label>Quantity dispensed</label>
            <input type="number" step="any" min="1" class="ob-fill-qty" placeholder="e.g. 90" data-fill-qty>
          </div>
        </div>
        <div class="ob-form-row">
          <div class="ob-field">
            <label>Carryover from previous fill (optional)</label>
            <input type="number" step="any" min="0" value="0" class="ob-carryover-qty" data-carryover-qty>
          </div>
        </div>
        <div class="ob-estimate-result" data-estimate-result hidden>
          <div class="ob-estimate-value" data-estimate-value></div>
          <div class="ob-estimate-confidence" data-estimate-confidence></div>
          <div class="ob-estimate-warnings" data-estimate-warnings></div>
        </div>
        <button type="button" class="btn btn-sm btn-outline ob-calc-btn" data-calc-estimate
                data-med-id="${window.escHtml(String(med.id))}"
                data-schedule-mode="${window.escHtml(String(med.schedule_mode ?? 'fixed_times'))}"
                data-times="${window.escHtml(JSON.stringify(med.times ?? []))}"
                data-qty-per-dose="${window.escHtml(String(med.qty_per_dose ?? 1))}">
          Calculate estimate
        </button>
      </div>
      <div class="ob-method-panel" data-method-panel="skip" hidden>
        <p class="ob-notice">Inventory tracking will be disabled for this medication. You can set it up later from the medication settings.</p>
      </div>
      <div class="ob-inventory-save-row">
        <button type="button" class="btn btn-sm btn-primary ob-save-inventory-btn"
                data-med-id="${window.escHtml(String(med.id))}">
          Save
        </button>
        <span class="ob-schedule-saved-indicator" data-inventory-saved hidden>
          <i class="fa-solid fa-check" aria-hidden="true"></i> Saved
        </span>
      </div>`;
    list.appendChild(card);
    wireInventoryCard(card);
  };

  document.querySelectorAll('.ob-inventory-card').forEach(wireInventoryCard);

  // ── Step 5: Reconcile today ───────────────────────────────────────────────

  document.querySelectorAll('[data-mark-group-taken]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const group = btn.closest('.ob-reconcile-group');
      group?.querySelectorAll('.ob-reconcile-check').forEach((chk) => { chk.checked = true; });
    });
  });

  document.querySelectorAll('[data-skip-group]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const group = btn.closest('.ob-reconcile-group');
      group?.querySelectorAll('.ob-reconcile-check').forEach((chk) => { chk.checked = false; });
    });
  });

  const submitReconcile = async () => {
    const entries = [];
    document.querySelectorAll('[data-reconcile-med]').forEach((medEl) => {
      const chk = medEl.querySelector('.ob-reconcile-check');
      if (chk?.checked) {
        entries.push({
          medication_id: medEl.dataset.medId,
          time: medEl.dataset.time,
          status: 'taken',
        });
      }
    });

    const body = { action: 'reconcile_doses' };
    entries.forEach((e, i) => {
      body[`doses[${i}][medication_id]`] = e.medication_id;
      body[`doses[${i}][time]`]          = e.time;
      body[`doses[${i}][status]`]        = e.status;
    });

    const btn = document.getElementById('ob-submit-reconcile');
    if (btn) btn.disabled = true;
    try {
      const data = await postJson(body);
      if (!data.ok) throw new Error(data.error || 'Could not save.');
      goNext();
    } catch (err) {
      alert(err.message);
    } finally {
      if (btn) btn.disabled = false;
    }
  };

  document.getElementById('ob-submit-reconcile')?.addEventListener('click', submitReconcile);

  document.querySelector('[data-ob-skip-reconcile]')?.addEventListener('click', () => {
    if (confirm('Skip reconciliation? Earlier doses today will NOT be marked as missed — tracking starts from now.')) {
      goNext();
    }
  });

  // ── Step 6: Activate ─────────────────────────────────────────────────────

  const activateBtn   = document.getElementById('ob-activate-btn');
  const activateError = document.querySelector('[data-ob-activate-error]');

  if (activateBtn) {
    activateBtn.addEventListener('click', async () => {
      activateBtn.disabled = true;
      activateBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Activating…';
      if (activateError) activateError.hidden = true;
      try {
        const data = await postJson({ action: 'activate_onboarding' });
        if (!data.ok) throw new Error(data.error || 'Could not activate.');
        window.location.href = 'index.php?notice=setup_complete';
      } catch (err) {
        if (activateError) { activateError.textContent = err.message; activateError.hidden = false; }
        activateBtn.disabled = false;
        activateBtn.innerHTML = '<i class="fa-solid fa-rocket" aria-hidden="true"></i> Activate & go to dashboard';
      }
    });
  }

  // Next button on step 2 also saves tracking prefs
  if (nextBtn) {
    const originalClickHandlers = [];
    nextBtn.addEventListener('click', async (e) => {
      if (currentStep === 2) {
        await saveTrackingPreferences();
      }
    }, true); // capture phase to run before the other handler
  }

})();
