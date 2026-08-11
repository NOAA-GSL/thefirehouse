import { normalizeSiteContent } from '../normalize';
import type { ContentAdapter, SiteContent } from '../types';

/**
 * Sanity adapter.
 *
 * Uses Sanity's HTTP query API directly rather than `@sanity/client`, so the app
 * carries no CMS SDK — switching to Strapi later is a config change, not a
 * dependency change. One GROQ query returns the whole site.
 *
 * Required env (see `.env.example`):
 *   VITE_CMS=sanity
 *   VITE_SANITY_PROJECT_ID
 *   VITE_SANITY_DATASET        (default: production)
 *   VITE_SANITY_API_VERSION    (default: 2024-10-01)
 *
 * Reads use the public CDN endpoint and no token — this is public content, and a
 * read token in a client bundle is not a secret. If drafts ever need previewing,
 * that belongs behind a server route, not here.
 */

const QUERY = /* groq */ `{
  "settings": *[_type == "siteSettings"][0]{
    siteName,
    nav[]{label, to, href},
    submitLabel,
    submitFormUrl,
    footerBlurb,
    footerGroups[]{heading, links[]{label, to, href}},
    agencyLine,
    usaGovUrl
  },
  "topics": *[_type == "topic"]|order(order asc){
    "key": key.current, label, short, description, intro, covers, order
  },
  "topicSummaries": *[_type == "topicSummary"]{
    "topic": topic->key.current, topNeeds, updatedAt
  },
  "projects": *[_type == "project"]|order(year desc, title asc){
    "id": _id,
    "slug": slug.current,
    title,
    "topic": topic->key.current,
    summary, author, org, year,
    takeaways, needs, recommendations,
    papers[]{title, url},
    fullRecordUrl,
    published
  },
  "landing": *[_type == "landingPage"][0]{
    hero{
      eyebrow, heading, body,
      primaryCta{label, to, href},
      secondaryCta{label, to, href},
      "imageUrl": image.asset->url,
      "imageAlt": coalesce(image.alt, "")
    },
    stats[]{id, source, value, label, caption, accent},
    topicSection{eyebrow, heading, body},
    needsPerCard,
    submitBand{heading, body, cta{label, to, href}}
  }
}`;

function requireEnv(name: string): string {
  const value = import.meta.env[name as keyof ImportMetaEnv] as string | undefined;
  if (!value) throw new Error(`[content] ${name} is required when VITE_CMS=sanity.`);
  return value;
}

export function createSanityAdapter(): ContentAdapter {
  return {
    name: 'sanity',
    async load(): Promise<SiteContent> {
      const projectId = requireEnv('VITE_SANITY_PROJECT_ID');
      const dataset = import.meta.env.VITE_SANITY_DATASET ?? 'production';
      const apiVersion = import.meta.env.VITE_SANITY_API_VERSION ?? '2024-10-01';

      const url =
        `https://${projectId}.apicdn.sanity.io/v${apiVersion}/data/query/${dataset}` +
        `?query=${encodeURIComponent(QUERY)}`;

      const response = await fetch(url, { headers: { Accept: 'application/json' } });
      if (!response.ok) {
        throw new Error(`[content] Sanity query failed: ${response.status} ${response.statusText}`);
      }

      const { result } = (await response.json()) as { result: Record<string, unknown> };
      return normalizeSiteContent({
        settings: result.settings,
        topics: result.topics,
        topicSummaries: result.topicSummaries,
        projects: result.projects,
        landing: result.landing,
      });
    },
  };
}
