<?php

declare(strict_types=1);

/** @var MedicationRepository $repository */
/** @var AuthService $auth */
/** @var string $today */
/** @var string $currentTime */
/** @var string $page */
/** @var string|null $error */
/** @var string|null $notice */

require __DIR__ . '/../includes/pages-data.php';
require __DIR__ . '/../includes/pages-shell-top.php';
?>
    <?php
      $vapidConfigured = trim((string) getenv('PUSH_VAPID_PUBLIC_KEY')) !== ''
          && trim((string) getenv('PUSH_VAPID_PRIVATE_KEY')) !== ''
          && trim((string) getenv('PUSH_VAPID_SUBJECT')) !== '';
      $webPushInstalled = is_file(dirname(__DIR__) . '/vendor/autoload.php')
          && class_exists(\Minishlink\WebPush\WebPush::class);
      $lastPushSentAt = $repository->lastPushSentAt();
    ?>
    <?php
      $tzGroups = [];
      foreach (DateTimeZone::listIdentifiers() as $tzId) {
          $slash = strpos($tzId, '/');
          $group = $slash !== false ? substr($tzId, 0, $slash) : 'Other';
          $label = $slash !== false ? str_replace('_', ' ', substr($tzId, $slash + 1)) : $tzId;
          $tzGroups[$group][] = ['value' => $tzId, 'label' => $label];
      }
      ksort($tzGroups);
      // When no timezone has been explicitly saved, default the selector to the
      // environment APP_TIMEZONE so saving other settings doesn't silently change
      // the timezone to whatever PHP's list happens to render first.
      $tzSaved    = $userTimezone !== '';
      $selectedTz = $tzSaved ? $userTimezone : date_default_timezone_get();
    ?>
    <section class="panel settings-panel">
      <div class="panel-heading"><h2>General Settings</h2></div>
      <form method="post" action="index.php?page=settings" class="stacked-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_settings">
        <label>Time zone
          <div class="timezone-select-row">
            <select name="timezone" id="timezone-select" data-tz-saved="<?= $tzSaved ? '1' : '0' ?>">
              <?php foreach ($tzGroups as $group => $tzList): ?>
                <optgroup label="<?= e($group) ?>">
                  <?php foreach ($tzList as $tz): ?>
                    <option value="<?= e($tz['value']) ?>"<?= $selectedTz === $tz['value'] ? ' selected' : '' ?>><?= e($tz['label']) ?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endforeach; ?>
            </select>
            <button type="button" class="button secondary small" id="detect-timezone-btn">Use device timezone</button>
          </div>
          <span class="field-hint" id="timezone-detect-hint"></span>
        </label>
        <label>Missed-dose grace period
          <select name="missed_grace_minutes">
            <option value="30"<?= $graceMinutes === 30 ? ' selected' : '' ?>>30 minutes</option>
            <option value="60"<?= $graceMinutes === 60 ? ' selected' : '' ?>>60 minutes</option>
          </select>
        </label>
        <label>Default snooze duration
          <select name="snooze_minutes">
            <option value="5"<?= $snoozeMinutes === 5 ? ' selected' : '' ?>>5 minutes</option>
            <option value="10"<?= $snoozeMinutes === 10 ? ' selected' : '' ?>>10 minutes</option>
            <option value="15"<?= $snoozeMinutes === 15 ? ' selected' : '' ?>>15 minutes</option>
            <option value="30"<?= $snoozeMinutes === 30 ? ' selected' : '' ?>>30 minutes</option>
          </select>
        </label>
        <button type="submit" class="button-solo">Save settings</button>
      </form>
      <hr>
      <h3 class="settings-subsection-heading">Alarm &amp; Notification Settings</h3>
      <p class="settings-subsection-hint">Enable both toggles below for full coverage — sound while the app is open, push alerts when it&rsquo;s closed.</p>
      <div class="notification-toggles">
        <div class="notification-toggle-row">
          <label class="toggle-control" for="sound-toggle">
            <input type="checkbox" id="sound-toggle" data-sound-toggle>
            <span class="toggle-slider" aria-hidden="true"></span>
            <span class="toggle-label">Alarm sound</span>
          </label>
          <p class="toggle-description">Audible alarm when a dose is due <strong>while the app is open</strong>. Works offline — no permission required. On by default.</p>
        </div>
        <div class="notification-toggle-row">
          <label class="toggle-control" for="vibration-toggle">
            <input type="checkbox" id="vibration-toggle" data-vibration-toggle>
            <span class="toggle-slider" aria-hidden="true"></span>
            <span class="toggle-label">Vibration</span>
          </label>
          <p class="toggle-description">Device vibration for in-app alarms. On by default. Turn off if you only want sound (e.g. when your phone is on a surface in a meeting).</p>
        </div>
        <div class="notification-toggle-row">
          <label class="toggle-control" for="mood-scheme-toggle">
            <input type="checkbox" id="mood-scheme-toggle" data-mood-scheme-toggle<?= $moodChartScheme === 'teal' ? ' checked' : '' ?>>
            <span class="toggle-slider" aria-hidden="true"></span>
            <span class="toggle-label">Teal mood chart</span>
          </label>
          <p class="toggle-description">Use a teal gradient for the mood trend chart (matches the PDF report) instead of the red-to-green scale. Saved to your account.</p>
        </div>
        <div class="notification-toggle-row">
          <label class="toggle-control" for="reminders-toggle">
            <input type="checkbox" id="reminders-toggle" data-enable-reminders>
            <span class="toggle-slider" aria-hidden="true"></span>
            <span class="toggle-label">Background reminders</span>
          </label>
          <p class="toggle-description">Push notification delivered to your device <strong>even when the app is closed</strong>. Requires internet, notification permission, and a service worker. Tap "Take Now" or "Snooze" directly from the notification tray.</p>
          <span class="muted" data-reminder-status>Background push reminders are currently disabled on this device.</span>
        </div>
      </div>
      <div class="in-app-alert" data-in-app-alert hidden></div>
    </section>

    <section class="panel push-status-panel" data-push-status-panel>
      <div class="panel-heading"><h2>Push Notification Status</h2></div>
      <p class="push-status-intro">All checks must pass for background alarms to fire when the app is closed.</p>

      <div class="push-check-list">

        <div class="push-check-row">
          <span class="push-check-icon <?= $vapidConfigured ? 'push-check-ok' : 'push-check-fail' ?>" aria-hidden="true"><?= $vapidConfigured ? '✓' : '✗' ?></span>
          <div class="push-check-body">
            <strong>VAPID keys configured</strong>
            <?php if (!$vapidConfigured): ?>
              <p class="push-check-hint">Run <code>php scripts/generate_vapid_keys.php</code>, then paste the output into your <code>.env</code> file and restart the server.</p>
            <?php endif; ?>
          </div>
        </div>

        <div class="push-check-row">
          <span class="push-check-icon <?= $webPushInstalled ? 'push-check-ok' : 'push-check-fail' ?>" aria-hidden="true"><?= $webPushInstalled ? '✓' : '✗' ?></span>
          <div class="push-check-body">
            <strong>PHP web-push library installed</strong>
            <?php if (!$webPushInstalled): ?>
              <p class="push-check-hint">Run <code>composer install</code> in the project root.</p>
            <?php endif; ?>
          </div>
        </div>

        <div class="push-check-row" data-check-sw>
          <span class="push-check-icon push-check-pending" aria-hidden="true">…</span>
          <div class="push-check-body">
            <strong>Service worker registered</strong>
            <p class="push-check-hint" data-check-hint hidden></p>
          </div>
        </div>

        <div class="push-check-row" data-check-permission>
          <span class="push-check-icon push-check-pending" aria-hidden="true">…</span>
          <div class="push-check-body">
            <strong>Notification permission granted</strong>
            <p class="push-check-hint" data-check-hint hidden></p>
          </div>
        </div>

        <div class="push-check-row" data-check-subscription>
          <span class="push-check-icon push-check-pending" aria-hidden="true">…</span>
          <div class="push-check-body">
            <strong>Push subscription active on this device</strong>
            <p class="push-check-hint" data-check-hint hidden></p>
          </div>
        </div>

        <div class="push-check-row">
          <span class="push-check-icon push-check-warn" aria-hidden="true">⚠</span>
          <div class="push-check-body">
            <strong>Cron job scheduled</strong>
            <p class="push-check-hint">Cannot be verified from the browser. Schedule <code>scripts/send_due_push.php</code> to run every minute on your server (cron on Linux/macOS, Task Scheduler on Windows).
            <?php if ($lastPushSentAt !== null): ?>
              <br>Last push sent: <strong><?= e((new DateTimeImmutable($lastPushSentAt))->format('M j, g:i A')) ?></strong>.
            <?php else: ?>
              <br>No pushes sent yet &mdash; the cron may not be running, or no doses have been due since setup.
            <?php endif; ?>
            </p>
          </div>
        </div>

      </div>

      <div class="push-test-row">
        <button type="button" class="secondary" data-test-push-btn disabled>Send test push</button>
        <span class="push-test-status muted" data-test-push-status></span>
      </div>
    </section>

    <section class="panel settings-panel" style="margin-top:1rem;">
      <div class="panel-heading"><h2>Help &amp; Documentation</h2></div>
      <p style="margin:0 0 .75rem;">New to RxTracker or need a refresher? The user guide covers every feature step by step.</p>
      <a href="index.php?page=help" class="button secondary" style="display:inline-block;">Open User Guide</a>
    </section>

    <p class="disclaimer">RxTracker is a tracking aid only and does not provide medical advice or clinical decision support.</p>
  </main>
  <?php include __DIR__ . '/../includes/bottom-nav.php'; ?>
  </body>
  </html>
  <?php
  exit;
  ?>

