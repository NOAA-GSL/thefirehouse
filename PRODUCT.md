# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Primary visitors are fire weather **forecasters**, **researchers**, and **decision-makers** (including land managers) who need to find what social-science work has already established about fire weather needs and recommendations — then contribute when they have a finding of their own.

Content operators are NOAA/GSL staff (Stephanie and Emily): they maintain copy, topic summaries, and project records so the hub stays current without a developer for day-to-day edits.

## Product Purpose

The Firehouse is NOAA/GSL’s public hub for fire weather **social science** needs and findings. It centralizes what was learned across completed DESI and testbed research so people can find what’s already known — and add to it — instead of rediscovering the same gaps in isolation.

Success means: at AMS and afterward, a visitor can reach the right topic need quickly; submissions can arrive via the outbound form; topic summaries stay reviewable and dated; and the site can be demoed without inventing citations or shipping placeholder project records as if they were final.

## Positioning

A shared home for **synthesized fire weather social-science needs and recommendations**, organized into four fixed topic areas (observations, forecasts, warnings, governance), with live counts derived from published content — not a general NOAA news site, not a paper archive, and not a raw project dump without synthesis.

## Operating Context

- Demo and use at AMS on phones as well as desktop.
- Day-to-day content ownership by Stephanie and Emily (CMS-ready content layer; local JSON until a CMS is chosen).
- “Submit a Finding” is an outbound Google Form for now; folding a processed submission into topic summaries may stay manual at first.
- Federal identity surface: USWDS-style gov banner, NOAA/GSL lockup, DOC/NOAA footer links.

## Capabilities and Constraints

**Shipped capabilities:** landing (hero, live stats, topic cards, submit band); project explorer with topic filter and detail modal; per-topic pages; light/dark theme; content adapters (`local` default, Sanity and Strapi stubs).

**Structural (code, not CMS):** the four `TopicKey` values (`observe`, `forecast`, `warning`, `governance`) are fixed; display names are editable.

**Hard constraints:** WCAG 2.1 AA / Section 508; NOAA–GSL branding and federal chrome; no invented citations, testimonials, or project takeaways; replace placeholder `projects.json` and the Google Form URL before public launch; hero photo asset still missing (gradient stand-in until then).

**Open decisions:** hosting placement (static build; optional `VITE_BASE_PATH` for subpath); which CMS to stand up; self-hosted vs Google Fonts CDN for Archivo / Public Sans; per-project URLs, explorer search/sort, About page, tests — not built yet.

## Brand Commitments

- Product name: **The Firehouse**
- Parent: NOAA Global Systems Laboratory (GSL) / Earth Prediction Innovation Center
- Voice in approved landing copy: plain, institutional, useful — not marketing flourish
- Binding assets: NOAA and GSL logos under `src/assets/logos/`; approved landing copy in `src/content/data/landing.json`
- Incumbent visual system exists (ported Firehouse design system); product record does not redefine it

## Evidence on Hand

- Approved landing copy and topic “top needs” text aligned to the design mock (`landing.json`, `topicSummaries.json`) — confirm needs against FireHouse 1.0 before launch
- Topic definitions and area copy in `topics.json` — needs Stephanie/Emily review
- Nine **placeholder** project records in `projects.json` — must not ship publicly as-is; `papers` empty on purpose
- Placeholders that must not be fabricated later: real citations, testimonials, benchmarks, or a live CMS instance that does not exist yet

## Product Principles

1. **Synthesis over inventory** — surface needs and recommendations by topic; don’t make visitors reconstruct them from raw projects alone.
2. **Truthful counts and provenance** — live stats track published content; never invent papers, quotes, or findings to fill the UI.
3. **Editable without a deploy ritual** — Stephanie and Emily can keep the hub current through the content layer once a CMS is chosen.
4. **Federal-grade access** — WCAG 2.1 AA / Section 508 and usable mobile at AMS are requirements, not polish.
5. **Honest launch readiness** — placeholders and open hosting/CMS choices stay visible as gaps until replaced.

## Accessibility & Inclusion

WCAG 2.1 AA and Section 508 are hard requirements (brief §9.2). The build includes skip link, focus management on route change, dialog semantics on the project modal, `aria-current` nav, form field wiring, `prefers-reduced-motion`, and isolated contrast overrides for known design-system failures (`src/styles/a11y-overrides.css`). A real screen-reader audit and remaining federal chrome checks (USWDS conventions, privacy notice, .gov branding) are still open before public launch.
