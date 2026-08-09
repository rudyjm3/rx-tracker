# RxTracker User Guide

RxTracker is a personal medication tracking app you can install on your phone or use in any browser. It keeps your medication schedule, tracks whether you took each dose, monitors your supply, and sends reminders so you never miss a dose.

---

## Table of Contents

1. [Getting Started & First-Run Setup](#getting-started--first-run-setup)
2. [Dashboard](#dashboard)
3. [Adding a Medication](#adding-a-medication)
4. [Editing or Discontinuing a Medication](#editing-or-discontinuing-a-medication)
5. [Medication Notes & Dose Change History](#medication-notes--dose-change-history)
6. [Marking Doses](#marking-doses)
7. [Snoozing a Dose](#snoozing-a-dose)
8. [Inventory, Refills & Quantity Adjustments](#inventory-refills--quantity-adjustments)
9. [Medication Groups](#medication-groups)
10. [Pain & Mood Tracking](#pain--mood-tracking)
11. [Side Effects](#side-effects)
12. [History & Calendar](#history--calendar)
13. [Export & Doctor Visit Report](#export--doctor-visit-report)
14. [Family Members & Profiles](#family-members--profiles)
15. [Signing In & Google Account](#signing-in--google-account)
16. [Settings](#settings)
17. [Push Notifications](#push-notifications)
18. [Installing as an App (PWA)](#installing-as-an-app-pwa)
19. [Troubleshooting](#troubleshooting)

---

## Getting Started & First-Run Setup

Open RxTracker in your browser and sign in or create an account (email/password, or **Continue with Google** — see [Signing In & Google Account](#signing-in--google-account)).

New accounts go through a one-time setup wizard before reaching the Dashboard:

1. **Medications** — add each medication you take (name, strength, unit, form, type). You can add several before moving on.
2. **Tracking** — for each medication, choose which features to turn on: Reminders, Adherence tracking, and/or Inventory tracking.
3. **Schedule** — set dose times for each medication. Quick-add presets (Morning 8am, Noon, Evening 6pm, Bedtime 10pm) speed this up, or enter custom times and per-time quantities.
4. **Inventory** — for each medication with inventory tracking on, choose one: **Count now** (enter what you currently have), **Estimate from fill** (enter your last fill date and quantity dispensed, and RxTracker calculates an estimate with a confidence label), or **Skip**.
5. **Today** — shown only if any doses were already due earlier today. Check off the doses in a time group and confirm to log them as Taken; leave a dose unchecked (or use the group's Skip button to uncheck the whole group) to leave it unreconciled rather than logged — it won't be recorded as Skipped or Missed. You can also skip this step altogether (a confirmation warns that unreconciled doses won't be marked Missed).
6. **Activate** — click **Activate & go to dashboard** to finish.

If you leave setup partway through, a **Resume setup** banner appears across the app until you complete it. After setup, the Dashboard is the first page you see when you sign in.

---

## Dashboard

The Dashboard is your home base. It shows:

- **Next Dose card** — the next medication coming up, with its dose info, scheduled time, and (for grouped medications) the full list of members in that group.
- **Today's Adherence ring** — a percentage ring showing required doses taken vs. total, plus on-time/late/skipped/missed breakdown.
- **Today's Schedule** — every dose for the day, listed in chronological order, with status buttons for each one.
- **Today's history** — a log of the day's dose activity, sortable newest/oldest, with per-entry **Edit** and **Delete** controls (see [History & Calendar](#history--calendar)).
- **Quick actions** — Add medication, Pain Tracking, Mood & Wellbeing, Manage medications.
- **Medications overview** — active medication count, today's dose counts, and a **View required doses list** button that breaks down every required dose for the day by medication.
- **Notification bell** (top navigation) — a running list of low-supply and out-of-stock alerts, separate from the dismissible refill banner cards described in [Inventory, Refills & Quantity Adjustments](#inventory-refills--quantity-adjustments). Includes an unread badge, "mark all read," per-item dismiss, and a Refill quick-action.
- **Profile banner** — when viewing a family member's profile, a banner reads "Viewing [Name]'s medications" with a **Switch back to Me** button.

Navigate to the Dashboard anytime by clicking **Dashboard** in the navigation bar.

---

## Adding a Medication

Click **Add medication** from the Dashboard or the Medications page to open a **4-step wizard**:

### Step 1 — Medication Info
| Field | Description |
|-------|-------------|
| **Name** | The medication name. Start typing for autocomplete suggestions from DailyMed. |
| **Type** | Choose Prescription, OTC Medication, or Vitamin/Supplement. |
| **Dose amount / Unit** | The quantity per dose and unit (mg, mcg, mL, IU, etc.). |
| **Dose form** | The physical form (tablet, capsule, liquid, inhaler, etc.). Affects the icon shown throughout the app. |
| **Start date** | When you started (or will start) taking this medication. Doses aren't logged as missed before this date. |
| **+ Add End Date** *(optional)* | Reveals a field for a planned end date. |
| **+ Add Notes** *(optional)* | Reveals a free-text notes field. |

### Step 2 — Inventory *(optional)*
| Field | Description |
|-------|-------------|
| **Starting quantity** | How many pills (or mL, puffs, etc.) you currently have. |
| **Quantity per dose** | How much each dose uses (e.g., `1` tablet or `5` mL). |
| **Low supply alert** | Get a warning when supply falls below this amount. |

For **liquid** medications, enter the bottle size in mL or oz instead of a pill count.

### Step 3 — Schedule
| Field | Description |
|-------|-------------|
| **Schedule type** | **Fixed times** — doses at specific times each day (e.g., 8:00 AM, 2:00 PM, 9:00 PM). **Every X hours** — doses at a set interval (e.g., every 8 hours). |
| **Dose times** | *(Fixed times only)* Enter one or more times, comma-separated, in `h:mm AM/PM` format. Example: `8:00 AM, 2:00 PM, 9:00 PM` |
| **Interval hours** | *(Every X hours only)* How many hours between doses (1–24). |
| **First dose time** | *(Every X hours only)* When to take the first dose of the day. |
| **As needed (PRN)** | Check this for medications taken only when needed, not on a fixed schedule. |
| **Medication group** | Assign this medication to an existing group (see [Medication Groups](#medication-groups)) if it's always taken with others at the same time. |

### Step 4 — Feedback *(optional)*
| Field | Description |
|-------|-------------|
| **Track dose feedback** | Choose **Pain level**, **Mood level**, or **Both** to enable a 1–10 rating each time you log a dose. Useful for tracking how a medication affects you over time. |

### Saving drafts
You don't have to finish the wizard in one sitting — click **Save draft** at any step to save your progress and return to it later. Draft medications show a "Draft" badge and a "Step N of 4 — not yet saved" note in your Active medications list, with options to resume or discard them. If you click Cancel or close the wizard without saving a draft, a confirmation warns you that unsaved progress will be lost.

Click **Save Medication** on Step 4 to finish and add it to your active list.

---

## Editing or Discontinuing a Medication

- To **edit**: Go to the **Medications** page → click the edit icon (pencil) on the medication card. Editing uses a single-page form, not the wizard used for adding a medication.
- To **discontinue**: On the Medications page, click **Discontinue Use** on the medication card and choose a reason — *End of regimen*, *Side effects (moderate to severe)*, *Doctor's orders*, or *Other* (requires a comment) — plus an optional comment. The medication moves to the **Inactive** tab and no longer appears on the Dashboard schedule.
- To **reactivate**: Go to the **Inactive** tab and click **Activate**, then choose a reason — *Doctor's orders*, *Symptoms returned*, *Retrying after side effects subsided*, *Restarting regimen*, or *Other* — plus an optional comment.
- Both the Active and Inactive tabs support **filtering** by medication type (Prescription / OTC / Supplement checkboxes). **Drag-to-reorder** is available on the Active tab only.

---

## Medication Notes & Dose Change History

Each medication card has a **View instructions / Notes** button that opens a panel with three distinct things:

- **Instructions** — whatever you entered when adding the medication (e.g., "Take with food").
- **Dose Change History** — an automatic, read-only log of past edits to the dose amount or schedule (including changes made via **Update dose**, see [Inventory, Refills & Quantity Adjustments](#inventory-refills--quantity-adjustments)).
- **Notes** — a separate list of free-form notes you add yourself, each timestamped. You can add a new note at any time, and edit or delete existing ones — useful for keeping a running record between doctor visits.

---

## Marking Doses

Every scheduled dose appears in today's schedule on the Dashboard with three action buttons:

| Button | What it does |
|--------|-------------|
| **Take** | Marks the dose as taken at the current time. If feedback tracking is enabled, you'll be asked for a pain/mood level first. |
| **Skip** | Records that you intentionally skipped this dose. |
| **Snooze** | Delays the reminder by your chosen snooze duration (5–30 minutes). |

### Dose Statuses
- **Taken** — logged on time.
- **Taken late** — logged after the grace period (shown with how many minutes late).
- **Skipped** — marked as intentionally skipped.
- **Missed** — the grace period passed without any action.
- **Snoozed** — postponed; shows "Snoozed until HH:MM".

### The Alarm Overlay
When a dose becomes due, a full-screen alarm overlay rings (sound and/or vibration, per your [Settings](#settings)) with its own Take / Skip / Snooze controls. For a **group** of medications taken together, the overlay lists every member and offers:
- **Take all** / **Skip all** / **Snooze all** — act on the whole group at once.
- **Individual** — expand the group and act on each medication separately.

If multiple medications in the group have feedback tracking enabled, you're stepped through a feedback prompt for each one in sequence, with a progress indicator (e.g. "Ibuprofen (2 of 3)").

### Logging a Dose Outside the Alarm
Use **Log past dose** from a medication's actions menu:
- If the medication has **multiple scheduled times**, a slot picker shows each slot's status (Taken/Skipped/Missed/Overdue/Upcoming). Picking an overdue slot asks whether it was taken on time or late, with a custom time field if late.
- If the medication is **PRN or interval-based** with no fixed slots, a simpler prompt asks what time it was taken.
- Tapping **Take** after the grace period has already elapsed opens a similar prompt automatically, so you can record the actual time along with feedback and notes in one step.

---

## Snoozing a Dose

1. Click **Snooze** on any due dose (or from the alarm overlay).
2. Select how long to delay: 5, 10, 15, or 30 minutes.
3. Click **Snooze**. The reminder will reappear after the chosen time.

The default snooze duration can be changed in **Settings**.

---

## Inventory, Refills & Quantity Adjustments

If you set up inventory tracking, RxTracker automatically deducts from your supply each time you log a dose as taken.

### Supply Indicator
Each medication card on the Medications page shows:
- A color-coded supply bar (green → yellow → red as supply decreases).
- Estimated days of supply remaining.
- A dismissible **refill reminder card** on the Dashboard, Medications, Pain Tracking, and Mood & Wellbeing pages once supply drops below your alert threshold. Swipe (mobile) or tap the **×** to dismiss it — dismissal is remembered only on that device, not synced across devices or to your account.

### When You're Already Out of Stock
If a medication is *already* at or below zero supply when you log a dose against it, a prompt offers:
- **Adjust quantity** — correct the count with a quick +/- stepper.
- **Log a refill** — jump straight to the refill form.
- **Not now** — dismiss and proceed with the dose as normal.
- **Cancel this dose** — fully undoes the dose log and restores your inventory, as if it was never marked taken. Use this if you tapped Take by mistake.

Note: a dose that itself brings your supply down to exactly zero is logged normally without this prompt — this interstitial only appears on doses logged *after* you're already out.

### Logging a Refill
1. On the Medications page, click **Log refill** on the medication card.
2. Enter the refill date, how many pills (or units) you received, and an optional note.
3. Click **Log refill**. Your supply count updates immediately.

### Viewing Refill History
Click **Refill history** on any medication card to see a month-by-month log of all past refills.

### Adjusting Quantity
If your on-hand count drifts from reality (e.g. after a recount or a dropped pill), click **Adjust quantity** on the medication card. Enter the corrected count and an optional reason. This directly overwrites the current supply count — unlike Log Refill, it does not add to inventory or create a refill-history entry. Use Log Refill when you've actually received more medication; use Adjust quantity to correct the number itself.

### Updating the Prescribed Dose
If your doctor changes how much you should take per dose (not how much you have on hand), use **Update dose** from the card's actions menu. Enter the new dose amount/unit and an optional reason (e.g. "Doctor increased dose at last visit"). This is recorded in the medication's Dose Change History.

---

## Medication Groups

Groups let you bundle medications that are always taken together at the same time. When a group reminder fires, all medications in it appear in one alarm.

### Creating a Group
1. Go to **Medications** → **Groups** tab.
2. Click **Create group**.
3. Enter a group name (e.g., "Morning meds") and the shared scheduled time (e.g., `8:00 AM`).
4. Click **Save**.

### Adding a Medication to a Group
On the Groups tab, find your group and use the **Add medication** dropdown to select which medication to add — or assign a group directly from the Schedule step when adding a new medication. **A medication can belong to more than one group**; the dropdown shows "(also in: [group names])" next to medications already assigned elsewhere.

### Group Dose Overrides
When adding a medication to a group, you can set a different **quantity per dose** for that medication specifically within the group (e.g. 2 tablets in the group vs. its default of 1). Each medication in a group keeps its own inventory tracking and feedback settings regardless of group membership.

### Removing a Medication from a Group
Click the **×** remove button next to a medication name within the group. The medication returns to its own independent schedule (or to any other groups it's still a member of).

### Deleting a Group
Click **Delete** on the group card. The group is removed, but all its medications remain active on their individual schedules.

---

## Pain & Mood Tracking

### Feedback Tied to a Dose
When **Track dose feedback** is enabled for a medication (with Pain level, Mood level, or Both selected), you'll see a feedback prompt each time you mark a dose taken:

1. Click **Take** on a dose.
2. The feedback dialog asks for a **pain and/or mood level** from 1 (low) to 10 (severe for pain, excellent for mood).
3. Add an optional note.
4. Click **Log dose** to save both the dose and your feedback.
5. Click **Take without comment** to log the dose without feedback.

### Logging Feedback Independently
Both the **Pain Tracking** page and the **Mood & Wellbeing** page (linked from the Dashboard quick actions) have their own **Log pain** / **Log mood** button for recording a level at any time — not tied to a scheduled dose. This is useful for tracking how you feel between doses. Each entry takes a level (1–10), a date/time, and an optional comment.

### Trend Charts
Both pages show a per-medication line chart of your levels over time, with range tabs (Today / 7 days / 30 days / 90 days). On multi-day views, hover or click a point to drill into that day's detail. The Pain Tracking page's chart also has a **print** button.

### Mood Tags
The Mood & Wellbeing page has its own tagging system: attach one or more tags (e.g. "stressful day", "good sleep") to any mood entry via the **+ Tags** picker, create new tags with **Add tag**, and rename or delete existing ones (with a usage count) via **Manage tags**.

### Settings
In **Settings**, toggle **Teal mood chart** to switch the mood trend line from the default red-to-green gradient to a teal gradient (matches the PDF report).

---

## Side Effects

Click **Log side effect** on any medication card to record: the date (defaults to today), a description, severity (**Mild**, **Moderate**, or **Severe**), and optional notes. Logged side effects are included in the Doctor Visit Report PDF.

---

## History & Calendar

### Dose History
The **Export** page shows a table of all your recent dose logs. You can:
- Filter by a custom date range using the **From** and **To** date inputs.
- See the medication name, scheduled time, actual time taken, status, pain level, and notes.

### Editing & Deleting Log Entries
Any logged dose — whether in the Dashboard's "Today's history" list or a Calendar day's detail view — has **Edit** and **Delete** controls:
- **Edit** lets you change the status (Taken/Skipped/Missed), the actual time taken (if Taken), and the comment.
- **Delete** permanently removes the entry (you'll be asked to confirm — this cannot be undone).

### Calendar View
The **Calendar** page shows a full month view. Each day has a color-coded marker indicating your overall adherence for that day:
- Fully taken days appear in the primary color.
- Days with missed doses appear highlighted in red/warning.
- Future days are shown without markers.

Click a day to open that day's dose log in a detail view, where you can also edit or delete individual entries. Use the **←** and **→** arrows to navigate between months.

---

## Export & Doctor Visit Report

The **Export** page lets you generate a single **Doctor Visit Report** PDF for a chosen date range:

1. Set the **Reporting Period** — a From and To date.
2. Optionally check **Include Mood & Wellbeing tracking** to add mood charts to the report alongside pain charts. Leave it unchecked for a pain-focused report only.
3. Click **Generate & Download PDF**.

The report includes:
- An adherence summary with rings.
- Your current medications (with type badges) and discontinued medications.
- A dose change history summary.
- Missed-dose detail.
- Full dose history for the period.
- Pain level charts for medications with pain feedback tracking enabled.
- Mood & wellbeing charts, if the checkbox was checked.
- A logged side effects section.
- A footer disclaimer.

Filenames reflect the date range selected (e.g. `doctor-visit-report-5-29-2026-thru-6-29-2026.pdf`).

---

## Family Members & Profiles

RxTracker supports multiple profiles so you can track medications for family members from one account.

- **Add a family member**: Go to **My Profile → Family Members**. Enter the name, relationship, birth year (optional), and choose an avatar color (from a preset palette or a custom color picker).
- **Switch profiles**: Click the avatar button in the top navigation to open the profile switcher dropdown, then select a family member. A banner confirms whose profile you're viewing, with a direct **Switch back to Me** button.
- **Switch back**: Open the avatar dropdown and select your own name (shown at the top of the list), or use the banner's switch-back button.
- **Edit or remove a member**: Go to My Profile → Family Members and use the edit/remove buttons on each member card.

From **My Profile** you can also update your display name, change your password, export or delete your account data, and view/revoke active remember-me sessions.

---

## Signing In & Google Account

Sign in with your email/password, or use **Continue with Google** on the login or register page. From **My Profile**, connect or disconnect a Google account at any time — if you disconnect while no password is set, set one first so you don't lose access to your account.

Terms of Service and Privacy Policy pages are linked from the login/register page footers and from the "More" menu in the bottom navigation.

---

## Settings

Access **Settings** from the navigation bar.

### Time Zone
| Setting | Description |
|---------|-------------|
| **Time zone** | Choose your time zone from a region-grouped list, or click **Use device timezone** to detect it automatically from your browser. |

### Reminder Settings
| Setting | Options | Description |
|---------|---------|-------------|
| **Grace period** | 30 min / 60 min | How long after a scheduled time before a dose is automatically marked Missed. |
| **Snooze duration** | 5 / 10 / 15 / 30 min | The default snooze length when you tap Snooze on a reminder. |

### Notifications
| Setting | Description |
|---------|-------------|
| **Sound** | Plays an alert sound when a dose alarm fires while the app is open. Works offline, on by default. |
| **Vibration** | Vibrates the device when an alarm fires (mobile only). On by default. |
| **Background reminders** | Enables push notifications so you receive reminders even when the app is closed. See [Push Notifications](#push-notifications). |
| **Teal mood chart** | Switches the mood trend line from the default red-to-green gradient to a teal gradient (matches the PDF report). |

### Help & Documentation
A card on the Settings page links back to the in-app Help page.

---

## Push Notifications

Push notifications deliver dose reminders in the background, even when your browser or the app is closed.

### Enabling Push Notifications
1. Go to **Settings**.
2. Under **Background Reminders**, toggle the switch to **On**.
3. Your browser will ask for notification permission — click **Allow**.
4. The Push Notification Status panel checks three things, each shown with a pass/fail icon and a remediation hint if it fails:
   - **Service worker registered**
   - **Notification permission granted**
   - **Push subscription active**

5. Once all three pass, click **Send test push** to verify delivery — it reports how many of your devices received it.

### How It Works
A background cron job runs every minute on the server and sends a push notification for any dose that is currently due. Tapping the notification opens RxTracker directly to the Dashboard.

### If Notifications Stop Working
- Check Settings to confirm the push subscription is still active.
- Try toggling Background Reminders off and back on to re-subscribe.
- Make sure your browser has not revoked notification permission (check browser site settings).

---

## Installing as an App (PWA)

RxTracker is a Progressive Web App — you can install it on your phone's home screen for a native app experience.

### On iPhone (Safari)
1. Open RxTracker in Safari.
2. Tap the **Share** button (box with arrow).
3. Scroll down and tap **Add to Home Screen**.
4. Tap **Add** to confirm.

### On Android (Chrome)
1. Open RxTracker in Chrome.
2. Tap the three-dot **Menu** button.
3. Tap **Add to Home Screen** (or **Install app**).
4. Tap **Install** to confirm.

### On Desktop (Chrome / Edge)
1. Look for the install icon in the browser address bar (a computer with a down arrow).
2. Click it and select **Install**.

Once installed, RxTracker opens as a standalone app without browser chrome.

---

## Troubleshooting

### I'm not receiving push notifications
- Check that notification permission is **Allowed** in your browser settings (not Blocked).
- On iPhone, make sure the app is installed to the home screen — Safari push requires the PWA to be installed.
- Verify the cron job (`php scripts/send_due_push.php`) is running on the server.
- Go to Settings and check which of the three push status checks (service worker / permission / subscription) is failing.
- Tap **Send test push** to test the connection.

### A dose shows as Missed even though I took it
- The grace period may have expired before you logged the dose. You can adjust the grace period in Settings (30 or 60 minutes).
- Use **Edit** on the dose history entry (Dashboard's Today's history, or a Calendar day's detail view) to correct the status directly.

### I accidentally marked a dose as taken
- If it dropped your supply to zero, use **Cancel this dose** in the zero-supply prompt to fully undo it and restore your inventory.
- Otherwise, use **Edit** or **Delete** on the dose history entry.

### The supply count is wrong after logging a dose
- Check that **Quantity per dose** is set correctly in the medication's edit form, and that any group dose overrides are set as intended.
- If inventory tracking is not set up, supply won't be tracked. Edit the medication and fill in the Inventory section.
- If the count has simply drifted from reality, use **Adjust quantity** on the card to correct it directly rather than logging a refill.

### The medication autocomplete isn't working
- Autocomplete pulls data from DailyMed and OpenFDA. It requires an internet connection.
- If you're on a slow connection or the APIs are temporarily unavailable, type the name manually.

### The app feels slow or outdated after an update
- Clear the browser/app cache, or force-refresh with `Ctrl+Shift+R` (Windows/Linux) or `Cmd+Shift+R` (Mac).
- On mobile, you may need to remove and reinstall the PWA from your home screen.

### I can't change a medication's schedule type
- Discontinue the medication and create a new one with the correct schedule. This preserves your history for the old schedule.

### A family member isn't showing in the profile switcher
- Add family members first via **My Profile → Family Members**.

### My Doctor Visit Report is missing mood charts
- Make sure you checked **Include Mood & Wellbeing tracking** before generating the PDF — mood charts are opt-in per report.
