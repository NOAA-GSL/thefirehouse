# WordPress plan — The Firehouse as a GSL WordPress plugin

> **Status: proposal for review, 30 September 2026; updated 1 October after the
> 30 September meeting with Stephanie and Emily, and 27 October when the survey moved
> from Qualtrics to Google Forms and GSL's theme was confirmed as a classic theme.**
> Only the empty plugin and a local WordPress are built (Phase 0). This
> document records the decisions made so far, the architecture they lead to, and a
> phased plan from the current `main` branch to a plugin running on gsl.noaa.gov.
> Disagreements belong in the pull request that adds this file.

Read alongside `README.md` (what exists today) and `PLAN.md` (the walkthrough changes
that produced it).

---

## Summary

- **What.** The Firehouse becomes a WordPress plugin that renders every page in
  WordPress itself: PHP templates and blocks, with WordPress's Interactivity API for
  the parts that respond to input. React, Vite and the static build retire at
  cutover.
- **Where.** A section of GSL's existing WordPress sites at `/firehouse`, inside GSL's
  own header and footer. The GSL test site first, then gsl.noaa.gov.
- **Who edits.** Stephanie and Emily, in wp-admin, through a Firehouse Editor role
  that can change Firehouse content and nothing else on the site. Content changes need
  no developer, no terminal and no deploy.
- **What's under one roof.** Everything after a submission or a synthesis arrives:
  drafts, previews, review, publishing and revision history. In the first release the
  survey stays in Google Forms and the AI pass stays outside WordPress, each feeding an
  import screen. Either can move inside later as its own release (see
  [Scope](#scope-one-roof-in-stages)).
- **How long.** About **17–20 weeks of one developer's time**, plus the wait for each
  security review. Two developers working in parallel after Phase 2 bring it to
  roughly 11–14 calendar weeks.
- **Mid-November and AMS.** The plugin will not be ready for the WPO meeting in
  mid-November or the January 2027 AMS launch. The current React site covers both as a
  static bridge: first on GSL's test site, restricted to GSL staff, for the WPO
  meeting; then at `gsl.noaa.gov/firehouse/`, on the same URLs the plugin will use, so
  no link shared at AMS breaks at cutover.

---

## Decisions so far

| Question | Decision |
| --- | --- |
| Rendering | **Full WordPress rendering.** Not headless, and not WordPress serving the React bundle. |
| Hosts | GSL's test WordPress site first, gsl.noaa.gov later. Both run GSL's own **classic theme** (`gsl-theme`: PHP header and footer, no Site Editor; confirmed 27 October) and meet the plugin's minimums (WordPress 6.7+, PHP 8.1+). |
| URL base | `/firehouse` on both. The path is free on both sites. |
| Fields | **WordPress built-in fields**: `register_post_meta`, the Settings API and block attributes. No ACF or Secure Custom Fields. |
| SEO plugin | None installed. The plugin writes its own meta tags and defers to an SEO plugin if one is added later. |
| Caching | Cloudflare. Whether it caches HTML, and how purges work, is still unknown (see [Caching](#caching)). |
| Install path | Security reviews each plugin release; developers install it. |
| WP-CLI | **Not relied on.** Everything, including the first data load, is done in wp-admin. |
| Outbound network | Believed allowed (doi.org). A Site Health check confirms it on the test site. |
| Survey tool | **Google Forms** today; it replaced Colorado State's Qualtrics. Where the survey lives long term is undecided. Responses land in a linked Google Sheet. Which Google account owns the form is open (question 16). See [What changes with Google Forms](#what-changes-with-google-forms). |
| Survey API sync | **Not now** (30 September). Stephanie and Emily download responses after their own quality check, and expect about 50 submissions a year. The design is kept as a later phase. |
| Survey intake | **Stays in Google Forms** for the first release (see [Scope](#scope-one-roof-in-stages)). |
| AI synthesis | **Runs outside WordPress** (NotebookLM today) and is pasted in as JSON for review. Running it from an approved LLM API is a later phase. |
| Link previews through Cloudflare | Found out during testing (see [Link previews](#page-titles-descriptions-and-link-previews)). |
| AMS bridge | Probably possible, and recommended, starting on the test site for the mid-November WPO meeting (see [The AMS bridge](#the-ams-bridge)). |
| Hosting outside GSL | **Not allowed** (October 2026 review of the intake documents). The earlier prototype on the former developer's personal domain was taken down in October 2026, and `netlify.toml` is removed from the repository so it is not redeployed. |
| Citations | **None.** The citation generator comes off the site, and project pages are not presented as citable: the site is not a permanent archive, and pages may be withdrawn (October 2026). |
| Plugin code | In this repository at `wp-content/plugins/firehouse/`, the path it has in a WordPress install, the way GSL's own repository keeps its theme. |
| Local development | Built on GSL's own local WordPress (the internal gsl-wordpress repository): its versions and theme. Firehouse runs in a separate local copy that only the developer's computer can reach (see [Local development](#local-development)). |

---

## Scope: one roof, in stages

The team's original concept puts the whole workflow in one system: a submission form,
an AI pass, a pending queue, human review, then publishing. This plan builds toward
that in stages.

**The first release puts the workflow under one roof.** Everything after data arrives
happens in wp-admin:

```
Google Form ─▶ Sheet ─download─▶ Import screen ────▶ draft projects ──preview, Publish──▶ site
AI pass (NotebookLM) ──JSON────▶ Synthesis screen ─▶ draft synthesis ──diff, Mark reviewed──▶ site
```

**Two inputs stay outside, each behind an import screen:**

- **The survey stays outside WordPress,** on the Google Form that replaced Qualtrics
  (see [What changes with Google Forms](#what-changes-with-google-forms)). Where it
  lives long term is undecided. The form's linked Google Sheet is where Stephanie and
  Emily take their first look at each response,
  and they said on 30 September that they don't need the survey connected. Moving it
  inside would store names, emails and job titles on gsl.noaa.gov and add a public form
  that writes to the database. That reverses two commitments the security review rests
  on (see [Security review](#security-review), and rule 2 under
  [Rules that must survive the port](#rules-that-must-survive-the-port)). Asking ten
  or more non-federal researchers the same questions on NOAA's behalf may need OMB
  clearance under the Paperwork Reduction Act wherever the form is hosted, the Google
  Form included (open question 14).
- **The AI pass stays where the team runs it.** No LLM API is approved for this use
  yet, and NotebookLM is not believed to offer one inside the NOAA enterprise. The
  synthesis is a batch pass over every published project, not a tag on each new
  submission, so a paste-and-review screen fits it. That screen is the same whichever
  way the JSON arrives.

Keeping the first release read-only to the public, with nothing personal stored, keeps
its security review short. Each input can move inside later as its own reviewed
release once its trigger is met; see [Later phases](#later-phases).

---

## Architecture

### Why full rendering

Three shapes were considered:

- **Headless.** WordPress holds the content and the React site stays elsewhere.
- **WordPress serves the React app.** WordPress owns routing and `<head>`; React
  renders the page body.
- **Full WordPress rendering.** PHP renders the pages.

The third was chosen. The site will be maintained inside GSL's WordPress by the people
who run it, so it should behave like the rest of it:

- server-rendered pages, real 404s and per-page link previews;
- a codebase in the stack GSL's WordPress developers and security reviewers already
  work in.

The cost is rewriting the page layer, which is where most of the estimate goes.

### Where today's code goes

| Today | Under the plugin |
| --- | --- |
| Pages (~1,900 lines of TSX) | PHP render templates, one per block |
| Design-system components (~2,300 lines of TSX) | PHP partials that emit **the same markup and class names** |
| All CSS (~5,000 lines: tokens, components, pages) | Carried over nearly verbatim, scoped under a wrapper class |
| `derive.ts`, `format.ts`, `normalize.ts` | Rewritten in PHP. |
| `citation.ts` and the citation tabs | Not ported. The citation generator comes off the site (October 2026). |
| `search.ts` | Kept in both languages: PHP renders the first, already-filtered page, and JavaScript filters as you type. |
| `RegionMap.tsx` | Leaflet stays, driven by an Interactivity API store |
| Mobile menu, scrollable need cards, quick-look modal | Interactivity API. The modal becomes a native `<dialog>`, which traps focus and handles Escape itself. |
| React Router, `ContentProvider`, the adapters, `RouteFocusManager` | Retired. Full page loads make them unnecessary. |
| `ThemeProvider` and its light/dark toggle | Retired. GSL's header has its own toggle, which sets the same `data-theme` attribute Firehouse's dark colors key off (open question 7). |
| `lucide-react` | The same icons as inline SVG from Lucide's static set |
| `landing.json`, `about.json` | Pages built from blocks, locked so editors change words but not layout |
| `projects.json` | `fh_project` posts |
| `topics.json`, `topicSummaries.json` | Four fixed `fh_topic` posts, each carrying its own synthesis |
| `settings.json` | A Firehouse settings screen |
| `scripts/import-survey.mjs`, `scripts/notebooklm-source.mjs` | Rewritten in PHP as admin screens |
| Vite, `public/.htaccess`, `public/_redirects` | Retired. The block and view scripts build with `@wordpress/scripts`. (`netlify.toml` was removed in October 2026.) |

The rewrite is smaller than the line counts suggest. The original port already moved
the design system from inline React styles into plain CSS files with `fh-` class
names. The PHP templates emit the same markup and classes, so the stylesheets move
across nearly untouched.

`src/content/types.ts` stays useful: it becomes the specification for the post meta.
A JSON Schema generated from it is passed to `register_post_meta`, so WordPress itself
rejects a malformed value.

### Plugin layout

The plugin sits in this repository at the path it has in a WordPress install:

```
wp-content/plugins/firehouse/
  firehouse.php
  includes/        post types, meta schemas, settings, roles and permissions,
                   validation, the import screen
  domain/          PHP versions of the vocabularies, derive, format, search
  blocks/<name>/   block.json · render.php · editor preview · view script (Interactivity API)
  templates/       project, explorer, topic and Firehouse page templates
  patterns/        landing and About layouts, locked to text-only edits
  assets/          tokens and component CSS, icons, logos, fonts, GACC boundaries
  shared/          vocabulary.json and test cases, read by both the PHP and JS tests
  tests/           PHPUnit tests, run without WordPress through Brain Monkey
```

### URL map and content model

| Page | URL | WordPress object |
| --- | --- | --- |
| Landing | `/firehouse/` | Page `firehouse`, built from blocks |
| About | `/firehouse/about/` | Child page of `firehouse` |
| Explorer | `/firehouse/projects/` | `fh_project` archive |
| Project | `/firehouse/projects/{slug}/` | Single `fh_project` |
| Topic | `/firehouse/topics/{key}/` | One of four `fh_topic` posts |
| 404 | anything else under `/firehouse/` | Firehouse "not found" page; GSL's own 404 elsewhere |

These are the React app's routes under a `/firehouse/` base, so a URL shared from the
bridge resolves unchanged under the plugin.

- **`fh_project`.** Post status replaces the `published` flag: a draft is unpublished.
  The slug is the current slug. Survey fields are plain-text post meta. The response
  ID is stored and unique, and it is the key every re-import matches on: the Qualtrics
  ResponseId for projects that came from Qualtrics, and the response timestamp for
  those that come from Google Forms. The block editor is off for this post type,
  because there is no free-form body to edit.
- **`fh_topic`.** Exactly four posts, with the topic key as the slug. Creating or
  deleting one is blocked by capability: adding a fifth topic is a schema change, not
  an edit. Each topic stores its synthesis as meta (`topNeeds`, `updatedAt`,
  `sourceCount`, `model`, `reviewedBy`). Each need points to its projects by **post
  ID**; the ID is turned back into a slug when the page renders.
- **Landing and About.** Ordinary WordPress pages built from blocks. Their layouts are
  locked to text-only editing (`contentOnly`), with a short allowed-blocks list and no
  Custom HTML block.
- **Settings.** A Firehouse → Settings screen holds the site name, the submit label, the
  submit survey URL and the navigation labels.
- **Navigation.** The four nav items are fixed in code, because the routes are
  structural; only their labels are editable. GSL's menus are edited under
  Appearance → Menus, which needs a capability that opens every menu on the site, so
  the Firehouse Editor role doesn't get it.

### Blocks

Core blocks carry copy. Custom blocks exist only where data is computed or input is
handled:

| Block | Renders | Interactive |
| --- | --- | --- |
| `firehouse/subnav` | FireHouse mark, the four nav items, Submit button | Mobile menu |
| `firehouse/hero` | Landing hero (eyebrow, heading and body are editable) | — |
| `firehouse/stats` | Live counts. The editor picks the source and writes the label; the number is always computed. | — |
| `firehouse/top-needs` | Four topic cards, the synthesis note, "Where these needs come from" | — |
| `firehouse/coverage-map` | GACC map, region list, the selected region's projects | Yes |
| `firehouse/explorer` | Search, filters, sort, project grid, quick look | Yes |
| `firehouse/project` | Project page: header, abstract, takeaways, needs, papers, at-a-glance panel, prev/next | Scrollable need cards |
| `firehouse/topic` | Topic page: ranked needs with sources, what the area covers, other areas | — |
| `firehouse/audience` | An About audience card with its icon | — |
| `firehouse/not-found` | The Firehouse 404 | — |

**Submit buttons** are core buttons whose link is bound to the Firehouse settings
through the Block Bindings API. The survey address lives in one field, which is the
job the empty-`href` convention does today.

**Editor previews** are rendered on the server, so each block's markup is written once,
in PHP.

### Interactivity

Every control is a real form element inside a `GET` form:

- **Without JavaScript,** submitting reloads the page with the same `?q=`, `?phase=`,
  `?region=`, `?year=`, `?type=` and `?sort=` parameters the React explorer uses today.
- **With JavaScript,** the Interactivity API enhances those same controls in place:
  live filtering, and the URL updated with `history.replaceState`.

Links people have already shared keep working either way.

- **Explorer.** Search runs in JavaScript (a port of `search.ts`) and in PHP (for the
  first render and for no-JS). Both are tested against one shared file of queries and
  expected results.
- **Map.** Leaflet's ES module build loads in the block's view script. The region list
  is a set of real buttons in the same kind of form, and the boundary GeoJSON ships as
  a plugin asset.

If working without JavaScript turns out not to be required, the PHP search can be
dropped. That saves about a week and removes the only logic that exists twice.

---

## Rules that must survive the port

Each of these is a decision the current code makes deliberately. Each is easy to lose
in a rewrite.

1. **Researcher text stays verbatim.** WordPress runs `wptexturize` (curly quotes,
   converted dashes) and `convert_smilies` over titles and over everything that passes
   through `the_content`, including block output. Left on, every project page would quietly rewrite what
   researchers submitted. Turn both off on Firehouse pages. Store survey text as
   plain-string meta, and give editors no rich-text control over it.
2. **Private survey answers never reach WordPress storage.** Q2 (email), Q3 (job
   title), Q10 (internal use only) and Qualtrics' IP and location columns are dropped
   today before anything is written. Google Forms records no IP address or location;
   its email, job-title and internal-use columns are dropped the same way. An uploaded
   export handled the default way lands in `/wp-content/uploads/`, where it is publicly
   reachable and carries every submitter's email. The import screen parses the file
   from PHP's temporary upload and discards it. The API sync requests only the columns
   the site uses.
3. **Unpublished records never leave the server.** Today `normalize.ts` keeps
   unpublished projects, and only the pages filter them out, so staged records ship in
   the JavaScript bundle. As WordPress drafts they are never rendered or sent to a
   visitor.
4. **Survey fields and editorial fields stay separate.** The importer matches on the
   response ID and overwrites survey fields only. The editorial fields (`published`,
   `summary`, `slug`, `publicationStatus`, `geoNote`, `fullRecordUrl`) live in
   `scripts/survey-editorial.json` today; in WordPress they belong to editors, and a
   re-import never touches them. Survey fields appear read-only on the edit screen,
   labelled as coming from the survey.
5. **Slugs are shared links.** The site offers no citations, but project links travel
   in slides, email and posts, so the migration keeps every current slug exactly.
   Needs point to projects by post ID, so renaming a project cannot silently strip a
   need's sources (today `normalize.ts` drops an unknown slug without an error).
6. **Fail loudly where the editor is, not on the public site.** Today one bad record
   throws in `normalize.ts` and the whole site shows "Content could not be loaded".
   That is right for JSON that goes through git review, and wrong for a CMS where
   content is published directly. Validate at save and import time with a clear admin
   notice, make invalid values impossible to select, and have public pages skip a bad
   record rather than fail.
7. **`year` is reserved by WordPress.** It is one of WordPress's public query
   variables. `/firehouse/projects/?year=2025` would filter by the date each post was
   created in WordPress (the migration date) and return nothing. Strip it from the main
   query on Firehouse pages so the explorer's parameter keeps its meaning.
8. **The vocabularies live in one place.** Fire phases, regions, publication statuses,
   topic keys and icon names move to `shared/vocabulary.json`, read by both PHP and
   JavaScript. Adding a survey option (as "After" was in September 2026) becomes a code
   change that waits on a reviewed release. The importer's existing warn-and-continue
   behaviour keeps imports working in the meantime.
9. **CSS is walled off in both directions.**
   - Outward: tokens on `:root`, the `body` background and its fixed atmosphere layer,
     the `*` and `img, svg` rules and the global `:focus-visible` ring all move under an
     `.fh-app` wrapper.
   - Inward: the theme's element styles for headings, links, lists and buttons would
     otherwise reach Firehouse markup, so the wrapper gets a small reset.
   - Both contrast audits are re-run on pages inside GSL's header and footer.
10. **Accessibility is re-earned, not assumed.** The React code handles many details
    on purpose:
    - dialog focus;
    - `aria-pressed` on the map's region list;
    - labelled scroll regions on long need cards;
    - the live result count;
    - the skip link.

    Each is rebuilt, then checked against accessibility-tree snapshots taken from the
    React site before work starts.
11. **New-tab links carry `rel="noopener noreferrer"`.** The React code sets it on
    every link that opens a new tab: papers, the survey and the footer's outside
    links. Security scanners flag pages that lack it, so the plugin writes it itself
    rather than rely on any site-wide filter.
12. **The Firehouse Editor role has no `unfiltered_html`,** so anything pasted into a
    paragraph block is sanitized. Biographies are still rendered as written, because
    `wptexturize` is off on these pages (rule 1).
13. **A malformed query string is ignored, never an error.** `parseProjectQuery`
    drops any parameter it doesn't recognize. PHP is less forgiving: `?phase[]=x`
    arrives as an array, and string functions given an array throw, so a scanner's
    probe becomes a server error. Every parameter the explorer and the map read is
    checked to be a string first.

---

## Operating inside GSL's WordPress

### Test and prod

- **Code** moves as a versioned plugin zip built by CI, through security review, to the
  test site and then to prod.
- **Content does not travel with code.** Test is for staging code and rehearsing. The
  first data load (the seed bundle below) runs on test for rehearsal, and again on prod
  at launch. After launch, **prod is the only place content is edited.**
- **Test is never indexed or linked publicly.** It is set to `noindex` and shows a
  visible test-site banner, because a link copied from a test page points at the test
  site.
- **If the survey sync is built later,** it is configured per environment, so only
  prod pulls the real survey.

### Local development

GSL keeps its own local WordPress in an internal repository, gsl-wordpress: GSL's
theme and must-use plugins, on the WordPress and PHP versions GSL runs. Firehouse
builds on it rather than on a generic `wp-env` site.

- **A separate stack.** `docker-compose.yml` in this repository mounts GSL's theme
  and must-use plugins read-only from a gsl-wordpress checkout, and the Firehouse
  plugin from this one. `bin/wp-init` reads GSL's image versions from that checkout
  into `.env`, so they stay out of this public repository, and copies the database
  from GSL's local site. The result listens only on the developer's computer, at
  `localhost:8090`, and is never exposed beyond it: Firehouse is not hosted outside
  GSL, even for a demo.
- **GSL's tooling.** PHPCS with the WordPress Coding Standards (plus a check against
  the PHP 8.1 minimum), PHPUnit 10 with Brain Monkey so tests run without WordPress,
  and CI on that minimum. Plugin Check runs inside the local site.
- **GSL's media library is not copied,** so images on GSL's own pages don't load
  locally. The theme's header, footer and logos do.

### Security review

Every code change is a reviewed release, so the design keeps releases rare and reviews
short:

- **No third-party PHP libraries.** Only WordPress's own APIs.
- **A short, documented list of outbound hosts:** doi.org, Google's APIs (if the sync
  is adopted) and the Esri basemap tiles, which the visitor's browser loads directly.
- **Secrets in `wp-config.php`, not the database.** The sync's Google key, if the sync
  is built, is a constant set by developers. It stays out of database backups, and
  editors never handle it.
- **Nothing personal is stored.** No emails, job titles, IP addresses or internal-only
  answers.
- **A one-page design summary goes to security in Phase 1:** data stored, endpoints,
  capabilities, outbound hosts, secrets. Surprises at the final review are the
  expensive kind.
- **Every release passes `PHPCS` (WordPress coding standards) and Plugin Check**
  before it is submitted.

### No WP-CLI: the Import screen

One **Firehouse → Import** screen, available only to a separate import capability,
handles both data paths. Each runs a dry-run preview first that lists what was parsed,
what was excluded and why, and any warnings. Nothing is written until the person
confirms.

- **Seed bundle.** A zip of today's JSON files plus the About portraits. This is how
  test, and later prod, get their first load. It is repeatable: running it again
  updates the same posts rather than duplicating them.
- **Survey responses.** The Google Form's response Sheet, downloaded as `.xlsx` or
  `.csv`. It is a PHP port of `import-survey.mjs`, adapted to Google's format (see
  [What changes with Google Forms](#what-changes-with-google-forms)): CSV through
  `fgetcsv`, XLSX through `ZipArchive`.
  - Records are matched on the response ID.
  - New records arrive as drafts. The import result links each one to a preview of
    its project page, so the quality check can happen on the page as visitors will
    see it rather than in spreadsheet cells. Publishing is WordPress's Publish button.
  - Responses missing from a newer export are flagged, not deleted.
  - Paper titles are looked up from DOIs and pages, keeping the last good metadata
    when a lookup fails.

WP-CLI commands may exist for developers, but nothing depends on them.

The same area offers **Download NotebookLM source**, a port of `notebooklm-source.mjs`.
It holds only published projects, so it should be the notebook's only source.

Next to it is the **synthesis screen**, where the AI pass comes back in:

1. **Paste the JSON** the prompt produced: up to five needs for each topic key
   (`observe`, `forecast`, `warning`, `governance`), each with its text, its mention
   count and the projects it draws on. Projects can be named by slug or by response
   ID, so the prompt can run against the downloaded source or the team's spreadsheet.
   Fields the site does not display are ignored.
2. **Validate.** A need that names an unknown project, or one that is not published, is
   flagged with the entry quoted: the synthesis may only draw on what the site shows.
   If the team settles on one mention per project (open question 13), the screen also
   flags any need whose count differs from the number of projects it lists.
3. **Compare.** The screen shows what changes against the published synthesis: needs
   added, dropped or reworded, rank and count changes, and sources gained or lost. This
   is what the reviewer signs off on.
4. **Save as a draft, then Mark reviewed.** Marking it reviewed publishes it to the
   topic pages and records the reviewer and the date. Until then, visitors keep seeing
   the last reviewed version. Earlier versions stay in the revision history.

No project link is made by hand: the JSON carries them.

### What changes with Google Forms

The survey has moved from Colorado State's Qualtrics to Google Forms (recorded
27 October). Responses land in a Google Sheet linked to the form, and that Sheet,
downloaded, is what the Import screen reads. Most of today's importer carries over: private columns
are dropped, exclusions are reported and the researcher's words stay verbatim. Five
things differ.

- **Columns are question titles, not IDs.** Qualtrics exports a stable ID for each
  question (`Q13`), so a reworded question kept importing. Google's header row is the
  question text itself. The Import screen remembers which column holds which field.
  When a heading it expects is missing, the dry run asks the person importing to pick
  the column from a list and saves the answer, so rewording a question needs no
  release. Google Forms has no multi-box question, so takeaways, needs and papers
  become separate numbered questions ("Key takeaway 1" to "Key takeaway 4"), gathered
  in order.
- **There is no response ID.** The Sheet's Timestamp column becomes the key, normalized
  so `.xlsx` and `.csv` downloads give the same value. It holds still only while
  **"Allow response editing" stays off**: an edited response gets a new timestamp and
  would import as a new project. As a backstop, the dry run flags a new response whose
  title and submitter match an existing project.
- **Answers are labels, not codes.** Qualtrics exported recode values (`ONCC`), so the
  map never depended on wording. Google exports the label the respondent clicked. The
  ten GACC labels already end in their code ("Northern California (ONCC)"), so the
  importer reads the code from the parentheses and the words before it can change. The
  four other regions ("Hawaii / Pacific Islands", "Not sure" and the rest) are matched
  by their full label in `shared/vocabulary.json`. A label that matches nothing is
  filed under UNKNOWN with a warning, as today.
- **Checkbox answers can't be split on commas.** Google joins choices with ", ", and
  "Northwest — OR, WA (NWCC)" has commas outside its parentheses, so today's splitter
  would cut it in two. The importer finds the known labels in each answer instead. An
  "Other" answer gets no column of its own: its text sits in the same cell, and it is
  whatever remains once the known labels are found.
- **Fewer private columns, cleaner text.** There are no IP or location columns. The
  email, job-title and internal-use answers are dropped as before (rule 2). Exports are
  clean UTF-8, so the double-encoding repair in the appendix applies only to the
  Qualtrics-era seed data.

Four form settings keep this working. The form is already live, so check them now:

1. "Allow response editing" is off.
2. The form is open to respondents without a NOAA Google login. A form in an
   organization's Google Workspace can be restricted to that organization's users.
3. There is no file-upload question. One makes every respondent sign in to Google.
4. Region and fire-phase options are worded as in `shared/vocabulary.json`, with each
   GACC label ending in its code.

### Caching

Cloudflare sits in front of both sites. One check decides the work: open any
gsl.noaa.gov page in the browser's developer tools and read the `cf-cache-status`
response header.

- **`DYNAMIC`.** HTML is not cached. Publishing shows up immediately. The only job is
  versioned CSS and JS filenames, which WordPress already does.
- **`HIT` or `MISS`.** HTML is cached, so a new project, a count or a synthesis update
  stays stale until the cache expires. Publishing then has to purge. That means either
  Cloudflare's own WordPress integration, or the plugin calling Cloudflare's purge API
  with a token limited to purging. The token is a security-review item.

### Page titles, descriptions and link previews

- **Core already provides** `<title>`, the canonical link, and the XML sitemap, which
  includes projects automatically.
- **The plugin adds** the description and the Open Graph and Twitter tags, on Firehouse
  pages only. A shared project link then previews with the project's own title and
  abstract. This closes the gap noted in `PLAN.md` §2.2.
- **If GSL installs an SEO plugin later,** the Firehouse plugin detects it and hands it
  these values instead of printing duplicate tags.
- **Cloudflare may block the preview bots.** Scripted requests to both sites currently
  receive a Cloudflare challenge page. If Slack's, Teams' or LinkedIn's preview bots
  receive the same, no tag the plugin writes will reach them. This is checked in
  Phase 4 on the test site, and again on prod before launch, because the two may be
  configured differently.

### Survey sync (deferred)

Not needed for now (30 September). The design below is kept for
[later phase L2](#later-phases), revised on 27 October for Google Forms.

- **Read the response Sheet through Google's Sheets API, not the Forms API.** The
  Sheets API fetches chosen columns: the sync reads the header row, then only the
  columns the site uses, so private answers never reach GSL's server at all. The Forms
  API returns whole responses, emails included.
- **A Google service account with read-only access to that one Sheet.** Its key is a
  constant in `wp-config.php`, and the plugin signs its requests with PHP's built-in
  OpenSSL, so no Google client library is needed. The key itself can read every
  column, so it is guarded as access to personal data: whoever owns the form's Google
  account has to agree, and someone has to own the key. Whether that Google Workspace
  allows service-account keys at all is a question for its administrators.
- **Never "Publish to the web".** It is the obvious shortcut, and it turns the whole
  response Sheet, emails included, into a public address.
- **Not a push from Google.** An Apps Script that sends each new response to WordPress
  needs an endpoint on gsl.noaa.gov that writes to the database, which the first
  release avoids.
- **A "Sync now" button, plus an optional schedule.** WordPress's scheduler fires on
  page visits: reliable on prod, unreliable on a quiet test site. New responses arrive
  as drafts, and the Firehouse editors get an email.
- **One importer core, two sources.** The file upload ships first. The API becomes a
  second source for the same code, so the decision does not block anything.

---

## The AMS bridge

The React app already builds for a sub-path, and its routes match the plugin's URL
map. Launching it at `gsl.noaa.gov/firehouse/` for AMS means links shared there keep
working after the cutover.

**Mid-November first, on the test site.** The same build serves the WPO meeting from
GSL's test site, restricted to GSL staff by login or by network, whichever ITS prefers.
Stephanie confirmed on 30 September that a demo opened with their own accounts is
enough. It also keeps the demo on GSL's infrastructure, the only place the site may
run: hosting outside GSL is not allowed (see [Decisions so far](#decisions-so-far)).
Build that copy with the test site's address as `VITE_SITE_URL`. If the test site isn't
ready in time, the fallback is running the build on the presenter's laptop and sharing
the screen.

1. Build with `VITE_BASE_PATH=/firehouse/` and `VITE_SITE_URL=https://gsl.noaa.gov`.
2. Serve the build as a static directory at `/firehouse`.
   - If the origin server is **Apache**, uncomment `RewriteBase /firehouse/` in
     `public/.htaccess`.
   - If it is **nginx**, a `try_files` block in the server configuration does the same
     job.
3. **Turn the gov banner and footer back on.** A static page does not receive GSL's
   header and footer. `GovBanner` and `SiteFooter` still exist; they were hidden in
   `1593473`.
4. Finish the remaining items in the README's "Before this goes public" list: hero
   image, synthesis review, `robots.txt` and the sitemap. The synthesis review matters
   most: today's draft covers 4 of the 7 published projects, so it has to be rerun
   across all of them and reviewed before anyone outside the team sees it.
5. **Point Submit a Finding at the Google Form.** `submitFormUrl` in `settings.json`
   still links to the Qualtrics survey.
6. **Remove the citation generator** from the project page, as agreed in October, so
   the public site does not invite citations.

About 2–3 days of work, plus about a day for the test-site copy, not counted in the
plugin estimate.

**Responses now arrive through the Google Form,** so before the next import
`import-survey.mjs` needs the changes in
[What changes with Google Forms](#what-changes-with-google-forms): columns found by
title, the timestamp as the key, and label matching. That is about 1–2 more days,
using a test response downloaded from the live form. It also gives Phase 7a's
importer parity check a Node importer to compare against.

**Cutover with no downtime.** Build the WordPress `firehouse` page tree on prod while
the static directory still shadows it; editors preview it through its page-ID URL.
Removing the directory then hands the URLs to WordPress immediately.

---

## Phased plan

### Phase 0 — Reference, decisions, groundwork (1–1.5 weeks)

- **Capture the reference.** Screenshots and accessibility-tree snapshots of every
  route from the React build, in each theme that will be kept. The React site is the
  specification the plugin has to match.
- **Add shared test cases.** Unit tests for `normalize`, `derive`, `search` and
  `format`, written as data files (input and expected output) that the PHP tests will
  read later.
- **Move the vocabularies** into `shared/vocabulary.json`, with each option's label as
  the Google Form shows it, and generate a JSON Schema from `types.ts`.
- **Repair the seed data.** Fix the two double-encoded strings in `projects.json` (see
  the appendix) before the seed bundle is built.
- **Scaffold the plugin** and a local WordPress for developers (see
  [Local development](#local-development)). *Done 27 October: the empty plugin passes
  PHPCS, PHPUnit and Plugin Check.*
- **Check `cf-cache-status`** on gsl.noaa.gov.
- **Send the design summary** to security.

**Done when:** the reference is captured, the shared cases pass in TypeScript, and an
empty plugin passes PHPCS and Plugin Check.

### Phase 1 — Content model and first data load (2 weeks)

- The `fh_project` and `fh_topic` post types.
- Meta registered with schemas, and revisions enabled.
- The URL map and rewrites.
- The fix for the `year` query variable.
- The Firehouse Editor role, with the permission filter that limits page editing to
  the `firehouse` page tree.
- The settings screen.
- The Import screen with the seed bundle and dry run.

**Done when:** the seed bundle loads on the test site with every slug intact, and the
editor role cannot edit anything outside Firehouse.

### Phase 2 — Logic in PHP (1.5–2 weeks)

PHP versions of derive (counts, region expansion for national studies, synthesis
info), format, validation and search/filter.

**Done when:** the PHP versions pass the same shared test cases as the TypeScript
ones.

### Phase 3 — Design system and frame (2 weeks)

- CSS moved into the plugin and scoped under `.fh-app`, with the inward reset.
- Icons as SVG, logos, and fonts. Fonts are self-hosted if Archivo and Public Sans are
  kept.
- Firehouse page templates, chosen with the `template_include` filter, that call
  GSL's `get_header()` and `get_footer()`, with full-width sections between them.
  GSL's theme is classic, so no Site Editor copy of a template can override the
  plugin's.
- Dark colors keyed to GSL's light/dark toggle, if dark mode is kept (open
  question 7).
- The `firehouse/subnav` block.

**Done when:** an empty Firehouse page on the test site sits inside GSL's header and
footer, with no styles leaking in either direction.

### Phase 4 — Display-only pages (2–2.5 weeks)

- The project page (including need cards), topic pages, About, the 404
  and the landing page without the map.
- Description and Open Graph tags.
- Check that projects appear in the sitemap.
- Test link previews in Slack and Teams.

**Done when:** these pages match the reference screenshots and accessibility
snapshots, and Stephanie and Emily can review them on the test site.

### Phase 5 — Interactive pieces (3–3.5 weeks)

The explorer (search, filters with counts, sort, removable filter chips, quick-look
`<dialog>`), the coverage map, scrollable need cards and the mobile menu.

**Done when:** it reaches feature parity with the React site, every URL parameter
behaves the same, and the accessibility snapshots match.

### Phase 6 — Editing screens (2–2.5 weeks)

- Project and topic edit screens: survey fields read-only, editorial fields editable,
  publishing through WordPress's Publish button.
- Landing and About layouts locked to text-only edits.
- The synthesis screen: paste, validation, the comparison against the published
  synthesis, and **Mark reviewed**.
- Submit buttons bound to the settings.
- Validation notices.

**Done when:** Stephanie and Emily can do every task in `README.md` and
`notebooklm/README.md` on the test site without a developer.

### Phase 7a — Survey import (2 weeks)

- The Google Forms path on the Import screen: column matching, the timestamp key,
  label matching, and each new draft linked to its preview.
- Download NotebookLM source.
- Site Health checks: outbound reach to doi.org, `noindex` on test, and HTML caching
  status.

**Done when:** the Node and PHP importers produce identical records from a synthetic
Google Forms export, a reworded question imports after one column choice, and the
uploaded file is never stored.

### Phase 7b — Survey sync (deferred)

Not needed for now (30 September). Moved to [later phase L2](#later-phases).

### Phase 8 — Review, launch, cutover (1.5–2 weeks)

- Security review and fixes.
- Cache purging, if the caching check calls for it.
- A screen-reader audit of the public pages and the admin screens.
- CI that builds the versioned plugin zip.
- A runbook for editors and for administrators.
- Launch: install on prod, run the seed import, build the page tree behind the bridge,
  remove the static directory.
- Confirm link previews on prod.
- Retire the React app and the static-hosting files.

**Done when:** gsl.noaa.gov/firehouse is served by the plugin, and every URL from the
bridge resolves to the same content.

### Later phases

Each is its own plugin release with its own security review. None is scheduled: each
starts when its trigger is met. They are listed in the order they are likely to be
needed.

**L1 — Run the synthesis from an approved LLM API (about 1 week).**

- *Trigger:* an LLM API approved for this use. A colleague of Stephanie's received OAR
  clearance for similar work, and she has the contacts he worked with.
- *What changes:* a **Run synthesis** button sends the published projects' text and
  the team's prompt to the model. The reply lands in the synthesis screen as a draft,
  with the same validation, comparison and **Mark reviewed** step. The prompt lives on
  the settings screen, so Stephanie and Emily can refine it without a release.
- *Review impact:* one outbound host and one key in `wp-config.php`. Only text that is
  already public on the site is sent, so nothing personal leaves GSL.

**L2 — Google Sheets sync (about 1 week).** Designed under
[Survey sync](#survey-sync-deferred): a service-account key in `wp-config.php`, a
read of only the response Sheet's public columns, "Sync now" plus an optional
schedule, and drafts with an email to the editors.

- *Trigger:* downloading by hand becomes a chore. The likeliest cause is WPO making
  submission a funding requirement.
- *Needs first:* the agreement of whoever owns the form's Google account, a named owner
  for the key, and a Google Workspace that allows service-account keys.

**L3 — The survey inside WordPress (2–3 weeks, plus reviews).**

- *Trigger:* Google Forms stops being workable: ITS objects to the account it runs
  under, or the survey needs something a Google Form can't do. Volume alone is L2's
  job.
- *Needs first:*
  - A Paperwork Reduction Act check with NOAA's PRA clearance officer. If OMB
    clearance is needed, it can take months.
  - A privacy review. The form stores names, emails and job titles, which reverses
    "nothing personal is stored" and rule 2.
  - Spam protection that adds no third-party service, or one the review accepts.
  - An accessible form with up to ten repeated need entries, checked with a screen
    reader like the rest.
  - Confirmation and acceptance emails sent through GSL's mail setup.
- *What stays the same:* submissions arrive as drafts, exactly like imports.

**L4 — Reporting and export.**

- *Trigger:* someone names who will use it, and for what.
- *Cheapest first:* a print stylesheet (about a day), so the landing and topic pages
  save cleanly to PDF from the browser. PDFs generated on the server run into the
  no-third-party-PHP-libraries rule.
- *Not recommended:* a live "last submission" ticker. At about 50 submissions a year
  it would mostly show how long ago the last one was, and it would publish submitters'
  names.

### At a glance

| Phase | Work | Size | Visible on the test site |
| --- | --- | --- | --- |
| 0 | Reference, decisions, groundwork | 1–1.5 wk | — |
| 1 | Content model and first data load | 2 wk | Empty plugin, data in wp-admin |
| 2 | Logic in PHP | 1.5–2 wk | — |
| 3 | Design system and frame | 2 wk | Firehouse frame inside GSL's chrome |
| 4 | Display-only pages | 2–2.5 wk | Pages to read and review |
| 5 | Interactive pieces | 3–3.5 wk | Feature parity |
| 6 | Editing screens | 2–2.5 wk | Editors working on test |
| 7a | Survey import | 2 wk | Import rehearsal |
| 7b | Survey sync | deferred to L2 | — |
| 8 | Review, launch, cutover | 1.5–2 wk | → gsl.noaa.gov |
| | **Total** | **~17–20 wk** | plus security-review wait time |
| L1–L4 | Later phases, each when triggered | ~1–3 wk each | — |

With two developers the work splits after Phase 2: one on Phases 3–5, the other on
Phases 6–7.

---

## Verification

- **Visual and semantic parity.** Screenshots and accessibility-tree snapshots of each
  route, compared against the reference captured in Phase 0.
- **Logic parity.** PHP and TypeScript run the same shared test cases.
- **Importer parity.** The Node and PHP importers run on the same Google Forms export and
  must produce identical records. Real exports contain submitters' emails, so fixtures
  are synthetic.
- **Migration parity.** After the seed import, the content WordPress renders must
  match `normalizeSiteContent()` run over today's JSON.
- **Synthesis import.** Pasting the published synthesis back shows no changes. A need
  that names an unknown or unpublished project is flagged with the entry quoted.
- **Contrast.** `scripts/check-contrast.mjs` against the tokens, and the live audit in
  `scripts/audit-contrast-live.js` on pages inside GSL's header and footer.
- **Assistive technology.** A real screen-reader pass on the public pages and on every
  admin screen. Section 508 applies to the staff tools too.
- **Link previews.** Slack and Teams, on test and on prod.

---

## What WordPress fixes, and what we give up

**Resolved by moving:**

- Per-project link previews (`PLAN.md` §2.2).
- Real 404 status codes instead of the `noindex` workaround.
- `sitemap.xml`.
- Canonical URLs without `VITE_SITE_URL`.
- The question of which SPA fallback GSL's host needs.
- The hero image and About portraits come from the Media Library.
- Revision history on projects and topic syntheses.
- Unpublished records no longer shipping to browsers.
- The federal chrome items in the README (gov banner, footer, privacy and
  accessibility links) now come from GSL's theme.

**Given up:**

- A static site with essentially no attack surface.
- Content history reviewed in git pull requests. Settings have no revision history,
  and the provenance notes in `notebooklm/` need a new home.
- A single-language codebase.
- Fast releases: every code change waits on a security review.

---

## Open questions

**For GSL's web team**

1. Is the origin server Apache or nginx? This decides how the bridge is served.
2. How long does a security review take, and what does a submission need to include?
3. ~~What are the slugs of the theme's header and footer template parts?~~ *None:
   GSL's theme is classic, with PHP header and footer files (confirmed 27 October).*
4. ~~Do administrators customize templates in the Site Editor?~~ *They can't: a
   classic theme has no Site Editor.*
5. Does Cloudflare cache HTML (`cf-cache-status`), and who can purge? Does any other
   cache sit in front of either site?

**For the team**

6. Launch the bridge, on the test site for mid-November and on prod for AMS?
   *Recommended.*
7. Drop dark mode for the WordPress version? *Recommended (27 October): keep it, and
   follow GSL's toggle.* The worry was a dark Firehouse section between GSL's light
   header and footer. GSL's theme turns out to have its own light/dark switch, which
   sets `data-theme="dark"` on the page, the same attribute Firehouse's dark colors
   use, so the two always match. Keeping it means the visual and contrast checks run
   in both themes.
8. Keep Archivo and Public Sans, or use GSL's fonts (Nunito Sans for text, Montserrat
   for headings)? GSL's pages already load those, so using them adds nothing to
   self-host.
9. ~~Adopt an API sync for the survey?~~ *Not now (30 September); see later phase L2.*
10. Is working without JavaScript a requirement? If not, the PHP search is dropped (see
    [Interactivity](#interactivity)).

**Added after the 30 September meeting**

11. *(GSL's web team)* Can the bridge go on the test site, restricted to GSL staff, by
    mid-November?
12. *(GSL's web team)* What do the Project Intake Request and the Technical Assessment
    Worksheet still need for this site? Dmitri started them.
13. *(Stephanie and Emily)* Which counting rule is canonical? The team's prompt counts
    at most one mention per project. Today's numbers count every entry (the rule in
    `notebooklm/README.md` and the 17 September draft), so one project can give a need
    two mentions. The synthesis screen's count check depends on the answer.
14. *(Stephanie and Emily, with NOAA's PRA clearance officer)* Does the survey count
    as a NOAA information collection under the Paperwork Reduction Act? It asks
    non-federal researchers the same questions on NOAA's behalf. Moving from Qualtrics
    to Google Forms doesn't change the question: it is about what is collected and for
    whom, not which tool hosts the form. The form is already live, so this is worth
    settling soon. The answer also bears on later phase L3.
15. *(Stephanie and Emily)* Should a project that answers "Project did not require IRB
    review" be published? The importer treats that answer as no approval (see the
    appendix).

**Added after the switch to Google Forms (27 October)**

16. *(Stephanie and Emily)* Which Google account owns the form and its response Sheet:
    NOAA's Google Workspace, Colorado State's, or another? It decides where personal
    data lives, who must agree to a sync (L2), and whether the form has to be opened
    to respondents outside the organization. If it is NOAA's, the intake documents
    should say so, because that is what settles the concern that the survey sat
    outside NOAA.
17. *(Stephanie and Emily)* Is the Qualtrics survey closed? Anything submitted there
    since the last export needs one last Qualtrics import before the seed bundle is
    built.

---

## Appendix — found during the analysis

- **Unpublished projects ship in the bundle today.** `normalize.ts` drops only records
  without IRB approval; the pages filter unpublished ones out. All seven current
  records are published, so nothing is exposed yet, but the next import stages new
  records as unpublished and they would ship with the next deploy.
- **Documentation drift.** `README.md` and `PRODUCT.md` still describe nine placeholder
  projects. `projects.json` now holds seven survey-imported records, all published. The
  migration treats them as real.
- **The Sanity and Strapi adapters** were never run against a live backend. They
  retire with this plan.
- **A submission missing from the site.** The latest export has eight responses; the
  site has seven. `R_3e9GnZYuWAjuZM5` (FWT Evaluation 001, WoFS-Smoke) answered Q8
  with "Project did not require IRB review", which `import-survey.mjs` counts as no
  approval, so it is excluded. The question itself asks about "approval or exemption",
  so it may belong on the site (open question 15).
- **Mis-encoded text in the export.** Some Qualtrics rows arrive double-encoded
  (`NGFSâ€™s` for `NGFS’s`), and two strings in `projects.json` carry it today. Google
  Forms exports are clean UTF-8, so rather than port a repair, fix the two strings
  before the seed bundle is built (Phase 0).
- **The live synthesis is out of date.** `topicSummaries.json` is a 17 September draft,
  written with Claude, that covers 4 of the 7 published projects. The landing page
  reports it as drawn from "4 projects today".
