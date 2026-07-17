# RxTracker — Codebase Review

_Date: 2026-07-16 · Branch: `claude/rxtracker-codebase-review-zv6hom` · Reviewer: automated code review_

## Scope & method

Full-codebase review of RxTracker (PHP 8.1+ / MySQL, ~21k LOC) covering security, correctness,
architecture, and performance. All PHP files pass `php -l`. The three hand-rolled test scripts
(`tests/NextDoseTest.php`, `tests/AdherenceTest.php`, `tests/MedicationRepositoryTest.php`) all pass
against in-memory SQLite. Findings below were verified by tracing the actual request path from the
route handlers (`routes/actions.php`, `routes/api.php`, `routes/profile.php`) into the repositories;
each cites `file:line`.

## Executive summary

The application is in **solid overall shape**: every database access uses PDO prepared statements
(no SQL injection was found), server-side output is escaped through a central `e()` helper, all
state-changing forms carry CSRF tokens verified with `hash_equals()`, passwords use bcrypt, sessions
are regenerated on login, and the auth flows are written to resist email enumeration. That baseline
is better than most projects of this size.

The **most important issues are broken object-level authorization (IDOR)** in the medication-group
and medication-update paths: an authenticated user can read, modify, or delete data belonging to
other users because several repository methods key only on `group_id` / `medication_id` and skip the
`user_id` ownership filter that the rest of the class applies consistently. For an app storing
personal health data, these are the findings to fix first.

Secondary themes: a spoofable/fail-open rate limiter, an SSRF redirect gap in the API proxy, and
significant maintainability drag from three god-files (`MedicationRepository.php` 4,491 lines with 25
schema-migration methods running on every request, `assets/js/app.js` 6,949 lines, `routes/pages.php`
2,234 lines).

### Remediation status (this branch)

The security and correctness findings have been fixed on this branch; the large
architectural refactors are deferred (tracked below).

| # | Finding | Status |
|---|---------|--------|
| 1 | Medication-group IDOR | **Fixed** — all group methods scoped to `user_id`/profile; added `groupBelongsToUser`/`medicationBelongsToUser` guards |
| 2 | `updateMedication` schedule rewrite | **Fixed** — ownership gate added before the schedule rewrite |
| 3 | API-proxy SSRF via redirects | **Fixed** — `CURLOPT_FOLLOWLOCATION` disabled, restricted to HTTPS |
| 4 | Rate-limiter IP spoofing | **Fixed** — `X-Forwarded-For` honored only when `TRUST_PROXY` is set |
| 5 | Missing `medications.user_id` index | **Fixed** — added to `schema.sql`, migration `011`, and runtime `ensure*` |
| 7 | Google token `nbf`/`iat` | **Fixed** — added not-before / issued-at validation with clock-skew leeway |
| 8 | Cross-tenant day-scoped reads | **Fixed** — `doseLogMapForDate`/`activePostponesForDate` scoped to the user |
| 6 | Constructor runs migrations per request | **Deferred** — needs the migration-runner refactor (out of scope for a surgical fix) |
| 9 | God-files / triple JS escaper | **Deferred** — large refactor |
| 10 | Interval run-out estimate vs schedule | **Deferred** — low-impact estimate discrepancy |
| — | Google JWKS not cached | **Deferred** — perf-only; skipped to avoid cache-staleness complexity |

Regression coverage for findings #1 and #2 was added in `tests/OwnershipTest.php`
(multi-tenant group + schedule-rewrite authorization). All four test scripts pass.

### Priority remediation list

| # | Severity | Area | Finding |
|---|----------|------|---------|
| 1 | **High** | Security / IDOR | Medication-group methods not scoped to `user_id` — cross-tenant read/modify/delete |
| 2 | **High** | Security / IDOR | `updateMedication` → `replaceScheduleTimes` rewrites any medication's schedule regardless of owner |
| 3 | Medium | Security | `api-proxy.php` follows upstream redirects (`CURLOPT_FOLLOWLOCATION`) — SSRF past the allowlist |
| 4 | Medium | Security | Login rate limiter is IP-spoofable (`X-Forwarded-For`) and fails open |
| 5 | Medium | Performance | `medications` has no index on `user_id`; every request filters on it |
| 6 | Medium | Perf / Arch | 25 `ensure*()` schema-migration checks run in the repository constructor on every request |
| 7 | Low | Security | Google `verifyIdToken` skips `nbf`/`iat` and re-fetches JWKS on every sign-in |
| 8 | Low | Correctness | `doseLogMapForDate` / `activePostponesForDate` query across all users' rows |
| 9 | Low | Maintainability | Three separate HTML-escaping implementations in `app.js`; god-files; thin test coverage |

---

## Security findings

### 1. HIGH — Broken object-level authorization on medication groups

`includes/MedicationRepository.php` scopes almost every query by `user_id` (+ optional `profile_id`),
but the medication-group methods do not:

- `updateGroup()` — `MedicationRepository.php:2648` — `UPDATE medication_groups ... WHERE id = :id` (no `user_id`)
- `deleteGroup()` — `MedicationRepository.php:2656` — `DELETE FROM medication_groups WHERE id = :id`
- `addMedicationToGroup()` — `MedicationRepository.php:2662` — inserts by `group_id`/`medication_id`, neither checked
- `removeMedicationFromGroup()` — `MedicationRepository.php:2674`
- `findGroup()` — `MedicationRepository.php:2596` — `SELECT ... WHERE id = :id` (info disclosure of group name/time/members)
- `groupForMedication()` — `MedicationRepository.php:2689` and `groupMembers()` — `MedicationRepository.php:2739`

These are reachable directly from `routes/actions.php` with attacker-controlled POST values and **no
prior ownership gate**:

- `update_group` → `updateGroup($groupId, …)` — `routes/actions.php:171`
- `delete_group` → `deleteGroup((int) post_string('group_id'))` — `routes/actions.php:287`
- `add_medication_to_group` → `addMedicationToGroup($targetGroupId, …)` — `routes/actions.php:300`
- `remove_medication_from_group` → `removeMedicationFromGroup(…)` — `routes/actions.php:315`

**Failure scenario:** User A is logged in and POSTs `action=delete_group&group_id=<B's group id>`
(sequential integer PKs make guessing trivial). The `DELETE` matches on `id` alone, so User B's
medication group is deleted. The same works for renaming/retiming (`update_group`) and for stuffing a
victim's medication into a group (`add_medication_to_group`).

**Fix:** Add `AND user_id = :user_id` (+ `profileSql()`) to every group query, mirroring the pattern
already used in `deactivateMedication()` (`MedicationRepository.php:1360`). The `medication_groups`
table already carries `user_id` and `profile_id` columns (`database/schema.sql:218,261`), so this is a
WHERE-clause change plus binding the existing `$this->userId`.

### 2. HIGH — `updateMedication` rewrites any medication's schedule regardless of owner

`updateMedication()` scopes its `UPDATE medications … WHERE id = :id AND user_id = :user_id`
correctly (`MedicationRepository.php:957`), but then unconditionally calls
`replaceScheduleTimes($id, …)` (`MedicationRepository.php:983`), which does:

```
DELETE FROM medication_schedule_times WHERE medication_id = :medication_id   -- line 2278
INSERT INTO medication_schedule_times (...) VALUES (...)                     -- line 2283
```

with **no ownership check**. In the route handler, `update_medication` calls
`$repository->updateMedication($id, …)` **unconditionally** (`routes/actions.php:113`) — the preceding
`findMedication($id)` result (`routes/actions.php:112`) is used only for dose-change logging, not as a
gate.

**Failure scenario:** User A POSTs `action=update_medication&medication_id=<B's med id>&…`. The
`medications` UPDATE affects 0 rows (correctly blocked by `user_id`), but execution continues to
`replaceScheduleTimes`, which **deletes User B's reminder times and inserts User A's submitted
times** — silently corrupting another user's dosing schedule (and therefore their reminders and
adherence math).

**Fix:** Gate the whole handler on ownership — return early when `findMedication($id) === null` in the
`update_medication` branch — and/or scope `replaceScheduleTimes` via an `EXISTS (SELECT 1 FROM
medications WHERE id = … AND user_id = …)` guard, the pattern already used by `updateNote()`
(`MedicationRepository.php:1539`) and `deleteNote()` (`MedicationRepository.php:1560`).

### 3. MEDIUM — SSRF: API proxy follows redirects past the allowlist

`api-proxy.php` validates the request URL against a 3-entry prefix allowlist (`api-proxy.php:55-76`)
but sets `CURLOPT_FOLLOWLOCATION => true` (`api-proxy.php:81`). The allowlist is checked only against
the **initial** URL; any redirect returned by an allowed upstream is followed to an arbitrary host,
including link-local metadata endpoints (e.g. `169.254.169.254`) or internal services.

**Failure scenario:** An allowlisted host (or a compromised/misbehaving one) returns `302 Location:
http://169.254.169.254/…`; curl follows it and returns the internal response body to the caller. The
per-user auth + rate limit reduce but do not remove the exposure.

**Fix:** Set `CURLOPT_FOLLOWLOCATION => false`, or cap `CURLOPT_MAXREDIRS` and re-validate every
redirect target against the allowlist (via `CURLOPT_REDIR_PROTOCOLS` + a redirect callback). Also
consider `CURLOPT_PROTOCOLS => CURLPROTO_HTTPS`.

### 4. MEDIUM — Login rate limiter is IP-spoofable and fails open

`AuthService::clientIp()` trusts `HTTP_X_FORWARDED_FOR` first (`AuthService.php:366`), and both
`isLockedOut()` and `isIpRateLimited()` return `false` on any exception (`AuthService.php:337,306`).
The code comments call these out as deliberate trade-offs. Two consequences:

- The **IP** limb of the limiter is trivially bypassed by rotating a spoofed `X-Forwarded-For`
  header. (The **email** limb — `AuthService.php:322` — is robust and not spoofable, so brute-forcing a
  single account is still throttled; the weakness is mainly around distributed/rotating attempts.)
- If the `login_attempts` table is unavailable, rate limiting silently disables (fail-open).

**Fix:** Only honor `X-Forwarded-For` when the direct peer is a known/trusted proxy (otherwise use
`REMOTE_ADDR`); and prefer failing closed for the IP check, or alert on repeated limiter exceptions.
Low-effort, meaningful hardening for a health app.

### 5. LOW — Google ID-token verification gaps

`GoogleAuthService::verifyIdToken()` validates signature, `iss`, `aud`, and `exp`
(`GoogleAuthService.php:157-168`) but does not check `nbf`/`iat`, and `publicKeyForKid()` re-fetches
Google's JWKS over the network on **every** sign-in with no caching (`GoogleAuthService.php:174`). The
core checks are sound; add `nbf`/`iat` tolerance validation and cache the JWKS by `kid` (respecting
`Cache-Control`) to reduce latency and a network-dependency failure mode.

### 6. LOW — Remember-me / CSP notes

- CSP allows `'unsafe-inline'` for scripts (`security_headers.php:26`) — self-documented as interim.
  It widens the blast radius of any future HTML-injection bug; migrate to nonces before public release.
- Session/remember cookies are always `secure => true` (`SessionManager.php:36`), which is correct for
  production but means remember-me silently won't set over plain-HTTP local dev. Not a vulnerability;
  noted for developer awareness.

---

## Correctness findings

### 8. LOW–MEDIUM — Cross-tenant reads in day-scoped helpers

`doseLogMapForDate()` (`MedicationRepository.php:2334`) runs
`SELECT … FROM dose_logs WHERE scheduled_for_date = :date` with **no `user_id`/medication scoping**,
and `activePostponesForDate()` (`MedicationRepository.php:2351`) does the same for `dose_postpones`.
Both build a map keyed by `medication_id|time`, and callers look up only their own medication IDs, so
correctness holds **today** purely because `medication_id` is a globally-unique PK. This is fragile
(any future keying change leaks data) and loads every tenant's rows for the date into memory on each
dashboard/schedule render. Scope both queries with a join to `medications` on `user_id` (+ profile).

### 10. LOW — Inventory forecast can disagree with the actual schedule

`daysUntilRunout()` for interval meds estimates doses/day as `round(24 / interval_hours)`
(`helpers.php:255`), while the real slot generator `timesForDate()` emits every slot from
`first_dose_time` within a 24h window (`MedicationRepository.php:2390`). For intervals that don't
divide 24 evenly (e.g. 5h), the two can differ by a dose, making the "days left / run-out" estimate
slightly off. Consider deriving the forecast from `count(timesForDate())` so the estimate and the
schedule share one source of truth.

_No correctness defects were found in the adherence math (`adherenceForDateRange`,
`MedicationRepository.php:148`), the missed-dose finalizer (`finalizeMissedDoses`,
`MedicationRepository.php:1783`), or the interval double-dose guard (`assertIntervalAllowed`,
`MedicationRepository.php:2535`); the timezone handling in the cron scripts correctly uses each user's
saved zone._

---

## Architecture & maintainability

### 6/11. MEDIUM — Repository constructor runs 25 schema migrations per request

`MedicationRepository::__construct()` invokes 25 `ensure*()` methods
(`MedicationRepository.php:14-42`) — `ensureGroupTables`, `ensureInventoryColumns`,
`ensureOnboardingColumns`, etc. — each issuing DDL/`information_schema`/`PRAGMA` checks. This runs on
**every** page load and every cron iteration (a new repository is constructed per user and per family
profile in `scripts/*.php`). It is both a performance cost and an architectural smell (migrations
living inside the data-access hot path).

**Fix:** Move these into the versioned migration runner under `database/migrations/` (which already
exists) and drop them from the constructor, or gate them behind a one-time "schema version" check.

### 11. Maintainability — god-files

- `includes/MedicationRepository.php` — 4,491 lines, ~90 methods. It conflates schema migration,
  medications, schedules, dose logs, groups, refills/inventory, notifications, push, pain/mood, and
  onboarding. Natural seams: `ScheduleRepository`, `InventoryRepository`, `FeedbackRepository`,
  `PushRepository`, and a separate `SchemaInstaller`.
- `assets/js/app.js` — 6,949 lines in one IIFE, with **three** independent HTML-escapers
  (`escHtml` at `app.js:4455`, another `escHtml` at `app.js:6330`, plus inline `.replace()` chains at
  `app.js:1962` and `:2343`). The escaping itself is applied consistently to user data (good — no DOM
  XSS found), but the duplication invites future divergence. Consolidate to one shared `escHtml`.
- `routes/pages.php` — 2,234 lines mixing controller logic and full HTML for every page. Splitting per
  page/view would greatly improve navigability.

### 14. Test coverage

Tests are three standalone scripts with a hand-rolled `assert` and no framework. They cover next-dose,
adherence, and repository happy paths against SQLite, but there is **no coverage of the security-
critical paths**: ownership scoping (the IDOR findings above), CSRF verification, or auth/lockout.
Adding PHPUnit and a few multi-user ownership tests would catch regressions of findings #1 and #2
directly.

---

## Performance

- **#5 Missing index on `medications.user_id`** — the column is added via `ALTER`
  (`database/schema.sql:216`) with no accompanying index; the table only has
  `idx_medications_active_name` (`database/schema.sql:20`). Since nearly every query filters
  `WHERE user_id = :user_id [AND profile_id …]`, add `INDEX idx_medications_user (user_id, profile_id,
  active)`.
- **#6/#11 constructor migrations** — see above; the single biggest per-request win.
- **#8 day-wide scans** — scoping `doseLogMapForDate`/`activePostponesForDate` to the current user also
  shrinks the working set.
- `refillsForMonth()` uses a correlated subquery per row (`MedicationRepository.php:2156`) — fine at
  current scale, worth revisiting if refill history grows large.

---

## Strengths (keep doing this)

- PDO prepared statements everywhere; **no SQL injection found** despite string-built WHERE fragments
  (the interpolated parts are class constants/`profileSql()`, never user input).
- Centralized, correct output escaping: server-side `e()` (`helpers.php:5`) and JS `escHtml`/`escSvg`
  applied to medication names and notes in dynamic rendering.
- CSRF tokens on all state-changing forms, verified with `hash_equals()` (`helpers.php:34`); coverage
  confirmed across `actions.php`, `profile.php`, `onboarding_actions.php`, `register.php`, `login.php`.
- bcrypt password hashing, `session_regenerate_id(true)` on login (`AuthService.php:115`), remember-me
  token rotation on use (`SessionManager.php:65`), and enumeration-resistant forgot-password/verify
  flows.
- Sensible security headers (HSTS, `X-Frame-Options: DENY`, `nosniff`, Referrer-Policy) and
  transactional multi-step writes with rollback.

---

## Suggested order of work

1. Fix the two IDORs (#1, #2) — add `user_id` scoping to group methods and gate
   `updateMedication`/`replaceScheduleTimes` on ownership. Add multi-user ownership tests alongside.
2. Close the SSRF redirect gap (#3) and harden the rate limiter (#4).
3. Add the `medications.user_id` index (#5) and move constructor migrations out of the hot path (#6).
4. Longer term: decompose `MedicationRepository`, `app.js`, and `pages.php`; adopt PHPUnit.
