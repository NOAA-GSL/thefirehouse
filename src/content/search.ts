import {
  isFirePhase,
  isRegionKey,
  type FirePhase,
  type RegionKey,
} from '../design-system/taxonomy';
import { byRecency, effectiveRegions } from './derive';
import type { Project } from './types';

/**
 * Project explorer search and filtering.
 *
 * Everything here is pure and keyed off the query string, so a filtered, searched,
 * sorted view is a URL someone can paste into an email.
 *
 * There is no topic facet: topic areas are a synthesis across the whole collection,
 * not a tag on any one project (see `TopicSummary`).
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
  phase: FirePhase | null;
  region: RegionKey | null;
  year: number | null;
  /** Survey Q11 project type, matched exactly ("Testbed Evaluation"). */
  type: string | null;
  /** Explicit sort, or null for the default (relevance when searching, else newest). */
  sort: ProjectSort | null;
}

/** The filters a reader can clear individually — everything except text and sort. */
export type FacetKey = 'phase' | 'region' | 'year' | 'type';

export const FACET_KEYS: FacetKey[] = ['phase', 'region', 'year', 'type'];

function isSort(value: unknown): value is ProjectSort {
  return SORT_OPTIONS.some((option) => option.key === value);
}

/** Reads a query from the URL, dropping anything malformed rather than erroring. */
export function parseProjectQuery(params: URLSearchParams): ProjectQuery {
  const phase = params.get('phase');
  const region = params.get('region');
  const sort = params.get('sort');
  const year = Number(params.get('year'));

  return {
    q: params.get('q') ?? '',
    phase: isFirePhase(phase) ? phase : null,
    region: isRegionKey(region) ? region : null,
    // A safe integer, so the server reads and writes it back the same.
    year: Number.isSafeInteger(year) && year > 0 ? year : null,
    type: params.get('type')?.trim() || null,
    sort: isSort(sort) ? sort : null,
  };
}

/** Writes a query back to URL params, omitting defaults so URLs stay short. */
export function serializeProjectQuery(query: ProjectQuery): URLSearchParams {
  const params = new URLSearchParams();
  if (query.q) params.set('q', query.q);
  if (query.phase) params.set('phase', query.phase);
  if (query.region) params.set('region', query.region);
  if (query.year) params.set('year', String(query.year));
  if (query.type) params.set('type', query.type);
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

/**
 * The most words a search counts; any after them are ignored. No real search comes
 * near it, and it bounds the work one long query string can ask of the server, which
 * runs this search too (WORDPRESS-PLAN.md, Phase 5).
 */
export const MAX_TERMS = 20;

function terms(q: string): string[] {
  return fold(q).split(/\s+/).filter(Boolean).slice(0, MAX_TERMS);
}

/**
 * Searchable fields, weighted. A hit in the title says far more about what a
 * project is than a hit somewhere in its fourth recommendation.
 */
function fieldsOf(project: Project): [text: string, weight: number][] {
  return [
    [project.title, 6],
    [project.projectType ?? '', 4],
    [project.authors.map((a) => a.name).join(' '), 4],
    [project.org ?? '', 3],
    [project.summary, 3],
    [project.abstract ?? '', 2],
    [project.takeaways.join(' '), 1],
    [project.methods.join(' '), 2],
    [project.needs.map((n) => `${n.need ?? ''} ${n.recommendation ?? ''}`).join(' '), 1],
    [project.papers.map((p) => `${p.title ?? ''} ${p.container ?? ''}`).join(' '), 1],
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

interface FoldedField {
  text: string;
  weight: number;
  tokens: string[];
}

/**
 * Each project's searchable fields, folded and split into words, worked out the first
 * time the project is scored and kept: content isn't changed in place once it's loaded
 * (normalize builds it), so a search as you type folds each field once, not on every
 * keystroke. Kept by the project object, so a project nothing holds any more is let go.
 */
const folded = new WeakMap<Project, FoldedField[]>();

function foldedFields(project: Project): FoldedField[] {
  let fields = folded.get(project);
  if (!fields) {
    fields = fieldsOf(project).map(([text, weight]) => {
      const foldedText = fold(text);
      return { text: foldedText, weight, tokens: foldedText.split(/[^\p{L}\p{N}]+/u).filter(Boolean) };
    });
    folded.set(project, fields);
  }
  return fields;
}

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

  const fields = foldedFields(project);

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
  if (skip !== 'phase' && query.phase && !project.firePhases.includes(query.phase)) return false;
  if (skip !== 'region' && query.region && !effectiveRegions(project.geo?.regions).includes(query.region)) {
    return false;
  }
  if (skip !== 'year' && query.year && project.completionYear !== query.year) return false;
  if (skip !== 'type' && query.type && project.projectType !== query.type) return false;
  return true;
}

export function effectiveSort(query: ProjectQuery): ProjectSort {
  if (query.sort && (query.sort !== 'relevance' || query.q.trim())) return query.sort;
  return query.q.trim() ? 'relevance' : 'newest';
}

/**
 * Each project's relevance to the search text, by its index in `projects`: what
 * `scoreProject` gives each. A page that both lists and counts scores once and passes
 * these to `filterProjects` and `facetCounts`, which would otherwise each score every
 * project again, five times over for the explorer. The PHP has the same
 * (`Search::scores()`).
 */
export function scores(projects: Project[], q: string): number[] {
  return projects.map((project) => scoreProject(project, q));
}

export function filterProjects(projects: Project[], query: ProjectQuery, projectScores?: number[]): Project[] {
  const scored = projects
    .map((project, index) => ({ project, index }))
    .filter(({ project }) => matchesFacets(project, query))
    .map(({ project, index }) => ({ project, score: projectScores?.[index] ?? scoreProject(project, query.q) }))
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
          a.project.title.localeCompare(b.project.title, 'en-US'),
      );
      break;
    case 'title':
      scored.sort((a, b) => a.project.title.localeCompare(b.project.title, 'en-US'));
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
  projectScores?: number[],
): Map<T, number> {
  const counts = new Map<T, number>();
  for (const [index, project] of projects.entries()) {
    if (!matchesFacets(project, query, facet)) continue;
    if ((projectScores?.[index] ?? scoreProject(project, query.q)) === 0) continue;
    for (const value of valuesOf(project)) counts.set(value, (counts.get(value) ?? 0) + 1);
  }
  return counts;
}
