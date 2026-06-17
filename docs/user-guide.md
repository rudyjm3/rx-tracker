# RxTracker User Guide

RxTracker is a personal medication tracking app you can install on your phone or use in any browser. It keeps your medication schedule, tracks whether you took each dose, monitors your supply, and sends reminders so you never miss a dose.

---

## Table of Contents

1. [Getting Started](#getting-started)
2. [Dashboard](#dashboard)
3. [Adding a Medication](#adding-a-medication)
4. [Marking Doses](#marking-doses)
5. [Snoozing a Dose](#snoozing-a-dose)
6. [Inventory & Refills](#inventory--refills)
7. [Medication Groups](#medication-groups)
8. [Pain & Feedback Tracking](#pain--feedback-tracking)
9. [History & Calendar](#history--calendar)
10. [Export & Print](#export--print)
11. [Settings](#settings)
12. [Push Notifications](#push-notifications)
13. [Installing as an App (PWA)](#installing-as-an-app-pwa)
14. [Troubleshooting](#troubleshooting)

---

## Getting Started

Open RxTracker in your browser. The first page you see is the **Dashboard**, which shows your next scheduled dose and today's full medication schedule.

No account or login is required to start using RxTracker.

---

## Dashboard

The Dashboard is your home base. It shows:

- **Next Dose card** — the next medication coming up, with its dose info and scheduled time.
- **Today's Schedule** — every dose for the day, listed in chronological order, with status buttons for each one.
- **Adherence summary** — how many doses you've taken vs. missed today.
- **Recent history** — a quick-reference log of your most recent dose activity.

Navigate to the Dashboard anytime by clicking **Dashboard** in the navigation bar.

---

## Adding a Medication

1. From the Dashboard or the Medications page, click **Add medication**.
2. Fill in the medication form:

### Basic Info
| Field | Description |
|-------|-------------|
| **Name** | The medication name. Start typing for autocomplete suggestions from DailyMed. |
| **Type** | Choose Prescription, OTC Medication, or Vitamin/Supplement. |

### Dose
| Field | Description |
|-------|-------------|
| **Dose amount** | The quantity per dose (e.g., `500`). |
| **Unit** | The unit for the dose (mg, mcg, mL, IU, etc.). |
| **Form** | The physical form (tablet, capsule, liquid, inhaler, etc.). |

### Schedule
| Field | Description |
|-------|-------------|
| **Schedule type** | **Fixed times** — doses at specific times each day (e.g., 8:00 AM, 2:00 PM, 9:00 PM). **Every X hours** — doses at a set interval (e.g., every 8 hours). |
| **Dose times** | *(Fixed times only)* Enter one or more times, comma-separated, in `h:mm AM/PM` format. Example: `8:00 AM, 2:00 PM, 9:00 PM` |
| **Interval hours** | *(Every X hours only)* How many hours between doses (1–24). |
| **First dose time** | *(Every X hours only)* When to take the first dose of the day. |
| **As needed (PRN)** | Check this for medications taken only when needed, not on a fixed schedule. |

### Inventory Tracking *(optional)*
Expand this section to track how much medication you have left.

| Field | Description |
|-------|-------------|
| **Inventory type** | Pills, liquid, inhaler, injection, patch, drops, or other. |
| **Starting quantity** | How many pills (or mL, puffs, etc.) you currently have. |
| **Quantity per dose** | How much each dose uses (e.g., `1` tablet or `5` mL). |
| **Low supply alert** | Get a warning when supply falls below this amount. |

For **liquid** medications, enter the bottle size in mL or oz instead of a pill count.

### Other Options
| Field | Description |
|-------|-------------|
| **Track dose feedback** | Enables a pain/symptom rating (1–10) each time you log a dose. Useful for tracking how a medication affects you over time. |
| **Instructions** | Any notes about how to take the medication (e.g., "Take with food"). |
| **Medication group** | Assign to a group if this medication is always taken with others at the same time. |

3. Click **Save medication** when done.

---

## Editing or Deactivating a Medication

- To **edit**: Go to the **Medications** page → click the edit icon (pencil) on the medication card.
- To **deactivate**: On the Medications page, click **Deactivate** on the medication card. The medication moves to the **Inactive** tab and no longer appears on the Dashboard schedule.
- To **reactivate**: Go to the **Inactive** tab and click **Activate**.

---

## Marking Doses

Every scheduled dose appears in today's schedule on the Dashboard with three action buttons:

| Button | What it does |
|--------|-------------|
| **Take** | Marks the dose as taken at the current time. If feedback tracking is enabled, you'll be asked for a pain level first. |
| **Skip** | Records that you intentionally skipped this dose. |
| **Snooze** | Delays the reminder by your chosen snooze duration (5–30 minutes). |

### Dose Statuses
- **Taken** — logged on time.
- **Taken late** — logged after the grace period (shown with how many minutes late).
- **Skipped** — marked as intentionally skipped.
- **Missed** — the grace period passed without any action.
- **Snoozed** — postponed; shows "Snoozed until HH:MM".

### Log a Past Dose
If you took a medication but forgot to log it, use **Log dose now** on the Medications page. If a medication has multiple scheduled times, you'll be asked which time slot to log it to.

---

## Snoozing a Dose

1. Click **Snooze** on any due dose.
2. Select how long to delay: 5, 10, 15, or 30 minutes.
3. Click **Snooze**. The reminder will reappear after the chosen time.

The default snooze duration can be changed in **Settings**.

---

## Inventory & Refills

If you set up inventory tracking, RxTracker automatically deducts from your supply each time you log a dose as taken.

### Supply Indicator
Each medication card on the Medications page shows:
- A color-coded supply bar (green → yellow → red as supply decreases).
- Estimated days of supply remaining.
- A **refill alert** banner when supply drops below your alert threshold.

### Logging a Refill
1. On the Medications page, click **Log refill** on the medication card.
2. Enter the refill date, how many pills (or units) you received, and an optional note.
3. Click **Log refill**. Your supply count updates immediately.

### Viewing Refill History
Click **Refill history** on any medication card to see a month-by-month log of all past refills.

---

## Medication Groups

Groups let you bundle medications that are always taken together at the same time. When a group reminder fires, all medications in it appear in one alarm.

### Creating a Group
1. Go to **Medications** → **Groups** tab.
2. Click **Create group**.
3. Enter a group name (e.g., "Morning meds") and the shared scheduled time (e.g., `8:00 AM`).
4. Click **Save**.

### Adding a Medication to a Group
On the Groups tab, find your group and use the **Add medication** dropdown to select which medication to add. A medication can only belong to one group at a time.

### Removing a Medication from a Group
Click the **×** remove button next to a medication name within the group. The medication returns to its own independent schedule.

### Deleting a Group
Click **Delete** on the group card. The group is removed, but all its medications remain active on their individual schedules.

---

## Pain & Feedback Tracking

When **Track dose feedback** is enabled for a medication, you'll see a feedback prompt each time you mark a dose taken.

### Logging Feedback
1. Click **Take** on a dose.
2. The feedback dialog asks for a **pain/symptom level** from 1 (low) to 10 (severe).
3. Add an optional note (up to 250 characters).
4. Click **Log dose** to save both the dose and your feedback.
5. Click **Take without comment** to log the dose without feedback.

### Viewing the Pain Trend
On the Medications page, any medication with feedback tracking shows a **Pain trend** button. Click it to see a line chart of your pain levels over time. Use the range tabs (Today / 7 days / 30 days / 90 days) to zoom in or out.

---

## History & Calendar

### Dose History
The **Export** page shows a table of all your recent dose logs. You can:
- Filter by a custom date range using the **From** and **To** date inputs.
- See the medication name, scheduled time, actual time taken, status, pain level, and notes.

### Calendar View
The **Calendar** page shows a full month view. Each day has a color-coded marker indicating your overall adherence for that day:
- Fully taken days appear in the primary color.
- Days with missed doses appear highlighted in red/warning.
- Future days are shown without markers.

Use the **←** and **→** arrows to navigate between months.

---

## Export & Print

The **Export** page displays two printable tables:
1. **Current Medications** — name, dose, schedule, instructions, current supply, refill threshold, days remaining.
2. **Dose History** — complete log with date range filtering.

To print or save as PDF:
1. Go to the Export page.
2. Filter the date range if needed.
3. Click **Print / Save as PDF**.
4. Your browser's print dialog will open. Choose "Save as PDF" in the destination to get a file.

---

## Settings

Access **Settings** from the navigation bar.

### Reminder Settings
| Setting | Options | Description |
|---------|---------|-------------|
| **Grace period** | 30 min / 60 min | How long after a scheduled time before a dose is automatically marked Missed. |
| **Snooze duration** | 5 / 10 / 15 / 30 min | The default snooze length when you tap Snooze on a reminder. |

### Notifications
| Setting | Description |
|---------|-------------|
| **Sound** | Plays an alert sound when a dose alarm fires while the app is open. |
| **Vibration** | Vibrates the device when an alarm fires (mobile only). |
| **Background reminders** | Enables push notifications so you receive reminders even when the app is closed. See [Push Notifications](#push-notifications). |

---

## Push Notifications

Push notifications deliver dose reminders in the background, even when your browser or the app is closed.

### Enabling Push Notifications
1. Go to **Settings**.
2. Under **Background Reminders**, toggle the switch to **On**.
3. Your browser will ask for notification permission — click **Allow**.
4. The status panel will show a checklist of six requirements. All must show a checkmark:
   - VAPID keys configured *(server-side setup)*
   - PHP web-push library installed *(server-side setup)*
   - Service worker registered
   - Notification permission granted
   - Push subscription active
   - Cron job scheduled *(server-side setup)*

5. Click **Send test notification** to verify everything is working.

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
- Go to Settings and check the push status checklist for any missing requirements.
- Tap **Send test notification** to test the connection.

### A dose shows as Missed even though I took it
- The grace period may have expired before you logged the dose. You can adjust the grace period in Settings (30 or 60 minutes).
- If the dose was genuinely missed, the status is correct. Future improvements may allow retroactive correction.

### The supply count is wrong after logging a dose
- Check that **Quantity per dose** is set correctly in the medication's edit form.
- If inventory tracking is not set up, supply won't be tracked. Edit the medication and fill in the Inventory section.

### The medication autocomplete isn't working
- Autocomplete pulls data from DailyMed and OpenFDA. It requires an internet connection.
- If you're on a slow connection or the APIs are temporarily unavailable, type the name manually.

### The app feels slow or outdated after an update
- Clear the browser/app cache, or force-refresh with `Ctrl+Shift+R` (Windows/Linux) or `Cmd+Shift+R` (Mac).
- On mobile, you may need to remove and reinstall the PWA from your home screen.

### I can't change a medication's schedule type
- Deactivate the medication and create a new one with the correct schedule. This preserves your history for the old schedule.
