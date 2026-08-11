(function () {
  var flashParams = new URLSearchParams(window.location.search);
  if (flashParams.has('success') || flashParams.has('error') || flashParams.has('open')) {
    flashParams.delete('success');
    flashParams.delete('error');
    flashParams.delete('open');
    var cleanedQuery = flashParams.toString();
    var cleanedUrl = window.location.pathname + (cleanedQuery ? '?' + cleanedQuery : '');
    history.replaceState(null, '', cleanedUrl);
  }

  document.querySelectorAll('[data-open-meds-modal]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var modal = document.querySelector('[data-meds-modal]');
      if (modal) modal.classList.add('is-open');
      window.lockBodyScroll();
    });
  });
  document.querySelectorAll('[data-close-meds-modal]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      btn.closest('.modal-overlay').classList.remove('is-open');
      window.unlockBodyScroll();
    });
  });
  var medsModal = document.querySelector('[data-meds-modal]');
  if (medsModal) {
    medsModal.addEventListener('click', function (e) {
      if (e.target === medsModal) {
        medsModal.classList.remove('is-open');
        window.unlockBodyScroll();
      }
    });
  }

  var allergiesModal = document.querySelector('[data-allergies-modal]');
  if (allergiesModal) {
    function showAllergyView(el) {
      allergiesModal.querySelectorAll('[data-allergies-view]').forEach(function (v) { v.hidden = true; });
      if (el) el.hidden = false;
    }

    document.querySelectorAll('[data-open-allergies-modal]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        showAllergyView(allergiesModal.querySelector('[data-allergies-view="list"]'));
        allergiesModal.classList.add('is-open');
        window.lockBodyScroll();
      });
    });
    document.querySelectorAll('[data-close-allergies-modal]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        allergiesModal.classList.remove('is-open');
        window.unlockBodyScroll();
      });
    });
    allergiesModal.addEventListener('click', function (e) {
      if (e.target === allergiesModal) {
        allergiesModal.classList.remove('is-open');
        window.unlockBodyScroll();
      }
    });

    allergiesModal.querySelectorAll('[data-open-allergy-add-view]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        showAllergyView(allergiesModal.querySelector('[data-allergies-view="add"]'));
      });
    });
    allergiesModal.querySelectorAll('[data-open-allergy-edit-view]').forEach(function (row) {
      function openRowEditView() {
        var id = row.getAttribute('data-open-allergy-edit-view');
        showAllergyView(allergiesModal.querySelector('[data-allergies-view="edit"][data-allergy-edit-id="' + id + '"]'));
      }
      row.addEventListener('click', openRowEditView);
      row.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          openRowEditView();
        }
      });
    });
    allergiesModal.querySelectorAll('[data-back-to-allergy-list]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        showAllergyView(allergiesModal.querySelector('[data-allergies-view="list"]'));
      });
    });

    var tabs = allergiesModal.querySelectorAll('[data-allergy-tab]');
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        tabs.forEach(function (t) { t.classList.remove('is-active'); });
        tab.classList.add('is-active');
        allergiesModal.querySelectorAll('[data-allergy-tab-panel]').forEach(function (p) {
          p.hidden = p.getAttribute('data-allergy-tab-panel') !== tab.getAttribute('data-allergy-tab');
        });
      });
    });

    allergiesModal.querySelectorAll('[data-allergy-select]').forEach(function (select) {
      var form = select.closest('form');
      var wrap = form ? form.querySelector('[data-allergy-new-wrap]') : null;
      if (!wrap) return;
      select.addEventListener('change', function () {
        wrap.style.display = select.value === 'new' ? '' : 'none';
      });
    });

    allergiesModal.querySelectorAll('[data-life-threatening-toggle]').forEach(function (toggle) {
      var form = toggle.closest('form');
      var severityGroup = form ? form.querySelector('[data-severity-group]') : null;
      if (!severityGroup) return;
      function syncSeverityGroup() {
        severityGroup.hidden = toggle.checked;
      }
      toggle.addEventListener('change', syncSeverityGroup);
      syncSeverityGroup();
    });

    if (allergiesModal.classList.contains('is-open')) {
      window.lockBodyScroll();
    }
  }

  var profileEditModal = document.querySelector('[data-profile-edit-modal]');
  if (profileEditModal) {
    document.querySelectorAll('[data-open-profile-edit-modal]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        profileEditModal.classList.add('is-open');
        window.lockBodyScroll();
      });
    });
    profileEditModal.querySelectorAll('[data-close-profile-edit-modal]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        profileEditModal.classList.remove('is-open');
        window.unlockBodyScroll();
      });
    });
    profileEditModal.addEventListener('click', function (e) {
      if (e.target === profileEditModal) {
        profileEditModal.classList.remove('is-open');
        window.unlockBodyScroll();
      }
    });
  }

  var allergyTypeInfoModal = document.querySelector('[data-allergy-type-info-modal]');
  if (allergyTypeInfoModal) {
    document.querySelectorAll('[data-open-allergy-type-info]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        allergyTypeInfoModal.classList.add('is-open');
        window.lockBodyScroll();
      });
    });
    allergyTypeInfoModal.querySelectorAll('[data-close-allergy-type-info-modal]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        allergyTypeInfoModal.classList.remove('is-open');
        window.unlockBodyScroll();
      });
    });
    allergyTypeInfoModal.addEventListener('click', function (e) {
      if (e.target === allergyTypeInfoModal) {
        allergyTypeInfoModal.classList.remove('is-open');
        window.unlockBodyScroll();
      }
    });
  }

  document.querySelectorAll('input[name="height_unit_cm"]').forEach(function (toggle) {
    var label = toggle.parentElement.querySelector('[data-height-unit-label]');
    if (!label) return;
    toggle.addEventListener('change', function () {
      label.textContent = toggle.checked ? 'cm' : 'in';
    });
  });
})();
