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

const enableRemindersButton = document.querySelector('[data-enable-reminders]');
const inAppAlert = document.querySelector('[data-in-app-alert]');

const readSeenMap = () => {
  try {
    return JSON.parse(window.localStorage.getItem('rxtracker_reminder_seen') ?? '{}');
  } catch (error) {
    return {};
  }
};

const writeSeenMap = (map) => {
  window.localStorage.setItem('rxtracker_reminder_seen', JSON.stringify(map));
};

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
  const nowIso = new Date().toISOString();
  const unseen = items.filter((item) => {
    const key = `${item.medication_id}|${item.scheduled_date}|${item.scheduled_time}`;
    return !seenMap[key];
  });

  if (unseen.length === 0) {
    showFallbackAlert([]);
    return;
  }

  if ('Notification' in window && Notification.permission === 'granted') {
    unseen.forEach((item) => {
      const dueText = item.postponed_until
        ? `Postponed dose due now`
        : `Dose due now`;
      new Notification(`${item.name} (${item.dose})`, {
        body: dueText,
      });
      const key = `${item.medication_id}|${item.scheduled_date}|${item.scheduled_time}`;
      seenMap[key] = nowIso;
    });
    writeSeenMap(seenMap);
    showFallbackAlert([]);
    return;
  }

  showFallbackAlert(unseen);
  unseen.forEach((item) => {
    const key = `${item.medication_id}|${item.scheduled_date}|${item.scheduled_time}`;
    seenMap[key] = nowIso;
  });
  writeSeenMap(seenMap);
};

const pollDueReminders = async () => {
  try {
    const response = await window.fetch('index.php?action=poll_due', { credentials: 'same-origin' });
    if (!response.ok) return;
    const payload = await response.json();
    if (!payload || payload.ok !== true || !Array.isArray(payload.items)) return;
    notifyItems(payload.items);
  } catch (error) {
    // swallow polling errors
  }
};

enableRemindersButton?.addEventListener('click', async () => {
  if (!('Notification' in window)) {
    window.alert('Notifications are not supported in this browser. In-app reminders will still appear.');
    return;
  }
  if (Notification.permission === 'granted') {
    window.alert('Reminders are already enabled.');
    return;
  }
  await Notification.requestPermission();
});

if (document.querySelector('.schedule-list')) {
  pollDueReminders();
  window.setInterval(pollDueReminders, 30000);
}

if (medicationModal?.classList.contains('is-open')) {
  document.body.style.overflow = 'hidden';
}

const medicationForm = document.querySelector('.medication-form');

if (medicationForm) {
  const scheduleMode = medicationForm.querySelector('select[name="schedule_mode"]');
  const timeFormat = medicationForm.querySelector('select[name="time_format"]');
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

  const normalizeToken = (token, format) => {
    if (format === '24h') {
      const from24 = parse24h(token);
      if (from24) return from24;
      const from12 = parse12h(token);
      return from12;
    }
    const from12 = parse12h(token);
    if (from12) return to12h(from12);
    const from24 = parse24h(token);
    if (from24) return to12h(from24);
    return null;
  };

  const normalizeCommaTimes = (raw, format) => {
    const parts = raw.split(',').map((part) => part.trim()).filter(Boolean);
    const normalized = [];
    for (const part of parts) {
      const value = normalizeToken(part, format);
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

  const convertVisibleTimes = () => {
    const format = timeFormat?.value === '12h' ? '12h' : '24h';
    if (doseTimesInput && doseTimesInput.value.trim() !== '') {
      const convertedList = normalizeCommaTimes(doseTimesInput.value, format);
      if (convertedList) doseTimesInput.value = convertedList;
    }
    if (firstDoseInput && firstDoseInput.value.trim() !== '') {
      const converted = normalizeToken(firstDoseInput.value, format);
      if (converted) firstDoseInput.value = converted;
    }
  };

  scheduleMode?.addEventListener('change', applyScheduleVisibility);
  timeFormat?.addEventListener('change', convertVisibleTimes);

  applyScheduleVisibility();

  medicationForm.addEventListener('submit', (event) => {
    const format = timeFormat?.value === '12h' ? '12h' : '24h';
    const intervalMode = scheduleMode?.value === 'interval';

    if (!intervalMode && doseTimesInput && doseTimesInput.value.trim() !== '') {
      const normalized = normalizeCommaTimes(doseTimesInput.value, format);
      if (!normalized) {
        event.preventDefault();
        window.alert('Invalid dose times. Use HH:MM (24h) or h:MM AM/PM (12h).');
        return;
      }
      doseTimesInput.value = normalized;
    }

    if (intervalMode && firstDoseInput && firstDoseInput.value.trim() !== '') {
      const normalized = normalizeToken(firstDoseInput.value, format);
      if (!normalized) {
        event.preventDefault();
        window.alert('Invalid first dose time. Use HH:MM (24h) or h:MM AM/PM (12h).');
        return;
      }
      firstDoseInput.value = normalized;
    }
  });
}
