# RxTracker — User Account & Family Profiles Roadmap

This document describes the planned architecture and implementation phases for adding user account creation and optional family/multi-user profile tracking to RxTracker.

---

## Background

RxTracker currently operates as a single-user app with no authentication. All data is shared by anyone who accesses the URL. The goal of this feature is to:

1. Allow each person to create their own account (email + password) so their medication data is private and portable.
2. *(Future)* Allow a primary user to manage medication schedules for family members (spouse, children, parents) under one account without requiring separate logins.

---

## Confirmed Decisions

| Decision | Choice |
|----------|--------|
| Auth method | PHP sessions + MySQL (no third-party auth provider) |
| "Remember me" | 30-day secure HttpOnly cookie backed by `user_sessions` table |
| Password reset | Via [Resend](https://resend.com) API (REST, no SMTP config needed) |
| Family profiles | Architecture designed; implementation deferred to a later cycle |
| Help document | `docs/user-guide.md` + in-app `/help` page |

---

## Phase 0 — Pre-Auth Bug Fixes & Refactoring

These changes should be made before the auth layer is added to reduce the scope of files touched during the auth migration.

### Bug Fixes
- **B1** `assets/js/app.js:387` — Wrap slot-picker `JSON.parse` in try/catch *(done)*
- **B5** `index.php:468` — Add `checkdate()` validation to refill date *(done)*
- **E2** `index.php:284` — Reject 0-quantity liquid inventory *(done)*
- **E6** `index.php:513` — Cap User-Agent at 255 chars before storing *(done)*

### Targeted Refactors (recommended before Phase 1)
- **R1** Extract `activeMedications()` / `inactiveMedications()` into one private method — easier to add `WHERE user_id = ?` once instead of twice.
- **R2** Split `index.php` action handlers into `routes/` — each route file gets `requireLogin()` at the top, instead of having to modify one 1,700-line file.
- **R3/R4** Fix N+1 queries in `activeMedications()` and `allGroups()` — bulk JOIN queries, one change each.

---

## Phase 1 — User Account Creation

### 1a. New Database Tables

```sql
CREATE TABLE users (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email                 VARCHAR(255) NOT NULL UNIQUE,
    password_hash         VARCHAR(255) NOT NULL,
    display_name          VARCHAR(100),
    remember_token        VARCHAR(64),
    reset_token           VARCHAR(64),
    reset_token_expires_at DATETIME,
    created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE user_sessions (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNSIGNED NOT NULL,
    session_token  VARCHAR(64) NOT NULL UNIQUE,
    user_agent     VARCHAR(255),
    ip_address     VARCHAR(45),
    expires_at     DATETIME NOT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token (session_token)
);
```

### 1b. Schema Changes to Existing Tables

Add `user_id` to every user-owned table:

```sql
ALTER TABLE medications        ADD COLUMN user_id INT UNSIGNED NOT NULL AFTER id,
                               ADD INDEX idx_user (user_id),
                               ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE medication_groups  ADD COLUMN user_id INT UNSIGNED NOT NULL AFTER id,
                               ADD INDEX idx_user (user_id),
                               ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE push_subscriptions ADD COLUMN user_id INT UNSIGNED NULL AFTER id,
                               ADD INDEX idx_user (user_id);

-- app_settings: change primary key from (setting_key) to (user_id, setting_key)
ALTER TABLE app_settings
    DROP PRIMARY KEY,
    ADD COLUMN user_id INT UNSIGNED NOT NULL FIRST,
    ADD PRIMARY KEY (user_id, setting_key),
    ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
```

> `dose_logs`, `medication_refills`, `dose_postpones`, `medication_schedule_times`, and `medication_group_members` inherit user scoping through the `medication_id` foreign key — no direct `user_id` column needed on those tables.

### 1c. Migration Script (Existing Data → First User)

A one-time script (`scripts/migrate_to_first_user.php`) that:
1. Creates a single user row (`id=1`, email from `.env` or prompted interactively).
2. Sets `user_id = 1` on all existing `medications`, `medication_groups`, `push_subscriptions`, and `app_settings` rows.
3. Disables itself after successful run (writes a flag to `app_settings`).

Run once after deploying the schema changes:
```bash
php scripts/migrate_to_first_user.php
```

### 1d. New PHP Files

```
includes/AuthService.php       — register(), login(), logout(), requireLogin(), currentUser()
includes/SessionManager.php    — token issuance, remember-me cookie, session expiry/rotation
includes/MailService.php       — sendPasswordReset() via Resend API
routes/login.php               — GET: login form; POST: authenticate
routes/register.php            — GET: registration form; POST: create account
routes/logout.php              — POST: clear session + cookie
routes/forgot_password.php     — GET/POST: send reset email
routes/reset_password.php      — GET: reset form with token; POST: update password
```

### 1e. AuthService Design

```php
class AuthService {
    public function register(string $email, string $password, string $displayName): int;
    public function login(string $email, string $password, bool $remember): bool;
    public function logout(): void;
    public function requireLogin(): void;   // redirects to /login if not authenticated
    public function currentUser(): ?array;  // returns ['id', 'email', 'display_name'] or null
    public function currentUserId(): int;   // throws if not logged in
}
```

**Password rules:** minimum 8 characters; bcrypt via `password_hash()` / `password_verify()`.

**Session rotation:** Issue a new `session_token` on every login. The "remember me" token is separate from the session token and stored in a `HttpOnly; Secure; SameSite=Strict` cookie named `rx_remember`.

### 1f. MedicationRepository Changes

Add `$userId` scoping to every public method that returns user data:

```php
// Before
public function activeMedications(): array

// After
public function activeMedications(int $userId): array
// adds: WHERE m.user_id = :user_id
```

Methods affected: `activeMedications`, `inactiveMedications`, `allGroups`, `ungroupedActiveMedications`, `todaySchedule`, `dueReminderItems`, `missedDoseCount`, `finalizeMissedDoses`, and all settings-related methods.

Recommended approach: store `$userId` as a constructor parameter so every call automatically scopes:

```php
$repository = new MedicationRepository($db, $auth->currentUserId());
```

### 1g. Push Subscription Scoping

`push_subscriptions.user_id` is added in 1b. Update `PushNotificationService` and the cron script (`scripts/send_due_push.php`) to only fetch subscriptions for the user whose medications are due.

### 1h. UI Changes

- Add `/login` page — clean form: email, password, "Remember me", link to register.
- Add `/register` page — email, password, confirm password, display name.
- Add `/forgot-password` — email input, sends reset link.
- Add `/reset-password?token=...` — new password + confirm.
- Top nav: replace placeholder user icon with logged-in name + **Logout** button.
- All unprotected requests redirect to `/login?redirect=<original-url>`.

### Environment Variables (additions)

```ini
RESEND_API_KEY=re_...
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME=RxTracker
APP_URL=https://yourdomain.com
```

---

## Phase 2 — Family / Sub-User Profiles *(Design complete; build deferred)*

### Concept

Sub-users are **named profiles under one account**, not separate logins. The primary user creates profiles for family members and can switch between them. All medication data is kept separate per profile.

### New Table

```sql
CREATE TABLE family_profiles (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_user_id  INT UNSIGNED NOT NULL,
    display_name   VARCHAR(100) NOT NULL,
    avatar_color   VARCHAR(7),          -- e.g. "#6366f1"
    relationship   VARCHAR(50),         -- "Spouse", "Child", "Parent", etc.
    birth_year     YEAR,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

### Schema Changes (Phase 2)

```sql
ALTER TABLE medications        ADD COLUMN profile_id INT UNSIGNED NULL,
                               ADD FOREIGN KEY (profile_id) REFERENCES family_profiles(id) ON DELETE SET NULL;

ALTER TABLE medication_groups  ADD COLUMN profile_id INT UNSIGNED NULL,
                               ADD FOREIGN KEY (profile_id) REFERENCES family_profiles(id) ON DELETE SET NULL;
```

`NULL profile_id` = belongs to the primary user themselves. Non-null = belongs to that family member's profile.

### UI Changes (Phase 2)

- **Profile switcher** in the top nav — avatar chip showing the currently viewed profile, click to switch.
- **Manage Family** section in Settings — add, edit, delete profiles.
- Dashboard, Medications, Calendar, and Export all filter by the active profile session variable.
- Push notifications include the profile name: *"Time for Sarah's Metformin (500 mg)"*.

---

## Implementation Order Summary

| Phase | Deliverable | Key Files Changed |
|-------|-------------|-------------------|
| 0 | Bug fixes + targeted refactors | `index.php`, `assets/js/app.js`, `MedicationRepository.php` |
| 1a | `users` + `user_sessions` tables | `database/schema.sql` |
| 1b | `user_id` on existing tables | `database/schema.sql` + migration script |
| 1c | Migration script | `scripts/migrate_to_first_user.php` |
| 1d | AuthService + SessionManager + MailService | `includes/` |
| 1e | Login / register / reset pages | `routes/` |
| 1f | MedicationRepository user scoping | `includes/MedicationRepository.php` |
| 1g | Push subscription scoping | `includes/PushNotificationService.php`, `scripts/send_due_push.php` |
| 1h | Nav / redirect UI | `index.php` |
| 2 | Family profiles (future) | New table + `routes/family.php` + nav switcher |

---

## Verification Checklist

### Phase 1 Verification
- [ ] Register new account → login → add medication → log dose → data persists on reload
- [ ] Log out → all pages redirect to `/login`
- [ ] Open incognito → no data visible without login
- [ ] Register a second account → confirm zero data bleed-through from account 1
- [ ] "Remember me" → close browser → reopen → still logged in
- [ ] Forgot password → receive Resend email → click link → reset password → login with new password
- [ ] Push notifications still deliver after user scoping is added

### Phase 2 Verification *(when built)*
- [ ] Create family profile → switch to it → add medication → switch back → primary user data unaffected
- [ ] Push notification body includes family member name
- [ ] Export filters to active profile only
