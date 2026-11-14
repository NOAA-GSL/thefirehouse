# WordPress plan — The Firehouse as a GSL WordPress plugin

> **Status: the plan being built, last updated 13 November 2026.** Phases 0–4 are built
> on the local copy of GSL's WordPress, and the frame is signed off; Phase 4's "Done
> when" waits on Stephanie and Emily's review on that local copy (see Where each phase
> is reviewed), and Phase 5 has started. This
> document is the current plan: the decisions made so far, the architecture
> they lead to, and a phased plan from the current `main` branch to a plugin running on
> gsl.noaa.gov. It changes through pull requests, so earlier versions are in its git
> history.

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
- **Who edits.** Stephanie and Emily, in wp-admin, through a FireHouse Editor role
  that can change Firehouse content and nothing else on the site. Content changes need
  no developer, no terminal and no deploy.
- **What's under one roof.** The whole workflow: the survey form, the AI synthesis,
  drafts, previews, review, publishing and revision history (see
  [Scope](#scope-one-roof)). The Google Form stays the way to submit until the plugin
  launches, and what it collected reaches WordPress in the first data load.
- **How long.** About **18–23 weeks of one developer's time**, plus the wait for each
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
| Rendering | **Full WordPress rendering, in a plugin.** Not headless, not WordPress serving the React bundle, and not a standalone application linked from GSL's site (considered and set aside in October; see [Why full rendering](#why-full-rendering)). |
| Hosts | GSL's test WordPress site first, gsl.noaa.gov later. Both run GSL's own **classic theme** (`gsl-theme`: PHP header and footer, no Site Editor; confirmed 27 October) and meet the plugin's minimums (WordPress 6.7+, PHP 8.1+). |
| Design | **The Firehouse keeps its own design** (the one in this repository, described in `DESIGN.md`) for everything between GSL's header and footer. Stephanie and Emily chose it on 28 October; ITS approval is still needed (open question 6). The plugin carries the templates, stylesheets and fonts and loads them on Firehouse pages only. Nothing goes into GSL's theme. See [Look and feel](#look-and-feel). |
| Fonts | **Archivo and Public Sans for now** (Eric, 3 November), self-hosted by the plugin. A later switch to GSL's Nunito Sans and Montserrat should stay a small change: the pages read only the font tokens, and the plugin loads its font files in one place. |
| Dark mode | **Kept** (Eric, 3 November), following GSL's light/dark switch. The switch sets `data-theme="dark"` on `<html>`, the attribute Firehouse's dark colors key off, so a Firehouse page is never dark between a light header and footer. The visual and contrast checks run in both themes. |
| URL base | `/firehouse` on both. The path is free on both sites. |
| Place in GSL's menus | A Firehouse item under GSL's Research Portal menu, or failing that a tile on its Decision Support Systems page (Stephanie and Emily, 28 October). GSL's web team adds it. |
| Fields | **WordPress built-in fields**: `register_post_meta`, the Settings API and block attributes. No ACF or Secure Custom Fields. |
| SEO plugin | None installed. The plugin writes its own meta tags and defers to an SEO plugin if one is added later. |
| Caching | **Cloudflare doesn't cache HTML** on either site (`cf-cache-status: DYNAMIC` on both, checked 1 November), so publishing shows up immediately and the plugin needs no purge step (see [Caching](#caching)). |
| Install path | Security reviews each plugin release; developers install it. |
| WP-CLI | **Not relied on.** Everything, including the first data load, is done in wp-admin. |
| Outbound network | Believed allowed (doi.org). DOI lookups also reach api.crossref.org and data.crosscite.org, where doi.org forwards them. The AI service adds one more host. A Site Health check confirms them on the test site. |
| Survey tool | **A form built into the plugin** (29 October), as a self-contained part of it (31 October). See [The survey form](#the-survey-form). Until the plugin launches, researchers submit through the **Google Form** that replaced Colorado State's Qualtrics; it belongs to Emily's account in NOAA's Google Workspace. Its responses go into the site's files through the existing import script, as today, and reach WordPress in the seed bundle at launch (see [Importing the Google Form until launch](#importing-the-google-form-until-launch)). |
| Responses so far | Qualtrics is closed, and every real submission was re-entered through the Google Form (28 October), so the form's Sheet is the complete record. |
| Survey API sync | **Dropped** (29 October). With the form in the plugin there is no Sheet to sync. |
| AI synthesis | **Runs from the plugin** (agreed with Stephanie and Emily, 28 October). An **Analyze results** button sends the published projects and the team's prompt to an approved AI service, and the result waits as a draft for review. The service will likely be Cloudflare Workers AI or Amazon Bedrock, and either needs approval under NOAA's AI policy (open question 7). The model is still open (1 November) and is chosen by checking its output. Until then, and as the fallback, the synthesis screen takes NotebookLM's output by paste. See [The AI service](#the-ai-service). |
| Need counts | **Once per project** (28 October): a need mentioned several times in one project counts once. |
| Link previews through Cloudflare | Found out during testing (see [Link previews](#page-titles-descriptions-and-link-previews)). |
| AMS bridge | Probably possible, and recommended, starting on the test site for the mid-November WPO meeting (see [The AMS bridge](#the-ams-bridge)). |
| Hosting outside GSL | **Not allowed** (October 2026 review of the intake documents). The earlier prototype on the former developer's personal domain was taken down in October 2026, and `netlify.toml` is removed from the repository so it is not redeployed. |
| Citations | **None.** The citation generator comes off the site, and project pages are not presented as citable: the site is not a permanent archive, and pages may be withdrawn (October 2026). |
| Plugin code | In this repository at `wp-content/plugins/firehouse/`, the path it has in a WordPress install, the way GSL's own repository keeps its theme. |
| Local development | Built on GSL's own local WordPress (the internal gsl-wordpress repository): its versions and theme. Firehouse runs in a separate local copy that only the developer's computer can reach (see [Local development](#local-development)). |

---

## Scope: one roof

The first release puts the whole workflow in one system: a submission form, an AI
pass, a pending queue, human review, then publishing.

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
- **Two ways in stay for what comes from outside.** The seed bundle brings today's
  projects, including every Google Form response collected before launch, once. Pasted
  AI output is the fallback until an AI service is approved.

**What it costs.** The first release stores personal data (submitters' emails and job
titles) on GSL's server and has a public form that writes to the database. Its
security review is longer, and the form can't open on gsl.noaa.gov until a privacy
review is done and the Paperwork Reduction Act question is answered in writing (open
questions 12 and 14).

---

## Architecture

### Why full rendering

PHP renders every page, in a plugin. The site is maintained inside GSL's WordPress by
the people who run it, so it should behave like the rest of it:

- server-rendered pages, real 404s and per-page link previews;
- a codebase in the stack GSL's WordPress developers and security reviewers already
  work in.

The cost is rewriting the page layer, which is where most of the estimate goes.

The alternatives, and why not:

- **Headless.** WordPress holds the content and the React site stays elsewhere. The
  site would still need hosting of its own, and wouldn't behave like GSL's other pages.
- **WordPress serves the React app.** WordPress owns routing and `<head>`; React
  renders the page body. Two stacks to maintain, and pages that render only in the
  browser.
- **A standalone application that GSL's site links to.** It keeps custom code out of
  WordPress, but The Firehouse is a whole application, not a widget on a page, and a
  standalone version would have to build the sign-in, roles, editing, drafts and
  revision history that WordPress already provides. Plugins that share one set of
  patterns are also easier for one developer to maintain than separate sites.

### Where today's code goes

| Today | Under the plugin |
| --- | --- |
| Pages (~1,900 lines of TSX) | PHP render templates, one per block |
| Design-system components (~2,300 lines of TSX) | PHP partials that emit **the same markup and class names** |
| All CSS (~5,000 lines: tokens, components, pages) | Carried over nearly verbatim, scoped under a wrapper class |
| `derive.ts`, `format.ts`, `normalize.ts` | Rewritten in PHP. |
| `citation.ts` and the citation tabs | Not ported: the site offers no citations (see [Decisions so far](#decisions-so-far)). |
| `search.ts` | Kept in both languages: PHP renders the first, already-filtered page, and JavaScript filters as you type. |
| `RegionMap.tsx` | Leaflet stays, driven by an Interactivity API store |
| Mobile menu, scrollable need cards, quick-look modal | Interactivity API. The modal becomes a native `<dialog>`, which traps focus and handles Escape itself. |
| React Router, `ContentProvider`, the adapters, `RouteFocusManager` | Retired. Full page loads make them unnecessary. |
| `SiteHeader`, `GovBanner`, `SiteFooter` | The Firehouse bar (`firehouse/subnav`) sits under GSL's header. GSL's header and footer take over the gov banner, the NOAA and GSL logos and the footer (see [Look and feel](#look-and-feel)). |
| `ThemeProvider` and its light/dark toggle | Retired. GSL's header has its own toggle, which sets the same `data-theme` attribute Firehouse's dark colors key off (open question 9). |
| `lucide-react` | The same icons as inline SVG, drawn by the site's `Icon` into `assets/icons/` (`npm run plugin:assets`) |
| `landing.json`, `about.json` | Pages built from blocks, locked so editors change words but not layout |
| `projects.json` | `fh_project` posts |
| `topics.json`, `topicSummaries.json` | Four fixed `fh_topic` posts, each carrying its own synthesis |
| `settings.json` | A Firehouse settings screen |
| `scripts/import-survey.mjs` | Kept until launch, adapted to the Google Form, then retired: the plugin's form takes over (see [Importing the Google Form until launch](#importing-the-google-form-until-launch)) |
| `scripts/notebooklm-source.mjs` | Rewritten in PHP as **Download NotebookLM source**, for the paste-in fallback |
| The Google Form (outside this repository) | The plugin's survey form (see [The survey form](#the-survey-form)) |
| The NotebookLM notebook | Analyze results and an approved AI service (see [The AI service](#the-ai-service)); paste-in stays as the fallback |
| Vite, `public/.htaccess`, `public/_redirects` | Retired. View scripts are plain ES modules, and the blocks' editor script (`assets/js/block-editor.js`) is plain JavaScript, with no build step; `@wordpress/scripts` comes in if Phase 6's editing controls need one. The one generated script is the explorer's search module (`assets/search/search.js`), which `npm run plugin:assets` bundles from `search.ts` with esbuild. |

The rewrite is smaller than the line counts suggest. The design system is already in
plain CSS files with `fh-` class names, not inline React styles. The PHP templates emit
the same markup and classes, so the stylesheets move across nearly untouched.

`src/content/types.ts` stays useful: it becomes the specification for the post meta.
`npm run schema` generates `shared/project.schema.json` and `shared/topic.schema.json`
from it, and the plugin registers each field from them. One field departs from the
type: a need's `projects` holds post IDs, not slugs (rule 5). Nothing needs the post
types in the REST API, so they aren't in it, and no draft or field can be read or
written through it. For the same reason WordPress never applies the schemas on its
own, so the plugin checks every write of a field against them, with WordPress's own
validator and for each value's exact type.

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
   header already has. Below 1024px the links fold into a drawer, as they do today;
   without JavaScript they stay in the bar, on a row of their own.
   Today's header stays pinned while the page scrolls; whether the bar does depends on
   how GSL's header behaves, and is settled in Phase 3. *Settled: GSL's header scrolls
   away, so the bar stays pinned, as today's header does.*
3. **The Firehouse page** in the current design: the atmosphere background, glass
   panels, Archivo and Public Sans (open question 10), the cards and the ember accent,
   all scoped under `.fh-app` (rule 9). It ends with the disclaimer.
4. **GSL's footer** (`get_footer()`), with the federal links.

The alternatives, and why not:

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
  includes/        post types, meta schemas, settings, roles and permissions, the
                   checks at save and import (which call domain/), the seed import
                   screen, the query, visibility and verbatim filters, the frame and
                   its head tags (Head), block registration, the markup the blocks
                   render (the bar, the 404, the explorer, the project, topic,
                   landing and About pages, and what those two share
                   (Page_Blocks), icons, buttons, topic tags, the synthesis note);
                   later, the AI service client
  survey/          the survey form, self-contained (see The survey form)
  domain/          PHP versions of the vocabularies, validation (normalize), derive,
                   format and search, with the email shape, the JSON reading and
                   the JavaScript rules they share (Email_Shape, Json, Js)
  blocks/<name>/   block.json · render.php · view.js and view.asset.php when it is
                   interactive (Interactivity API) · an editor preview once editors
                   place it
  blocks/blocks-manifest.php
                   every block.json in one array, generated (npm run plugin:blocks)
  templates/       frame.php, the one template every Firehouse page uses
  patterns/        landing and About layouts, locked to text-only edits (Phase 6; today
                   the Import screen creates both pages from custom blocks)
  assets/          tokens and component CSS, icons, logos, fonts, the link-preview
                   image, the blocks' script for the block editor, the site's search
                   as a module for the explorer's script; GACC boundaries with the
                   coverage map (Phase 5)
  shared/          vocabulary.json, the project and topic schemas, the email pattern,
                   the icon allowlist and test cases, read by PHP and JS
  tests/           PHPUnit tests, run without WordPress through Brain Monkey
  uninstall.php    removes the role and the capabilities; the content stays
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
| 404 | anything else under `/firehouse/` | Firehouse "not found" page; GSL's own 404 elsewhere. A shortened project or topic address still redirects to the post whose slug starts with it. |

These are the React app's routes under a `/firehouse/` base, so a URL shared from the
bridge resolves unchanged under the plugin. The Submit page is new.

- **`fh_project`.** Post status replaces the `published` flag: a draft is unpublished.
  The slug is the current slug. Survey fields, the title included, are post meta stored
  exactly as submitted; the post's own title is an escaped copy for WordPress's lists.
  The response ID is stored and unique, and it is the key every re-import matches on:
  the Qualtrics ResponseId for projects that came from Qualtrics, the response
  timestamp for those that come from Google Forms, and an ID the plugin generates for
  submissions through its own form. There is no block editor or title box, because a
  project has no free-form body and its title is meta. No one adds a project in the
  admin.
- **`fh_topic`.** Exactly four posts, with the topic key as the slug, created by the
  Import screen. Adding, deleting or trashing one in the admin is blocked by
  capability, and its key and status can't be changed: adding a fifth topic is a
  schema change, not an edit. Each topic stores its synthesis as meta (`topNeeds`,
  `updatedAt`, `sourceCount`, `model`, `reviewedBy`). Each need points to its projects
  by **post ID**; the ID is turned back into a slug when the page renders.
- **Landing and About.** Ordinary WordPress pages built from blocks. Their layouts are
  to be locked to text-only editing (`contentOnly`), with a short allowed-blocks list
  and no Custom HTML block; nothing locks the pages' layout yet (Phase 6). *Their sections are
  custom blocks whose attributes hold the words
  (Blocks, below), which `contentOnly` doesn't show to editors; how they're edited is
  decided in Phase 6.*
- **The disclaimer.** It ends every Firehouse page, above GSL's footer (`disclaimer`
  in `settings.json`). The Phase 1 import doesn't read it. Phase 4 decides whether the
  plugin keeps it as a setting or as the templates' own copy (*decided: the templates'
  own copy, `Frame::disclaimer()`, which a test holds to `settings.json`*); whether an editor can
  reword the cleared text is decided with the editing screens in Phase 6. Wherever it is
  kept, the templates render it inside the theme's `main`, after the content, never
  from `wp_footer` or a footer widget, which land after GSL's footer.
- **Settings.** A FireHouse → Settings screen holds the site name, the submit label, the
  submit address and the navigation labels; later, the survey's question wording
  (Phase 7a), the AI prompt (Phase 7b) and who is emailed about new submissions.
- **Navigation.** The three nav items (Home, Explore Projects, About) and the Submit
  button are fixed in code, because the routes are structural; only their labels are
  editable. GSL's menus are edited under Appearance → Menus, which needs a capability
  that opens every menu on the site, so the FireHouse Editor role doesn't get it.

### Blocks

Core blocks carry copy. Custom blocks exist only where data is computed or input is
handled, or where a section's markup isn't core blocks' (the landing and About pages,
below):

| Block | Renders | Interactive |
| --- | --- | --- |
| `firehouse/subnav` | FireHouse mark, the three nav items, Submit button | Mobile menu |
| `firehouse/hero` | Landing hero (eyebrow, heading and body are editable) | — |
| `firehouse/stats` | Live counts. The editor picks the source and writes the label; the number is always computed. | — |
| `firehouse/top-needs` | Four topic cards, the synthesis note, "Where these needs come from" | — |
| `firehouse/submit-band` | The landing page's closing band: heading, body and the Submit button | — |
| `firehouse/coverage-map` | GACC map, region list, the selected region's projects | Yes |
| `firehouse/explorer` | Search, filters, sort, project grid, quick look | Yes |
| `firehouse/project` | Project page: header, abstract, takeaways, needs, papers, at-a-glance panel, prev/next | Scrollable need cards |
| `firehouse/topic` | Topic page: ranked needs with sources, what the area covers, other areas | — |
| `firehouse/about-intro` | The About page's introduction: eyebrow, heading and what the hub is | — |
| `firehouse/about-sections` | The About page's body, holding the four sections below in order | — |
| `firehouse/about-mission` | Why it exists: a heading and its paragraphs | — |
| `firehouse/about-audiences` | Who it serves: the section's words and a card for each audience, with its icon | — |
| `firehouse/about-process` | How it works: the numbered steps and the links after them | — |
| `firehouse/about-team` | Who is behind it: the team's mailbox, and a card for each member with a portrait from the Media Library | — |
| `firehouse/about-cta` | The About page's closing band: heading, body and the Submit button | — |
| `firehouse/not-found` | The Firehouse 404 | — |
| `firehouse/survey-form` | The survey: questions, repeated need entries, validation messages, the thank-you message | Adding and removing entries (works without JavaScript) |

**Submit buttons** are core buttons whose link is bound to the Firehouse settings
through the Block Bindings API. The submit address lives in one field, which is the
job the empty-`href` convention does today. It points at the plugin's Submit page, or
at the Google Form until the plugin's form opens on prod. *Until the editing screens
(Phase 6), the landing page's Submit buttons are inside its own blocks, which read the
setting for a link stored empty; the closing band is the `firehouse/submit-band`
block, since its markup (a labelled section, the glow) isn't core blocks'. The About
page is built the same way (11 November): its parts' markup (labelled sections, icon
cards, numbered steps, the closing band) isn't core blocks', so each is a block whose
attributes hold its words, and its audience cards are a list in one block's
attributes, as the landing page's counts are, rather than a `firehouse/audience` block
each. Whether these blocks stay, edited through their own controls, or give way to
core blocks in a locked pattern is decided in Phase 6.*

**Editor previews** are rendered on the server, so each block's markup is written once,
in PHP. *Until Phase 6 (13 November): the editor shows each block as a read-only box of
what the page stores in it, not a preview, since a preview would blur the page's own
words with what FireHouse adds (Phase 6's landing and About item).*

### Interactivity

Every control belongs to a `GET` form, or is a link to the address it would make:

- **Without JavaScript,** submitting reloads the page with the same `?q=`, `?phase=`,
  `?region=`, `?year=`, `?type=` and `?sort=` parameters the React explorer uses today.
- **With JavaScript,** the Interactivity API enhances those same controls in place:
  live filtering, and the URL updated with `history.replaceState`.

Links people have already shared keep working either way.

- **Explorer.** Search runs in the browser, with the site's own `search.ts` bundled as a
  module the plugin ships, and in PHP, for the first render and for no-JS. Both are
  tested against one shared file of queries and expected results
  (`shared/cases/search.json`), and what each works out for the page (the sort's
  options, the applied filters' labels, and the words after the results count)
  against another (`shared/cases/explorer.json`). *Eric chose the browser over asking
  the server for each change (13 November): results are instant and work on bad
  wifi, as the site's are. The page carries each public project's searchable text for
  it, about 14 KB gzipped at eight projects, and about six times as much at 48; the
  view script and the search module add about 5 KB and 4 KB gzipped.*
- **Map.** Leaflet's ES module build loads in the block's view script. The region list
  is a set of real buttons in the same kind of form, and the boundary GeoJSON ships as
  a plugin asset.

If working without JavaScript turns out not to be required, the PHP search can be
dropped. That saves about a week and removes the only logic that exists twice. *It is
required (open question 11, Eric, 13 November).*

---

## Rules that must survive the port

Each of these is a decision the current code makes deliberately. Each is easy to lose
in a rewrite.

1. **Researcher text stays verbatim.** WordPress runs `wptexturize` (curly quotes,
   converted dashes) and `convert_smilies` over titles and over everything that passes
   through `the_content`, including block output. Left on, every project page would quietly rewrite what
   researchers submitted. Turn both off on Firehouse pages. Store survey text as
   plain-string meta, and give editors no rich-text control over it. *On Firehouse
   pages since Phase 4 (`includes/class-verbatim.php`), with `capital_P_dangit` off too,
   which would recapitalize a researcher's "Wordpress".*
2. **Private survey answers stay private.** Q2 (email) and Q3 (job title) are never
   shown on the site. Nor is Q10 (how results were shared outside the research group),
   which was marked internal-only in Qualtrics and stays private until Stephanie and
   Emily say otherwise (open question 15).
   - **The import script drops them,** as today, along with Qualtrics' IP and location
     columns (Google Forms records neither). It runs on the developer's computer, so a
     response export never reaches GSL's server. The seed bundle it feeds holds no
     private answers, only what the site publishes (researchers' names and
     organizations, and the team's names, biographies and mailbox address), and the
     Import screen refuses a bundle with anything shaped like an email address but a
     team work address in the About page's team mailbox field.
   - **The plugin's form keeps them,** because Stephanie and Emily need a way to reach
     a submitter. They are stored apart from the survey fields, in meta that is never
     rendered, never sent through the REST API and never copied into revisions, and
     only the Firehouse roles can read them. How long they are kept is for the privacy
     review (open question 14). The form records no IP address.
3. **Unpublished records never leave the server.** Today `normalize.ts` keeps
   unpublished projects, and only the pages filter them out, so staged records ship in
   the JavaScript bundle. As WordPress drafts they are never rendered or sent to a
   visitor.
4. **Survey fields and editorial fields stay separate.** The seed import matches on
   the response ID and overwrites survey fields only. The editorial fields (`published`,
   `summary`, `slug`, `publicationStatus`, `geoNote`, `fullRecordUrl`) live in
   `scripts/survey-editorial.json` today; in WordPress they belong to editors, and a
   re-import never touches them. So do a project's geography states, which the survey
   doesn't ask for. Survey fields appear read-only on the edit screen, labelled as
   coming from the survey.
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
   JavaScript, and the survey form draws its choices from it. Adding a survey option,
   such as a new fire phase, is a code change that waits on a reviewed release: the
   form can't offer the new option until then.
9. **CSS is walled off in both directions.**
   - Outward: every rule moves under an `.fh-app` wrapper (`scripts/scope-css.ts`): the
     tokens on `:root`, the `body` styles and the fixed atmosphere layer, the `*` and
     `img, svg` rules and the `:focus-visible` ring. A theme condition stays on
     `<html>`. The build stops on any rule that could match outside the wrapper. One
     rule is the exception, by design: `frame.css` hides the theme's hero and its
     footer's mission section on Firehouse pages (`body.fh-page`), and
     `scripts/frame-css.test.mjs` allows only that `display: none`.
   - Inward: the theme's global styles would reach Firehouse markup, so inside the
     wrapper a small reset puts back the browser's margins and padding and the fonts of
     headings, paragraphs and lists (`src/styles/inward-reset.css`). The theme's other
     element styles, on links and buttons for example, lose to Firehouse's own rules.
     The reset beats only element selectors, so the wrapper sits outside the theme's
     content wrappers.
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
    Google Form until the plugin's form opens, still open in a new tab. Security
    scanners flag pages that lack it, so the plugin writes it itself rather than rely
    on any site-wide filter.
12. **The FireHouse Editor role has no `unfiltered_html`,** so anything pasted into a
    paragraph block is sanitized. Biographies are still rendered as written, because
    `wptexturize` is off on these pages (rule 1).
13. **A malformed query string is ignored, never an error.** `parseProjectQuery`
    drops any parameter it doesn't recognize. PHP is less forgiving: `?phase[]=x`
    arrives as an array, and string functions given an array throw, so a scanner's
    probe becomes a server error. Every parameter the explorer and the map read is
    checked to be a string first. *The plugin's project and topic query variables
    are dropped the same way, on any address (`Query::request`).*

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
  and CI on that minimum. Plugin Check runs inside the local site, and in CI against a
  throwaway WordPress.
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
- **Private personal data comes only from the form,** and stays private (rule 2):
  submitters' emails, job titles and Q10 answers, readable by the Firehouse roles
  alone. No IP addresses. The seed bundle carries only what the site publishes.
- **One public endpoint writes to the database:** the form's submission handler. It
  checks a nonce, validates every field on the server, caps field lengths, accepts no
  files, and limits repeat submissions. Spam protection adds no third-party service
  unless the review accepts one.
- **Repeat limits without recording addresses.** Limits on repeat submissions usually
  key on the visitor's IP address, which the form doesn't record. Two options go to
  security: a Cloudflare rate-limiting rule on the form's POST, which keeps addresses
  out of WordPress entirely, or a salted hash of the address held for an hour in a
  transient and never attached to the submission. Behind Cloudflare, PHP sees the
  visitor's address only if the server restores it from Cloudflare's header; otherwise
  every visitor shares a handful of Cloudflare addresses and one limit.
- **No custom REST routes.** Nothing the pages or screens do needs one.
- **A one-page design summary goes to security in Phase 0:** data stored, endpoints,
  capabilities, outbound hosts, secrets. Surprises at the final review are the
  expensive kind.
- **Every release passes `PHPCS` (WordPress coding standards) and Plugin Check**
  before it is submitted. CI runs both on every change to the plugin, so a failure
  shows up on the pull request that caused it rather than at release time.

### No WP-CLI: the Import screen

One **FireHouse → Import** screen, available only to a separate import capability,
loads the **seed bundle**: the six content files of `src/content/data/` in one JSON
file, which `npm run seed` writes. One file was chosen over a zip on 1 November:
nothing needs the About portraits until the About page is built in Phase 4, and they
come through the Media Library then. This is how test, and later prod, get their first
load.

A dry run first lists what would change, every problem and any notes, and nothing is
written until the person confirms. Running it again updates the same posts rather than
duplicating them: projects take the bundle's survey fields and keep their editorial
fields (rule 4), and topics and settings are written only when they don't exist yet,
so a reviewed synthesis is never overwritten. The same goes for the Firehouse pages:
the import creates the landing page from the bundle's landing content, as its blocks,
only when there's no `firehouse` page in any status but the trash, and the About
page under it the same way (decided by Eric, 10 November; done 11 November), once the
landing page exists, or this run has created it and read it back as planned. A
`firehouse` page in the trash, or anything else holding the address
(an unattached Media Library file named `firehouse` takes it from a new page), stops
the import, and a page WordPress saves at another address anyway is deleted. The About
page's team portraits are uploaded to the Media Library by an administrator before the
import, a step in the runbook, rather than shipped in the plugin. The import finds each
as the newest image titled as the bundle's `photo` (`stephanie-hoekstra`,
`emily-wells`), which WordPress sets from the file name; the dry run names the image
and who uploaded it, for whoever imports to check; and the page keeps it by its ID
(decided by Eric, 12 November, after the security review found that a lookup by title
as the page renders let anyone who can upload media change a portrait). Until an
editor can choose a portrait in Phase 6, one uploaded after the import changes
nothing, and a card with none shows the person's initials. The slug can't be used:
WordPress gives every upload a slug no other post has, so where a staff profile page
already uses `stephanie-hoekstra`, as on the copy of GSL's site this was built
against, the upload becomes `stephanie-hoekstra-2`. The plugin checks the bundle
itself: a field it doesn't store in a project, topic or synthesis, or anything shaped
like an email address but a team work address in the About page's team mailbox field,
stops the import; the landing and About pages keep only the text their blocks use.

The uploaded file itself is never saved. After a dry run with no problems, the parts of
the content the import writes wait in a transient keyed to the person who uploaded it,
usable for 30 minutes; the confirmation plans again and imports only if nothing would
differ from what was shown. Opening the screen afresh, or importing, clears it.

Survey responses don't come through this screen. Until launch the existing import
script turns them into the JSON files (see
[Importing the Google Form until launch](#importing-the-google-form-until-launch)),
so the seed bundle holds every response the site shows by then, and projects arrive as
they are on the site. A response the script excludes, such as one without IRB approval
or exemption, isn't in the bundle; the Sheet remains its record. After launch,
responses arrive through the plugin's own form.

WP-CLI commands may exist for developers, but nothing depends on them.

The same area offers **Download NotebookLM source**, a port of `notebooklm-source.mjs`,
for the paste-in fallback. It holds only published projects, so it should be the
notebook's only source.

Next to it is the **synthesis screen**, where the AI pass comes back in:

1. **Run it, or paste it.** **Analyze results** sends the published projects and the
   team's prompt to the AI service (see [The AI service](#the-ai-service)). The
   fallback is to paste the JSON the prompt produced elsewhere. Either way the result
   is the same shape: up to five needs for each topic key (`observe`, `forecast`,
   `warning`, `governance`), each with its text, its project count and the projects it
   draws on. Projects can be named by slug or by response ID, so a pasted result can
   come from the downloaded source or the team's spreadsheet. Fields the site does not
   display are ignored.
2. **Validate.** A need that names an unknown project, or one that is not published, is
   flagged with the entry quoted: the synthesis may only draw on what the site shows.
   Needs count once per project, so the screen also flags any need whose count differs
   from the number of projects it lists, and any topic area whose needs don't run by
   project count, highest first, as the About page says. A project unpublished later
   lowers the counts the pages show, so publishing or unpublishing a project checks
   the order again (decided with the screen in Phase 6: flag it, or sort at render).
3. **Compare.** The screen shows what changes against the published synthesis: needs
   added, dropped or reworded, rank and count changes, and sources gained or lost. This
   is what the reviewer signs off on.
4. **Save as a draft, then Mark reviewed.** Marking it reviewed publishes it to the
   topic pages and records the reviewer and the date. Until then, visitors keep seeing
   the last reviewed version. Earlier versions stay in the revision history.

No project link is made by hand: the JSON carries them.

### The survey form

The survey is part of the plugin, at `/firehouse/submit/`. It is built in its own
`survey/` folder with its own tests, so it could later become a separate plugin if
other GSL projects want a survey (see [Later phases](#later-phases)).

- **The same questions as the Google Form.** The choices for regions, fire phases and
  publication status come from `shared/vocabulary.json` (rule 8). The wording of each
  question and its help text is editable on the settings screen, so Stephanie and
  Emily can reword a question without a release; adding a question or a choice is a
  code change.
- **Each submission becomes a draft project.** Survey fields fill the draft exactly as
  an import would, and researchers' words stay verbatim (rule 1). Private answers are
  stored apart (rule 2). The checks the import script applies run on submit, and a
  submission it would exclude, such as one without IRB approval or
  exemption, is still kept, as a draft marked with the reason, so nothing a researcher
  sends is lost.
- **Paper titles are looked up for DOIs only** when a submission arrives, keeping the
  last good metadata if a lookup fails. A DOI is also read off a link whose address
  carries one, as Zenodo records do. Today's import script also fetches other links
  and reads the page's citation tags; the plugin doesn't, so the server only contacts
  the hosts in [Security review](#security-review). The draft lists links without a
  DOI, and an editor can type a title for one. Titles the site already shows carry
  over in the seed bundle.
- **Email,** through GSL's own mail setup. The Firehouse editors are told of each new
  submission with a link to its draft; the message carries no private answers. The
  submitter gets a confirmation in fixed text, with nothing they typed in it, so the
  form can't be used to send spam to someone else's address.
- **One box per entry.** The Google Form asks for takeaways, needs and links as
  numbered or comma-separated lists in one box each. The plugin's form gives every
  entry its own box: two to four takeaways, up to eight needs (each "Need for…" ending
  in "Recommend…", as the form's guidance asks) and the links.
- **Accessible.** Real fieldsets and labels, an error summary that links to each
  problem, and entries that can be added and removed without JavaScript. It is checked
  with a screen reader like the rest.
- **The same notices as the Google Form:** that submissions may be shown on the site,
  and how AI is used on them, naming the approved AI service rather than NotebookLM.
- **Before it opens on prod:** a privacy review, a written answer on the Paperwork
  Reduction Act (open questions 12 and 14), and the security review's sign-off on the
  submission handler (see [Security review](#security-review)).
- **The switch.** At launch the Google Form closes, its last responses go through the
  import script into the seed bundle, and Submit a Finding moves to the Submit page.

### The AI service

The synthesis runs from the plugin. NotebookLM is not believed to offer an API inside
the NOAA enterprise, so the plugin calls an approved AI service. It will likely be
Cloudflare Workers AI or Amazon Bedrock, and either needs approval under NOAA's AI
policy (open question 7). The model isn't chosen yet (1 November): Llama 4, another
open model, or a commercial one. Either service fits the client as planned: one
host, and one key in `wp-config.php`.

- **Analyze results** sends the published projects' text and the team's prompt, and
  the reply lands on the synthesis screen as a draft, with the same validation,
  comparison and **Mark reviewed** step as a pasted result. The prompt lives on the
  settings screen, so Stephanie and Emily can refine it without a release. It runs
  when an editor presses the button; running it on its own each time a project is
  published can come later.
- **The model is chosen by its output.** Before NotebookLM is retired, each
  candidate runs the team's prompt on the same published projects as the reviewed
  NotebookLM synthesis, and Stephanie and Emily judge whether it matches in substance
  and nuance. Each draft also gets the checks any synthesis gets: every need traced
  to the entries it cites, its count equal to the projects it names, and wording no
  stronger than the entries. A model may need the prompt adjusted, and the old and
  new prompts run on the same projects before switching. Changing tools or models
  halfway through would otherwise change the results, so each synthesis records the
  model that wrote it, and the site's AI notice names it.
- **The fallback stays.** Until the service is approved, and whenever it is
  unavailable, the team runs the prompt in NotebookLM and pastes the result in.

### Caching

Cloudflare sits in front of both sites, and on both it **doesn't cache HTML**: pages
come back with `cf-cache-status: DYNAMIC` (checked 1 November). Publishing shows up
immediately, and the only job is versioned CSS and JS filenames, which WordPress
already does.

If that changes (Phase 7b's Site Health check keeps reading the header), a new
project, a count, a synthesis update or a hidden project would stay stale until the
cache expired. A hidden project matters most: one whose IRB approval is cleared would
stay on its page, the archive, the sitemap, every topic page that cites it, and the
previous and next links on the two project pages either side of it. Publishing would then have to purge, through
Cloudflare's own WordPress integration or the plugin calling Cloudflare's purge API
with a token limited to purging, and the token would be a security-review item.

The explorer's HTML depends on its address's query string (`?q=`, the filters, the
sort) and on nothing else, not the visitor. If HTML caching comes, the cache key must
hold the whole query string as sent: a rule that ignores it would serve one search's
results at every address. Each address is its own entry, so a purge has to reach all
of them (everything, or by prefix). Every explorer address, whatever its query, carries
each public project's searchable text in its state, so a hidden project's text would
stay in every cached entry, not only those whose search found it. Copies others keep
(an archive's snapshot of the explorer) hold it too, so a request to remove a
withdrawn project names the explorer's address as well as the project's.

Whatever Cloudflare does with other pages, **the Submit page is never cached.** A cached copy would carry an expired
nonce, and real submissions would start failing within a day. The page is sent with
WordPress's no-cache headers, and if Cloudflare is set to ignore them, the web team
adds a rule that bypasses the cache for that path.

### Page titles, descriptions and link previews

- **Core already provides** `<title>`, the canonical link, and the XML sitemap, which
  includes projects and topics automatically.
- **Projects and topics stay out of GSL's site search box** (Eric, as GSL's web team,
  9 November). WordPress's search reads a post's title and body, and a project keeps all
  but its title in fields, so it would be found by title words only, with no summary.
  The Firehouse's landing and About pages are ordinary pages and stay in site search,
  and the explorer searches every field. Staying out of search also takes them out of
  WordPress's guess at a missing address elsewhere on the site, so a missing GSL
  address can't redirect to a researcher's project. *Done: `exclude_from_search` on
  both types, which also leaves them out of any query for posts of `any` type. The
  sitemap still lists the ones a visitor may see, and a search that names the type
  still finds them.*
- **The plugin adds** the description and the Open Graph and Twitter tags, on Firehouse
  pages only. A shared project link then previews with the project's own title and
  abstract. This closes the gap noted in `PLAN.md` §2.2.
- **If GSL installs an SEO plugin later,** the Firehouse plugin detects it (a fixed list;
  the `firehouse_head_tags` filter covers any other) and steps aside: it sets no title
  and prints none of its tags, so there are no duplicates, and the SEO
  plugin's own settings decide what a shared FireHouse link previews with. Handing
  it the project's abstract would need that plugin's filters; nothing does yet.
- **Cloudflare may block the preview bots.** Scripted requests to both sites currently
  receive a Cloudflare challenge page. If Slack's, Teams' or LinkedIn's preview bots
  receive the same, no tag the plugin writes will reach them. This is checked with the
  first release on the test site, and again on prod before launch, because the two may
  be configured differently.

---

## The AMS bridge

The React app already builds for a sub-path, and its routes match the plugin's URL
map. Launching it at `gsl.noaa.gov/firehouse/` for AMS means links shared there keep
working after the cutover.

**Mid-November first, on the test site.** The same build serves the WPO meeting from
GSL's test site, which is visible only on GSL's internal network (VPN or in the office;
28 October). Stephanie and Emily can open it on the VPN, which is enough for that
meeting. It
also keeps the demo on GSL's infrastructure, the only place the site may run: hosting
outside GSL is not allowed (see [Decisions so far](#decisions-so-far)).
Build that copy with the test site's address as `VITE_SITE_URL` and with
`VITE_NOINDEX=1`, which marks every page `noindex` (the README's Hosting section has
both build commands). If the test site isn't ready in time, the fallback is running
the build on the presenter's laptop and sharing the screen.

1. Build with `VITE_BASE_PATH=/firehouse/` and `VITE_SITE_URL=https://gsl.noaa.gov`.
2. Serve the build as a static directory at `/firehouse`.
   - If the origin server is **Apache**, uncomment `RewriteBase /firehouse/` in
     `public/.htaccess`.
   - If it is **nginx**, a `try_files` block in the server configuration does the same
     job.
3. **Turn the gov banner and footer back on.** A static page does not receive GSL's
   header and footer. *Done.*
4. Finish the remaining items in the README's "Before this goes public" list: hero
   image, synthesis review, the team mailbox, the disclaimer's clearance and a sitemap.
   The synthesis review matters most: today's draft covers 4 of the 8 published
   projects. Stephanie and Emily reran it across all 8 on 27 October; it goes onto the
   site once their reviewed table arrives. A `robots.txt` in the build can't help:
   under `/firehouse/` crawlers never read it, so the test-site copy uses the
   `noindex` tag instead.
5. **Point Submit a Finding at the Google Form,** the way to submit through AMS, where
   the team's talk invites researchers to submit. *Done.*
6. **Remove the citation generator** from the project page, so the public site does
   not invite citations. *Done.*
7. **Stop the topic pages scrolling sideways on phones** (see the appendix). *Done.*
8. **Retake the reference** with `npm run reference` once these changes are in. That
   set is what the plugin is built against. *Retaken with steps 3, 5, 6 and 7; retaken
   again once the rerun synthesis is in, since it changes the topic pages.*

About 2–3 days of work, plus about a day for the test-site copy, not counted in the
plugin estimate.

**Cutover with no downtime.** Build the WordPress `firehouse` page tree on prod while
the static directory still shadows it; editors preview it through its page-ID URL.
Removing the directory then hands the URLs to WordPress immediately.

### Importing the Google Form until launch

Until the plugin launches, researchers submit through the Google Form, and the
developer's import script, `scripts/import-survey.mjs`, turns its response Sheet into
the site's JSON files, as it did for Qualtrics. The plugin never reads the Sheet: the
JSON reaches WordPress in the seed bundle, and the script retires at launch. It runs on
the developer's computer and drops the email, job-title and Q10 answers before
anything is written (rule 2).

The script was written for Qualtrics, so it needs these changes before the next import
(about a day, using a test response from the live form or a made-up row in its
format). They wait until the form has a genuinely new response: every response so far
re-enters a Qualtrics submission the site already shows. The form's response count
says when. The last Qualtrics export had 8 responses, so a count above 8 means
something new has arrived.

- **Columns by question title.** Google's header row is the question text, not a
  stable ID like Qualtrics' `Q13`, so the script finds each column by its title, and a
  reworded question means updating its map.
- **Lists in one box.** Takeaways (Q15, two to four) and needs (Q16, up to eight) are
  each one box the respondent numbers, and links (Q17) are one box separated by commas,
  where Qualtrics had a box per entry. The script splits numbered entries and commas.
  Each need is asked to start "Need for…" and end "Recommend…", which also marks where
  one entry ends. An answer that doesn't split cleanly is a warning, and its text is
  kept whole for the person importing to split.
- **The timestamp as the key.** There is no response ID. The Timestamp column holds
  still only while **"Allow response editing" stays off**: an edited response gets a
  new timestamp and would import as a new project. Two rows with the same timestamp, or
  a timestamp match whose title or submitter differs, stop the import for a person to
  resolve rather than being guessed at.
- **Labels, not codes.** Google exports the label the respondent clicked. The ten GACC
  labels end in their code ("Northern California (ONCC)"), which the script reads; the
  four other regions are matched by their full label in `shared/vocabulary.json`.
  Checkbox answers are matched against the known labels rather than split on commas,
  because "Northwest -- OR, WA (NWCC)" contains one.
- **Skipping the re-entered submissions.** Every Qualtrics-era submission was
  re-entered through the Google Form with a new timestamp. The script skips those rows,
  listed by timestamp, and the projects they duplicate keep their Qualtrics response
  IDs as their key.
- **Excluded responses stay in the Sheet.** A response without IRB approval or
  exemption stays out of the site's files, as today, and the script lists it in its
  report. There is one so far, and open question 13 may make it publishable (see the
  appendix). If the team wants an excluded response in WordPress, an editor enters it
  through the Submit page after launch, which flags it the same way. A row with no
  project title is still dropped outright: there is nothing to make a project from.
- **Tests first, and a check in CI.** The import already stops on two columns that
  claim one question, two rows that land on one record, a missing title, IRB or year
  column, and anything shaped like an email address (the PR #6 reviews). Tests with
  made-up rows pin those stops before the rewrite, so none is lost in it. A column
  whose title asks for an email, a job title or how results were shared is refused
  rather than mapped. CI also checks `src/content/data/projects.json` and
  `notebooklm/` for email-shaped text, which catches a hand edit or a merge the
  script never saw.

The form's settings that keep this working, all confirmed: "Allow response editing"
off; open to respondents without a NOAA Google login; no file-upload question, which
would make every respondent sign in to Google; and region and fire-phase options worded
as in `shared/vocabulary.json`, which takes its labels from the form.

---

## Phased plan

**Where each phase is reviewed (Eric, 6 November).** Each release goes through GSL's
security review, so the plugin reaches the test site near the end, not phase by phase.
Until then, Stephanie and Emily review each phase with Eric on his local copy of
GSL's WordPress (the `docker-compose.yml` stack, with GSL's theme), and a phase's
"Done when" that names the test site is met there. The checks that depend on the
test site's own setup wait for the first release: outbound network, Cloudflare and
link-preview bots, caching, and `noindex`. So does the check that Stephanie and Emily
can do every editing task on their own, without a developer (Phase 6), since on the
local copy Eric is always there.

### Phase 0 — Reference, decisions, groundwork (1–1.5 weeks)

- **Capture the reference.** Screenshots and accessibility-tree snapshots of every
  route from the React build, in each theme that will be kept. The React site is the
  specification the plugin has to match. *Done: `npm run reference` captures them (see
  `reference/README.md`). The committed snapshots are a baseline from before the bridge
  changes and are retaken with them (bridge step 8).*
- **Add shared test cases.** Unit tests for `normalize`, `derive`, `search` and
  `format`, written as data files (input and expected output) that the PHP tests will
  read later. *Done: 201 cases in `shared/cases/`, run by `npm test` and in CI.*
- **Move the vocabularies** into `shared/vocabulary.json`, with each option's label as
  the Google Form shows it, and generate a JSON Schema from `types.ts`. *Done: `npm run
  schema` writes `shared/project.schema.json`, and CI fails if it is out of date.*
- **Repair the seed data.** Fix the two double-encoded strings in `projects.json` (see
  the appendix) before the seed bundle is built. *Done.*
- **Scaffold the plugin** and a local WordPress for developers (see
  [Local development](#local-development)). *Done: the empty plugin passes PHPCS,
  PHPUnit and Plugin Check.*
- **Check `cf-cache-status`** on gsl.noaa.gov and the test site. *Done: `DYNAMIC` on
  both, so HTML isn't cached (see [Caching](#caching)).*
- **Send the design summary** to security. *Sent 1 November. Its questions are in open
  question 2.*

**Done when:** the reference is captured, the shared cases pass in TypeScript, and an
empty plugin passes PHPCS and Plugin Check.

### Phase 1 — Content model and first data load (2 weeks)

- The `fh_project` and `fh_topic` post types.
- Meta registered from the schemas, with revisions enabled.
- The URL map and rewrites.
- The fix for the `year` query variable.
- The FireHouse Editor role, with the permission filter that limits page editing to
  the `firehouse` page tree.
- The settings screen.
- The Import screen with the seed bundle and dry run.

*Built 1 November and working in the local WordPress: the post types, meta, rewrites,
`year` fix, role, settings screen and Import screen are in `includes/`, and the seed
bundle loads with every slug intact. The same load runs on the test site with the
first release (see Where each phase is reviewed, above).*

**Done when:** the seed bundle loads on the test site with every slug intact, and the
editor role cannot edit anything outside Firehouse.

### Phase 2 — Logic in PHP (1.5–2 weeks)

PHP versions of derive (counts, region expansion for national studies, synthesis
info), format, validation and search/filter. *Derive, format and validation are in
`domain/` and pass their shared cases (started before Phase 1, on 1 November, while
Phase 0's security answers were pending). Where the TypeScript would crash on a
missing or malformed block, the PHP refuses it with a clear error. Search followed on
13 November, once open question 11 was answered (see Phase 5).*

**Done when:** the PHP versions pass the same shared test cases as the TypeScript
ones.

### Phase 3 — Design system and frame (2 weeks)

- CSS moved into the plugin and scoped under `.fh-app`, with the inward reset. *Done
  3 November: `npm run plugin:css` writes `assets/css/firehouse.css` from the site's own
  build, which scopes every stylesheet with `scripts/scope-css.ts` and stops on any rule
  that could match outside the wrapper. CI checks the file is current, and
  `plugin:css` stops on anything in it that reaches the whole page or loads from
  elsewhere, as both PostCSS and a real browser read it. The React site renders inside `#root.fh-app`, and its reference
  screenshots match the unscoped build pixel for pixel. With the theme's stylesheet
  added to the static pages, no element's computed style changes, across the 15
  properties checked, at rest.*
- Icons as SVG, logos, and fonts. Archivo and Public Sans are kept (open question 10)
  and self-hosted, loaded from one place in the plugin. Every page reads its fonts
  from three tokens (`--font-display`, `--font-display-expanded`, `--font-body`), so
  switching to GSL's fonts means pointing those at Montserrat and Nunito Sans and not
  loading the files. GSL loads Montserrat at one weight, so the display weights would
  need checking then. *Fonts done 6 November: `scripts/fetch-fonts.mjs` saved Google's
  own files and licence in `assets/fonts/`, with where each came from in
  `SOURCES.json`, and their rules in `assets/css/fonts.css`, which `firehouse.css`
  leaves out and `scripts/fonts.test.mjs` checks. The static build loads the same file,
  and its screenshots match the build that loaded Google's stylesheet, pixel for pixel.
  Google serves no Archivo Expanded, so the stats and the wordmark fall back to
  Archivo, as before. The frame loads `fonts.css` before `firehouse.css` and keeps it
  in `assets/css/`, where its `../fonts/` paths resolve.*
  *The frame versions each stylesheet, `fonts.css` included, by the plugin's version
  and the file's modified time. The font files `fonts.css` points at carry no version,
  and `fetch-fonts.mjs` names them `<family>-<style>-<subset>.woff2`, so a refreshed
  file could be served stale from a cache: before the next refresh, the script should
  put each file's hash in its name. Preloading the latin Public Sans file moves to Phase 4.
  (12 November: the refresh that dropped the italics saved the same bytes. The script
  now stops rather than save new bytes under a name already saved, so the hashed
  names are owed before the first refresh that changes a file, and the frame's
  preload, `Frame::PRELOADED_FONTS`, takes the hashed name with them.)*
  *Icons and logos done 6 November: `npm run plugin:assets` draws every icon in the
  site's registry through its own `Icon` component into `assets/icons/<name>.svg`
  (since 13 November, one PHP array, `assets/icons/icons.php`: see Phase 4's weight),
  and copies the images `BrandMark` imports into `assets/logos/`; CI checks both are
  current. The templates and Phase 4's pages place them as the site does:*
  - *An icon is inlined only by a name in `Vocabulary::icons()` (`shared/vocabulary.json`,
    which the tests hold to the registry and the icons), never a path built from a
    block attribute or content: the About cards name their icons. What it adds (size,
    class) is escaped with `esc_attr()` and goes in at the `<svg ` the icons start
    with. `tests/IconsTest.php` holds every icon to `shared/icon-svg.json`.*
  - *An icon is inlined, never an `<img>`, so its `currentColor` stroke follows the
    text colour, forced colours included. It gets what `Icon` adds: its size in place of
    Lucide's 24×24, `aria-hidden="true"`, `focusable="false"`, its class and
    `display: inline-flex; flex-shrink: 0`. A topic tag's thicker stroke (2.25) changes
    `stroke-width` only, keeping `currentColor`.*
  - *Every icon is decorative. An icon-only control takes its name from the control:
    visually hidden text (close; the Firehouse bar's menu, which keeps one name while
    `aria-expanded` carries its state) or `aria-label` (clear search, remove filter). The `external-link` icon travels with
    its hidden "(opens in a new tab)".*
  - *A logo beside the site's name in text has `alt=""` (the Firehouse bar, the
    footer). The hero's wordmark has its alt text and is always the `-on-dark` image.
    A logo that follows the theme is both images with the `fh-brand__img--light` and
    `--dark` classes, which `firehouse.css` swaps from `[data-theme]` with no
    script. Both get the same `alt`, and `display: none` hides the other; not
    `BrandMark`'s `aria-hidden` on the dark image, which would hide a non-empty name
    in the dark theme.*
- Firehouse page templates, chosen with the `template_include` filter, that call
  GSL's `get_header()` and `get_footer()`, with full-width sections between them.
  GSL's theme is classic, so no Site Editor copy of a template can override the
  plugin's. *Frame done 6 November (`includes/class-frame.php`,
  `templates/frame.php`, `assets/css/frame.css`). In the local copy of GSL's
  WordPress:*
  - *No style leaks either way. Outside the wrapper, no computed style changes when
    Firehouse's stylesheets switch on, in either theme.*
  - *Inside it, the static site's About, explorer, topic and project markup, dropped
    into the real template, matches the static site at rest and with keyboard focus:
    845 elements and 42 focus rings, in both themes.*
  - *The frame decides from the query WordPress ran. The request's own variables let
    an empty `?fh_topic=` claim any page, and miss a draft's preview (found in
    review).*
  - *Both checks ran by hand: they need GSL's theme, which isn't in this repository.
    Phase 4 runs them again on the real pages.*
  - *How each item below landed is marked on it.*

  Found while scoping the CSS, and settled here against the real theme:
  - The atmosphere layer is fixed to the viewport and joins whatever stacking context
    holds the wrapper. Inside a theme wrapper that forms one, it paints over the
    footer, hiding links that stay focusable; elsewhere it shows through transparent
    parts of the header and footer, or hides behind an opaque wrapper. It has to be
    contained to the wrapper's box, and the contrast audits re-run against the result.
    *Done: `frame.css` clips the wrapper, so the layer stays in the Firehouse area.
    Inside it every computed colour matches the static site; the live audit re-runs
    on the real pages in Phase 4.*
  - The project dialog's overlay sits below a theme header with a higher stacking
    order, close button included. A native `<dialog>` opened with `showModal()`
    (rule 10) sits above everything and makes the rest of the page inert. *Moved to
    Phase 5 with the explorer, whose quick look it is, and done there (13 November).*
  - The skip link hides by sitting above its wrapper, which works only at the top of a
    page. It belongs before the theme's header, hidden by clipping. *The theme prints
    no `wp_body_open` hook, so nothing can go before its header. The skip link sits at
    the top of the wrapper instead, clipped with it until focused, and leads to the
    content, past the Firehouse bar. Its target spans the clipped wrapper's sides and
    bottom, so its focus ring is drawn inside it. Moving the link before the header
    needs that hook in GSL's theme (the README's checklist).*
  - The wrapper sits outside the theme's content wrappers, whose rules beat the reset,
    and `firehouse.css` loads after the theme's stylesheet, so ties go to Firehouse.
    *Done.*
  - The wrapper's `min-height: 100vh` pushes the footer down on a short page. *Done:
    `frame.css` lets it end with its content.*
  - The theme's header can print a full-height hero background, positioned over the
    top of whatever follows, unless the page switches it off. Firehouse pages switch
    it off, or the Firehouse bar and hero sit under it (seen in the 6 November
    preview). *Done: the theme offers no switch for a plugin's archive or missing
    pages, so `frame.css` hides it on Firehouse pages only (the `fh-page` body class).*
  - The theme's footer prints a mission section (Our Mission, Research Areas) above
    its own footer, with a per-page switch on the edit screen for pages and posts.
    Firehouse pages don't need it; GSL's footer stays (Eric, 6 November). *Done: hidden
    by the same rule as the hero, which reaches the project archive too.*
  - WordPress answers a missing address by redirecting to its best guess at a post
    anywhere on the site, so a missing `/firehouse/about/` landed on the site's own
    About page. *Done: under `/firehouse/`, only a project's or topic's address gets a
    guess, the plugin's own, which looks among its own type, so a shared link cut short
    still finds its project (rule 5). Any other missing address there gets the frame's
    not-found page.*
  - The wrapper sets `color-scheme`, which nothing else on the theme's page does, and
    a `[hidden]` rule that wins over Firehouse's own `display` values before the
    Interactivity API starts setting `hidden`. *Done in `frame.css`.*
  - The computed-style check re-runs inside the real template, with WordPress's own
    styles, on hover and focus as well as at rest. *Done at rest and with keyboard
    focus, above; hover isn't checked.*
- Dark colors keyed to GSL's light/dark toggle (kept: open question 9). *The scoped CSS
  already keeps the theme condition on `<html>`, where the toggle sets it. The frame
  sets `color-scheme` on the wrapper, and in the real theme GSL's switch turns the
  header, the Firehouse area and the footer dark together. The contrast audits in both
  themes run on the real pages in Phase 4.*
- The Firehouse bar (`firehouse/subnav`), including whether it stays pinned under
  GSL's header (see [Look and feel](#look-and-feel)). *Done 6 November
  (`blocks/subnav/`, `includes/class-subnav.php`, `class-blocks.php` and
  `class-icons.php`):*
  - *GSL's header scrolls away, so the bar stays pinned at the top, as today's header
    does, and below WordPress's toolbar for someone signed in.*
  - *The wrapper is now a `div`, holding the skip link, the bar and the page's one
    `main`. The bar is a `div` too, since the theme's header is the page's banner.*
  - *Its view script is plain JavaScript with no build step: WordPress maps its
    `@wordpress/interactivity` import, which `view.asset.php` declares.
    `@wordpress/scripts` comes in if Phase 6's editing controls need a build (the
    read-only editor boxes of 13 November didn't).*
  - *Without JavaScript, below 1024px the links stay in the bar on a row of their own,
    with the Submit button after them (`@media (scripting: none)`), so the menu never
    strands them. The bar is then too tall to pin, so it scrolls with the page. That covers JavaScript switched off, not a script that fails
    to load; keying the drawer on a class the script sets would cover both, at the cost
    of the bar's links showing, then folding, as each phone page loads.*
  - *Going Back to a page the browser kept in memory closes the drawer, as a route
    change does on the site.*
  - *Checked in the local copy of GSL's WordPress at 1280, 390 and 320px:*
    - *the bar fits the window and stays pinned on a long page;*
    - *the current link is marked;*
    - *the menu opens and closes, and Escape returns focus to its button;*
    - *with JavaScript off, the links stay in the bar;*
    - *the page raises no PHP notices or script errors.*
  - *From the Section 508 review:*
    - *content scrolled into view by focus or the skip link stops below the bar;*
    - *the open drawer scrolls within the window, so its last item can be reached on a
      short screen, and closes when focus leaves the bar or the window widens past
      1024px;*
    - *the menu button is "FireHouse menu" whether open or shut, since the theme's
      header has a menu button of its own;*
    - *in forced colours the current link keeps a distinct underline.*

    *The drawer, widening and forced-colours fixes are in `SiteHeader.css`, so the site
    has them too.*
- Stephanie and Emily see the frame on the test site (or in screenshots, if the VPN
  gets in the way), in light and dark and at phone width, before Phase 4 builds pages
  on it. The header is the part of the design that changes most, so this is the
  cheapest point to change it. *Signed off 6 November: Emily approved the screenshots
  from the 6 November preview, as Eric relayed. Eric takes that as the frame's
  sign-off. The screenshots show the landing, About, project and topic pages inside
  GSL's header and footer, in light and dark at desktop width and light at phone
  width, with the theme's hero removed, as the frame now does. They predate the
  plugin's template: the preview put the static site's pages into GSL's page by
  script. The template matches them in the computed-style check above. The Firehouse
  bar, built after the preview, has yet to be shown to Stephanie and Emily.*

**Done when:** an empty Firehouse page on the local copy of GSL's WordPress sits inside
GSL's header and footer, with no styles leaking in either direction, and Stephanie and Emily have
signed off on the frame.

### Phase 4 — Display-only pages (2–2.5 weeks)

- The project page (including need cards), topic pages, About, the 404
  and the landing page without the map.
- The disclaimer at the end of every Firehouse page, and where the plugin keeps it.
  *Done: the templates' own copy (above).*
- *Groundwork done 9 November:*
  - *Researchers' text stays verbatim (rule 1).*
  - *The 404 is the site's, as the `firehouse/not-found` block.*
  - *`npm run plugin:compare` checks each built page's main landmark against the
    reference's accessibility snapshot, at desktop and phone width, on the local
    WordPress. The 404 matches; the screenshots were compared by eye.*
- *Topic pages done 9 November, as the `firehouse/topic` block: the four match the
  reference's accessibility snapshots at both widths, and its screenshots by eye in
  both themes. A need names only the projects a visitor may see: published, without
  a password, and with IRB approval or exemption, the projects the site's normalize
  step keeps. One that named a project since unpublished is recounted, as that step
  does, and the "of 8 projects" total counts the same projects.*
- *Done 10 November (`includes/class-visibility.php`): a project a visitor may not
  see is off the public side of the site, not only the topic pages. A published
  project without IRB approval or exemption, or with a password, gets the 404 at its
  address and at `?p=` and `?fh_project=`, and stays out of the not-found guess, the
  project feed (`?post_type=fh_project&feed=rss2` still answers, though the type's
  pretty feed addresses are off), the sitemap, embeds and oEmbed, menus, the
  redirects WordPress builds from a post ID, a changed slug's redirect, and the
  previous and next links in a page's head. Checked on the local copy by request, with
  one project's approval cleared and then restored.*
  - *The rule is applied to what each query finds (`posts_results`), so the main
    query, feeds, embeds, the sitemap and oEmbed's lookup of a pretty address all
    inherit it. WordPress builds some redirects from `?p=`, `?page_id=` and
    `?attachment_id=` without a query, on a missing address and on a feed, and sends
    a revision's ID in any of the three to the post it's a revision of; oEmbed reads
    a `?p=` address's ID directly; menus and the old-slug redirect read the post
    directly. Each has its own filter.*
  - *Someone who may edit the project still sees it on its own page, as WordPress
    shows them a draft in preview, and nowhere else: not in a list, a feed, an embed
    or oEmbed, any of which a cache could hand on to a visitor.*
  - *Not covered: a query for IDs only, which WordPress answers before the filter
    (today only the sitemap index's page count); and an embed another post cached
    while the project was public, until that post is saved again.*
  - *WordPress limits a list before the filter, so a page of the archive or the feed
    can come back short, and `?paged=2` still pages both. The explorer (Phase 5) should
    ask for public projects itself, as the topic pages do. It does (13 November):
    `Explorer_Page::projects()` asks with `Store::public_projects_query()` and checks
    each with `Store::is_public()`.*
  - *It lands before the survey form or any screen that can publish a project. Today
    nothing in the plugin can publish a project without approval: the import drops
    one, so every draft it leaves is approved. A project's edit screen has no form for
    its fields yet, but it is WordPress's own screen, with the Publish box, so an
    editor can publish one of those drafts there. Whether publishing a project without approval
    should be refused outright, as the plugin refuses a topic's status change, is open
    (rule 6 would put the check where the editor is).*
- *Project pages done 10 November, as the `firehouse/project` block: the eight match
  the reference's accessibility snapshots at both widths. The previous and next links
  follow the explorer's order among the projects a visitor may see, as the site's
  pager does among its published ones, so a hidden project drops out of its
  neighbours' links.*
  - *A need card is measured by the block's view script (Interactivity API), as the
    site's is: one whose text runs past its cap scrolls, and only then gets a tab stop,
    the `group` role and a name from its need's lead. Checked against the site in short
    windows, where the reference's cards don't overflow, by tightening the cap in the
    page on both: the same cards scroll, with the same names and edge shadows, as they
    scroll and as the window grows. Until the script has measured a card,
    `frame.css` keeps its cap off, so without JavaScript, or if the script fails to
    load, the whole entry shows rather than scrolling out of a keyboard's reach. The
    cost: a card longer than its cap shows whole until it's measured, then shrinks, a
    layout shift. No card today is long enough to cause one.*
  - *Stored text is escaped by `Markup::text()`, which escapes every `&`: WordPress's
    `esc_html()` leaves a character reference alone, so "R&amp;D" would show as "R&D"
    where the site shows what's stored. The topic pages' needs and project titles use
    it too.*
  - *A paper's address goes through `Markup::url()`, which reads a `\` before the
    query as `/` in an http or https address or one with no scheme, as a browser
    does (dropping it, as `esc_url()` would, can move the host), and percent-encodes
    what `esc_url()` would drop. It's checked with `esc_url_raw()` and escaped with
    `Markup::text()`: `esc_url()` escapes for display itself but leaves a character
    reference alone, so a stored `R&amp;D` would be followed as `R&D` (found by
    Copilot on #24). Buttons use it too, and decide whether to open a new tab from the
    same reading.*
  - *A DOI follows `https://doi.org/` as stored, as on the site. The import keeps a
    pasted link's query or fragment in the DOI it takes from the link
    (`scripts/import-survey.mjs`), which doi.org ignores, so a `?` or `#` in a DOI is
    left to work as it does in any address. So an old SICI DOI whose check character
    is `#` doesn't link on either site. The fix is for the import, and later the
    survey form, to cut a pasted link's query and fragment off the DOI; then the page
    can encode a DOI's own `#` and `?`.*
  - *Where the plugin differs from the site on purpose: a need entry with neither a
    need nor a recommendation, and a paper with no address WordPress will print (none,
    or `javascript:`), aren't shown or counted, where the site shows an empty item and
    counts it. The schema allows both, though the import produces neither.*
  - *Found in review, and fixed on both sites in the next change (10 November): a
    focused need card's focus ring was painted under its recommendation band, and is
    now drawn around the card where `:has()` is supported; a focused card that stopped
    scrolling dropped focus to the page, and now keeps its tab stop until focus
    leaves; the scrollbar thumb was below 3:1, and is now `--color-text-tertiary`; and
    a card held at its cap now re-measures when its text reflows, not only when its
    own box changes size.*
  - *Still open, for a change to both sites: the space after a need's lead comes back
    as a plain space, so a non-breaking one is lost. The cards' names ("Need and recommendation —
    scrollable" for a recommendation alone, the same name for every card without a
    lead) are site wording, for Stephanie and Emily. The topic pages' area labels and
    "covers" lines, and the Submit button's label, still go through `esc_html()`;
    they're the team's wording, not researchers'.*
  - *The page's `<title>` and description are still WordPress's (next item).*
- *Landing page done 10 November, without the map: the Import screen creates the
  `firehouse` page from the bundle's landing content, as the `firehouse/hero` block with
  `firehouse/stats` inside it, `firehouse/top-needs` and `firehouse/submit-band`, only
  when there's none. Its words are the blocks' attributes; the counts, cards and note
  are computed as it renders, from the projects a visitor may see, with the topic
  pages' counting, so a need naming a hidden project counts only the rest. It matches
  the reference's accessibility snapshots at both widths, without the map's region
  (`LATER` in `scripts/compare-plugin.mjs`), and its sections match by eye in both
  themes. A link to the survey address is stored empty and follows the settings; with
  none set, or one WordPress refuses, its button is left out. The frame prints a tree
  page's blocks directly, not through `the_content`.*
  - *Settled in the About page's change (11 November), for Eric to confirm in its
    review: tree pages keep printing their blocks directly, without `the_content` or
    `wp_filter_content_tags`. The portraits aren't core image blocks: the team block
    prints them with `wp_get_attachment_image()`, which adds their `srcset` and
    dimensions, with the `sizes` and lazy loading the block passes. Revisit if a tree
    page ever holds a core image block.*
  - *Found in the accessibility review, for a change to both sites: in a light
    forced-colors palette the hero's wordmark, drawn for the dark hero, mostly
    vanishes against the white Canvas (a logotype, so not a WCAG failure, but hard to
    read); and the landing page's own scroll margins on `#top-needs` and
    `#needs-source` leave out WordPress's toolbar, so a signed-in editor's jump lands
    the section a little under the bar. The review also asked whether the skip link,
    focused, shows above GSL's header, since `.fh-app`'s `clip-path` makes the
    wrapper its own stacking context. It does: `.fh-app` is `position: relative`, so
    the link slides in at the top of the wrapper, over the Firehouse bar and below
    the header, and is the topmost element there at desktop and phone width (checked
    11 November).*
  - *Found in the About page's accessibility review, for a change to both sites: Safari
    doesn't expose a list styled `list-style: none` as a list to VoiceOver, so the
    About page's audiences, steps and team, and the other pages' lists of the same
    kind, lose their count there. Add `role="list"` to them, which leaves the
    reference snapshots as they are, and confirm in the screen-reader audit.*
  - *For Phase 6: a FireHouse Editor has no `unfiltered_html`, so saving the page in
    the block editor runs every attribute string through kses, which turns a `&` into
    `&amp;` (then shown as written) and can alter a `<`. Today's landing and About
    words have neither; the editing screens need to keep editors' text as typed.*
- *About page done 11 November: the Import screen creates the `about` page under
  `firehouse` from the bundle's about content, only when there's none, and only after
  the landing page has read back as planned, so deleting a misplaced landing page can't
  move the About page to the top of the site, where GSL's own `/about/` is. Its words
  are the blocks' attributes (`firehouse/about-intro`, `about-sections` holding
  `about-mission`, `about-audiences`, `about-process` and `about-team`, and
  `about-cta`). It matches the reference's accessibility snapshots at both widths, and
  every section, card and portrait is the same size as the site's at both widths in
  both themes. Each portrait is the image the import found by Title and kept by its
  ID, or the member's initials. Its title is "About — The FireHouse", as the site's
  is. No one can delete the landing page for good while pages outside the trash are
  under it. After the security review's second pass (12 November): the dry run finds
  the newest image by ID, which no uploader can set, where a date can be changed; its
  note names the uploader's login and whether they can import; the page shows only an
  image on its own or attached to a page visitors may see without a password, and the
  dry run stops on one it wouldn't show, which it would once that page went public; and
  the confirmation imports only the plan it checked. The headings rule is the importer's:
  `about.json` itself passes the site's checks without it.*
  - *For Phase 6: trashing the landing page leaves a published About page answering at
    `/firehouse__trashed/about/`, as an ordinary page of GSL's theme with nothing in
    it, until the landing page is restored. Decide whether trashing the landing page
    trashes the pages under it.*
- Description and Open Graph tags, and whether a Firehouse page's `<title>` names The
  Firehouse. *Done 12 November (`includes/class-head.php`). Decided by Eric, as GSL's
  web team, 12 November: a Firehouse page's title names both, the site's title for it
  and then GSL's suffix ("FWT Evaluation 001. WoFS-Smoke — The FireHouse – Global
  Systems Laboratory"), and the plugin prints a description and Open Graph and Twitter
  tags on Firehouse pages, since GSL's site prints none, stepping aside if an SEO
  plugin is active or the `firehouse_head_tags` filter says no. The words are the
  site's, from `index.html`, which a test holds them to; a project's page has its
  abstract as the researcher wrote it, or, with none, its editorial summary, and its
  title escaped as stored text is. Any other page in the tree is titled "{its title} —
  The FireHouse". The filter can also keep the tags where an SEO plugin leaves
  Firehouse pages alone. The separator before GSL's name is the en dash GSL's own titles show
  (WordPress makes it of "-" with wptexturize(), which is off on Firehouse pages), so a
  researcher's own " - " doesn't read as the separator. Titles are computed as the
  page renders, from the settings, so the About page's and the explorer's follow their
  links' labels (About's post title, set by the import, stays "About — The FireHouse"
  for WordPress's lists).
  (13 November, Stephanie and Emily's decision, relayed by Eric: the name people read
  is "FireHouse", so titles read "… — The FireHouse – Global Systems Laboratory" and
  About's post title is "About — The FireHouse"; identifiers in the code keep
  `firehouse` and `Firehouse`.) The
  preview image is the site's `public/og-image.png`, which `npm run plugin:assets`
  copies into the plugin's `assets/preview/`, its address versioned so a replacement
  isn't served from a preview's cache. A published page's preview names its address.*
  - *From the review, left as they are: a missing address under `/firehouse/topics/`
    is titled "Page not found" over the heading "Topic area not found", as on the site;
    a change would go to both sites. The preview image's alternative text, the site's,
    describes the hub rather than the image (its "Fire Weather Research Hub" against
    the title's "Social Science Hub"): wording for Stephanie and Emily, on both sites.
    The SEO plugins the Firehouse steps aside for are a fixed list; another plugin that
    prints its own preview tags, Jetpack's for one, would need `firehouse_head_tags`.
    A search within projects (`/firehouse/projects/?s=`) is titled as the explorer, as
    on the site; still to decide, now that the explorer is here (13 November): it
    ignores `s`, as the site's does, and lists every project.*
  - *Found in review, outside this change: a topic's page renders without the
    password check a page in the tree gets (`Frame::content()`); its title is only the
    topic's label, which WordPress shows for protected posts anyway. For whoever next
    changes the topic page.*
- Check that projects appear in the sitemap. *Done 12 November, on the local copy:
  WordPress's sitemap lists the eight projects, the four topics, and the landing and
  About pages, each at its address under `/firehouse/`; a project a visitor may not see
  stays out of it (Visibility, checked 10 November).*
- Test link previews in Slack and Teams, with the first release on the test site (see
  Where each phase is reviewed).
- Weight, measured on the frame (6 November): `firehouse.css` is about 28 KB
  compressed, all of it render-blocking, and about a third of it comments. Minify what
  the plugin loads (the checks run on the minified file), split the project, topic
  and About sections by template in `wp_enqueue_scripts`, preload the latin Public Sans
  file, and drop the italic faces nothing uses. *Fonts done 12 November:
  `scripts/fetch-fonts.mjs` no longer asks for italics. Nothing the site or the plugin
  writes is set in italic, which `scripts/fonts.test.mjs` now checks, so no browser
  ever downloaded them. Rerun without them, it saved the same upright files, byte for
  byte, so no cached copy is stale; `fonts.css` has 24 faces, not 30. The frame
  preloads the latin Public Sans file on Firehouse pages while `fonts.css` is
  enqueued (`Frame::preload()`), at the address `fonts.css` names, so each font loads
  once. Measured on the local copy, a first view over HTTP/1.1 on an emulated slow 4G
  connection: text moved by up to 0.07 (a topic page's layout shift at desktop
  width, 0.08 for a project page on a phone) when Public Sans swapped in about a
  second after the first paint. With the preload the shift is under 0.002, and the
  first paint, also the largest, 0 to 0.3 s later, as the font shares the connection
  with the stylesheets. Preloading Archivo as well would keep the headings from
  changing font, which moves almost nothing, for a first paint 0.15 to 0.55 s later
  than none, so it isn't. A second view takes the fonts from the cache.*
  *With the first release on the test site, over HTTP/2 or 3 behind Cloudflare, where
  stylesheets can go first: measure the preloads again on a first view, and decide
  Archivo then (at `fetchpriority="low"`, say). The numbers above were taken with the
  readable stylesheet, which the local copy loads under `SCRIPT_DEBUG`; check the test
  site's pages load `firehouse.min.css` before measuring there.*
  *Stylesheet done 12 November: the frame loads `firehouse.min.css`, and keeps
  `firehouse.css`, the same rules readable, for review and for `SCRIPT_DEBUG`, as
  WordPress does with its own files. `npm run plugin:css` minifies the readable copy as
  the site's own build minifies its CSS, and the checks run on both. Both leave out the
  explorer's CSS (its page, the project tiles and the quick look) until the explorer
  arrives in Phase 5, and the design system's `TextField`, `TextArea` and `TopicTagRow`,
  which no page renders. Compressed (Node's gzip), the site's CSS goes from 28.7 kB to
  9.8 kB: leaving those out takes it to 24.6 kB, and minifying does the rest.*
  *Not split by page. Minified the same way, one file per kind of page would be 5.1 to
  6.7 kB compressed, 3 to 4.7 kB less than the whole; a shared file plus one per page
  would save 1.9 to 4 kB, and need a third file, since `global.css` (the tokens, the
  reset and the accessibility overrides) has to load after every page's rules. On the
  local copy, on an emulated slow 4G connection, neither helps a first view: the
  minified file arrives about 0.36 s before the last of the page's render-blocking
  stylesheets, which aren't Firehouse's, so fewer bytes in it don't move the first
  paint. Either split costs the next kind of page a visitor opens: its stylesheet,
  found only once the HTML arrives, adds a round trip, about 0.6 s there (first paint
  1.3 to 1.4 s against 0.7 s with the one file from the cache). Revisit if the file
  grows past about 20 kB compressed.*
  *Not minified: `frame.css` (1.8 kB compressed, 0.5 kB if minified first), written by
  hand, and `fonts.css` (1.1 kB and 0.5 kB), which `scripts/fonts.test.mjs` and the
  frame's tests read as text. They arrive with `firehouse.min.css`, before the page's last
  render-blocking stylesheet.*
  Once the blocks build with
  `@wordpress/scripts`, register them from its generated manifest
  (`wp_register_block_types_from_metadata_collection()`), not by reading each
  `block.json` on every request. *That function needs WordPress 6.8, and the plugin
  says 6.7: raise the minimum, or use 6.7's `wp_register_block_metadata_collection()`
  with the current loop. The manifest is a plain PHP file, so it needn't wait for
  `@wordpress/scripts`: a small generator and a CI check would do.* *Manifest done 13
  November, without raising the minimum: `npm run plugin:blocks` writes every
  `block.json` into `blocks/blocks-manifest.php`, and `Blocks::register()` hands it to
  6.7's `wp_register_block_metadata_collection()` before its loop. WordPress takes each
  block's metadata from the array instead of decoding its `block.json`, but still
  checks the folder and the `block.json` are there and resolves their paths, and needs
  the `block.json` to find `render.php` and to translate the title. A missing manifest
  costs only the saving; a damaged one stops every request, like any damaged PHP file
  in the plugin. `tests/BlocksTest.php` is the check, run in the plugin's CI: it fails
  if the manifest differs from the files, or holds anything but its header and
  literals. Every page prints the same HTML as before. It saves little, on every
  request on the site: with opcache on, in WordPress requests on the local copy,
  registering the 15 blocks took about 410 µs from their files and takes about 280 µs
  from the manifest. Without opcache, as from the command line, compiling the manifest
  cancels the saving. 6.8's `wp_register_block_types_from_metadata_collection()` would
  add little more. When the blocks build with `@wordpress/scripts`, its own manifest
  can take this one's place.* Inline icons from a
  generated PHP array, cached by opcache, not by reading their SVG files on each
  request. *Icons done 13 November: `npm run plugin:assets` writes every icon into one
  array, `assets/icons/icons.php`, in place of the 31 SVG files, and `Icons` includes it
  the first time a request asks for an icon. Opcache keeps it as one array shared by
  every request, with no copy per request. A missing file costs the icons, not the page,
  as before; a damaged one stops the page, like any damaged PHP file. Since the plugin
  runs the file, `tests/IconsTest.php` holds it to its header and string literals.
  Every page prints the same HTML as before. It saves little: in a WordPress request on
  the local copy, a project page's 17 icons took about 80 µs to read from their files
  and take about 25 µs from the array, about 50 µs saved.* *The hero's wordmark file
  is about 2.8 times the size a 2× screen needs (752 by 240 for 226 by 72 CSS pixels);
  a smaller one belongs in the design system's `src/assets`, for both sites. Likewise
  the team portraits: a 2× screen takes the 640 by 800 originals (186 KB for the two)
  for a 168 by 210 box; exported at 336 by 420 they'd be about 55 KB, in
  `src/assets/people/` and the Media Library both.* *Images done 13 November, in
  `src/assets/` for both sites; `npm run plugin:assets` copies the logos into the
  plugin, and the portraits reach WordPress through the Media Library. The wordmark
  keeps its size, since a 3× phone shows the 226 by 72 box at 678 by 216, and a 2× file
  would look soft there: lettering and hard edges show it, where a photograph hides
  it. All four logo files, the wordmark and the bar's mark in each colour, are now
  WebP, lossy at quality 90 with lossless transparency. The hero's wordmark goes from
  86 to 42 KB. The bar's mark goes from 11 to 5.6 KB in the light theme; in the dark
  theme a page loads both, since GSL's theme switches after the page starts, so 22 to
  11 KB. The marks are lazy-loaded, which is what keeps the hidden one from loading;
  loading them eagerly measured no faster (on an emulated phone on slow 4G, the
  landing page's largest paint at 1,924 ms eager against 1,960 ms lazy, within
  noise). A
  256-colour PNG was smaller but banded the flame's gradients. The portraits
  are 336 by 420, 57 KB for the two. The local copy, imported before this, keeps the
  640 by 800 portraits: its About page holds them by ID, and importing again leaves an
  existing About page alone, so they change there when Phase 6 lets an editor choose a
  portrait, or when a developer swaps the files in place with WP-CLI. Sites not yet
  imported take the new files from the README's upload step.*
- Rerun the frame's style and focus checks on the real pages. *Done 12 November, on the
  local copy against the site's build: every element in the main content of the
  landing, About, project, topic and not-found pages has the site's computed styles, at
  desktop and phone width in both themes, and so does every focusable element there
  with keyboard focus, each showing its focus ring. Left out: the landing page's
  coverage map (Phase 5), and the site's About wrapper, which has no styles. It found
  one difference, invisible on both sites: the project pager's link took the browser's
  default link colour, which follows the colour scheme the frame sets; its parts set
  their own, and the link now inherits (`ProjectPage.css`). Hover isn't checked.*
- Keep the Firehouse bar to one per page once a page's own content renders blocks. The
  bar's block prints on any Firehouse page, so its comment typed into a page in the tree
  would print a second bar, with a duplicate drawer ID. *Done 12 November: the frame
  places the bar (`Subnav::place()`) and the block prints it only then, so a copy typed
  into any content prints nothing, whenever it renders, and the bar is always the
  frame's.*

**Done when:** these pages match the reference screenshots and accessibility
snapshots, and Stephanie and Emily can review them on the test site.

### Phase 5 — Interactive pieces (3–3.5 weeks)

The explorer (search, filters with counts, sort, removable filter chips, quick-look
`<dialog>`) and the coverage map. (The mobile menu came with the Firehouse bar in Phase
3, and the scrollable need cards with the project pages in Phase 4.) The explorer's CSS,
its quick look's included, is in the one stylesheet. The coverage map's CSS is a chunk of
its own (`DEFERRED`), loaded with the map.

- *Search done 13 November: `domain/class-search.php` ports `search.ts` and passes
  every case in `shared/cases/search.json`, which now also hold the query string and
  the year to the browser's own reading. It reads a query string as `URLSearchParams`
  does, not as PHP's `$_GET` does: the first of a repeated name wins, and `phase[]` is
  just another name, not an array. It reads a year as JavaScript's `Number()` does
  (`Js::number()`), keeping safe whole numbers only. Folding accents takes PHP's intl
  extension (its Normalizer); without it, a table of Latin letters does it, held to
  JavaScript's own folding by `scripts/search-fold.test.mjs`, and letters of other
  alphabets, such as Greek ά or Cyrillic й, keep theirs. Word lengths and typo
  distances count UTF-16 code units, as JavaScript does. Titles sort, and every sort
  breaks ties, by `Derive::compare_titles()`, which also takes intl; without it,
  titles with punctuation, digits or accented letters can sort differently. The
  TypeScript now compares titles in `en-US` too, whatever the browser's language, so
  the script never reorders a list the server drew.*
- *The code review found that one long address of misspelt words cost seconds of
  server time (about 45 at 48 projects). Both languages now count only a search's
  first 20 words (`MAX_TERMS`, a shared case), and the PHP scores a repeated word
  once and measures a field's words once: the same address takes about 50 ms at 48
  projects, for one pass over them (the explorer's page made five until it scored
  once; see below). The explorer's handler should still get the Application Security
  Engineer's review when it reads the query string.*
- *The explorer without JavaScript, 13 November: the `firehouse/explorer` block, its
  markup built by `Explorer_Page` (`includes/class-explorer-page.php`), renders the
  site's `ProjectsPage` from the server on the archive's address.*
  - *Every control belongs to one `GET` form. The selects stay where the site has
    them, outside the form element, and join it by its ID, so the markup and CSS stay
    the site's. Without a script a select changes nothing until the form is sent, so
    the plugin adds a button that sends it, "Update results": new wording, for
    Stephanie and Emily (README, Before this goes public). On a phone it takes a row
    of its own. Clearing the search, removing a filter and clearing them all are
    links to the address without them, where the site has buttons; the script slice
    should keep them links and intercept them, so their role doesn't change under a
    screen reader.*
  - *The sort's fallback option ("Newest first" with no search, "Best match" with one)
    sends what the address had: nothing, or `relevance` with no text yet. Sent as
    `sort=newest`, it would pin the order, and a search added later wouldn't list by
    best match, as the site does. A sent form's address carries every name, empty
    ones included (`?q=smoke&phase=&region=&year=&type=&sort=`), until a change made
    with the script rewrites it.*
  - *Accepted differences without a script: an option that would leave no results
    says "(0)" but stays enabled, since the counts don't follow a change to another
    select until the form is sent; and a year or type no project has can't be chosen
    in its select, so sending the form drops it, where the site keeps it until its
    filter is removed.*
  - *The form and every link back to the explorer land on `#fh-content`, the skip
    link's target, so a reload doesn't put a keyboard back at the top of the theme's
    header. Every reload has the same title; putting the result in it ("2 of 8
    projects matching “smoke”") would be new wording, for Stephanie and Emily.*
  - *It reads the query from `REQUEST_URI`, the address as the browser sent it, not
    from `QUERY_STRING`, which a server's rewrite rules can change. At an address
    with a query, it asks search engines not to index the page (`Head::robots()`):
    the page prints words from its address, which anyone can write into a link.*
  - *It scores each project once, for the list and the four counts
    (`Search::scores()`), where the review found five passes. A search at 48
    projects (the eight local ones, copied) renders in about 35–55 ms on the local
    stack, from 150–200 ms; with no search, about 15–20 ms. Phases or regions stored
    in the wrong shape give none, rather than stopping the page (rule 6).*
  - *Checked by hand, with a scratch script that loads both sites: at 34 addresses,
    from none to repeated, malformed and 21-word ones, its main landmark matched the
    React explorer's except where the script is missing or the plugin stands in for
    it. The hint leaves out "Results update as you type. Press Escape to clear.";
    there is no phone Filters and sort button and no quick look; the links above are
    buttons on the site; options aren't disabled; and "Update results" is the
    plugin's own. `tests/ExplorerPageTest.php` reads back what the form and each link
    would send, at 37 addresses. `npm run plugin:compare` listed the page as built but
    not compared, until the script and the quick look made it match (below).*
  - *The explorer's page and tile CSS joined the one stylesheet, as Phase 4's weight
    decided (not split by page), adding about 1.4 kB compressed. The quick look's
    (`ProjectDetailModal.css`) came with its dialog, about 0.5 kB more.*
  - *The review's notes for the script slice (the "/" shortcut, focus after a filter is
    removed, the status line's node, the hint's second sentence, and `disabled` bound
    to live counts) are done with the script, below.*
  - *Left for changes of their own: control edges below 3:1 (about 1.6:1 in light and
    2.2–2.8:1 in dark, the site's too; `--glass-control-border` would meet it); the
    tiles' h3 under the page's h1, on both sites; WordPress's own query for the
    archive, which still runs, and still answers `?s=`, `?m=` and `?paged=`; and the
    typo match's tokenizing, which an ASCII fast path would cut, worth it past about
    100 projects.*
- *The explorer with JavaScript, 13 November: `blocks/explorer/view.js` takes the same
  markup over through the Interactivity API and searches in the browser, as Eric chose
  (Interactivity, Explorer).*
  - *What it does. Results, counts and the applied filters follow each select as it
    changes, and the search after a 200 ms pause in typing, as on the site, with no
    request. The address follows with `history.replaceState`, written as the site
    writes it, relative to the page. Escape clears the search, and the hint gains the
    site's "Results update as you type. Press Escape to clear." On a phone the filters
    fold behind the "Filters and sort" button, which the server prints hidden, unless
    the visitor is already in them or has scrolled; "Update results" goes. Options that
    would leave nothing are disabled, now that the counts follow every change.
    Clearing the search, removing a filter and Clear all stay links, as planned, and
    the script follows a plain click in place; a click with a modifier key opens the
    link, as a link's would. On the site they're buttons.*
  - *The search. `search.ts` itself, bundled by esbuild (a pinned dev dependency) as
    `assets/search/search.js` and registered as the `@firehouse/search` script
    module. `npm run plugin:assets` writes it, and `plugin:assets:check`, which CI
    runs, fails if it falls behind. The projects come from the page's state
    (`Explorer_Page::state()`): each public project's fields the search reads, in the
    shapes it expects, with its address and byline, and nothing else (the quick look
    added three fields, below). Each project is
    scored once per search, for the list and the four counts (`scores()`, as
    `Search::scores()` does in PHP), and its fields folded once: at 48 projects on a
    phone at a quarter of a desktop's speed, an update takes about 70 ms, from about
    420.*
  - *Why the plugin prints the page. WordPress's processing of the directives prints
    text with `esc_html()`, which leaves a character reference as it is, so a title
    stored as "R&amp;D" would show as "R&D" (rule 1). So the block doesn't ask for it:
    the plugin prints the page as before, and the markup carries the directives the
    script binds to and templates for the lists it redraws (the tiles, the applied
    filters, the sort's options). What the server printed of a list stands in
    (`data-wp-each-child`), with its template after it so the items keep their nodes,
    and focus, as the script takes over.*
  - *Only once its script is there. The server marks the region `data-fh-interactive`,
    and the script sets `data-wp-interactive` when it loads. If it or the search fails
    to load, WordPress's runtime, which other blocks load, leaves the region alone and
    the page works as it does without JavaScript; drawn from an empty store, it would
    have shown no projects. What the visitor types or chooses until WordPress takes
    the page over is kept, and focus the takeover drops to the page is put back.*
  - *WordPress 6.7 and 6.8. Actions that call `preventDefault()` are wrapped in
    `withSyncEvent()`. WordPress added it in 6.8, warns (with `SCRIPT_DEBUG` on)
    about an action that needs it and lacks it, and plans to run such actions after the
    event, too late to prevent it. 6.7, which the plugin supports, has none and runs
    every action during the event, so there the script passes its actions through.*
  - *The review's notes. The site's "/" shortcut isn't ported, since a one-key shortcut
    that can't be turned off fails WCAG 2.1.4. Before a filter is removed, focus moves
    to the filter after it, else Clear all, else the search. The status line keeps its
    node; only its parts change: their text, and whether "Showing" and "matching …"
    show. Left for a screen-reader test: the status line speaks after each pause in
    typing, and on Windows after each arrow key in a closed select, as the site's does;
    settling it after a second would be a change to both sites. Folding the filters on
    a phone still moves the results up once, at load, when the visitor hasn't started;
    folding them before the first paint is Eric's call.*
  - *Checked by hand, with scratch scripts (not in the repository) that load both sites
    with JavaScript on: at the same 34 addresses, and after each of 11 steps (typing a
    search; choosing a phase; the sort; clearing the box; typing another; choosing a
    GACC, a year and a type; Escape; choosing any phase; a search that finds nothing),
    the main landmarks and the addresses matched, except for the quick look, which
    came next (below), and the links above, which are buttons on the site. With the search
    module or the script blocked, the page works as it does without JavaScript.
    Vitest runs the script's workings against `shared/cases/explorer.json`
    with the shipped module, its store's getters and actions with stand-ins for
    WordPress 6.8 and for 6.7, and checks every directive path the PHP prints
    against the store. PHPUnit holds the templates to the markup the server prints.*
- *The quick look, 13 November: the site's `ProjectDetailModal` as a native
  `<dialog>`, one at the end of the explorer, opened with `showModal()` from each tile's
  "Quick look at …" button.*
  - *What it shows. A project's type, title, every author, organization and year, its
    phases and publication status, its abstract and first three end-user needs as the
    researcher wrote them, and counts of what else its page holds, with a link to it.
    Its words are bound to the project being looked at (`quickLook()` in the view
    script); the page's state gains each project's byline naming every author, its
    publication status's key (none for a key the vocabulary lacks, which its page
    doesn't print either), and how many papers its page lists, leaving out any whose
    link the page refuses. All three are on its page already.*
  - *As a dialog. The browser makes the page behind inert and closes it on Escape;
    focus goes to its close button (`autofocus`), as on the site. The script puts focus
    back on the button that opened it (on the search box, if a search took that tile
    away), closes it on a click on the page around it, and stops the page scrolling
    behind it, as the site's does. It opens a moment after the click, once its words
    are the project's, so it's announced by the right name. `frame.css` takes the
    browser's own dialog box off, so the site's overlay styles hold.*
  - *Where it differs from the site's. A click closes it only when pressed and
    released on the page around it, and not as the second click of a double click:
    the site's closes on the press, so a drag that selects its text and ends outside
    it doesn't close it there either, but a press on the overlay's scrollbar does,
    and a double click on a tile's button opens it and closes it again. The dialog takes no
    tab stop of its own (`tabindex="-1"`); when the panel is taller than the window
    it scrolls, and the browser would stop on it with its focus ring off the screen
    (the Section 508 review, 14 November). Tab moves from its last control to the
    browser's, as a native dialog's does, where the site's loops back to its close
    button. Its project comes from the page's state, not the results, so it stays
    open if a pending search drops that project from the list; the site's closes.*
  - *Without JavaScript, the tiles' buttons are hidden and the dialog never opens; a
    tile without phases hides its footer.*
  - *Checked by hand, with the same scratch scripts: each of the eight projects'
    quick look matched the site's, its focus included; and the explorer's main
    landmark matched the site's at the same 34 addresses and after the same 11 steps,
    with the links above read as the site's buttons, and nothing else left out. `npm
    run plugin:compare` now compares the explorer too, at both widths: all 32
    comparisons (16 pages, desktop and phone) match.*
  - *Left for Stephanie and Emily: the phase and status pills in the quick look, as on
    its tiles and on the site, have no label a screen reader hears ("Before During
    Published"); a visually hidden "Fire phases:" and "Status:" would need their
    wording, on both sites.*

**Done when:** it reaches feature parity with the React site, every URL parameter
behaves the same, and the accessibility snapshots match.

### Phase 6 — Editing screens (2–2.5 weeks)

- Project and topic edit screens: survey fields read-only, editorial fields editable,
  publishing through WordPress's Publish button.
- Landing and About layouts locked to text-only edits. Their sections are custom
  blocks whose words are attributes, which `contentOnly` locking doesn't show to
  editors: decide whether they get their own text controls or become core blocks in a
  locked pattern. *Until then (13 November, at Eric's request, to tell the page's words
  from what FireHouse adds): `assets/js/block-editor.js` shows each block in the editor
  as a read-only box of what the page stores in it. Every block saves its inner blocks,
  so a save keeps the page's blocks; a save that rebuilds the page leaves out values
  equal to a block's default, which render the same, and a FireHouse Editor's save
  still runs the words through kses (Phase 4's About page note). The Code editor can
  still change anything stored, so the boxes aren't a control. Either choice replaces
  the boxes.*
- Whether an editor can reword the cleared disclaimer.
- Which blocks a page in the Firehouse tree allows. Nothing limits them today, so an
  editor can add a core Paragraph or Image to the landing or About page, with its
  Italic button and caption, which GSL's theme sets in italic. The fonts have no
  italic face (Phase 4's weight work), so the browser would slant the upright one.
  Keep core text blocks out of the tree, or add the italic faces they use to
  `scripts/fetch-fonts.mjs`.
- The synthesis screen: paste, validation, the comparison against the published
  synthesis, and **Mark reviewed**. Validation includes the order: each topic area's
  needs by project count, highest first, since the About page says so (ties go by
  broader interagency or multi-regional applicability, which the reviewer judges).
- Download NotebookLM source, for the paste-in fallback.
- Submit buttons bound to the settings.
- Validation notices.

**Done when:** Stephanie and Emily have done every task in `README.md` and
`notebooklm/README.md` in a walkthrough with Eric on his local copy. Doing them on their
own, without a developer, is checked on the test site with the first release (Eric, 9
November; see Where each phase is reviewed).

### Phase 7a — The survey form (2–3 weeks)

- The Submit page and the `firehouse/survey-form` block, in the `survey/` folder (see
  [The survey form](#the-survey-form)).
- Drafts from submissions, private answers stored apart (rule 2), the import script's
  checks on submit, and DOI title lookups.
- The submission handler's protections: nonce, server-side validation, length caps,
  repeat-submission limits and spam protection, with the Submit page never cached.
- Editable question wording on the settings screen.
- Emails to the editors and the submitter.
- If the form uses the design system's `TextField` or `TextArea`, take `Field.css` off
  `UNUSED` in `scripts/plugin-css-sources.mjs`, which leaves it out of the plugin's
  stylesheet; a test fails until then if the form's markup uses its classes.

**Done when:** a test submission on the test site arrives as a draft that matches the
import script's record of the same answers, its private answers are readable only by
the Firehouse roles and absent from the REST API, and the form passes a screen-reader
check. It opens on prod only once the privacy review and the written PRA answer are in
hand.

### Phase 7b — Analyze results (1–1.5 weeks)

- The AI service client: one host, a key in `wp-config.php`, only published text sent
  (see [The AI service](#the-ai-service)).
- The Analyze results button and the prompt on the settings screen.
- The comparison with NotebookLM: both run on the same published projects, and
  Stephanie and Emily judge the results.
- Site Health checks: outbound reach to doi.org, api.crossref.org, data.crosscite.org
  and the AI service, `noindex` on test, and HTML caching status.

**Done when:** Analyze results produces a draft synthesis on the test site that passes
the screen's validation, and Stephanie and Emily have signed off that it matches
NotebookLM's in substance. Until the service is approved, the paste-in path is what
ships.

### Phase 8 — Review, launch, cutover (1.5–2 weeks)

- Security review and fixes.
- Cache purging, only if Site Health shows Cloudflare has started caching HTML.
- A screen-reader audit of the public pages and the admin screens.
- CI that builds the versioned plugin zip, without `tests/`.
- A runbook for editors and for administrators, including how to remove a project
  for good (trash, then Delete Permanently, which takes its fields and revisions
  with it).
- Launch: close the Google Form and run the import script one last time, build the
  seed bundle, install on prod and run the seed import (which builds the page tree
  behind the bridge), remove the static directory, and point Submit a Finding at the
  Submit page.
- Confirm link previews on prod.
- Retire the React app and the static-hosting files.

**Done when:** gsl.noaa.gov/firehouse is served by the plugin, and every URL from the
bridge resolves to the same content.

### Later phases

Each is its own plugin release with its own security review. None is scheduled: each
starts when its trigger is met.

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

| Phase | Work | Size | What Stephanie and Emily see |
| --- | --- | --- | --- |
| 0 | Reference, decisions, groundwork | 1–1.5 wk | — |
| 1 | Content model and first data load | 2 wk | Empty plugin, data in wp-admin |
| 2 | Logic in PHP | 1.5–2 wk | — |
| 3 | Design system and frame | 2 wk | Firehouse frame inside GSL's chrome |
| 4 | Display-only pages | 2–2.5 wk | Pages to read and review |
| 5 | Interactive pieces | 3–3.5 wk | Feature parity |
| 6 | Editing screens | 2–2.5 wk | Editors working in wp-admin |
| 7a | The survey form | 2–3 wk | Test submissions arriving as drafts |
| 7b | Analyze results | 1–1.5 wk | A synthesis run from the dashboard |
| 8 | Review, launch, cutover | 1.5–2 wk | → gsl.noaa.gov |
| | **Total** | **~18–23 wk** | plus security-review, privacy-review and PRA wait time |
| L1–L2 | Later phases, each when triggered | up to ~2 wk each | — |

With two developers the work splits after Phase 2: one on Phases 3–5, the other on
Phases 6–7.

---

## Verification

- **Visual and semantic parity.** Screenshots and accessibility-tree snapshots of each
  route, compared against the reference captured in Phase 0.
- **Logic parity.** PHP and TypeScript run the same shared test cases.
- **Import script.** The adapted `import-survey.mjs` runs on a synthetic Google Forms
  export (real exports contain submitters' emails) and produces the expected records.
  Re-entered submissions are skipped, and excluded responses appear in its report,
  never in the site's JSON.
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
- The About portraits come from the Media Library.
- Revision history on projects and topic syntheses.
- Unpublished records no longer shipping to browsers.
- The federal chrome items in the README (gov banner, footer, privacy and
  accessibility links) come from GSL's theme.

**Given up:**

- The hero's photograph, for now: the plugin's hero draws only its smoke and shade,
  so a photograph arriving before cutover would show on the static site alone.

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
   The design summary also asks how repeat submissions should be limited (see
   [Security review](#security-review)), whether a honeypot and a minimum fill time are
   enough spam protection, and whether the AI service needs a security review as well
   as approval under NOAA's AI policy.
3. Does any other cache sit in front of either site, such as a page cache on the
   server? (Cloudflare caches neither site's HTML: see [Caching](#caching).) If one
   does, hiding a project has to purge the topic pages that cite it, not only its own.
4. Can the bridge go on the test site, restricted to GSL staff, by mid-November?

**For ITS**

5. What else do the Project Intake Request and the Technical Assessment Worksheet
   need before the project is accepted?
6. Is The Firehouse's own design approved for the pages between GSL's header and
   footer, in place of GSL's standard page styles (see
   [Look and feel](#look-and-feel))? Stephanie and Emily chose it.
7. Which AI service and model are approved for the synthesis? Cloudflare Workers AI
   or Amazon Bedrock is likely; the model (Llama 4, another open model, or a
   commercial one) is picked after its output is checked. A colleague of Stephanie's
   received OAR clearance for similar work, and she has the contacts that colleague
   worked with. Until a service is approved, the synthesis is pasted in from
   NotebookLM (see [The AI service](#the-ai-service)).

**For the team**

8. Launch the bridge, on the test site for mid-November and on prod for AMS?
   *Recommended.*
9. ~~Keep dark mode for the WordPress version?~~ *Yes (Eric, 3 November): keep it,
   and follow GSL's toggle.* GSL's theme has its own light/dark switch, which sets
   `data-theme="dark"` on the page, the same attribute Firehouse's dark colors use, so
   a Firehouse page never sits dark between a light header and footer. Keeping it
   means the visual and contrast checks run in both themes.
10. ~~Keep Archivo and Public Sans, or use GSL's fonts (Nunito Sans for text,
    Montserrat for headings)?~~ *Keep them for now (Eric, 3 November), and keep a
    switch to GSL's fonts easy.* GSL's pages already load Nunito Sans and Montserrat,
    so switching would add nothing to self-host.
11. ~~Is working without JavaScript a requirement? If not, the PHP search is dropped
    (see [Interactivity](#interactivity)).~~ *Yes (Eric, 13 November): a shared
    explorer address opens filtered from the server, with or without JavaScript, so
    the search is ported to PHP (`domain/class-search.php`, Phase 5).*
12. *(With NOAA's PRA clearance officer)* Does the survey count as a NOAA information
    collection under the Paperwork Reduction Act? It asks non-federal researchers the
    same questions on NOAA's behalf. The answer depends on what is collected and for
    whom, not on which tool hosts the form, and a form on gsl.noaa.gov makes the
    question more pointed. The current view (29 October) is that clearance isn't
    needed, but that isn't confirmed in writing, and the form can't open on prod until
    it is.
13. ~~Should a project that answers "Project did not require IRB review" be
    published?~~ *Yes (Stephanie and Emily, late October): only "No" ends the form, so a
    project that didn't need review is published like one that was approved. The
    import script accepts the answer, the plugin's form will too, and WoFS-Smoke, the
    one project it affected, is on the site.*

**Privacy**

14. *(Privacy review)* What may the survey form keep, and for how long? It stores
    submitters' emails and job titles, so the team can follow up, and their Q10 answers
    (rule 2). The review decides how long they are kept and when they are deleted.
15. *(Stephanie and Emily)* Can Q10, how results were shared outside the research
    group, appear on the site? Qualtrics marked it internal-only; the Google Form
    doesn't say. Until they decide, it stays private.

**Settled**

- ~~What are the slugs of the theme's header and footer template parts?~~ *None:
  GSL's theme is classic, with PHP header and footer files (27 October).*
- ~~Do administrators customize templates in the Site Editor?~~ *They can't: a
  classic theme has no Site Editor.*
- ~~Does Cloudflare cache HTML?~~ *No, on either site (1 November): pages come back
  with `cf-cache-status: DYNAMIC`, so publishing needs no purge.*
- ~~Sync the survey through Google's API?~~ *No (29 October): with the form in the
  plugin there is no Sheet to sync.*
- ~~Which counting rule is canonical?~~ *Once per project (28 October): a need
  mentioned several times in one project counts once. The prompt in
  `notebooklm/README.md` counts this way; the 17 September draft on the site doesn't,
  until the synthesis is rerun.*
- ~~Which Google account owns the Google Form?~~ *NOAA's (31 October): it belongs to
  Emily's account in NOAA's Google Workspace, and is open to respondents outside NOAA.
  The intake documents should say so, which settles the concern that the survey sat
  outside NOAA.*
- ~~Is the Qualtrics survey closed?~~ *Yes (28 October). Every real submission was
  re-entered through the Google Form, so no last Qualtrics import is needed; the
  import skips the re-entered rows, because the site already shows those projects (see
  [Importing the Google Form until launch](#importing-the-google-form-until-launch)).*

---

## Appendix — found during the analysis

- **Unpublished projects ship in the bundle today.** `normalize.ts` drops only records
  without IRB approval; the pages filter unpublished ones out. All eight current
  records are published, so nothing is exposed yet, but the next import stages new
  records as unpublished and they would ship with the next deploy.
- **Documentation drift.** `README.md` and `PRODUCT.md` still describe nine placeholder
  projects. `projects.json` holds eight survey-imported records, all published. The
  migration treats them as real.
- **The Sanity and Strapi adapters** were never run against a live backend. They
  retire with this plan.
- **A submission was missing from the site.** `R_3e9GnZYuWAjuZM5` (FWT Evaluation 001,
  WoFS-Smoke) answered Q8 with "Project did not require IRB review", which the import
  counted as no approval. Stephanie and Emily publish it (open question 13), and it is
  on the site.
- **The team's sheet is the corrected record.** The eight projects now come from
  Stephanie and Emily's "Submission Data" sheet, their checked copy of the Qualtrics
  responses, rather than a raw export. It has no ResponseId column, so the import
  matches each row to its record by title. It also carries their corrections: one need
  in Testbed 002 is gone, two in Igniting Insight are merged into one, and the NGFS
  abstract and first takeaway have straight apostrophes where the export had curly
  ones. Stephanie and Emily are confirming all three (pull request #6). Its
  timestamps aren't unique (two rows share one), so they can't serve as its key.
- **Mis-encoded text in the export.** Some Qualtrics rows arrive double-encoded
  (`NGFSâ€™s` for `NGFS’s`). The import now repairs it and lists each cell it
  repaired. Google Forms exports are clean UTF-8, so no repair is ported to the
  plugin.
- **The live synthesis is out of date.** `topicSummaries.json` is a 17 September draft,
  written with Claude, that covers 4 of the 8 published projects. The landing page
  reports it as drawn from "4 projects today".
- **Topic pages scrolled sideways on phones.** The source chips under each need (a
  project's title and year) didn't shrink, so at 390 px wide the page was 463 px wide.
  The reference capture found it, and the bridge fixed it: the chips' titles now
  shorten with an ellipsis. `npm run reference` fails if any page is wider than the
  window, so the plugin's pages get the same check.
