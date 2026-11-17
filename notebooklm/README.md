# NotebookLM synthesis

The four topic-area "top needs" on the landing page and topic pages are synthesized
across **all** published projects in Google NotebookLM, reviewed by the team, and
then written into `src/content/data/topicSummaries.json` for this site, or, under the
WordPress plugin, published from FireHouse → Synthesis (below). No project is
assigned to a topic area. This folder holds what the notebook reads.

| File | What it is |
| --- | --- |
| `firehouse-findings.md` | **Generated — do not hand-edit.** Every published project's abstract, takeaways, needs and recommendations, word for word, plus the four topic-area definitions. |
| `draft-synthesis-2026-09-17.md` | The current, **unreviewed** draft behind `topicSummaries.json`, with every need traced to the survey entries it counts. Replace or confirm it with a NotebookLM pass. |
| `reviewed-synthesis-2026-10-27.json` | The team's reviewed table of 27 October, from Stephanie's email of 3 November, transcribed word for word to the prompt's JSON (16 November). It passes the synthesis screen's checks, which look at its shape, counts and order, not at whether each project supports the need: that is the Research Synthesist's check, which goes to Stephanie and Emily, who decide on it and mark it reviewed. Not yet on the site. |

## Under the WordPress plugin

Everything happens on **FireHouse → Synthesis**. FireHouse Editors and administrators
can use it.

On a site with a Workers AI key, **Analyze results** on the same screen has a model
draft the synthesis in place of steps 1 and 2, and its Check button fills step 4's box
in place of the copying. The draft still gets step 3's review. The FireHouse guide's
synthesis page says how. Its prompt starts as the one below: the plugin's copy,
`wp-content/plugins/firehouse/shared/synthesis-prompt.txt`, is held to it by a test,
and FireHouse → Settings can change what the plugin sends.

1. **Press Download NotebookLM source.** In the notebook, delete the old
   `firehouse-findings.md` source and upload the one you downloaded. Keep it the only
   source. It holds the projects the site shows, word for word. Never add the team's
   "Submission Data" sheet or a survey export: they hold submitters' emails, job
   titles and Q10 answers, and projects the site doesn't show.
2. **Run the prompt below** in the notebook chat.
3. **Review** each need against the projects it came from, with NotebookLM's
   citations, and edit the JSON: the published wording is the team's, not the
   model's.
   - Order each area's needs by project count, highest first: the screen refuses any
     other order. For a tie, put first the need with broader interagency or
     multi-regional applicability, as the About page says; the screen leaves ties to
     you. The prompt below asks for this order, tie-break included, but check it: a
     model doesn't always keep it.
   - Each need's count must equal the number of projects it lists. If you take a
     project out of a need, lower its count to match.
   - A project can be named by its page slug or by the source's "Project ID".
4. **Copy only the JSON,** from its first `[` to its last `]`, and keep a copy of your
   edited version until it's published. Paste it into "The JSON the prompt gave" and
   press **Check the synthesis**. Fix whatever it lists, in the box, and check again.
   Nothing is written until it finds nothing wrong.
5. **Mark it reviewed.** "Reviewed by" starts with your name and "Made with" with
   NotebookLM; change either if it isn't right. Then press **Mark reviewed and
   publish**, within thirty minutes of the check and without opening FireHouse →
   Synthesis again, which drops the check. The screen records today's date and how
   many projects the site shows, and the topic pages and the landing page show the
   synthesis at once.
6. **To undo it,** open each topic under FireHouse → Topics, go to its Revisions, and
   restore the one before. Do this for all four topics. A restore also puts back the
   topic's label, description and what it covers as they were then.

## After each survey batch (this site)

1. **Import the export.** This also regenerates `firehouse-findings.md`:

   ```bash
   node scripts/import-survey.mjs path/to/export.xlsx
   ```

   If you only changed `scripts/survey-editorial.json` (for example, publishing a
   project), regenerate the source on its own:

   ```bash
   node scripts/notebooklm-source.mjs
   ```

2. **Replace the notebook's source.** In the Firehouse notebook, delete the old
   `firehouse-findings.md` source and upload the new one. Keep it the *only* source,
   so the synthesis can't draw on anything that isn't on the site. Never add the
   survey export or the team's sheet, which hold submitters' emails.

3. **Run the prompt below** in the notebook chat.

4. **Review.** Use NotebookLM's citations to check each need against the projects it
   came from. Edit, merge or drop needs as needed — the published wording is the
   team's, not the model's.

5. **Update `src/content/data/topicSummaries.json`.** For each topic, set `topNeeds`
   (up to five, each `{ "text", "mentions", "projects" }` — `projects` are page slugs
   from the source, e.g. `igniting-insight-evaluating-nws-fire-weather-products`),
   `sourceCount` (the number of projects in the document), `updatedAt` (today, as
   `YYYY-MM-DD`), `model: "NotebookLM"` and `reviewedBy`. Until `reviewedBy` is set,
   the site labels the needs as a draft. The landing page and topic pages both show
   all five, numbered, with their project counts; topic pages also link each need to
   its projects.

## Prompt

```text
# THE FIREHOUSE PLATFORM: DATA SYNTHESIS & AI CATEGORIZATION PROMPT

## ROLE & OVERVIEW

You are the Lead Data Synthesizer and Analyst for "The FireHouse Platform," an interagency knowledge base bridging wildland fire social science insights with operational weather and decision-support systems (O2R2O).

## CORE PURPOSE

Using only the source "The FireHouse — collected project findings", extract primary user needs and recommendations (each project's numbered "Entry" blocks under "End-user needs and recommendations"), cross-reference contextual metadata (each project's "Abstract" and "Major takeaways"), cluster needs into standardized macro-categories, calculate deduplicated project mention counts, and output both an actionable landing page table and a valid JSON payload. Do not invent needs the projects don't support. If a pillar has little support in the source, say so and list fewer needs.

--------------------------------------------------------------------------------

## 1. THE 4 STRUCTURAL PILLARS & ASSIGNMENT RULES

The four pillars are the four topic areas defined at the top of the source. Every extracted need MUST be assigned to exactly ONE Primary Pillar based on the ultimate solution or action recommended:

1. **Observations and Monitoring (JSON key "observe"; Theme: Blue)**: Remote sensing, satellite detection, telemetry, surface networks, fuel moisture monitoring, rural connectivity, and sensor integration.
2. **Forecasts and Modeling (JSON key "forecast"; Theme: Purple)**: High-resolution NWP, fire behavior/atmosphere modeling, forecast discussions, trend/duration messaging, and visual probabilistic risk graphics.
3. **Warnings and Immediate Response (JSON key "warning"; Theme: Red)**: Red Flag Warnings, Fire Warnings, Tactical Integrated Warning Teams (IWT), Wireless Emergency Alerts (WEA), and rapid-onset alerting.
4. **Strategic Adaptation and Institutional Governance (JSON key "governance"; Theme: Green)**: Interagency governance, multi-agency decision authority, Annual Operating Plans (AOP), staffing/capacity, joint training, and policy reform.

*Multi-Pillar Tie-Breaker Hierarchy*: Strategic Adaptation and Institutional Governance > Warnings and Immediate Response > Forecasts and Modeling > Observations and Monitoring.

--------------------------------------------------------------------------------

## 2. UNIVERSAL CLUSTERING & DEDUPLICATION RULES

To prevent overly broad "catch-all" categories, apply these guardrails before merging items:

1. **The 3-Axis Separation Test**: Evaluate items across (a) *Formality/Structure* (SOPs/AOPs vs. ad-hoc workflow), (b) *Functional Asset* (Data/Sensors vs. Software/Tech vs. Human Governance), and (c) *Operational Level* (Strategic/Executive vs. Tactical Field vs. Public Safety). If items differ on **2 or more axes**, DO NOT MERGE.
2. **The Single Funding Line Test**: Ask: *"Would an agency director or grant program address both items with a single budget line or policy directive?"* If NO, keep separate.
3. **Verb-Object Extraction**: Strip out generic buzzwords ("coordination", "communication") to isolate the CORE OPERATIONAL VERB + DIRECT OBJECT.
4. **Strict Deduplication (1-per-project rule)**: Increment `mention_count` by a **maximum of 1 per unique project**, even if a single project has multiple "Entry" blocks mapping to the same macro-category.
5. **Mention-Count Ranking & Tie-Breaker**: Sort categories within each pillar by `mention_count` descending. In the event of ANY tie in mention counts between two or more operational needs (including ties at count 1), prioritize categories with broader interagency or multi-regional applicability. List at most 5 needs in each pillar.

--------------------------------------------------------------------------------

## 3. LANDING PAGE NEED FORMULATION RULES (ACTIONABLE & VERB-DRIVEN)

When naming and describing each top operational need for display, DO NOT generate vague noun phrases (e.g., do not write "Automated Satellite Hotspot Detection Capabilities").

Instead, construct an **Actionable Operational Need Statement** following these guidelines:

* **Length**: Concise 1 to 2 lines (15 to 30 words).
* **Starting Word**: Always begin with an **active operational verb** (e.g., *Automate*, *Deliver*, *Expose*, *Overlay*, *Systematically incorporate*, *Establish*, *Deploy*, *Institutionalize*, *Conduct*).
* **Core Components**: Must explicitly state:
  1. The **active capability or task** to be executed.
  2. The **specific tool, platform, or data asset** involved (e.g., AWIPS, WEA, NGFS, AOPs).
  3. The **operational goal or user friction being resolved** (e.g., "to reduce cognitive overload during high-tempo operations", "to support proactive resource scaling").

--------------------------------------------------------------------------------

## 4. OUTPUT FORMAT REQUIREMENTS

When executing a data synthesis run, provide two outputs:

### A. Landing Page Markdown Table

Generate a clean table formatted as follows:

| Category / Pillar               | Rank  | Actionable Operational Need                                                             | Mention Count | Supporting Research Studies                           |
| ------------------------------- | ----- | --------------------------------------------------------------------------------------- | ------------- | ----------------------------------------------------- |
| **Pillar Name** *(Color Theme)* | **1** | **[Active Verb] + [Capability/Tool] + [Operational Outcome/Context]** (1–2 lines) | **[Count]** | • [Lead Author et al. (Year)] *(Project/Eval Name)* |

### B. Valid JSON Output

Then give the same needs as JSON in exactly this shape, with no citations inside it. List the four pillars in this order, by their JSON keys, and each pillar's needs in the table's rank order. "text" is the Actionable Operational Need Statement, "mentions" is its `mention_count`, and "projects" lists the "Page" slug (the part after /projects/) of every project counted, each project once, so the number of projects equals "mentions".

[
  {
    "topic": "observe",
    "topNeeds": [
      { "text": "...", "mentions": 2, "projects": ["project-slug", "another-slug"] }
    ]
  },
  { "topic": "forecast",   "topNeeds": [ ... ] },
  { "topic": "warning",    "topNeeds": [ ... ] },
  { "topic": "governance", "topNeeds": [ ... ] }
]
```

## Prompt changes

Stephanie and Emily clear every change to the prompt above (CLAUDE.md, "When a person
decides"). Each change is recorded here: the lines that changed, why, what the old and
new prompts did with the same projects, and who cleared it, on what text, and when.

### 17 November 2026: the team's prompt

**Cleared:** not yet.

The prompt above is now the team's, as they ran it on 17 November, in place of the one
this file gave before, which ranked needs by importance and wrote each as a phrase of
up to 15 words. The team's method stands word for word: the four pillars and the order
that settles a need spanning several, the clustering and counting rules, the need
statements, and the table. Six things changed, so that it reads the source this site
gives and returns JSON the synthesis screen takes. The escapes a Google Docs export
leaves (`&amp;`, `\_`, `\-`) were taken out too.

| What | The team's prompt | Here |
|---|---|---|
| What it reads | Process incoming Qualtrics survey submission data (Q1–Q17_5), extract primary user needs and recommendations (Q16_1–Q16_10), cross-reference contextual metadata (Q14, Q15_1–Q15_4), … | Using only the source "The FireHouse — collected project findings", extract primary user needs and recommendations (each project's numbered "Entry" blocks under "End-user needs and recommendations"), cross-reference contextual metadata (each project's "Abstract" and "Major takeaways"), … |
| Nothing invented | (none) | Do not invent needs the projects don't support. If a pillar has little support in the source, say so and list fewer needs. |
| The pillars' names | Observations & Monitoring; Forecasts & Modeling; Warnings & Immediate Response; Strategic Adaptation & Governance | The source's four topic areas, each with its key: Observations and Monitoring ("observe"); Forecasts and Modeling ("forecast"); Warnings and Immediate Response ("warning"); Strategic Adaptation and Institutional Governance ("governance"). The same names in the tie-breaker, and a line saying they're the topic areas defined at the top of the source. |
| What counts once | …a maximum of 1 per unique project submission row, even if a single project submits multiple Q16 entries mapping to the same macro-category. | …a maximum of 1 per unique project, even if a single project has multiple "Entry" blocks mapping to the same macro-category. |
| How many | (no limit) | List at most 5 needs in each pillar. |
| The JSON | Return a JSON payload matching standard structure with `total_projects_analyzed`, `last_updated_utc`, and `pillars`. | The shape the synthesis screen reads: the four pillars by key, in order; each need's text (the need statement), its mentions, and the "Page" slug of every project counted, each once; the needs in the table's rank order; no citations inside. |

Why:

- **What it reads.** The source holds the projects the site shows, under headings, not
  survey columns. Q1 to Q17_5 also take in the private answers (Q2, Q3 and Q10), which
  the source leaves out.
- **The JSON.** Run as the team wrote it, on Inkling 2 Small, the prompt gave a table
  and JSON in a shape of the model's own, which the synthesis screen can't read: one
  need in each pillar, a date it made up, and projects named by author rather than by
  page.
- **How many.** The site shows up to five needs a topic area, and the screen refuses
  more.
- **Nothing invented.** Kept from the prompt this one replaces.

What the prompt this one replaces and this one did, with the same eight published
projects, through Cloudflare Workers AI on 17 November (NotebookLM wasn't run):

| Model | Prompt | Runs finished | Needs | Words a need | Projects a need | Check problems |
|---|---|---|---|---|---|---|
| Inkling 2 Small | before | 2 of 2 | 20, 20 | 6–10 | 2.1–2.2 | none |
| Inkling 2 Small | after | 3 of 3 | 17, 17, 20 | 12–30 | 1.5–1.8 | none |
| gpt-oss-120b | before | 2 of 2 | 20, 20 | 4–10 | 1.9–2.0 | one run: a slug shortened in 6 needs, two counts off |
| gpt-oss-120b | after | 3 of 3 | 9, 20, 18 | 13–23 | 2.4–3.3 | one run: the same slug shortened, in 9 needs |
| Gemma 4 26B | before | 2 of 2 | 19, 19 | 6–12 | 1.2–1.4 | both runs: an area out of count order |
| Gemma 4 26B | after | 3 of 3 | 16, 14, 14 | 12–19 | 1.3–1.4 | one run: a slug made from a title |
| Llama 4 Scout | before | 2 of 2 | 20, 20 | 3–8 | 1.7–1.9 | one run: a wrong slug |
| Llama 4 Scout | after | 1 of 3 | 6 | 7–9 | 2.2 | none |

- Every need from the new prompt starts with a verb; every need from the old one was a
  noun phrase. No area came out of count order with the new prompt.
- Inkling 2 Small kept the prompt best: every run clean, needs of 12 to 30 words, and a
  table naming the studies behind each need.
- Llama 4 Scout, Analyze results' first model, mostly can't finish the new prompt:
  Cloudflare stopped two of its three runs at 120 seconds, and the one that finished
  gave six needs of seven to nine words. With the old prompt it took 120 seconds too.
- gpt-oss-120b drew most broadly: in one run a single governance need counted all
  eight projects, the catch-all the prompt's clustering tests are meant to stop, and
  that run gave nine needs in all.
- The runs named the source "The Firehouse", as the local site still did; the
  prompt above says "The FireHouse", to match the source's new title.
