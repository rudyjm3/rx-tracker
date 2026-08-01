(function () {
  const modal    = document.querySelector('[data-se-modal]');
  const medIdEl  = document.querySelector('#se-medication-id');
  const titleEl  = document.querySelector('#se-modal-title');
  if (!modal) return;

  // lockBodyScroll/unlockBodyScroll are defined in app.js and exposed on window
  const openSeModal = ({ medicationId, medicationName }) => {
    if (medIdEl) medIdEl.value = medicationId;
    if (titleEl) titleEl.textContent = `Log Side Effect — ${medicationName}`;
    modal.classList.add('is-open');
    window.lockBodyScroll();
  };
  const closeSeModal = () => {
    modal.classList.remove('is-open');
    window.unlockBodyScroll();
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
