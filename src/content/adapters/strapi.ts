import { normalizeSiteContent } from '../normalize';
import type { ContentAdapter, SiteContent } from '../types';

/**
 * Strapi adapter (Strapi v5 REST shape).
 *
 * Strapi returns `{ data: … }` envelopes and relation objects; everything is
 * flattened here so the rest of the app never sees a Strapi-ism. Four requests in
 * parallel — Strapi has no single-query equivalent to GROQ.
 *
 * Required env (see `.env.example`):
 *   VITE_CMS=strapi
 *   VITE_STRAPI_URL          e.g. https://cms.example.gov
 *   VITE_STRAPI_TOKEN        optional read-only API token, if the API isn't public
 */

interface StrapiEnvelope<T> {
  data: T;
}

function baseUrl(): string {
  const url = import.meta.env.VITE_STRAPI_URL;
  if (!url) throw new Error('[content] VITE_STRAPI_URL is required when VITE_CMS=strapi.');
  return url.replace(/\/$/, '');
}

async function get<T>(path: string): Promise<T> {
  const token = import.meta.env.VITE_STRAPI_TOKEN;
  const response = await fetch(`${baseUrl()}/api/${path}`, {
    headers: {
      Accept: 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });
  if (!response.ok) {
    throw new Error(`[content] Strapi request /${path} failed: ${response.status}`);
  }
  const body = (await response.json()) as StrapiEnvelope<T>;
  return body.data;
}

/** Strapi nests relations one level deep; topics arrive as `{ key: "observe", … }`. */
function topicKeyOf(relation: unknown): string {
  if (relation && typeof relation === 'object' && 'key' in relation) {
    return String((relation as { key: unknown }).key);
  }
  return String(relation);
}

export function createStrapiAdapter(): ContentAdapter {
  return {
    name: 'strapi',
    async load(): Promise<SiteContent> {
      const [settings, topics, summaries, projects, landing] = await Promise.all([
        get<Record<string, unknown>>('site-setting?populate=deep'),
        get<Record<string, unknown>[]>('topics?sort=order:asc'),
        get<Record<string, unknown>[]>('topic-summaries?populate=topic'),
        get<Record<string, unknown>[]>('projects?populate=topic&populate=papers&sort=year:desc'),
        get<Record<string, unknown>>('landing-page?populate=deep'),
      ]);

      return normalizeSiteContent({
        settings,
        topics,
        topicSummaries: summaries.map((s) => ({ ...s, topic: topicKeyOf(s.topic) })),
        projects: projects.map((p) => ({ ...p, topic: topicKeyOf(p.topic) })),
        landing,
      });
    },
  };
}
