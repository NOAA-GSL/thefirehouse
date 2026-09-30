# WordPress plan — The Firehouse as a GSL WordPress plugin

> **Status: proposal for review, 30 September 2026.** Nothing here is built. This
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
- **How long.** About **16–20 weeks of one developer's time**, plus the wait for each
  security review. Two developers working in parallel after Phase 2 bring it to
  roughly 11–14 calendar weeks.
- **AMS.** The plugin will not be ready for the January 2027 launch. The current
  React site launches at `gsl.noaa.gov/firehouse/` as a static bridge, on the same URLs
  the plugin will use, so nothing cited at AMS breaks at cutover.

---

## Decisions so far

| Question | Decision |
| --- | --- |
| Rendering | **Full WordPress rendering.** Not headless, and not WordPress serving the React bundle. |
| Hosts | GSL's test WordPress site first, gsl.noaa.gov later. Both run a **block theme** and meet the plugin's minimums (WordPress 6.7+, PHP 8.1+). |
| URL base | `/firehouse` on both. The path is free on both sites. |
| Fields | **WordPress built-in fields**: `register_post_meta`, the Settings API and block attributes. No ACF or Secure Custom Fields. |
| SEO plugin | None installed. The plugin writes its own meta tags and defers to an SEO plugin if one is added later. |
| Caching | Cloudflare. Whether it caches HTML, and how purges work, is still unknown (see [Caching](#caching)). |
| Install path | Security reviews each plugin release; developers install it. |
| WP-CLI | **Not relied on.** Everything, including the first data load, is done in wp-admin. |
| Outbound network | Believed allowed (doi.org, Qualtrics). A Site Health check confirms it on the test site. |
| Qualtrics API sync | To be decided. Designed for from the start, built after the file upload. |
| Link previews through Cloudflare | Found out during testing (see [Link previews](#page-titles-descriptions-and-link-previews)). |
| AMS bridge | Probably possible, and recommended (see [The AMS bridge](#the-ams-bridge)). |

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
| `derive.ts`, `format.ts`, `citation.ts`, `normalize.ts` | Rewritten in PHP. Citations are built on the server, so none of that logic stays in JavaScript. |
| `search.ts` | Kept in both languages: PHP renders the first, already-filtered page, and JavaScript filters as you type. |
| `RegionMap.tsx` | Leaflet stays, driven by an Interactivity API store |
| Citation tabs, mobile menu, scrollable need cards, quick-look modal | Interactivity API. The modal becomes a native `<dialog>`, which traps focus and handles Escape itself. |
| React Router, `ContentProvider`, the adapters, `RouteFocusManager` | Retired. Full page loads make them unnecessary. |
| `lucide-react` | The same icons as inline SVG from Lucide's static set |
| `landing.json`, `about.json` | Pages built from blocks, locked so editors change words but not layout |
| `projects.json` | `fh_project` posts |
| `topics.json`, `topicSummaries.json` | Four fixed `fh_topic` posts, each carrying its own synthesis |
| `settings.json` | A Firehouse settings screen |
| `scripts/import-survey.mjs`, `scripts/notebooklm-source.mjs` | Rewritten in PHP as admin screens |
| Vite, `netlify.toml`, `public/.htaccess`, `public/_redirects` | Retired. The block and view scripts build with `@wordpress/scripts`. |

The rewrite is smaller than the line counts suggest. The original port already moved
the design system from inline React styles into plain CSS files with `fh-` class
names. The PHP templates emit the same markup and classes, so the stylesheets move
across nearly untouched.

`src/content/types.ts` stays useful: it becomes the specification for the post meta.
A JSON Schema generated from it is passed to `register_post_meta`, so WordPress itself
rejects a malformed value.

### Plugin layout

```
firehouse/
  firehouse.php
  includes/        post types, meta schemas, settings, roles and permissions,
                   validation, the import screen
  domain/          PHP versions of the vocabularies, derive, format, citation, search
  blocks/<name>/   block.json · render.php · editor preview · view script (Interactivity API)
  templates/       project, explorer, topic and Firehouse page templates
  patterns/        landing and About layouts, locked to text-only edits
  assets/          tokens and component CSS, icons, logos, fonts, GACC boundaries
  shared/          vocabulary.json and test cases, read by both the PHP and JS tests
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
  The slug is the current slug. Survey fields are plain-text post meta. The Qualtrics
  ResponseId is stored and unique, and it is the key every re-import matches on. The
  block editor is off for this post type, because there is no free-form body to edit.
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
  structural; only their labels are editable. In a block theme, menus are edited in
  the Site Editor, which is admin-only and exposes every template on the site.

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
| `firehouse/project` | Project page: header, abstract, takeaways, needs, papers, citation, at-a-glance panel, prev/next | Citation tabs and copy; scrollable need cards |
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
- **Citation.** All three formats (APA, Chicago, BibTeX) are rendered on the server.
  The tabs switch which one is visible, and the copy button copies it.

If working without JavaScript turns out not to be required, the PHP search can be
dropped. That saves about a week and removes the only logic that exists twice.

---

## Rules that must survive the port

Each of these is a decision the current code makes deliberately. Each is easy to lose
in a rewrite.

1. **Researcher text stays verbatim.** WordPress runs `wptexturize` (curly quotes,
   converted dashes) and `convert_smilies` over the entire HTML of a block template,
   not only over post content. Left on, every project page would quietly rewrite what
   researchers submitted. Turn both off on Firehouse pages. Store survey text as
   plain-string meta, and give editors no rich-text control over it.
2. **Private survey answers never reach WordPress storage.** Q2 (email), Q3 (job
   title), Q10 (internal use only) and Qualtrics' IP and location columns are dropped
   today before anything is written. An uploaded export handled the default way lands
   in `/wp-content/uploads/`, where it is publicly reachable and carries every
   submitter's email. The import screen parses the file from PHP's temporary upload and
   discards it. The API sync requests only the columns the site uses.
3. **Unpublished records never leave the server.** Today `normalize.ts` keeps
   unpublished projects, and only the pages filter them out, so staged records ship in
   the JavaScript bundle. As WordPress drafts they are never rendered or sent to a
   visitor.
4. **Survey fields and editorial fields stay separate.** The importer matches on
   ResponseId and overwrites survey fields only. The editorial fields (`published`,
   `summary`, `slug`, `publicationStatus`, `geoNote`, `fullRecordUrl`) live in
   `scripts/survey-editorial.json` today; in WordPress they belong to editors, and a
   re-import never touches them. Survey fields appear read-only on the edit screen,
   labelled as coming from Qualtrics.
5. **Slugs are citations.** The migration keeps every current slug exactly. Needs point
   to projects by post ID, so renaming a project cannot silently strip a need's sources
   (today `normalize.ts` drops an unknown slug without an error).
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
    - a roving tab stop in the citation tabs;
    - `aria-pressed` on the map's region list;
    - labelled scroll regions on long need cards;
    - the live result count;
    - the skip link.

    Each is rebuilt, then checked against accessibility-tree snapshots taken from the
    React site before work starts.
11. **Templates stay in the plugin.** If an admin edits a Firehouse template in the
    Site Editor, WordPress saves a database copy that overrides the plugin's file from
    then on. Later plugin updates to that template then never appear.
12. **The Firehouse Editor role has no `unfiltered_html`,** so anything pasted into a
    paragraph block is sanitized. Biographies are still rendered as written, because
    `wptexturize` is off on these pages (rule 1).

---

## Operating inside GSL's WordPress

### Test and prod

- **Code** moves as a versioned plugin zip built by CI, through security review, to the
  test site and then to prod.
- **Content does not travel with code.** Test is for staging code and rehearsing. The
  first data load (the seed bundle below) runs on test for rehearsal, and again on prod
  at launch. After launch, **prod is the only place content is edited.**
- **Test is never indexed or cited.** It is set to `noindex` and shows a visible
  test-site banner, because a citation copied from a test page carries the test URL.
- **Qualtrics sync is configured per environment,** so only prod pulls the real
  survey.

### Security review

Every code change is a reviewed release, so the design keeps releases rare and reviews
short:

- **No third-party PHP libraries.** Only WordPress's own APIs.
- **A short, documented list of outbound hosts:** doi.org, Qualtrics (if the sync is
  adopted) and the Esri basemap tiles, which the visitor's browser loads directly.
- **Secrets in `wp-config.php`, not the database.** The Qualtrics token is a constant
  set by developers. It stays out of database backups, and editors never handle it.
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
- **Qualtrics export.** The `.xlsx` or `.csv` from Qualtrics. It is a PHP port of
  `import-survey.mjs`: CSV through `fgetcsv`, XLSX through `ZipArchive`.
  - Records are matched on ResponseId.
  - New records arrive as drafts.
  - Responses missing from a newer export are flagged, not deleted.
  - Paper titles are looked up from DOIs and pages, keeping the last good metadata
    when a lookup fails.

WP-CLI commands may exist for developers, but nothing depends on them.

The same area offers **Download NotebookLM source**, a port of `notebooklm-source.mjs`.
Next to it is a **synthesis screen**: paste the notebook's JSON, preview it with any
unknown project flagged, save it as a draft, then **Mark reviewed**, which records the
reviewer and the date.

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

### Qualtrics sync (if adopted)

- **The survey is on Colorado State's Qualtrics** (`colostate.az1.qualtrics.com`, per
  `settings.json`). The API token would be a CSU account credential stored on a NOAA
  server, so it needs an owner and CSU's agreement.
- **Request only the columns the site uses.** Qualtrics' response-export API selects
  questions, so private answers never reach GSL's server at all. It also requests
  **recode values rather than labels**, because the map depends on the codes.
- **A "Sync now" button, plus an optional schedule.** WordPress's scheduler fires on
  page visits: reliable on prod, unreliable on a quiet test site. New responses arrive
  as drafts, and the Firehouse editors get an email.
- **One importer core, two sources.** The file upload ships first. The API becomes a
  second source for the same code, so the decision does not block anything.

---

## The AMS bridge

The React app already builds for a sub-path, and its routes match the plugin's URL
map. Launching it at `gsl.noaa.gov/firehouse/` for AMS means citations made there
survive the cutover.

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
   image, synthesis review, `robots.txt` and the sitemap.

About 2–3 days of work, not counted in the plugin estimate.

**Cutover with no downtime.** Build the WordPress `firehouse` page tree on prod while
the static directory still shadows it; editors preview it through its page-ID URL.
Removing the directory then hands the URLs to WordPress immediately.

---

## Phased plan

### Phase 0 — Reference, decisions, groundwork (1–1.5 weeks)

- **Capture the reference.** Screenshots and accessibility-tree snapshots of every
  route from the React build, in each theme that will be kept. The React site is the
  specification the plugin has to match.
- **Add shared test cases.** Unit tests for `normalize`, `derive`, `search`,
  `citation` and `format`, written as data files (input and expected output) that the
  PHP tests will read later.
- **Move the vocabularies** into `shared/vocabulary.json`, and generate a JSON Schema
  from `types.ts`.
- **Scaffold the plugin** and a local WordPress for developers (`wp-env`).
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
info), format, citation, validation and search/filter.

**Done when:** the PHP versions pass the same shared test cases as the TypeScript
ones.

### Phase 3 — Design system and frame (2 weeks)

- CSS moved into the plugin and scoped under `.fh-app`, with the inward reset.
- Icons as SVG, logos, and fonts. Fonts are self-hosted if Archivo and Public Sans are
  kept.
- Plugin-registered templates that use GSL's header and footer template parts, with
  full-width sections.
- The `firehouse/subnav` block.

**Done when:** an empty Firehouse page on the test site sits inside GSL's header and
footer, with no styles leaking in either direction.

### Phase 4 — Display-only pages (2–2.5 weeks)

- The project page (including citations and need cards), topic pages, About, the 404
  and the landing page without the map.
- Description and Open Graph tags.
- Check that projects appear in the sitemap.
- Test link previews in Slack and Teams.

**Done when:** these pages match the reference screenshots and accessibility
snapshots, and Stephanie and Emily can review them on the test site.

### Phase 5 — Interactive pieces (3–4 weeks)

The explorer (search, filters with counts, sort, removable filter chips, quick-look
`<dialog>`), the coverage map, the citation tabs and copy, scrollable need cards and
the mobile menu.

**Done when:** it reaches feature parity with the React site, every URL parameter
behaves the same, and the accessibility snapshots match.

### Phase 6 — Editing screens (1.5–2 weeks)

- Project and topic edit screens: survey fields read-only, editorial fields editable,
  publishing through WordPress's Publish button.
- Landing and About layouts locked to text-only edits.
- The synthesis screen with **Mark reviewed**.
- Submit buttons bound to the settings.
- Validation notices.

**Done when:** Stephanie and Emily can do every task in `README.md` and
`notebooklm/README.md` on the test site without a developer.

### Phase 7a — Survey import (2 weeks)

- The Qualtrics export path on the Import screen.
- Download NotebookLM source.
- Site Health checks: outbound reach to doi.org and Qualtrics, `noindex` on test, and
  HTML caching status.

**Done when:** the Node and PHP importers produce identical records from a scrubbed
test export, and the uploaded file is never stored.

### Phase 7b — Qualtrics sync, if adopted (+1 week)

- Token in `wp-config.php`.
- Column-selective export with recode values.
- "Sync now" plus an optional schedule.
- Drafts, with an email to the editors.

### Phase 8 — Review, launch, cutover (1.5–2 weeks)

- Security review and fixes.
- Cache purging, if the caching check calls for it.
- A screen-reader audit of the public pages and the admin screens.
- CI that builds the versioned plugin zip.
- A runbook for editors and for administrators.
- Launch: install on prod, run the seed import, build the page tree behind the bridge,
  remove the static directory.
- Confirm link previews on prod.
- Retire the React app, the Netlify configuration and the static-hosting files.

**Done when:** gsl.noaa.gov/firehouse is served by the plugin, and every URL from the
bridge resolves to the same content.

### At a glance

| Phase | Work | Size | Visible on the test site |
| --- | --- | --- | --- |
| 0 | Reference, decisions, groundwork | 1–1.5 wk | — |
| 1 | Content model and first data load | 2 wk | Empty plugin, data in wp-admin |
| 2 | Logic in PHP | 1.5–2 wk | — |
| 3 | Design system and frame | 2 wk | Firehouse frame inside GSL's chrome |
| 4 | Display-only pages | 2–2.5 wk | Pages to read and review |
| 5 | Interactive pieces | 3–4 wk | Feature parity |
| 6 | Editing screens | 1.5–2 wk | Editors working on test |
| 7a | Survey import | 2 wk | Import rehearsal |
| 7b | Qualtrics sync (optional) | +1 wk | — |
| 8 | Review, launch, cutover | 1.5–2 wk | → gsl.noaa.gov |
| | **Total** | **~16–20 wk** | plus security-review wait time |

With two developers the work splits after Phase 2: one on Phases 3–5, the other on
Phases 6–7.

---

## Verification

- **Visual and semantic parity.** Screenshots and accessibility-tree snapshots of each
  route, compared against the reference captured in Phase 0.
- **Logic parity.** PHP and TypeScript run the same shared test cases.
- **Importer parity.** The Node and PHP importers run on the same scrubbed export and
  must produce identical records. Real exports contain submitters' emails, so fixtures
  are synthetic.
- **Migration parity.** After the seed import, the content WordPress renders must
  match `normalizeSiteContent()` run over today's JSON.
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
3. What are the slugs of the theme's header and footer template parts?
4. Do administrators customize templates in the Site Editor?
5. Does Cloudflare cache HTML (`cf-cache-status`), and who can purge?

**For the team**

6. Launch the AMS bridge? *Recommended.*
7. Drop dark mode for the WordPress version? A dark Firehouse section between GSL's
   light header and footer will look broken, and dropping it halves the visual and
   contrast testing.
8. Keep Archivo and Public Sans, or use GSL's fonts?
9. Adopt the Qualtrics API sync, and who owns the token on the CSU side?
10. Is working without JavaScript a requirement? If not, the PHP search is dropped (see
    [Interactivity](#interactivity)).

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
