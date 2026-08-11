---
name: The Firehouse
description: NOAA/GSL fire weather social science hub — maritime navy with ember signal
colors:
  brand-primary: "#263f78"
  brand-primary-hover: "#1b2e56"
  accent: "#c21500"
  accent-hover: "#a11200"
  accent-tint: "#fff4ee"
  bg-page: "#f7f8fa"
  bg-surface: "#ffffff"
  bg-sunken: "#f3f6fa"
  text-primary: "#101b36"
  text-secondary: "#545c6c"
  text-tertiary: "#616a7a"
  text-on-dark: "#ffffff"
  text-on-accent: "#ffffff"
  border: "#dce0e6"
  border-strong: "#c0c6d0"
  focus-ring: "#0284c7"
  link: "#0a4595"
  navy-950: "#0a1020"
  ember-200: "#ffc7a3"
  topic-observe: "#0369a1"
  topic-observe-tint: "#f0f9ff"
  topic-forecast: "#0b5d56"
  topic-forecast-tint: "#effcfa"
  topic-warning: "#a11200"
  topic-warning-tint: "#fff4ee"
  topic-governance: "#5b3e82"
  topic-governance-tint: "#f5f0fa"
typography:
  display:
    fontFamily: "Archivo, ui-sans-serif, system-ui, sans-serif"
    fontSize: "56px"
    fontWeight: 800
    lineHeight: 1.05
    letterSpacing: "normal"
  headline:
    fontFamily: "Archivo, ui-sans-serif, system-ui, sans-serif"
    fontSize: "32px"
    fontWeight: 700
    lineHeight: 1.2
  title:
    fontFamily: "Archivo, ui-sans-serif, system-ui, sans-serif"
    fontSize: "20px"
    fontWeight: 600
    lineHeight: 1.32
  body:
    fontFamily: "Public Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "16px"
    fontWeight: 400
    lineHeight: 1.6
  label:
    fontFamily: "Public Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "13px"
    fontWeight: 600
    lineHeight: 1.3
    letterSpacing: "0.06em"
  stat:
    fontFamily: "Archivo Expanded, Archivo, ui-sans-serif, system-ui, sans-serif"
    fontSize: "44px"
    fontWeight: 800
    lineHeight: 1
rounded:
  sm: "6px"
  md: "8px"
  lg: "10px"
  xl: "16px"
  full: "999px"
spacing:
  2xs: "4px"
  xs: "8px"
  sm: "12px"
  md: "16px"
  lg: "24px"
  xl: "32px"
  2xl: "48px"
  3xl: "64px"
  4xl: "96px"
components:
  button-primary:
    backgroundColor: "{colors.brand-primary}"
    textColor: "{colors.text-on-dark}"
    rounded: "{rounded.md}"
    padding: "10px 18px"
  button-primary-hover:
    backgroundColor: "{colors.brand-primary-hover}"
    textColor: "{colors.text-on-dark}"
  button-accent:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.text-on-accent}"
    rounded: "{rounded.md}"
    padding: "14px 24px"
  button-accent-hover:
    backgroundColor: "{colors.accent-hover}"
    textColor: "{colors.text-on-accent}"
  button-secondary:
    backgroundColor: "{colors.bg-surface}"
    textColor: "{colors.brand-primary}"
    rounded: "{rounded.md}"
    padding: "10px 18px"
  button-ghost:
    backgroundColor: "transparent"
    textColor: "{colors.brand-primary}"
    rounded: "{rounded.md}"
    padding: "10px 18px"
  topic-tag:
    backgroundColor: "{colors.topic-observe-tint}"
    textColor: "{colors.topic-observe}"
    rounded: "{rounded.full}"
    padding: "5px 12px"
    typography: "{typography.label}"
  field:
    backgroundColor: "{colors.bg-surface}"
    textColor: "{colors.text-primary}"
    rounded: "{rounded.md}"
    padding: "10px 14px"
  project-tile:
    backgroundColor: "{colors.bg-surface}"
    textColor: "{colors.text-primary}"
    rounded: "{rounded.lg}"
    padding: "24px"
  card-topic:
    backgroundColor: "{colors.bg-surface}"
    textColor: "{colors.text-primary}"
    rounded: "{rounded.lg}"
    padding: "24px"
---

# Design System: The Firehouse

## Overview

**Creative North Star: "The Watchtower"**

The Firehouse UI is a maritime-institutional watchtower: cool paper grounds, deep navy brand structure, and ember reserved for the actions that matter. It reads as NOAA/GSL federal chrome first — gov banner, agency lockups, quiet footer — then as a research hub that is approachable through soft tint washes (topic chips, sunken CTA bands) rather than marketing spectacle.

Density is deliberate and readable: generous section padding (`--space-3xl` on topic blocks), a 1280px content column, and type that separates display (Archivo) from working prose (Public Sans). Depth is mostly tonal and bordered; shadows appear when something is interactive, not as ambient decoration.

This document records the **ported Firehouse design system** already in `src/styles/tokens/` and `src/design-system/`. New screens should compose from those tokens and components — not invent a parallel palette or type stack. Visual anti-drift: do not introduce purple SaaS gradients, glassmorphism, or a second primary accent competing with ember.

**Key Characteristics:**
- Cool paper page (`bg-page`) with white surfaces and navy-tinted sunken bands
- Dual signal: `brand-primary` (navy) for structure; `accent` (ember) for primary CTAs
- Archivo display + Public Sans body; Archivo Expanded for live stats
- Border-first cards; soft shadow + 2px lift only on interactive tiles
- Topic identity via tinted pill chips (sky / teal / ember / plum)
- 3px sky `focus-ring` on `:focus-visible` — never removed
- Light and dark themes via `[data-theme]`; AA overrides live in `a11y-overrides.css`

## Colors

Palette character: cool maritime neutrals with a single hot ember signal; topic hues are secondary categorical colors, not brand competition.

### Primary
- **brand-primary** (`#263f78` / `--navy-700`): Structural brand fill — primary buttons, active nav emphasis, field focus border. Hover deepens to **brand-primary-hover** (`#1b2e56`).
- **accent** (`#c21500` / `--ember-500`): Fire signal for “Submit a Finding” and other high-intent CTAs. Hover **accent-hover** (`#a11200`). Soften with **accent-tint** (`#fff4ee`) for washes, never as a second solid primary on the same screen.

### Secondary
- **Topic categorical set** — observe (sky), forecast (teal), warning (ember family), governance (plum). Each ships text / tint / border tokens. Use only to mark topic membership (chips, accents on topic pages), not as general UI chrome.

### Neutral
- **bg-page** (`#f7f8fa`): Default page ground (`--slate-50`).
- **bg-surface** (`#ffffff`): Cards, header, stats strip.
- **bg-sunken** (`#f3f6fa` / `--navy-50`): Gov banner, footer, submit CTA band — cooler than page gray.
- **text-primary** (`#101b36`): Body and headings.
- **text-secondary** (`#545c6c`): Supporting copy.
- **text-tertiary** (`#616a7a`): Captions — **AA override** (design-system `--slate-500` fails; do not revert).
- **border** / **border-strong** (`#dce0e6` / `#c0c6d0`): Resting and stronger edges.
- **link** (`#0a4595`): In-content and footer links.
- **navy-950** (`#0a1020`): Hero scrim / darkest navy; also dark-mode accent label text via a11y override.

### Named Rules
**The Ember Budget Rule.** Ember (`accent`) is for decisive actions and topic-warning identity. Do not sprinkle solid ember across chrome, icons, or decorative rules — rarity is the point.

**The Token Names Rule.** Prefer CSS token names (`brand-primary`, `accent`, `bg-sunken`) in specs and PRs over poetic aliases so agents and editors stay synced with `tokens/colors.css`.

**The AA Override Rule.** Never “fix” tertiary text or dark-mode accent labels back to upstream token values without re-checking contrast; see `src/styles/a11y-overrides.css`.

## Typography

**Display Font:** Archivo (ui-sans-serif / system fallbacks)  
**Body Font:** Public Sans (ui-sans-serif / system fallbacks)  
**Stat / Wordmark Expanded:** Archivo Expanded (falls back to Archivo)  
**Mono:** system ui-monospace stack

**Character:** Archivo carries institutional authority in heavy weights; Public Sans keeps federal body copy calm and readable. Expanded weight is reserved for live statistics and the site wordmark — not for paragraphs.

### Hierarchy
- **Display** (800, 56px / 1.05, clamp down on small screens): Hero only.
- **Headline** (700, 32px / 1.2): Section titles (`text-h1`).
- **Title** (600, 20–24px): Card and band headings (`text-h2` / `text-h3`).
- **Body** (400, 16px / 1.6; 18px for hero lead): Default prose; keep lines readable (~40–70ch in practice via max-width wrappers).
- **Label** (600, 13px, 0.06em, uppercase): Eyebrows, field labels, topic chips.
- **Stat** (800, 44px / 1, Expanded): Live counters; 36px below 768px.

### Named Rules
**The Expanded Reserve Rule.** Archivo Expanded appears on stats and the Firehouse wordmark — not on body copy or buttons.

**The Uppercase Label Rule.** Uppercase + `letter-spacing-label` is for metadata (eyebrows, chips, field labels), never for long sentences.

## Layout

Spatial model: single centered column, `--container-max: 1280px`, horizontal padding `--space-lg` (24px) via `.fh-container`. Section rhythm uses the spacing scale — stats `xl` (32px) block padding, topics `3xl` (64px), CTA/footer `2xl` (48px).

Breakpoints in tokens: tablet `768px`, desktop `1200px`, wide `1440px`. Header collapses nav into a drawer below `1024px`; footer grid goes 4 → 2 → 1. Hero CTAs stack full-width below `480px` (`blockOnMobile`).

**The Container Rule.** Page content lives in `.fh-container`; do not invent ad-hoc max-widths that fight 1280px unless a component already defines a tighter measure (hero body 640px, topic intro 640px).

## Elevation & Depth

Depth is **border-first with shadow-on-interaction**. Resting surfaces are flat white or sunken navy-tinted fills with a 1px border. Project tiles use `--shadow-sm` at rest and `--shadow-md` plus `translateY(-2px)` on hover. Topic summary cards on the landing page stay bordered without ambient shadow. Shadows tint with `--shadow-color: 38 63 120` (navy), not pure black.

### Shadow Vocabulary
- **sm** (`0 1px 2px rgba(38, 63, 120, 0.08)`): Quiet resting lift on interactive tiles.
- **md** (`0 4px 10px …, 0 1px 2px …`): Hover / raised interactive state.
- **lg** (`0 12px 28px …, 0 2px 6px …`): Reserved for stronger elevation (e.g. modal) when needed.

### Named Rules
**The Flat-By-Default Rule.** Surfaces are flat at rest. Shadows appear as a response to interactivity or modal layering — not as permanent decoration under every card.

## Shapes

Form language is gently rounded federal UI: controls use **md** (8px), cards/tiles **lg** (10px), chips **full** pills. Borders are 1px; focus uses a 3px sky outline (or matching box-shadow on fields), offset 2px.

**The Soft Corner Rule.** Prefer `--radius-md` / `--radius-lg` over sharp rectangles or oversized “app pill” radii on containers. Full radius is for chips and tags only.

## Components

Components should feel **approachable within federal constraints**: soft topic tints and sunken bands warm the chrome; primary actions stay solid and clear.

### Buttons
- **Shape:** Medium rounding (`8px`); sizes sm / md / lg with 120ms color transitions.
- **Primary:** `brand-primary` fill — structural actions.
- **Accent:** `accent` fill — submit / high-intent CTAs (hero, header, bands).
- **Secondary:** White fill, strong border, navy text; on dark hero use `--on-dark` (translucent white + white border).
- **Ghost / Link:** Minimal chrome; link variant is underlined text.
- **Hover / Focus:** Darken fill or sunken wash; global `:focus-visible` sky ring. Dark mode: accent label uses navy text via a11y override.

### Chips (TopicTag)
- **Style:** Pill (`radius-full`), uppercase label, topic tint background + matching border/text tokens.
- **State:** Categorical only (observe / forecast / warning / governance) — not a generic filter chip system.

### Cards / Containers
- **Topic card:** Surface + 1px border + `radius-lg` + `space-lg` padding; no resting shadow.
- **Project tile:** Same radius/padding; `shadow-sm` → `shadow-md` on hover with 2px lift.
- **CTA band / footer:** `bg-sunken` full-bleed bands with border separators.

### Inputs / Fields
- **Style:** Surface fill, strong border, `radius-md`, 10×14 padding, body type.
- **Focus:** Brand-primary border + 3px sky focus ring shadow.
- **Error / Disabled:** Error border/text tokens; disabled uses sunken background.

### Navigation
- **Header:** White bar, NOAA/GSL lockup + Archivo wordmark, Public Sans links; active link gets brand-primary weight + ember underline. Theme toggle and accent CTA on the right; drawer under 1024px.
- **Gov banner:** Sunken strip, caption type — federal identity, not product marketing.

### StatCounter (signature)
- Expanded 44px value; optional `accent` color for the submissions “Open” signal. Caption uses tertiary text.

## Do's and Don'ts

### Do:
- **Do** compose new UI from `src/design-system` and CSS variables in `src/styles/tokens/` — never hard-code one-off hex when a token exists.
- **Do** keep ember rare and intentional (primary CTAs + warning topic identity).
- **Do** keep the 3px sky `:focus-visible` ring; honor `prefers-reduced-motion`.
- **Do** use topic tint chips for categorical identity and sunken bands for quiet sectioning.
- **Do** preserve AA overrides for tertiary text (`#616a7a`) and dark-mode accent labels.

### Don't:
- **Don't** invent a second brand accent (gradients, purple, neon) alongside ember.
- **Don't** put Archivo Expanded on paragraphs or buttons.
- **Don't** ambient-shadow every card; reserve lift for interactive tiles/modals.
- **Don't** remove NOAA/GSL lockups, gov banner, or federal footer patterns from public chrome.
- **Don't** ship placeholder project citations or decorative imagery that contradicts PRODUCT.md evidence rules.
