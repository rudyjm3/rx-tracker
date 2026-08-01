# RxTracker Rebuild Guide
## Next.js (Web) + Expo (Android) + Supabase

---

## 1. Supabase Schema

Create a new Supabase project at supabase.com, open the SQL Editor, and run this entire block.
Supabase handles auth natively — the `users` and `user_sessions` tables from MySQL are replaced
by Supabase Auth automatically. All other tables migrate directly.

```sql
-- Enable UUID extension (Supabase uses UUIDs instead of INT auto-increment)
create extension if not exists "uuid-ossp";

-- ─────────────────────────────────────────
-- FAMILY PROFILES
-- (optional grouping — one user can track meds for multiple people)
-- ─────────────────────────────────────────
create table if not exists family_profiles (
  id            uuid primary key default uuid_generate_v4(),
  user_id       uuid not null references auth.users(id) on delete cascade,
  display_name  text not null,
  avatar_color  text,
  relationship  text,
  birth_year    int,
  created_at    timestamptz default now()
);

-- ─────────────────────────────────────────
-- MEDICATIONS
-- ─────────────────────────────────────────
create table if not exists medications (
  id                      uuid primary key default uuid_generate_v4(),
  user_id                 uuid not null references auth.users(id) on delete cascade,
  profile_id              uuid references family_profiles(id) on delete set null,
  name                    text not null,
  dose                    text not null default '',
  dose_amount             numeric(10,3),
  dose_unit               text,
  dose_form               text,
  instructions            text not null default '',
  schedule_mode           text not null default 'fixed_times'
                            check (schedule_mode in ('fixed_times','interval')),
  interval_hours          smallint,
  first_dose_time         time,
  as_needed               boolean not null default false,
  medication_type         text not null default 'prescription'
                            check (medication_type in ('prescription','otc','supplement')),
  inventory_type          text not null default 'pills',
  inventory_unit          text not null default 'tablets',
  starting_quantity       numeric(10,3),
  current_quantity        numeric(10,3),
  quantity_per_dose       numeric(10,3) not null default 1,
  low_supply_threshold    int not null default 5,
  track_dose_feedback     boolean not null default false,
  feedback_type           text not null default 'none'
                            check (feedback_type in ('none','pain','mood','both')),
  start_date              date,
  active                  boolean not null default true,
  setup_status            text not null default 'active'
                            check (setup_status in ('draft','ready','active')),
  dashboard_enabled       boolean not null default true,
  reminders_enabled       boolean not null default true,
  adherence_enabled       boolean not null default true,
  inventory_enabled       boolean not null default false,
  sort_order              smallint not null default 0,
  created_at              timestamptz default now(),
  updated_at              timestamptz default now()
);

-- ─────────────────────────────────────────
-- MEDICATION SCHEDULE TIMES
-- (one row per fixed-time slot per medication)
-- ─────────────────────────────────────────
create table if not exists medication_schedule_times (
  id                uuid primary key default uuid_generate_v4(),
  medication_id     uuid not null references medications(id) on delete cascade,
  reminder_time     time not null,
  quantity_per_dose numeric(10,3),
  created_at        timestamptz default now(),
  unique (medication_id, reminder_time)
);

-- ─────────────────────────────────────────
-- DOSE LOGS
-- ─────────────────────────────────────────
create table if not exists dose_logs (
  id                  uuid primary key default uuid_generate_v4(),
  medication_id       uuid not null references medications(id) on delete cascade,
  scheduled_for_date  date not null,
  scheduled_time      time not null,
  status              text not null default 'taken'
                        check (status in ('taken','skipped','missed')),
  note                text not null default '',
  pain_level          smallint check (pain_level between 1 and 10),
  mood_level          smallint check (mood_level between 1 and 10),
  deducted_quantity   numeric(10,3),
  taken_at            timestamptz default now(),
  feedback_edited_at  timestamptz,
  created_at          timestamptz default now(),
  unique (medication_id, scheduled_for_date, scheduled_time)
);

-- ─────────────────────────────────────────
-- DOSE POSTPONES (snooze)
-- ─────────────────────────────────────────
create table if not exists dose_postpones (
  id                  uuid primary key default uuid_generate_v4(),
  medication_id       uuid not null references medications(id) on delete cascade,
  scheduled_for_date  date not null,
  scheduled_time      time not null,
  postponed_until     timestamptz not null,
  resolved_at         timestamptz,
  created_at          timestamptz default now(),
  unique (medication_id, scheduled_for_date, scheduled_time)
);

-- ─────────────────────────────────────────
-- SIDE EFFECTS
-- ─────────────────────────────────────────
create table if not exists side_effects (
  id            uuid primary key default uuid_generate_v4(),
  medication_id uuid not null references medications(id) on delete cascade,
  occurred_date date not null,
  description   text not null,
  severity      text not null default 'mild'
                  check (severity in ('mild','moderate','severe')),
  note          text not null default '',
  created_at    timestamptz default now()
);

-- ─────────────────────────────────────────
-- DOSE CHANGE HISTORY
-- ─────────────────────────────────────────
create table if not exists medication_dose_changes (
  id              uuid primary key default uuid_generate_v4(),
  medication_id   uuid not null references medications(id) on delete cascade,
  changed_at      timestamptz not null default now(),
  old_dose_amount numeric(10,3),
  old_dose_unit   text not null default '',
  new_dose_amount numeric(10,3),
  new_dose_unit   text not null default '',
  comment         text not null default '',
  created_at      timestamptz default now()
);

-- ─────────────────────────────────────────
-- MEDICATION STATUS EVENTS (discontinue / resume)
-- ─────────────────────────────────────────
create table if not exists medication_status_events (
  id            uuid primary key default uuid_generate_v4(),
  medication_id uuid not null references medications(id) on delete cascade,
  event         text not null,
  event_at      timestamptz not null default now(),
  reason        text not null default '',
  comment       text not null default '',
  created_at    timestamptz default now()
);

-- ─────────────────────────────────────────
-- MEDICATION GROUPS (take-together clusters)
-- ─────────────────────────────────────────
create table if not exists medication_groups (
  id             uuid primary key default uuid_generate_v4(),
  user_id        uuid not null references auth.users(id) on delete cascade,
  profile_id     uuid references family_profiles(id) on delete set null,
  name           text not null,
  scheduled_time time not null,
  active         boolean not null default true,
  sort_order     smallint not null default 0,
  created_at     timestamptz default now(),
  updated_at     timestamptz default now()
);

create table if not exists medication_group_members (
  group_id          uuid not null references medication_groups(id) on delete cascade,
  medication_id     uuid not null references medications(id) on delete cascade,
  sort_order        smallint not null default 0,
  quantity_per_dose numeric(10,3),
  primary key (group_id, medication_id)
);

-- ─────────────────────────────────────────
-- MEDICATION NOTES
-- ─────────────────────────────────────────
create table if not exists medication_notes (
  id            uuid primary key default uuid_generate_v4(),
  medication_id uuid not null references medications(id) on delete cascade,
  note          text not null,
  created_at    timestamptz default now(),
  updated_at    timestamptz default now()
);

-- ─────────────────────────────────────────
-- STANDALONE PAIN / MOOD LOGS
-- ─────────────────────────────────────────
create table if not exists standalone_pain_mood_logs (
  id            uuid primary key default uuid_generate_v4(),
  user_id       uuid not null references auth.users(id) on delete cascade,
  medication_id uuid not null references medications(id) on delete cascade,
  log_type      text not null check (log_type in ('pain','mood','both')),
  pain_level    smallint check (pain_level between 1 and 10),
  mood_level    smallint check (mood_level between 1 and 10),
  note          text not null default '',
  tags          text not null default '',
  logged_at     timestamptz default now(),
  updated_at    timestamptz
);

-- ─────────────────────────────────────────
-- MOOD TAGS
-- ─────────────────────────────────────────
create table if not exists mood_tags (
  id          uuid primary key default uuid_generate_v4(),
  user_id     uuid not null references auth.users(id) on delete cascade,
  name        text not null,
  always_show boolean not null default true,
  sort_order  int not null default 0,
  created_at  timestamptz default now(),
  unique (user_id, name)
);

-- ─────────────────────────────────────────
-- APP SETTINGS (per user key-value store)
-- ─────────────────────────────────────────
create table if not exists app_settings (
  user_id       uuid not null references auth.users(id) on delete cascade,
  setting_key   text not null,
  setting_value text not null,
  updated_at    timestamptz default now(),
  primary key (user_id, setting_key)
);

-- ─────────────────────────────────────────
-- PUSH SUBSCRIPTIONS (Expo push tokens)
-- ─────────────────────────────────────────
create table if not exists push_subscriptions (
  id          uuid primary key default uuid_generate_v4(),
  user_id     uuid not null references auth.users(id) on delete cascade,
  expo_token  text not null unique,
  device_name text not null default '',
  created_at  timestamptz default now(),
  updated_at  timestamptz default now()
);

-- ─────────────────────────────────────────
-- USER NOTIFICATIONS (in-app alerts)
-- ─────────────────────────────────────────
create table if not exists user_notifications (
  id            uuid primary key default uuid_generate_v4(),
  user_id       uuid not null references auth.users(id) on delete cascade,
  medication_id uuid not null references medications(id) on delete cascade,
  type          text not null
                  check (type in ('low_stock','critical_stock','out_of_stock')),
  is_read       boolean not null default false,
  is_dismissed  boolean not null default false,
  created_at    timestamptz default now()
);

-- ─────────────────────────────────────────
-- ROW LEVEL SECURITY
-- Every user can only see their own data.
-- Run this after creating tables.
-- ─────────────────────────────────────────
alter table medications              enable row level security;
alter table medication_schedule_times enable row level security;
alter table dose_logs                enable row level security;
alter table dose_postpones           enable row level security;
alter table side_effects             enable row level security;
alter table medication_dose_changes  enable row level security;
alter table medication_status_events enable row level security;
alter table medication_groups        enable row level security;
alter table medication_group_members enable row level security;
alter table medication_notes         enable row level security;
alter table standalone_pain_mood_logs enable row level security;
alter table mood_tags                enable row level security;
alter table app_settings             enable row level security;
alter table push_subscriptions       enable row level security;
alter table user_notifications       enable row level security;
alter table family_profiles          enable row level security;

-- Policies: users only touch their own rows
create policy "own medications"
  on medications for all using (auth.uid() = user_id);

create policy "own schedule times"
  on medication_schedule_times for all
  using (medication_id in (select id from medications where user_id = auth.uid()));

create policy "own dose logs"
  on dose_logs for all
  using (medication_id in (select id from medications where user_id = auth.uid()));

create policy "own postpones"
  on dose_postpones for all
  using (medication_id in (select id from medications where user_id = auth.uid()));

create policy "own side effects"
  on side_effects for all
  using (medication_id in (select id from medications where user_id = auth.uid()));

create policy "own dose changes"
  on medication_dose_changes for all
  using (medication_id in (select id from medications where user_id = auth.uid()));

create policy "own status events"
  on medication_status_events for all
  using (medication_id in (select id from medications where user_id = auth.uid()));

create policy "own groups"
  on medication_groups for all using (auth.uid() = user_id);

create policy "own group members"
  on medication_group_members for all
  using (group_id in (select id from medication_groups where user_id = auth.uid()));

create policy "own notes"
  on medication_notes for all
  using (medication_id in (select id from medications where user_id = auth.uid()));

create policy "own pain mood logs"
  on standalone_pain_mood_logs for all using (auth.uid() = user_id);

create policy "own mood tags"
  on mood_tags for all using (auth.uid() = user_id);

create policy "own settings"
  on app_settings for all using (auth.uid() = user_id);

create policy "own push subscriptions"
  on push_subscriptions for all using (auth.uid() = user_id);

create policy "own notifications"
  on user_notifications for all using (auth.uid() = user_id);

create policy "own family profiles"
  on family_profiles for all using (auth.uid() = user_id);
```

---

## 2. Next.js Web App — Project Structure

```
rx-tracker-web/
│
├── app/                          # Next.js App Router
│   ├── layout.tsx                # Root layout (nav, auth wrapper)
│   ├── page.tsx                  # Redirects to /dashboard
│   ├── (auth)/
│   │   ├── login/page.tsx        # Login screen
│   │   └── signup/page.tsx       # Sign up screen
│   ├── dashboard/
│   │   └── page.tsx              # Today's schedule, adherence, next dose
│   ├── calendar/
│   │   └── page.tsx              # Monthly calendar view
│   ├── medications/
│   │   ├── page.tsx              # Medication plan list
│   │   └── [id]/page.tsx         # Edit medication
│   ├── history/
│   │   └── page.tsx              # Recent dose logs
│   ├── export/
│   │   └── page.tsx              # Doctor Visit Report / print view
│   └── settings/
│       └── page.tsx              # Grace period, notifications, account
│
├── components/
│   ├── ui/
│   │   ├── Button.tsx
│   │   ├── Modal.tsx
│   │   └── Badge.tsx
│   ├── medications/
│   │   ├── MedicationCard.tsx
│   │   ├── MedicationForm.tsx
│   │   └── PainGraph.tsx
│   ├── dashboard/
│   │   ├── NextDoseCard.tsx
│   │   ├── ScheduleList.tsx
│   │   └── AdherenceSummary.tsx
│   └── layout/
│       ├── TopNav.tsx
│       └── BottomNav.tsx         # Mobile-friendly bottom nav
│
├── lib/
│   ├── supabase.ts               # Supabase client (browser)
│   ├── supabase-server.ts        # Supabase client (server components)
│   ├── medications.ts            # Data functions (replaces MedicationRepository.php)
│   ├── dose-logs.ts              # Dose logging functions
│   ├── schedule.ts               # Today schedule calculation
│   └── utils.ts                  # Time formatting, adherence calc
│
├── hooks/
│   ├── useMedications.ts         # React Query hook for medications
│   ├── useTodaySchedule.ts       # Hook for today's doses
│   └── useRealtimeSync.ts        # Supabase realtime subscription
│
├── middleware.ts                  # Redirect unauthenticated users to /login
│
├── public/
│   └── icons/                    # Favicon, PWA icons (reuse existing)
│
├── .env.local                    # Supabase URL + anon key
├── next.config.ts
├── tailwind.config.ts
└── package.json
```

### Key dependencies to install:
```bash
npx create-next-app@latest rx-tracker-web --typescript --tailwind --app
cd rx-tracker-web
npm install @supabase/supabase-js @supabase/ssr
npm install @tanstack/react-query
npm install recharts          # pain level charts (same library used for SVG charts)
npm install date-fns           # date formatting
```

### .env.local
```
NEXT_PUBLIC_SUPABASE_URL=https://your-project.supabase.co
NEXT_PUBLIC_SUPABASE_ANON_KEY=your-anon-key
```

---

## 3. Expo Android App — Project Structure

```
rx-tracker-app/
│
├── app/                          # Expo Router (file-based routing)
│   ├── _layout.tsx               # Root layout, auth check
│   ├── (auth)/
│   │   ├── login.tsx             # Login screen
│   │   └── signup.tsx            # Sign up screen
│   ├── (tabs)/
│   │   ├── _layout.tsx           # Bottom tab navigator
│   │   ├── index.tsx             # Dashboard tab
│   │   ├── calendar.tsx          # Calendar tab
│   │   ├── medications.tsx       # Medication plan tab
│   │   └── settings.tsx          # Settings tab
│   └── medication/
│       ├── add.tsx               # Add medication screen
│       └── [id].tsx              # Edit medication screen
│
├── components/
│   ├── medications/
│   │   ├── MedicationCard.tsx
│   │   ├── MedicationForm.tsx
│   │   └── PainGraph.tsx
│   ├── dashboard/
│   │   ├── NextDoseCard.tsx
│   │   ├── ScheduleRow.tsx
│   │   └── AdherenceSummary.tsx
│   └── ui/
│       ├── Button.tsx
│       ├── Modal.tsx
│       └── Badge.tsx
│
├── lib/
│   ├── supabase.ts               # Supabase client (uses AsyncStorage)
│   ├── medications.ts            # Same data functions as web (shared logic)
│   ├── dose-logs.ts
│   ├── schedule.ts
│   └── utils.ts
│
├── hooks/
│   ├── useMedications.ts
│   ├── useTodaySchedule.ts
│   └── useRealtimeSync.ts
│
├── notifications/
│   ├── setup.ts                  # Request permissions, register Expo token
│   ├── scheduler.ts              # Schedule local notifications for today's doses
│   └── background-task.ts        # Background fetch to reschedule daily
│
├── assets/
│   └── icons/                    # Reuse existing icons from rx-tracker repo
│
├── app.json                      # Expo config (app name, bundle ID, permissions)
├── eas.json                      # EAS Build config (preview profile for APK)
├── .env                          # Supabase URL + anon key
└── package.json
```

### Key dependencies to install:
```bash
npx create-expo-app rx-tracker-app --template blank-typescript
cd rx-tracker-app
npx expo install expo-router expo-notifications expo-task-manager
npx expo install expo-background-fetch expo-device
npx expo install @supabase/supabase-js @react-native-async-storage/async-storage
npm install @tanstack/react-query
npm install date-fns
```

### app.json (critical permissions block)
```json
{
  "expo": {
    "name": "RxTracker",
    "slug": "rx-tracker",
    "version": "1.0.0",
    "android": {
      "package": "com.yourname.rxtracker",
      "permissions": [
        "RECEIVE_BOOT_COMPLETED",
        "SCHEDULE_EXACT_ALARM",
        "USE_EXACT_ALARM",
        "POST_NOTIFICATIONS"
      ]
    },
    "plugins": [
      ["expo-notifications", {
        "icon": "./assets/icons/icon-192.png",
        "color": "#0754A8",
        "sounds": ["./assets/notification-sound.wav"]
      }]
    ]
  }
}
```

### eas.json (for sideloading — produces APK not AAB)
```json
{
  "build": {
    "preview": {
      "android": {
        "buildType": "apk"
      }
    },
    "production": {
      "android": {
        "buildType": "app-bundle"
      }
    }
  }
}
```

---

## 4. Shared Supabase Client (identical in both projects)

**lib/supabase.ts** (Web version)
```typescript
import { createBrowserClient } from '@supabase/ssr'

export const supabase = createBrowserClient(
  process.env.NEXT_PUBLIC_SUPABASE_URL!,
  process.env.NEXT_PUBLIC_SUPABASE_ANON_KEY!
)
```

**lib/supabase.ts** (Expo version)
```typescript
import { createClient } from '@supabase/supabase-js'
import AsyncStorage from '@react-native-async-storage/async-storage'

export const supabase = createClient(
  process.env.EXPO_PUBLIC_SUPABASE_URL!,
  process.env.EXPO_PUBLIC_SUPABASE_ANON_KEY!,
  {
    auth: {
      storage: AsyncStorage,       // persists session on device
      autoRefreshToken: true,
      persistSession: true,
      detectSessionInUrl: false,
    },
  }
)
```

---

## 5. Notification Flow (Expo — replaces the entire VAPID/cron system)

**notifications/setup.ts**
```typescript
import * as Notifications from 'expo-notifications'
import * as Device from 'expo-device'
import { supabase } from '../lib/supabase'

export async function registerForPushNotifications() {
  if (!Device.isDevice) return  // won't work in emulator

  const { status } = await Notifications.requestPermissionsAsync()
  if (status !== 'granted') return

  // Get the Expo push token for this device
  const token = (await Notifications.getExpoPushTokenAsync()).data

  // Save it to Supabase so the Edge Function can reach this device
  const { data: { user } } = await supabase.auth.getUser()
  if (!user) return

  await supabase.from('push_subscriptions').upsert({
    user_id: user.id,
    expo_token: token,
    device_name: Device.deviceName ?? 'Android device',
  }, { onConflict: 'expo_token' })
}
```

**notifications/scheduler.ts**
```typescript
import * as Notifications from 'expo-notifications'
import { supabase } from '../lib/supabase'

// Call this on app open and daily via background task
// Schedules local notifications for today's upcoming doses
export async function scheduleTodayNotifications() {
  await Notifications.cancelAllScheduledNotificationsAsync()

  const today = new Date().toISOString().split('T')[0]
  const { data: schedule } = await supabase
    .from('medication_schedule_times')
    .select('*, medications(name, dose, reminders_enabled)')
    .eq('medications.active', true)
    .eq('medications.reminders_enabled', true)

  if (!schedule) return

  for (const slot of schedule) {
    const [hour, minute] = slot.reminder_time.split(':').map(Number)
    const fireDate = new Date()
    fireDate.setHours(hour, minute, 0, 0)
    if (fireDate <= new Date()) continue  // already passed today

    await Notifications.scheduleNotificationAsync({
      content: {
        title: `Time to take ${slot.medications.name}`,
        body: slot.medications.dose,
        sound: true,
        data: { medicationId: slot.medication_id, scheduledTime: slot.reminder_time },
      },
      trigger: { date: fireDate },
    })
  }
}
```

---

## 6. Build Commands Reference

### Next.js Web
```bash
cd rx-tracker-web
npm run dev          # local development at localhost:3000
npm run build        # production build
npm run start        # serve production build
```

### Expo Android APK (cloud build — no Android Studio needed)
```bash
cd rx-tracker-app
npm install -g eas-cli
eas login            # one-time login to your Expo account (free)
eas build --platform android --profile preview
# Wait for build → download APK link → transfer to phone → install
```

### Expo local dev (test on phone without building APK)
```bash
npx expo start
# Scan QR code with Expo Go app on your Android phone
# Instant preview — no APK needed during development
```

---

## 7. Recommended Build Order

1. **Supabase first** — create project, run schema SQL, note your URL and anon key
2. **Next.js web app** — faster to iterate, test auth and data layer in a browser
3. **Expo app** — reuse the lib/ and hooks/ logic from web, build native UI on top
4. **Notifications** — wire up last once data layer is confirmed working
5. **EAS Build** — produce APK and sideload to test on real device

---

## 8. What Carries Over from the Existing PHP App

| Existing file | Reuse how |
|---|---|
| `database/schema.sql` | Used directly above (translated to Postgres) |
| `assets/css/styles.css` | Reference for colors and layout — recreate as Tailwind (web) or StyleSheet (Expo) |
| `assets/icons/` | Copy directly into both projects |
| `assets/icons/logo-round.png` | Reuse as app icon |
| `manifest.json` | Reference for PWA metadata — recreate in next.config.ts |
| Business logic in `MedicationRepository.php` | Recreate as TypeScript functions in lib/medications.ts |
| `sw.js` / VAPID push | Replaced entirely by expo-notifications |
| `scripts/send_due_push.php` | Replaced by Supabase Edge Function or local Expo scheduler |
