import { describe, expect, it } from 'vitest';
import { asJson, readCases } from '../content/cases';
import { heat, mix, RAMPS, type MapTheme } from './heat';
import coverageMap from '../../wp-content/plugins/firehouse/shared/coverage-map.json' with { type: 'json' };

interface Case {
  name: string;
  fn: keyof typeof FUNCTIONS;
  args: unknown[];
  expected: unknown;
}

const FUNCTIONS = {
  heat: (count: number, max: number, theme: MapTheme) => heat(count, max, theme),
  mix: (a: string, b: string, t: number) => mix(a, b, t),
} as const;

const { cases } = readCases<{ cases: Case[] }>('coverage-map.json');

describe('the coverage map colours (shared cases)', () => {
  it.each(cases)('$fn: $name', ({ fn, args, expected }) => {
    const call = FUNCTIONS[fn] as (...a: unknown[]) => unknown;
    expect(asJson(call(...args))).toEqual(expected);
  });

  it('reads its ramps from the shared file', () => {
    expect(RAMPS).toEqual(coverageMap.ramps);
    expect(Object.keys(RAMPS).sort()).toEqual(['dark', 'light']);
  });
});
