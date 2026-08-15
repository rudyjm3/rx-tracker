# RxTracker

Medication tracking and reminder web app built with HTML, CSS3, JavaScript, PHP, and MySQL.

**App name:** RxTracker

## What it does

RxTracker has grown well past its original MVP slice. Today it supports, per user account:

- Email/password and Google sign-in, with optional family member profiles tracked under one account.
- Adding medications (tablets, capsules, liquid, inhaler, injection, patch, drops, or other) via a guided
  wizard, including starting an already-in-progress prescription.
- Fixed-time or interval dosing schedules, plus medication groups that bundle same-time doses into one alarm.
- Taken / Skipped / Missed dose tracking with inventory deduction, refill logging, quantity adjustment, and
  low-supply / out-of-stock notifications with a days-remaining estimate.
- Pain and mood tracking tied to a dose or logged independently, with trend charts and tags.
- Side-effect logging, dose/history editing, a calendar view, and a full data export / Doctor Visit Report PDF.
- Background reminders via web push (installed as a PWA) plus in-app polling and an alarm overlay while a tab is open.
- An in-app Help page (`?page=help`) kept in sync with `docs/user-guide.md`.

See `docs/CODEBASE_AUDIT.md` for the current Feature Traceability Matrix and build-health assessment.

> This project is a tracking aid only and does not provide medical advice or clinical decision support.

## Requirements

- PHP 8.1 or newer with the PDO MySQL extension enabled.
- MySQL 8.0 or compatible MariaDB.
- A local PHP-compatible web server.
- Composer (for web push dependencies).

## Database setup

Create the schema and optional seed data from the repository root:

```bash
mysql -u root -p < database/schema.sql
mysql -u root -p < database/seed.sql
```

The app reads these environment variables, with local defaults shown below:

```bash
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=rx_tracker
DB_USERNAME=root
DB_PASSWORD=
PUSH_VAPID_PUBLIC_KEY=
PUSH_VAPID_PRIVATE_KEY=
PUSH_VAPID_SUBJECT=mailto:you@example.com
# Set to 1 only when deployed behind a trusted reverse proxy (see note below).
TRUST_PROXY=0
```

> **Reverse-proxy deployments:** the login rate limiter identifies clients by
> `REMOTE_ADDR` unless `TRUST_PROXY=1`, in which case it reads the client IP from
> `X-Forwarded-For`. If you run behind a proxy (nginx, Cloudflare, a load
> balancer) and leave `TRUST_PROXY` unset, every request looks like it comes from
> the proxy, so a handful of failed logins can lock out all users behind it. Set
> `TRUST_PROXY=1` in that setup — but only when the proxy is one you control and
> it strips/sets `X-Forwarded-For`, otherwise the header can be spoofed.

Install PHP dependencies:

```bash
composer install
```

## Running locally

Use PHP's built-in server for local development:

```bash
php -S localhost:8000
```

Then open <http://localhost:8000/index.php>.

## Background push notifications

RxTracker now supports web push delivery through a service worker when the app page is closed.

1. Configure `PUSH_VAPID_PUBLIC_KEY`, `PUSH_VAPID_PRIVATE_KEY`, and `PUSH_VAPID_SUBJECT`.
2. Install dependencies: `composer install`.
3. Click `Enable reminders` in the app to subscribe the browser.
4. Run the sender script on a schedule (every minute recommended):

```bash
php scripts/send_due_push.php
```

On Linux/macOS, use cron. On Windows, use Task Scheduler.

## Testing

There's no PHPUnit — syntax-check and test manually:

```bash
php -l index.php
php -l config/database.php
php -l includes/helpers.php
php -l includes/MedicationRepository.php
```

`tests/` holds 16 standalone PHP scripts, each runnable directly against an in-memory SQLite database:

```bash
php tests/MedicationRepositoryTest.php
php tests/OwnershipTest.php
# ...and so on for AdherenceTest, CalendarBackfillTest, DaysUntilRunoutTest, DeleteDoseLogTest,
# EditTakenIntervalDoseTest, GoogleAuthServiceJwksCacheTest, GroupScheduleOverrideTest,
# GroupTakeAllTest, InventorySimulationTest, NextDoseTest, RanOutOnDateTest, RevertDoseTest,
# TrackingStartTest
```

There is no CI configuration in this repository, so these must be run manually before/after a change.

## Project structure

- `index.php` — single front controller: bootstraps includes, runs the schema installer, handles auth
  gating, resolves the active family profile, and dispatches to `routes/`.
- `api-proxy.php` — separate authenticated, rate-limited, allowlisted proxy to the DailyMed/OpenFDA/RxImage
  drug-info APIs (keeps API calls and any future keys server-side).
- `config/` — `database.php` (PDO MySQL factory) and `load_env.php` (`.env` loader).
- `routes/` — one file per page/route (dashboard, medications, family, calendar, export, settings, help,
  auth pages, etc.) plus `actions.php` (POST mutations) and `api.php` (GET/JSON endpoints).
- `includes/` — repositories and shared logic: `MedicationRepository.php` is now a thin facade over
  `ScheduleRepository.php`, `InventoryRepository.php`, `MedicationGroupRepository.php`,
  `StockNotificationRepository.php`, `PushRepository.php`, `AdherenceRepository.php`,
  `AllergyRepository.php`, and others, plus `AuthService.php`, `SessionManager.php`,
  `GoogleAuthService.php`, `MailService.php`, `SchemaInstaller.php`, and `helpers.php`
  (escaping, CSRF, request helpers). Also holds shared view partials (`pages-shell-top.php`,
  `pages-data.php`, modal templates, nav/bell/banner includes) so pages aren't duplicated markup.
- `assets/css/styles.css` + `assets/css/rxtracker-brand-tokens.css` — UI styling.
- `assets/js/app.js` — main client-side logic, plus a handful of smaller feature-specific JS files.
- `database/schema.sql`, `database/seed.sql`, `database/migrations/*.sql` — fresh-install schema, seed
  data, and numbered migration history. `includes/SchemaInstaller.php` is what actually keeps a live
  database in sync at runtime; the SQL files are kept as best-effort mirrors of what it does.
- `scripts/` — cron/CLI maintenance scripts (`send_due_push.php`, `finalize_missed.php`,
  `generate_vapid_keys.php`, `migrate_to_first_user.php`, etc.).
- `tests/` — 16 hand-rolled PHP test scripts (see Testing above).
- `docs/` — `CODEBASE_AUDIT.md` (latest full audit), `CODE_REVIEW.md` (prior security/architecture
  review), `user-guide.md` (end-user documentation, mirrored by the in-app `?page=help`), plus
  `account-roadmap.md` and `rxtracker-style-guide.md`.
