# Build Status Comparison

Comparison date: 2026-05-08
Source outline: `BUILD_OUTLINE.md`

## Legend
- Built: Implemented and wired in current app flow.
- Partial: Some foundation exists, but feature is incomplete.
- Not Built: No implementation in current code path.

## Feature Status Matrix

| Outline Item | Status | Evidence in Current Code | Gap / Notes |
|---|---|---|---|
| Add medication | Built | `index.php` POST `add_medication`; modal form | Core fields present for current model. |
| Edit medication | Built | `index.php?edit=...`; `update_medication` flow | In-place via modal edit link. |
| Active/inactive medication support | Built | `deactivate_medication` action + repository method | Deactivate exists; no re-activate UI yet. |
| Fixed-time schedules | Built | `schedule_mode=fixed_times`; `medication_schedule_times` table | Supports multi-time entries. |
| Interval schedules | Built | `schedule_mode=interval`, `interval_hours`, `first_dose_time` | Next-due logic enforced with interval checks. |
| Dashboard next dose card | Built | `index.php` `Next dose` panel | Shows med, dose, time, instructions. |
| Today schedule list with statuses | Built | `Today schedule` panel + taken/skipped badges | Includes PRN marker and action buttons. |
| Taken action logging | Built | `mark_dose` and `log_dose_now` actions | Writes to `dose_logs` and updates inventory. |
| Skipped action with confirmation | Built | `data-confirm="Confirm skipped dose?"` + JS confirm handler | Matches requested UX behavior. |
| Missed dose visibility/count | Built | `missedDoseCount(...)` and adherence summary | Count shown on dashboard summary card. |
| Adherence summary | Built | `adherence` calculation in `index.php` | Required doses only. |
| Starting/current pill count display | Built | Medication plan row: `Pills: starting/current ...` | Recently added and persisted in DB. |
| Pill decrement on taken dose | Built | `deductPillCount(...)` on taken transitions | Decrements current count only. |
| Refill threshold display | Built | `low_supply_threshold` shown per medication | Threshold visible in plan rows. |
| Low-supply proactive warning state | Partial | Threshold exists in data and display text | No dedicated dynamic warning banner/state logic. |
| Recent history view | Built | `Recent history` panel + list | Shows time, med name, status. |
| Recent history capped + scrollable | Built | `.history-list { max-height + overflow-y:auto; }` | UI cap enforced. |
| Medication list capped + scrollable | Built | `.medication-list { max-height + overflow-y:auto; }` | UI cap enforced. |
| Medication panel collapse/expand | Built | `data-medication-plan-toggle` + JS state toggle | Defaults collapsed on load. |
| AM/PM schedule time display | Built | `to12h(...)` usage in next/schedule/history | Consistent 12-hour labels in UI. |
| Notification/alarm at dose time | Not Built | No browser notification/alarm scheduler in JS/PHP | Requires client-side notifications/service worker strategy. |
| Persistent reminders until action | Not Built | No reminder retry/escalation logic | Depends on reminder engine. |
| Snooze (10/15/30) | Not Built | No snooze UI or scheduling logic | Post-MVP item not implemented. |
| Auto-mark missed after timeout | Partial | `missedDoseCount` calculates missed for display | No background job writing missed rows automatically. |
| On-time vs late tracking | Partial | `recordDoseStatus` has deterministic timestamps for scheduled actions | No explicit `was_on_time` field or late classification surfaced. |
| Calendar/month visualization | Not Built | `calendarMarkersForMonth()` exists in repository only | No rendered calendar UI in `index.php`. |
| Refill date / days-left prediction | Not Built | No refill-date model fields or runout computation in app flow | Threshold-only warning currently. |
| Export/share medication list | Not Built | No export endpoints/PDF/share UI | Listed in outline as later milestone. |
| Side-effect/feedback logging | Not Built | Only generic dose note field | No dedicated symptom/effect tracking workflow. |
| Doctor/pharmacy metadata capture | Not Built | Form does not capture provider/pharmacy fields | Mentioned in project chat expansion. |

## Overall Progress Summary
- Built: Core medication tracking workflow is functional (setup, scheduling, taken/skipped logging, adherence, pill-count updates, and history).
- Partial: Some advanced adherence/refill behaviors have data or logic scaffolding but are not fully productized.
- Not Built: Reminder engine, snooze/persistent notifications, calendar UI, export/share, and extended clinical metadata.

## Recommended Next Build Order (From Current State)
1. Implement reminder engine (browser notifications + scheduling strategy).
2. Add missed-dose auto-finalization job/logic.
3. Add snooze and persistent reminder behavior.
4. Render calendar UI using existing repository marker support.
5. Add refill prediction (days-left + runout estimate).
6. Add export/share and optional provider/pharmacy metadata.
