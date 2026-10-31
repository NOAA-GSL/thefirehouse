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
3. **WordPress, first release:** the workflow under one roof in GSL's WordPress.
4. **Later phases:** what could move inside WordPress later, and what each move
   would take.

**Part 2 (optional): three editor-screen wireframes** for the first WordPress
release: the Import screen, a draft project preview, and the synthesis review.

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

- Draw NotebookLM, or any AI tool, talking directly to the website. In every diagram
  a person carries the AI's output in.
- Show a survey form inside WordPress anywhere except Later phases (L3).
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

**Takeaway:** Once data arrives, Stephanie and Emily do everything themselves in
GSL's WordPress, and nothing goes public without their review.

**Zones:** Outside WordPress (Google) · GSL WordPress: staff screens (login required)
· GSL WordPress: public pages. Add a thin lane along the bottom, spanning both WordPress
zones, for code changes.

| Zone | Who | Box | What happens there | Markers |
| --- | --- | --- | --- | --- |
| Outside WordPress | Researchers | Google Form | Replaces the Qualtrics survey | Personal data, Open question: "Whose Google account?" |
| Outside WordPress | Steph & Emily | Response Sheet | The form's linked Google Sheet; responses land here, ready to download | Personal data |
| Outside WordPress | Steph & Emily | AI tool | NotebookLM with the team's prompt; returns JSON | — |
| Staff screens | Steph & Emily | Import screen | Shows a preview first; drops email and job title; the uploaded file is not kept | — |
| Staff screens | Steph & Emily | Draft projects | Preview each project page as visitors will see it, then Publish | Review step |
| Staff screens | Steph & Emily | Synthesis screen | Paste the AI's JSON; flags any project it doesn't recognize | — |
| Staff screens | Steph & Emily | Draft synthesis | Shows what changed since the published version, then Mark reviewed | Review step |
| Staff screens | Steph & Emily | Page text | Edit About and landing page words in place; layout is locked | — |
| Public pages | Visitors | Explorer and project pages | Inside GSL's header and footer | — |
| Public pages | Visitors | Landing and topic pages | The ranked top needs, with their sources | — |
| Public pages | Visitors | About page | — | — |
| Code lane | Developer and ITS | Plugin release | Security review → test site → gsl.noaa.gov | — |

**Arrows:** Google Form → Response Sheet: "responses land" (solid) · Response Sheet →
Import screen: "downloaded, then uploaded" (dashed) · Import screen → Draft projects:
"creates drafts" (solid) · Draft projects → Explorer and
project pages: "Publish" (solid) · Synthesis screen → AI tool: "download AI source"
(dashed; the source holds published projects only) · AI tool → Synthesis screen:
"paste JSON" (dashed) · Synthesis screen → Draft synthesis: "checks links" (solid) ·
Draft synthesis → Landing and topic pages: "Mark reviewed" (solid) · Page text →
About page: "Update" (solid) · public pages → Google Form: "Submit a Finding opens
the form" (thin dotted link).

**For Steph and Emily:** You download the form's response Sheet, upload it, and
preview each new project before publishing it. You paste the AI's output, see exactly what changed, and mark
it reviewed. Text edits are yours too. A developer is only needed for code changes.

**For ITS:** Stored on GSL's server: project text, editorial fields, the syntheses and
their revision history. Not stored: emails, job titles, IP addresses or uploaded
files. Public pages are read-only, with no forms. Logins are a Firehouse Editor role
that can change Firehouse content and nothing else. Outbound requests: doi.org and the
two services it forwards to (Crossref and DataCite), for the titles of papers with a
DOI. Researchers' own links are never fetched. Map tiles load in the visitor's own browser. Personal data stays in
the form's Google Sheet; which Google account holds it is still open.

## Diagram 4: Later phases (each its own release, only when its trigger is met)

**Takeaway:** Each piece can move inside WordPress later, one at a time, each with its
own security review.

Draw Diagram 3's boxes in a muted, outlined style, then add four highlighted changes,
each tagged with its number:

| Tag | Change | Trigger | For Steph and Emily | For ITS |
| --- | --- | --- | --- | --- |
| L1 | Synthesis screen → **Approved LLM API** (new box, outside WordPress): "Run synthesis" (solid) | An LLM API approved for this use | No more copying between the AI tool and WordPress; the review step stays | One new outbound host and one key; sends only text that is already public |
| L2 | Response Sheet → Import screen becomes solid: "Sync now" | Downloading by hand becomes a chore, for example if WPO makes submission a funding requirement | No more downloads | A read-only Google key stored on GSL's server; fetches only the public columns; needs the form owner's agreement |
| L3 | **Survey form on gsl.noaa.gov** (new box in public pages, Personal data marker) → Draft projects: "creates drafts" | Google Forms stops being workable | Submissions arrive as drafts, as before | Personal data on GSL's server and a public form; needs a Paperwork Reduction Act check and a privacy review |
| L4 | **Print-ready pages** (small addition on the public pages): "save as PDF from the browser" | Someone names who will use it | A clean PDF of the top needs | No server change |

---

## Part 2 (optional): editor screens

Three low-fidelity screens in the style of the WordPress admin, using real project
titles from `src/content/data/projects.json` and the four topic areas: Observations
and Monitoring; Forecasts and Modeling; Warnings and Immediate Response; Strategic
Adaptation and Institutional Governance.

1. **Import screen.** Upload the response Sheet downloaded from Google. The preview
   lists new projects (arriving as drafts), updated projects, and excluded rows with
   their reasons, for example "no IRB approval or exemption". If a question was
   reworded, the preview asks which column now holds it. Nothing is written until
   "Import" is pressed. After import, each new draft links to its preview.
2. **Draft project preview.** The project page as visitors will see it, under a bar
   reading "Draft, not public" with "Publish" and "Edit" buttons. Survey answers are
   marked "From the survey, read-only"; editorial fields are editable.
3. **Synthesis review.** A paste box, then a checks panel (for example: "A need in
   Forecasts and Modeling names a project that is not published"), then a comparison
   per topic area: needs added, dropped or reworded, mention-count changes, and
   sources gained or lost. Buttons: "Save draft" and "Mark reviewed" (the ember
   action). After marking, show the reviewer's name and the date.

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

- About 50 submissions a year are expected. The latest Qualtrics export has 8
  responses; 7 are published. New responses come from the Google Form that replaced
  Qualtrics.
- The public address is `gsl.noaa.gov/firehouse/`. The test site's address is not
  given here; label it "Test site".
- Open questions to mark with "?" where they appear: Apache or nginx on GSL's
  servers; whether Cloudflare caches pages; login or network restriction for the
  test copy; one mention per project or per entry in the synthesis counts; which
  Google account owns the form.
