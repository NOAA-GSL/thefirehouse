# Local content fixtures

These JSON files back the `local` content adapter and are the site's content until a
CMS is stood up. They are validated against `../types.ts` at load time by
`../adapters/local.ts`, so a shape mistake here fails loudly in dev rather than
rendering a blank section.

## Provenance — read before publishing

| File                 | Status                                                                                                                                                  |
| -------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `settings.json`      | Real. `submitFormUrl` is the live FireHouse Google Form (Qualtrics is closed); every submit CTA reads from that one field. The footer link and the landing and About CTAs leave `href` empty to inherit it; the header and topic-page buttons read it directly. |
| `landing.json`       | Copy is taken verbatim from the approved landing page design.                                                                                            |
| `topics.json`        | The four topic areas from the FireHouse 1.0 thematic analysis. The `description`, `intro` and `covers` fields are written for this build and need Stephanie/Emily's review. `/topics/:topicKey` shows `covers`; `intro` is kept but no longer rendered. |
| `topicSummaries.json`| **Draft synthesis, pending review.** Up to five "top needs" per topic area, each with a mention count and the projects it draws on, synthesized across *all* published projects — topic areas are never assigned to individual projects. The current draft and its entry-by-entry sourcing are in `notebooklm/draft-synthesis-2026-09-17.md`; the standing workflow and prompt are in `notebooklm/README.md`. The site labels needs as a draft until `reviewedBy` is set. |
| `projects.json`      | **Generated — do not hand-edit.** Written by `scripts/import-survey.mjs` from Stephanie and Emily's "Submission Data" sheet, their checked copy of the Qualtrics responses: eight projects, all published. That sheet has no ResponseId column, so the script matches each row to its record by title; a new row, or one whose title changed, needs a `title` in `scripts/survey-editorial.json` first. New submissions come through the Google Form, which the script reads once it is adapted (see `WORDPRESS-PLAN.md`). Everything the researcher wrote is verbatim; paper titles are looked up from each link's DOI or page metadata. Private answers (email, job title, Q10) are never copied into it. Editorial decisions, such as `published`, live in `scripts/survey-editorial.json`, keyed by ResponseId, so a re-import keeps them. |

Re-import with `node scripts/import-survey.mjs path/to/export.xlsx` (add `--dry` to preview, `--offline` to skip link lookups). Download the sheet as `.xlsx` to a folder outside this repository, and delete it afterwards: it holds submitters' emails, and the repository is public.

## The "live stats" contract

`landing.json` never stores the project count. Stats declare a `source`
(`publishedProjectCount`, `needCount`, `topicCount`, or `static`) and the number is
computed from live content in `../derive.ts`. An editor controls the label and caption; they cannot
put the count out of step with the projects actually published. Publishing another
project moves the landing page counter on its own.
