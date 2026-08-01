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
<main class="app-shell app-shell--full">
  <section class="panel help-panel" style="margin:1.5rem auto;padding:1.5rem 1.75rem;">
    <div class="panel-heading"><h2>Help &amp; User Guide</h2></div>

    <nav class="help-toc" style="margin-bottom:1.5rem;line-height:2;">
      <strong>Jump to:</strong>
      <a href="#help-dashboard">Dashboard</a> &bull;
      <a href="#help-add-med">Adding Medications</a> &bull;
      <a href="#help-doses">Marking Doses</a> &bull;
      <a href="#help-inventory">Inventory &amp; Refills</a> &bull;
      <a href="#help-groups">Groups</a> &bull;
      <a href="#help-feedback">Pain &amp; Mood Tracking</a> &bull;
      <a href="#help-sideeffects">Side Effects</a> &bull;
      <a href="#help-history">History &amp; Calendar</a> &bull;
      <a href="#help-export">Export &amp; Reports</a> &bull;
      <a href="#help-family">Family Profiles</a> &bull;
      <a href="#help-profile">My Profile</a> &bull;
      <a href="#help-signin">Signing In &amp; Google</a> &bull;
      <a href="#help-settings">Settings</a> &bull;
      <a href="#help-push">Notifications</a> &bull;
      <a href="#help-pwa">Install App</a> &bull;
      <a href="#help-troubleshoot">Troubleshooting</a>
    </nav>

    <h3 id="help-dashboard">Dashboard</h3>
    <p>The Dashboard is your home base. It shows your <strong>Next Dose</strong> hero card (with upcoming dose info and group medication details), today&rsquo;s full schedule with Take / Skip / Snooze action buttons, your adherence ring summary for the day, and a quick-actions sidebar.</p>

    <h3 id="help-add-med">Adding a Medication</h3>
    <p>Click <strong>Add medication</strong> on the Dashboard or Medications page. Fill in:</p>
    <ul>
      <li><strong>Name</strong> &mdash; start typing for autocomplete suggestions from DailyMed.</li>
      <li><strong>Type</strong> &mdash; Prescription (<em>Rx</em>), OTC, or Supplement. A color-coded badge appears next to the name throughout the app.</li>
      <li><strong>Dose amount &amp; unit</strong> &mdash; e.g. 500 mg or 10 mL.</li>
      <li><strong>Dose form</strong> (optional) &mdash; Tablet, Capsule, Liquid, Inhaler, etc. Affects the icon shown on the dashboard.</li>
      <li><strong>Schedule</strong> &mdash; Fixed times (e.g. <code>8:00 AM, 2:00 PM, 9:00 PM</code>) or Every X hours with a first-dose time. Mark <em>As needed (PRN)</em> to exclude from required dose counts.</li>
      <li><strong>Inventory</strong> (optional) &mdash; starting quantity, quantity per dose, and a low-supply alert threshold. The supply bar turns yellow below 50% and red below 25%.</li>
      <li><strong>Track dose feedback</strong> (optional) &mdash; choose <em>Pain level</em>, <em>Mood level</em>, or <em>Both</em> to prompt for a 1&ndash;10 rating and optional note after each dose.</li>
    </ul>
    <p>To <strong>edit</strong> a medication: Medications page &rarr; click the edit icon on the card. To <strong>discontinue</strong>: click <em>Discontinue Use</em> on the card and choose a reason &mdash; <em>End of regimen</em>, <em>Side effects (moderate to severe)</em>, <em>Doctor&rsquo;s orders</em>, or <em>Other</em> (with a required comment) &mdash; plus an optional comment. The medication moves to the <em>Inactive</em> tab. To <strong>resume</strong> a discontinued medication: click <em>Activate</em> on its card in the Inactive tab and choose a reason &mdash; <em>Doctor&rsquo;s orders</em>, <em>Symptoms returned</em>, <em>Retrying after side effects subsided</em>, <em>Restarting regimen</em>, or <em>Other</em> &mdash; plus an optional comment. Both are saved to the medication&rsquo;s stop / resume history.</p>
    <p>To review past changes to a medication&rsquo;s dose or schedule, open its detail view and expand the <strong>Dose Change History</strong> section.</p>

    <h3 id="help-doses">Marking Doses</h3>
    <p>Each scheduled dose on the Dashboard has three action buttons:</p>
    <ul>
      <li><strong>Take</strong> &mdash; marks the dose taken now. Opens a feedback prompt if dose feedback is enabled for that medication.</li>
      <li><strong>Skip</strong> &mdash; records an intentional skip with a confirmation prompt.</li>
      <li><strong>Snooze</strong> &mdash; delays the reminder by your chosen snooze duration (configured in Settings).</li>
    </ul>
    <p>Possible statuses: <em>Taken</em>, <em>Taken late</em> (logged after the grace period), <em>Skipped</em>, <em>Missed</em> (grace period expired with no action), <em>Snoozed until [time]</em>.</p>
    <p>Forgot to log a dose from an earlier day? Use <strong>Log past dose</strong> from the medication&rsquo;s actions menu to pick the date, the scheduled time slot, and optionally the actual time taken, pain level, and notes.</p>

    <h3 id="help-inventory">Inventory &amp; Refills</h3>
    <p>RxTracker deducts from your supply each time a dose is logged as taken. A days-remaining estimate appears on the medication card, and a refill alert is shown when supply falls below your set threshold.</p>
    <p>To <strong>log a refill</strong>: Medications &rarr; click <em>Log refill</em> on the card &rarr; enter the date, quantity added, and an optional note. View past refills with the <em>Refill history</em> button on the card.</p>
    <p>If your on-hand count drifts from reality (e.g. after a recount or a dropped pill), use <strong>Adjust quantity</strong> on the card to enter a corrected count and an optional reason. This directly corrects the supply count without adding to inventory or creating a refill-history entry &mdash; use Log refill instead when you&rsquo;ve actually received more medication.</p>

    <h3 id="help-groups">Medication Groups</h3>
    <p>Groups bundle two or more medications taken at the same time into a single scheduled alarm. Go to <strong>Medications &rarr; Groups tab</strong> to create a group (name + scheduled time) and add medications to it. Notes:</p>
    <ul>
      <li>A medication can only belong to one group at a time.</li>
      <li>You can set a <strong>group dose override</strong> — a different quantity-per-dose for a specific medication when taken as part of this group (e.g. 2 tablets in the group vs. the default 1).</li>
      <li>Each medication in a group retains its own inventory tracking and feedback settings.</li>
    </ul>

    <h3 id="help-feedback">Pain &amp; Mood Tracking</h3>
    <p>Enable <em>Track dose feedback</em> in the medication form and choose <em>Pain level</em>, <em>Mood level</em>, or <em>Both</em>. After marking a dose taken, you&rsquo;ll be prompted to rate the relevant level(s) 1&ndash;10 and add an optional note.</p>
    <ul>
      <li><strong>Pain trend</strong> &mdash; click the <em>Pain trend</em> button on the medication card and select a window (Today / 7 / 30 / 90 days) to see a trend chart.</li>
      <li><strong>Mood &amp; Wellbeing page</strong> &mdash; a dedicated page (linked from the Dashboard quick actions) shows per-medication mood trend charts with the same time-range tabs; hover or click a point on multi-day views to drill into that day.</li>
      <li>In <strong>Settings</strong>, toggle <strong>Teal mood chart</strong> to switch the mood trend line from the default red-to-green gradient to a teal gradient (matches the PDF report).</li>
    </ul>

    <h3 id="help-sideeffects">Side Effects</h3>
    <p>Click <strong>Log side effect</strong> on a medication card to record a side effect: date (defaults to today), a description, severity (<em>Mild</em>, <em>Moderate</em>, or <em>Severe</em>), and optional notes. Logged side effects are included in both the Pain and Mood Doctor Visit Report PDFs.</p>

    <h3 id="help-history">History &amp; Calendar</h3>
    <p>The <strong>Calendar</strong> page shows a month view with color-coded adherence markers for each day. Click any day to see that day&rsquo;s dose log. Navigate months with the left/right arrows. The <strong>Export</strong> page provides a filterable full dose history table.</p>

    <h3 id="help-export">Export &amp; Doctor Visit Reports</h3>
    <p>The <strong>Export</strong> page has three main features:</p>
    <ul>
      <li><strong>Dose history table</strong> &mdash; filter by date range and medication, then use your browser&rsquo;s print dialog (<em>Print / Save as PDF</em>) to save or print it.</li>
      <li><strong>Pain Level Tracking report</strong> &mdash; select a date range, optionally toggle per-medication pain charts, then click <em>Generate &amp; Download PDF</em>. Includes an adherence summary with rings, current medications list (with type badges), full dose history, pain level charts, side effects log, and a footer disclaimer.</li>
      <li><strong>Mood and Wellbeing report</strong> &mdash; a separate PDF with the same layout, generated the same way, but with per-medication mood charts instead of pain charts.</li>
    </ul>
    <p>Filenames reflect the date range selected (e.g. <code>doctor-visit-report-5-29-2026-thru-6-29-2026.pdf</code>).</p>

    <h3 id="help-family">Family Members &amp; Profiles</h3>
    <p>RxTracker supports multiple profiles so you can track medications for family members from one account.</p>
    <ul>
      <li><strong>Add a family member</strong>: Go to <strong>My Profile &rarr; Family Members</strong> section. Enter the name, relationship, birth year (optional), and choose an avatar color.</li>
      <li><strong>Switch profiles</strong>: Click the avatar button in the top-right navigation to open the profile switcher dropdown. Select a family member to view and manage their medications. A banner at the top of the app confirms whose profile you&rsquo;re viewing.</li>
      <li><strong>Switching back</strong>: Open the avatar dropdown and select your own name (shown at the top of the list).</li>
      <li><strong>Edit or remove a member</strong>: Go to My Profile &rarr; Family Members and use the edit/remove buttons on each member card.</li>
    </ul>

    <h3 id="help-profile">My Profile</h3>
    <p>Access My Profile via the avatar button in the top nav &rarr; <em>My Profile</em>. From here you can:</p>
    <ul>
      <li>Update your <strong>display name</strong>.</li>
      <li>Change your <strong>password</strong>.</li>
      <li>Manage <strong>family member profiles</strong> (add, edit, remove, set avatar colors).</li>
      <li>Export or delete your <strong>account data</strong>.</li>
      <li>View and revoke active <strong>remember-me sessions</strong>.</li>
    </ul>

    <h3 id="help-signin">Signing In &amp; Google Account</h3>
    <p>You can sign in with your email/password or with <strong>Continue with Google</strong> on the login or register page. From <strong>My Profile</strong>, connect or disconnect a Google account at any time; if you disconnect while no password is set, set one first so you don&rsquo;t lose access to your account.</p>
    <p>Terms of Service and Privacy Policy pages are linked from the login/register page footers and the bottom navigation.</p>

    <h3 id="help-settings">Settings</h3>
    <ul>
      <li><strong>Grace period</strong> &mdash; how long (30 or 60 minutes) before a dose is auto-marked Missed if no action is taken.</li>
      <li><strong>Snooze duration</strong> &mdash; default snooze length: 5, 10, 15, or 30 minutes.</li>
      <li><strong>Sound &amp; Vibration</strong> &mdash; toggle in-app alarm sound and vibration for dose reminders.</li>
      <li><strong>Background Reminders</strong> &mdash; enables push notifications so you receive alerts even when the app is closed (see Notifications below).</li>
    </ul>

    <h3 id="help-push">Push Notifications</h3>
    <p>Go to <strong>Settings &rarr; Background Reminders</strong> and toggle it on. When your browser prompts for permission, click <em>Allow</em>. The push status checklist must show all items passing. Use <em>Send test notification</em> to verify delivery. Important notes:</p>
    <ul>
      <li>On <strong>iPhone</strong>, RxTracker must be installed to the home screen (as a PWA) before push notifications will work.</li>
      <li>Notifications require a server-side scheduled task (cron job) to dispatch them — confirm this is running with your hosting setup.</li>
    </ul>

    <h3 id="help-pwa">Installing as an App</h3>
    <ul>
      <li><strong>iPhone (Safari)</strong>: Tap the Share button &rarr; Add to Home Screen &rarr; Add.</li>
      <li><strong>Android (Chrome)</strong>: Tap the menu (&#8942;) &rarr; Add to Home Screen &rarr; Install.</li>
      <li><strong>Desktop (Chrome/Edge)</strong>: Click the install icon in the address bar.</li>
    </ul>
    <p>Once installed, the app runs in a standalone window without browser chrome and receives push notifications on supported platforms.</p>

    <h3 id="help-troubleshoot">Troubleshooting</h3>
    <ul>
      <li><strong>No push notifications</strong> &mdash; Check browser notification permission is set to <em>Allow</em>. On iPhone, the PWA must be installed to the home screen. Verify the server-side cron job is active.</li>
      <li><strong>Dose shows Missed despite taking it</strong> &mdash; The grace period expired before you logged it. Increase the grace period in Settings.</li>
      <li><strong>Supply count is wrong</strong> &mdash; Check that <em>Quantity per dose</em> is set correctly in the medication edit form, and that any group dose overrides are set as intended. If the count has simply drifted from reality, use <em>Adjust quantity</em> on the card to correct it directly.</li>
      <li><strong>Autocomplete not working</strong> &mdash; Requires internet access to DailyMed. Type the medication name manually if offline.</li>
      <li><strong>App feels outdated after an update</strong> &mdash; Force-refresh: Ctrl+Shift+R (Windows/Linux) or Cmd+Shift+R (Mac). If that doesn&rsquo;t help, clear the site data in browser settings.</li>
      <li><strong>Profile switcher not showing family members</strong> &mdash; Add family members first via My Profile &rarr; Family Members.</li>
      <li><strong>Doctor Visit Report is blank or missing data</strong> &mdash; Ensure you have dose logs within the selected date range. Pain charts only appear for medications with <em>Track dose feedback</em> enabled.</li>
    </ul>

    <p style="margin-top:2rem;color:var(--color-text-muted,#64748b);font-size:.875rem;">
      Full documentation available in <a href="docs/user-guide.md" target="_blank" rel="noopener"><code>docs/user-guide.md</code></a>.
    </p>
  </section>
</main>
<?php include __DIR__ . '/../includes/bottom-nav.php'; ?>
</body>
</html>
<?php exit; ?>

