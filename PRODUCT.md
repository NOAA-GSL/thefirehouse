# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Primary visitors are fire weather **forecasters**, **researchers**, and **decision-makers** (including land managers) who need to find what social-science work has already established about fire weather needs and recommendations — then contribute when they have a finding of their own.

Content operators are NOAA/GSL staff (Stephanie and Emily): they maintain copy, topic summaries, and project records so the hub stays current without a developer for day-to-day edits.

## Product Purpose

The FireHouse is NOAA/GSL’s public hub for fire weather **social science** needs and findings. It centralizes what was learned across completed DESI and testbed research so people can find what’s already known — and add to it — instead of rediscovering the same gaps in isolation.

Success means: at AMS and afterward, a visitor can reach the right topic need quickly; submissions can arrive via the outbound form; topic summaries stay reviewable and dated; and the site can be demoed without inventing citations or shipping placeholder project records as if they were final.

## Positioning

A shared home for **synthesized fire weather social-science needs and recommendations**, organized into four fixed topic areas (observations, forecasts, warnings, governance), with live counts derived from published content — not a general NOAA news site, not a paper archive, and not a raw project dump without synthesis.

## Operating Context

- Demo and use at AMS on phones as well as desktop.
- Day-to-day content ownership by Stephanie and Emily (CMS-ready content layer; local JSON until a CMS is chosen).
- “Submit a Finding” is an outbound Google Form (Qualtrics is closed); folding a processed submission into topic summaries may stay manual at first.
- Federal identity surface: USWDS-style gov banner, NOAA/GSL lockup, DOC/NOAA footer links.

## Capabilities and Constraints

**Shipped capabilities:** landing (FireHouse logo, hero with live stats, top-5 needs per topic area with project counts, synthesis provenance, GACC coverage map, submit band); project explorer with search, fire phase/GACC/year/project type filters, sort, and detail modal; per-topic pages leading with their top needs and per-need project attribution; About page (purpose, audiences, process, team bios); light/dark theme; content adapters (`local` default, Sanity and Strapi stubs).

**Structural (code, not CMS):** the four `TopicKey` values (`observe`, `forecast`, `warning`, `governance`) are fixed; display names are editable.

**Hard constraints:** WCAG 2.1 AA / Section 508; NOAA–GSL branding and federal chrome; no invented citations, testimonials, or project takeaways; team biographies published verbatim as written by their subjects; hero photo still missing (gradient stand-in until then); About portraits are real photographs of staff and need their subjects' consent before public launch.

**Open decisions:** see the open questions in `WORDPRESS-PLAN.md`. The site moves into GSL's WordPress as a plugin, and both the site and the plugin have tests.

## Brand Commitments

- Product name: **The FireHouse**; the logo wordmark reads **FireHouse — Fire Weather Research Hub** (brand files in `src/assets/logos/`)
- Parent: NOAA Global Systems Laboratory (GSL)
- Voice in approved landing copy: plain, institutional, useful — not marketing flourish
- Binding assets: NOAA and GSL logos under `src/assets/logos/`; approved landing copy in `src/content/data/landing.json`
- The disclaimer that ends every page (`disclaimer` in `src/content/data/settings.json`): the findings are the researchers' own, not NOAA's. It goes through clearance before gsl.noaa.gov
- Incumbent visual system exists (ported Firehouse design system); product record does not redefine it

## Evidence on Hand

- Approved landing copy in `landing.json`
- **Draft** top-needs synthesis in `topicSummaries.json`, drafted from four of the eight published projects and traced entry-by-entry in `notebooklm/draft-synthesis-2026-09-17.md` — unreviewed, and labelled as a draft in the UI until `reviewedBy` is set
- Topic definitions and area copy in `topics.json` — needs Stephanie/Emily review
- Eight real projects in `projects.json`, imported from the team's Submission Data sheet
- Placeholders that must not be fabricated later: real citations, testimonials, benchmarks, or a live CMS instance that does not exist yet

## Product Principles

1. **Synthesis over inventory** — surface needs and recommendations by topic; don’t make visitors reconstruct them from raw projects alone.
2. **Truthful counts and provenance** — live stats track published content; never invent papers, quotes, or findings to fill the UI.
3. **Editable without a deploy ritual** — Stephanie and Emily can keep the hub current through the content layer once a CMS is chosen.
4. **Federal-grade access** — WCAG 2.1 AA / Section 508 and usable mobile at AMS are requirements, not polish.
5. **Honest launch readiness** — placeholders and open hosting/CMS choices stay visible as gaps until replaced.

## Accessibility & Inclusion

WCAG 2.1 AA and Section 508 are hard requirements (brief §9.2). The build includes skip link, focus management on route change, dialog semantics on the project modal, `aria-current` nav, form field wiring, `prefers-reduced-motion`, and isolated contrast overrides for known design-system failures (`src/styles/a11y-overrides.css`). A real screen-reader audit and remaining federal chrome checks (USWDS conventions, privacy notice, .gov branding) are still open before public launch.
