# Local content fixtures

These JSON files back the `local` content adapter and are the site's content until a
CMS is stood up. They are validated against `../types.ts` at load time by
`../adapters/local.ts`, so a shape mistake here fails loudly in dev rather than
rendering a blank section.

## Provenance — read before publishing

| File                 | Status                                                                                                                                                  |
| -------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `settings.json`      | Real, except `submitFormUrl` — a placeholder Google Forms URL that **must** be replaced before launch. Every submit CTA on the site reads from that one field. |
| `landing.json`       | Copy is taken verbatim from the approved landing page design.                                                                                            |
| `topics.json`        | The four topic areas from the FireHouse 1.0 thematic analysis. The `description`, `intro` and `covers` fields are written for this build — they are the copy on `/topics/:topicKey` and need Stephanie/Emily's review. |
| `topicSummaries.json`| "Top needs" text as it appears in the approved design. Confirm against the FireHouse 1.0 report before launch.                                            |
| `projects.json`      | **Placeholder.** Nine records stand in for the nine completed DESI and testbed projects so the explorer and the "projects analyzed" count render at the right scale. Titles and summaries are descriptive stand-ins; takeaways, needs and recommendations say "Placeholder" on purpose. `papers` is empty everywhere — no citation is invented. Replace wholesale from the FireHouse 1.0 report. |

Nothing in `projects.json` should reach a public deploy as-is.

## The "live stats" contract

`landing.json` never stores the project count. Stats declare a `source`
(`publishedProjectCount`, `topicCount`, or `static`) and the number is computed from
live content in `../derive.ts`. An editor controls the label and caption; they cannot
put the count out of step with the projects actually published. Adding a tenth
project to the CMS moves the landing page counter on its own.
