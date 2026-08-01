(function () {
  document.querySelectorAll('[data-export-form]').forEach((form) => {
    const btn      = form.querySelector('[data-export-btn]');
    const tokenEl  = form.querySelector('[data-download-token]');
    const notice   = form.querySelector('[data-export-notice]');
    const viewLink = form.querySelector('[data-view-pdf-link]');
    if (!btn || !tokenEl) return;

    if (viewLink) {
      viewLink.addEventListener('click', (e) => {
        e.preventDefault();
        const prev = form.target;
        form.target = '_blank';
        form.submit();
        requestAnimationFrame(() => { form.target = prev; });
      });
    }

    form.addEventListener('submit', () => {
      const token = Math.random().toString(36).slice(2) + Date.now().toString(36);
      tokenEl.value = token;

      const originalHtml = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Generating PDF…';

      const cookieName = 'rx_dl_' + token;
      let attempts = 0;
      const poll = setInterval(() => {
        attempts++;
        if (document.cookie.includes(cookieName) || attempts > 60) {
          clearInterval(poll);
          document.cookie = cookieName + '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
          btn.disabled = false;
          btn.innerHTML = originalHtml;
          if (notice) notice.style.display = 'flex';
          if (viewLink) viewLink.hidden = false;
        }
      }, 500);
    });
  });
})();

// ── Export PDF: toggle mood fieldset visibility ───────────────────────────────

(function () {
  const toggle = document.getElementById('include-mood-toggle');
  const moodFieldset = document.querySelector('[data-mood-fieldset]');
  if (!toggle || !moodFieldset) return;

  const sync = () => { moodFieldset.style.display = toggle.checked ? '' : 'none'; };
  toggle.addEventListener('change', sync);
  sync();
})();

// ── Export PDF: sync shared reporting-period inputs into each report form ────

(function () {
  const startEl = document.getElementById('report-start-shared');
  const endEl   = document.getElementById('report-end-shared');
  if (!startEl || !endEl) return;

  startEl.addEventListener('input', () => {
    document.querySelectorAll('[data-report-start-mirror]').forEach((el) => { el.value = startEl.value; });
  });
  endEl.addEventListener('input', () => {
    document.querySelectorAll('[data-report-end-mirror]').forEach((el) => { el.value = endEl.value; });
  });
})();
