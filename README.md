# The Firehouse

Public-facing hub for NOAA/GSL fire weather social science needs and findings.
React + TypeScript + Vite, built against the Firehouse design system and the
July 2026 creative brief.

```bash
npm install
npm run dev        # http://localhost:5173
npm run build      # typecheck + production build to dist/
npm run preview    # serve the production build
npm test           # shared test cases for normalize, derive, search and format
npm run schema     # regenerate the project schema from types.ts
npm run reference  # capture the reference the plugin matches (reference/README.md)
```

The test cases live in `wp-content/plugins/firehouse/shared/cases/` as plain JSON, so
the plugin's PHP tests run them too: all but `search.json` so far.

---

## What's here

| Route              | Status                                                                        |
| ------------------ | ----------------------------------------------------------------------------- |
| `/`                | **Landing page** — logo and hero with live stats, then the four topic areas' top-5 needs with mention counts, "where these needs come from", the GACC coverage map, and the submit CTA band. |
| `/about`           | **About page** — what the hub is, the three audiences it serves, how a completed project becomes a published need, and the two people who maintain it. All copy lives in `about.json`; the biographies are rendered verbatim. |
| `/projects`        | **Project explorer** — brief §5.2. Search, fire-phase / GACC / year / project-type filters, sort, detail modal. |
| `/topics/:topicKey` | **Topic area page** — the area's top five needs, each with its mention count and links to the projects it draws on, plus a "what this area covers" callout. One per `TopicKey`. Projects are not filed under topics: needs are synthesized across the whole collection, so the attribution is per *need*. |
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
  pages/            Landing, Projects, Project, Topic, About and NotFound pages
  components/       Layout, ThemeProvider, SynthesisNote
  assets/           NOAA + GSL logos; hero imagery (see caveat)

public/             Copied as-is to the build's base path (the site root, or /firehouse/):
                    icons, the link-preview card, robots.txt, and the two host-level
                    SPA fallbacks (see Hosting)

wp-content/plugins/firehouse/
                    The WordPress plugin that will replace this app (see
                    The WordPress plugin, below). So far: firehouse.php, which
                    loads the content logic in PHP (domain/); the shared
                    vocabularies and test cases; and the PHP tests.
```

### The design system

Eleven components from
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
  "Submit a Finding" goes off-site to the Google Form and has to be a real link.

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
the "empty `href` inherits `settings.submitFormUrl`" convention so the submission survey
address lives in exactly one field even though several controls point at it.

**Live stats are computed, not stored.** `landing.json` never contains the project count.
Each stat declares a `source` (`publishedProjectCount` / `needCount` / `topicCount` /
`static`) and `derive.ts` counts live content. Editors control the wording; they can't put the
count out of step with what's published. Publishing another project moves the
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
- **"About" back in the nav.** It was in the design pointing at nothing, so it was
  cut; the page now exists (`/about`) and the link is restored in `settings.json`,
  alongside one in the footer's "Explore" group.
- **Project explorer built.** The landing page's secondary CTA points at it and the
  design system ships `ProjectTile` / `ProjectDetailModal` for exactly this screen,
  so shipping the landing page alone would have left a dead button.
- **Topic area pages built** (`/topics/:topicKey`), and the landing cards now link
  to them. The design left the four cards inert; they read as the entry point to an
  area but went nowhere. Each page carries the area's top five needs, each linked to
  the projects it draws on, and a "what this area covers" callout the card has no
  room for.
  The route key is the `TopicKey` itself, not a CMS slug — the keys are structural,
  so a published URL can't be broken by renaming a topic in the CMS.
- **Two new topic fields**, `intro` (paragraphs) and `covers` (a "what this area
  covers" list), in `topics.json` and `types.ts`. Both optional: a topic without
  them still renders. `intro` is kept in the data but no longer shown. Arrays of plain strings rather than rich text,
  so nothing has to render editor-supplied HTML.

## Before this goes public

- [x] **Point `submitFormUrl` at the survey** in `src/content/data/settings.json`: now
      the FireHouse Google Form (Qualtrics is closed). Every submit CTA reads from it.
- [x] **Replace the placeholder projects.** `projects.json` holds the eight projects
      imported from the survey, all published.
- [x] **Turn the gov banner and footer back on.**
- [x] **Remove the citation generator.** The site isn't a permanent archive and pages
      may be withdrawn, so project pages don't offer a citation.
- [x] **Stop the topic pages scrolling sideways on phones.** `npm run reference` now
      fails if any page is wider than the window, at 1280px, 390px or WCAG's 320px.
- [ ] **Add the hero image.** `src/assets/imagery/fire-weather-hero.png` is missing —
      it's a binary asset in the design project and couldn't come across the
      design-sync API, which returns text. Drop the file in that directory and it's
      picked up automatically; until then the hero renders a gradient stand-in. See
      `src/assets/imagery/README.md`.
- [ ] **Rerun and review the "top needs" synthesis** in `topicSummaries.json`. The
      draft on the site was made from four of the eight published projects, with every
      need traced to the survey entries it counts in
      `notebooklm/draft-synthesis-2026-09-17.md`. Nothing is reviewed yet, so the site
      labels it a draft on every surface that shows it — rerun it across all eight
      with the prompt in `notebooklm/README.md`, edit the wording, then set
      `reviewedBy` to clear the label.
- [ ] **Review the topic-area `covers` copy** in `topics.json` — the bullet list in
      the callout, and now the only area copy on `/topics/…`. The longer `intro`
      paragraphs are no longer rendered (September 2026 review) but are kept in the
      data. Stephanie or Emily should own the wording.
- [ ] **Build with the right variables** for each copy (see [Hosting](#hosting)), so
      `og:image` is emitted and the test-site copy isn't indexed.
- [ ] **Confirm the SPA fallback with Matt** — which of `.htaccess` / `_redirects` /
      `404.html` actually applies on GSL's host, and drop the two that don't.
- [ ] **Decide on a sitemap.** Under `/firehouse/`, `public/robots.txt` isn't at the
      site's root, so crawlers never read it, and a `Sitemap:` line there does
      nothing. A `sitemap.xml` of the project pages would have to be listed in GSL's
      own `robots.txt` or submitted to search engines directly.
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
- **The rest of explorer search.** Search and filters are in (`src/content/search.ts`);
  still open from `PLAN.md` §1.2–1.3 are highlighting matched words in results and
  multi-select facets (each facet is currently single-select).
- **Build-time link previews for project pages.** Each project has its own URL and
  sets its own `<title>` and description at runtime, so deep links work and a search
  engine that runs scripts sees each page's own title — but a *shared* link still previews with the site-level card. Fixing it
  means emitting a per-project HTML shell at build time (`PLAN.md` §2.2).

## The WordPress plugin

The site is moving into GSL's WordPress as a plugin; `WORDPRESS-PLAN.md` has the plan.
The plugin sits at `wp-content/plugins/firehouse/`, the path it has in a WordPress
install, the way GSL's own repository (NOAA-GSL/gsl-wordpress) keeps its theme. It
registers nothing yet: `firehouse.php` loads `domain/`, which adds no hooks.

`domain/` holds the PHP versions of `src/content/derive.ts`, `format.ts` and
`normalize.ts`:

- `Normalize::site_content()` checks the raw content files and returns them in the
  JSON shape of `src/content/types.ts`. When it refuses content, it throws a
  `Content_Error` whose message starts `[content] ` and is already escaped for HTML.
- `Derive` expects that normalized content; `Format` works on one field at a time: a
  date, a byline's authors, a need, a link.
- `Vocabulary` reads the keys and icon names from `shared/vocabulary.json`, and `Js`
  holds the few JavaScript rules PHP doesn't share, such as what counts as true or as
  whitespace.
- `Json::decode()` reads JSON as the TypeScript sees it: an empty or numbered object
  (`{}`, `{"0": …}`) stays an object, a `Json_Object`, instead of passing for a list.
  The tests read the shared cases through it, and the Import screen will read the seed
  bundle through it.

They pass the cases in `shared/cases/derive.json`, `format.json` and `normalize.json`,
the same ones `npm test` runs. Search isn't ported yet: whether the explorer needs a
PHP version depends on whether the site must work without JavaScript (plan open
question 11).

The local WordPress runs in Docker and borrows GSL's theme, WordPress images and
database from a checkout of GSL's internal gsl-wordpress repository beside this one
(`../wordpress-gsl`, or set `GSL_WORDPRESS_DIR`). GSL's database has to be running for
the first step.

```bash
bin/wp-init         # once: copy GSL's local database, activate the plugin
                    # then http://localhost:8090, with your GSL local login
bin/wp plugin list  # WP-CLI against that site
bin/wp plugin install plugin-check --activate   # once
bin/wp plugin check firehouse                   # WordPress's Plugin Check
composer install
composer lint       # PHPCS: WordPress coding standards, PHP 8.1 compatibility
composer test       # PHPUnit
```

CI runs all three on every change to the plugin: PHPCS and PHPUnit on PHP 8.1, and
Plugin Check in a throwaway WordPress (`.github/workflows/wordpress-plugin.yml`).

It listens only on this computer and is never exposed beyond it, because Firehouse
isn't hosted outside GSL, even for a demo.

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

`scripts/import-survey.mjs` turns the survey responses into
`src/content/data/projects.json`. Today they come from Stephanie and Emily's
"Submission Data" sheet, their checked copy of the Qualtrics responses, downloaded as
`.xlsx`. A Qualtrics `.xlsx` or `.csv` export works too.

```bash
node scripts/import-survey.mjs ~/Downloads/"Submission Data.xlsx" --dry
```

Download it outside this repository and delete it afterwards: it holds submitters'
emails, the repository is public, and `.gitignore` ignores spreadsheets only as a
backstop.

`--dry` parses and reports without writing — use it first. The script is the seam
where survey data becomes site content, and it is plain dependency-free Node so it
can be read and re-hosted by whoever takes it over: a live integration replaces the
CSV read with an API call and writes the same file. Nothing downstream changes.

Two rules it enforces that the UI cannot:

- **Q10 (how results were communicated) never enters the JSON.** It is internal-use
  only, and a field that is merely not rendered still ships in the bundle. Nor do Q2
  (email) and Q3 (job title).
- **Rows whose IRB answer isn't "Yes" or "Project did not require IRB review" are
  reported, not silently dropped**: a "No", a blank, or wording the script doesn't
  know. A silent drop looks exactly like a parsing bug. "Project did not require IRB review"
  is published like "Yes". `normalize.ts` filters ineligible records again at runtime
  as a backstop.

A record whose `survey-editorial.json` entry doesn't set `"published": true` stages
as unpublished, so the site doesn't show it until Stephanie or Emily publish it. It is still in `projects.json`,
though, and so in this public repository and the site's JavaScript once it's committed
and built: staging hides a record, it doesn't keep it private.

`QUESTION_MAP` at the top of the script maps each field to its question id (`Q5`).
A column is found by the id its header starts with, so both Qualtrics' bare ids and
the team's sheet's "Q5. Project Title" work. Two columns that claim the id of a
question the script reads stop the import, except that a header that is exactly the
id beats one like "Q14 - Topics". A
missing title, IRB or completion-year column stops it too, rather than writing an
empty file. Only the links box (`Q17`) may hold several entries in one cell, split
before each link or DOI; takeaways and needs have to come one per column.

The sheet has no ResponseId column, so each row is matched to its record by title
(see `keyFor`): first to a `title` in `survey-editorial.json`, then to a title already
in `projects.json`. A row matching neither is skipped and listed under Excluded. To
add a row, or after a title changes in the sheet, give its ResponseId a `title` in
`survey-editorial.json`, as WoFS-Smoke has. Two rows that land on one record stop the
import. The report says which record each row was matched to, and lists any record
in `projects.json` the import would drop.

The script stops before writing if any field holds something shaped like an email
address, naming only the record and field, and its report masks addresses. It
repairs text Qualtrics double-encoded (`NGFSâ€™s`) and lists every cell it changed.
It reads only the workbook's first sheet, and besides `projects.json` it rewrites
`notebooklm/firehouse-findings.md`. It imports TypeScript directly, so it needs Node
22.18 or later.

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

Until the WordPress plugin replaces it, the static build serves `/firehouse/`: first as
a copy on GSL's test site for the WPO meeting, then on gsl.noaa.gov for AMS (see "The
AMS bridge" in `WORDPRESS-PLAN.md`). It needs no server. `VITE_BASE_PATH` sets the
sub-path: Vite's `base` and the router's `basename` are wired to it, and the icon
links in `index.html` use `%BASE_URL%` so they resolve there too.

```bash
# The copy on GSL's test site: its own address, and kept out of search results
VITE_BASE_PATH=/firehouse/ VITE_SITE_URL=https://<test site> VITE_NOINDEX=1 npm run build

# The public site
VITE_BASE_PATH=/firehouse/ VITE_SITE_URL=https://gsl.noaa.gov npm run build
```

Set these in the shell, as above: `vite.config.ts` reads them from the environment,
and Vite doesn't give it `.env` files. Leave `VITE_NOINDEX` unset for the public build.
It takes `1`, `true` or `yes` for on and `0`, `false` or `no` for off, and stops the
build on anything else. The build prints the tags it added.

In Git Bash on Windows, start each with `MSYS_NO_PATHCONV=1`. Otherwise Git Bash
rewrites `/firehouse/` into a Windows path, and every asset link in the build breaks.

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
`og:image` has to be an absolute URL, so the `firehouse-site-meta` plugin injects it at
build time from **`VITE_SITE_URL`**, and leaves it out when that isn't set: a shared
link still previews with its title and description, whereas a guessed domain would
point at a URL that may never exist. With it set (see the build commands above), the
card at `public/og-image.png` appears in Slack, email and social unfurls.

There is no `canonical` link or `og:url`. Every route gets the same `index.html`, and
nothing rewrites the head at runtime, so either would tell search engines and link
previews that every project and topic page is the home page.

The card (1200×630, the FireHouse wordmark on white) and the icons (`favicon.ico`,
`favicon-32.png`, `icon-192/512.png`, `apple-touch-icon.png`) are generated from the
brand files in `src/assets/logos/source/`. Icons use the isolated house-and-flame mark
on a white tile, so its slate outline still reads on a dark browser tab. The UI copies
in `src/assets/logos/` include `-on-dark` variants with the slate recoloured light for
the dark header and hero. There is no vector source yet — if one arrives, regenerate
from it.

## License

Apache License 2.0. See [LICENSE](LICENSE).
