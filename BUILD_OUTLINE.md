# RxTracker Build Outline

## Product Goal
Build a standalone, low-friction medication reminder and adherence tracker where users can quickly see what is due, log taken/skipped doses, and monitor supply/refill risk.

## Core MVP Outcomes
1. User can add and manage active medications.
2. Dashboard highlights the next due dose and today adherence.
3. User can mark each scheduled dose as Taken or Skipped with skip confirmation.
4. Missed doses are visible and included in adherence/missed counts.
5. Pill counts auto-decrement on taken doses.
6. Refill alert thresholds are visible per medication.
7. Recent medication history is viewable.

## Functional Scope (From Project Chat)

### 1. Medication Setup and Management
- Add medication with name, dose, schedule, instructions.
- Support fixed-times and interval-based schedules.
- Mark medications as active/inactive (without deleting history).
- Edit medication details.

### 2. Reminders and Dose Actions
- Alert/reminder when dose time arrives.
- User actions: Taken or Skipped.
- Skip action includes confirmation warning.
- If no action occurs within configured window, mark dose as missed.

### 3. Adherence and Daily Workflow
- Dashboard card for next upcoming dose.
- Daily schedule list with status badges.
- Adherence summary (taken vs required doses).
- Missed-dose count for current day.
- Track on-time vs late doses.

### 4. Pill Inventory and Refill Support
- Store starting and current pill counts.
- Decrement current count by pills-per-dose when a dose is taken.
- Show refill alert threshold and low-supply warning.
- Predict days-left/runout estimate (future enhancement from chat).

### 5. History and Calendar Visibility
- Recent history of dose actions.
- Calendar/month view with visual markers:
  - Taken
  - Skipped
  - Missed
  - Upcoming
  - Refill date marker

### 6. UX and Interaction Principles
- Fast main flow: open app -> see next dose -> one tap action.
- Progressive disclosure for advanced setup details.
- Clean, uncluttered dashboard with clear warning states.

### 7. Nice-to-Have / Post-MVP Enhancements
- Snooze reminders (10/15/30 min).
- Persistent reminder until action is taken.
- Export/share medication list (PDF/share view).
- Dose history over time (dose changes).
- Side-effect/feedback logging.

## Data Model Targets (From Chat + Existing Direction)
- `medications`
  - identity/details
  - schedule mode metadata
  - active flag
  - starting/current pill counts
  - refill threshold and refill metadata
- `medication_schedule_times`
  - one or more fixed times per medication
- `dose_logs`
  - scheduled date/time
  - action status: taken/skipped/missed
  - action timestamp and note

## Implementation Milestones
1. Add/edit medication form and storage.
2. Medication plan list.
3. Dose schedule setup.
4. Dashboard next-dose card.
5. Taken/skipped logging.
6. Pill count update.
7. Refill warning surface.
8. Missed/adherence stats.
9. Calendar view.
10. Export/share list.
