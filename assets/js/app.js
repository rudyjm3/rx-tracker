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
const feedbackNoteEl = document.querySelector('[data-feedback-note]');
const feedbackCharCounter = document.querySelector('[data-feedback-char-counter]');
const feedbackPainSection = document.querySelector('[data-feedback-pain-section]');
const skipFeedbackBtn = document.querySelector('[data-skip-feedback]');

const openMedicationModal = () => {
  if (!medicationModal) return;
  closeMedPlanModal();
  medicationModal.classList.add('is-open');
  lockBodyScroll();
};

const closeMedicationModal = () => {
  if (!medicationModal) return;
  if (!medicationModal.classList.contains('is-open')) return;
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

// ── Dose feedback modal ───────────────────────────────────────────────────────

let feedbackAlarmContext = false;

const openDoseFeedbackModal = (medicationId, scheduledDate, scheduledTime, showPain, fromAlarm = false) => {
  if (!doseFeedbackModal) return;
  feedbackAlarmContext = fromAlarm;
  if (feedbackMedicationIdEl) feedbackMedicationIdEl.value = medicationId;
  if (feedbackScheduledDateEl) feedbackScheduledDateEl.value = scheduledDate;
  if (feedbackScheduledTimeEl) feedbackScheduledTimeEl.value = scheduledTime;
  if (feedbackPainLevelEl) feedbackPainLevelEl.value = '';
  if (feedbackNoteEl) feedbackNoteEl.value = '';
  if (feedbackCharCounter) feedbackCharCounter.textContent = '[0/250]';
  document.querySelectorAll('.pain-level-btn').forEach((b) => b.classList.remove('is-selected'));
  if (feedbackPainSection) {
    feedbackPainSection.classList.toggle('is-hidden', !showPain);
  }
  doseFeedbackModal.classList.add('is-open');
  lockBodyScroll();
};

const closeDoseFeedbackModal = (cancelQueue = false) => {
  if (!doseFeedbackModal) return;
  if (!doseFeedbackModal.classList.contains('is-open')) return;
  doseFeedbackModal.classList.remove('is-open');
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

const submitFeedbackAsAlarmAction = async () => {
  const note = feedbackNoteEl?.value ?? '';
  const painLevel = feedbackPainLevelEl?.value ?? '';
  closeDoseFeedbackModal();
  hideAlarmOverlay();
  await alarmAction('mark_dose', { status: 'taken', note, pain_level: painLevel });
};

feedbackForm?.addEventListener('submit', async (event) => {
  if (feedbackQueueMode) {
    event.preventDefault();
    const note = feedbackNoteEl?.value ?? '';
    const painLevel = feedbackPainLevelEl?.value ?? '';
    await submitCurrentQueueItem(note, painLevel);
  } else if (feedbackAlarmContext) {
    event.preventDefault();
    await submitFeedbackAsAlarmAction();
  }
});

skipFeedbackBtn?.addEventListener('click', async () => {
  if (feedbackQueueMode) {
    await submitCurrentQueueItem('', '');
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

document.querySelectorAll('[data-take-dose]').forEach((btn) => {
  btn.addEventListener('click', (event) => {
    if (btn.dataset.trackDoseFeedback === '1' && !btn.disabled) {
      event.preventDefault();
      const medicationId = btn.dataset.medicationId ?? '';
      const scheduledDate = btn.dataset.scheduledDate ?? '';
      const scheduledTime = btn.dataset.scheduledTime ?? '';
      if (!medicationId || !scheduledDate || !scheduledTime) return;
      openDoseFeedbackModal(medicationId, scheduledDate, scheduledTime, true, false);
    }
  });
});

document.querySelectorAll('[data-log-dose-now-form]').forEach((form) => {
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const btn = form.querySelector('[data-log-dose-now]');
    const trackFeedback = btn?.dataset.trackDoseFeedback === '1';
    const medicationId = btn?.dataset.medicationId ?? '';

    if (trackFeedback) {
      if (!medicationId) return;
      const now = new Date();
      const pad = (n) => String(n).padStart(2, '0');
      const scheduledDate = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
      const scheduledTime = `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
      closeMedPlanModal();
      openDoseFeedbackModal(medicationId, scheduledDate, scheduledTime, true, false);
      return;
    }

    if (btn) btn.disabled = true;
    try {
      const params = new URLSearchParams(new FormData(form));
      params.set('json_response', '1');
      const res = await fetch('index.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString(),
      });
      const json = await res.json();
      if (!json.ok) throw new Error('Failed to log dose.');
      if (btn) {
        const orig = btn.textContent;
        btn.textContent = 'Logged ✓';
        setTimeout(() => { btn.textContent = orig; btn.disabled = false; }, 2000);
      }
    } catch (err) {
      alert(err.message ?? 'Something went wrong.');
      if (btn) btn.disabled = false;
    }
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

openMedPlanModalBtns.forEach((btn) => btn.addEventListener('click', openMedPlanModal));
closeMedPlanModalBtn?.addEventListener('click', closeMedPlanModal);

medPlanModal?.addEventListener('click', (event) => {
  if (event.target === medPlanModal) closeMedPlanModal();
});

// ── Med plan modal: deactivate / activate AJAX ────────────────────────────────

medPlanModal?.addEventListener('submit', async (e) => {
  const form = e.target.closest('form');
  if (!form) return;
  const action = form.querySelector('input[name="action"]')?.value;

  // ── Deactivate ──────────────────────────────────────────────────────────────
  if (action === 'deactivate_medication') {
    if (e.defaultPrevented) return; // cancelled confirm
    e.preventDefault();
    const medId = form.querySelector('input[name="medication_id"]')?.value ?? '';
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    try {
      const params = new URLSearchParams();
      params.set('action', 'deactivate_medication');
      params.set('csrf_token', getCsrfToken());
      params.set('json_response', '1');
      params.set('medication_id', medId);

      const res = await fetch('index.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString(),
      });
      const json = await res.json();
      if (!json.ok) throw new Error('Failed to deactivate medication.');

      const activeCard = form.closest('.medication-row-plan');
      const name = activeCard?.querySelector('.medication-content strong')?.textContent ?? '';
      const dose = activeCard?.querySelector('.medication-content p')?.textContent ?? '';
      activeCard?.remove();

      // Update active tab count
      const activeTab = document.querySelector('[data-plan-tab="active"]');
      if (activeTab) {
        const n = document.querySelectorAll('#active-medications-panel .medication-row-plan').length;
        activeTab.textContent = `Active (${n})`;
      }

      // Add a row to inactive panel
      const inactiveList = document.querySelector('.inactive-list');
      inactiveList?.querySelector('.empty-state')?.remove();
      if (inactiveList) {
        const row = document.createElement('div');
        row.className = 'medication-row';
        row.dataset.inactiveMedId = medId;
        row.innerHTML = `<div><strong>${escHtml(name)}</strong><p>${escHtml(dose)}</p></div>
          <div class="row-actions">
            <form method="post" action="index.php" data-activate-form>
              <input type="hidden" name="csrf_token" value="${escHtml(getCsrfToken())}">
              <input type="hidden" name="json_response" value="1">
              <input type="hidden" name="action" value="activate_medication">
              <input type="hidden" name="medication_id" value="${escHtml(medId)}">
              <button type="submit">Activate</button>
            </form>
          </div>`;
        inactiveList.appendChild(row);
      }

      const inactiveTab = document.querySelector('[data-plan-tab="inactive"]');
      if (inactiveTab) {
        const n = document.querySelectorAll('#inactive-medications-panel .medication-row').length;
        inactiveTab.textContent = `Inactive (${n})`;
      }
    } catch (err) {
      alert(err.message ?? 'Something went wrong.');
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

      const inactiveTab = document.querySelector('[data-plan-tab="inactive"]');
      if (inactiveTab) {
        const n = document.querySelectorAll('#inactive-medications-panel .medication-row').length;
        inactiveTab.textContent = `Inactive (${n})`;
        if (n === 0) {
          document.querySelector('.inactive-list')?.insertAdjacentHTML('afterbegin', '<div class="empty-state"><p>No inactive medications.</p></div>');
        }
      }

      // Reload to show the full active card, and reopen the modal on the active tab
      sessionStorage.setItem('medPlanReopen', 'active');
      window.location.reload();
    } catch (err) {
      alert(err.message ?? 'Something went wrong.');
      if (submitBtn) submitBtn.disabled = false;
    }
  }
});


document.addEventListener('keydown', (event) => {
  if (event.key !== 'Escape') return;
  // Priority cascade: lightbox > refill history > refill modal > detail modal > plan modal
  if (pillLightbox?.classList.contains('is-open')) {
    closePillLightbox();
  } else if (refillHistoryModal?.classList.contains('is-open')) {
    closeRefillHistoryModal();
  } else if (refillModal?.classList.contains('is-open')) {
    closeRefillModal();
  } else if (medDetailModal?.classList.contains('is-open')) {
    closeMedDetailModal();
  } else if (medPlanModal && !medPlanModal.hidden) {
    closeMedPlanModal();
  }
});

const planTabs = document.querySelectorAll('[data-plan-tab]');
const planPanels = {
  active: document.querySelector('#active-medications-panel'),
  inactive: document.querySelector('#inactive-medications-panel'),
  groups: document.querySelector('#groups-panel'),
};

const medPlanHeaderActions = document.querySelectorAll('[data-med-plan-action]');

const setPlanTab = (target) => {
  planTabs.forEach((tab) => {
    const isSelected = tab.getAttribute('data-plan-tab') === target;
    tab.classList.toggle('is-active', isSelected);
    tab.setAttribute('aria-selected', isSelected ? 'true' : 'false');
  });
  Object.entries(planPanels).forEach(([key, panel]) => {
    if (!panel) return;
    panel.hidden = key !== target;
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

let painGraphMedId = null;
let painGraphDays = 7;

const openPainGraphModal = (medicationId, medicationName) => {
  if (!painGraphModal) return;
  painGraphMedId = medicationId;
  painGraphDays = 7;
  if (painGraphTitle) painGraphTitle.textContent = medicationName + ' — Pain Trend';
  painGraphModal.querySelectorAll('.range-tab').forEach((t) => {
    t.classList.toggle('is-active', t.dataset.range === '7');
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

const renderPainChart = (container, data) => {
  const W = 500, H = 200;
  const ml = 32, mr = 12, mt = 12, mb = 36;
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

  // Circles with tooltip
  let circles = '';
  byDate.forEach(({ date, level, pts }, i) => {
    const x = xScale(i).toFixed(1);
    const y = yScale(level).toFixed(1);
    const color = painLevelColor(Math.round(level));
    const tipLines = pts.map((p) => `${escSvg(p.time.slice(0, 5))}: Pain ${escSvg(p.pain_level)}/10${p.note ? ' — ' + escSvg(p.note) : ''}`).join('&#10;');
    circles += `<circle cx="${x}" cy="${y}" r="5" fill="${color}" stroke="#fff" stroke-width="1.5"><title>${escSvg(date)}&#10;${tipLines}</title></circle>`;
  });

  container.innerHTML = `<svg viewBox="0 0 ${W} ${H}" width="100%" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Pain level trend chart">
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
  const ml = 32, mr = 12, mt = 12, mb = 36;
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
    const tip = `${escSvg(d.time.slice(0, 5))}: Pain ${escSvg(d.pain_level)}/10${d.note ? ' — ' + escSvg(d.note) : ''}`;
    circles += `<circle cx="${x}" cy="${y}" r="5" fill="${color}" stroke="#fff" stroke-width="1.5"><title>${tip}</title></circle>`;
  });

  container.innerHTML = `<svg viewBox="0 0 ${W} ${H}" width="100%" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Pain level trend chart">
    ${gridLines}
    <line x1="${ml}" y1="${mt}" x2="${ml}" y2="${H - mb}" stroke="#cbd5e1" stroke-width="1"/>
    <line x1="${ml}" y1="${H - mb}" x2="${W - mr}" y2="${H - mb}" stroke="#cbd5e1" stroke-width="1"/>
    ${xLabels}
    <polyline points="${points}" fill="none" stroke="#6b7a96" stroke-width="2" stroke-linejoin="round"/>
    ${circles}
  </svg>`;
};

const loadPainGraph = async () => {
  if (!painGraphBody || !painGraphEmpty) return;
  painGraphBody.innerHTML = '<p class="pain-graph-loading">Loading…</p>';
  painGraphEmpty.hidden = true;
  try {
    const resp = await window.fetch(`index.php?action=pain_trend&medication_id=${encodeURIComponent(painGraphMedId ?? '')}&days=${painGraphDays}`);
    const payload = await resp.json();
    if (!payload.ok || payload.data.length === 0) {
      painGraphBody.innerHTML = '';
      painGraphEmpty.hidden = false;
      return;
    }
    if (painGraphDays === 0) {
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
    loadPainGraph();
  });
});

document.querySelectorAll('[data-open-pain-graph]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const medId = btn.dataset.medicationId ?? '';
    const medName = btn.dataset.medicationName ?? '';
    if (!medId) return;
    openPainGraphModal(medId, medName);
  });
});

document.querySelector('[data-close-pain-graph]')?.addEventListener('click', closePainGraphModal);

painGraphModal?.addEventListener('click', (event) => {
  if (event.target === painGraphModal) closePainGraphModal();
});


const historyPanel = document.querySelector('[data-history-panel]');
const historyList = document.querySelector('[data-history-list]');
const historyToggle = document.querySelector('[data-history-toggle]');

historyToggle?.addEventListener('click', () => {
  if (!historyPanel || !historyList) return;
  const expanded = historyPanel.classList.toggle('is-expanded');
  historyToggle.textContent = expanded ? 'View less' : 'View more';
});

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

let alarmGroupItems = [];

let alarmAudioCtx = null;
let alarmBeepTimer = null;
let alarmVibrateTimer = null;
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

const isAlarmEnabled = () =>
  window.localStorage.getItem('rxtracker_alarm_enabled') === '1';

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
  if (alarmAudioCtx) {
    if (alarmAudioCtx.state === 'suspended') {
      alarmAudioCtx.resume().then(playAlarmPattern).catch(() => {});
    }
    return;
  }
  try {
    alarmAudioCtx = new AudioContext();
    if (alarmAudioCtx.state === 'suspended') {
      alarmAudioCtx.resume().then(playAlarmPattern).catch(() => {});
    } else {
      playAlarmPattern();
    }
  } catch {
    // AudioContext unavailable
  }
};

const stopAlarmAudio = () => {
  if (alarmBeepTimer) {
    clearTimeout(alarmBeepTimer);
    alarmBeepTimer = null;
  }
  if (alarmAudioCtx) {
    alarmAudioCtx.close().catch(() => {});
    alarmAudioCtx = null;
  }
};

const VIBRATE_PATTERN = [400, 200, 400, 200, 400];

const startAlarmVibration = () => {
  if (!('vibrate' in navigator)) return;
  navigator.vibrate(VIBRATE_PATTERN);
  alarmVibrateTimer = window.setInterval(() => {
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
  if (alarmEyebrowEl) alarmEyebrowEl.textContent = 'Dose Due Now';
  if (alarmTakeBtn) alarmTakeBtn.textContent = 'Take Now';
  if (alarmSkipBtn) alarmSkipBtn.textContent = 'Skip';
  if (alarmMedNameEl) alarmMedNameEl.textContent = item.name;
  if (alarmMedDoseEl) alarmMedDoseEl.textContent = item.dose;
  alarmOverlay.dataset.alarmMedicationId = item.medication_id;
  alarmOverlay.dataset.alarmScheduledDate = item.scheduled_date;
  alarmOverlay.dataset.alarmScheduledTime = item.scheduled_time;
  alarmOverlay.dataset.alarmTrackDoseFeedback = item.track_dose_feedback ? '1' : '0';
  alarmOverlay.classList.add('is-active');
  lockBodyScroll();
  if (isAlarmEnabled()) startAlarmAudio();
  startAlarmVibration();
};

const showGroupAlarmOverlay = (groupItems) => {
  if (!alarmOverlay || groupItems.length === 0) return;
  alarmGroupItems = groupItems;
  if (alarmSingleModeEl) alarmSingleModeEl.hidden = true;
  if (alarmGroupModeEl) alarmGroupModeEl.hidden = false;
  if (alarmEyebrowEl) alarmEyebrowEl.textContent = 'Group Dose Due Now';
  if (alarmTakeBtn) alarmTakeBtn.textContent = 'Take All';
  if (alarmSkipBtn) alarmSkipBtn.textContent = 'Skip All';
  if (alarmGroupNameEl) alarmGroupNameEl.textContent = groupItems[0].group_name ?? 'Medication Group';
  if (alarmGroupListEl) {
    alarmGroupListEl.innerHTML = '';
    groupItems.forEach((item) => {
      const li = document.createElement('li');
      li.className = 'alarm-group-list-item';
      li.textContent = `${item.name} — ${item.dose}`;
      alarmGroupListEl.appendChild(li);
    });
  }
  alarmOverlay.classList.add('is-active');
  lockBodyScroll();
  if (isAlarmEnabled()) startAlarmAudio();
  startAlarmVibration();
};

const hideAlarmOverlay = () => {
  if (!alarmOverlay) return;
  if (!alarmOverlay.classList.contains('is-active')) return;
  alarmOverlay.classList.remove('is-active');
  alarmGroupItems = [];
  unlockBodyScroll();
  stopAlarmAudio();
  stopAlarmVibration();
};

const isMedicationModalOpen = () => medicationModal?.classList.contains('is-open') ?? false;

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
      if (!isMedicationModalOpen()) window.location.reload();
      return;
    }
  } catch {
    // fall through
  }
  hideAlarmOverlay();
  if (!isMedicationModalOpen()) window.location.reload();
};

// ── Sequential feedback queue ─────────────────────────────────────────────────

let feedbackQueue = [];
let feedbackQueueMode = false;
const feedbackQueueProgressEl = document.querySelector('[data-feedback-queue-progress]');

const postDose = async (medicationId, scheduledDate, scheduledTime, status, note, painLevel = '') => {
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
    if (!isMedicationModalOpen()) window.location.reload();
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
  openDoseFeedbackModal(item.medication_id, item.scheduled_date, item.scheduled_time, true, false);
};

const submitCurrentQueueItem = async (note, painLevel) => {
  const item = feedbackQueue.shift();
  if (!item) return;
  await postDose(item.medication_id, item.scheduled_date, item.scheduled_time, 'taken', note, painLevel);
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

    for (const item of nonFeedback) {
      await postDose(item.medication_id, item.scheduled_date, item.scheduled_time, 'taken', '');
    }

    if (withFeedback.length === 0) {
      window.location.reload();
      return;
    }

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
      openDoseFeedbackModal(medicationId, scheduledDate, scheduledTime, true, true);
    } else {
      alarmAction('mark_dose', { status: 'taken', note: '' });
    }
  }
});

alarmSkipBtn?.addEventListener('click', async () => {
  if (alarmGroupItems.length > 0) {
    const items = [...alarmGroupItems];
    hideAlarmOverlay();
    for (const item of items) {
      await postDose(item.medication_id, item.scheduled_date, item.scheduled_time, 'skipped', 'Skipped dose');
    }
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

// ── Reminders & polling ───────────────────────────────────────────────────────

const enableRemindersButton = document.querySelector('[data-enable-reminders]');
const alarmSoundToggle = document.querySelector('[data-alarm-sound-toggle]');
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

const initAlarmSoundToggle = () => {
  // Default to enabled so new users get in-app alarms without touching settings
  if (window.localStorage.getItem('rxtracker_alarm_enabled') === null) {
    window.localStorage.setItem('rxtracker_alarm_enabled', '1');
  }
  if (alarmSoundToggle) {
    alarmSoundToggle.checked = isAlarmEnabled();
  }
};

alarmSoundToggle?.addEventListener('change', () => {
  if (alarmSoundToggle.checked) {
    window.localStorage.setItem('rxtracker_alarm_enabled', '1');
  } else {
    window.localStorage.removeItem('rxtracker_alarm_enabled');
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

initAlarmSoundToggle();
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
  const doseTimesInput = medicationForm.querySelector('input[name="dose_times"]');
  const intervalHoursInput = medicationForm.querySelector('input[name="interval_hours"]');
  const firstDoseInput = medicationForm.querySelector('input[name="first_dose_time"]');
  const doseTimesLabel = doseTimesInput?.closest('label');
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

  const applyScheduleVisibility = () => {
    const intervalMode = scheduleMode?.value === 'interval';
    if (doseTimesLabel) doseTimesLabel.style.display = intervalMode ? 'none' : '';
    if (intervalLabel) intervalLabel.style.display = intervalMode ? '' : 'none';
    if (firstDoseLabel) firstDoseLabel.style.display = intervalMode ? '' : 'none';
    if (doseTimesInput) doseTimesInput.required = !intervalMode;
    if (intervalHoursInput) intervalHoursInput.required = intervalMode;
    if (firstDoseInput) firstDoseInput.required = intervalMode;
  };

  scheduleMode?.addEventListener('change', applyScheduleVisibility);

  applyScheduleVisibility();

  medicationForm.addEventListener('submit', (event) => {
    const intervalMode = scheduleMode?.value === 'interval';

    if (!intervalMode && doseTimesInput && doseTimesInput.value.trim() !== '') {
      const normalized = normalizeCommaTimes(doseTimesInput.value);
      if (!normalized) {
        event.preventDefault();
        window.alert('Invalid dose times. Use h:MM AM/PM format (e.g. 8:00 AM, 2:30 PM).');
        return;
      }
      doseTimesInput.value = normalized;
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

// ── Drug name autocomplete ────────────────────────────────────────────────────

const medNameInput = document.querySelector('[data-med-name-input]');
const autocompleteDropdown = document.querySelector('[data-autocomplete-dropdown]');
const setIdInput = document.querySelector('[data-set-id-input]');
let autocompleteTimer = null;

const hideDrugDropdown = () => {
  if (autocompleteDropdown) autocompleteDropdown.hidden = true;
};

const showDrugDropdown = (names) => {
  if (!autocompleteDropdown) return;
  autocompleteDropdown.innerHTML = '';
  if (!names.length) { hideDrugDropdown(); return; }
  names.forEach((name) => {
    const li = document.createElement('li');
    li.className = 'autocomplete-item';
    li.textContent = name;
    li.addEventListener('mousedown', (e) => {
      e.preventDefault();
      if (medNameInput) medNameInput.value = name;
      hideDrugDropdown();
      fetchAndSetSplId(name);
    });
    autocompleteDropdown.appendChild(li);
  });
  autocompleteDropdown.hidden = false;
};

const fetchDrugSuggestions = async (query) => {
  try {
    const res = await fetch(
      apiProxy(`https://dailymed.nlm.nih.gov/dailymed/services/v2/drugnames.json?drug_name=${encodeURIComponent(query)}&pagesize=10`)
    );
    if (!res.ok) return;
    const data = await res.json();
    const names = (data?.data ?? []).map((item) => item?.drug_name ?? '').filter(Boolean);
    showDrugDropdown(names);
  } catch { hideDrugDropdown(); }
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
    setIdInput.value = data?.data?.[0]?.setid ?? '';
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

// ── Pill image loading ────────────────────────────────────────────────────────

const PILL_IMG_CACHE_PREFIX = 'rxtracker_pillimg_v1_';
const PILL_IMG_TTL = 86400000;

const PILL_SVG = `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.5 20H4a2 2 0 0 1-2-2V5c0-1.1.9-2 2-2h3.93a2 2 0 0 1 1.66.9l.82 1.2a2 2 0 0 0 1.66.9H20a2 2 0 0 1 2 2v3"/><circle cx="18" cy="18" r="3"/><path d="m22 22-1.5-1.5"/></svg>`;

// Key by setId when available so a medication edited to a different drug
// immediately resolves its own cache entry rather than reusing the old one.
const pillCacheKey = (medicationId, setId) => setId ? `s_${setId}` : `m_${medicationId}`;

const readPillCache = (medicationId, setId) => {
  const key = PILL_IMG_CACHE_PREFIX + pillCacheKey(medicationId, setId);
  try {
    const raw = localStorage.getItem(key);
    if (!raw) return undefined;
    const { url, ts } = JSON.parse(raw);
    if (Date.now() - ts > PILL_IMG_TTL) { localStorage.removeItem(key); return undefined; }
    return url;
  } catch { return undefined; }
};

const writePillCache = (medicationId, setId, url) => {
  try { localStorage.setItem(PILL_IMG_CACHE_PREFIX + pillCacheKey(medicationId, setId), JSON.stringify({ url, ts: Date.now() })); } catch {}
};

const fetchPillImageUrl = async (setId, medicationName) => {
  if (setId) {
    try {
      const res = await fetch(
        apiProxy(`https://dailymed.nlm.nih.gov/dailymed/services/v2/spls/${encodeURIComponent(setId)}/media.json`)
      );
      if (res.ok) {
        const data = await res.json();
        const item = (data?.data ?? []).find((d) => (d.type ?? '').startsWith('image/'));
        if (item) {
          return item.url
            ?? `https://dailymed.nlm.nih.gov/dailymed/image.cfm?setid=${encodeURIComponent(setId)}&name=${encodeURIComponent(item.name ?? '')}`;
        }
      }
    } catch {}
  }
  return null;
};

const applyPillImage = (wrap, medicationId, setId, url) => {
  wrap.innerHTML = '';
  if (url) {
    const img = document.createElement('img');
    img.src = url;
    img.alt = '';
    img.width = 48;
    img.height = 48;
    img.addEventListener('error', () => {
      writePillCache(medicationId, setId, null);
      applyPillImage(wrap, medicationId, setId, null);
    });
    img.addEventListener('click', () => {
      openPillLightbox(url, wrap.dataset.medicationName ?? '');
    });
    wrap.appendChild(img);
  } else {
    wrap.innerHTML = `<span class="pill-img-placeholder">${PILL_SVG}</span>`;
  }
};

const loadPillImages = () => {
  document.querySelectorAll('[data-pill-img-wrap]').forEach(async (wrap) => {
    const { medicationId, setId = '', medicationName = '' } = wrap.dataset;
    if (!medicationId) return;
    wrap.innerHTML = `<span class="pill-img-placeholder">${PILL_SVG}</span>`;
    const cached = readPillCache(medicationId, setId);
    if (cached !== undefined) { applyPillImage(wrap, medicationId, setId, cached); return; }
    try {
      const url = await fetchPillImageUrl(setId, medicationName);
      writePillCache(medicationId, setId, url);
      applyPillImage(wrap, medicationId, setId, url);
    } catch {
      writePillCache(medicationId, setId, null);
    }
  });
};

loadPillImages();

// ── Pill image lightbox ───────────────────────────────────────────────────────

const pillLightbox = document.querySelector('[data-pill-lightbox]');
const pillLightboxImg = document.querySelector('[data-lightbox-img]');
const pillLightboxCaption = document.querySelector('[data-lightbox-caption]');

const openPillLightbox = (imageUrl, medicationName) => {
  if (!pillLightbox || !pillLightboxImg) return;
  pillLightboxImg.src = imageUrl;
  pillLightboxImg.alt = medicationName;
  if (pillLightboxCaption) pillLightboxCaption.textContent = medicationName;
  pillLightbox.classList.add('is-open');
  lockBodyScroll();
};

const closePillLightbox = () => {
  if (!pillLightbox?.classList.contains('is-open')) return;
  pillLightbox.classList.remove('is-open');
  unlockBodyScroll();
};

document.querySelector('[data-close-lightbox]')?.addEventListener('click', closePillLightbox);
pillLightbox?.addEventListener('click', (e) => { if (e.target === pillLightbox) closePillLightbox(); });

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

const renderDetailContent = (name, setId, ofda) => {
  const sections = [];
  const boxedWarning = getOfdaField(ofda, 'boxed_warning');
  const indications  = getOfdaField(ofda, 'indications_and_usage');
  const activeIng    = getOfdaField(ofda, 'active_ingredient');
  const inactiveIng  = getOfdaField(ofda, 'inactive_ingredient');
  const adverseRx    = getOfdaField(ofda, 'adverse_reactions');
  const warnCautions = getOfdaField(ofda, 'warnings_and_cautions');
  const dosageAdmin  = getOfdaField(ofda, 'dosage_and_administration');
  const warnings     = getOfdaField(ofda, 'warnings');
  const contraind    = getOfdaField(ofda, 'contraindications');

  if (indications) {
    sections.push(`<div class="med-detail-section"><h3>What it&rsquo;s used for</h3>${textBlock(indications)}</div>`);
  }

  if (activeIng || inactiveIng) {
    let ing = '';
    if (activeIng) ing += `<p><strong>Active:</strong></p>${textBlock(activeIng)}`;
    if (inactiveIng) ing += `<p><strong>Inactive:</strong></p>${textBlock(inactiveIng)}`;
    sections.push(`<div class="med-detail-section"><h3>Ingredients</h3>${ing}</div>`);
  }

  const sideEffects = adverseRx || warnCautions;
  if (sideEffects) {
    sections.push(`<div class="med-detail-section"><h3>Side Effects</h3>${textBlock(sideEffects)}</div>`);
  }

  if (dosageAdmin) {
    sections.push(`<div class="med-detail-section"><h3>How to Take This Medication</h3>${textBlock(dosageAdmin)}<p class="muted" style="font-size:0.82rem;margin-top:0.35rem">Always follow your prescriber&rsquo;s specific instructions.</p></div>`);
  }

  if (boxedWarning || warnings || contraind) {
    let warnHtml = '';
    if (boxedWarning) warnHtml += `<div class="boxed-warning-banner"><strong>&#9888; Boxed Warning</strong>${textBlock(boxedWarning)}</div>`;
    if (warnings) warnHtml += textBlock(warnings);
    if (contraind) warnHtml += `<p><strong>Contraindications:</strong></p>${textBlock(contraind)}`;
    sections.push(`<div class="med-detail-section"><h3>Warnings</h3>${warnHtml}</div>`);
  }

  const dailyMedLink = setId
    ? `<a href="https://dailymed.nlm.nih.gov/dailymed/lookup.cfm?setid=${encodeURIComponent(setId)}" target="_blank" rel="noopener">View full label on DailyMed &#8599;</a>`
    : '';

  if (!sections.length) {
    return `<p class="muted">Detailed information is not available for this medication.</p>${dailyMedLink ? `<div class="med-detail-links">${dailyMedLink}</div>` : ''}`;
  }

  return sections.join('') + `<div class="med-detail-links"><p class="disclaimer">This information is for general reference only. Always follow your doctor&rsquo;s or pharmacist&rsquo;s instructions.</p>${dailyMedLink}</div>`;
};

document.querySelectorAll('[data-view-details]').forEach((btn) => {
  btn.addEventListener('click', async () => {
    const { medicationName = '', setId = '' } = btn.dataset;
    if (medDetailTitle) medDetailTitle.textContent = medicationName;
    if (medDetailBody) medDetailBody.innerHTML = '<p class="pain-graph-loading">Loading&hellip;</p>';
    openMedDetailModal();

    const cacheKey = setId || `name_${medicationName}`;
    if (!medDetailCache[cacheKey]) {
      const [, ofdaResult] = await Promise.allSettled([
        setId
          ? fetch(apiProxy(`https://dailymed.nlm.nih.gov/dailymed/services/v2/spls/${encodeURIComponent(setId)}.json`)).catch(() => null)
          : Promise.resolve(null),
        fetch(apiProxy(`https://api.fda.gov/drug/label.json?search=openfda.brand_name:"${encodeURIComponent(medicationName)}"&limit=1`))
          .then((r) => (r.ok ? r.json() : null))
          .catch(() => null),
      ]);
      medDetailCache[cacheKey] = { ofda: ofdaResult.status === 'fulfilled' ? ofdaResult.value : null };
    }

    if (medDetailBody) {
      try {
        medDetailBody.innerHTML = renderDetailContent(medicationName, setId, medDetailCache[cacheKey].ofda);
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
  groupFormWrap.hidden = false;
  groupFormName?.focus();
};

const closeGroupForm = () => {
  if (groupFormWrap) groupFormWrap.hidden = true;
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

      const groupsTab = document.querySelector('[data-plan-tab="groups"]');
      if (groupsTab) {
        const n = document.querySelectorAll('.group-card').length;
        groupsTab.textContent = `Groups (${n})`;
      }
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

document.querySelectorAll('[data-edit-group]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const { groupId = '', groupName = '', groupTime = '' } = btn.dataset;
    openGroupForm('edit', groupId, groupName, groupTime);
    btn.closest('.group-card')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  });
});

const escHtml = (str) =>
  String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

const buildMedOptions = (ungrouped) =>
  ungrouped.map((m) =>
    `<option value="${escHtml(String(m.id))}" data-name="${escHtml(m.name)}" data-dose="${escHtml(m.dose)}">${escHtml(m.name)} &mdash; ${escHtml(m.dose)}</option>`
  ).join('');

const buildGroupCard = (groupId, groupName, groupTimeDisplay, ungrouped) => {
  const card = document.createElement('div');
  card.className = 'group-card';
  card.dataset.groupCardId = String(groupId);

  const addMedFormHtml = ungrouped.length > 0
    ? `<form class="group-add-med-form" method="post" action="index.php" data-ajax-add>
        <input type="hidden" name="action" value="add_medication_to_group">
        <input type="hidden" name="group_id" value="${groupId}">
        <select name="medication_id" class="group-add-select">
          <option value="">Add a medication&hellip;</option>
          ${buildMedOptions(ungrouped)}
        </select>
        <button type="submit" class="secondary group-add-btn">Add</button>
      </form>`
    : '';

  card.innerHTML = `
    <div class="group-card-header">
      <div class="group-card-title">
        <strong data-group-card-name>${escHtml(groupName)}</strong>
        <span class="group-time-badge" data-group-card-time>${escHtml(groupTimeDisplay)}</span>
        <span class="count-badge" data-group-card-count>0 meds</span>
      </div>
      <div class="row-actions">
        <button type="button" class="secondary" data-edit-group
          data-group-id="${groupId}"
          data-group-name="${escHtml(groupName)}"
          data-group-time="${escHtml(groupTimeDisplay)}">Edit</button>
        <form method="post" action="index.php" data-confirm="Delete this group? Medications will become individual.">
          <input type="hidden" name="csrf_token" value="${escHtml(getCsrfToken())}">
          <input type="hidden" name="action" value="delete_group">
          <input type="hidden" name="group_id" value="${groupId}">
          <button type="submit" class="secondary">Delete</button>
        </form>
      </div>
    </div>
    <div class="group-members-list" data-group-members>
      <p class="group-empty-hint">No medications in this group yet.</p>
    </div>
    ${addMedFormHtml}`;

  card.querySelector('[data-confirm]')?.addEventListener('submit', (e) => {
    if (!window.confirm(e.currentTarget.getAttribute('data-confirm') ?? '')) e.preventDefault();
  });

  card.querySelector('[data-edit-group]')?.addEventListener('click', () => {
    openGroupForm('edit', String(groupId), groupName, groupTimeDisplay);
    card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  });

  return card;
};

// ── Group panel delegated AJAX (add-med + delete) ─────────────────────────────

planPanels.groups?.addEventListener('submit', async (e) => {
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

    const submitBtn = addForm.querySelector('.group-add-btn');
    if (submitBtn) submitBtn.disabled = true;

    try {
      const params = new URLSearchParams();
      params.set('action', 'add_medication_to_group');
      params.set('csrf_token', getCsrfToken());
      params.set('json_response', '1');
      params.set('group_id', groupId);
      params.set('medication_id', medId);

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

      if (json.ungrouped.length === 0) {
        addForm.remove();
      } else {
        select.innerHTML = `<option value="">Add a medication&hellip;</option>${buildMedOptions(json.ungrouped)}`;
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
        existingAddForm.hidden = false;
      } else if (!existingAddForm && json.ungrouped.length > 0 && card) {
        const groupId = card.dataset.groupCardId ?? '';
        const newForm = document.createElement('form');
        newForm.className = 'group-add-med-form';
        newForm.setAttribute('method', 'post');
        newForm.setAttribute('action', 'index.php');
        newForm.dataset.ajaxAdd = '';
        newForm.innerHTML = `<input type="hidden" name="action" value="add_medication_to_group">
          <input type="hidden" name="group_id" value="${escHtml(groupId)}">
          <select name="medication_id" class="group-add-select">
            <option value="">Add a medication…</option>${buildMedOptions(json.ungrouped)}
          </select>
          <button type="submit" class="secondary group-add-btn">Add</button>`;
        card.appendChild(newForm);
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

    const groupsTab = document.querySelector('[data-plan-tab="groups"]');
    if (groupsTab) {
      const n = document.querySelectorAll('.group-card').length;
      groupsTab.textContent = `Groups (${n})`;
    }

    if (document.querySelectorAll('.group-card').length === 0) {
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
const refillMedicationIdEl = document.querySelector('[data-refill-medication-id]');
const refillDateInput = document.querySelector('[data-refill-date]');
const refillForm = document.querySelector('[data-refill-form]');

const openRefillModal = (medicationId, medicationName) => {
  if (!refillModal) return;
  if (refillForm) refillForm.reset();
  if (refillMedNameEl) refillMedNameEl.textContent = medicationName;
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
    const { medicationId = '', medicationName = '' } = btn.dataset;
    if (!medicationId) return;
    openRefillModal(medicationId, medicationName);
  });
});

document.querySelectorAll('[data-close-refill-modal]').forEach((btn) => {
  btn.addEventListener('click', closeRefillModal);
});

refillModal?.addEventListener('click', (event) => {
  if (event.target === refillModal) closeRefillModal();
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
        listHtml += `<li class="refill-history-entry">
          <span class="refill-entry-date">${escHtml(dateStr)}</span>
          <div class="refill-entry-meta">
            <span class="refill-entry-amount">+${escHtml(String(r.amount))} pills</span>
            <span class="refill-entry-hand">${escHtml(String(r.pills_on_hand))} on hand after</span>
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

initPushStatusPanel();
