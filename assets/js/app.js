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

const closeDoseFeedbackModal = () => {
  if (!doseFeedbackModal) return;
  if (!doseFeedbackModal.classList.contains('is-open')) return;
  doseFeedbackModal.classList.remove('is-open');
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
  if (feedbackAlarmContext) {
    event.preventDefault();
    await submitFeedbackAsAlarmAction();
  }
});

skipFeedbackBtn?.addEventListener('click', async () => {
  if (feedbackAlarmContext) {
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

closeFeedbackModalButton?.addEventListener('click', closeDoseFeedbackModal);

doseFeedbackModal?.addEventListener('click', (event) => {
  if (event.target === doseFeedbackModal) {
    closeDoseFeedbackModal();
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

document.querySelectorAll('[data-log-dose-now]').forEach((btn) => {
  btn.addEventListener('click', (event) => {
    if (btn.dataset.trackDoseFeedback === '1') {
      event.preventDefault();
      const medicationId = btn.dataset.medicationId ?? '';
      if (!medicationId) return;
      const now = new Date();
      const pad = (n) => String(n).padStart(2, '0');
      const scheduledDate = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
      const scheduledTime = `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
      closeMedPlanModal();
      openDoseFeedbackModal(medicationId, scheduledDate, scheduledTime, true, false);
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

document.addEventListener('keydown', (event) => {
  if (event.key !== 'Escape') return;
  // Priority cascade: lightbox > detail modal > plan modal
  if (pillLightbox?.classList.contains('is-open')) {
    closePillLightbox();
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
};

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
};

planTabs.forEach((tab) => {
  tab.addEventListener('click', () => {
    const target = tab.getAttribute('data-plan-tab');
    if (!target) return;
    setPlanTab(target);
  });
});

setPlanTab('active');

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
const alarmSnoozeMinutesEl = document.querySelector('[data-alarm-snooze-minutes]');
const alarmTakeBtn = document.querySelector('[data-alarm-take]');
const alarmSkipBtn = document.querySelector('[data-alarm-skip]');
const alarmSnoozeBtn = document.querySelector('[data-alarm-snooze]');

let alarmAudioCtx = null;
let alarmBeepTimer = null;
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

const showAlarmOverlay = (item) => {
  if (!alarmOverlay) return;
  if (alarmMedNameEl) alarmMedNameEl.textContent = item.name;
  if (alarmMedDoseEl) alarmMedDoseEl.textContent = item.dose;
  alarmOverlay.dataset.alarmMedicationId = item.medication_id;
  alarmOverlay.dataset.alarmScheduledDate = item.scheduled_date;
  alarmOverlay.dataset.alarmScheduledTime = item.scheduled_time;
  alarmOverlay.dataset.alarmTrackDoseFeedback = item.track_dose_feedback ? '1' : '0';
  alarmOverlay.classList.add('is-active');
  lockBodyScroll();
  if (isAlarmEnabled()) startAlarmAudio();
};

const hideAlarmOverlay = () => {
  if (!alarmOverlay) return;
  if (!alarmOverlay.classList.contains('is-active')) return;
  alarmOverlay.classList.remove('is-active');
  unlockBodyScroll();
  stopAlarmAudio();
};

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
      window.location.reload();
      return;
    }
  } catch {
    // fall through
  }
  hideAlarmOverlay();
  window.location.reload();
};

alarmTakeBtn?.addEventListener('click', () => {
  const trackFeedback = alarmOverlay?.dataset.alarmTrackDoseFeedback === '1';
  if (trackFeedback) {
    const medicationId = alarmOverlay?.dataset.alarmMedicationId ?? '';
    const scheduledDate = alarmOverlay?.dataset.alarmScheduledDate ?? '';
    const scheduledTime = alarmOverlay?.dataset.alarmScheduledTime ?? '';
    stopAlarmAudio();
    openDoseFeedbackModal(medicationId, scheduledDate, scheduledTime, true, true);
  } else {
    alarmAction('mark_dose', { status: 'taken', note: '' });
  }
});

alarmSkipBtn?.addEventListener('click', () => {
  alarmAction('mark_dose', { status: 'skipped', note: 'Skipped dose' });
});

alarmSnoozeBtn?.addEventListener('click', () => {
  const minutes = alarmSnoozeMinutesEl?.value ?? '5';
  alarmAction('postpone_dose', { postpone_minutes: minutes });
});

// ── Reminders & polling ───────────────────────────────────────────────────────

const enableRemindersButton = document.querySelector('[data-enable-reminders]');
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
  inAppAlert.textContent = `Dose reminder: ${top.name} ${top.dose} is due now.`;
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
    unseen.forEach((item) => {
      const dueText = item.postponed_until ? 'Snoozed dose due now' : 'Dose due now';
      const title = `${item.name} (${item.dose})`;
      if (swRegistration) {
        swRegistration.showNotification(title, { body: dueText });
      } else {
        new Notification(title, { body: dueText });
      }
    });
    showFallbackAlert([]);
  } else {
    showFallbackAlert(unseen);
  }

  if (!alarmOverlay?.classList.contains('is-active')) {
    showAlarmOverlay(unseen[0]);
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
      window.localStorage.removeItem('rxtracker_alarm_enabled');
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
      window.localStorage.setItem('rxtracker_alarm_enabled', '1');
      pollDueReminders();
      return;
    }
    const permission = Notification.permission === 'default'
      ? await Notification.requestPermission()
      : Notification.permission;
    window.localStorage.setItem('rxtracker_alarm_enabled', '1');
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

const initializeReminderToggle = async () => {
  if (!enableRemindersButton) return;
  try {
    const subscription = await currentPushSubscription();
    setReminderToggleState(Boolean(subscription));
  } catch (error) {
    setReminderToggleState(false);
  }
};

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
      `https://dailymed.nlm.nih.gov/dailymed/services/v2/drugnames.json?drug_name=${encodeURIComponent(query)}&pagesize=10`
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
      `https://dailymed.nlm.nih.gov/dailymed/services/v2/spls.json?drug_name=${encodeURIComponent(name)}&pagesize=1`
    );
    if (!res.ok) return;
    const data = await res.json();
    setIdInput.value = data?.data?.[0]?.setid ?? '';
  } catch {}
};

if (medNameInput) {
  medNameInput.addEventListener('input', () => {
    clearTimeout(autocompleteTimer);
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

const readPillCache = (id) => {
  try {
    const raw = localStorage.getItem(PILL_IMG_CACHE_PREFIX + id);
    if (!raw) return undefined;
    const { url, ts } = JSON.parse(raw);
    if (Date.now() - ts > PILL_IMG_TTL) { localStorage.removeItem(PILL_IMG_CACHE_PREFIX + id); return undefined; }
    return url;
  } catch { return undefined; }
};

const writePillCache = (id, url) => {
  try { localStorage.setItem(PILL_IMG_CACHE_PREFIX + id, JSON.stringify({ url, ts: Date.now() })); } catch {}
};

const fetchPillImageUrl = async (setId, medicationName) => {
  if (setId) {
    try {
      const res = await fetch(
        `https://dailymed.nlm.nih.gov/dailymed/services/v2/spls/${encodeURIComponent(setId)}/media.json`
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
  try {
    await fetch(
      `https://api.fda.gov/drug/label.json?search=openfda.brand_name:"${encodeURIComponent(medicationName)}"&limit=1`
    );
  } catch {}
  return null;
};

const applyPillImage = (wrap, medicationId, url) => {
  wrap.innerHTML = '';
  if (url) {
    const img = document.createElement('img');
    img.src = url;
    img.alt = '';
    img.width = 48;
    img.height = 48;
    img.addEventListener('error', () => {
      writePillCache(medicationId, null);
      applyPillImage(wrap, medicationId, null);
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
    const cached = readPillCache(medicationId);
    if (cached !== undefined) { applyPillImage(wrap, medicationId, cached); return; }
    try {
      const url = await fetchPillImageUrl(setId, medicationName);
      writePillCache(medicationId, url);
      applyPillImage(wrap, medicationId, url);
    } catch {
      writePillCache(medicationId, null);
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
          ? fetch(`https://dailymed.nlm.nih.gov/dailymed/services/v2/spls/${encodeURIComponent(setId)}.json`).catch(() => null)
          : Promise.resolve(null),
        fetch(`https://api.fda.gov/drug/label.json?search=openfda.brand_name:"${encodeURIComponent(medicationName)}"&limit=1`)
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
