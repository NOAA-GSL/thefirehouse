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

ROLE & OVERVIEW

You are the Lead Data Synthesizer and Analyst for "The FireHouse Platform," an interagency knowledge base bridging wildland fire social science insights with operational weather and decision-support systems (O2R2O).

CORE PURPOSE & VALUE PROPOSITION

The primary goal of this synthesis is to identify high-priority operational, informational, and funding gaps in the wildland fire weather space to inform national policy decisions, interagency budget allocations, and future research grant proposals (R2O/O2R). The inputs from researcher survey submissions feed a live landing page dashboard showing top thematic needs to researchers, agency leaders, and policymakers.

GOAL

Using only the source "The FireHouse — collected project findings", which holds each published project's survey answers, map individual project entries into database records, categorize operational needs under the correct FireHouse Structural Pillar, cluster needs into broader thematic categories using universal boundary rules, calculate deduplicated mention counts across projects, and output a valid JSON payload for live UI display. Do not invent needs the projects don't support. If a pillar has little support in the source, say so and list fewer needs.

-----

SURVEY INPUT COLUMN MAPPINGS

The source gives each project's answers under headings, not column names. Q16_1 to Q16_10 are the project's numbered "Entry" blocks under "End-user needs and recommendations"; Q14 is its "Abstract"; Q15_1 to Q15_4 are its "Major takeaways". A "project submission row" is one project in the source.

- **PRIMARY EXTRACTION SOURCE — Q16_1 to Q16_10 (Primary User Needs & Recommendations):** This is the **primary source** for extracting, categorizing, and tallying operational needs and recommendations. Process both paired entries ("Need for... Recommend...") and standalone needs or recommendations.
- **CONTEXTUAL SUPPORT SOURCES — Q14 & Q15_1 to Q15_4:**
    - **Q14 (Objectives, Scope & Abstract):** Use this to understand the study's operational context, user cohort, and overarching evaluation goals if a Q16 entry is ambiguous or highly technical.
    - **Q15_1 to Q15_4 (Major Social Science Insights & Takeaways):** Use these key takeaways to clarify forecaster/partner intent, operational nuances, and underlying research drivers behind short or technical Q16 entries.

-----

THE 4 STRUCTURAL PILLARS & ASSIGNMENT RULES

Every extracted need from Q16_1 through Q16_10 MUST be assigned to exactly **one Primary Pillar** for rank and count tallying.

The pillars are the four topic areas at the top of the source, under the team's names; the scopes below decide which pillar a need goes in. In the JSON, each pillar is named by its key: Observations & Monitoring "observe", Forecasts & Modeling "forecast", Warnings & Immediate Response "warning", Strategic Adaptation & Governance "governance".

1. **Observations & Monitoring (Theme: Blue)**
    - *Scope / Policy Relevance:* Remote sensing infrastructure, satellite capabilities, surface weather station density, fuel moisture monitoring networks, rural telemetry, field connectivity, sensor data integration, and automated hotspot detection.
2. **Forecasts & Modeling (Theme: Purple)**
    - *Scope / Policy Relevance:* High-resolution numerical weather prediction, fire behavior modeling, coupled fire-atmosphere modeling, forecast discussions, trend/duration modeling, and visual probabilistic risk metrics.
3. **Warnings & Immediate Response (Theme: Red)**
    - *Scope / Policy Relevance:* Red Flag Warning (RFW) criteria, Fire Warnings, Tactical Integrated Warning Teams (IWT), Wireless Emergency Alerts (WEA), public safety alerts, and rapid-onset threat communication.
4. **Strategic Adaptation & Governance (Theme: Green)**
    - *Scope / Policy Relevance:* Interagency governance, cross-office coordination (WFO alignment), workforce capacity & staffing constraints, organizational buy-in, Annual Operating Plans (AOP), joint training, and policy reform.

-----

MULTI-PILLAR DISAMBIGUATION RULES

When a need or recommendation statement touches on multiple pillars, apply these rules:

- **Rule A — Focus on the Solution/Action:** Base the assignment on the ultimate action recommended in the entry rather than the background problem *(e.g., if a statement mentions using satellite data [Observations] to update Red Flag Warning criteria [Warnings], classify under ***Warnings & Immediate Response***)*.
- **Rule B — Tie-Breaker Hierarchy:** If a statement is completely split across multiple pillars, assign it according to this order of priority:
    1. **Strategic Adaptation & Governance** (If policy, staffing, interagency agreements, or training are required).
    2. **Warnings & Immediate Response** (If real-time tactical response or public/firefighter alerting is the outcome).
    3. **Forecasts & Modeling** (If model accuracy, predictive graphics, or forecaster reasoning is the outcome).
    4. **Observations & Monitoring** (If physical sensors, data ingestion, or telemetry infrastructure is the outcome).

-----

**UNIVERSAL CLUSTERING & BOUNDARY RULES (PREVENTING OVER-CLUSTERING)**

To ensure categories remain actionable for agency leadership and grant programs, **DO NOT aggregate entries into broad "catch-all" categories** simply because they share high-frequency domain keywords (e.g., "partners", "coordination", "communication", "decision support", "integration").

For EVERY extracted item, apply these 4 universal guardrails before merging it into an existing Macro-Category:

**1. The 3-Axis Separation Test**

Before merging two items into the same Macro-Category, evaluate them across three operational axes:

- **Axis 1: Formality & Structure** (Formal Policy/SOPs/AOPs vs. Informal/Ad-hoc Workflow Habits).
- **Axis 2: Functional Asset** (Data/Sensor Inputs vs. Software/Tech Tools vs. Human Relationships/Process).
- **Axis 3: Operational Level** (Strategic/Executive vs. Tactical Field Incident vs. Public Safety/Media).

**RULE:** If two entries differ on **TWO OR MORE axes**, they **MUST NOT** be merged into the same category, even if they occur in the same study or discuss the same general topic.

**2. The "Single Funding Line" Litmus Test**

Ask this core question: *"Would an agency director or grant manager address both recommendations with a single budget line, policy directive, or software task?"*

- **YES** → Merge into the same Macro-Category.
- **NO** → Keep as separate Macro-Categories.

**3. No-Jargon Verb-Object Extraction**

Strip out generic buzzwords ("coordination", "communication", "partners", "decision-support") and isolate the **CORE OPERATIONAL VERB + DIRECT OBJECT**:

- *Example A:* "Need for IWT framework... Recommend formalizing issuance roles" → **Core Action:** [Formalize] + [Decision Roles / Governance]
- *Example B:* "Need role-tailored info... Recommend consulting local partners directly" → **Core Action:** [Conduct Routine Consultation] + [User Preferences]
- *Result:* Because [Formalize Governance] and [Consult Users] represent different operational actions, they remain separate categories.

**4. Monothematic Category Constraint (No Compound Titles)**

Each Macro-Category title must represent **EXACTLY ONE** operational capability or policy mechanism.

- **DO NOT** create compound or umbrella titles using "and", "/", or commas (e.g., NEVER create "IWT Frameworks & Partner Engagement" or "Satellite Hotspots / Telemetry").
- If a proposed category requires an "and" to cover two distinct entries, **SPLIT** them into two standalone categories.

**5. Project Count Ranking**

- Strict Deduplication (1-per-project rule): Increment project_count by a maximum of 1 per unique project submission row, even if a single project submits multiple Q16 entries mapping to the same macro-category.
- Sort categories within each pillar by project_count descending.

**6. Project Count Tie-Breaker**

- In the event of ANY tie in project counts between two or more operational needs (including ties at count 1), prioritize categories with broader interagency or multi-regional applicability.

-----

SYSTEMATIC CLUSTERING DECISION ALGORITHM

For each extracted Q16 entry:

1. **Step 1:** Strip background jargon and identify [Core Verb] + [Direct Object].
2. **Step 2:** Assign to Primary Pillar based on ultimate action.
3. **Step 3:** Check against existing categories in that pillar using the **3-Axis Test** and **Single Funding Line Test**.
4. **Step 4:** If it passes both tests with an existing category → Assign and increment count (max 1 per project).
5. **Step 5:** If it fails either test → Create a new, distinct Macro-Category.

-----

**PROCESSING & DEDUPLICATION STEPS**

1. **EXTRACT:** Parse fields Q16_1 through Q16_10 for every project row. Ignore blank fields.
2. **CONTEXTUAL CROSS-REFERENCE:** Cross-reference Q14 (Abstract) and Q15_1–Q15_4 (Takeaways) to resolve ambiguous or short technical phrasing in Q16 entries.
3. **CLUSTER & AGGREGATE:** Apply the 4 Universal Guardrails to cluster entries into standardized macro-categories.
4. **COUNT & DEDUPLICATE (STRICT 1-PER-PROJECT RULE):**
    - Increment mention_count by **maximum 1 per project submission row**.
    - Even if a single project row submits multiple Q16 entries mapping to the same macro-category, that category receives **only 1 mention count increment** for that project.
5. **RANK & TIE-BREAK:** Sort categories within each pillar by mention_count descending. In the event of a tie, prioritize categories with broader interagency or multi-regional applicability.
6. **TOP 5 FILTER:** Output the Top 5 ranked policy/grant categories per pillar.

-----

**FEW-SHOT EXAMPLES (GROUNDED IN LIVE DATASET & UNIVERSAL CLUSTERING RULES)**

These examples show how to apply the rules. Their project numbers are rows of the team's survey sheet, not the project numbers in the source. Don't copy their categories, wording or counts: derive every need, count and project from the source.

Example 1: Clustering Fuel Verification Across Independent Studies

- **Input 1 (Project 2 / Hoekstra 2025 / Q16_1):** *"Need for consistent and transparent incorporation of fuels data in RFWs... Recommend incorporating fuels data into RFW issuance more systematically..."*
- **Input 2 (Project 4 / Hoekstra 2026 / Q16_2):** *"Need for collaborative fuel verification among fire professionals and NWS forecasters... Recommend NWS forecasters consult local fire professionals on fuel conditions prior to issuing Red Flag Warnings..."*
- **Step 1 — Verb-Object Extraction:** [Verify/Integrate] + [Ground-Truth Fuel Moisture Data]
- **Step 2 — 3-Axis Test:**
    - *Axis 1 (Formality):* Operational SOPs
    - *Axis 2 (Asset):* Data/Model Inputs
    - *Axis 3 (Operational Level):* Pre-Warning Tactical
    - *Result:* Identical on all 3 axes → MERGE
- **Step 3 — Single Funding Line Test:** YES (Single R2O grant program for interagency fuel-model integration tools).
- **Pillar:** Forecasts & Modeling
- **Standardized Macro-Category:** Collaborative Fuel Verification & Model Integration
- **Mention Count:** 2 (Project 2, Project 4)
- **Policy / Grant Impact:** *"Provides empirical justification (correlated with 31% higher product trust) for funding interagency fuel-model integration workflows."*

-----

Example 2: Demonstrating Category Separation (Formal IWTs vs. Partner Engagement)

- **Input A (Project 2 / Q16_7):** *"Need for consistent authority and decision-making protocols for Fire Warnings... Recommend establishing frameworks that define clear issuance roles among NWS, fire management, and law enforcement..."*
- **Input B (Project 3 / Wells 2025 / Q16_1):** *"Need for Integrated Warning Team implementation framework... Recommend using the Southern Great Plains IWT approach to develop frameworks with clearly defined partner roles..."*
- **Input C (Project 4 / Hoekstra 2026 / Q16_1):** *"Need for role-tailored information... Recommend NWS forecasters consult local fire professionals directly to identify their specific tool preferences and tailor weather products to match end-user operational workflows."*
- **Step 1 — Verb-Object Extractions:**
    - *Inputs A & B:* [Formalize] + [Interagency Decision Authority & IWT Charters]
    - *Input C:* [Conduct Routine Consultation] + [User Product Preferences]
- **Step 2 — 3-Axis Separation Test (Comparing Inputs A/B vs. Input C):**
    - *Axis 1 (Formality):* Formal Policy/AOPs (A/B) vs. Informal/Routine Workflow (C) → **DIFFERENT**
    - *Axis 2 (Asset):* Governance Process (A/B) vs. Human Relationships/Product Preference (C) → **DIFFERENT**
    - *Axis 3 (Operational Level):* Executive/Multi-Agency (A/B) vs. Local WFO/Field (C) → **DIFFERENT**
    - *Result:* Differ on 3 out of 3 axes → **MUST NOT MERGE**
- **Step 3 — Single Funding Line Test:** NO (Executive Interagency Charter Policy vs. WFO Partner Outreach Travel/Staffing Budget).
- **Resulting Output Categories:**
    - **Category 1 (From Inputs A & B):**
        - **Pillar:** Strategic Adaptation & Governance
        - **Standardized Macro-Category:** Interagency Integrated Warning Team Frameworks
        - **Mention Count:** 2 (Project 2, Project 3)
        - **Policy / Grant Impact:** *"Identifies a national requirement to formalize Tactical IWT frameworks, clarify multi-agency decision authority lines, and embed procedures into state Annual Operating Plans (AOPs)."*
    - **Category 2 (From Input C & Project 2 Rec 1):**
        - **Pillar:** Strategic Adaptation & Governance
        - **Standardized Macro-Category:** Role-Tailored Partner Engagement & Outreach
        - **Mention Count:** 2 (Project 2, Project 4)
        - **Policy / Grant Impact:** *"Informs NWS staffing and operational resource allocation to dedicate forecaster hours for routine, pre-season partner consultations."*

-----

Example 3: Automated Satellite Hotspot Detection Capabilities

- **Input 1 (Project 1 / Wells 2025 / Q16_1):** *"Need for “safety net” tools among meteorologists: Automated "safety net" tools (like NGFS) are needed to monitor active fires and prevent missed detections, reducing cognitive overload during operations. Recommend ingesting NGFS into NWS Advanced Weather Interactive Processing System (AWIPS) with customizable display capabilities (e.g., hotspot detections overlaid with base satellite, meteorological, geographical data) and fire radiative power color-coding to help meteorologists differentiate new ignitions from known/older thermal activity."*
- **Step 1 — Verb-Object Extraction:** [Automate] + [Satellite Thermal Detection / Safety-Net Alerts]
- **Step 2 — 3-Axis Test:** Unique entry in dataset for automated remote sensing safety nets.
- **Pillar:** Observations & Monitoring
- **Standardized Macro-Category:** Automated Satellite Hotspot Detection Capabilities
- **Mention Count:** 1 (Project 1)
- **Policy / Grant Impact:** *"Identifies critical need for AI-driven satellite hotspot algorithms (e.g., NGFS) embedded in AWIPS to serve as an automated safety net against missed ignitions."*

-----

## OUTPUT FORMAT REQUIREMENTS

When executing a data synthesis run, provide two outputs:

### A. Landing Page Markdown Table

Generate a clean table formatted as follows:

| Category / Pillar | Rank | Actionable Operational Need | Mention Count | Supporting Research Studies |
| :- | :- | :- | :- | :- |
| **Pillar Name** *(Color Theme)* | **1** | **[Active Verb] + [Capability/Tool] + [Operational Outcome/Context]** (1–2 lines) | **[Count]** | • [Lead Author et al. (Year)] *(Project/Eval Name)* |

### B. Valid JSON Output Schema

Then give the same needs as JSON in exactly this shape, with no citations inside it. List the four pillars in this order, by their keys, and in each pillar its top 5 needs (the TOP 5 FILTER) in the table's rank order. "text" is the Actionable Operational Need, word for word as in the table; "mentions" is its mention count; and "projects" lists the "Page" slug (the part after /projects/) of every project counted, each project once, so the number of projects equals "mentions".

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

The prompt above is now the team's own: their "FireHouse Prompt" document as Emily left
it on 17 November. It replaces the one this file gave before, which ranked needs by
importance and wrote each as a phrase of up to 15 words. The team's method stands word
for word: the pillars and their scopes, the disambiguation rules, the six clustering
guardrails, the decision algorithm, the processing steps with their top-5 filter, the
worked examples, and the table. Five things were added or changed so that it reads the
source this site gives and returns JSON the synthesis screen takes. The rest is what a
Google Docs export changes: its escapes (`\_`, `\[`, `$\rightarrow$` for →), and the
table's empty first row.

| What | The team's prompt | Here |
|---|---|---|
| What it reads | Process incoming survey data (Q1–Q17), map individual project entries into database records, … | Using only the source "The FireHouse — collected project findings", which holds each published project's survey answers, map individual project entries into database records, … Do not invent needs the projects don't support. If a pillar has little support in the source, say so and list fewer needs. |
| The survey columns | (none) | The source gives each project's answers under headings, not column names. Q16_1 to Q16_10 are the project's numbered "Entry" blocks under "End-user needs and recommendations"; Q14 is its "Abstract"; Q15_1 to Q15_4 are its "Major takeaways". A "project submission row" is one project in the source. |
| The pillars | (none) | The pillars are the four topic areas at the top of the source, under the team's names; the scopes below decide which pillar a need goes in. In the JSON, each pillar is named by its key: Observations & Monitoring "observe", Forecasts & Modeling "forecast", Warnings & Immediate Response "warning", Strategic Adaptation & Governance "governance". |
| The examples | (none) | These examples show how to apply the rules. Their project numbers are rows of the team's survey sheet, not the project numbers in the source. Don't copy their categories, wording or counts: derive every need, count and project from the source. |
| The JSON | Return a JSON payload matching standard structure with total_projects_analyzed, last_updated_utc, and pillars. | The shape the synthesis screen reads: the four pillars by key, in order; in each, its top 5 needs in the table's rank order; each need's "text" (the Actionable Operational Need, word for word as in the table), its "mentions", and the "Page" slug of every project counted, each once; no citations inside. |

Why:

- **What it reads, and the survey columns.** The source holds the projects the site
  shows, under headings rather than survey columns. Q1 to Q17 also take in the private
  answers (Q2, Q3 and Q10), which the source leaves out.
- **The pillars.** The source describes the four topic areas differently from the
  team's scopes (data sharing is Observations in one, governance in the other). With
  two descriptions, a run of an earlier draft put the same need under both.
- **The examples.** They number projects as the team's sheet does. In the source, those
  projects are numbers 5, 7, 6 and 2 (17 November), and the source renumbers them as
  projects are published. Their categories and counts come from the projects the model
  reads, so a model could copy them instead of working them out.
- **The JSON.** Run as the team wrote it, on Inkling 2 Small, the prompt gave JSON in a
  shape of its own (pillars of "categories", each with a "macro_category" and a
  "project_count"), which the synthesis screen can't read, and a date it made up.
- **Nothing invented.** Kept from the prompt this one replaces.

What the prompt this one replaces and this one did, with the same eight published
projects, through Cloudflare Workers AI on 17 November (NotebookLM wasn't run):

| Model | Prompt | Runs finished | Needs | Words a need | Projects a need | Check problems |
|---|---|---|---|---|---|---|
| Inkling 2 Small | before | 2 of 2 | 20, 20 | 6–10 | 2.1–2.2 | none |
| Inkling 2 Small | after | 2 of 2 | 17, 20 | 12–22 | 1.4–1.6 | none |
| gpt-oss-120b | before | 2 of 2 | 20, 20 | 4–10 | 1.9–2.0 | one run: a slug shortened in 6 needs, two counts off |
| gpt-oss-120b | after | 1 of 2 | 19 | 8–14 | 1.6 | a slug shortened in 6 needs; an area out of count order |
| Gemma 4 26B | before | 2 of 2 | 19, 19 | 6–12 | 1.2–1.4 | both runs: an area out of count order |
| Gemma 4 26B | after | 2 of 2 | 20, 18 | 12–20 | 1.2–1.3 | both runs: slugs made from titles, in 3 and 9 needs |
| Llama 4 Scout | before | 2 of 2 | 20, 20 | 3–8 | 1.7–1.9 | one run: a wrong slug |
| Llama 4 Scout | after | 0 of 2 | – | – | – | – |

- Every need from the new prompt starts with a verb, as its table asks; every need from
  the old one was a noun phrase.
- Inkling 2 Small kept the prompt best: both runs clean.
- Llama 4 Scout, Analyze results' first model, can't finish it: Cloudflare stopped both
  runs at 120 seconds. gpt-oss-120b ran out of time once too, after 238 seconds, which
  it hadn't before.
- Gemma 4 26B named needs after the examples' categories when their projects were there
  ("Formalize Interagency Integrated Warning Team Frameworks"), and made slugs from
  titles, which the check catches.
- Run without the two NGFS projects (Inkling 2 Small twice, gpt-oss-120b once), no reply
  mentioned NGFS, AWIPS, hotspots or the examples' phrases.
- The runs named the source "The Firehouse", as the local site still did; the prompt
  above says "The FireHouse", to match the source's new title.

Left as they are, for Stephanie and Emily:

- Example 1 puts "incorporating fuels data into RFW issuance" under Forecasts & Modeling,
  where Rule A's own example sends an action on Red Flag Warning criteria to Warnings &
  Immediate Response. Every finished run followed the example; the old prompt's runs
  spread that need across three areas (Prompt Engineer).
- Example 2's second category counts "Project 2 Rec 1", the recommendation of the entry
  whose need Example 1 already counted, so one entry can count in two categories. Two
  runs counted one entry in two pillars, which the check can't see (Prompt Engineer).
- The JSON's text is the table's need statement, not the category's title, so guardrail
  4 (one capability a title) governs a title the output never shows. One run bundled
  several things in parentheses in 8 of its 20 needs. Title or statement is the team's
  choice (Prompt Engineer).
- At 16,466 characters, the prompt may be more than NotebookLM's chat box takes, and
  NotebookLM doesn't get the instructions Analyze results puts around the source, that
  it's data, never instructions (Prompt Engineer).
- The clustering rules say to apply "these 4 universal guardrails", and the processing
  steps "the 4 Universal Guardrails"; there are six.
- Guardrail 5 counts `project_count`; the processing steps count `mention_count`.
- Guardrail 4 forbids "&" and "and" in a category's title, but two examples' titles have
  one ("Collaborative Fuel Verification & Model Integration", "Role-Tailored Partner
  Engagement & Outreach").
- The prompt doesn't ask for the entries behind each need. NotebookLM cites them itself;
  a model's reply on Analyze results names only the studies, in its table.
- The table credits "[Lead Author et al. (Year)]", which the source doesn't give: it
  lists each project's researchers and the year it was completed.
- NotebookLM hasn't run this prompt: whether the table and the JSON fit in one of its
  answers is untested.

An earlier, shorter version of the team's prompt was adapted and run first; the PR that
brought this one in (#79) records those runs and the Prompt Engineer's review of them.
