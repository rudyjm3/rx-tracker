# RxTracker Brand Style Guide

## Overview

RxTracker uses a clean, mobile-first medical design language built around a teal-to-deep-blue gradient palette. Cards are white on a soft blue background, with rounded corners and subtle shadows for a friendly, trustworthy feel.

---

## Core Gradient

The brand gradient runs from teal through primary blue to deep blue — used for the hero panel, active nav, primary CTAs, and the app icon.

```css
/* Standard brand gradient (CTAs, active nav, primary buttons) */
background: linear-gradient(135deg, #14CFE0 0%, #0A8AC8 48%, #0754A8 100%);

/* Hero section gradient (richer, dark-to-light) */
background: linear-gradient(160deg, #071D3D 0%, #0754A8 45%, #0A8AC8 75%, #14CFE0 100%);
```

---

## Brand Colors

| Token | Hex | Usage |
|---|---|---|
| `--rx-cyan` | `#14CFE0` | Gradient highlight, active glow, progress ring fill |
| `--rx-blue` | `#0A8AC8` | Gradient mid-tone, icon accents, clock icons |
| `--rx-deep-blue` | `#0754A8` | Primary CTA, active nav, links |
| `--rx-navy` | `#102B57` | Headers, secondary buttons |
| `--rx-dark-navy` | `#071D3D` | Hero gradient start, dark backgrounds |
| `--rx-bg` | `#EAF4FF` | Page background, empty states, soft cards |
| `--rx-card` | `#FFFFFF` | Card surfaces |
| `--rx-border` | `#D7E6F8` | Card borders, inputs, dividers, schedule row lines |
| `--rx-text` | `#172033` | Primary body text |
| `--rx-text-muted` | `#60708A` | Secondary text, timestamps, meta info |
| `--rx-success` | `#18BFA6` | Taken / on-time dose states |
| `--rx-warning` | `#F5A524` | Refill soon, late doses, pending |
| `--rx-danger` | `#E5484D` | Missed / skipped dose alerts |

---

## Typography

```css
font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
```

| Use | Size | Weight |
|---|---|---|
| Page/section headings | `1rem` | `800` |
| Hero medication name | `1.5rem` | `900` |
| Hero time display | `1.9rem` | `900` |
| Body / schedule items | `0.9–0.92rem` | `600–800` |
| Muted / meta text | `0.78–0.84rem` | `400–700` |
| Badge / pill labels | `0.74–0.82rem` | `800–900` |

---

## Border Radius

| Token | Value | Use |
|---|---|---|
| `--rx-radius-sm` | `10px` | Buttons, inputs, small cards, schedule rows |
| `--rx-radius-md` | `18px` | Main panels and cards |
| `--rx-radius-lg` | `28px` | Hero section |
| `1.5rem` | `24px` | Hero glassmorphic cards |
| `50%` | circle | Icon circles, quick-action icons |

---

## Shadows

```css
--rx-shadow: 0 18px 45px rgba(7, 29, 61, 0.14);  /* Standard card shadow */
```

Hero cards use a deeper shadow: `0 24px 60px rgba(3, 18, 42, 0.24)`

---

## Buttons

### Primary (gradient)
```css
background: var(--rx-gradient);
border: none;
border-radius: var(--rx-radius-sm);
color: #fff;
font-weight: 800;
padding: 0.9rem 1.2rem;
```
Hover: `var(--rx-gradient-dark)` + `box-shadow: 0 12px 24px rgba(7, 84, 168, 0.28)` + `translateY(-1px)`

### Secondary (outline)
```css
background: var(--rx-bg);
border: 1px solid var(--rx-border);
border-radius: var(--rx-radius-sm);
color: var(--rx-navy);
```
Hover: `background: var(--rx-border)`; no shadow.

### Schedule "Take" button
Smaller primary — `padding: 0.5rem 0.9rem; font-size: 0.82rem`

### Medication action buttons (Log dose now, Log refill, etc.)
Secondary style with icon + text: `display: inline-flex; gap: 0.4rem; font-size: 0.82rem; padding: 0.5rem 0.8rem`

---

## Icons

Uses **Font Awesome 6.5** (CDN loaded). Key icon usage:

| Context | Icon class |
|---|---|
| Clock / schedule time | `fa-regular fa-clock` |
| Calendar link | `fa-regular fa-calendar` |
| Calendar check / adherence | `fa-regular fa-calendar-check` |
| Bottom nav – Dashboard | `fa-solid fa-house` |
| Bottom nav – Medications | `fa-solid fa-pills` |
| Bottom nav – Calendar | `fa-regular fa-calendar` |
| Bottom nav – Export | `fa-solid fa-file-export` |
| Bottom nav – More | `fa-solid fa-ellipsis` |
| Quick action – Add | `fa-solid fa-plus` |
| Quick action – Log dose | `fa-regular fa-file-lines` |
| Quick action – Manage | `fa-solid fa-pills` |
| Medications overview | `fa-regular fa-rectangle-list` |
| Log dose now | `fa-regular fa-circle-check` |
| Log refill | `fa-regular fa-calendar-plus` |
| Refill history | `fa-solid fa-clock-rotate-left` |
| Deactivate | `fa-solid fa-power-off` |
| Chevron right | `fa-solid fa-chevron-right` |

---

## Status Badges / Pills

```css
/* Taken */
background: rgba(24, 191, 166, 0.12);  color: #18BFA6;

/* Warning / late */
background: rgba(245, 165, 36, 0.12);  color: #F5A524;

/* Missed / danger */
background: rgba(229, 72, 77, 0.10);   color: #E5484D;
```

All pills: `border-radius: 999px; font-size: 0.74rem; font-weight: 900; padding: 0.3rem 0.65rem`

---

## Hero Section

The hero banner uses `--rx-gradient-hero` (dark navy → teal) with two radial overlays for depth. Two glassmorphic cards side-by-side:

1. **Next Dose card** (left, wider) — eyebrow label, large time + medication name, dose badge, decorative pill graphic, upcoming dose row
2. **Adherence card** (right) — eyebrow label, animated gradient ring (SVG `linearGradient` from `#0A8AC8` → `#14CFE0`), stats list

Glass card style:
```css
backdrop-filter: blur(18px);
background: rgba(255, 255, 255, 0.13);
border: 1px solid rgba(255, 255, 255, 0.24);
border-radius: 1.5rem;
```

Pill graphic: `72px circle, linear-gradient(135deg, #fff 50%, #0A8AC8 50%)`

---

## Dashboard Layout (Desktop)

Two-column grid: `grid-template-columns: minmax(0, 1fr) 320px`
- Left: Today's schedule panel
- Right sidebar: Quick Actions card + Medications Overview card

Mobile (`max-width: 800px`): stacks to single column, sidebar moves below schedule.

---

## Logo

- Size: `3rem × 3rem` (48px) in navigation
- Border radius: `0.5rem`
- The `.nav-brand` wrapper has no border or background — it must not look like a button

---

## CSS Variables Reference

See `assets/css/rxtracker-brand-tokens.css` for the complete token file.

---

## Recommended UI Patterns

- Use `--rx-gradient-hero` for the hero banner (richer, darker than the standard gradient)
- Use `--rx-gradient` for primary CTAs, the "Take" button, and active nav items
- Use white cards (`--rx-card`) on `--rx-bg` background
- Use `--rx-success` for taken dose states only
- Use `--rx-warning` for upcoming doses, late notices, and refill-soon alerts
- Use `--rx-danger` only for missed doses and critical alerts
- All interactive elements need `:hover` states with `transition: 160ms ease`
- Keep icon and card corners rounded for a mobile-first, friendly medical aesthetic
