Show more
Going off the other chat in this project. I want to make a
quick medication tracking and reminder system to help
with taking medicine/s on time.
Basic outline:
User adds meds that are taking. Dashboard shows next up
coming med and dose. Alarm goes off to reminder user to
take meds at certain times. When alarm happens the user
will click meds was taken or skipped button. If skipped is
clicked warning confirmation message asking if your sure.
As the user takes there meds, the remaining pill count for
the med will update. When perscriptions are getting low
Going off the other chat in this project. I want to make a
quick medication tracking and reminder system to help
with taking medicine/s on time.
Basic outline:
User adds meds that are taking. Dashboard shows next up
coming med and dose. Alarm goes off to reminder user to
take meds at certain times. When alarm happens the user
will click meds was taken or skipped button. If skipped is
clicked warning confirmation message asking if your sure.
As the user takes there meds, the remaining pill count for
the med will update. When perscriptions are getting low
Thought for 5s
I’ll shape this into a practical MVP plan: core screens, data structure, reminder logic, pillcount math, and a few safety/privacy considerations so it can become a clean first build.
This is a strong quick MVP for the Mood + Med Tracker project. I’d build it as a
Medication Reminder + Dose Log first, then expand into mood/med effectiveness
tracking later.
Core MVP Screens
1. Dashboard
Show:
Next medication due
Medication name + dose
Time due
Buttons: Taken / Skipped
Remaining pill count
Low refill warning
Missed dose count
On-time dose count
Example dashboard cards:
Add Medication Form
I’d include these fields:
For medication autocomplete, use RxNorm/RxTerms. RxNorm has autocomplete-style
display terms, and RxTerms is designed for user-friendly drug name/strength/form
selection.
Main Data Tables / Objects
medications
Next Dose
Adderall XR — 20mg
Due at 8:00 AM
[ Taken ] [ Skipped ]
Pill Count
12 pills remaining
Refill soon — estimated 4 days left
Stats This Week
Taken on time: 12
Missed/skipped: 2
Medication Name
Dosage Amount
Dosage Unit: mg, ml, tablet, capsule, injection, etc.
Directions: Take 1 tablet by mouth...
Times Per Day
Dose Times: 8:00 AM, 2:00 PM, etc.
Fill Quantity
Pills Per Dose
Fill Date
Refill Date / Next Fill Date
Has Refills? Yes/No
Number of Refills Remaining
Prescribed For
Prescribing Doctor
Pharmacy Name
Pharmacy Location / Phone
Notes
Active / Inactive
Lister Hill Center +1
medication_schedules
dose_logs
Reminder Logic
{
 id: 1,
 name: "Adderall XR",
 dosage: "20mg",
 prescribed_for: "ADHD",
 doctor_name: "Dr. Smith",
 pharmacy_name: "CVS",
 pharmacy_location: "Maryville, TN",
 fill_quantity: 30,
 current_quantity: 12,
 pills_per_dose: 1,
 fill_date: "2026-05-01",
 refill_date: "2026-05-25",
 refills_remaining: 2,
 is_active: true
}
{
 id: 1,
 medication_id: 1,
 dose_time: "08:00",
 dose_amount: "1 capsule",
 days_of_week: ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"]
}
{
 id: 1,
 medication_id: 1,
 scheduled_time: "2026-05-02 08:00:00",
 action_time: "2026-05-02 08:04:00",
 status: "taken", // taken, skipped, missed
 was_on_time: true
}
JavaScript
JavaScript
JavaScript
Mood and Med Tracker
When a dose time arrives:
1. Show alarm/notification.
2. User chooses Taken or Skipped.
3. If Taken:
Create dose log.
Reduce pill count by pills_per_dose .
Mark as on-time or late.
4. If Skipped:
Show confirmation:
5. If user does nothing after a set time, mark it as missed.
Pill Count Logic
Simple formula:
Example:
Then calculate estimated days left:
Refill Warning Rules
Show warnings when:
Are you sure you want to skip this dose?
Skipping medication may affect your treatment plan.
[Cancel] [Yes, Skip Dose]
current_quantity = current_quantity - pills_per_dose;
30 pills filled
1 pill per day
After 18 taken doses = 12 pills remaining
days_left = current_quantity / daily_dose_total;
Pill count is below 7 days remaining
OR
JavaScript
JavaScript
Warning examples:
Calendar View
Calendar should show:
Green dot = dose taken
Red dot = missed/skipped dose
Blue dot = upcoming dose
Orange marker = refill date
Clicking a day could show:
Features I’d Add
The best additions:
Snooze reminder: remind again in 10/15/30 minutes.
Late dose tracking: taken, but late.
Medication history: dosage changes over time.
Inactive medications: keep old meds without deleting history.
Export/share med list: printable PDF or shareable screen for doctor/family.
Side effect / feedback log: “How did you feel after taking this?”
Mood correlation later: compare mood logs with medication adherence.
MVP Build Order
Build in this order:
1. Add/edit medication form
2. Medication list
Refill date is within 5–7 days
Refill Soon
You have about 5 days of Adderall XR left.
Upcoming Fill Date
Your next fill date is May 25, 2026.
May 2, 2026
8:00 AM — Adderall XR 20mg — Taken
2:00 PM — Sertraline 50mg — Upcoming
Refill Reminder — Adderall XR on May 25
3. Dose schedule setup
4. Dashboard next-dose card
5. Taken/skipped dose logging
6. Pill count update
7. Refill warning
8. Missed/on-time stats
9. Calendar view
10. Export/share med list
Important Safety Note
The app should avoid telling users whether they should take, skip, or change medication. It
should only track what their doctor prescribed and show reminders. For skipped doses, the
app can encourage them to follow their prescription label or contact their
doctor/pharmacist.
This could be a very good first standalone module before building the larger ADHD/mood
tracker.
Sources
No this is an idea on its own
Got it — and honestly, that’s a smart move. This works really well as a standalone app. In
fact, keeping it focused makes it more useful and easier to launch.
Let me help you shape it like a real product instead of just a feature set.
🧠 Product Direction (Keep It Focused)
This is not a “health tracker.”
This is:
Think:
A dead-simple, reliable medication reminder + adherence tracker.
Fast
Zero friction
No clutter
Trustworthy
💡 Product Identity (Name Ideas)
Since it’s standalone, branding matters more.
Some solid directions:
Clean / Professional
DoseTrack
MedLog
PillTrack
RxRoutine
DoseFlow
Slightly more modern
MedPulse
DoseSync
PillPilot
RxFlow
MediCue
👉 My top picks for what you're building:
DoseTrack or PillPilot
🎯 Core Value Proposition
What makes this different from other apps:
Most medication apps are:
bloated
confusing
too clinical
or too “health app” heavy
Yours should be:
⚙️ Core Feature Set (Tight MVP)
You already nailed most of this — I’ll refine it into a clean product scope
1. Smart Dashboard (Main Screen)
Next medication due (PRIMARY focus)
Countdown timer (nice touch)
Quick action buttons:
✅ Taken
⚠️ Skipped
Secondary info:
Remaining pills
Low refill warning
Today’s adherence (simple % or count)
2. Medication Setup
Keep this simple — don’t overwhelm users.
Required:
Medication name
Dosage
Times per day (or exact times)
Pill quantity
Pills per dose
Optional (expandable section):
Doctor
Pharmacy
Prescribed for
Refill info
“Open app → see what to take → tap one button → done”
👉 Smart UX move:
Progressive disclosure (hide advanced fields by default)
3. Reminder System (Core Engine)
This is the most important part of the app.
Local notifications (must work offline)
Exact scheduled times
Snooze option
Persistent reminder until action taken
Interaction flow:
4. Dose Logging
Each interaction logs:
Taken
Skipped
Missed (auto)
Also track:
On-time vs late
5. Pill Count + Refill Logic
Automatic:
Decrease on “Taken”
Warning when low
Simple UX:
Notification fires →
User taps →
App opens →
[ Taken ] [ Skipped ]
6. Calendar / History View
Keep it clean:
Day view or monthly grid
Visual indicators:
✅ Taken
❌ Missed
⚠️ Skipped
👉 No over-detail — just clarity
🔥 Features That Make It Stand Out
These are small but powerful:
1. “Are you sure?” Skip Confirmation
This is actually huge UX value
2. Adherence Score
Simple but motivating.
3. Smart Refill Prediction
Instead of just dates:
⚠️ 5 days left of medication
Refill recommended soon
This week: 92% adherence
You’ll run out in 4 days based on usage
4. Ultra-Fast Interaction
Goal:
Open app → log dose in under 2 seconds
🧱 Tech Stack (Based on Your Skills)
Since you're strong in:
HTML / CSS / JS
PHP / MySQL
Option 1 (Fastest MVP - Web App / PWA)
Frontend: Vanilla JS (or light framework)
Backend: PHP + MySQL
Notifications: Service Worker (PWA)
👉 This lets you install it like an app
Option 2 (Better Long-Term)
Next.js + Supabase
Push notifications via Firebase
🧠 Data Model (Simplified)
Keep it lean:
Medications
id
name
dosage
pills_per_dose
current_quantity
refill_date
Schedules
medication_id
time_of_day
Logs
medication_id
scheduled_time
status (taken/skipped/missed)
actual_time
⚠️ Real Talk (Important)
This idea is good — but here’s the truth:
👉 Medication reminder apps already exist.
So if you just build “another one,” it won’t stand out.
What makes yours win:
Speed
Simplicity
Better UX
Cleaner design
No friction
🚀 Positioning Strategy
Instead of:
❌ “Medication tracking app”
Go with:
🧩 Expansion Path (After MVP)
Once it's working:
“The fastest way to track your medication — no clutter, no confusion.”
Mood tracking (connect back to your original idea)
Side effect tracking
Doctor share/export
Family/caregiver mode
Multiple users (kids/elder care)
👍 My Take
This is a great standalone app idea for you because:
Matches your current skill level
Strong real-world use
Easy to demo in a portfolio
Can evolve into a bigger product later
If you want next, I can:
Design the exact UI layout (mobile-first screens)
Or map this into a step-by-step build plan with code structure
Or help you turn this into a portfolio-ready project that stands out to employers
Just tell me 👍
I would want the Tech Stack to be:
HTML / CSS / JS
PHP / MySQL. Map this into a step by step build plan.
Mobile first UI
Thought for a couple of seconds
Absolutely. For HTML / CSS / JS + PHP / MySQL, I’d build this as a mobile-first PWA-style
web app.
Medication Tracker Build Plan
Phase 1 — Project Setup
Folder structure
Phase 2 — Database Tables
medications
Stores the medication info.
med-tracker/
│
├── index.php
├── dashboard.php
├── medications.php
├── add-medication.php
├── calendar.php
├── history.php
│
├── assets/
│ ├── css/
│ │ └── style.css
│ ├── js/
│ │ ├── app.js
│ │ ├── dashboard.js
│ │ ├── medication-form.js
│ │ └── reminders.js
│
├── api/
│ ├── db.php
│ ├── get_medications.php
│ ├── save_medication.php
│ ├── update_medication.php
│ ├── get_next_dose.php
│ ├── log_dose.php
│ ├── get_dashboard_stats.php
│ ├── get_calendar_data.php
│ └── mark_missed_doses.php
CREATE TABLE medications (
 id INT AUTO_INCREMENT PRIMARY KEY,
 medication_name VARCHAR(150) NOT NULL,
SQL
medication_schedules
Stores dose times.
dose_logs
Stores taken/skipped/missed history.
 dosage_amount VARCHAR(50) NOT NULL,
 dosage_unit VARCHAR(50),
 prescribed_for VARCHAR(150),
 doctor_name VARCHAR(150),
 pharmacy_name VARCHAR(150),
 pharmacy_location VARCHAR(255),
 fill_quantity INT NOT NULL,
 current_quantity INT NOT NULL,
 pills_per_dose DECIMAL(5,2) DEFAULT 1,
 fill_date DATE,
 refill_date DATE,
 has_refills TINYINT(1) DEFAULT 0,
 refills_remaining INT DEFAULT 0,
 is_active TINYINT(1) DEFAULT 1,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE medication_schedules (
 id INT AUTO_INCREMENT PRIMARY KEY,
 medication_id INT NOT NULL,
 dose_time TIME NOT NULL,
 dose_amount VARCHAR(100),
 is_active TINYINT(1) DEFAULT 1,
 FOREIGN KEY (medication_id) REFERENCES medications(id) ON DELETE CASCADE
);
CREATE TABLE dose_logs (
 id INT AUTO_INCREMENT PRIMARY KEY,
 medication_id INT NOT NULL,
 schedule_id INT NOT NULL,
 scheduled_datetime DATETIME NOT NULL,
 action_datetime DATETIME,
 status ENUM('taken', 'skipped', 'missed') NOT NULL,
 was_on_time TINYINT(1) DEFAULT 0,
 notes TEXT,
SQL
SQL
Phase 3 — Mobile-First UI Screens
1. Dashboard
Main screen.
Mobile layout:
Cards stacked vertically
Large buttons
Sticky bottom nav
Clear refill warning card
Next dose card is biggest
2. Add Medication Screen
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (medication_id) REFERENCES medications(id),
 FOREIGN KEY (schedule_id) REFERENCES medication_schedules(id)
);
--------------------------------
Good morning, Rudy
Next Dose
Adderall XR
20mg — 1 capsule
Due at 8:00 AM
[ Taken ] [ Skipped ]
Pill Count
12 remaining
Refill soon
Today
Taken: 2
Skipped: 0
Missed: 1
--------------------------------
Bottom Nav:
Dashboard | Meds | Calendar | History
Use sections so the form does not feel overwhelming.
Basic Info
Schedule
Prescription / Refill
Provider Info
3. Medication List
Medication Name
Dosage Amount
Dosage Unit
Prescribed For
How many times per day?
Dose Times
Pills per dose
Fill Quantity
Fill Date
Refill Date
Has Refills?
Refills Remaining
Doctor Name
Pharmacy Name
Pharmacy Location
My Medications
Adderall XR
20mg — 8:00 AM
12 pills left
[Edit]
Sertraline
50mg — 9:00 PM
4. Calendar View
Clicking a date shows dose details.
Phase 4 — Build Order
Step 1 — Create the Database
Create:
1. medications
2. medication_schedules
3. dose_logs
Then create api/db.php .
24 pills left
[Edit]
May 2026
Sun Mon Tue Wed Thu Fri Sat
Green = Taken
Red = Missed
Yellow = Skipped
Blue = Refill Date
<?php
$host = 'localhost';
$dbname = 'med_tracker';
$username = 'root';
$password = '';
try {
 $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
 $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
 echo json_encode(['success' => false, 'message' => 'Database connection failed']);
PHP
Step 2 — Build the Mobile Shell
Create:
Header
Main container
Card component styling
Bottom nav
CSS base:
 exit;
}
body {
 margin: 0;
 font-family: system-ui, sans-serif;
 background: #f6f7fb;
 color: #1f2937;
}
.app-container {
 max-width: 480px;
 margin: 0 auto;
 min-height: 100vh;
 padding: 16px;
 padding-bottom: 80px;
}
.card {
 background: #fff;
 border-radius: 18px;
 padding: 18px;
 margin-bottom: 16px;
 box-shadow: 0 8px 24px rgba(0,0,0,0.06);
}
.bottom-nav {
 position: fixed;
 bottom: 0;
 left: 50%;
 transform: translateX(-50%);
 max-width: 480px;
 width: 100%;
CSS
Step 3 — Add Medication Form
Build add-medication.php .
Then JS collects form data and sends it to:
Important behavior:
User chooses number of times per day
JS dynamically creates dose time inputs
Save medication first
Save dose times second
Step 4 — Display Medication List
Build:
Show:
Medication name
Dosage
Remaining quantity
Refill warning
Active/inactive status
Step 5 — Calculate Next Dose
Build:
 background: #fff;
 border-top: 1px solid #e5e7eb;
 display: flex;
 justify-content: space-around;
 padding: 10px 0;
}
api/save_medication.php
api/get_medications.php
Logic:
1. Get all active medication schedules.
2. Compare dose time to current time.
3. Find the closest upcoming dose.
4. If none left today, show first dose tomorrow.
Dashboard uses this endpoint.
Step 6 — Taken / Skipped Buttons
Build:
When user clicks Taken:
Insert row into dose_logs
Set status to taken
Reduce current_quantity
Check if taken within allowed window
Example on-time rule:
When user clicks Skipped:
Show confirmation modal first
If confirmed, insert skipped log
Do not reduce pill count
Step 7 — Missed Dose Logic
Create:
A dose becomes missed when:
api/get_next_dose.php
api/log_dose.php
On time = taken within 30 minutes before or after scheduled time
api/mark_missed_doses.php
Run this:
When dashboard loads
Before showing stats
Later with cron job if needed
Step 8 — Dashboard Stats
Build:
Return:
Show simple cards:
Step 9 — Refill Warning Logic
scheduled time passed by more than 2 hours
AND
no taken/skipped log exists
api/get_dashboard_stats.php
{
 "taken_today": 2,
 "skipped_today": 0,
 "missed_today": 1,
 "taken_week": 12,
 "missed_week": 3,
 "on_time_week": 10
}
Today
Taken 2
Skipped 0
Missed 1
This Week
On Time 10
Missed 3
JSON
Show warning when:
Formula:
Warning levels:
Step 10 — Calendar View
Build:
Calendar data should include:
Taken doses
Skipped doses
Missed doses
Refill dates
Example:
current_quantity <= 7 days of doses
OR refill_date is within 7 days
dailyUsage = pillsPerDose * timesPerDay;
daysLeft = currentQuantity / dailyUsage;
7 days left = Refill Soon
3 days left = Low Supply
0 days left = Out of Medication
calendar.php
api/get_calendar_data.php
{
 "2026-05-07": {
 "taken": 2,
 "skipped": 0,
 "missed": 1,
 "refill": false
JavaScript
JSON
Recommended MVP Navigation
Bottom nav should include:
MVP Version 1 Feature List
Build only this first:
Add medication
Add dose times
View medication list
Dashboard next dose
Taken button
Skipped button with warning
Pill count deduction
Low supply warning
Missed dose count
Calendar markers
Avoid these until later:
Login system
Medication API autocomplete
Doctor sharing
PDF export
Family accounts
Push notifications across devices
 }
}
Dashboard
Medications
Add Medication
Calendar
History
Home | Meds | Add | Calendar
Best First Build Goal
Your first working version should answer:
That’s the heart of the app.
What do I take next?
Did I take it?
How many pills are left?
Am I about to run out?
I need a way for a user to have this on there phone and
get something similar to push notifications for the
medication reminder alarms without this actually being an
android app. How can this be achieved?
Thought for 6s
I’ll break down the realistic options for a web/PWA build, especially what works on
Android Chrome and iPhone Safari, because notification support has changed over time.
Yes — build it as a PWA, not a native Android app.
The setup would be:
Best Option: PWA + Web Push
A Progressive Web App can be “installed” on the user’s phone from the browser and can
send system-level notifications using the Push API, Notifications API, and a Service
HTML/CSS/JS frontend
PHP/MySQL backend
PWA installable to phone
Service Worker
Web Push Notifications
Worker. The Push API lets the app receive server-sent messages even when the web app is
not open.
Phone Support
Android
This works well on Android through Chrome/Edge.
The user would:
iPhone
This can also work on iPhone, but with an important rule:
The user must install the web app to their Home Screen first. Apple added Web Push
support for Home Screen web apps in iOS/iPadOS 16.4+.
So the iPhone flow is:
What You Need to Build
1. PWA Manifest
Create:
This makes the site installable.
MDN Web Docs +1
Open website
Tap “Add to Home Screen”
Allow notifications
Receive medication reminders like app notifications
WebKit +1
Open in Safari
Share button
Add to Home Screen
Open installed app
Allow notifications
Receive reminders
manifest.json
JSON
2. Service Worker
Create:
This receives push events and displays notifications.
{
 "name": "DoseTrack",
 "short_name": "DoseTrack",
 "start_url": "/dashboard.php",
 "display": "standalone",
 "background_color": "#ffffff",
 "theme_color": "#2563eb",
 "icons": [
 {
 "src": "/assets/icons/icon-192.png",
 "sizes": "192x192",
 "type": "image/png"
 },
 {
 "src": "/assets/icons/icon-512.png",
 "sizes": "512x512",
 "type": "image/png"
 }
 ]
}
service-worker.js
self.addEventListener("push", event => {
 const data = event.data ? event.data.json() : {};
 const title = data.title || "Medication Reminder";
 const options = {
 body: data.body || "It is time to take your medication.",
 icon: "/assets/icons/icon-192.png",
 badge: "/assets/icons/icon-192.png",
 data: {
 url: "/dashboard.php"
 }
 };
 event.waitUntil(
 self.registration.showNotification(title, options)
JavaScript
3. Ask Permission
In JS:
4. Store Push Subscription in MySQL
Create a table:
 );
});
self.addEventListener("notificationclick", event => {
 event.notification.close();
 event.waitUntil(
 clients.openWindow(event.notification.data.url)
 );
});
async function enableNotifications() {
 if (!("serviceWorker" in navigator)) return;
 if (!("PushManager" in window)) return;
 const registration = await navigator.serviceWorker.register("/service-worker.js");
 const permission = await Notification.requestPermission();
 if (permission !== "granted") {
 alert("Notifications were not enabled.");
 return;
 }
 // Subscribe user to push notifications here
}
CREATE TABLE push_subscriptions (
 id INT AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 endpoint TEXT NOT NULL,
 p256dh TEXT NOT NULL,
 auth TEXT NOT NULL,
 device_name VARCHAR(100),
JavaScript
SQL
5. Server Sends Reminder
Your PHP backend would check upcoming doses and send a push notification when it’s
time.
You would likely use a PHP Web Push library such as:
The flow:
Important Limitation
Web push is good, but it may not be as guaranteed as a native alarm app. Phones can
delay notifications because of battery-saving settings, poor signal, browser limitations, or
notification permissions.
So for medication reminders, I would add a backup:
Best Build Approach for Your Stack
For your app, I’d do this:
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
minishlink/web-push
Medication schedule stored in MySQL
PHP cron job checks due meds every minute
If dose is due, send push notification
Service worker receives it
Phone shows reminder
User taps notification
App opens dashboard
User taps Taken or Skipped
Primary: Web Push notification
Backup: In-app reminder when app opens
Optional: Email/SMS reminder later
Version 1:
PWA install
Local in-app reminders while app is open
Basic browser notifications
Simple Answer
Yes, this can be achieved without making an Android app.
Build it as an installable PWA with Web Push Notifications. It will feel close to a phone
app, especially on Android, and it can also work on newer iPhones when installed to the
Home Screen.
Version 2:
Web Push with service worker
PHP cron job
MySQL push subscription table
Version 3:
SMS backup reminders
Caregiver/family notification option
Sources