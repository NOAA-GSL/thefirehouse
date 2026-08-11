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

export type { TopicKey };

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
 * The rolling "top needs" summary for one topic area.
 *
 * Downstream of the submission pipeline (brief §5.3): free-text submissions are
 * bucketed into the four topics, reviewed by a human, and the resulting needs list
 * is written back here. The landing page renders whatever is current — that's what
 * makes the topic cards "update automatically as new submissions are processed".
 */
export interface TopicSummary {
  topic: TopicKey;
  topNeeds: string[];
  /** ISO date of the last review pass; surfaced so readers know how fresh this is. */
  updatedAt?: string;
}

export interface Paper {
  title: string;
  url?: string;
}

/** One completed research project in the explorer (brief §5.2). */
export interface Project {
  id: string;
  slug: string;
  title: string;
  topic: TopicKey;
  /** One- or two-line summary shown on the grid tile. */
  summary: string;
  author: string;
  org?: string;
  year: number;
  takeaways: string[];
  needs: string[];
  recommendations: string[];
  papers: Paper[];
  fullRecordUrl?: string;
  /**
   * Counted in the "projects analyzed" stat only when true. Lets editors stage a
   * project in the CMS before it should affect the public count.
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
  /** The Google Form the submission CTA points at (brief §5.3). */
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
