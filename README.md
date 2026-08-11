# The Firehouse

Public-facing hub for NOAA/GSL fire weather social science needs and findings.
React + TypeScript + Vite, built against the Firehouse design system and the
July 2026 creative brief.

```bash
npm install
npm run dev      # http://localhost:5173
npm run build    # typecheck + production build to dist/
npm run preview  # serve the production build
```

---

## What's here

| Route              | Status                                                                        |
| ------------------ | ----------------------------------------------------------------------------- |
| `/`                | **Landing page** — the implemented design. Hero, live stats, four topic-area summary cards, submit CTA band. |
| `/projects`        | **Project explorer** — first pass at brief §5.2. Topic-filtered grid, detail modal. |
| `/topics/:topicKey` | **Topic area page** — introduction, current top needs, and every project in that area. One per `TopicKey`. |
| `*`                | 404.                                                                          |

The landing page is a faithful implementation of `Firehouse Landing Page.dc.html`
from the Claude Design project. Every deviation is listed under
[Deliberate deviations](#deliberate-deviations) below.

## Layout

```
src/
  design-system/    The Firehouse design system, ported to React. Pages import
                    only from here — never raw token values.
  styles/tokens/    colors / typography / spacing, ported byte-faithfully.
  styles/           global.css, a11y-overrides.css
  content/          CMS-agnostic content layer (see below)
  pages/            LandingPage, ProjectsPage, NotFoundPage
  components/       Layout, ThemeProvider
  assets/           NOAA + GSL logos; hero imagery (see caveat)
```

### The design system

Thirteen components from
`_ds/the-firehouse-design-system-a05f43c6-bfb5-47a4-b052-1f16fdeac42a/_ds_bundle.js`
were ported to typed React: `Button`, `Icon`, `TopicTag`, `StatCounter`,
`ProjectTile`, `ProjectDetailModal`, `TextField`, `TextArea`, `GovBanner`,
`SiteHeader`, `SiteFooter`, plus the `TOPICS` map.

Three deliberate changes to how they're built, none to how they look:

- **Inline styles → CSS files.** The source used React state for hover and inline
  style objects throughout. Those became real `:hover` / `:focus-visible` rules and
  media queries — which is what makes the responsive and keyboard behaviour below
  possible at all. Token values are unchanged.
- **Lucide from CDN → `lucide-react`.** The source `Icon` injected `<i data-lucide>`
  and called a global from `unpkg.com`. Icons are now real React elements: no
  third-party script (which some agencies restrict outright), no layout shift, and
  an unknown icon name is a compile error rather than an empty box.
- **`Button` is polymorphic.** Renders `<button>`, a router `<Link>`, or an `<a>`.
  "Submit a Finding" goes off-site to a Google Form and has to be a real link.

### The content layer

Everything renderable comes from `src/content`, behind one interface:

```ts
interface ContentAdapter {
  load(): Promise<SiteContent>;
}
```

Three adapters ship: `local` (bundled JSON, the default), `sanity` (one GROQ query
over the HTTP API), and `strapi` (v5 REST). Selected by `VITE_CMS` — see
`.env.example`. Neither CMS adapter pulls in an SDK, so choosing between Sanity and
Strapi later doesn't change the dependency tree.

`src/content/types.ts` is the contract every adapter meets. `normalize.ts` runs on
all of them: it rejects unknown topic keys and duplicate slugs loudly, and resolves
the "empty `href` inherits `settings.submitFormUrl`" convention so the Google Form
address lives in exactly one field even though five controls point at it.

**Live stats are computed, not stored.** `landing.json` never contains the number 9.
Each stat declares a `source` (`publishedProjectCount` / `topicCount` / `static`) and
`derive.ts` counts live content. Editors control the wording; they can't put the
count out of step with what's published. Publishing a tenth project moves the
counter on its own — brief §5.1.

Structural things stay in code on purpose: the four topic keys (`observe`,
`forecast`, `warning`, `governance`) are a `TopicKey` union, because adding a fifth
topic is a schema and pipeline change, not a CMS edit. Their display names *are*
editable.

## Deliberate deviations from the design

Everything below is an addition or correction, with the reason. Nothing was dropped.

**Required by the brief (§9.2), absent from the design:**

- **Mobile navigation.** The source header is one flex row that overflows below
  ~900px. Added a disclosure drawer under 1024px; the identity lockup and theme
  toggle stay visible. The site has to work on a phone at AMS.
- **Responsive footer.** The 4-column grid was fixed; it now collapses to 2 and 1.
- **Real dialog semantics on `ProjectDetailModal`** — `aria-modal`, focus moved in
  and restored, Escape to close, focus loop. The source was a presentational panel.
- **Focus management on route change.** Client-side routing swaps the DOM without
  moving focus. `RouteFocusManager` moves it to `<main>`.
- **Skip link**, `aria-current` on the active nav item, `<label htmlFor>` +
  `aria-describedby` + `aria-invalid` wiring on the form fields, `prefers-reduced-motion`.

**Two AA contrast failures corrected** — see `src/styles/a11y-overrides.css` for
measurements. Both are design system bugs and should be fixed upstream; the
overrides are isolated so a re-sync doesn't lose them.

1. `--color-text-tertiary` (`--slate-500`) was **4.07:1** on the page background,
   carrying 12px caption text. Darkened to `#616A7A` (5.13:1).
2. In dark mode, white on `--ember-400` was **2.84:1** — that's the label on the
   primary "Submit a Finding" CTA. The label flips to navy-950 (6.71:1); the ember
   fill is untouched.

**Judgment calls, easy to reverse:**

- **"Last reviewed <date>" on each topic card.** Not in the design. The card copy
  promises the area "updates automatically", and a reader has no way to tell how
  fresh it is. It only renders when a summary has `updatedAt` — delete that field
  in `topicSummaries.json` and the line disappears.
- **"About" removed from the nav.** It was in the design pointing at nothing.
  Restore it in `settings.json` once there's an About page.
- **Project explorer built.** The landing page's secondary CTA points at it and the
  design system ships `ProjectTile` / `ProjectDetailModal` for exactly this screen,
  so shipping the landing page alone would have left a dead button.
- **Topic area pages built** (`/topics/:topicKey`), and the landing cards now link
  to them. The design left the four cards inert; they read as the entry point to an
  area but went nowhere. Each page carries the introduction the card has no room
  for, the full needs list rather than the top two, and every project in that area.
  The route key is the `TopicKey` itself, not a CMS slug — the keys are structural,
  so a published URL can't be broken by renaming a topic in the CMS.
- **Two new topic fields**, `intro` (paragraphs) and `covers` (a "what this area
  covers" list), in `topics.json` and `types.ts`. Both optional: a topic without
  them still renders, just shorter. Arrays of plain strings rather than rich text,
  so nothing has to render editor-supplied HTML.

## Before this goes public

- [ ] **Replace `submitFormUrl`** in `src/content/data/settings.json`. It is a
      placeholder (`forms.gle/REPLACE-WITH-REAL-FORM-ID`) and every submit CTA reads
      from it.
- [ ] **Replace `projects.json` wholesale** from the FireHouse 1.0 report. The nine
      records there are placeholders — see `src/content/data/README.md`. Their
      takeaways and recommendations say "Placeholder" on purpose, and `papers` is
      empty everywhere because no citation was invented.
- [ ] **Add the hero image.** `src/assets/imagery/fire-weather-hero.png` is missing —
      it's a binary asset in the design project and couldn't come across the
      design-sync API, which returns text. Drop the file in that directory and it's
      picked up automatically; until then the hero renders a gradient stand-in. See
      `src/assets/imagery/README.md`.
- [ ] **Confirm the topic "top needs" copy** in `topicSummaries.json` against the
      FireHouse 1.0 report — it currently mirrors the design mock-up.
- [ ] **Review the topic-area `intro` and `covers` copy** in `topics.json`. It was
      written from the four topic definitions and the needs already on file, not
      from the FireHouse 1.0 report, and it is the first thing a reader arriving at
      `/topics/…` will read. Stephanie or Emily should own the wording.
- [ ] **Decide on self-hosted fonts.** Archivo and Public Sans load from Google
      Fonts. Some agencies prohibit third-party CDN calls; swapping the `@import` in
      `tokens/typography.css` for local `@font-face` is the only change needed.
- [ ] **Run an audit with a real screen reader**, and check the remaining federal
      requirements the brief flags as unconfirmed (USWDS conventions, privacy
      notice, .gov branding).

## What's not built yet

Named so it's clear these are gaps, not oversights:

- **CMS.** The content layer is ready and two adapters are written, but no Sanity or
  Strapi instance exists and neither adapter has been run against a live backend.
  A matching schema still has to be authored on whichever is chosen.
- **Submission pipeline.** The Google Form is an outbound link. Nothing yet moves a
  processed submission into `topicSummaries.json` — brief §9.1 allows this to be
  manual at first, and the brief's own risk list recommends a human review step.
- **Per-project URLs.** Explorer detail is a modal; projects aren't linkable
  individually. The content model already carries `slug` for when they should be.
- **Explorer search and sort**, an About page.
- **Tests.** None. Worth adding around `normalize.ts` and `derive.ts` first — they're
  pure functions and they're what stands between a bad CMS edit and a broken page.

## Hosting

Placement is still open (brief §8). The build is fully static and needs no server.
If it lands on a sub-path, set `VITE_BASE_PATH=/firehouse/` — Vite's `base` and the
router's `basename` are already wired to it. Client-side routing needs the host to
rewrite unknown paths to `index.html`.
