(function () {
  const tzSelect = document.getElementById('timezone-select');
  const detectBtn = document.getElementById('detect-timezone-btn');
  const detectHint = document.getElementById('timezone-detect-hint');
  if (!tzSelect || !detectBtn) return;

  const browserTz = (() => {
    try { return Intl.DateTimeFormat().resolvedOptions().timeZone; } catch { return ''; }
  })();

  const applyBrowserTz = () => {
    if (!browserTz) return;
    const opt = Array.from(tzSelect.options).find((o) => o.value === browserTz);
    if (opt) {
      tzSelect.value = browserTz;
      if (detectHint) {
        detectHint.textContent = `Device timezone set: ${browserTz.replace(/_/g, ' ').replace(/\//g, ' / ')}`;
      }
    } else if (detectHint) {
      detectHint.textContent = `Could not find "${browserTz}" in the list — select manually.`;
    }
  };

  detectBtn.addEventListener('click', applyBrowserTz);

  // Auto-set when no timezone has been explicitly saved yet. Uses data-tz-saved
  // rather than the selected value so users who intentionally pick UTC are not
  // overridden on the next page load.
  if (tzSelect.dataset.tzSaved !== '1' && browserTz && browserTz !== tzSelect.value) {
    applyBrowserTz();
  }
}());
