# CLAUDE.md

Guidance for Claude Code in this repository. `README.md` describes the site as it is
today; `WORDPRESS-PLAN.md` is the plan for moving it into GSL's WordPress as a plugin,
with the decisions behind it. Read the plan's section on anything you're about to
change.

## Ground rules

- **This repository is public.** GSL's internal details stay out of it: exact software
  versions, security-scan findings, hosting internals, the test site's address, and
  internal deliberations.
- **Survey downloads never enter the repository or a chat.** Qualtrics exports and the
  team's "Submission Data" sheet hold submitters' emails. Download them outside the
  repository, let `scripts/import-survey.mjs` read them (it drops the email, job-title
  and Q10 answers), and delete them afterwards. `.gitignore` ignores spreadsheets only
  as a backstop.
- **Researchers' words are verbatim.** Nothing rewrites, merges or tidies what a
  researcher submitted. Changes to that text come from the survey record, and
  Stephanie and Emily confirm them.
- **One set of vocabularies and test cases.** `wp-content/plugins/firehouse/shared/`
  is read by the TypeScript site now and by the plugin's PHP later. Change values
  there rather than in code, and keep the test cases language-neutral.
- **Before reporting a change done,** run the checks it touches: `npm run typecheck`,
  `npm test`, `npm run schema:check` and `npm run build` for the site; `composer lint`
  and `composer test` for the plugin; `npm run reference` when a page changes.

## Review agents

`.claude/agents/` holds a review team of 13 agents from the
[agency-agents catalog](https://github.com/Hackshaven/agency-agents) (`hackshaven`
branch, commit `e42f947`), under the catalog's MIT license, which is copied beside them
in `.claude/agents/LICENSE`. Firehouse has one developer at a time, and the person who
prompts a change is the person who approves it. This team is a second reader, not an
independent one: it runs on the same model family that writes most commits here. It
doesn't replace GSL's security review, the privacy review, or Stephanie and Emily's
sign-off.

**When to run them.** Before reporting a change done or opening a PR, route it by the
paths it touches and run each matching reviewer through the Agent tool, with
`subagent_type` set to the name in the table exactly as written. Not after every edit:
each run rereads the diff and the docs it cites. Every code change gets at least one
reviewer: the specialist for each row it matches, or the `Code Reviewer` when none
does. A change that matches several rows runs each of them; few need more than two.
A change only to the plan docs (`WORDPRESS-PLAN.md`, `PLAN.md`, `WIREFRAME-BRIEF.md`)
needs none. When the maintainer names an agent, run that one.

- **High-scrutiny paths get two.** The survey form's submission handler, roles and
  capabilities, the Import screen, anything that stores or reads private answers, and
  the AI service client get their specialist plus the `Code Reviewer`. The PR says
  whether a person outside the session has reviewed the change.
- **Reviewers report; they don't edit.** Each returns findings with `file:line`. You
  fix, and the maintainer decides what stands.
- **The repo wins.** Where this file or `WORDPRESS-PLAN.md` sets a rule (its "Rules
  that must survive the port" above all), it beats an agent's general advice. Where a
  doc describes the code and the code disagrees, the code wins and the mismatch is a
  finding.
- **Don't hand-edit the agents.** The Agency Agents app recognizes each one by its
  exact content, and its next update restores the catalog's copy. Change them in the
  catalog.

Plugin paths below are under `wp-content/plugins/firehouse/`, laid out as in the plan's
"Plugin layout". Most of them arrive in Phase 1 and later.

| If the change touches… | Run |
|---|---|
| The site's pages and components (`src/pages/**`, `src/components/**`, `src/design-system/**`, `src/styles/**`, `index.html`); the plugin's `blocks/**`, `templates/**`, `patterns/**`, `assets/**` and admin screens | `Section 508 Accessibility Specialist`. Section 508 covers the staff screens too |
| `scripts/import-survey.mjs`, `scripts/survey-editorial.json`, `scripts/notebooklm-source.mjs`; the plugin's `survey/**`, private meta, submission emails, and anything that exports project data | `Privacy Engineer`. Submitters' emails, job titles and Q10 answers never reach a page, the JSON, a revision or the REST API |
| The plugin's `survey/**` submission handler and `includes/**` (post types, meta, settings, roles and permissions, the Import screen), outbound HTTP such as DOI lookups, `vite.config.ts`, `public/**`, `.github/workflows/**` | `Application Security Engineer` |
| The plugin's AI service client and Analyze results, the synthesis screen's paste-in validation, any new `import.meta.env.VITE_*` | `AI-Generated Code Security Auditor`. Published researchers' text goes into the model's prompt, which makes it the prompt-injection path |
| The synthesis prompt (`notebooklm/README.md`, and the plugin's prompt setting) | `Prompt Engineer`. Run the old and new prompts on the same projects before switching |
| The plugin's render path: `templates/**`, each block's `render.php`, asset registration, anything cached | `WordPress Performance Engineer`. Ignore its hosting advice: GSL runs the servers |
| `src/content/**`, `wp-content/plugins/firehouse/shared/**`, the plugin's `domain/**`, `scripts/**` not routed above, and any other code or config | `Code Reviewer` |
| `README.md`, and the editor and administrator runbooks (Phase 8) | `Technical Writer` |

**Some routes follow the content, not the path.**

| When… | Run |
|---|---|
| Public wording changes: `src/content/data/{landing,about,topics,settings}.json`, the survey form's notices, the plugin's default copy | `Science Communicator`, for plain language. Stephanie and Emily own the wording |
| A new or rerun synthesis is about to replace `topicSummaries.json`, or, under the plugin, before Mark reviewed | `Research Synthesist`: each need traced to entries in the projects it names, its count equal to the number of those projects, and its wording no stronger than the entries. Then Stephanie or Emily marks it reviewed |
| Something goes public on gsl.noaa.gov for the first time: the AMS bridge, a reviewed synthesis, the About page, the AI notice on the survey form | `Communications Clearance Officer`. On a NOAA site, each of these speaks for the agency |

**When a person decides.** The agents report findings; people own these decisions.

| Decision | Who decides |
|---|---|
| Any change to what a researcher wrote, including corrections carried in from the team's sheet | Stephanie and Emily |
| Publishing a project or a synthesis | Stephanie or Emily |
| Wording on the site | Stephanie and Emily, after any clearance |
| Releasing a plugin version | GSL's security review |
| What the survey form may keep, and for how long | The privacy review (plan open question 14) |
| Whether the survey needs Paperwork Reduction Act clearance | NOAA's PRA clearance officer (open question 12) |
| Which AI service may be used | NOAA's AI policy approval (open question 7) |

Record who decided, on what exact text, and when. A relayed "they said it's fine" is a
claim, not a sign-off.

**At phase boundaries, not per change.** The `Codebase Archaeologist` checks the plan
docs and the README against the code at the end of each phase; they have drifted
before. The `Codebase Onboarding Engineer` writes a code-grounded guide when a second
developer joins (after Phase 2 in the plan) and before maintenance passes to anyone
else.

**Left out on purpose.**

| Agent | Why |
|---|---|
| CMS Developer | Built around ACF and themes. The plan uses `register_post_meta` and the Interactivity API, and CI already enforces WordPress's rules with PHPCS and Plugin Check |
| USWDS Developer | Firehouse pages sit inside GSL's theme, with Firehouse's own design |
| FedRAMP & RMF Compliance Engineer | GSL hosts everything. Revisit if the AI service needs an authorization |
| Data Privacy Officer, Legal Compliance Checker | The privacy review and the PRA answer belong to NOAA's people |
| Accessibility Auditor | Overlaps the Section 508 specialist |
| Scientific Data Steward | The site holds no datasets; DOIs are only looked up |
| Builders and orchestrators | Claude Code builds, and one developer orchestrates |
