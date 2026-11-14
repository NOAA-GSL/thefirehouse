import type { RegionKey } from './taxonomy';
import coverageMap from '../../wp-content/plugins/firehouse/shared/coverage-map.json' with { type: 'json' };

/**
 * The coverage map's colours: a sequential blue ramp for a choropleth over a *small*
 * count range. The ramps live in `wp-content/plugins/firehouse/shared/coverage-map.json`,
 * which the plugin reads too, and `shared/cases/coverage-map.json` holds this and its PHP
 * port, `Map_Colors`, to the same answers.
 *
 * Blue rather than the ember/amber ramp this started with: on a fire site, red and
 * yellow read as danger and caution, so a busy region looked like a *hazard* rather
 * than a place with a lot of research. Blue carries "more" without "worse".
 *
 * Three decisions worth spelling out, because the obvious version of this looks fine
 * in a colour picker and fails on the actual map:
 *
 * 1. **Normalised against the busiest region, not an absolute scale.** With nine
 *    submissions an absolute ramp renders everything at the cold end and the map
 *    says nothing. It rescales as submissions arrive.
 *
 * 2. **One ramp per basemap.** The basemap follows the theme, and "more" has to
 *    read as *more contrast against the tiles* on either one: brighter on the dark
 *    basemap, deeper on the light one. A single ramp would put its high end at the
 *    same luminance as one of the two basemaps.
 *
 * 3. **The low end never matches the basemap.** A one-submission region is the
 *    one a reader most needs to notice, so the ramp starts clearly off the tile
 *    colour, and the outline stays crisp. Intensity is a *secondary* channel on top
 *    of the count printed in the list.
 */

export type MapTheme = 'dark' | 'light';

export interface MapRamp {
  stops: string[];
  empty: { fill: string; stroke: string };
  selected: string;
  label: { text: string; halo: string };
}

export const RAMPS: Record<MapTheme, MapRamp> = coverageMap.ramps;

/** Mixes two `#rrggbb` colours, `t` of the way from `a` to `b`. */
export function mix(a: string, b: string, t: number): string {
  const pa = [1, 3, 5].map((o) => parseInt(a.slice(o, o + 2), 16));
  const pb = [1, 3, 5].map((o) => parseInt(b.slice(o, o + 2), 16));
  const out = pa.map((v, k) => Math.round(v + (pb[k] - v) * t));
  return `#${out.map((v) => v.toString(16).padStart(2, '0')).join('')}`;
}

/** A region's fill, outline and fill opacity, from its count and the busiest region's. */
export function heat(
  count: number,
  max: number,
  theme: MapTheme,
): { fill: string; stroke: string; alpha: number } {
  const ramp = RAMPS[theme];
  if (count === 0) {
    // Present but plainly empty: a neutral outline, almost no fill.
    return { ...ramp.empty, alpha: 0.05 };
  }

  const t = max <= 1 ? 1 : (count - 1) / (max - 1);
  const { stops } = ramp;
  const scaled = t * (stops.length - 1);
  const i = Math.min(Math.floor(scaled), stops.length - 2);
  const local = scaled - i;

  const fill = mix(stops[i], stops[i + 1], local);
  // Stroke is pulled toward the high-contrast end of the ramp regardless of count,
  // so a sparse region still has a crisp edge.
  const stroke = mix(stops[Math.max(i, 1)], stops[Math.min(i + 2, stops.length - 1)], local);

  return { fill, stroke, alpha: 0.38 + 0.4 * t };
}

/** How one region is drawn. */
export interface RegionStyle {
  color: string;
  weight: number;
  opacity: number;
  fillColor: string;
  fillOpacity: number;
}

/**
 * A region's style, with the selection applied: coloured by its count against the
 * busiest GACC's, the selected region outlined, and, while one is selected, the rest
 * dimmed rather than only the selected one brightened: at these counts a single
 * highlight is easy to miss.
 */
export function regionStyle(
  key: RegionKey,
  count: number,
  max: number,
  theme: MapTheme,
  selected: RegionKey | null,
): RegionStyle {
  const { fill, stroke, alpha } = heat(count, max, theme);
  const isSelected = selected === key;
  return {
    color: isSelected ? RAMPS[theme].selected : stroke,
    weight: isSelected ? 3.5 : 1.2,
    opacity: 0.9,
    fillColor: fill,
    fillOpacity: selected && !isSelected ? alpha * 0.35 : alpha,
  };
}

/**
 * The same region under the pointer: a heavier edge, and its fill a step stronger than
 * undimmed, so a dimmed region still answers. Leaving it puts back `regionStyle()`,
 * the selection included.
 */
export function hoveredStyle(
  key: RegionKey,
  count: number,
  max: number,
  theme: MapTheme,
  selected: RegionKey | null,
): RegionStyle {
  const style = regionStyle(key, count, max, theme, selected);
  return { ...style, weight: Math.max(style.weight, 3), fillOpacity: heat(count, max, theme).alpha + 0.2 };
}
