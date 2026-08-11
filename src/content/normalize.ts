import { isTopicKey } from '../design-system/topics';
import type {
  LandingPageContent,
  LinkRef,
  Project,
  SiteContent,
  SiteSettings,
  TopicContent,
  TopicSummary,
} from './types';

/**
 * Shared normalisation, run by every adapter before content reaches React.
 *
 * Two jobs:
 *  1. Fail loudly on structural problems (an unknown topic key, a missing settings
 *     block) instead of rendering a silently empty section. A CMS lets a
 *     non-developer publish at any hour; the failure needs to be legible.
 *  2. Resolve the "inherit the submit URL" convention — any LinkRef with an empty
 *     `href` picks up `settings.submitFormUrl`, so the Google Form address lives in
 *     exactly one field even though five controls point at it.
 */

class ContentError extends Error {
  constructor(message: string) {
    super(`[content] ${message}`);
    this.name = 'ContentError';
  }
}

function resolveLink(link: LinkRef, submitFormUrl: string): LinkRef {
  if (link.href === '') return { ...link, href: submitFormUrl };
  return link;
}

function assertTopicKey(value: string, where: string) {
  if (!isTopicKey(value)) {
    throw new ContentError(
      `${where} references unknown topic "${value}". Valid keys: observe, forecast, warning, governance.`,
    );
  }
  return value;
}

export interface RawSiteContent {
  settings: unknown;
  topics: unknown;
  topicSummaries: unknown;
  projects: unknown;
  landing: unknown;
}

export function normalizeSiteContent(raw: RawSiteContent): SiteContent {
  if (!raw.settings || typeof raw.settings !== 'object') {
    throw new ContentError('Site settings are missing.');
  }

  const settings = raw.settings as SiteSettings;
  const submitFormUrl = settings.submitFormUrl;

  if (!submitFormUrl) {
    throw new ContentError('settings.submitFormUrl is required — every submit CTA reads from it.');
  }

  const topics = (raw.topics as TopicContent[])
    .map((topic) => ({ ...topic, key: assertTopicKey(topic.key, `topics["${topic.key}"]`) }))
    .sort((a, b) => a.order - b.order);

  if (topics.length === 0) {
    throw new ContentError('At least one topic area is required.');
  }

  const topicSummaries = (raw.topicSummaries as TopicSummary[]).map((summary) => ({
    ...summary,
    topic: assertTopicKey(summary.topic, `topicSummaries["${summary.topic}"]`),
  }));

  const projects = (raw.projects as Project[]).map((project) => ({
    ...project,
    topic: assertTopicKey(project.topic, `project "${project.slug}"`),
    takeaways: project.takeaways ?? [],
    needs: project.needs ?? [],
    recommendations: project.recommendations ?? [],
    papers: project.papers ?? [],
    published: project.published ?? false,
  }));

  const duplicateSlug = projects
    .map((p) => p.slug)
    .find((slug, i, all) => all.indexOf(slug) !== i);
  if (duplicateSlug) {
    throw new ContentError(`Duplicate project slug "${duplicateSlug}" — slugs are used as URLs.`);
  }

  const rawLanding = raw.landing as LandingPageContent;
  const landing: LandingPageContent = {
    ...rawLanding,
    hero: {
      ...rawLanding.hero,
      primaryCta: resolveLink(rawLanding.hero.primaryCta, submitFormUrl),
      secondaryCta: resolveLink(rawLanding.hero.secondaryCta, submitFormUrl),
    },
    submitBand: {
      ...rawLanding.submitBand,
      cta: resolveLink(rawLanding.submitBand.cta, submitFormUrl),
    },
  };

  return {
    settings: {
      ...settings,
      nav: settings.nav.map((link) => resolveLink(link, submitFormUrl)),
      footerGroups: settings.footerGroups.map((group) => ({
        ...group,
        links: group.links.map((link) => resolveLink(link, submitFormUrl)),
      })),
    },
    topics,
    topicSummaries,
    projects,
    landing,
  };
}
