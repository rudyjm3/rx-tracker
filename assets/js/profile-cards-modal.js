(function () {
  document.querySelectorAll('[data-open-meds-modal]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var modal = document.querySelector('[data-meds-modal]');
      if (modal) modal.classList.add('is-open');
    });
  });
  document.querySelectorAll('[data-close-meds-modal]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      btn.closest('.modal-overlay').classList.remove('is-open');
    });
  });
  var medsModal = document.querySelector('[data-meds-modal]');
  if (medsModal) {
    medsModal.addEventListener('click', function (e) {
      if (e.target === medsModal) medsModal.classList.remove('is-open');
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
      });
    });
    document.querySelectorAll('[data-close-allergies-modal]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        allergiesModal.classList.remove('is-open');
      });
    });
    allergiesModal.addEventListener('click', function (e) {
      if (e.target === allergiesModal) allergiesModal.classList.remove('is-open');
    });

    allergiesModal.querySelectorAll('[data-open-allergy-add-view]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        showAllergyView(allergiesModal.querySelector('[data-allergies-view="add"]'));
      });
    });
    allergiesModal.querySelectorAll('[data-open-allergy-edit-view]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-open-allergy-edit-view');
        showAllergyView(allergiesModal.querySelector('[data-allergies-view="edit"][data-allergy-edit-id="' + id + '"]'));
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
  }

  document.querySelectorAll('input[name="height_unit_cm"]').forEach(function (toggle) {
    var label = toggle.parentElement.querySelector('[data-height-unit-label]');
    if (!label) return;
    toggle.addEventListener('change', function () {
      label.textContent = toggle.checked ? 'cm' : 'in';
    });
  });
})();
