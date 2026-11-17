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
draft the synthesis in place of steps 1 to 3; the FireHouse guide's synthesis page says
how. Its prompt starts as the one below: the plugin's copy,
`wp-content/plugins/firehouse/shared/synthesis-prompt.txt`, is held to it by a test,
and FireHouse → Settings can change what the plugin sends.

1. **Press Download NotebookLM source.** In the notebook, delete the old
   `firehouse-findings.md` source and upload the one you downloaded. Keep it the only
   source. It holds the projects the site shows, word for word.
2. **Run the prompt below** in the notebook chat.
3. **Review** each need against the projects it came from, with NotebookLM's
   citations, and edit the JSON: the published wording is the team's, not the
   model's.
   - Order each area's needs by project count, highest first: the screen refuses any
     other order. For a tie, put first the need with broader interagency or
     multi-regional applicability, as the About page says; the screen leaves ties to
     you. The prompt below asks for the most important need first, so reorder its
     needs before you check them.
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
   so the synthesis can't draw on anything that isn't on the site.

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
Using only the source "The Firehouse — collected project findings", synthesize the
most important end-user needs across ALL of the projects for each of the four topic
areas defined at the top of the source: Observations and Monitoring; Forecasts and
Modeling; Warnings and Immediate Response; Strategic Adaptation and Institutional
Governance.

For each topic area:
- List up to 5 needs, most important first. Weigh a need higher when several projects
  raise it independently.
- For each need, count its mentions: the number of projects that raise it. A project
  counts once, however many of its numbered "Entry" blocks raise the need. The same
  entry may count toward needs in more than one topic area.
- Write each need as one plain-language phrase of no more than 15 words, not a
  sentence. Start with what is needed. Name the end-user group when the projects do.
- Cite the entries each need draws on, and list the "Page" slug of every project
  those entries come from (the part after /projects/), each project once.
- Do not invent needs the projects don't support. If a topic area has little
  support in the source, say so and list fewer needs.

Then give the result as JSON in exactly this shape, with no citations inside it:

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

### 16 November 2026: the example need, and each project once

**Cleared:** not yet.

Two lines changed; the rest of the prompt is as it was.

| Line | Before | After |
|---|---|---|
| The need's shape | Write each need as one plain-language phrase of no more than 15 words, starting with a noun (e.g. "Longer-lead outlooks for planning windows beyond 72 hours"). Name the end-user group when the projects do. | Write each need as one plain-language phrase of no more than 15 words, not a sentence. Start with what is needed. Name the end-user group when the projects do. |
| The projects | …list the "Page" slug of every project those entries come from (the part after /projects/). | …list the "Page" slug of every project those entries come from (the part after /projects/), each project once. |

Why:

- **The example became a need.** In all three runs with the old prompt, Llama 4 Scout,
  Analyze results' default model, gave the example word for word as the first
  Forecasts need, which would have led that area on the site. No project says "beyond
  72 hours": the one that asks for longer Red Flag Warning lead times asks for 48
  hours. Each run credited the example to two to four projects, of which at most two
  mention lead times.
- **Without an example, the same model wrote sentences.** In a first draft that took
  the example out and put nothing in its place, Llama 4 Scout wrote 18 of its 20 needs
  as sentences ("Forecasts include local fuel data"), so the line now says what shape
  to use instead of showing one.
- **Each project once.** An earlier gpt-oss-120b run, with the old prompt, listed the
  same project twice in a need, which the synthesis screen refuses.

What the old and new prompts did, with the same eight published projects, through
Cloudflare Workers AI on 16 November (NotebookLM wasn't run):

| Model | Prompt | Runs | Example given as a need | Needs written as sentences | Check problems |
|---|---|---|---|---|---|
| Llama 4 Scout | before | 3 | 3 runs | none | one run: a wrong slug; one run: an area out of order |
| Llama 4 Scout | after | 3 | none | none | none |
| gpt-oss-120b | before | 2 | none | none | one run: one slug shortened, in 5 needs |
| gpt-oss-120b | after | 2 | none | none | both runs: the same slug shortened, in 6 and 9 needs |
| Gemma 4 26B | before | 2 | none | none | one run clean; one gave the areas by name, not key |
| Gemma 4 26B | after | 3 | none | none | one run clean; one: two counts off and a slug lengthened; one: two areas out of order |
| Inkling 2 Small | before | 2 | none | none | one run: a count off |
| Inkling 2 Small | after | 2 | none | one run: all 20 ("Agencies need …") | none |
| gpt-oss-20b | before | 2 | none | one run: all 20 | none |
| gpt-oss-20b | after | 2 | none | none | none |

- One gpt-oss-120b run with the new prompt gave 11 needs, two or three an area, instead
  of five each; the other gave 20.
- gpt-oss-120b shortens the same long slug under both prompts, and Gemma 4 26B
  lengthened two from their titles. A sentence telling the model to copy slugs exactly
  made no difference in a first draft, so it isn't here: the synthesis screen's check
  catches these names and gives the nearest slug.
- Gemma 4 26B ran with its thinking off, as Analyze results now asks it to (#76):
  thinking, it used up its length limit under both prompts and gave no reply, or half
  of one.
- Thinking Machines' Inkling 2 Small (reasoning set low) and OpenAI's gpt-oss-20b ran
  through the same client from a scratch script, since Analyze results doesn't offer
  them. gpt-oss-20b drew nearly every need from a single project (1.0 to 1.1 projects
  a need, against 1.2 to 3.2 for the others), listing findings rather than putting
  them together. Inkling 2 Small drew on the most (2.1 to 2.8) and named no project
  wrongly.
- Nemotron 3 120B isn't in the table: all 16 requests to it timed out, under both
  prompts, and Cloudflare retires it on 20 November 2026, so Analyze results no longer
  offers it (#76).
