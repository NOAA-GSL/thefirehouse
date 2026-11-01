import { describe, expect, it } from 'vitest';
import { asJson, readCases, resolveArg, site, slugs } from './cases';
import {
  buildTopicCards,
  byRecency,
  effectiveRegions,
  findProjectBySlug,
  needMentions,
  projectsInRegion,
  publishedProjects,
  regionCounts,
  resolveStatValue,
  synthesisInfo,
  type TopicCard,
} from './derive';
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
  publishedProjects,
  resolveStatValue,
  buildTopicCards,
  findProjectBySlug,
  sortByRecency: (projects: Project[]) => [...projects].sort(byRecency),
  needMentions,
  synthesisInfo,
  effectiveRegions,
  projectsInRegion,
  regionCounts,
} as unknown as Record<string, (...args: unknown[]) => unknown>;

const VIEWS = {
  slugs: (value: unknown) => slugs(value as Project[]),
  slug: (value: unknown) => (value as Project | undefined)?.slug ?? null,
  cards: (value: unknown) =>
    (value as TopicCard[]).map(({ topic, needs }) => ({
      topic: topic.key,
      needs: needs.map((need) => need.text),
    })),
};

const { cases } = readCases<{ cases: Case[] }>('derive.json');
const content = site();

describe('derive (shared cases)', () => {
  it.each(cases)('$fn: $name', ({ fn, args, view, expected }) => {
    const result = FUNCTIONS[fn](...args.map((arg) => resolveArg(arg, content)));
    expect(asJson(view ? VIEWS[view](result) : result)).toEqual(expected);
  });
});
