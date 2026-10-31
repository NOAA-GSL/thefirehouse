# WordPress plan — The Firehouse as a GSL WordPress plugin

> **Status: proposal for review, 30 September 2026; updated 1 October after the
> 30 September meeting with Stephanie and Emily, 27 October when the survey moved
> from Qualtrics to Google Forms and GSL's theme was confirmed as a classic theme, and
> 31 October with the look and feel and the decisions of 28–31 October: the survey
> form and the AI synthesis move inside the plugin.**
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
- **What's under one roof.** The whole workflow: the survey form, the AI synthesis,
  drafts, previews, review, publishing and revision history (see
  [Scope](#scope-one-roof)). The Google Form stays the way to submit until the plugin
  launches, and what it collected comes across once through an import screen.
- **How long.** About **20–25 weeks of one developer's time**, plus the wait for each
  security review. Two developers working in parallel after Phase 2 bring it to
  roughly 12–15 calendar weeks.
- **Mid-November and AMS.** The plugin will not be ready for the WPO meeting in
  mid-November or the January 2027 AMS launch. The current React site covers both as a
  static bridge: first on GSL's test site, restricted to GSL staff, for the WPO
  meeting; then at `gsl.noaa.gov/firehouse/`, on the same URLs the plugin will use, so
  no link shared at AMS breaks at cutover.

---

## Decisions so far

| Question | Decision |
| --- | --- |
| Rendering | **Full WordPress rendering, in a plugin.** Not headless, not WordPress serving the React bundle, and not a standalone application linked from GSL's site (considered and set aside in October; see [Why full rendering](#why-full-rendering)). |
| Hosts | GSL's test WordPress site first, gsl.noaa.gov later. Both run GSL's own **classic theme** (`gsl-theme`: PHP header and footer, no Site Editor; confirmed 27 October) and meet the plugin's minimums (WordPress 6.7+, PHP 8.1+). |
| Design | **The Firehouse keeps its own design** (the one in this repository, described in `DESIGN.md`) for everything between GSL's header and footer. Stephanie and Emily chose it on 28 October; ITS approval is still needed (open question 18). The plugin carries the templates, stylesheets and fonts and loads them on Firehouse pages only. Nothing goes into GSL's theme. See [Look and feel](#look-and-feel). |
| URL base | `/firehouse` on both. The path is free on both sites. |
| Place in GSL's menus | A Firehouse item under GSL's Research Portal menu, or failing that a tile on its Decision Support Systems page (Stephanie and Emily, 28 October). GSL's web team adds it. |
| Fields | **WordPress built-in fields**: `register_post_meta`, the Settings API and block attributes. No ACF or Secure Custom Fields. |
| SEO plugin | None installed. The plugin writes its own meta tags and defers to an SEO plugin if one is added later. |
| Caching | Cloudflare. Whether it caches HTML, and how purges work, is still unknown (see [Caching](#caching)). |
| Install path | Security reviews each plugin release; developers install it. |
| WP-CLI | **Not relied on.** Everything, including the first data load, is done in wp-admin. |
| Outbound network | Believed allowed (doi.org). DOI lookups also reach api.crossref.org and data.crosscite.org, where doi.org forwards them. The AI service adds one more host. A Site Health check confirms them on the test site. |
| Survey tool | **A form built into the plugin** (29 October), as a self-contained part of it (31 October). See [The survey form](#the-survey-form). Until the plugin launches, researchers submit through the **Google Form** that replaced Colorado State's Qualtrics, and its responses come across through the Import screen (see [What changes with Google Forms](#what-changes-with-google-forms)). |
| Responses so far | Qualtrics is closed, and every real submission was re-entered through the Google Form (28 October), so the form's Sheet is the complete record. |
| Survey API sync | **Dropped** (29 October). With the form in the plugin there is no Sheet to sync. |
| AI synthesis | **Runs from the plugin** (agreed with Stephanie and Emily, 28 October). An **Analyze results** button sends the published projects and the team's prompt to an approved AI service, and the result waits as a draft for review. Amazon Bedrock is proposed and needs approval under NOAA's AI policy (open question 20). Until then, and as the fallback, the synthesis screen takes NotebookLM's output by paste. See [The AI service](#the-ai-service). |
| Need counts | **Once per project** (28 October): a need mentioned several times in one project counts once. |
| Link previews through Cloudflare | Found out during testing (see [Link previews](#page-titles-descriptions-and-link-previews)). |
| AMS bridge | Probably possible, and recommended, starting on the test site for the mid-November WPO meeting (see [The AMS bridge](#the-ams-bridge)). |
| Hosting outside GSL | **Not allowed** (October 2026 review of the intake documents). The earlier prototype on the former developer's personal domain was taken down in October 2026, and `netlify.toml` is removed from the repository so it is not redeployed. |
| Citations | **None.** The citation generator comes off the site, and project pages are not presented as citable: the site is not a permanent archive, and pages may be withdrawn (October 2026). |
| Plugin code | In this repository at `wp-content/plugins/firehouse/`, the path it has in a WordPress install, the way GSL's own repository keeps its theme. |
| Local development | Built on GSL's own local WordPress (the internal gsl-wordpress repository): its versions and theme. Firehouse runs in a separate local copy that only the developer's computer can reach (see [Local development](#local-development)). |

---

## Scope: one roof

The team's original concept puts the whole workflow in one system: a submission form,
an AI pass, a pending queue, human review, then publishing. The first release builds
all of it (decided 28–29 October):

```
Survey form (/firehouse/submit/) ───▶ draft projects ──preview, Publish──▶ site
Analyze results (AI service) ───────▶ draft synthesis ──diff, Mark reviewed──▶ site
```

- **Researchers submit on gsl.noaa.gov.** The survey form is part of the plugin, and
  each submission arrives as a draft project. Stephanie and Emily's quality check
  happens on the draft's preview, which shows the project page as visitors will see it
  (see [The survey form](#the-survey-form)).
- **The synthesis runs from the dashboard.** Analyze results sends the published
  projects to an approved AI service; the result is a draft that Stephanie and Emily
  compare, edit and mark reviewed (see [The AI service](#the-ai-service)).
- **Two import paths stay,** for what arrives from outside: the Google Form's
  responses, collected until the plugin launches, and pasted AI output, the fallback
  until an AI service is approved.

**What it costs.** The first release now stores personal data (submitters' emails and
job titles) on GSL's server and has a public form that writes to the database. Its
security review is longer, and the form can't open on gsl.noaa.gov until a privacy
review is done and the Paperwork Reduction Act question is answered in writing (open
questions 14 and 19).

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

A fourth shape was considered in October: **a standalone application that GSL's site
links to**, which keeps custom code out of WordPress. It was set aside. The Firehouse
is a whole application, not a widget on a page, and a standalone version would have to
build the sign-in, roles, editing, drafts and revision history that WordPress already
provides. Plugins that share one set of patterns are also easier for one developer to
maintain than separate sites.

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
| `SiteHeader`, `GovBanner`, `SiteFooter` | The Firehouse bar (`firehouse/subnav`) sits under GSL's header. GSL's header and footer take over the gov banner, the NOAA and GSL logos and the footer (see [Look and feel](#look-and-feel)). |
| `ThemeProvider` and its light/dark toggle | Retired. GSL's header has its own toggle, which sets the same `data-theme` attribute Firehouse's dark colors key off (open question 7). |
| `lucide-react` | The same icons as inline SVG from Lucide's static set |
| `landing.json`, `about.json` | Pages built from blocks, locked so editors change words but not layout |
| `projects.json` | `fh_project` posts |
| `topics.json`, `topicSummaries.json` | Four fixed `fh_topic` posts, each carrying its own synthesis |
| `settings.json` | A Firehouse settings screen |
| `scripts/import-survey.mjs`, `scripts/notebooklm-source.mjs` | Rewritten in PHP as admin screens |
| The Google Form (outside this repository) | The plugin's survey form (see [The survey form](#the-survey-form)) |
| The NotebookLM notebook | Analyze results and an approved AI service (see [The AI service](#the-ai-service)); paste-in stays as the fallback |
| Vite, `public/.htaccess`, `public/_redirects` | Retired. The block and view scripts build with `@wordpress/scripts`. (`netlify.toml` was removed in October 2026.) |

The rewrite is smaller than the line counts suggest. The original port already moved
the design system from inline React styles into plain CSS files with `fh-` class
names. The PHP templates emit the same markup and classes, so the stylesheets move
across nearly untouched.

`src/content/types.ts` stays useful: it becomes the specification for the post meta.
A JSON Schema generated from it is passed to `register_post_meta`, so WordPress itself
rejects a malformed value.

### Look and feel

WordPress runs one theme per site, so the plugin doesn't add a second one. It doesn't
need to: for its own pages, a plugin can do what a theme does. It picks the template
with the `template_include` filter and loads its own stylesheets, fonts and scripts on
Firehouse pages only.

A Firehouse page is stacked like this:

1. **GSL's header** (`get_header()`): the gov banner, the NOAA and GSL logos, GSL's
   navigation and its light/dark switch.
2. **The Firehouse bar** (`firehouse/subnav`), on every Firehouse page: the FireHouse
   wordmark, Home, Explore Projects, About and the Submit a Finding button. It is
   today's header without the NOAA and GSL logos and the theme toggle, which GSL's
   header already has. Below 1024px the links fold into a drawer, as they do today.
   Today's header stays pinned while the page scrolls; whether the bar does depends on
   how GSL's header behaves, and is settled in Phase 3.
3. **The Firehouse page** in the current design: the atmosphere background, glass
   panels, Archivo and Public Sans (open question 8), the cards and the ember accent,
   all scoped under `.fh-app` (rule 9).
4. **GSL's footer** (`get_footer()`), with the federal links.

Set aside:

- **Building the Firehouse look into GSL's theme**, switched on under `/firehouse`.
  One feature would be split across two codebases, its markup in the plugin and its
  styles in a theme the web team owns. Every design change would wait on a theme
  release, the two halves would drift apart, and deactivating the plugin would leave
  its styles behind.
- **Full-page Firehouse, without GSL's header and footer.** The plugin could do it
  (its template would still call `wp_head()` and `wp_footer()`), and the pages would
  look exactly like today. But Firehouse would have to rebuild the gov banner and
  footer links, would lose GSL's navigation, and would read as a separate site.
- **A separate theme for `/firehouse`**, through WordPress Multisite or a
  theme-switching plugin. Multisite means converting GSL's whole install, and theme
  switchers are fragile.

### Plugin layout

The plugin sits in this repository at the path it has in a WordPress install:

```
wp-content/plugins/firehouse/
  firehouse.php
  includes/        post types, meta schemas, settings, roles and permissions,
                   validation, the import screen, the AI service client
  survey/          the survey form, self-contained (see The survey form)
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
| Submit | `/firehouse/submit/` | Child page of `firehouse` holding the survey form |
| 404 | anything else under `/firehouse/` | Firehouse "not found" page; GSL's own 404 elsewhere |

These are the React app's routes under a `/firehouse/` base, so a URL shared from the
bridge resolves unchanged under the plugin. The Submit page is new.

- **`fh_project`.** Post status replaces the `published` flag: a draft is unpublished.
  The slug is the current slug. Survey fields are plain-text post meta. The response
  ID is stored and unique, and it is the key every re-import matches on: the Qualtrics
  ResponseId for projects that came from Qualtrics, the response timestamp for those
  that come from Google Forms, and an ID the plugin generates for submissions through
  its own form. The block editor is off for this post type,
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
  submit address, the navigation labels, the survey's question wording, the AI prompt
  and who is emailed about new submissions.
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
| `firehouse/survey-form` | The survey: questions, repeated need entries, validation messages, the thank-you message | Adding and removing entries (works without JavaScript) |

**Submit buttons** are core buttons whose link is bound to the Firehouse settings
through the Block Bindings API. The submit address lives in one field, which is the
job the empty-`href` convention does today. It points at the plugin's Submit page, or
at the Google Form until the plugin's form opens on prod.

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
2. **Private survey answers stay private.** Q2 (email), Q3 (job title) and Q10
   (internal use only) are never shown on the site.
   - **Imports drop them,** as today, along with Qualtrics' IP and location columns
     (Google Forms records neither). An uploaded export handled the default way lands
     in `/wp-content/uploads/`, where it is publicly reachable and carries every
     submitter's email, so the import screen parses the file from PHP's temporary
     upload and discards it.
   - **The plugin's form keeps them,** because Stephanie and Emily need a way to reach
     a submitter. They are stored apart from the survey fields, in meta that is never
     rendered, never sent through the REST API and never copied into revisions, and
     only the Firehouse roles can read them. How long they are kept is for the privacy
     review (open question 19). The form records no IP address.
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
   JavaScript, and the survey form draws its choices from it. Adding a survey option
   (as "After" was in September 2026) becomes a code change that waits on a reviewed
   release. The importer's existing warn-and-continue
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
    links. Under the plugin the survey is a page of its own, but papers, and the
    Google Form until the plugin's form opens, still open in a new tab. Security scanners flag pages that lack it, so the plugin writes it itself
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
- **The survey form on test is marked as a test,** and its submissions stay on test.
  Each site has its own AI service key, set in its own `wp-config.php`.

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
- **A short, documented list of outbound hosts:** doi.org and the two metadata services
  it forwards DOI lookups to (api.crossref.org and data.crosscite.org), the approved
  AI service's endpoint, and the Esri basemap tiles, which the visitor's browser loads
  directly. The server never fetches a link a researcher typed, and a redirect to any
  host off that list is refused.
- **Only public text goes to the AI service:** the published projects and the team's
  prompt. Drafts and private answers never leave GSL.
- **Secrets in `wp-config.php`, not the database.** The AI service key is a constant
  set by developers. It stays out of database backups, and editors never handle it.
- **Personal data comes only from the form,** and stays private (rule 2): submitters'
  emails, job titles and internal-only answers, readable by the Firehouse roles alone.
  No IP addresses.
- **One public endpoint writes to the database:** the form's submission handler. It
  checks a nonce, validates every field on the server, caps field lengths, accepts no
  files, and limits repeat submissions. Spam protection adds no third-party service
  unless the review accepts one.
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
  `.csv`, for what the form collects until the plugin's form opens. It is a PHP port
  of `import-survey.mjs`, adapted to Google's format (see
  [What changes with Google Forms](#what-changes-with-google-forms)): CSV through
  `fgetcsv`, XLSX through `ZipArchive`.
  - Records are matched on the response ID.
  - Every Qualtrics-era submission was re-entered through the Google Form, with a new
    timestamp. The first import pairs each re-entered row with its existing project
    by title and submitter, confirmed by the person importing, and stores the
    timestamp as that project's key, so slugs and editorial fields carry over.
  - New records arrive as drafts. The import result links each one to a preview of
    its project page, so the quality check can happen on the page as visitors will
    see it rather than in spreadsheet cells. Publishing is WordPress's Publish button.
  - Responses missing from a newer export are flagged, not deleted.
  - Paper titles are looked up for DOIs only, keeping the last good metadata when a
    lookup fails. A DOI is also read off a link whose address carries one, as Zenodo
    records do. Today's importer also fetches other links and reads the page's
    citation tags; the port drops that, so the server only contacts the hosts in
    [Security review](#security-review). The dry run lists links without a DOI, and an
    editor can type a title for one on the project's edit screen, which re-imports
    keep. Titles the site already shows for such links carry over in the seed bundle.

WP-CLI commands may exist for developers, but nothing depends on them.

The same area offers **Download NotebookLM source**, a port of `notebooklm-source.mjs`,
for the paste-in fallback. It holds only published projects, so it should be the
notebook's only source.

Next to it is the **synthesis screen**, where the AI pass comes back in:

1. **Run it, or paste it.** **Analyze results** sends the published projects and the
   team's prompt to the AI service (see [The AI service](#the-ai-service)). The
   fallback is to paste the JSON the prompt produced elsewhere. Either way the result
   is the same shape: up to five needs for each topic key (`observe`, `forecast`,
   `warning`, `governance`), each with its text, its mention count and the projects it
   draws on. Projects can be named by slug or by response ID, so a pasted result can
   come from the downloaded source or the team's spreadsheet. Fields the site does not
   display are ignored.
2. **Validate.** A need that names an unknown project, or one that is not published, is
   flagged with the entry quoted: the synthesis may only draw on what the site shows.
   Needs count once per project (decided 28 October), so the screen also flags any need
   whose count differs from the number of projects it lists.
3. **Compare.** The screen shows what changes against the published synthesis: needs
   added, dropped or reworded, rank and count changes, and sources gained or lost. This
   is what the reviewer signs off on.
4. **Save as a draft, then Mark reviewed.** Marking it reviewed publishes it to the
   topic pages and records the reviewer and the date. Until then, visitors keep seeing
   the last reviewed version. Earlier versions stay in the revision history.

No project link is made by hand: the JSON carries them.

### The survey form

The survey moves onto gsl.noaa.gov as part of the plugin (decided 29 October), at
`/firehouse/submit/`. It is built in its own `survey/` folder with its own tests, so it
could later become a separate plugin if other GSL projects want a survey (see
[Later phases](#later-phases)).

- **The same questions as the Google Form.** The choices for regions, fire phases and
  publication status come from `shared/vocabulary.json` (rule 8). The wording of each
  question and its help text is editable on the settings screen, so Stephanie and
  Emily can reword a question without a release; adding a question or a choice is a
  code change.
- **Each submission becomes a draft project.** Survey fields fill the draft exactly as
  an import would, and researchers' words stay verbatim (rule 1). Private answers are
  stored apart (rule 2). The checks the importer applies run on submit, and a
  submission the importer would exclude, such as one without IRB approval or
  exemption, is still kept, as a draft marked with the reason, so nothing a researcher
  sends is lost.
- **Email.** The Firehouse editors are told of each new submission, and the submitter
  gets a confirmation, through GSL's own mail setup.
- **Accessible.** Real fieldsets and labels, an error summary that links to each
  problem, and up to ten repeated need entries that can be added and removed without
  JavaScript. It is checked with a screen reader like the rest.
- **Before it opens on prod:** a privacy review, a written answer on the Paperwork
  Reduction Act (open questions 14 and 19), and the security review's sign-off on the
  submission handler (see [Security review](#security-review)).
- **The switch.** At launch, Submit a Finding moves from the Google Form to the Submit
  page. The Google Form closes after one last import.

### The AI service

The synthesis moves from NotebookLM into the plugin (agreed with Stephanie and Emily,
28 October). NotebookLM is not believed to offer an API inside the NOAA enterprise, so
the plugin calls an approved AI service instead. Amazon Bedrock is proposed; it needs approval under
NOAA's AI policy (open question 20).

- **Analyze results** sends the published projects' text and the team's prompt, and
  the reply lands on the synthesis screen as a draft, with the same validation,
  comparison and **Mark reviewed** step as a pasted result. The prompt lives on the
  settings screen, so Stephanie and Emily can refine it without a release. It runs
  when an editor presses the button; running it on its own each time a project is
  published can come later.
- **Before NotebookLM is retired,** both run on the same published projects, and
  Stephanie and Emily judge whether the new output matches in substance and nuance.
  Changing tools halfway through would otherwise change the results.
- **The fallback stays.** Until the service is approved, and whenever it is
  unavailable, the team runs the prompt in NotebookLM and pastes the result in.

### What changes with Google Forms

The survey moved from Colorado State's Qualtrics to Google Forms (recorded
27 October), and the Google Form stays the way to submit until the plugin's form opens.
Responses land in a Google Sheet linked to the form, and that Sheet, downloaded, is
what the Import screen reads. Most of today's importer carries over: private columns
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
  title and submitter match an existing project. A timestamp is only to the second, so
  the dry run never guesses on a match. Two rows with the same timestamp stop both from
  importing, and moving one by a second in the Sheet resolves it. A row whose timestamp
  matches a project but whose title or submitter differs is shown beside that project,
  and updates it only when the person importing confirms that row: it may be a
  correction made in the Sheet, or a different response.
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

Four form settings keep this working while the form is live:

1. "Allow response editing" is off.
2. The form is open to respondents without a NOAA Google login. A form in an
   organization's Google Workspace can be restricted to that organization's users.
   *Confirmed 28 October.*
3. There is no file-upload question. One makes every respondent sign in to Google.
   *Confirmed 28 October: the only link question takes a typed address.*
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
   still links to the Qualtrics survey, which is closed. The Google Form stays the way
   to submit through AMS, where the team's talk invites researchers to submit.
6. **Remove the citation generator** from the project page, as agreed in October, so
   the public site does not invite citations.

About 2–3 days of work, plus about a day for the test-site copy, not counted in the
plugin estimate.

**Responses now arrive through the Google Form,** so before the next import
`import-survey.mjs` needs the changes in
[What changes with Google Forms](#what-changes-with-google-forms): columns found by
title, the timestamp as the key, and label matching. The re-entered Qualtrics-era
submissions also have to be paired with their existing records, by title and
submitter, so their slugs and editorial fields carry over. That is about 1–2 more
days, using a test response downloaded from the live form. It also gives Phase 7a's
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
- The Firehouse bar (`firehouse/subnav`), including whether it stays pinned under
  GSL's header (see [Look and feel](#look-and-feel)).
- Stephanie and Emily see the frame on the test site (or in screenshots, if the VPN
  gets in the way), in light and dark and at phone width, before Phase 4 builds pages
  on it. The header is the part of the design that changes most, so this is the
  cheapest point to change it.

**Done when:** an empty Firehouse page on the test site sits inside GSL's header and
footer, with no styles leaking in either direction, and Stephanie and Emily have
signed off on the frame.

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

### Phase 7a — Google Form import (2 weeks)

- The Google Forms path on the Import screen: column matching, the timestamp key,
  label matching, pairing the re-entered Qualtrics-era submissions, and each new draft
  linked to its preview.
- Download NotebookLM source, for the paste-in fallback.
- Site Health checks: outbound reach to doi.org, api.crossref.org, data.crosscite.org
  and the AI service, `noindex` on test, and HTML caching status.

**Done when:** the Node and PHP importers produce identical records from a synthetic
Google Forms export, a reworded question imports after one column choice, and the
uploaded file is never stored.

### Phase 7b — The survey form (2–3 weeks)

- The Submit page and the `firehouse/survey-form` block, in the `survey/` folder (see
  [The survey form](#the-survey-form)).
- Drafts from submissions, private answers stored apart (rule 2), and the importer's
  checks on submit.
- The submission handler's protections: nonce, server-side validation, length caps,
  repeat-submission limits and spam protection.
- Editable question wording on the settings screen.
- Emails to the editors and the submitter.

**Done when:** a test submission on the test site arrives as a draft that matches an
import of the same answers, its private answers are readable only by the Firehouse
roles and absent from the REST API, and the form passes a screen-reader check. It
opens on prod only once the privacy review and the written PRA answer are in hand.

### Phase 7c — Analyze results (1–1.5 weeks)

- The AI service client: one host, a key in `wp-config.php`, only published text sent
  (see [The AI service](#the-ai-service)).
- The Analyze results button and the prompt on the settings screen.
- The comparison with NotebookLM: both run on the same published projects, and
  Stephanie and Emily judge the results.

**Done when:** Analyze results produces a draft synthesis on the test site that passes
the screen's validation, and Stephanie and Emily have signed off that it matches
NotebookLM's in substance. Until the service is approved, the paste-in path is what
ships.

### Phase 8 — Review, launch, cutover (1.5–2 weeks)

- Security review and fixes.
- Cache purging, if the caching check calls for it.
- A screen-reader audit of the public pages and the admin screens.
- CI that builds the versioned plugin zip.
- A runbook for editors and for administrators.
- Launch: install on prod, run the seed import and a last Google Form import, build
  the page tree behind the bridge, remove the static directory, point Submit a Finding
  at the Submit page, and close the Google Form.
- Confirm link previews on prod.
- Retire the React app and the static-hosting files.

**Done when:** gsl.noaa.gov/firehouse is served by the plugin, and every URL from the
bridge resolves to the same content.

### Later phases

Each is its own plugin release with its own security review. None is scheduled: each
starts when its trigger is met. The AI synthesis and the survey form, once later
phases here, are now in the first release (Phases 7b and 7c), and the Google Sheets
sync is dropped.

**L1 — Reporting and export.**

- *Trigger:* someone names who will use it, and for what.
- *Cheapest first:* a print stylesheet (about a day), so the landing and topic pages
  save cleanly to PDF from the browser. PDFs generated on the server run into the
  no-third-party-PHP-libraries rule.
- *Not recommended:* a live "last submission" ticker. At about 50 submissions a year
  it would mostly show how long ago the last one was, and it would publish submitters'
  names.

**L2 — A survey plugin for other GSL projects.**

- *Trigger:* another GSL project wants a survey like this one.
- *What changes:* the `survey/` folder becomes its own plugin, and the Firehouse plugin
  uses it. It is built self-contained so this is a move, not a rewrite.

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
| 7a | Google Form import | 2 wk | Import rehearsal |
| 7b | The survey form | 2–3 wk | Test submissions arriving as drafts |
| 7c | Analyze results | 1–1.5 wk | A synthesis run from the dashboard |
| 8 | Review, launch, cutover | 1.5–2 wk | → gsl.noaa.gov |
| | **Total** | **~20–25 wk** | plus security-review, privacy-review and PRA wait time |
| L1–L2 | Later phases, each when triggered | up to ~2 wk each | — |

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
- **Synthesis comparison.** Analyze results and NotebookLM run on the same published
  projects, and Stephanie and Emily sign off that the results match in substance.
- **The survey form.** A submission and an import of the same answers produce the same
  draft. Private answers never appear on a page, in the REST API or in a revision.
  Malformed and repeated submissions are refused without a server error.
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

- A static site with essentially no attack surface. The plugin adds logins, and a
  public form that stores personal data.
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
9. ~~Adopt an API sync for the survey?~~ *Not now (30 September); dropped
   29 October, when the form moved into the plugin.*
10. Is working without JavaScript a requirement? If not, the PHP search is dropped (see
    [Interactivity](#interactivity)).

**Added after the 30 September meeting**

11. *(GSL's web team)* Can the bridge go on the test site, restricted to GSL staff, by
    mid-November?
12. *(GSL's web team)* What do the Project Intake Request and the Technical Assessment
    Worksheet still need for this site? Dmitri started them.
13. ~~*(Stephanie and Emily)* Which counting rule is canonical?~~ *Once per project
    (28 October): a need mentioned several times in one project counts once. Their
    27 October rerun already used it, and the prompt in `notebooklm/README.md` now
    does too. The 17 September draft on the site still counts every entry, until the
    synthesis is rerun.*
14. *(Stephanie and Emily, with NOAA's PRA clearance officer)* Does the survey count
    as a NOAA information collection under the Paperwork Reduction Act? It asks
    non-federal researchers the same questions on NOAA's behalf. The tool doesn't
    change the question: it is about what is collected and for whom, not which tool
    hosts the form. Moving the form onto gsl.noaa.gov makes it more pointed. The
    current view (29 October) is that clearance isn't needed, but that isn't confirmed
    in writing, and the form can't open on prod until it is.
15. *(Stephanie and Emily)* Should a project that answers "Project did not require IRB
    review" be published? The importer treats that answer as no approval (see the
    appendix).

**Added after the switch to Google Forms (27 October)**

16. *(Stephanie and Emily)* Which Google account owns the form and its response Sheet:
    NOAA's Google Workspace, Colorado State's, or another? It decides where personal
    data lives until launch, and who deletes the Sheet once its responses are
    imported. If it is NOAA's, the intake documents should say so, because that is
    what settles the concern that the survey sat outside NOAA. (The form is open to
    respondents outside NOAA, confirmed 28 October.)
17. ~~*(Stephanie and Emily)* Is the Qualtrics survey closed?~~ *Yes (28 October).
    Every real submission was re-entered through the Google Form, so no last
    Qualtrics import is needed; the re-entered rows are paired with their existing
    projects instead (see [the Import screen](#no-wp-cli-the-import-screen)).*

**Added 28–31 October**

18. *(ITS)* Is The Firehouse's own design approved for the pages between GSL's header
    and footer, in place of GSL's standard page styles (see
    [Look and feel](#look-and-feel))? Stephanie and Emily chose it on 28 October.
19. *(Privacy review)* What may the survey form keep, and for how long? It stores
    submitters' emails, job titles and internal-only answers so the team can follow
    up (rule 2). The review decides how long they are kept and when they are deleted.
20. *(ITS)* Which AI service is approved for the synthesis? Amazon Bedrock is
    proposed. A colleague of Stephanie's received OAR clearance for similar work, and
    she has the contacts that colleague worked with. Until a service is approved, the
    synthesis is pasted in from NotebookLM (see [The AI service](#the-ai-service)).

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
