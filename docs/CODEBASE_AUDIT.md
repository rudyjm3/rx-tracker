# RxTracker — Codebase Audit, Documentation Reconciliation & MVP Readiness Report

_Date: 2026-08-13 · Branch: `claude/rxtracker-audit-mvp-jtb2ju`_

## Method note (what was actually done)

This audit combined:

- **Code inspection** of the full repository (PHP 8.1+/MySQL app, ~30 route files, ~30 `includes/`
  classes, `assets/js/app.js`, `assets/css/*`, `database/*`, `scripts/*`, `tests/*`, and every
  Markdown doc in the repo).
- **Static, end-to-end tracing** of the add-medication, mark-dose (Take/Skip), refill, inventory,
  refill-warning, medication-lookup (DailyMed/OpenFDA), and notification/push workflows, from the
  HTML form through the JS handler, the PHP route, the repository method, and the SQL, and back.
- **A fresh authorization pass** over 6 ownership-sensitive endpoints not previously covered by
  `docs/CODE_REVIEW.md` (notes, group mutations, revert/delete dose log, refill/adjust-quantity).
- **Automated testing**: all 15 hand-rolled scripts in `tests/` were run before and after every
  code change in this pass (`php tests/*.php` against in-memory SQLite) — all pass.
- **`php -l` linting** of every file touched.
- **No browser/UI testing was possible in this environment** (no way to launch and click through
  the live app or a service worker/push flow). Anything about actual rendered behavior, PWA
  install flow, or push delivery timing is stated as "traced from code," not "tested."

This is the second full review of this codebase. `docs/CODE_REVIEW.md` (2026-07-16) is the first —
a deep security/architecture pass. Its findings are treated as an established baseline here, not
rediscovered; the fixes it documents were spot-checked and confirmed still present in the current
tree (medication-group IDOR guards, `updateMedication` ownership gate, API-proxy SSRF hardening,
JWKS caching). Two of its citations had gone stale since (`routes/pages.php` no longer exists; the
triple-`escHtml` finding has been resolved) and were corrected in place in that document.

---

## 1. Executive Summary

RxTracker is a mature, actively developed PHP/MySQL medication-tracking PWA that has grown well
past its original MVP scope (see `BUILD_OUTLINE.md`, now marked superseded). It has real user
accounts, family profiles, Google sign-in, medication groups, pain/mood tracking, side-effect
logging, allergy tracking, a calendar view, PDF export, and background web push — on top of the
original core loop of add medication → schedule → take/skip → inventory → refill → history.

**Strengths:**
- Consistent architecture: every database access goes through PDO prepared statements, output is
  centrally escaped (`e()` server-side, one `escHtml` client-side), CSRF tokens guard every
  state-changing form, and ownership (`user_id`/`profile_id`) scoping is applied consistently
  across the endpoints traced in this pass and the prior review — no new IDOR was found.
  `dose_logs` carries a real unique-key constraint that backstops the app-level duplicate-submit
  guards, so the double-take/double-refill race conditions this audit hunted for turn out to be
  handled correctly at the database layer even where the PHP-level error message wasn't friendly.
- The business logic that matters most for a health app — inventory deduction/restoration on
  take/revert, run-out-date estimation, and the interval double-dose guard — is unit-agnostic by
  design (works the same for pills, mL, puffs, patches) and is covered by targeted regression
  tests (`InventorySimulationTest`, `RanOutOnDateTest`, `DaysUntilRunoutTest`, `OwnershipTest`,
  `GroupTakeAllTest`, `TrackingStartTest`, etc.) — all 15 pass.
- The prior security review's high-severity findings (medication-group IDOR, schedule-rewrite
  IDOR, SSRF-via-redirect, spoofable rate limiter) are genuinely fixed, not just documented as
  fixed.

**Weaknesses:**
- Documentation had drifted badly from reality in three places (`BUILD_STATUS_COMPARISON.md`,
  `docs/account-roadmap.md`, `README.md`) — all marked shipped features as missing or future work.
  This is now corrected as part of this audit (see §8).
- `docs/user-guide.md` / `routes/help.php` — the best-maintained docs — were missing the Allergies
  feature entirely and described the Medication Groups UI pre-modal-refactor. Also now corrected.
- Three parallel sources of schema truth (`database/schema.sql`, `database/migrations/*.sql`,
  `includes/SchemaInstaller.php`'s runtime sweep) with no automated consistency check — a real
  maintainability/data-integrity risk if they drift again (they have before).
- Three god-files remain undecomposed: `assets/js/app.js` (7,173 lines), `assets/css/styles.css`
  (8,492 lines), `includes/SchemaInstaller.php` (2,247 lines). `MedicationRepository.php` itself
  has already been successfully decomposed from 4,491 lines to a ~1,000-line facade — proof this is
  achievable, but the remaining three are large, higher-risk, and deliberately **not** attempted in
  this pass per the "no large uncontrolled rewrite" constraint.
- No PHPUnit, no CI configuration — the 15 hand-rolled tests are solid for what they cover but must
  be run manually, and there's no route/HTTP-level or browser-level test coverage.

**Highest risk found and fixed this pass:** a real data-integrity bug — refill amounts were
truncated to an integer server-side (`(int) post_string('amount')`), silently corrupting inventory
for any liquid/drop/patch/injection medication refilled with a fractional amount (e.g. "4.5 mL"
silently became "4"). See BUG-001.

**MVP readiness:** **POST-MVP / BETA READY** — see §11.

---

## 2. Build Health Score

| Category | Score | Notes |
|---|---|---|
| Core Functionality | 90/100 | All core workflows traced end-to-end and correct; the highest-impact bug found (refill truncation) is now fixed. One traced edge case (zero-pill interstitial's refill-modal unit fallback) remains generic rather than fully wired — see Technical Debt. |
| Code Quality | 78/100 | Consistent patterns, no SQL injection, centralized escaping/CSRF; docked for the three remaining god-files and some duplicated markup (the "Update prescribed dose" modal is repeated near-verbatim across 5 route files). |
| Maintainability | 72/100 | `MedicationRepository` decomposition is a good precedent, but three parallel schema sources of truth and the undecomposed god-files remain real drag. |
| Performance | 82/100 | Indexes present, N+1s from the prior review fixed, schema sweep gated behind a version check; one low-priority correlated subquery (`refillsForMonth()`) and two still-per-user migration methods remain (both previously flagged, neither urgent at current scale). |
| Security | 88/100 | Strong baseline (prepared statements, CSRF, bcrypt, session regeneration, ownership scoping); this pass's fresh 6-endpoint authorization check found nothing new. Main open item is the documented `'unsafe-inline'` CSP trade-off. |
| Data Integrity | 85/100 | Transactions used correctly throughout; `dose_logs`' unique constraint backstops app-level dedup logic; the refill-amount truncation bug (now fixed) was the one real integrity gap found. |
| UX | 80/100 | Thorough dose-status/alarm/snooze/zero-pill-interstitial flows and confirmation dialogs per code inspection; not independently browser-tested this pass. |
| Accessibility | 65/100 | Modals consistently use `role="dialog"`/`aria-modal`/`aria-label`; no dedicated accessibility audit (contrast, full keyboard-nav trace, screen-reader pass) was performed this pass — scored conservatively pending one. |
| Documentation | 82/100 | Significantly improved by this pass (stale docs marked superseded, user-guide/help.php gap closed); still no automated mechanism to keep the three schema-truth sources or the guide/help.php pair in sync going forward. |
| Testability | 60/100 | 15 focused hand-rolled tests with real business-logic coverage (including a dedicated ownership regression suite), but no PHPUnit, no CI, and no route/HTTP or browser-level tests. |
| MVP Readiness | 88/100 | See §11 — core workflow is complete and reliable per tracing; gaps are hardening/polish, not missing functionality. |
| **Overall** | **79/100** | A mature, actively-maintained app with a real (now-fixed) data bug and genuine but well-understood technical debt — not a fragile codebase. |

---

## 3. Feature Implementation Status — Feature Traceability Matrix

Legend: ✅ Fully implemented · 🟡 Partially implemented · ❌ Not implemented · 🐛 Implemented but
broken · 🔄 Implemented differently than documented · 🗑 No longer applicable · ➕ Implemented but
was missing from documentation (now added).

| Feature | Source Document | Document Requirement | Implementation Location | Status | Notes |
|---|---|---|---|---|---|
| Email/password accounts | `docs/account-roadmap.md` Phase 1 | Register, login, logout, sessions | `AuthService.php`, `SessionManager.php`, `routes/{login,register,logout}.php` | ✅ | Matches design; bcrypt, session regen, remember-me all present. |
| Password reset via email | `docs/account-roadmap.md` §1e | Resend API reset flow | `MailService.php`, `routes/forgot_password.php`/`reset_password.php` | ✅ | |
| Email verification | *(not in roadmap doc)* | — | `routes/verify_email.php`, `AuthService.php` | ➕ | Built beyond the original roadmap; not documented there. |
| Google Sign-In | *(not in roadmap doc)* | — | `GoogleAuthService.php`, `routes/google_login.php`/`google_link.php`/`google_unlink.php` | ➕ | Hand-rolled JWKS-cached ID-token verification; not in the original roadmap. |
| Family / sub-user profiles | `docs/account-roadmap.md` Phase 2 | Named profiles, switcher, per-profile data | `family_profiles` table, `routes/family.php`, `routes/family_member.php` | ✅ | Roadmap doc previously said "design complete, build deferred" — corrected in this pass. |
| Add medication (wizard) | `BUILD_OUTLINE.md` #1 | Add/manage active medications | `routes/medications.php`, `assets/js/medication-wizard.js`, `routes/actions.php` | ✅ | 4-step wizard; exceeds original scope (dose form/unit, groups, drafts). |
| Edit / discontinue / reactivate medication | `BUILD_OUTLINE.md` #1 | — | `routes/actions.php` (`update_medication`, `deactivate_medication`, `activate_medication`) | ✅ | Reason capture + history on both discontinue and reactivate. |
| Fixed-time schedules | `BUILD_OUTLINE.md` #2 | — | `medication_schedule_times`, `ScheduleRepository.php` | ✅ | |
| Interval schedules | `BUILD_OUTLINE.md` #2 | — | `schedule_mode=interval`, double-dose guard (`assertIntervalAllowed`) | ✅ | |
| Dashboard next-dose / today schedule | `BUILD_OUTLINE.md` #2 | — | `routes/dashboard.php`, `pages-data.php` | ✅ | |
| Taken / Skipped logging | `BUILD_OUTLINE.md` #3 | — | `routes/actions.php` (`mark_dose`), `ScheduleRepository::recordDoseStatus()` | ✅ | Duplicate-submit now gives a friendly error (BUG-004, fixed this pass). |
| Missed-dose auto-finalization | `BUILD_STATUS_COMPARISON.md` said "Partial" | Background job | `finalizeMissedDoses()`, `scripts/finalize_missed.php`, piggybacked on `poll_due` | ✅ | Comparison doc was stale on this; corrected. |
| Snooze (5/10/15/30 min) | `BUILD_STATUS_COMPARISON.md` said "Not Built" | — | `dose_postpones`, `ScheduleRepository::postponeDose()` | ✅ | Comparison doc was stale; corrected. |
| Push / alarm notifications at dose time | `BUILD_STATUS_COMPARISON.md` said "Not Built" | — | `sw.js`, `PushNotificationService.php`, `assets/js/app.js` polling + alarm overlay | ✅ | Comparison doc was stale; corrected. |
| Inventory deduction on Taken | `BUILD_OUTLINE.md` #4 | — | `InventoryRepository::deductInventory()`/`restoreInventory()` | ✅ | Unit-agnostic; correctly reverts using the historically-deducted amount, not today's config. |
| Refill logging | `BUILD_OUTLINE.md` #4 | — | `InventoryRepository::logRefill()`, `routes/actions.php` | 🐛→✅ | Was truncating fractional amounts to int for non-pill units (BUG-001); fixed this pass. Modal was also hardcoded "pills" (BUG-002); fixed this pass. |
| Quantity adjustment (recount) | `BUILD_OUTLINE.md` #4 | — | `InventoryRepository::adjustQuantity()` | ✅ | Correctly float-aware already. |
| Low-supply / out-of-stock warnings | `BUILD_STATUS_COMPARISON.md` said "Partial" | Dynamic warning state | `StockNotificationRepository.php`, `refill-reminder-banner.php`, `nav-bell.php` | ✅ | Comparison doc was stale; corrected. |
| Run-out-date / days-remaining estimate | `BUILD_STATUS_COMPARISON.md` said "Not Built" | — | `helpers.php::daysUntilRunout()`, `InventoryRepository::dateInventoryCrossedZero()` | ✅ | Comparison doc was stale; corrected. Slot-counting logic matches the real schedule generator (prior review fix, still verified correct). |
| Calendar / month view | `BUILD_STATUS_COMPARISON.md` said "Not Built" | — | `routes/dashboard.php` calendar section / calendar markers | ✅ | Comparison doc was stale; corrected. |
| Export / share medication list | `BUILD_STATUS_COMPARISON.md` said "Not Built" | — | `routes/export.php`, `DoctorVisitReport.php` (dompdf) | ✅ | Comparison doc was stale; corrected. |
| Side-effect / feedback logging | `BUILD_STATUS_COMPARISON.md` said "Not Built" | — | pain/mood feedback prompts, side-effect logging in medication detail, included in PDF report | ✅ | Comparison doc was stale; corrected. |
| Medication groups | *(not in original roadmap)* | — | `MedicationGroupRepository.php`, group modal in `routes/medications.php` | ➕ | Recently reworked from inline forms to a modal with a dose-qty stepper (PR #88); `docs/user-guide.md`/`routes/help.php` updated this pass to match. |
| Pain & mood tracking | *(not in original roadmap)* | — | `routes/pain_tracking.php`, `routes/mood_wellbeing.php`, `AdherenceRepository.php`, `MoodTagRepository.php` | ➕ | Trend charts, tags, standalone logging — well beyond original MVP scope. |
| Allergies & intolerances | *(undocumented until this pass)* | — | `AllergyRepository.php`, `includes/allergies-modal.php`, migrations 017–019 | ➕ | Fully implemented but absent from `docs/user-guide.md`/`routes/help.php`; both updated this pass. |
| DailyMed/OpenFDA medication lookup | *(not in original roadmap)* | — | `api-proxy.php`, `assets/js/app.js` autocomplete/SPL/label calls | ➕ | Server-proxied, allowlisted, SSRF-hardened (prior review fix, confirmed still in place). |
| Web push notifications (PWA) | *(not in original roadmap)* | — | `sw.js`, `manifest.json`, `PushNotificationService.php`, `scripts/send_due_push.php` | ➕ | Cron-dependent for background delivery; documented in `docs/user-guide.md` §Push Notifications. |
| In-app Help page | *(implied by account-roadmap "Help document" row)* | `docs/user-guide.md` + in-app `/help` | `routes/help.php` | ✅ | Now covers Allergies and the current Groups modal flow (this pass); kept in sync with `docs/user-guide.md`. |
| RxImage integration | `api-proxy.php` allowlist entry | — | Allowlisted in `api-proxy.php`; no active call site found in `app.js` | 🟡 | Possible dead/reserved allowlist entry — flagged for verification, not removed (see Technical Debt P3). |
| Doctor/pharmacy metadata capture | `BUILD_STATUS_COMPARISON.md` said "Not Built" | — | Not found in medication form fields | ❌ | Genuinely not implemented; correctly still marked not-built. Low priority per current scope. |
| Automated CI test runs | *(implied by "Testing" sections)* | — | No `.github/workflows` or other CI config found | ❌ | 15 tests exist but must be run manually; no CI wiring. |

---

## 4. Bugs Found

### BUG-001 — Refill amount truncated to integer, corrupting non-pill inventory
**Severity:** High (data integrity)
**Affected area:** Refill logging
**Files involved:** `routes/actions.php` (`log_refill` action)
**Description:** The refill amount POST value was cast with `(int)` before being passed to
`InventoryRepository::logRefill(int $medicationId, string $refillDate, float $amount, ...)`, which
accepts and expects a float.
**Expected behavior:** A refill of "4.5 mL" (or any fractional patch/drop/injection amount) should
add exactly 4.5 to `current_quantity`.
**Actual behavior:** The fractional part was silently discarded — "4.5 mL" became "4", "0.5 units"
became "0" (and was then rejected by the `$amount <= 0` check with a confusing error).
**Root cause:** `$amount = (int) post_string('amount');` — the sibling `adjust_quantity` action
already parses its equivalent field as `(float)`; this one action didn't follow that pattern.
**Recommended fix:** Cast to `(float)` instead.
**Status:** Fixed.

### BUG-002 — Refill modal hardcoded to "Amount (pills)" regardless of medication unit
**Severity:** Medium (UX correctness)
**Affected area:** Refill logging UI
**Files involved:** `routes/medications.php` (refill modal markup), `assets/js/app.js` (`openRefillModal`), `includes/medication-plan-tabs.php`, `includes/nav-bell.php`, `includes/refill-reminder-banner.php`
**Description:** Unlike the sibling "Adjust Quantity" modal (which correctly shows the medication's
real unit via `data-adjust-qty-unit` and uses `step="0.001"`), the refill modal always read "Amount
(pills)" with no `step` attribute (defaulting to integer-only stepping in most browsers), even for
liquid/inhaler/patch/injection/drops medications.
**Expected behavior:** The refill modal should show the medication's actual inventory unit (mL,
puffs, patches, units, drops, tablets) and accept fractional amounts, matching the Adjust Quantity
modal.
**Actual behavior:** Always said "pills"; integer-only number stepping.
**Root cause:** The modal template and its three trigger call sites never threaded the
medication's `inventory_unit` through to the modal, unlike the adjust-qty modal which already did.
**Recommended fix:** Add `data-inventory-unit` to each `data-open-refill-modal` trigger (mirroring
the existing `data-inventory-unit` pattern already used elsewhere), thread it through
`openRefillModal()`, and add `step="0.001"` plus a unit label span to the modal input, matching the
adjust-qty modal's existing markup.
**Status:** Fixed for the three primary entry points (medication plan actions menu, low-supply
banner, notification bell). One secondary entry point — the zero-pill interstitial's "Refill"
button — still falls back to a generic "tablets" default, since wiring it correctly would require
threading `inventory_unit` through the mark-dose/zero-pill JSON response chain, a larger change out
of scope for this pass (see Technical Debt P2).

### BUG-003 — No server-side length cap on medication name
**Severity:** Low
**Affected area:** Add/edit medication validation
**Files involved:** `routes/actions.php`
**Description:** `medications.name` is `VARCHAR(120)`, but only an empty-string check existed
before insert/update.
**Expected behavior:** An over-length name should produce a normal validation error.
**Actual behavior:** An over-length name would hit the database layer as an uncaught-looking
`PDOException`, surfaced via the generic top-level catch rather than a friendly message.
**Root cause:** Missing length validation alongside the existing empty-string check.
**Recommended fix:** Add `mb_strlen($name) > 120` → `RuntimeException` next to the existing check.
**Status:** Fixed.

### BUG-004 — Duplicate-submission race on scheduled Take/Skip surfaces a raw DB error
**Severity:** Low
**Affected area:** Dose logging
**Files involved:** `includes/ScheduleRepository.php` (`recordDoseStatus()`)
**Description:** `dose_logs` has a unique constraint on `(medication_id, scheduled_for_date,
scheduled_time)` that correctly prevents a double-take race at the database layer. The free/PRN
"log now" path (`logDoseNow()`) already catches the resulting `PDOException` (code `23000`) and
raises a friendly `RuntimeException('Dose already logged...')`. The scheduled Take/Skip path
(`recordDoseStatus()`) did not have the same catch, so a genuine double-submit race (e.g. a
double-tap that slips past the client-side button-disable guard, or two open tabs) would surface
the database's raw error message instead.
**Expected behavior:** Same friendly error on both paths.
**Actual behavior:** Raw `PDOException` message on the scheduled path only. No data-integrity
impact either way — the transaction correctly rolls back, including any inventory deduction.
**Root cause:** The two methods' catch blocks diverged when the friendly-error handling was added
to `logDoseNow()` but not backported to `recordDoseStatus()`.
**Recommended fix:** Mirror `logDoseNow()`'s `catch (PDOException $exception)` block.
**Status:** Fixed.

### BUG-005 — Liquid `bottle_amount` optionality (needs a product decision, not a code fix)
**Severity:** Low
**Affected area:** Add medication validation
**Files involved:** `routes/actions.php`
**Description:** When adding a liquid medication, an omitted `bottle_amount` silently proceeds with
a starting quantity of 0 rather than erroring. Investigating further: the non-liquid
`starting_quantity` field is equally optional (also defaults to 0 with no required-field check), so
this is not actually inconsistent between the two paths — it may be intentional (e.g. to support
adding a medication and setting its count later via Adjust Quantity).
**Expected/actual behavior:** Matches current behavior for both unit types; not a defect.
**Recommended fix:** None — this is a product question ("should starting quantity ever be
required at add time?"), not a code bug. Flagged for the team's judgment rather than auto-fixed.
**Status:** Needs investigation (product decision, not a code defect).

### BUG-006 — Group-mutation ownership guards silently no-op instead of returning an error
**Severity:** Low (not a security issue — no cross-tenant read/write occurs)
**Affected area:** Medication groups
**Files involved:** `includes/MedicationGroupRepository.php` (`addMedicationToGroup`,
`updateMemberDose`, `removeMedicationFromGroup`)
**Description:** These methods gate on `groupBelongsToUser()`/`medicationBelongsToUser()` before
mutating (correctly preventing any cross-tenant effect), but on a failed check they silently return
rather than throwing/signaling failure. A tampered request against another user's group/medication
ID gets a `{ok:true}`-shaped JSON response despite nothing having happened.
**Expected behavior:** A blocked mutation should be visible to the caller (403/error), not
indistinguishable from success.
**Actual behavior:** Misleading `{ok:true}` response; no actual effect.
**Root cause:** The ownership guard was added as a silent early-return rather than an exception.
**Recommended fix:** Throw a `RuntimeException` (matching the pattern used elsewhere in the same
class, e.g. `logRefill`/`adjustQuantity`'s "Medication not found.") instead of a silent no-op.
**Status:** Recommended (not applied this pass — would change a JSON response contract, which the
audit's constraints ask to avoid without a clear need; this is a debugging/UX quality issue, not a
security hole, so it's queued rather than rushed in).

---

## 5. Refactoring Opportunities

### REF-001 — Duplicated dose-unit `<option>` array (8 call sites)
**Files:** `routes/dashboard.php`, `routes/medications.php`, `routes/mood_wellbeing.php`,
`routes/pain_tracking.php`, `routes/onboarding.php`, `includes/pages-bottom-modals.php`,
`includes/pages-shell-top.php` (×2)
**Current issue:** The literal `['mg','mcg','g','mL','tsp','tbsp','oz','IU','units','drops','puffs','patches']`
(plus a `'%'` variant in onboarding) was repeated verbatim 8 times.
**Recommended refactor:** Extracted to `dose_unit_options(bool $includePercent = false): array` in
`includes/helpers.php`; all 8 call sites now call it.
**Benefit:** Reduced duplication; a future unit addition/removal now happens in one place instead
of risking 8 out-of-sync copies.
**Risk:** Low (mechanical, behavior-preserving — verified all 8 literals were identical before
replacing, and preserved the one `'%'` variant via a parameter rather than dropping it).
**Status:** Completed.

### REF-002 — Correct a stale finding in `docs/CODE_REVIEW.md`
**Files:** `docs/CODE_REVIEW.md`
**Current issue:** The prior review's triple-`escHtml`-duplication finding no longer reflects the
code — only one `escHtml` definition exists today.
**Recommended refactor:** N/A (no code change needed) — the doc was corrected in place with a
dated annotation rather than silently rewritten, preserving the historical record of what was found
and when.
**Benefit:** Prevents a future reader from re-investigating an already-resolved issue.
**Risk:** None.
**Status:** Completed (documentation only).

### REF-003 — "Update prescribed dose" modal duplicated near-verbatim across 5 route files
**Files:** `routes/dashboard.php`, `routes/medications.php`, `routes/mood_wellbeing.php`,
`routes/pain_tracking.php`, `includes/pages-bottom-modals.php`
**Current issue:** The full "Update prescribed dose" modal (heading, dose-amount input, dose-unit
select, note field, footer buttons) is repeated as near-identical markup in 5 separate files, not
just the dose-unit array addressed in REF-001.
**Recommended refactor:** Extract to a single shared partial (following the existing pattern used
for the refill/adjust-qty/allergies modals, which already live in shared `includes/` files) and
`require` it from each page that needs it.
**Benefit:** Maintainability, reduced duplication — a future field change currently requires 5
synchronized edits.
**Risk:** Low-Medium (mechanical extraction, but touches 5 pages' rendering — recommend doing this
as its own focused, separately-tested change rather than folding into this pass).
**Status:** Recommended (not applied this pass, to keep this pass's diff small and independently
verifiable per the "no large uncontrolled rewrite" constraint).

### REF-004 — Three parallel schema sources of truth
**Files:** `database/schema.sql`, `database/migrations/*.sql`, `includes/SchemaInstaller.php`
**Current issue:** `SchemaInstaller.php` (2,247 lines) is the actual mechanism that keeps a live
database in sync at runtime via ~40 idempotent `ensure*()` methods; `schema.sql` and
`migrations/*.sql` are best-effort hand-maintained mirrors with no automated consistency check. The
prior review already found and fixed one real drift instance (`mood_tags`/`tags` columns missing
from `schema.sql`).
**Recommended refactor:** Either (a) add a CI/test step that diffs a fresh `schema.sql` install
against a fresh `SchemaInstaller`-swept database and fails on drift, or (b) longer-term, make
`database/migrations/*.sql` the single source of truth with a real migration runner and derive
`SchemaInstaller`'s sweep from it instead of hand-duplicating DDL in PHP.
**Benefit:** Prevents recurrence of the exact drift bug already found once; reduces the
three-places-to-remember maintenance burden.
**Risk:** Medium if attempting option (b) (touches the core bootstrap path); Low for option (a)
(purely additive, a test/CI script).
**Status:** Recommended (not attempted this pass — this is the kind of schema-adjacent change the
task's constraints explicitly ask to avoid without a clear immediate problem; option (a), the
CI-diff check, is the safer near-term step).

### REF-005 — God-files: `assets/js/app.js`, `assets/css/styles.css`, `includes/SchemaInstaller.php`
**Files:** as named
**Current issue:** 7,173 / 8,492 / 2,247 lines respectively, each mixing many concerns.
**Recommended refactor:** `MedicationRepository.php`'s successful decomposition (4,491 → ~1,000
lines, split into `ScheduleRepository`/`InventoryRepository`/`MedicationGroupRepository`/etc.) is a
proven template for `app.js` (natural seams: dose-tracking, medication-form/wizard,
groups/modal, notifications/push, pain-mood, calendar/export) and for `SchemaInstaller.php`
(natural seam: one file per logical schema area, or per migration).
**Benefit:** Maintainability, easier code review, smaller blast radius per change.
**Risk:** High for `app.js` (single IIFE with implicit ordering dependencies; a bad split can
silently break event wiring) and Medium for `SchemaInstaller.php`/`styles.css`.
**Status:** Recommended, explicitly **not** attempted this pass — this is exactly the class of
large, speculative rewrite the audit's own constraints ask to avoid without a specific bug driving
it. Flagged as technical debt (P1, see §10) for a dedicated, incremental future effort.

---

## 6. Performance Findings

All items below were previously identified in `docs/CODE_REVIEW.md` and confirmed still accurate
(or already fixed) in this pass; no new performance issues were found in the workflows traced.

- **Fixed (verified):** missing `medications.user_id` index — present in current schema.
- **Fixed (verified):** the repository constructor's ~25 `ensure*()` schema checks are now gated
  behind a `schema_state.schema_version` check so the full sweep runs once per database, not once
  per request. Two per-user hybrid methods (mood-tag seeding, notes backfill) still run per user —
  low priority at current scale, unchanged from the prior review's assessment.
- **Fixed (verified):** `doseLogMapForDate()`/`activePostponesForDate()` now scope to the current
  user rather than scanning all tenants' rows for a date.
- **Open (low priority, unchanged):** `refillsForMonth()` uses a correlated subquery per row — fine
  at current data volumes, worth revisiting if refill history grows large.
- **Client-side:** the 30-second `poll_due` interval (in-tab reminder polling) is a reasonable
  tradeoff for a health-reminder app; no evidence of redundant/duplicate polling was found in
  `app.js`. Push delivery latency depends on how often `scripts/send_due_push.php` is scheduled via
  the host's cron — not something the application code controls or can verify from this repo alone.

---

## 7. Security Findings

No new vulnerability was found in this pass. Summary of the current security posture:

- **Ownership/authorization:** this pass independently re-checked 6 endpoints not covered by the
  prior review (`mark_dose`, `update_medication`'s schedule-rewrite path, `revert_dose`/
  `delete_dose_log`, medication notes CRUD, group mutations, `log_refill`/`adjust_quantity`) — all
  correctly scope to the session's `user_id`(+`profile_id`) before mutating. No IDOR found.
- **Previously fixed and reconfirmed present:** medication-group IDOR, `updateMedication`→
  `replaceScheduleTimes` IDOR, API-proxy SSRF-via-redirect (`CURLOPT_FOLLOWLOCATION => false`,
  `CURLOPT_PROTOCOLS => CURLPROTO_HTTPS`), rate-limiter IP-spoofing guard (`TRUST_PROXY`), Google
  ID-token `nbf`/`iat` validation with JWKS caching.
- **Still open (documented trade-off, unchanged):** CSP allows `'unsafe-inline'` for scripts
  (`security_headers.php`) — self-documented as an interim measure. Migrating to nonces before a
  wide public release would meaningfully reduce the blast radius of any future HTML-injection bug,
  though none was found in this pass.
- **Spot-checked, not adversarially tested:** the nonce-authenticated `push_action` endpoint (used
  by the service worker's background Take/Snooze buttons, which run without a session cookie) —
  the nonce is consumed once and the underlying write reuses the same ownership-scoped
  `recordDoseStatus()`/`postponeDose()` methods as the logged-in path. This looks sound on
  inspection but wasn't independently fuzzed/adversarially tested this pass; flagged as a
  lower-priority follow-up given the architecture agent's note that it's a different trust model
  from the rest of the app.
- **No secrets found committed** — `.env`/`.htaccess` are correctly absent and gitignored;
  `.env.example`/`.htaccess.example` contain only placeholder values.

---

## 8. Documentation Changes

**Updated:**
- `README.md` — replaced the stale single-file-MVP framing with an accurate feature summary,
  rewrote "Project structure" to reflect the real `routes/`/`includes/` layout, rewrote "Testing"
  to list all 15 test files and note there's no CI.
- `docs/account-roadmap.md` — added a status banner marking Phases 1 and 2 implemented, corrected
  the "no authentication" background statement, annotated the verification checklists with what
  this pass's tracing actually confirmed vs. what remains unverified.
- `docs/CODE_REVIEW.md` — corrected the stale `routes/pages.php` file-size citation and the
  triple-`escHtml` finding (both resolved since that review), added a pointer to this document.
- `BUILD_STATUS_COMPARISON.md`, `BUILD_OUTLINE.md` — added "superseded" banners pointing here,
  without rewriting their historical content (per the task's instruction to preserve historical
  roadmap information).
- `docs/user-guide.md` — added a new "Allergies & Intolerances" section; rewrote the "Medication
  Groups" section to describe the current modal-based create/edit/delete flow instead of the
  pre-refactor inline-forms description; renumbered the table of contents accordingly.
- `routes/help.php` (in-app Help page) — mirrored the same two changes, keeping it in sync with
  `docs/user-guide.md` per their existing pattern.

**Added:**
- `docs/CODEBASE_AUDIT.md` — this document.

**Deprecated:** none removed; `BUILD_STATUS_COMPARISON.md`/`BUILD_OUTLINE.md` marked superseded in
place rather than deleted, per the task's instruction to preserve historical planning documents.

**Left unchanged:** `ProjectChat.md` (a raw ideation transcript, already self-evidently historical
and never a maintained spec — not worth annotating); `docs/rxtracker-style-guide.md` (design tokens
weren't part of this pass's scope — worth a future spot-check against
`assets/css/rxtracker-brand-tokens.css`, flagged in Technical Debt); `docs/rxtracker-rebuild-guide.md`
(an explicit proposal for a hypothetical future Next.js/Expo/Supabase rewrite, not documentation of
the current app — out of scope for a docs-vs-implementation reconciliation); `CLAUDE.md` (agent
process instructions, not product documentation).

---

## 9. Help System Review

- **Does Help documentation match the application?** Yes, as of this pass. The two gaps found
  (Allergies feature entirely undocumented; Medication Groups section describing the pre-modal
  inline-forms UX) are both closed.
- **Does the in-app Help page match the standalone user guide?** Yes — `routes/help.php` and
  `docs/user-guide.md` are edited together and cover the same 20 topics in the same order, with
  `routes/help.php` linking back to the full guide.
- **Are user flows complete?** The guide covers the full account → medication → schedule →
  dose-tracking → inventory/refill → history/export loop, plus every secondary feature found in
  code (groups, pain/mood, side effects, allergies, family profiles, push, PWA install).
- **Is troubleshooting information adequate?** The existing 9-scenario troubleshooting section
  (no push, dose shows missed, accidental taken mark, wrong supply count, autocomplete not working,
  app feels outdated, can't change schedule type, family member not showing, missing mood charts in
  report) covers the failure modes this audit's edge-case review also surfaced independently; no
  new troubleshooting gap was identified.

---

## 10. Technical Debt

**P0 — Critical:** None identified this pass. (The prior review's P0/P1-equivalent findings — the
two IDORs — are fixed and reconfirmed.)

**P1 — High:**
- Three parallel schema sources of truth (`schema.sql`, `migrations/*.sql`, `SchemaInstaller.php`)
  with no automated drift check (REF-004).
- Three undecomposed god-files: `assets/js/app.js`, `assets/css/styles.css`,
  `includes/SchemaInstaller.php` (REF-005).
- No CI configuration — the 15 hand-rolled tests exist but nothing runs them automatically on push
  or PR.

**P2 — Medium:**
- `docs/CODE_REVIEW.md`-documented CSP `'unsafe-inline'` trade-off — worth closing before a wide
  public release.
- "Update prescribed dose" modal duplicated near-verbatim across 5 files (REF-003).
- Group-mutation ownership guards silently no-op instead of erroring on a blocked request
  (BUG-006).
- Zero-pill interstitial's "Refill" entry point still falls back to a generic unit label rather
  than the medication's real inventory unit (remaining piece of BUG-002).
- No PHPUnit / route-level / browser-level test coverage — the existing tests are solid for
  business logic but don't exercise HTTP routing, CSRF enforcement, or rendered UI.

**P3 — Low:**
- `RxImage` is allowlisted in `api-proxy.php` with no confirmed active call site in `app.js` —
  verify whether it's a reserved-for-later integration or genuinely dead before removing.
- `docs/rxtracker-style-guide.md` hasn't been spot-checked against
  `assets/css/rxtracker-brand-tokens.css` for drift since recent UI changes (allergies modal, group
  modal).
- `refillsForMonth()`'s correlated subquery — fine at current scale.
- Two per-user hybrid schema-migration methods (mood-tag seeding, notes backfill) still run per
  user rather than once globally — low cost at current scale.

---

## 11. MVP Readiness

**MVP Status: POST-MVP / BETA READY**

### Completed Core Requirements
The full MVP loop this audit was asked to evaluate — *account → add medication → schedule → doses
tracked (Taken/Skipped/Missed) → inventory updates → history retained → refill warning → reliable
management* — is implemented and was traced end-to-end without finding a broken link in the chain.
Beyond that minimum, RxTracker also ships family profiles, medication groups, pain/mood tracking,
side-effect logging, allergy tracking, a calendar view, PDF export, and background push
notifications — substantially more than a minimum MVP requires, which is why this is scored as
Post-MVP/Beta rather than merely "MVP ready."

### MVP Blockers
None identified. The one real data-integrity bug found (refill-amount truncation, BUG-001) is
fixed as part of this same pass, so nothing in the traced core workflow is currently broken or
unsafe.

### Recommended Before Public Release
- Add CI to run the existing 15 tests automatically (currently manual-only).
- Close the CSP `'unsafe-inline'` trade-off (nonces).
- Do a real browser/device pass on the PWA install flow and push notification delivery — this
  audit could trace the code but not click through it.
- A dedicated accessibility pass (contrast, full keyboard navigation, screen-reader spot-check) —
  not performed this pass beyond noting that modals consistently carry `role`/`aria-*` attributes.
- Decide the product question behind BUG-005 (should starting quantity be required at add time?).

### Safe to Defer Until Post-MVP
- The three god-file decompositions (REF-005) — real debt, but the app functions correctly with
  them as-is; each is a meaningful, higher-risk project of its own.
- The schema-source-of-truth consolidation (REF-004) — an automated drift *check* (CI diff) is
  worth doing soon; the deeper migration-runner rewrite is not urgent.
- The "Update prescribed dose" modal deduplication (REF-003) — cosmetic/maintainability, not
  functionality.
- The `RxImage` dead-code verification and style-guide drift check (P3 items).

---

## 12. Recommended Next Development Priorities

**Immediate — P0:** None outstanding — the one data-integrity bug found this pass is already fixed.

**Before MVP Release — P1:**
1. Wire up CI to run `tests/*.php` and `php -l` on every push/PR.
2. Add an automated check that `database/schema.sql` matches a fresh `SchemaInstaller` sweep, to
   prevent the kind of drift already found once (REF-004 option a).
3. Do a real-device pass on PWA install + push notification delivery timing.

**Soon After MVP — P2:**
1. Extract the "Update prescribed dose" modal into one shared partial (REF-003).
2. Make group-mutation ownership failures return an explicit error instead of a silent `{ok:true}`
   no-op (BUG-006).
3. Thread the real inventory unit through the zero-pill interstitial's refill entry point (finish
   BUG-002).
4. Close the CSP `'unsafe-inline'` gap with nonces.
5. Add a lightweight accessibility pass.

**Future / Post-MVP — P3:**
1. Decompose `assets/js/app.js`, `assets/css/styles.css`, and `includes/SchemaInstaller.php`
   incrementally, following the `MedicationRepository.php` precedent (REF-005).
2. Verify and likely remove the unused `RxImage` allowlist entry in `api-proxy.php`.
3. Spot-check `docs/rxtracker-style-guide.md` against the current brand-tokens CSS.
4. Consider a real migration runner if the schema-truth consolidation (REF-004 option b) becomes
   worth the risk later.
