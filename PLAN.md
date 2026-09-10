# Implementation plan — post-walkthrough changes

Derived from the 2026 mockup walkthrough with Emily and Steph. Everything here is
achievable in the frontend with no backend developer: the content layer already
abstracts the data source (`src/content/adapters`), so "the survey feeds this" means
"a JSON file shaped like `src/content/types.ts`, hand-exported from Qualtrics until
Eric wires it live."

Read alongside `README.md` ("What's not built yet") — this plan closes four of the
five items listed there.

---

## The constraint that shapes everything

There is no API. Qualtrics holds the survey responses; the site is a static build.
So the seam is a **file contract**, not an endpoint:

```
Qualtrics CSV export
   └─> scripts/import-survey.mjs   (new, Node, runs locally)
         └─> src/content/data/projects.json      ← X-ray page data
         └─> src/content/data/topicSummaries.json ← AI-synthesized needs
               └─> localAdapter → normalize.ts → React
```

When Eric takes over, he replaces the *left* half (a Qualtrics API poll + a Gemini
call writing the same two files, or a Sanity/Strapi instance behind the existing
adapters). Nothing to the right of `normalize.ts` changes. That is the "Dmitri
builds 90%, Eric wires it live" boundary, made concrete.

---

## Phase 0 — Data model ✅ IMPLEMENTED

> Done. What shipped is below; the original plan text follows for reference.
>
> - `src/design-system/taxonomy.ts` — `FirePhase`, `PublicationStatus`, `GaccKey`
>   as structural unions with labels. The fire-phase list is still a documented
>   guess pending open question 1, isolated so it is a one-file edit.
> - `Project.topic` → `Project.topics: TopicKey[]`, plus `abstract`, `authors`,
>   `completionYear`, `firePhases`, `publicationStatus`, `irbApproved`, `geo`,
>   and `papers[].doi`. `TopicSummary` gained `sourceCount` / `model` / `reviewedBy`.
> - IRB skip logic enforced in `normalize.ts`, before content reaches React.
> - `scripts/import-survey.mjs` — Qualtrics CSV → `projects.json`. Drops Q10 at the
>   boundary, reports IRB exclusions rather than hiding them, stages every imported
>   record as `published: false`.
> - Both CMS adapters reprojected; `projects.json` migrated (5 of 9 records now span
>   multiple topics, all 10 GACCs represented).
> - Still open: search (Phase 1), per-project URLs and citation (Phase 2), the GACC
>   map (Phase 3a).

### Original plan

## Phase 0 — Data model (blocks everything else)

`src/content/types.ts` `Project` today carries: `id, slug, title, topic, summary,
author, org, year, takeaways, needs, recommendations, papers, fullRecordUrl,
published`. The survey feeds substantially more, and one field must change shape.

### Breaking change: `topic` → `topics`

Meeting: *"Multi-tag support for topic areas on projects (e.g. Observation +
Warning)."* Single-topic is baked into `derive.ts`, `ProjectsPage`, `TopicPage`,
`ProjectTile`, `ProjectDetailModal`, both CMS adapters, and `projects.json`.

```ts
topics: TopicKey[];   // was: topic: TopicKey — min length 1, order = display order
```

Touch list: `types.ts`, `normalize.ts` (validate every key in the array),
`derive.ts` (`projectsInTopic` → `.some()`), `ProjectTile`/`ProjectDetailModal`
(render N tags, cap visible at 2 + "+1"), `sanity.ts`/`strapi.ts` projections,
`projects.json`.

### New fields, from the survey

| Field | Type | Source | Used by |
| --- | --- | --- | --- |
| `abstract` | `string` | Survey abstract | X-ray page body, **fuzzy search corpus** |
| `firePhase` | `FirePhase[]` | Fire cycle phase | Primary filter, X-ray metadata |
| `completionYear` | `number` | Completion year | Primary filter, X-ray metadata, citation |
| `publicationStatus` | `'published' \| 'in-review' \| 'unpublished'` | Publication status | X-ray metadata badge |
| `irbApproved` | `boolean` | IRB approval | **Skip logic — see below** |
| `geo` | `{ gaccs: GaccKey[] }` | Survey geo data | GACC map, map-driven filter |
| `authors` | `Author[]` | — | Citation block (see open questions) |
| `papers[].doi` | `string?` | DOI links | X-ray related papers, citation |

`year` becomes an alias of `completionYear` or is dropped outright — pick one and
be consistent; I'd drop it and rename in `projects.json` in the same pass.

`FirePhase` and `GaccKey` become string-union types next to `TopicKey` in
`src/design-system/topics.ts` (or a sibling `taxonomy.ts`) — same reasoning that is
already documented there: structural keys are code, display labels are content.

### IRB skip logic

*"Projects without IRB approval excluded."* Enforce in `normalize.ts`, not in a
page filter — it should be structurally impossible for a non-IRB record to render:

```ts
const projects = raw.projects
  .filter((p) => p.irbApproved)     // survey skip logic, enforced at the boundary
  .map(/* … */);
```

### Q10 must never reach the client

*"Q10 (how results were communicated) for internal use only, not displayed."* Do
not add it to `Project` at all. Strip it in `scripts/import-survey.mjs` so it never
enters `projects.json` and therefore never ships in the bundle. A field that only
"isn't rendered" is still a field anyone can read in devtools.

---

## Phase 1 — Project browser (`/projects`)

### 1.1 Remove topic grouping

*"Projects can span multiple topics."* Delete the topic chip row and the `?topic=`
filter from `ProjectsPage.tsx` (lines ~57–95). Topic tags stay **visible** on each
tile as metadata — they just stop being the organizing axis. `/topics/:key` pages
survive as the landing cards' destination; `projectsInTopic` switches to
`topics.includes(key)`.

### 1.2 Fuzzy search

*"Fuzzy search across project title and abstract (e.g. 'smoke and emergency
managers')."* That example is telling: it's a multi-token phrase where the tokens
appear scattered across the abstract, not a typo-tolerant prefix match. So the
right algorithm is **token-AND with per-token fuzziness and field weighting**, not
classic subsequence fuzzy.

New `src/content/search.ts` (~90 lines, no dependency):

- normalize (lowercase, strip punctuation/diacritics), tokenize the query
- every token must match *somewhere* in the record (AND) — exact > prefix >
  Levenshtein ≤1 for tokens ≥4 chars
- weight: title 3×, topic labels 2×, abstract 1×, needs/takeaways 1×
- rank by summed score, tie-break by `completionYear` desc
- highlight matched runs in results (`<mark>`), which is what makes fuzzy legible

Alternative if you'd rather not own it: Fuse.js (~6 kB gz, `threshold: 0.35`,
weighted keys). Given this repo ships with four runtime deps and is heading for a
government handoff, I recommend the local module — it's pure, testable, and Eric
never has to audit a transitive tree. Either way it lives behind one function so
swapping is a one-file change.

Debounce input at ~150 ms; announce result counts via the existing `role="status"`
count line so screen readers hear the list change.

### 1.3 Filters

Primary, per the meeting: **fire cycle phase** and **completion year**. Both
multi-select. Secondary (cheap, add if they don't clutter): publication status,
topic tag.

All state — `q`, `phase`, `year`, `gacc` — lives in the query string, extending the
pattern `ProjectsPage` already uses with `useSearchParams`. That keeps a filtered
view linkable, which matters because the GACC map links *into* it.

Add an active-filter chip row with individual dismiss + "Clear all", and an empty
state that says which filter is responsible.

---

## Phase 2 — X-ray screen ✅ IMPLEMENTED

> Done.
>
> - `/projects/:slug` route and `src/pages/ProjectPage.tsx` — the X-ray template.
>   Header (topic tags, title, byline, fire phase / status / year), abstract,
>   takeaways, needs, recommendations, papers with DOI links, an "At a glance"
>   sidebar, prev/next paging, and a per-project `document.title` + description.
>   One template; each project renders its own record.
> - Tiles are now **links**, not buttons — so middle-click, open-in-new-tab and
>   copy-address all work, and each project has an address to cite.
> - The modal became a **quick look**: abstract, needs, and a count of what else is
>   on the page, with "Open project page" as its primary action. A preview that
>   reprints the whole page gives nobody a reason to open the page.
> - `src/content/citation.ts` + `CitationBlock` — APA 7 / Chicago / BibTeX with
>   copy-to-clipboard. A DOI supersedes the site URL when one exists.
> - Not done: build-time per-project meta tags (§2.2). Titles and descriptions are
>   set at runtime, so deep links work and are indexed, but a link *preview* still
>   shows the site-level card.

### Original plan

## Phase 2 — X-ray screen (per-project URLs)

*"Each project opens on its own unique URL page for citability."*

### 2.1 Route + page

New `src/pages/ProjectPage.tsx` at `/projects/:slug`. `ProjectTile` becomes a
`<Link>` instead of a `<button>` (it is navigation now, not a disclosure —
also fixes right-click/open-in-new-tab, which a `<button>` silently breaks).

**Retire `ProjectDetailModal` as a page surface.** Lift its sections into the new
page; keep the component file only if you want it for a future quick-peek. Same
change applies to `TopicPage`, which uses the modal too.

Layout, per the image mockup:

```
[topic tags]  [fire cycle phase]  [publication status]  [completion year]
Title
Authors · org

Abstract                        ← verbatim
Major takeaways                 ← verbatim
Needs and recommendations (Q17) ← verbatim
Related papers + DOI links
[Cite this project]  [View full record]
```

*"Researcher's original voice preserved verbatim on project pages. AI-synthesized
version used only on the landing page."* Worth a literal code comment on this
page — it's a product rule that a future contributor would otherwise "helpfully"
violate by summarizing.

### 2.2 Citable link previews (build-time, still no backend)

A citable URL that previews as the generic site card undercuts the whole point.
Extend the existing `siteMeta()` plugin in `vite.config.ts` to also emit
`dist/projects/<slug>/index.html` per published project — a copy of `index.html`
with `<title>`, `description`, `og:title`, `og:description`, `og:url` substituted.
No SSR, no framework change; ~40 lines beside the `spaFallback()` plugin that
already does a similar copy. The SPA boots normally and routes to the right page.

Runtime `document.title` per project as well, matching the `useEffect` pattern on
the other pages.

### 2.3 Quick citation

*"Format TBD; align to a commonly used citation standard."* Recommendation: **APA
7 as the default**, with Chicago and BibTeX behind a small format switcher, and a
copy-to-clipboard button. APA is the dominant standard in atmospheric and social
science journals and is what a NOAA report will most often need.

```
Author, A. A., & Author, B. B. (2024). Title of project. NOAA Global Systems
    Laboratory, The Firehouse. https://…/projects/<slug>
```

Blocked on structured authors — see open questions.

---

## Phase 3 — Landing page

### 3.1 GACC map ✅ IMPLEMENTED (demo)

> Done, and built to `FirehouseFormAdditions.pdf` rather than to my guess.
>
> **The PDF changed the taxonomy.** Region keys are now the survey's own recode
> values (`ONCC`, not my invented `onc`), because the spec's first setting is that
> the export carries codes rather than labels — display text gets reworded, codes
> must not. And there are **fourteen** values, not ten: `PACIFIC` (the NIFC boundary
> file folds the Pacific islands into Northern California, so Hawaii research would
> otherwise be filed under ONCC), plus `NATIONAL`, `INTL` and `UNKNOWN`. Those four
> have no polygon and are listed beside the map — a count that drops them
> under-reports what was actually submitted.
>
> - `src/design-system/RegionMap.tsx` — Leaflet over an Esri basemap, with the
>   authoritative NIFC `National_GACC_Boundaries` committed to the repo
>   (`gaccBoundaries.json`, 33 kB) rather than fetched at runtime.
> - Choropleth heat ramp normalised to the busiest region, click or keyboard to
>   select, fly-to on selection, and the selected region's projects listed beneath
>   with links to their pages. Selection lives in `?region=`, so a view is linkable.
> - Sits in the hero as the second column, per the walkthrough.
>
> I had recommended inline SVG over a tile-based map; the ask was explicitly for a
> basemap, so that is what this is. The trade is real and worth naming: **the
> basemap tiles are the site's only runtime third-party dependency.** They come from
> `server.arcgisonline.com`. If a federal review objects, the map degrades to
> boundaries-on-a-blank-canvas rather than breaking, and swapping to a self-hosted
> or vector basemap is a one-constant change in `RegionMap.tsx`.

### Original plan

### 3.1 GACC map

*"GAC map on landing page alongside the 4 topic-area need summaries. Heat map or
badge-count visualization. Clickable map regions filter to relevant projects."*

Recommendation: **inline SVG of the 10 GACC regions**, not a mapping library.

- No tile server (an external network dependency is a real problem for a
  `.gov` review), no ~150 kB of Leaflet/MapLibre, works offline at AMS
- Themes with the existing CSS custom properties in `src/styles/tokens/colors.css`
  the same way `TopicTag` does — a raster or tile-based map will look wrong in dark
  mode and there's nothing you can do about it
- Every region is a focusable `<a>` → `/projects?gacc=<key>`, so the map is
  keyboard-navigable and the "clickable regions filter to projects" requirement is
  satisfied by plain links, not click handlers
- Badge counts (a count bubble per region) read better than a heat map at 10
  regions with single-digit counts — a choropleth of 0–3 projects is mostly noise.
  Ship badges; the fill ramp is a later swap if the dataset grows.

Boundaries: NIFC publishes GACC boundaries as public-domain GeoJSON. Simplify to
~2 kB per region and commit the SVG as `src/design-system/GaccMap.tsx`. Provide a
table fallback beneath it (region / project count) — that's both the accessible
alternative and genuinely more scannable for some readers.

Requires a paired legend and an "All regions" reset.

### 3.2 AI-synthesized needs

*"AI categorizes open-text survey responses in real time. Buckets needs/recs from
Q17 into top themes across all submissions."*

Real-time is a backend feature. The frontend-buildable 90%:

1. **Keep `topicSummaries.json` as the contract** — it already is exactly this
   shape, and the landing page already renders it with no code change needed.
2. **Add provenance to `TopicSummary`**: `generatedAt`, `sourceCount`, `model`,
   `reviewedBy?`. Then surface it — "Synthesized from 14 submissions · reviewed
   23 Jul 2026". A government site presenting AI-derived synthesis without saying
   so is a problem waiting to happen, and the label costs one line.
3. **Write `scripts/synthesize-needs.mjs`** — reads Q17 free text from the export,
   calls the model with a fixed prompt, writes `topicSummaries.json`. You run it
   manually after each survey batch. Eric later moves the same script behind a
   trigger; the prompt and output schema are already settled and reviewed.
4. Keep the human review step. The brief's own risk list calls for it, and the
   landing page is the one surface where the site speaks in its own voice rather
   than the researcher's.

Note for the Eric conversation: whether GSL's Google Suite/Gemini integration can
be called from a service account is the question that decides step 3's future — ask
it early, since the answer changes nothing about what you build now.

### 3.3 Landing layout

The map and the four topic cards share a row/band. At current card sizing that's
tight — expect the topic grid to go 2×2 beside the map on desktop and stack on
mobile. `LandingPage.css` grid change, no structural rework.

---

## Phase 4 — Branding

- Swap `src/assets/logos/{noaa,gsl}-logo.svg` for Steph's logo; the header lockup
  in `SiteHeader.tsx` (lines ~103–115) currently renders two marks + divider +
  wordmark and will likely collapse to one mark + wordmark.
- Also: `public/favicon.svg`, `favicon.ico`, `apple-touch-icon.png`, `og-image.png`.
- Keep the NOAA/DOC agency line in the footer regardless — that's a federal
  requirement, not branding.
- **Blocked on Steph.**

---

## Sequencing

Phase 0 first and alone — every other phase edits the same files, and doing the
model change last means doing it three times. Then 1 and 2 (they share the search
and routing work), then 3, with 4 dropped in whenever the asset lands.

| # | Work | Depends on |
| --- | --- | --- |
| 0 | ✅ Data model + multi-tag + IRB filter + import script | Survey field map |
| 1 | Browser: drop topic grouping, fuzzy search, phase/year filters | 0 |
| 2 | ✅ X-ray pages + citation (build-time meta still open) | 0 |
| 3a | ✅ GACC map (demo) | 0 + geo granularity answer |
| 3b | Needs provenance + synthesis script | 0 |
| 4 | Logo swap | Steph's asset |

Worth adding tests around `normalize.ts`, `derive.ts` and the new `search.ts` in
Phase 1 — they're pure functions, and after Phase 0 they are what stands between a
malformed survey export and a broken page. There are none today.

---

## Open questions

**For Emily and Steph** (the mapped survey-question list covers most of this):

1. **Fire cycle phase — what are the exact values?** They become a fixed union type
   and a filter UI. Preparedness / prevention / detection / response / recovery is
   the usual set, but use theirs verbatim.
2. ~~**Geo data granularity?**~~ **Answered** by `FirehouseFormAdditions.pdf`:
   Q1 is a required multi-select of the fourteen region values, tagged
   `GACC_REGION`; Q2 is an optional multi-select of states tagged `STATES`. Both are
   implemented. The one thing still to confirm is that Qualtrics is actually
   configured to export *recode values* — the import script warns loudly and files
   an unrecognised answer under UNKNOWN rather than dropping it, but a label-only
   export means every submission lands in "Not on the map".
3. **Authors — one free-text string or structured names?** Today `author` is a
   single string ("DESI research team"). A correct APA citation needs
   surname/initials per author. If the survey only captures free text, the
   citation renders the string verbatim and won't be strictly APA — acceptable,
   but it should be a decision rather than a surprise.
4. **Publication status values** — exact list, and which ones display a badge.
5. **Which field is the search "abstract"** — the survey abstract, or abstract +
   takeaways?

**For Eric:**

6. Can GSL's Gemini/Google Suite integration be called from a service account or
   scheduled job, or is it interactive-only? Decides whether §3.2 step 3 can ever
   run unattended.
7. Where does this deploy, and does it get a stable path? `VITE_SITE_URL` and
   `VITE_BASE_PATH` are already wired; per-project citation URLs need the final
   origin to be correct in printed citations.

**For the team:**

8. Citation format — confirming APA 7 as default.


---

## Appendix — surface treatment (done alongside Phase 0)

Not in the walkthrough notes; added on request for a more distinct look.

- `src/styles/tokens/glass.css` — a bounded atmospheric backdrop (two fixed fire
  glows over slate/navy) and a `.fh-glass` panel primitive. `colors.css` is left
  byte-faithful to the upstream design system so it can still be re-synced.
- The governing rule is **glass provides depth, never contrast**. Panel alphas are
  chosen so text clears AA against the *worst-case composite* — the most saturated
  point the backdrop can reach — rather than against the panel colour. Blur is
  decoration layered on an already-passing surface.
- Three fallbacks, all verified to fire: `prefers-reduced-transparency`,
  `prefers-contrast: more`, and `@supports not (backdrop-filter)`. Each drops to an
  opaque surface, which is safe because that opaque colour is the audit's floor.

### Verification

Two audits, deliberately complementary:

| Tool | Checks | Run |
| --- | --- | --- |
| `scripts/check-contrast.mjs` | the *token system*, against a modelled worst case; also covers non-text/UI contrast (1.4.11) | `node scripts/check-contrast.mjs` |
| `scripts/audit-contrast-live.js` | the *delivered page*, by walking real computed styles | paste into the console, `fhAudit()` |

The live walk earns its place: it found a pre-existing cascade bug where a
light-mode override leaked into dark mode and painted every caption on the site at
2.65:1. A token-level audit could not have seen it. Fixed in `a11y-overrides.css`.

Current state: **0 AA text-contrast failures** across `/`, `/projects`,
`/topics/*` and the 404, in both themes, and in the reduced-transparency and
high-contrast fallback states.

One caveat worth knowing before trusting a failure from the live tool: it resolves
backdrops by walking *ancestors*, so where a backdrop is painted by a positioned
sibling — the hero is the only such case here — it reports false failures. Pin the
region to its worst-case colour first; the recipe is in the script's header.
