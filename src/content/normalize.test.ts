import { describe, expect, it } from 'vitest';
import { asJson, pointerGet, pointerRemove, pointerSet, rawSite, readCases } from './cases';
import { normalizeSiteContent } from './normalize';

interface Check {
  path: string;
  equals?: unknown;
  length?: number;
  absent?: boolean;
  pluck?: string;
}

interface Case {
  name: string;
  set?: { path: string; value: unknown }[];
  remove?: string[];
  throws?: boolean;
  checks?: Check[];
}

const { cases } = readCases<{ cases: Case[] }>('normalize.json');

describe('normalizeSiteContent (shared cases)', () => {
  it.each(cases)('$name', ({ set = [], remove = [], throws, checks = [] }) => {
    const raw = rawSite();
    for (const { path, value } of set) pointerSet(raw, path, value);
    for (const path of remove) pointerRemove(raw, path);

    if (throws) {
      // A deliberate refusal, not a crash on the way there.
      expect(() => normalizeSiteContent(raw)).toThrow(/^\[content\] /);
      return;
    }

    const result = asJson(normalizeSiteContent(raw));
    for (const { path, equals, length, absent, pluck } of checks) {
      const value = pointerGet(result, path);
      if (absent) expect(value, path).toBeUndefined();
      if (length !== undefined) expect(value, path).toHaveLength(length);
      if (equals !== undefined) {
        const actual = pluck ? (value as Record<string, unknown>[]).map((item) => item[pluck]) : value;
        expect(actual, path).toEqual(equals);
      }
    }
  });
});
