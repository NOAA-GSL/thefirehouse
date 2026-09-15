# Local content fixtures

These JSON files back the `local` content adapter and are the site's content until a
CMS is stood up. They are validated against `../types.ts` at load time by
`../adapters/local.ts`, so a shape mistake here fails loudly in dev rather than
rendering a blank section.

## Provenance — read before publishing

| File                 | Status                                                                                                                                                  |
| -------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `settings.json`      | Real. `submitFormUrl` is the live FireHouse Qualtrics survey; every submit CTA reads from that one field (nav and footer links leave `href` empty to inherit it). |
| `landing.json`       | Copy is taken verbatim from the approved landing page design.                                                                                            |
| `topics.json`        | The four topic areas from the FireHouse 1.0 thematic analysis. The `description`, `intro` and `covers` fields are written for this build — they are the copy on `/topics/:topicKey` and need Stephanie/Emily's review. |
| `topicSummaries.json`| **Placeholder synthesis.** "Top needs" text as it appears in the approved design. The real version is an LLM synthesis (Google NotebookLM) across *all* collected projects, reviewed by the team and pasted in here — topic areas are never assigned to individual projects. Workflow and prompt: `notebooklm/README.md`. `sourceCount` is omitted until a real pass records how many projects it read; the site falls back to the live published count. |
| `projects.json`      | **Generated — do not hand-edit.** Written by `scripts/import-survey.mjs` from the Qualtrics export (currently the four demo responses of 2026-09-14). Everything the researcher wrote is verbatim; paper titles are looked up from each link's DOI or page metadata. Private answers (email, job title, Q10) are never read. Editorial decisions — topic areas, `published`, summary overrides — live in `scripts/survey-editorial.json`, keyed by ResponseId, so a re-import keeps them. The four topic assignments there are proposals awaiting Emily and Stephanie's review. |

Re-import with `node scripts/import-survey.mjs path/to/export.xlsx` (add `--dry` to preview, `--offline` to skip link lookups).

## The "live stats" contract

`landing.json` never stores the project count. Stats declare a `source`
(`publishedProjectCount`, `topicCount`, or `static`) and the number is computed from
live content in `../derive.ts`. An editor controls the label and caption; they cannot
put the count out of step with the projects actually published. Adding a tenth
project to the CMS moves the landing page counter on its own.
