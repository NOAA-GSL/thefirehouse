import landing from '../data/landing.json';
import projects from '../data/projects.json';
import settings from '../data/settings.json';
import topicSummaries from '../data/topicSummaries.json';
import topics from '../data/topics.json';
import { normalizeSiteContent } from '../normalize';
import type { ContentAdapter, SiteContent } from '../types';

/**
 * Bundled-JSON adapter — the default until a CMS is live.
 *
 * Content ships with the build, so there is no network call, no loading state that
 * can fail, and the site is fully static. That's deliberate for the AMS launch: it
 * works from a conference floor on bad wifi, and it means hosting placement
 * (creative brief §8, still open) can be a plain static bucket.
 */
export const localAdapter: ContentAdapter = {
  name: 'local',
  async load(): Promise<SiteContent> {
    return normalizeSiteContent({
      settings,
      topics,
      topicSummaries,
      projects,
      landing,
    });
  },
};
