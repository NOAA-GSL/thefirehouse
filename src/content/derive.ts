import { GACC_KEYS, REGION_KEYS, type RegionKey } from '../design-system/taxonomy';
import type { TopicKey } from '../design-system/topics';
import type { Project, SiteContent, StatContent, TopicContent, TopicSummary, TopNeed } from './types';

/**
 * Values computed from live content rather than stored.
 *
 * This is where "live, auto-updating stats" (creative brief §5.1) actually happens.
 * Nobody types "9" anywhere; the landing page counts what's published. When the
 * tenth project lands in the CMS, the number moves with no code or copy edit.
 */

export function publishedProjects(content: SiteContent): Project[] {
  return content.projects.filter((project) => project.published);
}

export function resolveStatValue(stat: StatContent, content: SiteContent): string {
  switch (stat.source) {
    case 'publishedProjectCount':
      return String(publishedProjects(content).length);
    case 'topicCount':
      return String(content.topics.length);
    case 'static':
      return stat.value ?? '';
  }
}

/** A topic paired with its current top-needs list, in display order. */
export interface TopicCard {
  topic: TopicContent;
  needs: TopNeed[];
}

export function buildTopicCards(content: SiteContent, needsPerCard: number): TopicCard[] {
  const summaryByTopic = new Map<TopicKey, TopicSummary>(
    content.topicSummaries.map((summary) => [summary.topic, summary]),
  );
  return content.topics.map((topic) => {
    const summary = summaryByTopic.get(topic.key);
    return {
      topic,
      needs: (summary?.topNeeds ?? []).slice(0, needsPerCard),
    };
  });
}

export function findProjectBySlug(content: SiteContent, slug: string): Project | undefined {
  return content.projects.find((project) => project.slug === slug);
}

/** The editor-facing record for one topic area, or undefined if the CMS has no entry. */
export function findTopic(content: SiteContent, key: TopicKey): TopicContent | undefined {
  return content.topics.find((topic) => topic.key === key);
}

export function findTopicSummary(content: SiteContent, key: TopicKey): TopicSummary | undefined {
  return content.topicSummaries.find((summary) => summary.topic === key);
}

/**
 * The site-wide default project order: newest first, alphabetical within a year.
 *
 * Shared so the explorer, the topic pages and the map-filtered views can't disagree
 * about it — the same project should not move between two lists that both claim to
 * be unsorted.
 */
export function byRecency(a: Project, b: Project): number {
  return b.completionYear - a.completionYear || a.title.localeCompare(b.title);
}

/** How many times a need was raised; falls back to how many projects raised it. */
export function needMentions(need: TopNeed): number {
  return need.mentions ?? need.projects?.length ?? 0;
}

/**
 * The provenance shared by every topic's needs, for the one line the landing page
 * shows above the cards. Passes are run over the whole collection at once, so the
 * four summaries normally agree; the most recent date and the largest source count
 * win if they don't. `reviewed` is true only when every summary has a reviewer.
 */
export function synthesisInfo(content: SiteContent) {
  const summaries = content.topicSummaries;
  const dates = summaries.map((s) => s.updatedAt).filter((d): d is string => Boolean(d));
  return {
    model: summaries.find((s) => s.model)?.model,
    reviewedBy: summaries.every((s) => s.reviewedBy) ? summaries[0]?.reviewedBy : undefined,
    updatedAt: dates.sort().at(-1),
    sourceCount:
      Math.max(0, ...summaries.map((s) => s.sourceCount ?? 0)) || publishedProjects(content).length,
  };
}

/**
 * The regions a project counts toward.
 *
 * A NATIONAL answer means a nationally representative study, so it speaks to every
 * GACC: the map counts it once in each and lists it when any GACC is selected, and
 * the explorer's GACC filter matches it. NATIONAL itself is kept too, so the
 * "National" row still counts these studies on their own. PACIFIC, INTL and
 * UNKNOWN are not expanded — none of them is a claim about the whole country.
 */
export function effectiveRegions(regions: readonly RegionKey[] = []): RegionKey[] {
  return regions.includes('NATIONAL') ? [...new Set([...regions, ...GACC_KEYS])] : [...regions];
}

/** Published projects that count toward one coordination region, newest first. */
export function projectsInRegion(content: SiteContent, key: RegionKey): Project[] {
  return publishedProjects(content)
    .filter((project) => effectiveRegions(project.geo?.regions).includes(key))
    .sort(byRecency);
}

/**
 * Published project counts per coordination region, for the landing-page map.
 *
 * Returns every key — including the zeroes, and including the four non-GACC values
 * that have no boundary to draw. Both omissions would misinform: a map that hides
 * empty regions reads as "no data exists here" when it means "nothing is filed here
 * yet", and a count that quietly drops NATIONAL/INTL/UNKNOWN submissions reports a
 * total smaller than the number of submissions actually received.
 *
 * National studies are counted in every GACC as well — see `effectiveRegions`.
 */
export function regionCounts(content: SiteContent): Record<RegionKey, number> {
  const counts = Object.fromEntries(REGION_KEYS.map((key) => [key, 0])) as Record<
    RegionKey,
    number
  >;
  for (const project of publishedProjects(content)) {
    for (const key of effectiveRegions(project.geo?.regions)) counts[key] += 1;
  }
  return counts;
}
