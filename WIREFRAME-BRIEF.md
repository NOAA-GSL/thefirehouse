# Wireframe brief: how information moves through The Firehouse

> **For Claude Design.** Link this repository so Claude Design picks up the Firehouse
> design system (`DESIGN.md` and `src/styles/tokens/`), then paste this whole file in
> as the prompt. Everything the diagrams need is written here; nothing has to be
> worked out from the code. The source of truth is `WORDPRESS-PLAN.md` (updated
> 31 October 2026). If the two disagree, the plan wins and this brief is out of date.

---

## What to make

**Part 1: four information-flow diagrams,** one per 16:9 frame (1600 × 900), in this
order:

1. **Today:** how a survey response becomes a page on the current site.
2. **The bridge:** the same site moved onto GSL's servers for the mid-November WPO
   meeting and for AMS in January 2027.
3. **WordPress, first release:** the whole workflow under one roof in GSL's
   WordPress, survey form and AI synthesis included.
4. **Later phases:** what could come after the first release, and what each would
   take.

**Part 2 (optional): three editor-screen wireframes** for the first WordPress
release: a new submission, a draft project preview, and the synthesis review.

**Part 3: one page-frame mockup** showing a Firehouse page inside GSL's header and
footer, so Stephanie and Emily can see what changes at the top and bottom of the page.

These are conversation starters for a meeting, not final art. Keep Parts 1 and 2 low
fidelity and easy to change. Part 3 is the exception: it uses the real design system,
because how the page looks is the question it answers.

## Who reads them

- **Stephanie Hoekstra and Emily Wells** run The Firehouse. They are social
  scientists, not developers. From each diagram they need to see what they do at each
  step, where their quality check and their review of the AI synthesis happen, and
  which steps no longer need a developer.
- **Jenny and GSL's ITS security reviewers** decide whether the site can run on GSL's
  servers. They need to see where personal data lives, what is stored on GSL servers,
  who can log in, what the public can reach, and what leaves GSL.

Write every label for Stephanie and Emily in plain language. Put the security facts
in the "For ITS" notes and the markers below, not in the box labels.

## Visual language (all four diagrams)

- **Wireframe fidelity.** Flat boxes and arrows. No illustrations, gradients or drop
  shadows, and no icons beyond the three markers below.
- **Firehouse tokens.** Cool paper ground (`bg-page`), white boxes (`bg-surface`)
  with `border-strong` edges, navy (`brand-primary`) for structure and arrows.
  Archivo for titles, Public Sans for everything else.
- **Columns are places.** Each diagram is split into columns by where information
  lives, left to right, from outside NOAA to the public web. Each column is a lightly
  tinted zone with its name at the top.
- **Every box has three parts.** A small uppercase eyebrow saying who does the work
  (label style: "STEPH & EMILY", "DEVELOPER", "RESEARCHERS", "VISITORS"), a title
  naming the thing, and one or two short lines saying what happens there.
- **Arrows say how things move.** Solid means it happens automatically. Dashed means
  a person moves it by hand: an export, an upload, a paste, a retyped edit. Every
  arrow carries a two-to-five-word label.
- **Three markers,** repeated across diagrams, so include a small legend:
  - **Personal data:** a gold pill (gold-600 text on gold-50) on any box that holds
    names, emails, job titles or IP addresses.
  - **Review step:** an ember pill (`accent` on `accent-tint`) on any box where
    Stephanie and Emily approve something before the public sees it. This is the
    only ember in the diagrams (the Ember Budget Rule in `DESIGN.md`).
  - **Open question:** a small "?" in a circle with a short question beside it, for
    facts nobody knows yet.
- **Under each diagram:** a one-sentence takeaway, then two short note columns
  headed "For Steph and Emily" and "For ITS".

**Don't:**

- Draw any AI output reaching the public pages without passing through Stephanie and
  Emily's review. In Diagrams 1 and 2 a person carries the AI's output in; in
  Diagram 3 the plugin fetches it, and it still waits as a draft for review.
- Show a survey form inside WordPress in Diagrams 1 and 2.
- Show a live "latest submission" ticker or a PDF generator on the server.
- Draw a citation button or "Cite this page" anywhere. The site no longer offers
  citations.
- Draw any hosting outside GSL, except the taken-down prototype in Diagram 1.
- Invent numbers, dates, hostnames or product names that are not in this brief.

---

## Diagram 1: Today (as of 27 October 2026)

**Takeaway:** Every change to the site, even one sentence, goes through a developer,
and the site is not online anywhere until GSL hosts it.

**Zones, left to right:** Outside GSL's servers (Google and AI tools) · Developer's
computer and GitHub · Hosting (none today)

| Zone | Who | Box | What happens there | Markers |
| --- | --- | --- | --- | --- |
| Outside GSL | Researchers | Google Form | Replaced Colorado State's Qualtrics survey | Personal data |
| Outside GSL | Steph & Emily | Google Sheet | Linked to the form; quality check of each response | Personal data, Review step |
| Outside GSL | Steph & Emily | AI synthesis | NotebookLM with the team's prompt. The live draft was made with Claude. | Personal data (the sheet is one of its sources) |
| Outside GSL | Steph & Emily | Edit requests | A Google Doc of text changes | — |
| Developer | Developer | Import script | Drops email, job title, the internal-use answer and IP; skips projects without IRB approval. Written for Qualtrics exports; being adapted to the form | — |
| Developer | Developer | Site files in GitHub | Projects, top needs and page text, stored as JSON | — |
| Developer | Developer | Build | Turns the files into a static site | — |
| Hosting | — | Earlier prototype (muted, dotted outline) | Ran on the former developer's personal domain; taken down in October 2026 | — |

**Arrows:**

- Google Form → Google Sheet: "responses land" (solid)
- Google Sheet → AI synthesis: "added as a source" (dashed)
- Google Sheet → Import script: "approved responses sent over" (dashed)
- Import script → Site files: "writes project records" (solid)
- AI synthesis → Site files: "top needs copied in" (dashed)
- Edit requests → Site files: "retyped by developer" (dashed)
- Site files → Build: "build" (solid)
- No arrow leaves Build: nothing is hosted today.

**For Steph and Emily:** You run the survey, the quality check and the AI synthesis.
Everything after that, including a one-word change to the About page, waits for a
developer to retype and rebuild it, and there is nowhere to put it online until GSL
hosts the site.

**For ITS:** Personal data lives in Google: the form's response Sheet, and the
NotebookLM notebook that uses it as a source. Earlier responses were collected in
CSU's Qualtrics. The website holds none: the import script drops it before anything
is written. The site is static, with no database, logins or forms. It is not hosted
anywhere today: an earlier prototype on the former developer's personal domain was
taken down in October 2026, and its Netlify configuration has been removed from the
repository.

## Diagram 2: The bridge (WPO meeting in mid-November, AMS in January 2027)

**Takeaway:** Same workflow as today, but the site moves onto GSL's servers at the
address the WordPress version will keep.

**Zones:** Outside GSL's servers · Developer's computer and GitHub · GSL web servers
(behind Cloudflare)

| Zone | Who | Box | What happens there | Markers |
| --- | --- | --- | --- | --- |
| Outside GSL | Same as today | Survey, quality check, edit requests | The Google Form and its Sheet; a Google Doc | Personal data, Open question: "Whose Google account?" |
| Outside GSL | Steph & Emily | AI synthesis, rerun | Across all 7 published projects, reviewed before the WPO meeting | Review step |
| Developer | Developer | Import and site files | The same files as today; the import script learns the Google Form's format | — |
| Developer | Developer | Build for /firehouse/ | Gov banner and footer back on; Submit a Finding opens the Google Form; no citation generator | — |
| GSL web servers | Mid-November · WPO | Test site copy | GSL staff only, not indexed by search engines | Open question: "Login or network restriction?" |
| GSL web servers | January · AMS | gsl.noaa.gov/firehouse/ | Public, on the same addresses the WordPress version will use | — |

**Arrows:** survey and edits → Import and site files: "export and edits sent over"
(dashed) · AI synthesis, rerun → Import and site files: "reviewed top needs"
(dashed) · Import and site files → Build: "build" (solid) · Build → Test site copy:
"static files copied" (dashed) · Build → gsl.noaa.gov/firehouse/: "static files
copied" (dashed).

**Open question on the GSL web servers zone:** "Apache or nginx?"

**For Steph and Emily:** Nothing changes in how you work yet. Before the WPO meeting,
the top needs have to be rerun across all seven projects and signed off by you: the
live draft covers four of them.

**For ITS:** Static files only: no database, no logins, no forms and no personal data.
The test copy is restricted to GSL staff and kept out of search engines. Links shared
at AMS keep working when WordPress takes over, because the addresses do not change.
Nothing runs outside GSL.

## Diagram 3: WordPress, first release (one roof for the workflow)

**Takeaway:** Researchers submit on gsl.noaa.gov, the synthesis runs from the
dashboard, and Stephanie and Emily review everything before it goes public, all in
GSL's WordPress.

**Zones:** Outside WordPress (the AI service, and the retiring Google Form) · GSL
WordPress: staff screens (login required) · GSL WordPress: public pages. Add a thin
lane along the bottom, spanning both WordPress zones, for code changes.

| Zone | Who | Box | What happens there | Markers |
| --- | --- | --- | --- | --- |
| Public pages | Researchers | Survey form | The Submit page; replaces the Google Form at launch | Personal data, Open question: "PRA answer in writing?" |
| Outside WordPress | — | Approved AI service | Receives published project text and the team's prompt; returns the top needs | Open question: "Which service is approved?" |
| Outside WordPress | Steph & Emily | Google Form and Sheet (muted, dotted outline) | Responses collected before launch | Personal data |
| Staff screens | Steph & Emily | Import screen | One-time import of the Google Form's responses; drops email and job title; the uploaded file is not kept | — |
| Staff screens | Steph & Emily | Draft projects | Each submission arrives here; preview the page as visitors will see it, then Publish. The submitter's email is visible only here | Review step, Personal data |
| Staff screens | Steph & Emily | Synthesis screen | "Analyze results", or paste NotebookLM's output as a fallback; flags any project it doesn't recognize | — |
| Staff screens | Steph & Emily | Draft synthesis | Shows what changed since the published version, then Mark reviewed | Review step |
| Staff screens | Steph & Emily | Page text and question wording | Edit About and landing page words, and the survey's questions, in place; layout is locked | — |
| Public pages | Visitors | Explorer and project pages | Inside GSL's header and footer | — |
| Public pages | Visitors | Landing and topic pages | The ranked top needs, with their sources | — |
| Public pages | Visitors | About page | — | — |
| Code lane | Developer and ITS | Plugin release | Security review → test site → gsl.noaa.gov | — |

**Arrows:** Survey form → Draft projects: "creates a draft" (solid) · Google Form and
Sheet → Import screen: "downloaded once, then uploaded" (dashed) · Import screen →
Draft projects: "creates drafts" (solid) · Draft projects → Explorer and project
pages: "Publish" (solid) · Synthesis screen → Approved AI service: "Analyze results"
(solid; published projects only) · Approved AI service → Synthesis screen: "top needs
back" (solid) · Synthesis screen → Draft synthesis: "checks links" (solid) · Draft
synthesis → Landing and topic pages: "Mark reviewed" (solid) · Page text → About page:
"Update" (solid) · public pages → Survey form: "Submit a Finding" (thin dotted link).

**For Steph and Emily:** Submissions arrive as drafts: you preview each one as a
project page and publish it. You press Analyze results, see exactly what changed, and
mark it reviewed. Page text and the survey's wording are yours too. A developer is
only needed for code changes. Until an AI service is approved, you paste NotebookLM's
output in instead.

**For ITS:** Stored on GSL's server: project text, editorial fields, the syntheses and
their revision history, and from the form, submitters' emails, job titles and
internal-only answers. Those are readable only by the Firehouse roles, never shown on a
page, and kept as long as the privacy review allows. No IP addresses or uploaded files.
One public form writes to the database, with server-side validation and spam
protection. Logins are a Firehouse Editor role that can change Firehouse content and
nothing else. Outbound requests: the approved AI service (published text only), and
doi.org and the two services it forwards to (Crossref and DataCite), for the titles of
papers with a DOI. Researchers' own links are never fetched. Map tiles load in the
visitor's own browser.

## Diagram 4: Later phases (each its own release, only when its trigger is met)

**Takeaway:** The first release already holds the whole workflow. What remains is
optional, one release at a time, each with its own security review.

Draw Diagram 3's boxes in a muted, outlined style, then add two highlighted changes,
each tagged with its number:

| Tag | Change | Trigger | For Steph and Emily | For ITS |
| --- | --- | --- | --- | --- |
| L1 | **Print-ready pages** (small addition on the public pages): "save as PDF from the browser" | Someone names who will use it | A clean PDF of the top needs | No server change |
| L2 | **Survey plugin** (the Survey form box splits out as its own plugin, which another GSL project's box also uses) | Another GSL project wants a survey | No change to how you work | One more plugin to review, holding code that already passed review |

---

## Part 2 (optional): editor screens

Three low-fidelity screens in the style of the WordPress admin, using real project
titles from `src/content/data/projects.json` and the four topic areas: Observations
and Monitoring; Forecasts and Modeling; Warnings and Immediate Response; Strategic
Adaptation and Institutional Governance.

1. **New submission.** A draft that arrived from the survey form, listed with its
   submitter and date. A panel visible only to the Firehouse roles shows the
   submitter's email and job title, marked "Private, never shown on the site". A
   submission the checks flagged shows the reason, for example "no IRB approval or
   exemption".
2. **Draft project preview.** The project page as visitors will see it, under a bar
   reading "Draft, not public" with "Publish" and "Edit" buttons. Survey answers are
   marked "From the survey, read-only"; editorial fields are editable.
3. **Synthesis review.** An "Analyze results" button, with "or paste a result" beneath
   it, then a checks panel (for example: "A need in Forecasts and Modeling names a
   project that is not published"), then a comparison per topic area: needs added,
   dropped or reworded, mention-count changes, and sources gained or lost. Buttons:
   "Save draft" and "Mark reviewed" (the ember action). After marking, show the
   reviewer's name and the date.

## Part 3: the page frame

The WordPress version keeps The Firehouse's own design for the page itself, but
GSL's header and footer replace the Firehouse header's logos and the footer (see
"Look and feel" in `WORDPRESS-PLAN.md`). This mockup shows the result.

Two frames, side by side: desktop (1440 wide) and phone (390 wide), both in light
mode, both showing the top of the landing page. Stacked top to bottom:

1. **GSL's header:** a plain gray placeholder band labelled "GSL header: gov banner,
   NOAA and GSL logos, GSL's site navigation, light/dark switch". Don't draw GSL's
   real header or invent its design.
2. **The Firehouse bar**, in the real design (`SiteHeader` in
   `src/design-system/`): the FireHouse wordmark, Home, Explore Projects, About and
   the ember Submit a Finding button. No NOAA or GSL logos and no theme toggle, since
   GSL's header has both. On the phone frame the links fold into the drawer button.
3. **The Firehouse landing page** as it looks today: the atmosphere background, the
   hero and the top of the topic cards.
4. **GSL's footer:** a gray placeholder band labelled "GSL footer: federal links",
   shown after a short "…" gap that stands for the rest of the page.

Under the frames, one line: "Everything between the gray bands is The Firehouse's
current design."

## Facts to keep straight

- About 50 submissions a year are expected. There are 8 submissions so far; 7 are
  published. Qualtrics is closed, and all 8 were re-entered through the Google Form,
  which takes new submissions until the plugin's own form replaces it at launch.
- Needs are counted once per project: a need raised several times in one project
  counts once.
- The public address is `gsl.noaa.gov/firehouse/`. The test site's address is not
  given here; label it "Test site".
- Open questions to mark with "?" where they appear: Apache or nginx on GSL's
  servers; whether Cloudflare caches pages; login or network restriction for the
  test copy; which Google account owns the form; which AI service is approved; the
  Paperwork Reduction Act answer in writing.
