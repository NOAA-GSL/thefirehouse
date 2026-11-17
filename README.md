# The Firehouse

Public-facing hub for NOAA/GSL fire weather social science needs and findings.
React + TypeScript + Vite, built against the Firehouse design system and the
July 2026 creative brief.

The site and the plugin print the name as **FireHouse** (Stephanie and Emily's
decision, relayed by Eric, 13 November); the code says `firehouse` and `Firehouse`,
and so do these docs, except where they quote what a page or screen shows.

```bash
npm install
npm run dev        # http://localhost:5173
npm run build      # typecheck + production build to dist/
npm run preview    # serve the production build
npm test           # the shared test cases, and the CSS, font and script tests
                   # (some read stylesheets in Edge or Chrome, as plugin:css does)
npm run schema     # regenerate shared/*.schema.json from types.ts
                   # (schema:check only compares; CI runs it, and typecheck too)
npm run seed       # write seed/firehouse-seed.json for the plugin's Import screen
npm run plugin:css # write the plugin's stylesheets from src/, scoped under .fh-app
                   # (Node 22.18+, and Edge or Chrome installed, as for npm run reference;
                   # plugin:css:check only compares, and CI runs it)
npm run plugin:assets
                   # write the plugin's icons, logos, link-preview image, search module
                   # and map module from the site's own (plugin:assets:check only
                   # compares; CI runs it)
npm run plugin:blocks
                   # write the plugin's block manifest from its block.json files
                   # (plugin:blocks:check only compares)
npm run plugin:zip # build release/firehouse-<version>-<commit>.zip, the file WordPress
                   # installs, from the last commit, without the tests (CI builds it
                   # on every change to the plugin; see "The plugin's zip" below)
npm run plugin:help
                   # build the FireHouse guide the plugin shows, help/help.json and
                   # help/README.md, from its Markdown pages in help/ (plugin:help:check
                   # only compares; CI runs it)
npm run reference  # capture the reference the plugin matches (reference/README.md)
npm run plugin:compare
                   # compare each built page's main landmark with the reference's
                   # accessibility snapshots, on the local WordPress (needs
                   # bin/wp-init, and Edge or Chrome)
```

The test cases live in `wp-content/plugins/firehouse/shared/cases/` as plain JSON, so
the plugin's PHP tests run them too.

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
  assets/           logos (the FireHouse marks, NOAA and GSL's for the static build,
                    and the brand originals in logos/source/), the team's portraits
                    (people/), and a place for a hero photograph (imagery/, only its
                    README so far)

public/             Copied as-is to the build's base path (the site root, or /firehouse/):
                    icons, the link-preview card, robots.txt, and the two host-level
                    SPA fallbacks (see Hosting)

wp-content/plugins/firehouse/
                    The WordPress plugin that will replace this app (see
                    The WordPress plugin, below). So far: firehouse.php, which
                    loads the content logic in PHP (domain/) and what hooks
                    into WordPress (includes/), the admin screens among them
                    (Settings, Import, the project and topic edit screens,
                    Synthesis and Help); survey/, the survey form and what
                    happens to a submission; templates/frame.php, the frame
                    every Firehouse page sits in; blocks/, the server-rendered
                    blocks the templates and pages place (the Firehouse bar,
                    the not-found page, the project pages, the topic pages,
                    the landing page, the About page, the explorer, the
                    coverage map and the survey form); help/, the FireHouse
                    guide, as Markdown pages and the help.json built from
                    them; the shared vocabularies and test cases; and the PHP
                    tests.
                    assets/css/firehouse.min.css, coverage-map.min.css and their
                    readable copies are generated from src/, and
                    assets/css/frame.css and survey.css are written by hand;
                    assets/fonts/ and assets/css/fonts.css hold the fonts;
                    assets/js/block-editor.js, which shows the blocks and their
                    words' fields in the block editor, is written by hand;
                    assets/icons/, assets/logos/, assets/preview/, assets/search/
                    and assets/map/ are generated from src/, public/ and the site's
                    npm packages by npm run plugin:assets;
                    blocks/blocks-manifest.php is generated from the blocks'
                    block.json files.
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
to update the plugin's copies, or CI fails.

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
They leave out the two italic faces the `@import` asked for, since nothing on the
site is set in italic.

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
      replace it before any copy goes up, the test site's included. The reviewed
      table is transcribed word for word to the prompt's JSON in
      `notebooklm/reviewed-synthesis-2026-10-27.json` (16 November), and the Research
      Synthesist has checked it against the projects; that check goes to Stephanie and
      Emily. They decide on it and mark it reviewed on FireHouse → Synthesis (Eric, 16
      November). For this site, the same needs, with each topic's `updatedAt`,
      `sourceCount`, `model` and `reviewedBy` added, replace the draft here.
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
      - the last of the three lines that said "how often" or "frequency": "with how
        often each one came up" (`landing.json`). In her review of the preview on 11
        November Emily changed the other two, to "ranked by project count" (`about.json`)
        and "ranked by prevalence across projects" (written into `LandingPage.tsx`, and
        separately into the plugin's `includes/class-landing-page.php`: change both,
        since no test keeps them the same). Wording from `landing.json` and `about.json`
        also lives in each imported site's landing and About pages, which a later import
        leaves as they are and an editor changes in the block editor. Her review also
        changed "AI" to "SI", as Executive Order 14434 requires of federal sites
        ("Super Intelligence" and "SI" in place of "artificial intelligence" and "AI").
        On 16 November Eric chose how: the note says "Drafted with Super Intelligence
        (SI) across …", "Where these needs come from" says the projects are read
        together "with Super Intelligence (SI)" and, citing the order, that it means the
        same as artificial intelligence, as the topic pages also say beside the note;
        the project pages say the needs in the topic areas "are drafted with Super
        Intelligence (SI)". None of these names the tool while the service is undecided;
        the survey form's notice does, as the Google Form's did (see the survey form's
        wording, below). Stephanie and Emily to confirm the wording. It is
        the synthesis's disclosure, so it goes through clearance with the reviewed
        synthesis before gsl.noaa.gov;
      - whether the synthesis needs its own line saying whose it is;
      - the explorer's "Update results" button, which only the plugin has: without
        JavaScript it sends the search and filters. The Science Communicator suggests
        keeping the label, with "Apply filters" as the alternative;
      - the explorer's search hint, for screen readers: without JavaScript it is
        "Searches titles, authors, abstracts, and findings.", without the site's
        "Results update as you type. Press Escape to clear." The Science Communicator
        offers "Press Enter to search." in its place, and, on both sites, naming what
        the search covers in the site's own words (organizations, takeaways, methods,
        end-user needs and recommendations).
- [ ] **Settle the rest of the wording Phases 4 and 5 left open,** when Stephanie
      and Emily review those pages (the explorer's button and search hint are in the
      item above). The words are the same on both sites:
      - the phase and status pills in the quick look, and the phase pills on the
        explorer's tiles, which a screen reader hears as a run of words ("Before
        During Published"): a visually hidden "Fire phases:" and "Status:" would need
        their wording;
      - the coverage map's hint, "Select a GACC on the map or in the list to see the
        research filed there.", and its "Not on the map" heading, which speak of a
        map that a visitor without JavaScript doesn't get on the plugin's page (the
        site shows nothing at all without it). "Not on the map" also names a group in
        the explorer's region filter, where no map is shown;
      - the need cards' names for screen readers: "Need and recommendation —
        scrollable" for a recommendation alone, and the same name for every card
        without a lead.
- [ ] **Bring in the team's synthesis prompt.** Their "FireHouse Prompt (Final)", which
      Stephanie describes as being in their Gemini notebook, orders needs by project
      count with that tie-break. It assigns an entry that spans several topic areas by
      a fixed hierarchy: governance, then warnings, then forecasts, then observations.
      The prompt in `notebooklm/README.md` differs: it ranks by importance and lets an
      entry count in several areas. The team's full text replaces it, through the
      Prompt Engineer, after old and new are run on the same projects (CLAUDE.md). It
      has to keep, or come with updates to, what other docs and code rely on: the JSON
      shape in `notebooklm/README.md` and `Synthesis::check()`, and the once-per-project
      count in the plan. The
      plan's "Need counts" row then records the hierarchy too. In the same change the
      source document's title becomes "The FireHouse — collected project findings"
      (Eric, 16 November), in `scripts/notebooklm-source.mjs`,
      `domain/class-notebook-source.php` and `shared/cases/notebooklm-source.json`,
      whose `expected` is regenerated from the script. The plugin's synthesis check
      (`Synthesis::check()`, `domain/class-synthesis.php`) reads the prompt's JSON and
      refuses an area whose needs don't run by project count, highest first. The team's
      prompt orders them that way; the current one, which ranks by importance, doesn't.
      The plugin's own prompt, `shared/synthesis-prompt.txt`, changes with it: a test
      holds it to the one in `notebooklm/README.md`, so Analyze results and NotebookLM
      are compared on the same words.
- [ ] **Review the topic-area `covers` copy** in `topics.json` — the bullet list in
      the callout, and now the only area copy on `/topics/…`. The longer `intro`
      paragraphs are no longer rendered (September 2026 review) but are kept in the
      data. Stephanie or Emily should own the wording.
- [ ] **Confirm the page titles and link-preview text** with Stephanie and Emily. The
      titles are the site's: "{page} — The FireHouse", and the landing page's "The
      FireHouse — Fire Weather Social Science Hub". The explorer's title follows its
      link label, "Explore Projects", where the React site's title says "Explore
      projects". The description, preview text and image alt text in
      `index.html` are repeated by the plugin (`includes/class-head.php`), and the alt
      text describes the hub, not the image. Record who confirmed what text, and when.
- [ ] **Build with the right variables** for each copy (see [Hosting](#hosting)), so
      `og:image` is emitted and the test-site copy isn't indexed.
- [ ] **Confirm the survey form's wording** with Stephanie and Emily, then send it
      through clearance before gsl.noaa.gov (CLAUDE.md). The notice, the no-IRB thanks
      and the questions are the Google Form's (`survey/class-questions.php`), with "AI"
      made "SI" (Executive Order 14434) and the three list questions reworded for one
      box per entry. The rest is the plugin's own: each private question's line saying
      who sees the answer (for the privacy review too), the thanks, the closed page and
      the error messages (`survey/`). The SI disclosure still names the tool ("Google
      Gemini within a secure NOAA Gemini / NotebookLM workspace"), which the plan
      expects to name the approved service instead (plan open question 7). A question's
      wording and help can be changed under FireHouse → Settings; everything else is in
      code.
- [ ] **Open the survey form only once it's cleared:** the privacy review, the
      written Paperwork Reduction Act answer (plan open questions 12 and 14), and GSL's
      security review of the release that carries it. Then tick Accept submissions
      under FireHouse → Settings, which makes the Submit page.
- [ ] **Set the Cloudflare rate-limit rule** on POSTs to `/firehouse/submit/` on each
      site, before its form opens: it limits repeat submissions, so the plugin keeps
      nothing about visitors (Eric, 16 November). The plugin takes a submission at
      that exact address only. Adding or removing an entry's box is a POST to the
      same address, and a researcher filling in every box can send 20 or more in one
      sitting, so the limit has to allow that. Each submission saved costs a draft,
      one email to the team mailbox, and up to ten seconds of DOI lookups in a
      scheduled event.
- [ ] **Check the survey form's background work on each site** before its form opens:
      that WP-Cron runs (WordPress's own, or a system cron if `DISABLE_WP_CRON` is
      set), or a draft's DOIs are never looked up; that the server can reach doi.org,
      api.crossref.org and data.crosscite.org; that the site's mail reaches
      gsl.firehouse@noaa.gov; and, on a local copy or the test site, whether the team
      wants its test submissions emailed (the `firehouse_survey_notify` filter, set to
      "" in a must-use plugin, stops them). Also confirm the site's address doesn't
      come from the request's Host header, since the email's links use it. Tools →
      Site Health's FireHouse checks cover the hosts, the test site's search-engine
      setting (with `WP_ENVIRONMENT_TYPE` set to `staging` there, so the check knows
      it's not production) and whether a cache keeps FireHouse's pages; WordPress's
      own checks there cover WP-Cron.
- [ ] **Give a site Analyze results' key only once the service is approved for it**
      (plan open question 7). Until then it has none, and its synthesis screen offers
      only the paste. The key is the site's `wp-config.php` constants
      `FIREHOUSE_WORKERS_AI_ACCOUNT` and `FIREHOUSE_WORKERS_AI_TOKEN`: a Cloudflare
      account ID, and a token made from Cloudflare's "Workers AI" template (Workers AI
      Read and Edit, on that account only), with an expiry date. Never put either in
      the database, the repository or a chat; if one is exposed, roll the token in
      Cloudflare's dashboard. Any other plugin on the site can see the token in
      WordPress's HTTP hooks (`http_request_args`, `http_api_debug`), as it can any
      request's headers, so its narrow scope is what limits the harm: GSL's security
      review should know. Set a spending alert on the account too: an editor can start
      run after run, each sending every published project. A site with the key also
      needs WP-Cron running and api.cloudflare.com reachable from its server.
- [ ] **Check that deleting a transient reports whether it deleted one** on each
      WordPress site the plugin runs on, with whatever object cache it has. The
      synthesis screen's Mark reviewed relies on it to publish a check once
      (`Synthesis_Screen::publish()`). On each site,
      `wp eval 'set_transient( "fh_check", 1, 60 ); var_dump( delete_transient( "fh_check" ), delete_transient( "fh_check" ) );'`
      must print `bool(true) bool(false)`. If the second is true too, two clicks at once
      could both publish: hold the release.
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
      in the page. A portrait uploaded later shows in the team card's portrait picker
      once an administrator attaches it to the About page (Media → Library, list
      view, Attach; `Blocks::portraits()`),
      and an editor chooses it there; a card with no portrait shows the person's
      initials. After importing, open `/firehouse/about/` and check that each
      photo sits under the right name.
- [ ] **Run an audit with a real screen reader**, and check the remaining federal
      requirements the brief flags as unconfirmed (USWDS conventions, privacy
      notice, .gov branding).

## What's not built yet

Named so it's clear these are gaps, not oversights:

- **CMS.** The content layer is ready and two adapters are written, but no Sanity or
  Strapi instance exists and neither adapter has been run against a live backend.
  A matching schema still has to be authored on whichever is chosen.
- **Submission pipeline.** On this React site the Google Form is an outbound link; the
  plugin has its own survey form, off until it's cleared. Nothing yet moves a
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
    `firehouse/topic` block, the project archive the `firehouse/explorer` block, a page
    in the Firehouse tree its own blocks (not through `the_content`), and an empty page
    in the tree a placeholder.
  - **Stylesheets:** it loads `fonts.css`, `firehouse.min.css`, `coverage-map.min.css`
    on a page whose content holds the coverage map's block, `survey.css` on a page whose
    content holds the survey form's block, and `frame.css`, after the
    theme's stylesheet, at the plugin's version and each file's modified time, and adds
    `fh-page` to the body. Under `SCRIPT_DEBUG` it loads `firehouse.css` and
    `coverage-map.css`, the same rules readable, in place of the minified copies. The local WordPress turns
    `SCRIPT_DEBUG` on (`docker-compose.yml`), so pages reviewed there use the readable
    copy; turn it off there to see the minified one. It preloads Public Sans's latin
    file, the text font, so text doesn't shift when it arrives. Nothing depends on the
    fonts, so dequeuing `firehouse-fonts` drops only them, and the preload with them.
    The frame enqueues at priority 20 of `wp_enqueue_scripts`, so a dequeue has to run
    after that: at priority 21 or later of the same hook.
  - **Missing addresses:** at a project's or topic's address under `/firehouse/`, the
    plugin makes its own guess at what was meant, among that type only, so a link cut
    short still finds its project. Any other missing address under `/firehouse/` gets
    the frame's not-found page, not a page elsewhere on the site. Projects and topics
    are left out of the site's own search, and so out of WordPress's guess at a missing
    address anywhere else.

  `assets/css/frame.css`, written by hand, holds what Firehouse needs inside the
  theme's page, and without the site's scripts:
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
  - without the site's scripts, it shows a need card's whole entry until the project
    page's script has measured it, sets the explorer's Update results button level
    with its selects (on a row of its own on a phone), and keeps the links that stand
    in for the site's buttons free of underlines, Clear all at the button's height;
  - it takes the browser's own dialog box off the quick look, a native `<dialog>`
    where the site has an overlay `<div>`, so the site's overlay styles hold, and keeps
    a closed one hidden;
  - it gives each coverage-map swatch the colour `Coverage_Map` wrote for the page's
    theme;
  - without JavaScript, it hides the map's stage (the box the view script draws the
    map into, with the GACC labels toggle) and the credit under it, which mean nothing
    without the map. When there's no map, without JavaScript or because the map's
    module couldn't load, it puts the selected region's projects beside the region
    list, after it, as they come in the page;
  - on Firehouse pages only, it hides the theme's hero background and the mission
    section above its footer.

  Every rule stays inside the wrapper except the last, and
  `scripts/frame-css.test.mjs` holds it to that.

- `Blocks` registers each block named in `Blocks::NAMES`, one folder each in `blocks/`,
  described by its `block.json`.
  - WordPress takes the blocks' metadata from `blocks/blocks-manifest.php`, every
    `block.json` in one generated array, instead of reading each file on every
    request. Don't edit the manifest. After you add, remove or change a block's
    `block.json`, run `npm run plugin:blocks`: until you do, WordPress keeps the old
    metadata. `composer test` (`tests/BlocksTest.php`) fails while the manifest
    differs from the files, or if it holds anything but its header and literals.
    `render.php` and `view.js` need no rerun.
  - Each block renders on the server (`render.php`) and stays out of the inserter: the
    templates, the Import screen or, for the survey form, saving Settings with Accept
    submissions ticked (`Survey::opened()`) place it.
  - An interactive block has a view script module for WordPress's Interactivity API.
    It's plain JavaScript with no build step: WordPress maps its imports, which
    `view.asset.php` declares (`@wordpress/interactivity`; for the explorer,
    `@firehouse/search`; for the coverage map, `@firehouse/coverage-map`, as a dynamic
    import, so WordPress maps it without loading it first), and `tests/BlocksTest.php`
    holds the two to each other. The bar and the project block declare
    `supports.interactivity`, so WordPress processes their directives on the server;
    the explorer and the coverage map don't (`Explorer_Page` says why).
  - In the block editor, each landing and About block is a box with its title and its
    words as text fields in place (`assets/js/block-editor.js`, also plain
    JavaScript): headings, text, button and link text, each count's label and caption,
    each audience card's, step's and team member's words, a paragraph at a time. The
    layout, links, icons and counts stay as they are, shown beside the fields, and a
    button the page doesn't have stays out. The audience cards and the steps keep the
    entries they have; the team's cards can be added, removed and moved, and the
    mission's and each biography's paragraphs added and removed (one left empty prints
    nothing), the focus following each change and screen readers told. Each card's
    portrait is chosen from the FireHouse portraits (`Blocks::portraits()`): the images
    attached to a FireHouse page and those the page's cards already use, each one the
    About page would show. The FireHouse Editor role has no Media Library; an
    administrator adds a portrait by uploading it and attaching it to the About page
    (Media → Library, list view, Attach). A field that can't be empty (a heading, a
    button's or link's text, a count's label or words, an entry's title, a member's
    name) says so; while one is, it's marked, the editor says which in a notice that
    goes to it, and holds the save. The hold is a guide, not a control: the Code
    editor and an autosave don't keep it. The box tells the page's own words from
    what FireHouse adds from its settings, projects and topic areas, and says how to
    move between the page's parts by keyboard. A block that stores nothing and holds
    no others says FireHouse adds it. On a FireHouse page made of nothing but
    FireHouse blocks, whatever attributes they hold, a note's included, the editor
    locks the layout (`Blocks::lock_layout()`, with
    `Page_Words::only_firehouse_blocks()`): no part can be added, moved or removed
    there, so Backspace or Enter on a part's outline changes nothing. A page holding
    another block isn't locked, so the block can be taken out. Nothing else can be
    added to a FireHouse page, and an empty one can be written only with FireHouse
    blocks, in the Code editor or pasted (`Allowed_Blocks`, below).
  - Every block saves its inner blocks and nothing else, so saving from the editor
    keeps the page's blocks: the hero keeps the live counts it holds, and the About
    sections block the four sections it holds. `scripts/block-editor.test.mjs` holds
    each block's save to that. A save that rebuilds the page respaces the block
    comments and leaves out values equal to a block's default, which render the same,
    and keeps an editor's words as typed (`Page_Words`).
  - `tests/BlocksTest.php` holds each folder to that shape, and fails on a folder
    missing from the list, or on any file in `blocks/` but the manifest.
- `Subnav` is the Firehouse bar, `firehouse/subnav`: the site's `SiteHeader` without
  the NOAA and GSL logos and the theme toggle, which the theme's header already has.
  - It has the wordmark, the Settings screen's link labels, and the Submit a Finding
    button, which is left out until a survey address is set or the survey form is
    open and its Submit page published (`Settings::get()`). A survey on another site opens in a new tab and tells
    screen readers so.
  - It marks the current page's link with `aria-current="page"`: Home on the landing
    page, Explore Projects on the explorer and every project, About on the About page
    and any page under it. A topic or a missing address marks none.
  - It's a `div`, not a `header`, since the theme's header is the page's banner. Its two
    navs, the bar's and the drawer's, are both labelled "FireHouse".
  - It stays pinned once the theme's header has scrolled away.
  - Below 1024px its links and Submit button fold into a drawer that the menu button
    opens and closes (`blocks/subnav/view.js`). Escape closes it and returns focus to
    the menu button, and focus leaving the bar closes it. The button is named
    "FireHouse menu" whether open or shut, since the theme's header has a menu button
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
  what the area covers, the Submit button once a survey address is set or the survey
  form is open, and the other three areas. A need that names a project a visitor may not see leaves it out and
  counts only the projects that remain, as the site's normalize step does.
- `Explorer_Page` is the project explorer, the `firehouse/explorer` block: the site's
  `ProjectsPage`, drawn on the server so it works without JavaScript. Every control belongs to one `GET`
  form; the selects stand where the site has them, outside the form element, and join
  it by its ID, and an "Update results" button, which only the plugin has, sends it.
  Clearing the search, removing a filter and clearing them all are links to the
  address without them, where the site has buttons. The form and the links land on
  the page's content (`#fh-content`). It lists the projects a visitor may see, and
  searches, filters, sorts and counts them with `Search` (below), scoring each
  project once. With JavaScript, its view script (`blocks/explorer/view.js`) takes the
  same markup over through the Interactivity API: results as you type, Escape to
  clear, the filters folded behind a button on a phone, the address kept in step,
  and the quick look: each tile's "Quick look" button opens a native `<dialog>`
  previewing the project, as the site's `ProjectDetailModal` does (without
  JavaScript the buttons are hidden). It searches in the browser, with the site's own
  `search.ts` bundled as `assets/search/search.js`, over each public project's fields
  in the page's state (`Explorer_Page::state()`): those the search reads, and what the
  tiles and the quick look show. The block doesn't ask WordPress to process
  its directives: that processing prints text with `esc_html()`, which leaves a
  character reference as it is, so a title stored as "R&amp;D" would show as "R&D"
  (rule 1). The plugin prints the page itself, with templates for the lists the
  script redraws, and marks the region `data-fh-interactive`; the script makes it
  WordPress's only once it has loaded, so a script that fails to load leaves the page
  working as without JavaScript. `tests/ExplorerPageTest.php` and
  `scripts/explorer-view.test.mjs` both pass `shared/cases/explorer.json`.
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
- `Icons` inlines an icon from `assets/icons/icons.php` by a name in the vocabulary,
  never by a path. That file is one generated array, so a request reads at most one
  file for its icons, and none once opcache has compiled it. It replaces the icon's
  24×24 size and marks it decorative. An unknown name gives an empty string, not an
  error.
- `Post_Types` registers `fh_project` and `fh_topic` at the React site's addresses:
  `/firehouse/projects/`, `/firehouse/projects/{slug}/` and `/firehouse/topics/{key}/`
  (no topic archive). Neither is in the REST API. No one adds either in the admin.
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
  use both: the explorer's projects (`Explorer_Page::projects()`), which the coverage
  map reuses, and which both carry in their page state; the landing page's counts and
  need cards (`Landing_Page::content()`), which keep a project the explorer leaves out
  for want of a slug, a title or a year; the project pages' previous and next links;
  the topic pages' links and project total; the not-found guess; and the synthesis
  screen's download, the projects its check accepts and the count it records
  (`Synthesis_Screen`). The topic screen marks a need's project visitors can't see
  with `Store::is_public()`.
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
- `Capabilities` defines the FireHouse Editor role: projects, topics, the FireHouse
  settings and the `firehouse` page tree, and nothing else on the site. The role
  stores `read` and Firehouse's own capabilities; WordPress's page capabilities are
  lent only while the plugin runs, so with the plugin off its users can only sign
  in. Only administrators have `firehouse_import`. A project or topic has no author,
  whoever saves it and by whatever route (the plugin's own writes aside), so no
  editor's name or sign-in name is published as its author through oEmbed, the feeds
  or the users sitemap. A limited editor, one whose page editing is limited to the
  FireHouse pages as a FireHouse Editor's is, can't change a page's parent or slug,
  which are its address: `keep_in_place()` puts them back whatever a save asks, and
  the REST API refuses the change. No one can delete the landing page for good while
  pages outside the trash are under it: WordPress would move them to the top of the
  site, the About page beside the site's own `/about/`. Nor can anyone put it in the
  trash while a published or scheduled page is anywhere under it: with the landing
  page in the trash, such a page would still open under its trashed address
  (`/firehouse__trashed/about/`) as a plain page of the site (decided by Eric, 14
  November). That counts a page below another page already in the trash, or in any
  other registered status, such as an editorial workflow's, since WordPress finds a
  page by its path whatever the status of the pages above it
  (`Capabilities::pages_below()`; Copilot's review, 16 November). For the same reason,
  deleting the landing page for good counts the pages directly under it in any
  registered status but the trash. Drafts, pending and private pages don't count. The
  Pages screens say why.
  For the trash, they and the block editor's Move to trash also name those pages and
  say to make them drafts or trash them first. The block editor's message comes only
  after WordPress's own permission check, so only someone who may delete the landing
  page sees the titles. Anywhere else, such as WP-CLI, the move just fails. The rule
  covers putting the landing page in the trash, not what happens after: a draft under
  a landing page already in the trash can still be published.
- `Installer` stores the role and the administrators' capabilities and has the
  rewrite rules rebuilt, on activation and after `Installer::REVISION` changes.
- `Settings` is FireHouse → Settings: the site name, the Submit a Finding label and
  address, the survey form's Accept submissions switch (administrators only,
  `Settings::OPEN_SURVEY`), the three navigation labels, each survey question's
  wording and help (stored only where they differ from `Questions::all()`), and,
  while the site has a Workers AI key, the synthesis prompt (stored only where it
  differs from `Analysis::own_prompt()`; cleared, it's the plugin's own). The pages
  read `Settings::get()`, which while the form is open gives the Submit page's address
  as the survey address; `Settings::stored()` is what's saved.
- `Edit_Screen` is what the project and topic edit screens share. Each adds a box of
  the team's fields to WordPress's own edit screen, saved whenever the screen saves
  the post (Save Draft, Publish or Update), through `Store::update_fields()`, kept as typed (line endings aside). A save
  checks every field first (the screen's `check()`, with `Meta`'s schema check) and
  saves none if any is wrong; the input and problems wait in a transient for the
  screen WordPress redirects to, which marks each field and lists them at the top.
  WordPress has saved the post's own fields by then. Only the screen's own form saves
  the fields, besides a restore of a revision, which puts back the ones it holds
  (below): not Quick Edit, an autosave, or the plugin's writes. A request with the
  screen's nonce but without its fields, or with one that isn't text, saves none. A
  good save clears any refusal still waiting. Text that isn't valid UTF-8 is refused
  too (`texts()`), since the database would refuse it after the other fields were
  written. The screen's nonce is the post's own,
  refreshed with WordPress's through the heartbeat (`refresh_nonce()`), as after
  signing in again in the session-expired dialog; if it has gone stale anyway,
  WordPress's save of the post still goes through, and the screen says the fields
  weren't saved and shows what was typed.
- `Project_Screen` is a project's, in two boxes, and a third for a survey form
  submission. **Editorial fields** (the summary,
  publication status, geography note and full-record link, which is trimmed) are the
  team's, never touched by a re-import (rule 4). The geography is written only when
  its note changes, so a save never stands or falls on the survey's regions. **From
  the survey** shows the researcher's answers read-only, as stored. **Private
  answers**, on a form submission that answered any of the three optional private
  questions, shows the submitter's email, job title and how
  they shared their results, to FireHouse Editors and administrators. WordPress has
  saved the project's status by the time a save is refused.
- `Topic_Screen` is a topic's, in two boxes under its label, WordPress's title box.
  **Topic fields** are the team's words for the area: its short label, which can't be
  blank (spaces and invisible characters such as a zero-width space count as blank:
  `blank()`), its description, and what it covers, one item a line (each trimmed,
  blank lines dropped). **Synthesis of needs** shows the synthesis read-only: who reviewed
  it (or that the topic page calls it a draft), its date, what made it, and how many
  projects the site showed when it was marked reviewed. Below that come its needs,
  each with its project count and its projects by title, linked to their edit screens
  and marked when visitors can't see one. A new synthesis replaces it, on FireHouse →
  Synthesis (`Synthesis_Screen`); restoring each topic's earlier revision is the
  undo, and puts back all of that topic's fields, its label and words included (Eric,
  16 November). The label is kept escaped, as `Store::label()` writes it, by
  any route that saves it (`Topic_Screen::keep_label()`, on `wp_insert_post_data`,
  late). It's read as it was sent, before kses, which would strip anything that looks
  like a tag from a FireHouse Editor's save; the control characters kses would drop
  are dropped. An unchanged label is kept exactly as stored. One that's blank, judged
  on what the topic page prints, or isn't valid UTF-8 keeps the label the topic had,
  and the screen refuses its fields with it and says why, under the title box. The
  title box shows the label as text (`edit_label()`) and is named "Label". The
  topic's introduction, which no page shows, and its order aren't on the screen, and
  no one can change its key or status (`Capabilities`). Under Preview Changes a note
  says the label shows as typed and the other words as last updated, since a preview
  is an autosave (`preview_note()`). The topics list has no Quick Edit, bulk Edit or
  checkboxes (`row_actions()`, `bulk_actions()`, `columns()`). Hiding them is a guide,
  not a control: a Quick Edit or bulk Edit request sent without the screen is still
  saved, with the topic's slug and status kept (`Capabilities::keep_in_place()`) and
  its label checked (`keep_label()`).
- `Project_Rules` holds three rules on every save of a project through WordPress's
  editing routes (the edit screen, Quick Edit, bulk edit, a restore, XML-RPC), on
  `wp_insert_post_data`, late, so another plugin's filter can't undo them. The
  plugin's own writes through `Store` are left alone: the importer and the survey form
  set a project's status themselves.
  - A project without IRB approval isn't published, scheduled or made private: it stays
    a draft or pending, or becomes a draft if it was public, with its dates and the
    slug it asked for. Its edit screen says why at the top and in the Publish box,
    which hides the Publish button and the visibility and date settings; Quick Edit
    refuses with the reason in its own panel, which screen readers hear; and the
    projects list marks it "No IRB approval".
  - A project's slug, its address, is fixed once it has been published, which
    `Project_Rules::mark_published()` records (`_firehouse_published`) the first time it
    enters or leaves a published status, by any route; a date set on a draft doesn't
    count. Trashing and restoring, which rename a slug for WordPress's own reasons, are
    left alone. A draft can't take an address another project holds: it gets the next
    free one. If another published project holds this one's address, as when one took
    it while this one was in the trash, this one gets the next free one, never one a
    request names, rather than two sharing it. The Publish box shows the fixed
    address in place of the Slug box.
  - The post's own title is `Store::label()` of the researcher's (escaped and
    trimmed, as WordPress stores titles), so a restore or a forged Quick Edit can't
    relabel a project in WordPress's lists.

  A refusal from bulk edit or a forged request is said on the next projects list or
  project screen the editor opens, within an hour. `Project_Rules::preview_note()` (on
  `post_submitbox_minor_actions`) says under the Preview button what it shows: for a
  draft or pending project, Preview shows it as last saved; once published, Preview
  Changes shows it as last updated; when scheduled or private, Preview shows it as last
  updated. No editor is a project's author, so a preview is an autosave, which takes no
  field from the screen.
- `Seed_Bundle` reads a seed bundle and checks it; `Importer` plans the import and
  writes it through `Store`; `Import_Screen` is FireHouse → Import, for
  administrators. `npm run seed` writes the bundle from `src/content/data/` to
  `seed/firehouse-seed.json`, which git ignores; upload it on the screen. The screen
  shows a dry run of every change and problem, writes nothing while there is a
  problem, and imports once confirmed. A site imported before 13 November keeps "The
  Firehouse" in its saved settings and its landing and About pages, since the import
  writes neither again: set the name under FireHouse → Settings, and import onto a
  clean site, or change those pages' words in the block editor.
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
    FireHouse", as the site's is, so it isn't a second "About" beside the site's own.
  - Each post and page is read back after it is written.
- `Landing_Page` is the landing page's blocks: `firehouse/hero` with `firehouse/stats`
  inside it, `firehouse/top-needs`, `firehouse/coverage-map` and
  `firehouse/submit-band`, as the site's `LandingPage` renders them. The words are the
  blocks' attributes, except the coverage map's, which are fixed in `Coverage_Map` as
  they are in the site's `LandingPage` and `RegionMap`; the counts, the four topic
  cards, the synthesis note and the map's region counts are computed as the page
  renders, from the projects a visitor may see and the published topics, with the
  topic pages' counting. A button with nowhere to go, such as Submit
  a Finding with no survey address set, is left out.
- `Coverage_Map` is the coverage map's block, as the site's `RegionMap` renders it: the
  map, how many projects are filed under each GACC (a national study counts in every
  one) and under the four answers with no boundary, and the selected region's
  projects, under an `h3` that counts them, ending in a visually hidden
  `role="status"` line.
  - Without JavaScript, the region list is the map: each region is a button that
    submits a GET form for `?region=` and its key, the address the static site uses,
    and the page comes back at that region's projects. The selected region's button,
    "All submissions" and "Clear" submit nothing, which selects none, and the page
    comes back at the section's heading, by the list.
  - With JavaScript, its view script (`blocks/coverage-map/view.js`) takes the same
    markup over through the Interactivity API, as the explorer's does. A choice
    changes the page in place, and the address with it; the status line says "4
    projects in Great Basin", from the heading's own words; and Clear moves focus to
    "All submissions". The map is the site's own drawing (`assets/map/`, below), which
    the script imports once the page is up and which follows the selection, the GACC
    labels toggle and GSL's light/dark switch. The toggle shows once the map is drawn.
    If the map's module can't load, the stage and credit go and the list works as
    before. If the view script itself doesn't load, the list works as it does without
    JavaScript, but the stage stays on the page, empty: `frame.css` can tell only
    whether scripting is on.
  - So the server prints every part a choice can show (the hint, the heading, the list
    and the empty message), each hidden unless the selection shows it, and a template
    the script draws the list from. Each project it prints has the template's shape,
    its National tag printed hidden where it doesn't apply, since WordPress takes them
    over element by element. It also hands the script its starting state
    (`Coverage_Map::state()`): the selection, the projects, each region's count and
    name, the tiles' host, and the translated words, singular and plural, so the script
    holds no English of its own. `tests/CoverageMapTest.php` and
    `scripts/coverage-map-view.test.mjs` both pass `shared/cases/coverage-map-view.json`:
    the heading split around the region's name, and the map's tooltip line.
  - Each GACC with something filed under it has a swatch carrying its colour for both
    themes, from `Map_Colors`, the site's `heat.ts` ported and held to it by
    `shared/cases/coverage-map.json`, and `frame.css` shows the theme's.

  A landing page imported before the block existed doesn't have it: add
  `<!-- wp:firehouse/coverage-map /-->` before its submit band.
- `About_Page` is the About page's blocks, as the site's `AboutPage` renders them:
  `firehouse/about-intro`, `firehouse/about-sections` holding `about-mission`,
  `about-audiences`, `about-process` and `about-team`, and `firehouse/about-cta`. The
  words are the blocks' attributes, the biographies printed as written. Each member's
  portrait is the image kept by its ID, the import's or the one an editor chose in the
  team card (`photoId`), shown only where `About_Page::shows()` allows it, printed by
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
- `Page_Words` keeps the words an editor types into the FireHouse pages' blocks as
  typed. A FireHouse Editor may not post unfiltered HTML, so WordPress runs kses over
  every attribute string of a page they save, turning "R&D" into "R&amp;D", which the
  page shows as written, and dropping "<5 km>". When a page in the FireHouse tree, or
  a revision of one, is saved with content that's nothing but FireHouse blocks, each
  holding only the attributes its block.json declares (`Blocks::attributes()`), with
  only spaces between and inside them, the content is kept as sent, before any of
  WordPress's filters on it (`Page_Words::keep_as_typed()`, on `wp_insert_post_data`,
  late), and stored as WordPress's serializer writes it. Every FireHouse block prints
  its words escaped and checks its links, and saves no markup of its own. Anything
  else keeps WordPress's filters: a core block, markup anywhere among them, an
  undeclared attribute such as a block's custom CSS (`style`), whose strip for those
  who may not edit CSS is one of those filters, or a page being moved. The blocks also
  turn off custom CSS, class names, locking and renaming in their block.json. A
  WordPress feature that adds `metadata` to a block, such as a note on it, sends the
  page's words back through kses until it's gone.
- `Allowed_Blocks` keeps the FireHouse pages to their FireHouse blocks: no other block
  (core, another plugin's, Custom HTML), and nothing but spaces between them (decided
  by Eric, 14 November), for everyone, administrators too. The block editor offers
  only FireHouse blocks on a page in the tree (`allowed_block_types_all`), and none of
  them is in the inserter, so it offers nothing to add. A save that would leave a page
  in the tree holding anything else is refused, whether the page is there already, is
  created under it, or is moved into it. The check judges the page by the parent it
  will have after the save: a limited editor's page keeps its old one, whatever the
  save asks, because `keep_in_place()` puts it back after the check has run, so the
  check uses the same answer (`Capabilities::parent_kept()`). A page moved in brings
  the pages under it without a save of their own, so the move is refused while any of
  them, at any depth, holds something else ("This page wasn’t moved…"). The message
  names the first five, a title's `<` and `>` as ‹ and › (the block editor leaves out
  a save's message that looks like markup), and counts the rest and any its user may
  not open (`read_post`). That walk (`Capabilities::pages_below()`) goes through every
  registered status, the trash included, where `tree_ids()` stops: restoring a trashed page brings it back as it is, and a
  published page under one still opens at the trashed page's new address.
  Through the REST API, which the block editor saves with, the editor shows why ("This
  page wasn’t saved: a FireHouse page holds only its FireHouse blocks…"). On any other
  route the backstop on `wp_insert_post_empty_content` refuses it. WordPress reports
  that as "Content, title, and excerpt are empty." to a caller that asks for errors,
  such as WP-CLI; Quick Edit reports nothing, and the row keeps its old parent.
  - A save that leaves a page in the tree with the content it holds goes through, so
    a page that already holds another block can still be published, unpublished,
    trashed or restored. That's recognized after WordPress's own filters too (kses,
    for someone who may not post unfiltered HTML). Any other change to such a page is
    refused until a save takes every other block out, which goes through like any
    save of FireHouse blocks alone. Empty content saves too.
  - An autosave WordPress keeps as a revision is left alone; a draft's autosave by its
    own author saves the page itself and is checked like any save. Restoring a
    revision is checked like any save, so the revisions screen turns off Restore for
    one holding another block (`wp_prepare_revision_for_js`), rather than let
    WordPress say it restored a revision it didn't.
  - While the landing page is in the trash there's no tree, so nothing is checked
    until it's restored.
- `Synthesis_Screen` is FireHouse → Synthesis, for whoever may publish a topic
  (`publish_fh_topics`: the FireHouse Editors and administrators).
  - **Download NotebookLM source** (the admin-post action `firehouse_notebook_source`,
    with a nonce) sends `firehouse-findings.md`, which `Notebook_Source` builds from
    the projects a visitor may see and the published topics.
  - A synthesis pasted below it (200 KB at most; a Markdown code fence around it is
    fine) is checked by `Synthesis`, which lists what's wrong, 50 problems at most,
    and writes nothing. A check with none waits at most thirty minutes for the person
    who made it, under its own token, in place of a stored draft (Eric, 16 November).
    Opening the screen afresh, or checking again, drops it.
  - **Mark reviewed and publish** claims that check by deleting its transient, so it
    publishes once, checks it again against the projects as they are, then writes
    each topic's `topNeeds`, `updatedAt` (the site's date), `sourceCount` (how many
    projects the site shows then), `model` (Made with) and `reviewedBy` through
    `Store`. Each topic keeps a revision, whose author is whoever was signed in. If that
    second check differs, nothing is written and the screen shows the new check. An
    area with no topic post is skipped without a problem, which only an administrator
    can cause, before an import. Reviewed by and Made with go through
    `sanitize_text_field()`; one that's blank, holds a control or format character
    (`Synthesis::hidden()`), or runs past 200 characters is sent back to fix before
    the check is claimed.
  - Any FireHouse Editor may mark a synthesis reviewed; restoring each topic's earlier
    revision is the undo, one topic at a time, and puts back all of that topic's
    fields. The comparison against the published synthesis and the
    re-sort when counts change come after launch (Eric, 16 November).
  - **Analyze results** shows only while `Workers_Ai::configured()`. It starts a run
    with the model picked (`Analysis::start()`), then returns to the screen, so a
    reload doesn't start another, and shows the run going or each model's latest
    draft: when, by whom, from how many projects, its whole reply and the prompt it
    was given. **Check** puts a draft's JSON through the same check as a paste, with
    "<model>, on Cloudflare Workers AI" offered for Made with, and the model's whole
    reply shown beside the needs, read from what the site keeps. The paste and review
    forms carry which draft it is (its model and when it was started) through the
    checks after the JSON is edited; a draft replaced since says so. A draft that read
    a different number of projects from the number the site shows says so too, since
    the topic pages give the number shown when it's marked reviewed.
- `Workers_Ai` asks a model on Cloudflare Workers AI (Eric, 16 November: try it first;
  NOAA's AI policy still decides, plan open question 7). The account ID and token are
  the constants `FIREHOUSE_WORKERS_AI_ACCOUNT` and `FIREHOUSE_WORKERS_AI_TOKEN` in
  `wp-config.php`: never in the database, a screen, a message or a log, and refused
  unless shaped like Cloudflare's (32 hex digits; letters, digits, `-` and `_`), so
  neither can add to the address or the header. The models are a fixed list, all from
  US companies (Eric, 16 November): Meta's Llama 4 Scout, OpenAI's gpt-oss-120b and
  Google's Gemma 4 26B, each reading 128,000 tokens or more. NVIDIA's Nemotron 3 120B
  is left out: Cloudflare retires it on 20 November 2026. Gemma is asked not to think
  (`chat_template_kwargs`), since its thinking used up its length limit. One POST to
  `api.cloudflare.com/client/v4/accounts/{id}/ai/run/{model}` with `messages`, the
  model's `max_tokens` and any fields its entry adds, no redirects, five minutes at
  most; the reply's text is read from a `response`, a chat completion's first choice,
  or a Responses API output, since the models answer in different shapes. An error says
  Cloudflare's reason with the key taken out; a timeout (HTTP 408) first says in plain
  words that the model may be busy.
- `Analysis` is Analyze results. A run is a WP-Cron event (`firehouse_analyze`) that
  carries the run's ID, so it runs that run and no other, one at a time; the option
  `firehouse_analysis` holds the run going and each model's latest result. Every
  change to it (starting a run, claiming it, recording it, failing a stale one) is
  made under the database's named lock (`GET_LOCK`), reading the database afresh, so
  of two requests at once the second sees the first's write; `add_option()` can't be
  that lock, since it overwrites a row another request has just added. A run whose
  event hasn't fired within half an hour, or whose request has gone five minutes past
  its own five, is recorded as failed, and a late one never overwrites a newer run or
  draft. A source in which a topic area or a project holds a character that doesn't
  show (`Submission::HIDDEN`) isn't sent: the project is named, to be corrected from
  the survey record. The run sends the
  published projects' NotebookLM source text (`Notebook_Source::parts()`), each
  project between `<project number="N" key="…">` markers with a random key no
  project's text holds, under fixed instructions that only those markers count and
  that nothing in the source is an instruction; then the prompt, which is the one
  changed on Settings or the plugin's own (`shared/synthesis-prompt.txt`, which a test
  keeps equal to `notebooklm/README.md`'s). No earlier synthesis is sent. The reply's
  JSON is the last fenced block holding a list of topic areas, or else the first
  stretch from a `[{` to a later `]`, the furthest first, that reads as one, past any
  `<think>` block, so a citation such as `[7]` isn't taken for it; a reply without
  one, or cut off, is kept as failed, with the reply to read. A reply holding a
  character that doesn't show is marked, and the screen says so above it.
- `Site_Health` adds three checks to Tools → Site Health, badged FireHouse:
  - **Outside services** (async): a HEAD request to the front page of doi.org,
    api.crossref.org and data.crosscite.org (`Doi_Lookup::HOSTS`), and
    api.cloudflare.com while the site has a Workers AI key, with no key sent; any
    answer counts, and a host that can't be reached is named.
  - **Search engines** (direct): production should let them in, and any other
    `wp_get_environment_type()` keep them out (`blog_public`), so WordPress marks its
    pages noindex.
  - **Page caching** (async): the server loads its own FireHouse landing page and
    reads `cf-cache-status`. A status of a kept page (HIT, MISS, EXPIRED…) is
    critical, since a hidden project would stay on the pages that showed it and the
    plugin doesn't purge; `Age` above 0 or an `X-Cache` HIT is a recommendation; none
    says how to check from outside, since the server's own request may not pass
    through Cloudflare.
  - The async checks answer Site Health's script (`wp_ajax_health-check-firehouse-…`)
    with its nonce, for `view_site_health_checks`, and skip WordPress's weekly check
    (`skip_cron`), so no request is made unless someone is looking.
- `Head` gives each Firehouse page its title, the site's title for it then GSL's
  suffix ("About — The FireHouse – Global Systems Laboratory"; decided by Eric, 12
  November, with Stephanie and Emily's spelling), and the description and Open Graph and
  Twitter tags a shared link's preview is built from: the site's words from
  `index.html`, or on a project's page its abstract as the researcher wrote it. It
  changes nothing on any other page, and leaves everything to an SEO plugin if one is
  active, or when the `firehouse_head_tags` filter returns false.

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
- **Revisions hold every field; a restore puts back only the team's.** A re-import that
  changes a researcher's words leaves the old ones in a revision. Restoring a revision
  puts back only a project's summary, publication status and full-record link
  (`Meta::restored()`, applied by `Meta::revision_keys()` while WordPress restores),
  never a survey field, IRB approval included; a topic's restore puts back all its
  fields. An autosave takes nothing from the request (`Meta::revision_keys()`):
  WordPress would copy any revisioned field sent with it into the autosave, for a
  restore to write. A new autosave still copies the post's own fields, as any revision
  does. No one may change, add or delete a FireHouse field through WordPress's
  custom-field routes, in any case, on a project, a topic or a revision of either
  (`Capabilities::map_meta_cap()`): the fields' own refusal covers only the exact key
  on the post itself, and the database compares keys ignoring case.
- **The plugin checks the bundle itself.** It refuses fields it doesn't store in a
  project, topic or synthesis (the landing and About pages keep only the text their
  blocks use, and drop the rest), and anything shaped like an email address but a team
  work address in the About page's team mailbox field, by the same pattern and field list as the Node scripts
  (`shared/email-shape.json`).
- `uninstall.php` removes the role, so users who had it are left with no role on the
  site, and the capabilities, and Analyze results' drafts and any run waiting; the
  content stays.

`includes/class-help.php`, `Help`, shows the FireHouse guide: FireHouse → Help, every
page the user may read with its screenshots, and on each FireHouse screen
(`Help::SCREENS`) a Help tab with that screen's pages. The guide's Markdown pages in
`help/` are the source; `npm run plugin:help` builds `help/help.json` and
`help/README.md` from them, and CI fails if they fall behind (`plugin:help:check`).
Pages for administrators show only to those who may import. When a screen changes, its
page changes in the same pull request (CLAUDE.md), and its screenshot is taken again
with `node scripts/help-screenshots.mjs`, which crops each to its subject and hides
the toolbar, other plugins' notices and anything private.

`survey/` is the survey form, off until an administrator ticks Accept submissions
(WORDPRESS-PLAN.md, Phase 7a):

- `Questions` holds the questions in the Google Form's order and wording, with the
  site's own choices for fire phases and regions; `Questions::worded()` gives them as
  reworded under FireHouse → Settings. Each private question's line saying who sees
  the answer is fixed here.
- `Form` draws the `firehouse/survey-form` block on the Submit page
  (`/firehouse/submit/`): the notice, every question with its label, help and
  problem tied to it, one box per entry added and removed without JavaScript, a
  summary of what's wrong that takes the focus, and the thanks.
- `Submission` reads a sent form into the fields an import of the same answers would
  write, and lists what's wrong: required answers, counts, lengths, an email address
  in a public answer, a character that doesn't show.
- `Survey` handles the POST, only at the page's own address and from the site's own
  pages, with a nonce, a field people don't see, and a fill time of at least the
  minimum and at most a day; saves the draft (response ID `W_…`, no author), with the
  email, job title and sharing answer apart in protected meta (`_firehouse_private`)
  left out of WordPress's export; and emails the team mailbox fixed text with a link
  to the draft (`firehouse_survey_notify` changes the address, or with "" sends
  none). A submission without IRB approval is saved too, as a draft `Project_Rules`
  won't publish, and gets the Google Form's no-IRB thanks; its DOIs aren't looked up.
- `Doi_Lookup` looks up each DOI's title, journal and year from doi.org, Crossref and
  DataCite only, in a WP-Cron event just after the save, and stores them only while the
  submission is still a draft.

`domain/` holds the PHP versions of `src/content/derive.ts`, `format.ts`,
`normalize.ts` and `search.ts`, of `src/design-system/heat.ts` (`Map_Colors`) and
`scripts/notebooklm-source.mjs` (`Notebook_Source`), and the synthesis screen's
checks (`Synthesis`):

- `Normalize::site_content()` checks the raw content files and returns them in the
  JSON shape of `src/content/types.ts`. When it refuses content, it throws a
  `Content_Error` whose message starts `[content] ` and is already escaped for HTML.
- `Derive` expects that normalized content; `Format` works on one field at a time: a
  date, a byline's authors, a need, a link.
- `Email_Shape` finds text shaped like an email address where none belongs, the
  server's backstop for rule 2, by the pattern in `shared/email-shape.json`, which the
  survey import and the seed bundle script use too.
- `Search` is the explorer's search, filters, sort and counts. An address such as
  `/firehouse/projects/?q=smoke&region=NWCC` opens already filtered, by the same
  rules as the React explorer (both pass `shared/cases/search.json`; see the intl
  caveats below). `Search::params()` reads a query string as the browser's
  `URLSearchParams` does. The explorer gives it the query part of
  `wp_unslash( $_SERVER['REQUEST_URI'] )`, the address as the browser sent it
  (`Explorer_Page::query_string()`). Don't pass `QUERY_STRING`, which a server's
  rewrite rules can change, or `$_GET`, or anything through `sanitize_text_field()`,
  which deletes percent-escapes. Only a search's first 20 words count, in both
  languages, so one long address can't tie up the server. `Search::scores()` scores
  each project once, for a page that both lists and counts.
- Two things match the browser only when the server has PHP's intl extension, and the
  plugin works either way. Folding (lower-casing and dropping accents, so
  "Évacuation" matches "evacuation"): without intl, `Search::fold_without_intl()`
  drops accents from Latin letters by a table that `scripts/search-fold.test.mjs`
  checks against the browser's folding, but keeps them on other alphabets' letters,
  such as Greek ά or Cyrillic й, so a search can find or rank those differently. And
  title order (`Derive::compare_titles()`, which also breaks ties in every sort):
  without intl, titles with punctuation, digits or accented letters can sort
  differently from the browser.
- `Vocabulary` reads the keys and icon names from `shared/vocabulary.json`, and `Js`
  holds the few JavaScript rules PHP doesn't share, such as what counts as true, as
  whitespace, or as a number, and how `Math.round()` takes a tie.
- `Map_Colors` is the coverage map's colours, from the two ramps in
  `shared/coverage-map.json`, which the site's `src/design-system/heat.ts` reads too,
  and, from the same file, the basemap's attribution for the credit under the map and
  the tiles' host, which the view script connects to before the map loads.
- `Notebook_Source::build()` writes the NotebookLM source from the projects and
  topics, byte for byte as `scripts/notebooklm-source.mjs` does: both pass
  `shared/cases/notebooklm-source.json`, whose `expected` is generated from the script.
  `parts()` gives its introduction and each project's section apart, for `Analysis`.
- `Synthesis::check()` reads the JSON the synthesis prompt gives and lists what's
  wrong: its shape (the four areas, each once, up to five needs each, an empty list
  allowed); a project, named by page slug or response ID, that the site doesn't show
  or that a need names twice; a count that isn't the number of projects named; an
  area not ordered by count, highest first; and text that shows nothing or holds a
  control or format character. It lists 50 problems at most and counts the rest.
- `Json::decode()` reads JSON as the TypeScript sees it: an empty or numbered object
  (`{}`, `{"0": …}`) stays an object, a `Json_Object`, instead of passing for a list.
  `Normalize` turns every one back into an array on the way out, so pages and post meta
  only see arrays. The tests read the shared cases through it, and the Import screen
  reads the seed bundle through it.

They pass the cases in `shared/cases/derive.json`, `format.json`, `normalize.json`,
`email-shape.json`, `search.json`, `coverage-map.json` and `notebooklm-source.json`,
the same ones `npm test` runs.

`assets/css/firehouse.min.css` is the site's CSS, scoped under `.fh-app` so it can sit
inside GSL's theme without reaching the theme's header and footer (plan rule 9).
`firehouse.css` beside it holds the same rules, readable, for review and for
`SCRIPT_DEBUG`; the minified copy is that one minified as the site's own build
minifies its CSS. One stylesheet serves every Firehouse page; it isn't split by page.
Both copies leave out what GSL's theme replaces (the static build's page shell, gov
banner and footer), the coverage map's CSS, the design system's components no page
renders, and the fonts, which load on their own (`scripts/plugin-css-sources.mjs`); a
test fails if the plugin's markup uses a class only the unused components' stylesheets
style. The coverage map's CSS, Leaflet's and the site's `RegionMap.css`, is a chunk of
its own in the site's build (`DEFERRED`), and so it is in the plugin, less three of
Leaflet's images for parts the map doesn't draw:
`coverage-map.min.css`, or under `SCRIPT_DEBUG` the readable `coverage-map.css`,
which loads only on a page whose content holds the map's block, after the site's
stylesheet and before `frame.css`. Don't edit any of them: `npm run plugin:css`
writes all four from `src/`, and CI fails if one falls behind.
The site's own build scopes every stylesheet the same way (`scripts/scope-css.ts`), with
`#root` as the wrapper, so the static site runs on the same CSS the plugin gets, and
the build stops on any rule that could match outside the wrapper. Before writing,
`plugin:css` also loads all four files into a real browser (`scripts/css-browser-check.mjs`)
and stops on any request, unscoped rule or loading value it finds there, since
PostCSS and a browser tokenize some CSS differently.
`src/styles/inward-reset.css` undoes GSL's element styles inside the wrapper, and
`src/styles/page-shell.css`, which styles the static build's own `<html>` and
`<body>`, is the one stylesheet left unscoped. The plugin loads the site's CSS on
Firehouse pages, through `Frame` (above).

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

`assets/icons/icons.php` holds every icon in the site's registry (`ICON_NAMES` in
`src/design-system/Icon.tsx`), drawn by the site's own `Icon` component, as one PHP
array of SVG by name. `assets/logos/` holds the images `BrandMark` imports. Don't edit
either: `npm run plugin:assets` writes both from `src/`, and the link-preview image
from `public/og-image.png`, and CI fails if they fall behind. An icon keeps the drawing
exactly as the site renders it, at Lucide's 24×24, and leaves off what belongs to one
use (class, inline style, `aria-hidden`, `focusable`). Where the plugin inlines an icon
it adds those and replaces the size, as `Icon` does. Every icon may hold only the plain
shapes and drawing attributes `shared/icon-svg.json` lists: the script refuses anything
else, and `tests/IconsTest.php` checks the file that ships, which may hold nothing but
its header and string literals, since the plugin runs it. Lucide's licence sits beside
it as `LICENSE.txt`. The NOAA and GSL logos stay with the static build, since GSL's
header carries its own.

`assets/search/search.js` is the site's search (`src/content/search.ts`, with
`effectiveRegions()` from `derive.ts` and the vocabulary they read), bundled by esbuild
as one ES module and left unminified so a review can read it. The explorer's view
script imports it as `@firehouse/search`, which `Blocks::register()` registers. Don't
edit it: `npm run plugin:assets` writes it, and CI fails if it falls behind.

`assets/map/map.min.js` is the site's map drawing (`src/design-system/mapCanvas.ts`,
with `heat.ts`, the vocabulary, the GACC boundaries, and the colours and tile addresses
from `shared/coverage-map.json`) and Leaflet, bundled by esbuild as one ES module, about
60 KB compressed; `map.js` beside it is the same, unminified, for review and
`SCRIPT_DEBUG`. The coverage map's view script imports it as `@firehouse/coverage-map`
once the page is up (`Blocks::register()` registers it, and `view.asset.php` declares
it a dynamic import, so it never holds the page up). Leaflet sets `window.L` as it
loads; the module puts back whatever was there, so another plugin's Leaflet on the page
is left alone. Leaflet's licence sits beside it as `LICENSE.txt`. Don't edit them:
`npm run plugin:assets` writes them, and CI fails if they fall behind. Leaflet is
pinned to one version in `package.json`, so `npm ci`, then `npm run
plugin:assets:check`, shows a reviewer that the committed bundle is what the script
makes. Leaflet's CSS isn't in them: it's in `coverage-map.min.css`, above.

The local WordPress runs in Docker and borrows GSL's theme, WordPress images and
database from a checkout of GSL's internal gsl-wordpress repository beside this one
(`../wordpress-gsl`, or set `GSL_WORDPRESS_DIR`). GSL's database has to be running for
the first step.

```bash
bin/wp-init         # once: copy GSL's local database, activate the plugin
                    # then http://localhost:8090, with your GSL local login
bin/wp plugin list  # WP-CLI against that site
bin/wp plugin install plugin-check --activate   # once
bin/wp plugin check firehouse --exclude-checks=plugin_updater
                    # WordPress's Plugin Check, less the check that flags the
                    # Update URI header (wordpress-org/README.md)
npm run seed        # then upload seed/firehouse-seed.json at FireHouse → Import
composer install
composer lint       # PHPCS: WordPress coding standards, PHP 8.1 compatibility
composer test       # PHPUnit
```

Analyze results needs a Workers AI key. For the local copy, put a Cloudflare account ID
and a token made from Cloudflare's "Workers AI" template in `.env` as
`FIREHOUSE_WORKERS_AI_ACCOUNT` and `FIREHOUSE_WORKERS_AI_TOKEN` (`.env.example` has the
lines), typed there yourself and never into a chat, an issue or a commit, then run
`docker compose up -d`. `docker-compose.yml` passes them to `wp-config.php`'s
constants. Without them, the synthesis screen offers only the paste.

WP-Cron starts scheduled events (DOI lookups, Analyze results) by calling the site's
own address, `localhost:8090`, which reaches nothing inside the container, where
Apache listens on port 80. `docker-compose.yml` sends that call to port 80, still
asking for `localhost:8090`, through a `cron_request` filter set in `wp-config.php`,
so they run locally as on GSL's sites; after changing it, `docker compose up -d`. To
run a waiting event at once: `bin/wp cron event run firehouse_analyze` (from bash,
since `bin/wp` is a bash script). The filter reroutes only WP-Cron's call, so on the
local copy Site Health's FireHouse page-caching check, and WordPress's own loopback
check, still say they couldn't load the site from its own address: that's the port,
not a fault.

CI runs all three on every change to the plugin: PHPCS and PHPUnit on PHP 8.1, and
Plugin Check in a throwaway WordPress (`.github/workflows/wordpress-plugin.yml`), on
what the plugin's zip holds.

The local WordPress listens only on this computer and is never exposed beyond it,
because Firehouse isn't hosted outside GSL, even for a demo.

### The plugin's zip

`npm run plugin:zip` (`scripts/plugin-zip.mjs`) builds the zip WordPress installs at
Plugins → Add New Plugin → Upload Plugin: `wp-content/plugins/firehouse` as of the last
commit, in a `firehouse/` folder, with the repository's `LICENSE`, and without
`tests/` and `shared/cases/`, which only the tests read. It reads the zip back and
fails if a file is missing or extra, and if the plugin header, `VERSION` and
readme.txt's stable tag name different versions.

CI builds it on every change to the plugin and keeps it for 30 days: on the run's
Summary page, under Artifacts, `firehouse-<version>-<commit>.zip` downloads as the
zip itself. That zip hasn't been through GSL's security review; it's for a local or
test site, and only from a run on one of this repository's branches: a pull request's
run, a fork's included, builds the pull request's code and names the zip the same way. For a release, set the version in the three places, merge, and push a tag
named for it (`git tag v0.3.0 && git push origin v0.3.0`): CI checks the tag matches,
runs the checks, and drafts a GitHub release with `firehouse-0.3.0.zip`. The release
job downloads the zip by the package job's artifact ID and checks its SHA-256 before
attaching it, and the draft's notes name the commit and the hash, so the security
review can record exactly what it passed. Publish the draft once it has.

`wordpress-org/` holds what a WordPress.org listing would need beyond the zip, and
what submitting it waits on (`wordpress-org/README.md`). The plugin isn't submitted.

## The coverage map

The landing page carries a map of where research comes from, in its own section after
the top needs, built to `FirehouseFormAdditions.pdf`.

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
- **One drawing for both sites.** `src/design-system/mapCanvas.ts` draws the map with
  Leaflet, and no framework: the site's `RegionMap` calls it, and the plugin bundles it
  for its view script (`assets/map/`). `RegionMap` and the plugin's markup render
  everything around it.

**The one runtime third-party request FireHouse makes is the basemap tiles**
(`server.arcgisonline.com`, Esri — attribution is rendered under the map and is
required by their terms), and only with JavaScript. The browser asks for them with
no Referer, so Esri isn't told which page is open. On the plugin's landing page, the
browser connects to Esri as soon as the view script starts loading the map, before any
tile, so Esri sees the visitor's IP address even if the map then fails to load. The
plugin's `readme.txt` says what each request carries. If the tiles can't load, the map
draws the boundaries on a blank canvas rather than failing. Blocking only the tile
requests, with a Content Security Policy for instance, still leaves the early
connection; a firewall that refuses Esri's host stops both. To use
another tile host, change the `basemap` in
`wp-content/plugins/firehouse/shared/coverage-map.json` (its tile addresses and
attribution), which both sites read, then run `npm run plugin:assets`: the plugin's
map module carries the tile addresses, and CI fails until it's rewritten. Update the
host and the Content Security Policy line in the plugin's `readme.txt` with it.
Dropping the tiles altogether also means changing `drawTiles()` in `mapCanvas.ts`, the
view script's early connection (`callbacks.draw` in `blocks/coverage-map/view.js`,
whose host is `tileHost` in `Coverage_Map::state()`, from `Map_Colors::tile_origin()`),
and the credit's wording.

Accessibility: the region list beside the map is not a fallback. It is the operable
surface — real buttons, real counts, driving the same state the polygons do — and
the Leaflet canvas is `aria-hidden` so assistive technology is sent there rather
than into unlabelled SVG paths; nothing in the map takes keyboard focus, so Tab
never stops on a part of it that says nothing. Colour is never the only channel; every
region carries its count as a number. For a visitor who has asked for less motion,
the map moves to a region without flying there. The selected region's projects sit
under a heading that counts them. They aren't a live region, which would read the whole list out on
each change. Instead, a visually hidden `role="status"` line after them says only "4
projects in Great Basin" when a choice changes them. It says nothing for the region
the page opened with, or once none is selected. Clear disappears once nothing is
selected, so it first moves focus to "All submissions", now the pressed button,
rather than letting focus fall back to the top of the page. The plugin's coverage
map block does the same with JavaScript, through its view script; without it, its
Clear reloads the page, and the status line stays empty.

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
can be read by whoever takes it over. It retires when the plugin launches: the
plugin's own survey form replaces it, and there is no live sync with Qualtrics or the
Google Form (WORDPRESS-PLAN.md).

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

Current state (14 November): the token audit passes. The live walk passes on every
route in dark, at rest and with `prefers-contrast: more` or
`prefers-reduced-transparency: reduce`. In light it flags the landing page only: the
coverage map's GACC codes, drawn with a halo over the basemap tiles, and, except with
reduced transparency, the hero and stats band, whose dark background is an image. The
walk can't see the halo and tiles, or the image (the known limitation at the top of
`scripts/audit-contrast-live.js`). Measured against the background pixels as drawn,
the hero's and stats' text is 6.45:1 at the lowest (AA needs 4.5:1); the codes'
contrast against their halo wasn't measured. The walk flags the same on the plugin's
landing page, explorer and quick look, inside GSL's header and footer, and nothing
more. Phase 6's admin screens were reviewed by the Section 508 specialist change by
change, not audited as a whole; Phase 8's screen-reader audit covers them. So with
Phase 7a's survey form at `/firehouse/submit/` and its Settings section: the form has
no React counterpart, so `npm run reference` and `plugin:compare` don't cover it; the
Section 508 specialist reviewed it in its pull requests (#62, #68), and the audit
includes it.

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
the dark header and hero. The four FireHouse files there are WebP at the size of the
PNGs they replaced (the wordmark 752×240, the mark 94×96, enough for a 3× screen),
lossy at quality 90 with lossless transparency (Pillow: `quality=90,
alpha_quality=100, method=6`), about half the size of those PNGs and the same to the
eye; a 256-colour PNG was smaller but banded the flame's gradients. The lossless PNGs
are in git history, for instance `git show
69f2dbb:src/assets/logos/firehouse-wordmark-on-dark.png`; start from those, not the
WebPs, for a new size. Keep the four names: `BrandMark` imports all four, the
plugin's PHP names three of them (the light wordmark ships with the plugin but
nothing there prints it), and `tests/LogosTest.php` fails if a logo the PHP names
isn't in the plugin's `assets/logos/`. There is no vector source yet — if one arrives, regenerate from it, then run `npm run plugin:assets` so the
plugin's copies follow.

## License

Apache License 2.0. See [LICENSE](LICENSE).
