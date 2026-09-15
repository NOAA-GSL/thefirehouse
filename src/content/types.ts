import type {
  FirePhase,
  PublicationStatus,
  RegionKey,
} from '../design-system/taxonomy';
import type { TopicKey } from '../design-system/topics';

/**
 * The Firehouse content model.
 *
 * This is the contract between the site and whatever CMS ends up behind it
 * (Sanity or Strapi — creative brief §5.4). Every adapter in `./adapters` returns
 * these exact shapes, so pages and components never learn which CMS is in play.
 *
 * Two rules keep it that way:
 *  1. No CMS-native types leak in here — no Portable Text blocks, no Strapi
 *     `data/attributes` envelopes, no `_ref` strings. Adapters normalise.
 *  2. Anything an editor should be able to change is a field. Anything structural
 *     (the four topic keys, the route table) is code. See `design-system/topics.ts`.
 */

export type { FirePhase, PublicationStatus, RegionKey, TopicKey };

/** A destination: either an in-app route (`to`) or an absolute URL (`href`). */
export interface LinkRef {
  label: string;
  to?: string;
  href?: string;
}

/** Editor-facing overrides for the four fixed topic areas. */
export interface TopicContent {
  key: TopicKey;
  /** Full display name, e.g. "Observations and Monitoring". */
  label: string;
  /** Pill label, e.g. "Observations". */
  short: string;
  /**
   * One-sentence description. Doubles as the lead paragraph on the topic page and
   * the summary a search engine or a link preview will pick up.
   */
  description?: string;
  /**
   * The longer introduction shown only on the topic page, one string per paragraph.
   * Kept as an array rather than one blob so an editor can't smuggle markup in and
   * the page never has to render raw HTML.
   */
  intro?: string[];
  /** "What this area covers" — the kinds of question that belong under this topic. */
  covers?: string[];
  order: number;
}

/**
 * The rolling "top needs" synthesis for one topic area.
 *
 * Topic areas are a lens over the WHOLE body of findings, not a tag on individual
 * projects. After each survey batch the team runs every collected project through an
 * LLM synthesis (a Google NotebookLM notebook, per the September 2026 meeting), a
 * human reviews the result, and it is written back here. There is deliberately no
 * link from a need to the projects behind it: the synthesis draws on all of them at
 * once, and a per-project attribution would claim a precision it doesn't have.
 *
 * The landing page renders whatever is current — that's what makes the topic cards
 * "update automatically as new submissions are processed".
 */
export interface TopicSummary {
  topic: TopicKey;
  topNeeds: string[];
  /** ISO date of the last review pass; surfaced so readers know how fresh this is. */
  updatedAt?: string;
  /**
   * How many projects were in the synthesis pass. Rendered next to the card, because
   * "top needs" carries very different weight at 4 projects and at 40.
   */
  sourceCount?: number;
  /**
   * Tool or model that produced the synthesis, e.g. "NotebookLM". Presence of this
   * field is what flips the card's "AI-synthesized" label on.
   *
   * This is not decoration. The landing page is the one surface where the site
   * paraphrases researchers instead of quoting them (project pages stay verbatim),
   * and a federal site presenting model-derived synthesis needs to say that it is.
   */
  model?: string;
  /** Who signed off on the synthesis pass. The human review step is not optional. */
  reviewedBy?: string;
}

/**
 * A publication, report or presentation linked from a project (survey Q17).
 *
 * The survey collects links only. `title`, `container` and `year` are looked up
 * from the link's own metadata by the import script and may be missing — the page
 * then labels the link by its host rather than inventing a title.
 */
export interface Paper {
  title?: string;
  /** Journal, report series, or site the item was published in. */
  container?: string;
  year?: number;
  url?: string;
  /**
   * Bare DOI (`10.1175/WCAS-D-21-0042.1`), not a URL — the `https://doi.org/`
   * prefix is added at render time. Storing it bare keeps one canonical form, so
   * the same value can be a link, a citation field, and a dedupe key.
   */
  doi?: string;
}

/**
 * One entry from survey Q16 — "Need for [topic] among [group]: [gap]. Recommend
 * [solution]."
 *
 * The survey pairs each need with its recommendation, and so does the page, so they
 * are stored together rather than as two parallel lists that a reorder could
 * silently mis-pair. Either half may be absent: the survey explicitly allows a
 * stand-alone need or a stand-alone recommendation.
 *
 * Both halves are verbatim substrings of what the researcher typed. The import
 * script only decides where one ends and the other begins (at the sentence starting
 * "Recommend").
 */
export interface NeedEntry {
  need?: string;
  recommendation?: string;
}

/**
 * One credited researcher.
 *
 * `name` is the only guaranteed field: the survey may capture a free-text byline
 * ("DESI research team") rather than structured names. When `family` is present the
 * citation builder can render a proper APA author string; when it isn't, it falls
 * back to `name` verbatim rather than guessing where a surname ends.
 * See PLAN.md, open question 3.
 */
export interface Author {
  name: string;
  family?: string;
  given?: string;
  org?: string;
}

/**
 * Where a project's fieldwork or subject matter sits — survey Q13, a multi-select
 * that exports GACC recode values (see FirehouseFormAdditions.pdf).
 *
 * `regions` rather than `gaccs`, because it holds more than the ten GACCs: the four
 * non-GACC answers (PACIFIC, NATIONAL, INTL, UNKNOWN) are equally valid responses
 * and equally have to be counted. Naming the field after only the mappable subset
 * is how the other four quietly get dropped from a total.
 */
export interface ProjectGeo {
  regions: RegionKey[];
  /** Optional free-text refinement shown on the X-ray page, e.g. "Front Range". */
  note?: string;
  /** Two-letter postal codes. Optional backstop; not asked in the current survey. */
  states?: string[];
}

/**
 * One completed research project — the record behind an "X-ray" page (brief §5.2).
 *
 * Field origins are noted because most of this arrives from one Qualtrics survey and
 * the mapping is the thing most likely to be misremembered later. Anything marked
 * *survey* is written by `scripts/import-survey.mjs` and should not be hand-edited
 * in `projects.json` — the next import would overwrite it.
 *
 * Fields that look like they belong here and deliberately don't:
 *  - **Q10 (how results were shared)** is internal-use-only, and **Q2/Q3 (submitter
 *    email and job title)** are private. The import script never reads them and they
 *    have no field here, because a field that merely isn't rendered still ships in
 *    the JSON bundle for anyone to read.
 *  - **IRB approval** is a survey field, but records without it are filtered out in
 *    `normalize.ts` rather than carried and hidden — see `irbApproved` below.
 */
export interface Project {
  id: string;
  slug: string;
  title: string;
  /**
   * One- or two-line summary shown on the grid tile. *Editorial* — seeded by the
   * import script with the abstract's first sentence until an editor writes one.
   */
  summary: string;
  /** *Survey (Q14).* The researcher's own abstract, shown verbatim and searched fuzzily. */
  abstract?: string;
  authors: Author[];
  org?: string;
  /** *Survey (Q7).* Year the project was completed — a primary filter and a citation field. */
  completionYear: number;
  /** *Survey (Q9).* Where in the fire cycle the work sits. A primary filter. */
  firePhases: FirePhase[];
  /**
   * *Editorial.* Publication state of the underlying research. The survey does not
   * ask, so it is absent unless an editor sets it — and nothing is shown rather than
   * a guessed "Unpublished" badge on work that is in fact in a journal.
   */
  publicationStatus?: PublicationStatus;
  /** *Survey (Q11).* "Testbed Evaluation", "Research Study", or the respondent's own text. */
  projectType?: string;
  /** *Survey (Q12).* Data collection instruments, e.g. "Focus groups". */
  methods: string[];
  /**
   * *Survey (Q8).* IRB approval. Records where this is false never reach the UI —
   * `normalize.ts` drops them at the boundary, implementing the survey's own skip
   * logic in the one place it cannot be forgotten by a future page.
   */
  irbApproved: boolean;
  /** *Survey (Q13).* Geography, for the landing-page GACC map. */
  geo?: ProjectGeo;
  /** *Survey (Q15).* Major takeaways, in the researcher's own words. */
  takeaways: string[];
  /**
   * *Survey (Q16).* End-user needs paired with recommendations, verbatim. Also the
   * input to the landing-page synthesis.
   */
  needs: NeedEntry[];
  /** *Survey (Q17).* Publications, tech notes, presentations or reports. */
  papers: Paper[];
  fullRecordUrl?: string;
  /**
   * Counted in the "projects analyzed" stat only when true. Lets editors stage a
   * project in the CMS before it should affect the public count.
   *
   * Note this is *editorial* visibility on this site — distinct from
   * `publicationStatus`, which describes the underlying research in the literature.
   * A project can be published here while its paper is still in review.
   */
  published: boolean;
}

export interface HeroContent {
  eyebrow: string;
  heading: string;
  body: string;
  primaryCta: LinkRef;
  secondaryCta: LinkRef;
  /** Optional CMS-hosted override for the packaged hero image. */
  imageUrl?: string;
  /**
   * Empty string marks the image as decorative — correct here, because the hero
   * carries no information the heading and body don't already state.
   */
  imageAlt: string;
}

/**
 * A landing-page stat.
 *
 * `source` decides where the number comes from. `derived` values are computed from
 * live content at render time (brief §5.1: "live, auto-updating stats") — an editor
 * controls the wording but cannot desync the count from reality. `static` is for
 * non-numeric slots like the "Open / For submissions" tile.
 */
export type StatSource = 'publishedProjectCount' | 'topicCount' | 'static';

export interface StatContent {
  id: string;
  source: StatSource;
  /** Used only when `source` is 'static'. */
  value?: string;
  label: string;
  caption?: string;
  accent?: boolean;
}

export interface SectionIntro {
  eyebrow?: string;
  heading: string;
  body?: string;
}

export interface CtaBandContent {
  heading: string;
  body: string;
  cta: LinkRef;
}

export interface LandingPageContent {
  hero: HeroContent;
  stats: StatContent[];
  topicSection: SectionIntro;
  /** How many needs to show per topic card before the card links out. */
  needsPerCard: number;
  submitBand: CtaBandContent;
}

export interface FooterGroupContent {
  heading: string;
  links: LinkRef[];
}

export interface SiteSettings {
  siteName: string;
  /** Primary navigation. */
  nav: LinkRef[];
  /** Label used on every "Submit a Finding" control. */
  submitLabel: string;
  /** The Qualtrics submission survey every "Submit a Finding" control points at. */
  submitFormUrl: string;
  footerBlurb: string;
  footerGroups: FooterGroupContent[];
  agencyLine: string;
  usaGovUrl: string;
}

/** Everything a page render needs, fetched in one pass. */
export interface SiteContent {
  settings: SiteSettings;
  topics: TopicContent[];
  topicSummaries: TopicSummary[];
  projects: Project[];
  landing: LandingPageContent;
}

/**
 * What every CMS adapter implements.
 *
 * Deliberately one coarse method rather than five fine-grained ones: the whole site
 * is a few kilobytes of JSON, so a single request per load beats a waterfall, and it
 * maps cleanly onto one GROQ query or one Strapi populate call.
 */
export interface ContentAdapter {
  readonly name: string;
  load(): Promise<SiteContent>;
}
