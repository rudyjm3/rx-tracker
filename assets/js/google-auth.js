(() => {
  const clientId = document.body?.dataset.googleClientId || '';
  const mode = document.body?.dataset.googleAuthMode || 'login';
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const button = document.querySelector('[data-google-auth-button]');
  const message = document.querySelector('[data-google-auth-message]');
  const text = button?.querySelector('[data-google-auth-text]');

  const label = mode === 'connect' ? 'Connect Google Account' : (mode === 'signup' ? 'Sign up with Google' : 'Continue with Google');
  const endpoint = mode === 'connect' ? 'index.php?page=google-link' : 'index.php?page=google-login';

  const setMessage = (value) => {
    if (!message) return;
    message.textContent = value;
    message.hidden = value === '';
  };

  const setLoading = (loading) => {
    if (!button || !text) return;
    button.disabled = loading;
    button.classList.toggle('is-loading', loading);
    text.textContent = loading ? 'Contacting Google…' : label;
  };

  const postCredential = async (credential) => {
    setLoading(true);
    setMessage('');
    try {
      const res = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ credential, mode, csrf_token: csrfToken }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) throw new Error(data.message || 'Google authentication failed. Please try again.');
      window.location.assign(data.redirect || 'index.php');
    } catch (error) {
      setMessage(error.message || 'Network error. Please try again.');
      setLoading(false);
    }
  };

  window.addEventListener('load', () => {
    if (!clientId || !button || !window.google?.accounts?.id) return;

    google.accounts.id.initialize({
      client_id: clientId,
      callback: (response) => {
        if (!response.credential) {
          setMessage('Google sign-in was not completed. Please try again.');
          setLoading(false);
          return;
        }
        postCredential(response.credential);
      },
      cancel_on_tap_outside: true,
      ux_mode: 'popup',
      use_fedcm_for_prompt: true,
    });

    button.addEventListener('click', () => {
      setLoading(true);
      setMessage('');
      google.accounts.id.prompt((notification) => {
        const dismissedWithoutCredential = notification.isDismissedMoment()
          && notification.getDismissedReason() !== 'credential_returned';
        if (dismissedWithoutCredential) {
          setMessage('Google sign-in popup was closed or blocked. Please try again.');
          setLoading(false);
        }
      });
    });
  });
})();
