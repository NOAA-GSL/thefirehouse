import {
  isFirePhase,
  isPublicationStatus,
  isRegionKey,
  type FirePhase,
  type PublicationStatus,
  type RegionKey,
} from '../design-system/taxonomy';
import { TOPICS, isTopicKey, type TopicKey } from '../design-system/topics';
import { byRecency } from './derive';
import type { Project } from './types';

/**
 * Project explorer search and filtering.
 *
 * Everything here is pure and keyed off the query string, so a filtered, searched,
 * sorted view is a URL someone can paste into an email — the same reason the topic
 * filter was put in the query string in the first place.
 *
 * The dataset is small (tens of projects, not thousands), so search runs in the
 * browser on every keystroke. There is no index to keep in sync with the CMS, and
 * no request to wait on during a conference demo on bad wifi.
 */

export type ProjectSort = 'relevance' | 'newest' | 'oldest' | 'title';

export const SORT_OPTIONS: { key: ProjectSort; label: string }[] = [
  { key: 'relevance', label: 'Best match' },
  { key: 'newest', label: 'Newest first' },
  { key: 'oldest', label: 'Oldest first' },
  { key: 'title', label: 'Title A–Z' },
];

export interface ProjectQuery {
  q: string;
  topic: TopicKey | null;
  phase: FirePhase | null;
  region: RegionKey | null;
  year: number | null;
  status: PublicationStatus | null;
  /** Explicit sort, or null for the default (relevance when searching, else newest). */
  sort: ProjectSort | null;
}

/** The filters a reader can clear individually — everything except text and sort. */
export type FacetKey = 'topic' | 'phase' | 'region' | 'year' | 'status';

export const FACET_KEYS: FacetKey[] = ['topic', 'phase', 'region', 'year', 'status'];

function isSort(value: unknown): value is ProjectSort {
  return SORT_OPTIONS.some((option) => option.key === value);
}

/** Reads a query from the URL, dropping anything malformed rather than erroring. */
export function parseProjectQuery(params: URLSearchParams): ProjectQuery {
  const topic = params.get('topic');
  const phase = params.get('phase');
  const region = params.get('region');
  const status = params.get('status');
  const sort = params.get('sort');
  const year = Number(params.get('year'));

  return {
    q: params.get('q') ?? '',
    topic: isTopicKey(topic) ? topic : null,
    phase: isFirePhase(phase) ? phase : null,
    region: isRegionKey(region) ? region : null,
    year: Number.isInteger(year) && year > 0 ? year : null,
    status: isPublicationStatus(status) ? status : null,
    sort: isSort(sort) ? sort : null,
  };
}

/** Writes a query back to URL params, omitting defaults so URLs stay short. */
export function serializeProjectQuery(query: ProjectQuery): URLSearchParams {
  const params = new URLSearchParams();
  if (query.q) params.set('q', query.q);
  if (query.topic) params.set('topic', query.topic);
  if (query.phase) params.set('phase', query.phase);
  if (query.region) params.set('region', query.region);
  if (query.year) params.set('year', String(query.year));
  if (query.status) params.set('status', query.status);
  if (query.sort) params.set('sort', query.sort);
  return params;
}

export function activeFacetCount(query: ProjectQuery): number {
  return FACET_KEYS.filter((key) => query[key] !== null).length;
}

/**
 * Lower-cased, accent-folded text. "Évacuation" and "evacuation" should match —
 * researchers' names and place names both carry diacritics.
 */
function fold(text: string): string {
  return text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
}

function terms(q: string): string[] {
  return fold(q).split(/\s+/).filter(Boolean);
}

/**
 * Searchable fields, weighted. A hit in the title says far more about what a
 * project is than a hit somewhere in its fourth recommendation.
 */
function fieldsOf(project: Project): [text: string, weight: number][] {
  return [
    [project.title, 6],
    [project.topics.map((t) => `${TOPICS[t].label} ${TOPICS[t].short}`).join(' '), 4],
    [project.authors.map((a) => a.name).join(' '), 4],
    [project.org ?? '', 3],
    [project.summary, 3],
    [project.abstract ?? '', 2],
    [project.takeaways.join(' '), 1],
    [project.needs.join(' '), 1],
    [project.recommendations.join(' '), 1],
    [project.papers.map((p) => p.title).join(' '), 1],
    [project.geo?.note ?? '', 1],
  ];
}

/** True when `a` and `b` are at most one insertion, deletion or substitution apart. */
function withinOneEdit(a: string, b: string): boolean {
  if (Math.abs(a.length - b.length) > 1) return false;
  let i = 0;
  let j = 0;
  let edits = 0;
  while (i < a.length && j < b.length) {
    if (a[i] === b[j]) {
      i += 1;
      j += 1;
      continue;
    }
    if (++edits > 1) return false;
    if (a.length > b.length) i += 1;
    else if (b.length > a.length) j += 1;
    else {
      i += 1;
      j += 1;
    }
  }
  return edits + (a.length - i) + (b.length - j) <= 1;
}

const escapeRegExp = (text: string) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/**
 * Relevance score, or 0 if the project doesn't match.
 *
 * Every term has to appear somewhere (AND, not OR): adding a word should narrow
 * results, which is what people expect from a search box. For each term, the best
 * hit wins:
 *  - a word-start hit ("evac" in "evacuation") at full weight, doubled;
 *  - a mid-word substring hit at full weight;
 *  - for terms of four letters or more, a word one typo away ("forcast") at half
 *    weight — enough to rescue a misspelling without outranking a real match.
 */
export function scoreProject(project: Project, q: string): number {
  const words = terms(q);
  if (words.length === 0) return 1;

  const fields = fieldsOf(project).map(([text, weight]) => {
    const folded = fold(text);
    return { text: folded, weight, tokens: folded.split(/[^\p{L}\p{N}]+/u).filter(Boolean) };
  });

  let score = 0;
  for (const word of words) {
    const startsWord = new RegExp(`(^|[^\\p{L}\\p{N}])${escapeRegExp(word)}`, 'u');
    let best = 0;
    for (const { text, weight, tokens } of fields) {
      if (text.includes(word)) {
        best = Math.max(best, weight * (startsWord.test(text) ? 2 : 1));
      } else if (word.length >= 4 && tokens.some((token) => withinOneEdit(word, token))) {
        best = Math.max(best, weight * 0.5);
      }
    }
    if (best === 0) return 0;
    score += best;
  }
  return score;
}

function matchesFacets(project: Project, query: ProjectQuery, skip?: FacetKey): boolean {
  if (skip !== 'topic' && query.topic && !project.topics.includes(query.topic)) return false;
  if (skip !== 'phase' && query.phase && !project.firePhases.includes(query.phase)) return false;
  if (skip !== 'region' && query.region && !project.geo?.regions.includes(query.region)) return false;
  if (skip !== 'year' && query.year && project.completionYear !== query.year) return false;
  if (skip !== 'status' && query.status && project.publicationStatus !== query.status) return false;
  return true;
}

export function effectiveSort(query: ProjectQuery): ProjectSort {
  if (query.sort && (query.sort !== 'relevance' || query.q.trim())) return query.sort;
  return query.q.trim() ? 'relevance' : 'newest';
}

export function filterProjects(projects: Project[], query: ProjectQuery): Project[] {
  const scored = projects
    .filter((project) => matchesFacets(project, query))
    .map((project) => ({ project, score: scoreProject(project, query.q) }))
    .filter(({ score }) => score > 0);

  switch (effectiveSort(query)) {
    case 'relevance':
      scored.sort((a, b) => b.score - a.score || byRecency(a.project, b.project));
      break;
    case 'newest':
      scored.sort((a, b) => byRecency(a.project, b.project));
      break;
    case 'oldest':
      scored.sort(
        (a, b) =>
          a.project.completionYear - b.project.completionYear ||
          a.project.title.localeCompare(b.project.title),
      );
      break;
    case 'title':
      scored.sort((a, b) => a.project.title.localeCompare(b.project.title));
      break;
  }
  return scored.map(({ project }) => project);
}

/**
 * How many results each option of one facet would give, holding every *other*
 * filter and the search text fixed.
 *
 * Shown next to each option so a reader can see a dead end before choosing it,
 * rather than picking a filter and landing on "no results".
 */
export function facetCounts<T extends string | number>(
  projects: Project[],
  query: ProjectQuery,
  facet: FacetKey,
  valuesOf: (project: Project) => readonly T[],
): Map<T, number> {
  const counts = new Map<T, number>();
  for (const project of projects) {
    if (!matchesFacets(project, query, facet)) continue;
    if (scoreProject(project, query.q) === 0) continue;
    for (const value of valuesOf(project)) counts.set(value, (counts.get(value) ?? 0) + 1);
  }
  return counts;
}
