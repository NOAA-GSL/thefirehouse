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

  // mix() reads #rrggbb only, and the two languages part ways on anything else: with
  // #fff, JavaScript writes "NaN" where PHP's hexdec() reads 0.
  it('has two ramps, each colour mix() reads written #rrggbb', () => {
    expect(Object.keys(coverageMap.ramps).sort()).toEqual(['dark', 'light']);
    for (const theme of ['dark', 'light'] as const) {
      const ramp = RAMPS[theme];
      expect(ramp.stops, theme).toHaveLength(4);
      for (const colour of [...ramp.stops, ramp.empty.fill, ramp.empty.stroke, ramp.selected]) {
        expect(colour, theme).toMatch(/^#[0-9a-f]{6}$/);
      }
    }
  });
});
