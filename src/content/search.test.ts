import { describe, expect, it } from 'vitest';
import { asJson, readCases, resolveArg, site, slugs } from './cases';
import { effectiveRegions } from './derive';
import {
  activeFacetCount,
  effectiveSort,
  facetCounts,
  filterProjects,
  parseProjectQuery,
  scoreProject,
  scores,
  serializeProjectQuery,
  type FacetKey,
  type ProjectQuery,
} from './search';
import type { Project } from './types';

interface Case {
  name: string;
  fn: keyof typeof FUNCTIONS;
  args: unknown[];
  view?: keyof typeof VIEWS;
  expected: unknown;
}

// Each entry spreads its case's arguments; the casts are the JSON boundary.
const FUNCTIONS = {
  parseProjectQuery: (query: string) => parseProjectQuery(new URLSearchParams(query)),
  scores,
  filterProjectsScored: (projects: Project[], query: ProjectQuery) => filterProjects(projects, query, scores(projects, query.q)),
  facetCountsScored: (projects: Project[], query: ProjectQuery, facet: FacetKey, valuesOf: (project: Project) => readonly (string | number)[]) =>
    facetCounts(projects, query, facet, valuesOf, scores(projects, query.q)),
  serializeProjectQuery,
  activeFacetCount,
  effectiveSort,
  scoreProject,
  filterProjects,
  facetCounts,
} as unknown as Record<string, (...args: unknown[]) => unknown>;

const VIEWS = {
  slugs: (value: unknown) => slugs(value as Project[]),
  string: (value: unknown) => String(value),
};

/** What the explorer counts for each facet (see `ProjectsPage`). */
const VALUES: Record<string, (project: Project) => readonly (string | number)[]> = {
  phase: (project) => project.firePhases,
  region: (project) => effectiveRegions(project.geo?.regions),
  year: (project) => [project.completionYear],
  type: (project) => (project.projectType ? [project.projectType] : []),
};

const { cases } = readCases<{ cases: Case[] }>('search.json');
const content = site();

function arg(value: unknown): unknown {
  if (typeof value === 'string' && value.startsWith('@values:')) {
    return VALUES[value.slice('@values:'.length)];
  }
  return resolveArg(value, content);
}

describe('search (shared cases)', () => {
  it.each(cases)('$fn: $name', ({ fn, args, view, expected }) => {
    const result = FUNCTIONS[fn](...args.map(arg));
    expect(asJson(view ? VIEWS[view](result) : result)).toEqual(expected);
  });
});

describe('search, scored once', () => {
  it('gives with scores passed in what it gives scoring each time, for every sort, filter and count', () => {
    const projects = content.projects.filter((project) => project.published);
    // q=recovery&phase=after and q=outlook&phase=long-term leave out an earlier project,
    // so a score taken by its place after the filter would be another project's.
    for (const string of ['', 'q=smoke', 'q=smoke+fire&sort=title', 'q=forcast&phase=during', 'q=zzzz', 'phase=before&sort=oldest', 'q=evac&region=NWCC', 'q=recovery&phase=after', 'q=outlook&phase=long-term']) {
      const query = parseProjectQuery(new URLSearchParams(string));
      const scored = scores(projects, query.q);
      expect(scored, string).toEqual(projects.map((project) => scoreProject(project, query.q)));
      expect(slugs(filterProjects(projects, query, scored)), string).toEqual(slugs(filterProjects(projects, query)));
      for (const [facet, valuesOf] of Object.entries(VALUES)) {
        expect(facetCounts(projects, query, facet as FacetKey, valuesOf, scored), `${string} ${facet}`).toEqual(facetCounts(projects, query, facet as FacetKey, valuesOf));
      }
    }
  });

  it('ignores scores for a list of another length', () => {
    const projects = content.projects.filter((project) => project.published);
    const query = parseProjectQuery(new URLSearchParams('q=smoke'));
    const scored = scores(projects, query.q);
    expect(slugs(filterProjects(projects.slice(1), query, scored))).toEqual(slugs(filterProjects(projects.slice(1), query)));
    expect(facetCounts(projects.slice(1), query, 'phase', VALUES.phase, scored)).toEqual(facetCounts(projects.slice(1), query, 'phase', VALUES.phase));
  });

  it('scores a project the same however often it is scored', () => {
    const project = content.projects[0];
    expect(scoreProject(project, 'smoke')).toBe(scoreProject(project, 'smoke'));
    expect(scoreProject(project, 'zzzz')).toBe(0);
    expect(scoreProject(project, 'smoke')).toBeGreaterThan(0);
  });
});
