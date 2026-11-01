import { describe, expect, it } from 'vitest';
import { asJson, readCases } from './cases';
import { formatByline, formatReviewDate, linkLabel, splitNeedLead } from './format';

interface Case {
  name: string;
  fn: keyof typeof FUNCTIONS;
  args: unknown[];
  expected: unknown;
}

const FUNCTIONS = {
  formatReviewDate: (iso: string) => formatReviewDate(iso),
  formatByline: (authors: { name: string }[], max?: number) => formatByline(authors, max),
  splitNeedLead: (need: string) => splitNeedLead(need),
  linkLabel: (url: string) => linkLabel(url),
} as const;

const { cases } = readCases<{ cases: Case[] }>('format.json');

describe('format (shared cases)', () => {
  it.each(cases)('$fn: $name', ({ fn, args, expected }) => {
    const call = FUNCTIONS[fn] as (...a: unknown[]) => unknown;
    expect(asJson(call(...args))).toEqual(expected);
  });
});
