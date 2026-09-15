# NotebookLM synthesis

The four topic-area "top needs" on the landing page and topic pages are synthesized
across **all** published projects in Google NotebookLM, reviewed by the team, and
then written into `src/content/data/topicSummaries.json`. No project is assigned to
a topic area. This folder holds what the notebook reads.

| File | What it is |
| --- | --- |
| `firehouse-findings.md` | **Generated — do not hand-edit.** Every published project's abstract, takeaways, needs and recommendations, word for word, plus the four topic-area definitions. |

## After each survey batch

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

5. **Update `src/content/data/topicSummaries.json`.** For each topic, set `topNeeds`,
   `sourceCount` (the number of projects in the document), `updatedAt` (today, as
   `YYYY-MM-DD`), `model: "NotebookLM"` and `reviewedBy`. The landing page shows the
   first two needs per card; the topic page shows them all.

## Prompt

```text
Using only the source "The Firehouse — collected project findings", synthesize the
most important end-user needs across ALL of the projects for each of the four topic
areas defined at the top of the source: Observations and Monitoring; Forecasts and
Modeling; Warnings and Immediate Response; Strategic Adaptation and Institutional
Governance.

For each topic area:
- List 3 to 5 needs, most important first. Weigh a need higher when several projects
  raise it independently.
- Write each need as one plain-language phrase of no more than 15 words, starting
  with a noun (e.g. "Longer-lead outlooks for planning windows beyond 72 hours").
  Name the end-user group when the projects do.
- Cite the projects each need draws on.
- Do not invent needs the projects don't support. If a topic area has little
  support in the source, say so and list fewer needs.

Then give the result as JSON in exactly this shape, with no citations inside it:

[
  { "topic": "observe",    "topNeeds": ["...", "..."] },
  { "topic": "forecast",   "topNeeds": ["...", "..."] },
  { "topic": "warning",    "topNeeds": ["...", "..."] },
  { "topic": "governance", "topNeeds": ["...", "..."] }
]
```
