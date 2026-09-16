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
| `*`                | **Custom 404** — echoes the requested address, and offers the explorer plus all four topic areas rather than dead-ending. Also rendered by `/topics/:topicKey` for a key that isn't one of the four, with wording tailored to that case. |

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

public/             Served verbatim at the site root: icons, the link-preview card,
                    robots.txt, and the two host-level SPA fallbacks (see Hosting)
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
  moving focus. `RouteFocusManager` moves it to `<main>` — but only on an actual
  navigation, never on the initial page load. A cold load already starts focus at the
  top of the document, and pulling it into `<main>` there would leave the skip link,
  the gov banner toggle and the entire header *behind* the focus position: the first
  Tab would land inside the content and the skip link could never be reached at all.
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
- [ ] **Set `VITE_SITE_URL`** once hosting is settled, so `canonical`, `og:url` and
      `og:image` are emitted. Until then shared links preview without the image card.
- [ ] **Confirm the SPA fallback with Matt** — which of `.htaccess` / `_redirects` /
      `404.html` actually applies on GSL's host, and drop the two that don't.
- [ ] **Add a `Sitemap:` line to `public/robots.txt`** and generate `sitemap.xml`;
      both need the final URL. If the build goes on a staging URL before launch, flip
      `robots.txt` to `Disallow: /` so the preview isn't indexed.
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
- **An About page.** Explorer search and filters are in (`src/content/search.ts`);
  still open from `PLAN.md` §1.2–1.3 are highlighting matched words in results and
  multi-select facets (each facet is currently single-select).
- **Build-time link previews for project pages.** Each project has its own URL and
  sets its own `<title>` and description at runtime, so deep links work and index
  correctly — but a *shared* link still previews with the site-level card. Fixing it
  means emitting a per-project HTML shell at build time (`PLAN.md` §2.2).
- **Tests.** None. Worth adding around `normalize.ts` and `derive.ts` first — they're
  pure functions and they're what stands between a bad CMS edit and a broken page.

## The coverage map

The landing-page hero carries a map of where research comes from, built to
`FirehouseFormAdditions.pdf`.

- **Regions are the survey's recode values** — `ONCC`, not `Northern California
  (ONCC)`. Display text gets reworded over time; codes must not, or the map breaks
  quietly. Defined in `src/design-system/taxonomy.ts`.
- **Fourteen values, not ten.** The ten GACCs have boundaries. `PACIFIC`,
  `NATIONAL`, `INTL` and `UNKNOWN` do not, and are listed beside the map rather than
  dropped — a total that excludes them under-reports what was submitted. `PACIFIC`
  exists specifically because the NIFC boundary file folds the Pacific islands into
  Northern California, so Hawaii research would otherwise be filed under ONCC.
- **Boundaries are committed, not fetched.** `src/content/data/gaccBoundaries.json`
  is NIFC's `National_GACC_Boundaries`, simplified to ~33 kB. The map draws on a
  hostile network; only the basemap tiles are live.
- **Selection lives in `?region=`**, so a filtered view of the map is a link someone
  can paste into an email.

**The one runtime third-party dependency on the whole site is the basemap tiles**
(`server.arcgisonline.com`, Esri — attribution is rendered under the map and is
required by their terms). If a federal review objects to the external call, the map
degrades to boundaries on a blank canvas rather than failing, and the `BASEMAPS`
constant in `src/design-system/RegionMap.tsx` is the only thing to change.

Accessibility: the region list beside the map is not a fallback. It is the operable
surface — real buttons, real counts, driving the same state the polygons do — and
the Leaflet canvas is `aria-hidden` so assistive technology is sent there rather
than into unlabelled SVG paths. Colour is never the only channel; every region
carries its count as a number.

## Survey import

`scripts/import-survey.mjs` turns a Qualtrics CSV export into
`src/content/data/projects.json`:

```bash
node scripts/import-survey.mjs ~/Downloads/firehouse-survey.csv --dry
```

`--dry` parses and reports without writing — use it first. The script is the seam
where survey data becomes site content, and it is plain dependency-free Node so it
can be read and re-hosted by whoever takes it over: a live integration replaces the
CSV read with an API call and writes the same file. Nothing downstream changes.

Two rules it enforces that the UI cannot:

- **Q10 (how results were communicated) never enters the JSON.** It is internal-use
  only, and a field that is merely not rendered still ships in the bundle.
- **Rows without IRB approval are reported, not silently dropped** — a silent drop
  looks exactly like a parsing bug. `normalize.ts` filters them again at runtime as
  a backstop.

Imported records stage as `published: false`, so running the script can never put
unreviewed research on a live site as a side effect.

`QUESTION_MAP` at the top of the script is keyed to *guessed* Qualtrics column
names, pending the mapped question list from Emily and Steph. It is the only thing
that should need editing when that arrives.

## Accessibility

WCAG 2.1 AA / Section 508 is a hard requirement (creative brief §9.2), and the
translucent surface treatment is exactly where that usually goes wrong. The rule
the design holds to is **glass provides depth, never contrast**: the page backdrop
is deliberately bounded, and every panel alpha is chosen so text clears AA against
the worst-case composite rather than against the panel colour. Blur is decoration
on top of a surface that already passes.

Three fallbacks drop to fully opaque surfaces — `prefers-reduced-transparency`,
`prefers-contrast: more`, and `@supports not (backdrop-filter)`.

Two audits, complementary:

```bash
node scripts/check-contrast.mjs      # the token system, incl. UI contrast (1.4.11)
```

```js
// scripts/audit-contrast-live.js — paste into the browser console
fhAudit();                 // current theme
await fhAuditBothThemes(); // light and dark
```

The static script proves the palette is sound and runs without a browser. The live
walk reads computed styles off real DOM nodes and catches what a token audit cannot
— cascade bugs, hardcoded component colours, unaccounted translucent ancestors. Run
both; they have caught different things.

Current state: 0 AA text-contrast failures across all routes in both themes, and in
both fallback states.

## Hosting

Placement is still open (brief §8). The build is fully static and needs no server.
If it lands on a sub-path, set `VITE_BASE_PATH=/firehouse/` — Vite's `base` and the
router's `basename` are already wired to it, and the icon links in `index.html` use
`%BASE_URL%` so they resolve there too.

**Client routing needs the host to serve the app shell for unknown paths.** `/projects`
and `/topics/observe` have no file behind them, so without this a direct hit, a refresh,
or a link shared off a conference slide returns 404. Three options ship, so whichever
host GSL lands on is already covered:

| File | Host |
| ---- | ---- |
| `public/.htaccess` | Apache. Needs `AllowOverride FileInfo`; also sets cache headers. Uncomment `RewriteBase` for a sub-path. |
| `public/_redirects` | Netlify / Cloudflare Pages. Rewrites with a **200**, so the status code stays correct. |
| `dist/404.html` | Bucket-style hosts with no rewrite lever at all — S3, GitHub Pages, Azure Static Web Apps. Emitted at build time by the `firehouse-spa-fallback` plugin in `vite.config.ts` as a byte copy of `index.html`. Returns a 404 status, so prefer one of the above where possible. |

nginx has no drop-in file; ask for `try_files $uri $uri/ /index.html;` in the location
block.

### Link previews

`index.html` carries the Open Graph and Twitter tags that don't depend on the domain.
The three that must be absolute URLs — `canonical`, `og:url`, `og:image` — are injected
at build time by the `firehouse-site-meta` plugin from **`VITE_SITE_URL`**, and are
omitted entirely when it isn't set. That's deliberate: a shared link still previews with
its title and description, whereas a guessed domain would point at a URL that may never
exist. Set it once Matt confirms placement and the card at `public/og-image.png` starts
appearing in Slack, email and social unfurls.

The card is a flat 1200×630 export (Archivo over the navy/ember palette) and the icons
are generated from the same flame geometry, with `public/favicon.svg` as the editable
vector source. All of it is static — re-export by hand if the wordmark or tagline
changes.
