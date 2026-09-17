import {
  isFirePhase,
  isPublicationStatus,
  isRegionKey,
} from '../design-system/taxonomy';
import { isTopicKey } from '../design-system/topics';
import type {
  Author,
  LandingPageContent,
  LinkRef,
  NeedEntry,
  Project,
  SiteContent,
  SiteSettings,
  TopicContent,
  TopicSummary,
  TopNeed,
} from './types';

/**
 * Shared normalisation, run by every adapter before content reaches React.
 *
 * Two jobs:
 *  1. Fail loudly on structural problems (an unknown topic key, a missing settings
 *     block) instead of rendering a silently empty section. A CMS lets a
 *     non-developer publish at any hour; the failure needs to be legible.
 *  2. Resolve the "inherit the submit URL" convention — any LinkRef with an empty
 *     `href` picks up `settings.submitFormUrl`, so the submission survey address lives in
 *     exactly one field even though five controls point at it.
 *  3. Enforce the survey's IRB skip logic. This is the only place it happens, and it
 *     happens before content reaches React, so no page can accidentally render a
 *     record that shouldn't exist. See `dropUnapprovedProjects` below.
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

function assertFirePhases(values: unknown, where: string) {
  if (!Array.isArray(values)) return [];
  return [
    ...new Set(
      values.map((value) => {
        if (!isFirePhase(value)) {
          throw new ContentError(
            `${where} references unknown fire cycle phase "${String(value)}". ` +
              'Valid keys are listed in design-system/taxonomy.ts.',
          );
        }
        return value;
      }),
    ),
  ];
}

function assertRegionKeys(values: unknown, where: string) {
  if (!Array.isArray(values)) return [];
  return [
    ...new Set(
      values.map((value) => {
        if (!isRegionKey(value)) {
          throw new ContentError(
            `${where} references unknown coordination region "${String(value)}". ` +
              'Valid values are the ten GACC codes plus PACIFIC, NATIONAL, INTL and ' +
              'UNKNOWN — see design-system/taxonomy.ts.',
          );
        }
        return value;
      }),
    ),
  ];
}

/**
 * Need/recommendation entries. A bare string is accepted as a stand-alone need, so a
 * CMS that stores the older flat list still renders rather than failing the load.
 */
function normalizeNeeds(raw: unknown, where: string): NeedEntry[] {
  if (!Array.isArray(raw)) return [];
  return raw
    .map((entry) => (typeof entry === 'string' ? { need: entry } : (entry as NeedEntry)))
    .filter((entry) => {
      if (entry?.need || entry?.recommendation) return true;
      throw new ContentError(`${where} has an empty need/recommendation entry.`);
    });
}

/**
 * Accepts either the structured author list or a legacy single `author` string.
 *
 * The survey may hand us a free-text byline rather than parsed names, and the
 * pre-survey `projects.json` used one string. Both normalise to the same shape;
 * only the structured path can produce a strict APA citation, which is exactly the
 * distinction the citation builder needs to make.
 */
function normalizeAuthors(raw: unknown, legacy: unknown, where: string): Author[] {
  if (Array.isArray(raw) && raw.length > 0) {
    return raw.map((entry) => {
      if (typeof entry === 'string') return { name: entry };
      const author = entry as Author;
      if (!author?.name) {
        throw new ContentError(`${where} has an author entry with no name.`);
      }
      return author;
    });
  }
  if (typeof legacy === 'string' && legacy.trim()) return [{ name: legacy.trim() }];
  throw new ContentError(`${where} must credit at least one author.`);
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

  const publishedSlugs = new Set(
    (raw.projects as Project[]).filter((p) => p.published && p.irbApproved === true).map((p) => p.slug),
  );

  const topicSummaries = (raw.topicSummaries as TopicSummary[]).map((summary) => ({
    ...summary,
    topic: assertTopicKey(summary.topic, `topicSummaries["${summary.topic}"]`),
    // Older passes stored plain strings; accept them so a CMS mid-migration still renders.
    topNeeds: (summary.topNeeds as (TopNeed | string)[]).map((need) => {
      const entry = typeof need === 'string' ? { text: need } : need;
      // A citation to an unpublished (or IRB-excluded) project must not become a
      // link or a count on the public site.
      const projects = entry.projects?.filter((slug) => publishedSlugs.has(slug));
      // Nor may its mentions: a stored count that includes a dropped project would
      // over-report, so fall back to the count of what is actually public.
      const dropped = (entry.projects?.length ?? 0) !== (projects?.length ?? 0);
      return { ...entry, projects, mentions: dropped ? undefined : entry.mentions };
    }),
  }));

  const projects = (raw.projects as Project[])
    // ---- Survey skip logic, enforced at the boundary -------------------------
    // "Projects without IRB approval excluded." Dropping them here rather than
    // filtering in a page means an un-approved record cannot be rendered by any
    // future page, cannot be counted in a stat, and cannot be reached by a direct
    // URL — none of which is true of a record that is merely never displayed.
    .filter((project) => project.irbApproved === true)
    .map((project) => {
      const where = `project "${project.slug}"`;

      if (typeof project.completionYear !== 'number' || Number.isNaN(project.completionYear)) {
        throw new ContentError(
          `${where} has no completion year. It is a primary filter and a citation field.`,
        );
      }

      if (project.publicationStatus && !isPublicationStatus(project.publicationStatus)) {
        throw new ContentError(
          `${where} has unknown publication status "${project.publicationStatus}".`,
        );
      }

      return {
        ...project,
        authors: normalizeAuthors(project.authors, (project as { author?: string }).author, where),
        firePhases: assertFirePhases(project.firePhases, where),
        geo: project.geo
          ? { ...project.geo, regions: assertRegionKeys(project.geo.regions, where) }
          : undefined,
        methods: project.methods ?? [],
        takeaways: project.takeaways ?? [],
        needs: normalizeNeeds(project.needs, where),
        papers: project.papers ?? [],
        published: project.published ?? false,
      };
    });

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
