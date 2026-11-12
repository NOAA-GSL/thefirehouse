# The Firehouse

Public-facing hub for NOAA/GSL fire weather social science needs and findings.
React + TypeScript + Vite, built against the Firehouse design system and the
July 2026 creative brief.

```bash
npm install
npm run dev        # http://localhost:5173
npm run build      # typecheck + production build to dist/
npm run preview    # serve the production build
npm test           # the shared test cases, and the CSS, font and script tests
                   # (some read stylesheets in Edge or Chrome, as plugin:css does)
npm run schema     # regenerate shared/*.schema.json from types.ts
npm run seed       # write seed/firehouse-seed.json for the plugin's Import screen
npm run plugin:css # write the plugin's stylesheet from src/, scoped under .fh-app
                   # (Node 22.18+, and Edge or Chrome installed, as for npm run reference)
npm run plugin:assets
                   # write the plugin's icons and logos from the site's own
npm run reference  # capture the reference the plugin matches (reference/README.md)
npm run plugin:compare
                   # compare each built page's main landmark with the reference's
                   # accessibility snapshots, on the local WordPress (needs
                   # bin/wp-init, and Edge or Chrome)
```

The test cases live in `wp-content/plugins/firehouse/shared/cases/` as plain JSON, so
the plugin's PHP tests run them too: all but `search.json` so far.

---

## What's here

| Route              | Status                                                                        |
| ------------------ | ----------------------------------------------------------------------------- |
| `/`                | **Landing page** — logo and hero with live stats, then the four topic areas' top-5 needs with project counts, "where these needs come from", the GACC coverage map and the submit CTA band. |
| `/about`           | **About page** — what the hub is, the three audiences it serves, how a completed project becomes a published need, the two people who maintain it with the team's mailbox, and a closing call to action. All copy lives in `about.json`; the biographies are rendered verbatim. |
| `/projects`        | **Project explorer** — brief §5.2. Search, fire-phase / GACC / year / project-type filters, sort, detail modal. |
| `/topics/:topicKey` | **Topic area page** — the area's top five needs, each with its project count and links to the projects it draws on, plus a "what this area covers" callout. One per `TopicKey`. Projects are not filed under topics: needs are synthesized across the whole collection, so the attribution is per *need*. |
| `*`                | **Custom 404** — echoes the requested address, and offers the explorer plus all four topic areas rather than dead-ending. Also rendered by `/topics/:topicKey` for a key that isn't one of the four, with wording tailored to that case. |

The landing page is a faithful implementation of `Firehouse Landing Page.dc.html`
from the Claude Design project. Every deviation is listed under
[Deliberate deviations](#deliberate-deviations) below.

## Layout

```
src/
  design-system/    The Firehouse design system, ported to React. Pages import
                    only from here — never raw token values.
  styles/tokens/    colors / typography / spacing / glass, ported byte-faithfully.
  styles/           global.css, a11y-overrides.css, inward-reset.css, page-shell.css
  content/          CMS-agnostic content layer (see below)
  pages/            Landing, Projects, Project, Topic, About and NotFound pages
  components/       Layout, ThemeProvider, SynthesisNote, Disclaimer
  assets/           NOAA + GSL logos; hero imagery (see caveat)

public/             Copied as-is to the build's base path (the site root, or /firehouse/):
                    icons, the link-preview card, robots.txt, and the two host-level
                    SPA fallbacks (see Hosting)

wp-content/plugins/firehouse/
                    The WordPress plugin that will replace this app (see
                    The WordPress plugin, below). So far: firehouse.php, which
                    loads the content logic in PHP (domain/) and what hooks
                    into WordPress (includes/); templates/frame.php, the frame
                    every Firehouse page sits in; blocks/, the server-rendered
                    blocks the templates and pages place (so far the Firehouse
                    bar, the not-found page, the project pages, the topic
                    pages and the landing page); the shared
                    vocabularies and test cases; and the PHP tests.
                    assets/css/firehouse.css is generated from src/, and
                    assets/css/frame.css is written by hand;
                    assets/fonts/ and assets/css/fonts.css hold the fonts;
                    assets/icons/ and assets/logos/ are generated from src/.
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

Every stylesheet is scoped under `.fh-app` at build time (see The WordPress plugin,
below), so selectors are written as usual. After changing one, run `npm run plugin:css`
to update the plugin's copy, or CI fails.

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

**Live stats are computed, not stored.** `landing.json` never contains the published-project count.
Each stat declares a `source` (`publishedProjectCount` / `needCount` / `topicCount` /
`static`) and `derive.ts` counts live content. Editors control the wording; they can't put the
count out of step with what's published. Publishing another project moves the
counter on its own — brief §5.1.

Structural things stay in code on purpose: the four topic keys (`observe`,
`forecast`, `warning`, `governance`) are a `TopicKey` union, because adding a fifth
topic is a schema and pipeline change, not a CMS edit. Their display names *are*
editable.

## Deliberate deviations from the design

Everything below is an addition or correction, with the reason. Nothing was dropped
but the Google Fonts `@import` in `tokens/typography.css`: the same fonts are
self-hosted instead (see The WordPress plugin), so no page calls a third-party CDN.

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
- **A disclaimer ends every page** (`disclaimer` in `settings.json` and `types.ts`), just
  above the footer: researchers outside NOAA can submit, so the site says whose views
  the findings are. The field is new and optional; delete it and the line disappears.

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
      labels it a draft on every surface that shows it. Stephanie and Emily reran it
      across all eight on 27 October with their own prompt (see the next items); its
      reviewed table replaces the draft, with `reviewedBy` set to clear the label. The draft also counts some needs more than once
      per project, so four of its "Project count" figures exceed the projects listed
      under them (`src/content/synthesis.test.ts` pins the four). The rerun has to
      replace it before any copy goes up, the test site's included.
- [x] **Set up `gsl.firehouse@noaa.gov`.** Since 2 November the About page gives it as
      the team's address in place of Stephanie's and Emily's own, so it has to receive
      mail before any build is deployed, the test site included. Requested from GSL's
      help desk on 2 November; Eric reported it working the same afternoon.
- [ ] **Clear the disclaimer** that ends every page (`disclaimer` in `settings.json`).
      Asked whether its wording works, Stephanie and Emily answered "Yes - disclaimer
      for Landing Page, About Page, Project Pages" on 3 November. It speaks for NOAA, so it goes
      through clearance before gsl.noaa.gov.
- [ ] **Finish the 3 November wording round.** Stephanie and Emily answered on 3
      November, in one reply signed by both. On 6 November Stephanie, relayed by Eric,
      answered the ranking question, saying the About page should say "something
      like": "Order of the top 5 needs is determined by project count; in the event of a tie, the need with broader interagency or multi-regional applicability is prioritized." The About page's second process step now carries it, with "The"
      added. Eric confirmed on 6 November that this is the approved wording: the
      sentence is Stephanie's own, given for that page. Still open, for when they
      review the pages:
      - the three lines that say "how often" or "frequency": "ranked by frequency"
        (`about.json`), "with how often each one came up" (`landing.json`) and
        "ranked by how often they come up" (written into `LandingPage.tsx`);
      - whether the synthesis needs its own line saying whose it is.
- [ ] **Bring in the team's synthesis prompt.** Their "FireHouse Prompt (Final)", which
      Stephanie describes as being in their Gemini notebook, orders needs by project
      count with that tie-break. It assigns an entry that spans several topic areas by
      a fixed hierarchy: governance, then warnings, then forecasts, then observations.
      The prompt in `notebooklm/README.md` differs: it ranks by importance and lets an
      entry count in several areas. The team's full text replaces it, through the
      Prompt Engineer, after old and new are run on the same projects (CLAUDE.md). It
      has to keep, or come with updates to, what other docs rely on: the JSON shape
      in `notebooklm/README.md` and the once-per-project count in the plan. The
      plan's "Need counts" row then records the hierarchy too.
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
- [x] **Self-host the fonts.** Some agencies prohibit third-party CDN calls.
      Archivo and Public Sans now come from the site itself: Google's own files,
      saved in the plugin's `assets/fonts/` and loaded from `assets/css/fonts.css`
      (see The WordPress plugin, below).
- [ ] **Ask GSL for a `wp_body_open()` hook** in its theme. Firehouse's skip link then
      moves before the theme's header; until then it sits at the top of the Firehouse
      area, after the header's links (plan, Phase 3).
- [ ] **Record that Stephanie and Emily agree** to their photos and biographies
      appearing publicly on gsl.noaa.gov: who confirmed, what they agreed to, and when.
      `src/assets/people/README.md` asks for this before the page goes live; that they
      supplied the photos isn't a recorded agreement.
- [ ] **Upload the team's portraits before importing,** to the Media Library on each
      WordPress site the plugin runs on (the test site, then gsl.noaa.gov), once the
      item above is done. Use the two files in
      [`src/assets/people/`](src/assets/people/), `stephanie-hoekstra.webp` and
      `emily-wells.webp`, without renaming them, under Media → Add New Media File; not
      originals, which can carry the location a phone photo was taken, removed from
      these. WordPress sets each image's **Title** to its file name without the
      extension; leave it as it is, since the import finds each portrait by that Title,
      the member's `photo` in `about.json`. The image's Alternative Text isn't used:
      the name printed beside the photo already says who it is. The dry run names the
      image it found for each person, the newest with that Title, with who uploaded it
      (their login, and whether they can import), or says it found none: check each is
      the right person before confirming. If it names the wrong image, such as a staff
      headshot uploaded since, change that image's Title in the Media Library, or
      upload the right file again so it's the newest, then preview again. The import keeps those images
      in the page, so one uploaded later changes nothing until Phase 6's editing
      screens let an editor choose it; until then, a card with no portrait shows the
      person's initials. After importing, open `/firehouse/about/` and check that each
      photo sits under the right name.
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
install, the way GSL's own repository (NOAA-GSL/gsl-wordpress) keeps its theme.
`firehouse.php` loads two folders: `includes/`, which works with WordPress's hooks,
posts, options and screens, and `domain/`, plain PHP that adds no hooks and reads
nothing from the database. Each class's docblock has the detail; this is the map.

`includes/`:

- `Frame` puts every Firehouse request in `templates/frame.php`: the project archive, a
  project, a topic, a page in the `firehouse` tree (a draft's preview included), and a
  missing address under `/firehouse/`. It decides from the query WordPress ran, not
  the request's own variables, and leaves embeds to WordPress's embed template.
  - **The template** is the theme's own `get_header()` and `get_footer()` around
    `div.fh-app`, outside the theme's content wrappers. The wrapper holds a skip link,
    the Firehouse bar (the `firehouse/subnav` block) and the page's one `main`, which
    the skip link leads to. The main landmark holds the page's content, then the
    disclaimer that ends every Firehouse page: the site's wording, approved by
    Stephanie and Emily on 3 November (NOAA's clearance is still open; see Before this
    goes public), kept in `Frame::disclaimer()`, not as a setting, and held to the
    site's `settings.json` by a test. A missing address gets the `firehouse/not-found`
    block, a project's address the `firehouse/project` block, a topic's address the
    `firehouse/topic` block, a page in the Firehouse tree its own blocks (not through
    `the_content`), and a page Phase 4 hasn't built yet a placeholder.
  - **Stylesheets:** it loads `fonts.css`, `firehouse.css` and `frame.css` after the
    theme's stylesheet, at the plugin's version and each file's modified time, and adds
    `fh-page` to the body. Nothing depends on the fonts, so dequeuing `firehouse-fonts`
    drops only them.
  - **Missing addresses:** at a project's or topic's address under `/firehouse/`, the
    plugin makes its own guess at what was meant, among that type only, so a link cut
    short still finds its project. Any other missing address under `/firehouse/` gets
    the frame's not-found page, not a page elsewhere on the site. Projects and topics
    are left out of the site's own search, and so out of WordPress's guess at a missing
    address anywhere else.

  `assets/css/frame.css`, written by hand, holds what Firehouse needs only inside the
  theme's page:
  - it clips the fixed atmosphere to the Firehouse area;
  - it drops the static build's full-window height, so a short page doesn't push the
    footer down;
  - it sets `color-scheme` to match the page's light or dark mode, and makes
    `[hidden]` hide;
  - it draws the skip link target's focus ring inside the clip, and stops anything
    focus scrolls into view below the pinned bar;
  - it puts the pinned Firehouse bar below WordPress's toolbar when there is one (at
    the top on phones), and without JavaScript, below 1024px, it keeps the links in
    the bar on a row of their own, with the Submit button after them, and lets the
    bar scroll with the page;
  - on Firehouse pages only, it hides the theme's hero background and the mission
    section above its footer.

  Every rule stays inside the wrapper except the last, and
  `scripts/frame-css.test.mjs` holds it to that.

- `Blocks` registers each block named in `Blocks::NAMES`, one folder each in `blocks/`,
  from its `block.json`.
  - Each block renders on the server (`render.php`) and stays out of the inserter: the
    templates place it.
  - An interactive block has a view script module for WordPress's Interactivity API.
    It's plain JavaScript with no build step: WordPress maps its
    `@wordpress/interactivity` import, which `view.asset.php` declares.
  - `tests/BlocksTest.php` holds each folder to that shape, and fails on a folder
    missing from the list.
- `Subnav` is the Firehouse bar, `firehouse/subnav`: the site's `SiteHeader` without
  the NOAA and GSL logos and the theme toggle, which the theme's header already has.
  - It has the wordmark, the Settings screen's link labels, and the Submit a Finding
    button, which is left out until a survey address is set. A survey on another site
    opens in a new tab and tells screen readers so.
  - It marks the current page's link with `aria-current="page"`: Home on the landing
    page, Explore Projects on the explorer and every project, About on the About page
    and any page under it. A topic or a missing address marks none.
  - It's a `div`, not a `header`, since the theme's header is the page's banner. Its two
    navs, the bar's and the drawer's, are both labelled "Firehouse".
  - It stays pinned once the theme's header has scrolled away.
  - Below 1024px its links and Submit button fold into a drawer that the menu button
    opens and closes (`blocks/subnav/view.js`). Escape closes it and returns focus to
    the menu button, and focus leaving the bar closes it. The button is named
    "Firehouse menu" whether open or shut, since the theme's header has a menu button
    too; `aria-expanded` says which.
- `Not_Found` is the Firehouse 404, the `firehouse/not-found` block: the site's
  `NotFoundPage`, with the way back, the explorer and the four topic areas. It quotes
  the requested address, escaped and cut to 64 characters with an ellipsis. Any
  address under `/firehouse/topics/` gets the "Topic area not found" wording.
- `Project_Page` is a project's page, the `firehouse/project` block: the site's
  `ProjectPage`, with the abstract, takeaways, need cards and papers as the researcher
  submitted them, the "At a glance" panel, and the projects either side of it in the
  explorer's order, among those a visitor may see. A need card whose text runs past
  its height scrolls, and only then becomes a tab stop named for its need
  (`blocks/project/view.js`). Until the script has measured a card its cap is off, so
  without JavaScript, or if the script doesn't run, the whole entry shows. A paper's
  address goes through `Markup::url()`.
- `Topic_Page` is a topic area's page, the `firehouse/topic` block: the site's
  `TopicPage`, with the area's top five needs, the published projects behind each,
  what the area covers, the Submit button once a survey address is set, and the other
  three areas. A need that names a project a visitor may not see leaves it out and
  counts only the projects that remain, as the site's normalize step does.
- `Markup` writes the design system's small components as the site renders them: a
  link styled as a `Button` (opening another site in a new tab, and saying so), a
  `TopicTag`, and the `SynthesisNote` that says the needs are synthesized and whether
  the synthesis has been reviewed. `Markup::text()` escapes stored text so it shows
  exactly as stored, where `esc_html()` would let a character reference such as
  `&amp;` show as "&" (rule 1). `Markup::url()` makes an address lead where a browser
  would take it: in an http or https address, or one with no scheme, a `\` before the
  query is a `/`, and what `esc_url()` would drop is percent-encoded, so dropping a `\`
  can't move the host. It's checked with `esc_url_raw()` and escaped with
  `Markup::text()`, since `esc_url()` would let a stored `R&amp;D` be followed as
  `R&D`. A button decides whether it opens in a new tab from the same reading.
- `Verbatim` keeps researchers' words as they wrote them on Firehouse pages (rule 1).
  It turns off curly quotes, dashes and smilies for everything the page prints, the
  theme's header and footer included, and stops WordPress recapitalizing its own name
  in titles, the `<title>` and content.
- `Icons` inlines an icon from `assets/icons/` by a name in the vocabulary, never by a
  path. It replaces the file's 24×24 size and marks the icon decorative. An unknown
  name gives an empty string, not an error.
- `Post_Types` registers `fh_project` and `fh_topic` at the React site's addresses:
  `/firehouse/projects/`, `/firehouse/projects/{slug}/` and `/firehouse/topics/{key}/`
  (no topic archive). Neither is in the REST API, and their edit screens have no form
  for the fields yet (Phase 6). No one adds either in the admin.
- `Meta` registers every field in `shared/project.schema.json` and
  `shared/topic.schema.json` (`npm run schema` writes them from `types.ts`) as post
  meta named as in `types.ts` with an `fh_` prefix, except what the post holds: a
  project's slug and `published` (its status), and a topic's key and label (its slug
  and title). The project's title is meta, verbatim (rule 1). A need's `projects`
  holds post IDs, not slugs (rule 5).
- `Store` reads posts back as the fields of `types.ts`, numbers and booleans typed and
  a need's `projects` still post IDs, and saves them. `Store::public_projects_query()`
  defines a project a visitor may see: published, without a password, and with IRB
  approval or exemption, as the site's normalize step keeps them.
  `Store::is_public()` is the test for a post already loaded. The plugin's own lookups
  use both: the project pages' previous and next links, the topic pages' links and
  project total, and the not-found guess.
- `Visibility` keeps every other project off the public side of the site. It drops
  one from what a query finds, outside the admin's own screens, so its address,
  `?p=`, the archive, the feed, embeds and the sitemap leave it out. It refuses one
  to the redirects WordPress builds from a post ID, to oEmbed and to a changed slug's
  redirect, and marks a menu item that links to one invalid. Someone who may edit the
  project still sees it on its own page, as WordPress shows them a draft in preview,
  and nowhere else. A get_posts() call and a query for IDs only skip these filters, and
  WordPress limits a list before them, so the plugin's own lookups and lists ask for
  public projects themselves.
- `Query` drops WordPress's `year` query variable from Firehouse requests (rule 7), and
  a project or topic variable that names no post (`?fh_project[]=x`, `?fh_topic=`) from
  any request (rule 13).
- `Capabilities` defines the Firehouse Editor role: projects, topics, the Firehouse
  settings and the `firehouse` page tree, and nothing else on the site. The role
  stores `read` and Firehouse's own capabilities; WordPress's page capabilities are
  lent only while the plugin runs, so with the plugin off its users can only sign
  in. Only administrators have `firehouse_import`. No one can delete the landing page
  for good while pages outside the trash are under it: WordPress would move them to the
  top of the site, the About page beside the site's own `/about/`. The Pages screens
  say why.
- `Installer` stores the role and the administrators' capabilities and has the
  rewrite rules rebuilt, on activation and after `Installer::REVISION` changes.
- `Settings` is Firehouse → Settings: the site name, the Submit a Finding label and
  address, and the three navigation labels, read with `Settings::get()`.
- `Seed_Bundle` reads a seed bundle and checks it; `Importer` plans the import and
  writes it through `Store`; `Import_Screen` is Firehouse → Import, for
  administrators. `npm run seed` writes the bundle from `src/content/data/` to
  `seed/firehouse-seed.json`, which git ignores; upload it on the screen. The screen
  shows a dry run of every change and problem, writes nothing while there is a
  problem, and imports once confirmed.
  - Projects are matched by response ID, so importing again updates the same posts.
    Survey fields come from the bundle; editorial fields (`Importer::EDITORIAL`, and
    the geography note and states) keep WordPress's (rule 4). Projects the bundle
    doesn't have are left alone.
  - Topics and settings are written only when they don't exist yet; after that,
    WordPress's are kept, so importing again never overwrites a reviewed synthesis.
  - The landing page, the `firehouse` page at `/firehouse/`, is created from the
    bundle's landing content as the landing page's blocks, only when there's no such
    page in any status but the trash; after that its words are the editors'. One in
    the trash, or anything else holding the address (a Media Library file named
    `firehouse`), stops the import, and a page WordPress saves at another address
    anyway is deleted, not left published. A link to the bundle's survey address is
    stored empty, so it follows the settings. Two confirmations of one dry run, as a
    double click sends, import once.
  - The About page, the `about` page under it at `/firehouse/about/`, is created the
    same way from the bundle's about content, under the landing page: one that already
    exists, or one this run created and read back as planned. If the run deletes a
    misplaced landing page, the About page isn't created, so WordPress can't move it to
    the top of the site. An About page in the trash under the landing page, a file
    uploaded to the landing page whose slug is `about`, or a section, audience card or
    step without its heading, stops the import. Each member's portrait is the newest
    Media Library image whose Title is their `photo`: the dry run names it and who
    uploaded it, for whoever imports to check, and the import keeps it in the page by
    its ID (decided by Eric, 12 November). The page's title is "About — The
    Firehouse", as the site's is, so it isn't a second "About" beside the site's own.
  - Each post and page is read back after it is written.
- `Landing_Page` is the landing page's blocks: `firehouse/hero` with `firehouse/stats`
  inside it, `firehouse/top-needs` and `firehouse/submit-band`, as the site's
  `LandingPage` renders them, without the coverage map, which comes with the explorer
  in Phase 5. The words are the blocks' attributes; the counts, the four topic cards
  and the synthesis note are computed as the page renders, from the projects a visitor
  may see and the published topics, with the topic pages' counting. A button with
  nowhere to go, such as Submit a Finding with no survey address set, is left out.
- `About_Page` is the About page's blocks, as the site's `AboutPage` renders them:
  `firehouse/about-intro`, `firehouse/about-sections` holding `about-mission`,
  `about-audiences`, `about-process` and `about-team`, and `firehouse/about-cta`. The
  words are the blocks' attributes, the biographies printed as written. Each member's
  portrait is the image the import kept by its ID, printed by
  `wp_get_attachment_image()` with its sizes for the 168px it's drawn at; the page
  looks nothing up as it renders, and without one the card shows their initials
  (`Format::initials()`, held to the site's by `shared/cases/format.json`). The import
  finds the image by its Title, not its slug: WordPress gives every upload a slug no
  other post on the site has, so where a page such as a staff profile already uses
  `stephanie-hoekstra`, the upload becomes `stephanie-hoekstra-2`. The team's mailbox
  shows only if it's a team work address, with anything a `mailto:` link would read
  as more than the address encoded.
- `Page_Blocks` is what the two share: building their blocks from the bundle's text,
  where a stored link goes, and its button, left out when it leads nowhere.

The traps, for whoever changes this code next:

- **Raise `Installer::REVISION`** whenever you change the role, the capabilities or
  the addresses, or sites won't apply the change.
- **Write fields through `Store`.** WordPress strips backslashes from what it saves,
  so `Store` slashes every value, and runs with the kses filters off so text is kept
  as written. A post's own title is stored escaped (`Store::label()`), for themes
  that print it raw.
- **Call `Meta::validate()` before writing a field.** It checks a value against its
  schema and its exact PHP type. `Meta` also refuses, by any code, a write of a field
  that fails it, reading a number or boolean in the text form WordPress stores
  ("2025", "1") as that type, so a restored revision still saves; but its refusal is
  only a `false` from WordPress's function, with no message. No one may change a
  field through WordPress's custom-field routes.
- **The plugin checks the bundle itself.** It refuses fields it doesn't store in a
  project, topic or synthesis (the landing and About pages keep only the text their
  blocks use, and drop the rest), and anything shaped like an email address but a team
  work address in the About page's team mailbox field, by the same pattern and field list as the Node scripts
  (`shared/email-shape.json`).
- `uninstall.php` removes the role, so users who had it are left with no role on the
  site, and the capabilities; the content stays.

`domain/` holds the PHP versions of `src/content/derive.ts`, `format.ts` and
`normalize.ts`:

- `Normalize::site_content()` checks the raw content files and returns them in the
  JSON shape of `src/content/types.ts`. When it refuses content, it throws a
  `Content_Error` whose message starts `[content] ` and is already escaped for HTML.
- `Derive` expects that normalized content; `Format` works on one field at a time: a
  date, a byline's authors, a need, a link.
- `Email_Shape` finds text shaped like an email address where none belongs, the
  server's backstop for rule 2, by the pattern in `shared/email-shape.json`, which the
  survey import and the seed bundle script use too.
- `Vocabulary` reads the keys and icon names from `shared/vocabulary.json`, and `Js`
  holds the few JavaScript rules PHP doesn't share, such as what counts as true or as
  whitespace.
- `Json::decode()` reads JSON as the TypeScript sees it: an empty or numbered object
  (`{}`, `{"0": …}`) stays an object, a `Json_Object`, instead of passing for a list.
  `Normalize` turns every one back into an array on the way out, so pages and post meta
  only see arrays. The tests read the shared cases through it, and the Import screen
  reads the seed bundle through it.

They pass the cases in `shared/cases/derive.json`, `format.json`, `normalize.json` and
`email-shape.json`,
the same ones `npm test` runs. Search isn't ported yet: whether the explorer needs a
PHP version depends on whether the site must work without JavaScript (plan open
question 11).

`assets/css/firehouse.css` is the site's CSS, scoped under `.fh-app` so it can sit
inside GSL's theme without reaching the theme's header and footer (plan rule 9). It
leaves out what GSL's theme replaces (the static build's page shell, gov banner and
footer), the coverage map's CSS until Phase 5, and the fonts, which load on their own.
Don't edit it: `npm run plugin:css` writes it from `src/`, and CI fails if it falls
behind.
The site's own build scopes every stylesheet the same way (`scripts/scope-css.ts`), with
`#root` as the wrapper, so the static site runs on the same CSS the plugin gets, and
the build stops on any rule that could match outside the wrapper. Before writing,
`plugin:css` also loads the result into a real browser (`scripts/css-browser-check.mjs`)
and stops on any request, unscoped rule or loading value it finds there, since
PostCSS and a browser tokenize some CSS differently.
`src/styles/inward-reset.css` undoes GSL's element styles inside the wrapper, and
`src/styles/page-shell.css`, which styles the static build's own `<html>` and
`<body>`, is the one stylesheet left unscoped. The plugin loads `firehouse.css` on Firehouse
pages, through `Frame` (above).

`assets/fonts/` holds Archivo and Public Sans, Google Fonts' own files with their SIL
Open Font License, and `assets/css/fonts.css` their `@font-face` rules. The static
build imports that file (`src/main.tsx`), and the plugin loads it on Firehouse pages
through `Frame`, so no page calls Google. The static build copies each family's licence into
`dist/assets/` beside its fonts (`vite.config.ts`), as the licence asks, and stops if
one is missing.
- `node scripts/fetch-fonts.mjs` refreshes them: the fonts from Google, the licences
  from a pinned commit of Google's fonts repository on GitHub. Only that script reaches
  either. The families, weights and styles are listed in the script, so a new weight
  starts there.
- `assets/fonts/SOURCES.json` records where each file came from, with its SHA-256, and
  `scripts/fonts.test.mjs` checks the committed files against it. It also checks that
  `fonts.css` holds only the two families' `@font-face` rules, pointing at those files.
- Google serves no Archivo Expanded, so the stats, the wordmark and the About page's
  expanded heading fall back to Archivo, as they always have.
- To switch to GSL's fonts, stop loading `fonts.css` (in `src/main.tsx`, and in
  `Frame::enqueue()`) and
  point the three font tokens in `src/styles/tokens/typography.css` at Montserrat and
  Nunito Sans. The static build would then need its own way to load those, or it falls
  back to system fonts.

`assets/icons/` holds every icon in the site's registry (`ICON_NAMES` in
`src/design-system/Icon.tsx`) as an SVG file, drawn by the site's own `Icon` component,
and `assets/logos/` the images `BrandMark` imports. Don't edit either: `npm run
plugin:assets` writes both from `src/`, and CI fails if they fall behind. An icon file
keeps the drawing exactly as the site renders it, at Lucide's 24×24, and leaves off what
belongs to one use (class, inline style, `aria-hidden`, `focusable`). Where the plugin
inlines an icon it adds those and replaces the size, as `Icon` does. Every icon may hold
only the plain shapes and drawing attributes `shared/icon-svg.json` lists: the script
refuses anything else, and `tests/IconsTest.php` checks the files that ship. Lucide's
licence sits beside them as `LICENSE.txt`. The NOAA and GSL logos stay with the static
build, since GSL's header carries its own.

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
npm run seed        # then upload seed/firehouse-seed.json at Firehouse → Import
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
from it, then run `npm run plugin:assets` so the plugin's copies follow.

## License

Apache License 2.0. See [LICENSE](LICENSE).
