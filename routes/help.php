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
  <section class="panel help-panel" style="margin:1.5rem 0;padding-top:1.5rem;padding-bottom:1.5rem;">
    <div class="panel-heading"><h2>Help &amp; User Guide</h2></div>

    <nav class="help-toc" style="margin-bottom:1.5rem;line-height:2;">
      <strong>Jump to:</strong>
      <a href="#help-onboarding">Getting Started</a> &bull;
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

    <h3 id="help-onboarding">Getting Started &amp; First-Run Setup</h3>
    <p>New accounts are walked through a one-time setup wizard before landing on the Dashboard: <strong>Medications</strong> (add what you take), <strong>Tracking</strong> (choose Reminders / Adherence / Inventory per medication), <strong>Schedule</strong> (dose times, with quick-add presets like Morning/Noon/Evening/Bedtime), <strong>Inventory</strong> (count what you have now, estimate from your last fill date, or skip), <strong>Today</strong> (if any doses were already due earlier today, check off the ones you took and confirm to log them as Taken &mdash; unchecked doses are simply left unreconciled, not marked Skipped or Missed), and finally <strong>Activate</strong>. If you leave setup partway through, a <strong>Resume setup</strong> banner appears at the top of the app until you finish it.</p>

    <h3 id="help-dashboard">Dashboard</h3>
    <p>The Dashboard is your home base. It shows your <strong>Next Dose</strong> hero card (with upcoming dose info and group medication details), today&rsquo;s full schedule with Take / Skip / Snooze action buttons, your adherence ring summary for the day, today&rsquo;s dose history (with edit/delete controls, see <a href="#help-history">History &amp; Calendar</a>), and a quick-actions sidebar.</p>
    <p>A <strong>notification bell</strong> in the top navigation shows a running list of low-supply and out-of-stock alerts with a Refill quick-action &mdash; it's separate from the dismissible refill banners described in <a href="#help-inventory">Inventory &amp; Refills</a>. The medications panel's <strong>View required doses list</strong> button opens a breakdown of every required dose for the day, grouped by medication. When you&rsquo;re viewing a family member&rsquo;s profile, a banner reading &ldquo;Viewing [Name]&rsquo;s medications&rdquo; stays pinned at the top with a <strong>Switch back to Me</strong> button.</p>

    <h3 id="help-add-med">Adding a Medication</h3>
    <p>Click <strong>Add medication</strong> on the Dashboard or Medications page to open a 4-step wizard:</p>
    <ul>
      <li><strong>1. Medication Info</strong> &mdash; name (autocomplete from DailyMed), type (Prescription/OTC/Supplement), dose amount &amp; unit, dose form, start date (doses aren&rsquo;t marked missed before this date), and optional <em>+ Add End Date</em> / <em>+ Add Notes</em> toggles.</li>
      <li><strong>2. Inventory</strong> (optional) &mdash; starting quantity, quantity per dose, and a low-supply alert threshold. The supply bar turns yellow below 50% and red below 25%.</li>
      <li><strong>3. Schedule</strong> &mdash; Fixed times (e.g. <code>8:00 AM, 2:00 PM, 9:00 PM</code>) or Every X hours with a first-dose time. Mark <em>As needed (PRN)</em> to exclude from required dose counts. This step is also where you can assign the medication to a <a href="#help-groups">group</a>.</li>
      <li><strong>4. Feedback</strong> (optional) &mdash; choose <em>Pain level</em>, <em>Mood level</em>, or <em>Both</em> to prompt for a 1&ndash;10 rating and optional note after each dose.</li>
    </ul>
    <p>You can leave the wizard at any point with <strong>Save draft</strong> &mdash; the in-progress medication is saved and shows a &ldquo;Draft&rdquo; badge (with a &ldquo;Step N of 4&rdquo; note) in your Active list so you can resume or discard it later. Clicking Cancel or closing the wizard without saving a draft asks you to confirm, since unsaved progress is lost.</p>
    <p>To <strong>edit</strong> a medication: Medications page &rarr; click the edit icon on the card. Unlike Add, editing uses a single-page form rather than the wizard. To <strong>discontinue</strong>: click <em>Discontinue Use</em> on the card and choose a reason &mdash; <em>End of regimen</em>, <em>Side effects (moderate to severe)</em>, <em>Doctor&rsquo;s orders</em>, or <em>Other</em> (with a required comment) &mdash; plus an optional comment. The medication moves to the <em>Inactive</em> tab. To <strong>resume</strong> a discontinued medication: click <em>Activate</em> on its card in the Inactive tab and choose a reason &mdash; <em>Doctor&rsquo;s orders</em>, <em>Symptoms returned</em>, <em>Retrying after side effects subsided</em>, <em>Restarting regimen</em>, or <em>Other</em> &mdash; plus an optional comment. Both are saved to the medication&rsquo;s stop / resume history.</p>
    <p>Each medication&rsquo;s <strong>View instructions / Notes</strong> button opens a panel with three things: the instructions you entered, a <strong>Dose Change History</strong> log of past dose/schedule edits, and a separate list of free-form, timestamped <strong>Notes</strong> you can add, edit, or delete at any time &mdash; useful for jotting down anything you want to remember between doctor visits.</p>

    <h3 id="help-doses">Marking Doses</h3>
    <p>Each scheduled dose on the Dashboard has three action buttons:</p>
    <ul>
      <li><strong>Take</strong> &mdash; marks the dose taken now. Opens a feedback prompt if dose feedback is enabled for that medication.</li>
      <li><strong>Skip</strong> &mdash; records an intentional skip with a confirmation prompt.</li>
      <li><strong>Snooze</strong> &mdash; delays the reminder by your chosen snooze duration (configured in Settings).</li>
    </ul>
    <p>Possible statuses: <em>Taken</em>, <em>Taken late</em> (logged after the grace period), <em>Skipped</em>, <em>Missed</em> (grace period expired with no action), <em>Snoozed until [time]</em>.</p>
    <p>When a dose becomes due, a full-screen <strong>alarm overlay</strong> rings (sound and/or vibration, per your Settings) with its own Take / Skip / Snooze. For a group of medications taken together, the overlay lists every member and lets you <strong>Take all</strong>, <strong>Skip all</strong>, <strong>Snooze all</strong>, or switch to <strong>Individual</strong> mode to act on each one separately. If several members have feedback tracking enabled, you&rsquo;re walked through a short feedback prompt for each one in turn (e.g. &ldquo;Ibuprofen (2 of 3)&rdquo;).</p>
    <p>Forgot to log a dose from an earlier day, or taking something as-needed? Use <strong>Log past dose</strong> from the medication&rsquo;s actions menu. If the medication has multiple scheduled times, a slot picker lets you choose which one, and asks whether it was on time or late (with a custom time field). PRN or interval medications without fixed slots get a simpler &ldquo;what time did you take it&rdquo; prompt instead. Tapping Take after the grace period has already passed opens a similar prompt so you can record the actual time, along with feedback and notes.</p>

    <h3 id="help-inventory">Inventory &amp; Refills</h3>
    <p>RxTracker deducts from your supply each time a dose is logged as taken. A days-remaining estimate appears on the medication card, and a dismissible refill reminder card appears on the Dashboard, Medications, Pain Tracking, and Mood &amp; Wellbeing pages once supply falls below your set threshold &mdash; swipe or tap the &times; to dismiss it (dismissal is remembered only on that device, not synced to your account).</p>
    <p>If a medication is <em>already</em> out of stock (at or below zero) when you log a dose against it, a prompt lets you <strong>Adjust quantity</strong>, jump straight to <strong>Log a refill</strong>, dismiss with <strong>Not now</strong>, or <strong>Cancel this dose</strong> entirely (which fully undoes the dose log and restores your inventory, as if it was never marked taken). A dose that itself brings supply down to exactly zero is logged normally without this prompt &mdash; you&rsquo;ll simply see the supply bar hit empty and the usual low-supply reminder.</p>
    <p>To <strong>log a refill</strong>: Medications &rarr; click <em>Log refill</em> on the card &rarr; enter the date, quantity added, and an optional note. View past refills with the <em>Refill history</em> button on the card.</p>
    <p>If your on-hand count drifts from reality (e.g. after a recount or a dropped pill), use <strong>Adjust quantity</strong> on the card to enter a corrected count and an optional reason. This directly corrects the supply count without adding to inventory or creating a refill-history entry &mdash; use Log refill instead when you&rsquo;ve actually received more medication. To change the prescribed dose amount itself (e.g. your doctor adjusted it), use <strong>Update dose</strong> from the card&rsquo;s actions menu &mdash; this is saved to the medication&rsquo;s Dose Change History.</p>

    <h3 id="help-groups">Medication Groups</h3>
    <p>Groups bundle medications taken at the same time into a single scheduled alarm. Go to <strong>Medications &rarr; Groups tab</strong> to create a group (name + scheduled time) and add medications to it, or assign a group directly from the Schedule step of the Add Medication wizard. Notes:</p>
    <ul>
      <li>A medication can belong to more than one group &mdash; the &ldquo;Add a medication&rdquo; dropdown shows which other group(s) a medication is already in.</li>
      <li>You can set a <strong>group dose override</strong> — a different quantity-per-dose for a specific medication when taken as part of this group (e.g. 2 tablets in the group vs. the default 1).</li>
      <li>Each medication in a group retains its own inventory tracking and feedback settings.</li>
    </ul>

    <h3 id="help-feedback">Pain &amp; Mood Tracking</h3>
    <p>Enable <em>Track dose feedback</em> in the medication form and choose <em>Pain level</em>, <em>Mood level</em>, or <em>Both</em>. After marking a dose taken, you&rsquo;ll be prompted to rate the relevant level(s) 1&ndash;10 and add an optional note.</p>
    <ul>
      <li><strong>Pain Tracking page</strong> and <strong>Mood &amp; Wellbeing page</strong> (both linked from the Dashboard quick actions) are dedicated pages with per-medication trend charts (Today / 7 / 30 / 90 day tabs; hover or click a point on multi-day views to drill into that day) and a <strong>Log pain</strong> / <strong>Log mood</strong> button for recording a level at any time, independent of a scheduled dose.</li>
      <li>The Mood &amp; Wellbeing page also has a <strong>tags</strong> system &mdash; attach one or more tags (e.g. &ldquo;stressful day&rdquo;, &ldquo;good sleep&rdquo;) to any mood entry, and use <em>Manage tags</em> to rename or delete tags you&rsquo;ve created.</li>
      <li>In <strong>Settings</strong>, toggle <strong>Teal mood chart</strong> to switch the mood trend line from the default red-to-green gradient to a teal gradient (matches the PDF report).</li>
    </ul>

    <h3 id="help-sideeffects">Side Effects</h3>
    <p>Click <strong>Log side effect</strong> on a medication card to record a side effect: date (defaults to today), a description, severity (<em>Mild</em>, <em>Moderate</em>, or <em>Severe</em>), and optional notes. Logged side effects are included in the Doctor Visit Report PDF.</p>

    <h3 id="help-history">History &amp; Calendar</h3>
    <p>The <strong>Calendar</strong> page shows a month view with color-coded adherence markers for each day. Click any day to see that day&rsquo;s dose log. Navigate months with the left/right arrows. The <strong>Export</strong> page provides a filterable full dose history table.</p>
    <p>Any logged dose &mdash; in the Dashboard&rsquo;s Today&rsquo;s history list or a Calendar day&rsquo;s detail view &mdash; has <strong>Edit</strong> (change status, actual time, or comment) and <strong>Delete</strong> controls, so you can correct a mistake without contacting support.</p>

    <h3 id="help-export">Export &amp; Doctor Visit Report</h3>
    <p>The <strong>Export</strong> page lets you choose a reporting date range and generate a single <strong>Doctor Visit Report</strong> PDF. Check the <em>Include Mood &amp; Wellbeing tracking</em> box to add mood charts alongside pain charts; leave it unchecked for a pain-only report. The report includes an adherence summary with rings, your current medications (with type badges), discontinued medications, dose changes, missed-dose detail, dose history, pain level charts, and &mdash; if checked &mdash; mood &amp; wellbeing charts, plus a footer disclaimer.</p>
    <p>Filenames reflect the date range selected (e.g. <code>doctor-visit-report-5-29-2026-thru-6-29-2026.pdf</code>).</p>

    <h3 id="help-family">Family Members &amp; Profiles</h3>
    <p>RxTracker supports multiple profiles so you can track medications for family members from one account.</p>
    <ul>
      <li><strong>Add a family member</strong>: Go to <strong>My Profile &rarr; Family Members</strong> section. Enter the name, relationship, birth year (optional), and choose an avatar color.</li>
      <li><strong>Switch profiles</strong>: Click the avatar button in the top-right navigation to open the profile switcher dropdown. Select a family member to view and manage their medications. A banner at the top of the app confirms whose profile you&rsquo;re viewing, with a <strong>Switch back to Me</strong> button.</li>
      <li><strong>Switching back</strong>: Open the avatar dropdown and select your own name (shown at the top of the list), or use the switch-back button on the banner.</li>
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
    <p>Terms of Service and Privacy Policy pages are linked from the login/register page footers and from the &ldquo;More&rdquo; menu in the bottom navigation.</p>

    <h3 id="help-settings">Settings</h3>
    <ul>
      <li><strong>Time zone</strong> &mdash; choose your time zone from the region-grouped list, or click <em>Use device timezone</em> to detect it automatically.</li>
      <li><strong>Grace period</strong> &mdash; how long (30 or 60 minutes) before a dose is auto-marked Missed if no action is taken.</li>
      <li><strong>Snooze duration</strong> &mdash; default snooze length: 5, 10, 15, or 30 minutes.</li>
      <li><strong>Sound &amp; Vibration</strong> &mdash; toggle in-app alarm sound and vibration for dose reminders. Both work offline and are on by default.</li>
      <li><strong>Background Reminders</strong> &mdash; enables push notifications so you receive alerts even when the app is closed (see Notifications below).</li>
      <li><strong>Teal mood chart</strong> &mdash; switches the mood trend line to a teal gradient instead of the default red-to-green.</li>
    </ul>

    <h3 id="help-push">Push Notifications</h3>
    <p>Go to <strong>Settings &rarr; Background Reminders</strong> and toggle it on. When your browser prompts for permission, click <em>Allow</em>. The status panel checks three things &mdash; <strong>service worker registered</strong>, <strong>notification permission granted</strong>, and <strong>push subscription active</strong> &mdash; each shown with a pass/fail icon and a remediation hint. Once all three pass, use <em>Send test push</em> to verify delivery. Important notes:</p>
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
      <li><strong>No push notifications</strong> &mdash; Check browser notification permission is set to <em>Allow</em>. On iPhone, the PWA must be installed to the home screen. Verify the server-side cron job is active. Check Settings for which of the three status checks is failing.</li>
      <li><strong>Dose shows Missed despite taking it</strong> &mdash; The grace period expired before you logged it. Increase the grace period in Settings, or use <em>Edit</em> on the dose history entry to correct its status.</li>
      <li><strong>Supply count is wrong</strong> &mdash; Check that <em>Quantity per dose</em> is set correctly in the medication edit form, and that any group dose overrides are set as intended. If the count has simply drifted from reality, use <em>Adjust quantity</em> on the card to correct it directly.</li>
      <li><strong>Accidentally marked a dose taken</strong> &mdash; If it dropped your supply to zero, use <em>Cancel this dose</em> in the zero-supply prompt. Otherwise, use <em>Edit</em> or <em>Delete</em> on the dose history entry.</li>
      <li><strong>Autocomplete not working</strong> &mdash; Requires internet access to DailyMed. Type the medication name manually if offline.</li>
      <li><strong>App feels outdated after an update</strong> &mdash; Force-refresh: Ctrl+Shift+R (Windows/Linux) or Cmd+Shift+R (Mac). If that doesn&rsquo;t help, clear the site data in browser settings.</li>
      <li><strong>Profile switcher not showing family members</strong> &mdash; Add family members first via My Profile &rarr; Family Members.</li>
      <li><strong>Doctor Visit Report is blank or missing data</strong> &mdash; Ensure you have dose logs within the selected date range. Pain and mood charts only appear for medications with <em>Track dose feedback</em> enabled, and mood charts only appear when the &ldquo;Include Mood &amp; Wellbeing tracking&rdquo; box is checked.</li>
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
