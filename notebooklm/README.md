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
| `reviewed-synthesis-2026-10-27.json` | The team's reviewed table of 27 October, from Stephanie's email of 3 November, transcribed word for word to the prompt's JSON (16 November). It passes the synthesis screen's checks. The Research Synthesist's check of it against the projects goes to Stephanie and Emily, who decide on it and mark it reviewed. Not yet on the site. |

## Under the WordPress plugin

Everything happens on **FireHouse → Synthesis**, for anyone who may publish a topic.

1. **Download NotebookLM source** there, and make that `firehouse-findings.md` the
   notebook's only source, in place of the old one. It holds the projects the site
   shows, word for word.
2. **Run the prompt below** in the notebook chat.
3. **Review** each need against the projects it came from, with NotebookLM's
   citations, and edit the JSON: the published wording is the team's, not the
   model's. Order each area's needs by project count, highest first, with a tie going
   to the need with broader interagency or multi-regional applicability, as the About
   page says: the screen refuses any other order. (The prompt below asks for the most
   important first; the team's own prompt orders by count.) A project can be named by
   its page slug or by the source's "Project ID".
4. **Paste** the JSON into "The JSON the prompt gave" and press **Check the
   synthesis**. Fix whatever it lists in the box and check again; nothing is written
   until it finds nothing wrong.
5. **Mark it reviewed:** fill in Reviewed by and Made with, and press **Mark reviewed
   and publish** within thirty minutes of the check. The screen records today's date
   and how many projects the site shows, and the topic pages show it at once. Each
   topic keeps the synthesis it had as a revision; restoring that revision is the
   undo.

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
- Write each need as one plain-language phrase of no more than 15 words, starting
  with a noun (e.g. "Longer-lead outlooks for planning windows beyond 72 hours").
  Name the end-user group when the projects do.
- Cite the entries each need draws on, and list the "Page" slug of every project
  those entries come from (the part after /projects/).
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
