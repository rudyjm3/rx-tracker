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
