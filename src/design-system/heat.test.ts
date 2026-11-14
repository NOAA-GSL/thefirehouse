import { describe, expect, it } from 'vitest';
import { asJson, readCases } from '../content/cases';
import { heat, hoveredStyle, mix, RAMPS, regionStyle, type MapTheme } from './heat';
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

describe('a region on the map', () => {
  const at = (selected: 'GBCC' | 'NWCC' | null) => regionStyle('GBCC', 2, 4, 'light', selected);
  const base = heat(2, 4, 'light');

  it('is coloured by its count, with nothing selected', () => {
    expect(at(null)).toEqual({ color: base.stroke, weight: 1.2, opacity: 0.9, fillColor: base.fill, fillOpacity: base.alpha });
  });

  it('is outlined when selected, and dimmed when another is', () => {
    expect(at('GBCC')).toMatchObject({ color: RAMPS.light.selected, weight: 3.5, fillOpacity: base.alpha });
    expect(at('NWCC')).toMatchObject({ color: base.stroke, weight: 1.2, fillOpacity: base.alpha * 0.35 });
  });

  it('answers the pointer a step above undimmed, and keeps the selected outline', () => {
    expect(hoveredStyle('GBCC', 2, 4, 'light', 'NWCC')).toMatchObject({ weight: 3, fillOpacity: base.alpha + 0.2 });
    expect(hoveredStyle('GBCC', 2, 4, 'light', 'GBCC')).toMatchObject({ color: RAMPS.light.selected, weight: 3.5 });
  });
});

describe('the basemap', () => {
  it('has a tile address for each theme, from one host, and one attribution', () => {
    expect(Object.keys(coverageMap.basemap.tiles).sort()).toEqual(['dark', 'light']);
    for (const url of Object.values(coverageMap.basemap.tiles)) {
      expect(url).toMatch(/^https:\/\/server\.arcgisonline\.com\/ArcGIS\/rest\/services\/Canvas\/[A-Za-z_]+\/MapServer\/tile\/\{z\}\/\{y\}\/\{x\}$/);
    }
    expect(coverageMap.basemap.attribution).toMatch(/Esri.*OpenStreetMap/);
  });
});
