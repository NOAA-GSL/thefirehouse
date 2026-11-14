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
    for (const string of ['', 'q=smoke', 'q=smoke+fire&sort=title', 'q=forcast&phase=during', 'q=zzzz', 'phase=before&sort=oldest', 'q=evac&region=NWCC']) {
      const query = parseProjectQuery(new URLSearchParams(string));
      const scored = scores(projects, query.q);
      expect(scored, string).toEqual(projects.map((project) => scoreProject(project, query.q)));
      expect(slugs(filterProjects(projects, query, scored)), string).toEqual(slugs(filterProjects(projects, query)));
      for (const [facet, valuesOf] of Object.entries(VALUES)) {
        expect(facetCounts(projects, query, facet as FacetKey, valuesOf, scored), `${string} ${facet}`).toEqual(facetCounts(projects, query, facet as FacetKey, valuesOf));
      }
    }
  });

  it('scores a project the same however often it is scored', () => {
    const project = content.projects[0];
    expect(scoreProject(project, 'smoke')).toBe(scoreProject(project, 'smoke'));
    expect(scoreProject(project, 'zzzz')).toBe(0);
    expect(scoreProject(project, 'smoke')).toBeGreaterThan(0);
  });
});
