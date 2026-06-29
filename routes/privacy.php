<?php

declare(strict_types=1);

/** @var AuthService $auth */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$isLoggedIn = isset($auth) && $auth->currentUserId() > 0;

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#0754A8">
  <title>Privacy Policy — RxTracker</title>
  <link rel="stylesheet" href="assets/css/styles.css?v=<?= filemtime(__DIR__ . '/../assets/css/styles.css') ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous">
  <link rel="icon" type="image/x-icon" href="assets/icons/favicon.ico">
</head>
<body>

<div class="auth-shell auth-shell--centered" style="min-height:100vh;align-items:flex-start;padding:2rem 1rem;">
  <div style="max-width:720px;width:100%;margin:0 auto;">
    <div style="margin-bottom:1.5rem;">
      <a href="<?= $isLoggedIn ? 'index.php' : 'index.php?page=login' ?>" style="display:inline-flex;align-items:center;gap:0.4rem;color:var(--rx-blue);font-size:0.9rem;text-decoration:none;">
        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back
      </a>
    </div>
    <div class="auth-card" style="max-width:100%;text-align:left;">
      <div class="auth-brand" style="margin-bottom:1.5rem;">
        <img src="assets/icons/icon-192.png" alt="" class="auth-logo" aria-hidden="true">
        <span class="auth-brand-name" style="color:var(--rx-navy);">RxTracker</span>
      </div>

      <h1 style="font-size:1.6rem;margin-bottom:0.25rem;">Privacy Policy</h1>
      <p style="color:var(--rx-text-muted);font-size:0.9rem;margin-bottom:2rem;">Effective date: June 1, 2025</p>

      <section style="margin-bottom:1.75rem;">
        <h2 style="font-size:1.1rem;margin-bottom:0.5rem;">1. Information We Collect</h2>
        <p>When you use RxTracker, we collect information you provide directly:</p>
        <ul style="margin-left:1.25rem;line-height:1.8;">
          <li><strong>Account information:</strong> email address, display name, and a securely hashed password</li>
          <li><strong>Medication data:</strong> medication names, dosage details, schedules, dose logs, refill records, and side effect notes you enter</li>
          <li><strong>Usage data:</strong> timestamps of logged doses, settings preferences, and family profile data you create</li>
          <li><strong>Device data:</strong> browser push notification subscription tokens if you enable reminders (stored per-device)</li>
        </ul>
      </section>

      <section style="margin-bottom:1.75rem;">
        <h2 style="font-size:1.1rem;margin-bottom:0.5rem;">2. How We Use Your Information</h2>
        <p>We use the information we collect solely to:</p>
        <ul style="margin-left:1.25rem;line-height:1.8;">
          <li>Operate and provide the RxTracker service</li>
          <li>Send medication dose reminders and push notifications you have enabled</li>
          <li>Generate adherence reports and summaries you request</li>
          <li>Respond to account requests such as password resets</li>
          <li>Improve the reliability and functionality of the App</li>
        </ul>
        <p style="margin-top:0.75rem;"><strong>We do not sell, rent, or share your personal or health data with third parties for advertising or marketing purposes.</strong></p>
      </section>

      <section style="margin-bottom:1.75rem;">
        <h2 style="font-size:1.1rem;margin-bottom:0.5rem;">3. Health Information</h2>
        <p>Medication and health data you enter is sensitive. We take this seriously:</p>
        <ul style="margin-left:1.25rem;line-height:1.8;">
          <li>Your data is stored in a private database accessible only to your account</li>
          <li>Passwords are stored using bcrypt hashing — we cannot read your password</li>
          <li>Family profiles are scoped to your account and are not visible to other users</li>
        </ul>
        <p style="margin-top:0.75rem;">RxTracker is not a HIPAA-covered entity. Do not enter information that requires HIPAA-level protections.</p>
      </section>

      <section style="margin-bottom:1.75rem;">
        <h2 style="font-size:1.1rem;margin-bottom:0.5rem;">4. Data Retention</h2>
        <p>Your data is retained as long as your account is active. You may delete your account at any time from the profile page, which will permanently remove your account and all associated data from our systems.</p>
      </section>

      <section style="margin-bottom:1.75rem;">
        <h2 style="font-size:1.1rem;margin-bottom:0.5rem;">5. Push Notifications</h2>
        <p>If you enable browser push notifications, your browser generates a push subscription endpoint that is stored on our server to deliver reminders. You can disable notifications at any time from the Settings page or your browser's notification settings, which will remove your subscription.</p>
      </section>

      <section style="margin-bottom:1.75rem;">
        <h2 style="font-size:1.1rem;margin-bottom:0.5rem;">6. Cookies and Sessions</h2>
        <p>We use session cookies to keep you signed in. If you choose "Remember me," a long-lived session token is stored in a cookie for up to 30 days. We do not use tracking cookies or third-party analytics cookies.</p>
      </section>

      <section style="margin-bottom:1.75rem;">
        <h2 style="font-size:1.1rem;margin-bottom:0.5rem;">7. Third-Party Services</h2>
        <p>RxTracker may use the following third-party services:</p>
        <ul style="margin-left:1.25rem;line-height:1.8;">
          <li><strong>Font Awesome (CDN):</strong> used to deliver icons — subject to its own privacy policy</li>
          <li><strong>Email provider (password resets):</strong> your email address is passed to an email delivery service solely to send password reset messages</li>
        </ul>
      </section>

      <section style="margin-bottom:1.75rem;">
        <h2 style="font-size:1.1rem;margin-bottom:0.5rem;">8. Your Rights</h2>
        <p>You have the right to:</p>
        <ul style="margin-left:1.25rem;line-height:1.8;">
          <li>Access all data in your account by viewing it within the App</li>
          <li>Correct inaccurate data by editing it within the App</li>
          <li>Delete your account and all associated data from the profile page</li>
        </ul>
      </section>

      <section style="margin-bottom:1.75rem;">
        <h2 style="font-size:1.1rem;margin-bottom:0.5rem;">9. Children's Privacy</h2>
        <p>RxTracker is not directed at children under 13. We do not knowingly collect personal information from children under 13. The family profiles feature is intended for use by adults managing medications for minor dependents under their care.</p>
      </section>

      <section style="margin-bottom:1.75rem;">
        <h2 style="font-size:1.1rem;margin-bottom:0.5rem;">10. Changes to This Policy</h2>
        <p>We may update this Privacy Policy periodically. Continued use of the App after changes are posted constitutes your acceptance of the updated policy.</p>
      </section>

      <section>
        <h2 style="font-size:1.1rem;margin-bottom:0.5rem;">11. Contact</h2>
        <p>If you have questions or concerns about this Privacy Policy or your data, please contact us through the App's profile or help section.</p>
      </section>
    </div>
  </div>
</div>

</body>
</html>
