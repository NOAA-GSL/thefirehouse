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
      projectCount: published.filter((project) => project.topic === topic.key).length,
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
    .filter((project) => project.topic === key)
    .sort((a, b) => b.year - a.year || a.title.localeCompare(b.title));
}
