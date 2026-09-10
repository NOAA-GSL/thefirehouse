import { REGION_KEYS, type RegionKey } from '../design-system/taxonomy';
import type { TopicKey } from '../design-system/topics';
import type { Project, SiteContent, StatContent, TopicContent, TopicSummary } from './types';

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
  needs: string[];
  updatedAt?: string;
  /** Submissions folded into this summary — see `TopicSummary.sourceCount`. */
  sourceCount?: number;
  /** Set when the summary was model-synthesized; drives the provenance label. */
  model?: string;
  projectCount: number;
}

export function buildTopicCards(content: SiteContent, needsPerCard: number): TopicCard[] {
  const summaryByTopic = new Map<TopicKey, TopicSummary>(
    content.topicSummaries.map((summary) => [summary.topic, summary]),
  );
  const published = publishedProjects(content);

  return content.topics.map((topic) => {
    const summary = summaryByTopic.get(topic.key);
    return {
      topic,
      needs: (summary?.topNeeds ?? []).slice(0, needsPerCard),
      updatedAt: summary?.updatedAt,
      sourceCount: summary?.sourceCount,
      model: summary?.model,
      // A project tagged with several areas counts once in each — the card answers
      // "how much evidence sits behind this area", not "how do projects partition".
      projectCount: published.filter((project) => project.topics.includes(topic.key)).length,
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
 * Published projects in one topic area, newest first.
 *
 * Ordering is decided here rather than left to whatever the CMS returns, so the
 * topic page and the explorer can't disagree about it.
 */
export function projectsInTopic(content: SiteContent, key: TopicKey): Project[] {
  return publishedProjects(content)
    .filter((project) => project.topics.includes(key))
    .sort(byRecency);
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

/** Published projects tagged for one coordination region, newest first. */
export function projectsInRegion(content: SiteContent, key: RegionKey): Project[] {
  return publishedProjects(content)
    .filter((project) => project.geo?.regions.includes(key))
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
 */
export function regionCounts(content: SiteContent): Record<RegionKey, number> {
  const counts = Object.fromEntries(REGION_KEYS.map((key) => [key, 0])) as Record<
    RegionKey,
    number
  >;
  for (const project of publishedProjects(content)) {
    for (const key of project.geo?.regions ?? []) counts[key] += 1;
  }
  return counts;
}
