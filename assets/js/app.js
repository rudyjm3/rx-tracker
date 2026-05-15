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

const medicationModal = document.querySelector('[data-medication-modal]');
const openMedicationModalButton = document.querySelector('[data-open-medication-modal]');
const closeMedicationModalButton = document.querySelector('[data-close-medication-modal]');
const postponeModal = document.querySelector('[data-postpone-modal]');
const closePostponeModalButton = document.querySelector('[data-close-postpone-modal]');
const postponeMedicationId = document.querySelector('[data-postpone-medication-id]');
const postponeScheduledDate = document.querySelector('[data-postpone-scheduled-date]');
const postponeScheduledTime = document.querySelector('[data-postpone-scheduled-time]');

const openMedicationModal = () => {
  if (!medicationModal) return;
  medicationModal.classList.add('is-open');
  document.body.style.overflow = 'hidden';
};

const closeMedicationModal = () => {
  if (!medicationModal) return;
  medicationModal.classList.remove('is-open');
  document.body.style.overflow = '';
  if (window.location.search.includes('edit=')) {
    window.history.replaceState({}, '', 'index.php');
  }
};

openMedicationModalButton?.addEventListener('click', openMedicationModal);
closeMedicationModalButton?.addEventListener('click', closeMedicationModal);

const openPostponeModal = (medicationId, scheduledDate, scheduledTime) => {
  if (!postponeModal) return;
  if (postponeMedicationId) postponeMedicationId.value = medicationId;
  if (postponeScheduledDate) postponeScheduledDate.value = scheduledDate;
  if (postponeScheduledTime) postponeScheduledTime.value = scheduledTime;
  postponeModal.classList.add('is-open');
  document.body.style.overflow = 'hidden';
};

const closePostponeModal = () => {
  if (!postponeModal) return;
  postponeModal.classList.remove('is-open');
  document.body.style.overflow = medicationModal?.classList.contains('is-open') ? 'hidden' : '';
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

const medicationPlanPanel = document.querySelector('[data-medication-plan]');
const medicationPlanToggle = document.querySelector('[data-medication-plan-toggle]');
const medicationPlanBody = document.querySelector('#medication-plan-body');

const setMedicationPlanState = (isExpanded) => {
  if (!medicationPlanPanel || !medicationPlanToggle || !medicationPlanBody) return;
  medicationPlanPanel.classList.toggle('is-collapsed', !isExpanded);
  medicationPlanBody.hidden = !isExpanded;
  medicationPlanToggle.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
  medicationPlanToggle.textContent = isExpanded ? 'Collapse' : 'Expand';
};

medicationPlanToggle?.addEventListener('click', () => {
  const isExpanded = medicationPlanToggle.getAttribute('aria-expanded') === 'true';
  setMedicationPlanState(!isExpanded);
});

setMedicationPlanState(false);

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
const ALARM_RENOTIFY_MS = 5 * 60 * 1000;

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
  if (alarmAudioCtx) return;
  try {
    alarmAudioCtx = new AudioContext();
    playAlarmPattern();
  } catch {
    // AudioContext unavailable (e.g. desktop without audio)
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
  alarmOverlay.classList.add('is-active');
  document.body.style.overflow = 'hidden';
  if (isAlarmEnabled()) startAlarmAudio();
};

const hideAlarmOverlay = () => {
  if (!alarmOverlay) return;
  alarmOverlay.classList.remove('is-active');
  document.body.style.overflow = '';
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
  alarmAction('mark_dose', { status: 'taken', note: '' });
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

  const unnotified = items.filter((item) => {
    const key = `${item.medication_id}|${item.scheduled_date}|${item.scheduled_time}`;
    const lastSeen = seenMap[key];
    if (!lastSeen) return true;
    return now - new Date(lastSeen).getTime() > ALARM_RENOTIFY_MS;
  });

  if (unnotified.length === 0) {
    showFallbackAlert([]);
    return;
  }

  if ('Notification' in window && Notification.permission === 'granted') {
    unnotified.forEach((item) => {
      const dueText = item.postponed_until ? 'Snoozed dose due now' : 'Dose due now';
      const title = `${item.name} (${item.dose})`;
      if (swRegistration) {
        swRegistration.showNotification(title, { body: dueText });
      } else {
        new Notification(title, { body: dueText });
      }
      const key = `${item.medication_id}|${item.scheduled_date}|${item.scheduled_time}`;
      seenMap[key] = nowIso;
    });
    writeSeenMap(seenMap);
    showFallbackAlert([]);
  }

  if (!alarmOverlay?.classList.contains('is-active')) {
    showAlarmOverlay(unnotified[0]);
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

if (medicationModal?.classList.contains('is-open')) {
  document.body.style.overflow = 'hidden';
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
