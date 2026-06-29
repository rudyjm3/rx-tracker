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
  <title>Terms of Use — RxTracker</title>
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

      <h1 style="font-size:1.6rem;margin-bottom:0.25rem;">Terms of Use</h1>
      <p style="color:var(--rx-text-muted);font-size:0.9rem;margin-bottom:2rem;">Effective date: June 1, 2025</p>

      <section style="margin-bottom:1.75rem;">
        <h2 style="font-size:1.1rem;margin-bottom:0.5rem;">1. Acceptance of Terms</h2>
        <p>By accessing or using RxTracker ("the App"), you agree to be bound by these Terms of Use. If you do not agree to these terms, do not use the App.</p>
      </section>

      <section style="margin-bottom:1.75rem;">
        <h2 style="font-size:1.1rem;margin-bottom:0.5rem;">2. Not Medical Advice</h2>
        <p>RxTracker is a personal medication tracking tool and is provided for informational and organizational purposes only. <strong>The App does not provide medical advice, diagnosis, or treatment.</strong> Nothing in the App should be construed as a substitute for professional medical advice from a qualified healthcare provider. Always consult your doctor, pharmacist, or other qualified health professional before making any decisions about your medications or health.</p>
      </section>

      <section style="margin-bottom:1.75rem;">
        <h2 style="font-size:1.1rem;margin-bottom:0.5rem;">3. Your Account</h2>
        <p>You are responsible for maintaining the confidentiality of your account credentials and for all activity that occurs under your account. Notify us immediately if you believe your account has been compromised. You must provide accurate information when creating your account.</p>
      </section>

      <section style="margin-bottom:1.75rem;">
        <h2 style="font-size:1.1rem;margin-bottom:0.5rem;">4. Acceptable Use</h2>
        <p>You agree not to:</p>
        <ul style="margin-left:1.25rem;line-height:1.8;">
          <li>Use the App for any unlawful purpose</li>
          <li>Attempt to gain unauthorized access to any part of the App or its infrastructure</li>
          <li>Transmit any harmful, offensive, or disruptive content</li>
          <li>Reverse-engineer or attempt to extract the App's source code</li>
        </ul>
      </section>

      <section style="margin-bottom:1.75rem;">
        <h2 style="font-size:1.1rem;margin-bottom:0.5rem;">5. Data and Privacy</h2>
        <p>Your use of the App is also governed by our <a href="index.php?page=privacy" style="color:var(--rx-blue);">Privacy Policy</a>, which is incorporated into these Terms by reference. Please review it carefully.</p>
      </section>

      <section style="margin-bottom:1.75rem;">
        <h2 style="font-size:1.1rem;margin-bottom:0.5rem;">6. Disclaimer of Warranties</h2>
        <p>The App is provided "as is" and "as available" without warranties of any kind, either express or implied. We do not warrant that the App will be uninterrupted, error-free, or that medication reminders will be delivered at any particular time. You are solely responsible for managing your medications.</p>
      </section>

      <section style="margin-bottom:1.75rem;">
        <h2 style="font-size:1.1rem;margin-bottom:0.5rem;">7. Limitation of Liability</h2>
        <p>To the fullest extent permitted by law, RxTracker and its developers shall not be liable for any indirect, incidental, special, or consequential damages arising from your use of the App, including but not limited to missed doses, medication errors, or reliance on information displayed in the App.</p>
      </section>

      <section style="margin-bottom:1.75rem;">
        <h2 style="font-size:1.1rem;margin-bottom:0.5rem;">8. Modifications</h2>
        <p>We may update these Terms from time to time. Continued use of the App after changes are posted constitutes acceptance of the updated Terms. We encourage you to review this page periodically.</p>
      </section>

      <section style="margin-bottom:1.75rem;">
        <h2 style="font-size:1.1rem;margin-bottom:0.5rem;">9. Governing Law</h2>
        <p>These Terms are governed by applicable law. Any disputes arising from these Terms or your use of the App shall be resolved in accordance with applicable law.</p>
      </section>

      <section>
        <h2 style="font-size:1.1rem;margin-bottom:0.5rem;">10. Contact</h2>
        <p>If you have questions about these Terms, please contact us through the App's profile or help section.</p>
      </section>
    </div>
  </div>
</div>

</body>
</html>
