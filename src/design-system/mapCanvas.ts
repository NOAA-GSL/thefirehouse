import L from 'leaflet';
import boundaries from '../content/data/gaccBoundaries.json';
import coverageMap from '../../wp-content/plugins/firehouse/shared/coverage-map.json' with { type: 'json' };
import { hoveredStyle, RAMPS, regionStyle, type MapTheme } from './heat';
import { GACC_LIST, REGIONS, type RegionKey } from './taxonomy';

/**
 * The coverage map's drawing: Leaflet, the ten GACCs' boundaries coloured by how much
 * is filed under each, the basemap for the theme, the GACC codes, and a view that
 * follows the selected region.
 *
 * Plain TypeScript, with no framework, so two pages draw the same map from it: the
 * site's `RegionMap`, and the plugin's view script (blocks/coverage-map/view.js),
 * through the module scripts/plugin-assets.mjs bundles from this file with Leaflet and
 * the boundaries. Leaflet's CSS isn't imported here: each page loads it its own way.
 *
 * Everything a visitor reads or operates is outside it, in the region list, which
 * carries the same counts and drives the same selection. The map is drawn for the eye:
 * its host is `aria-hidden`, and nothing in it takes keyboard focus.
 *
 * Boundaries are the authoritative NIFC file (`National_GACC_Boundaries`, served from
 * NIFC's ArcGIS instance), simplified and committed to the repo rather than fetched at
 * runtime. Committing it means the map still draws when the network is hostile — a
 * conference floor, or a federal network that blocks the host outright. The basemap
 * tiles are the only live dependency (`shared/coverage-map.json`), and the map degrades
 * to boundaries on a blank canvas if they fail rather than showing nothing.
 */

/** Esri's basemap, a tile address per theme, and the attribution its terms require. */
export const BASEMAP: { attribution: string; tiles: Record<MapTheme, string> } = coverageMap.basemap;

/**
 * Default view: the lower 48.
 *
 * Framing Alaska, Hawaii and CONUS at once is the obvious first instinct and it is
 * wrong — Web Mercator has to zoom out to most of the Pacific to hold all three,
 * and the result is a map where every mainland region is a few pixels across. The
 * default therefore shows the area most submissions are in, and selecting Alaska or
 * the Pacific flies there (see `OFF_FRAME`), which is also a better answer than a
 * static inset: the reader gets the whole region rather than a thumbnail of it.
 */
const HOME_VIEW: L.LatLngBoundsExpression = [
  [24.4, -125.2],
  [49.6, -66.5],
];

/** How far out the reader may pan — enough for Alaska and Hawaii, not the globe. */
const MAX_VIEW: L.LatLngBoundsExpression = [
  [10.0, -180.0],
  [72.0, -60.0],
];

/** Regions that sit outside the default frame and need a fly-to. */
const OFF_FRAME: Partial<Record<RegionKey, L.LatLngBoundsExpression>> = {
  AICC: [
    [51.0, -170.0],
    [71.5, -130.0],
  ],
  PACIFIC: [
    [18.5, -160.5],
    [22.5, -154.5],
  ],
};

/** What the map shows: the counts, the theme, the selected region and the GACC codes. */
export interface MapState {
  /** Each region's count, a national study already counted in every GACC. */
  counts: Partial<Record<RegionKey, number>>;
  theme: MapTheme;
  selected: RegionKey | null;
  /** Whether the GACC codes are drawn. They're always in the list beside the map. */
  showCodes: boolean;
}

export interface MapCanvasOptions extends MapState {
  /** What a region's tooltip says under its name: "4 submissions". */
  submissions: (count: number) => string;
  /** A region's shape was clicked: the region to select, or null to select none. */
  onSelect: (key: RegionKey | null) => void;
  /** Whether the basemap's latest tile failed (true) or loaded (false). */
  onTiles: (failed: boolean) => void;
}

export interface MapCanvas {
  /** Redraws what changed. */
  update: (changes: Partial<MapState>) => void;
  /** Takes the map down, and everything it listens to. */
  remove: () => void;
}

/** Whether the visitor has asked for less motion: the view then moves without flying. */
const still = () => typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * Draws the map into `host`, an empty element with a height, and returns what redraws
 * and removes it.
 */
export function drawMap(host: HTMLElement, options: MapCanvasOptions): MapCanvas {
  let state: MapState = {
    counts: options.counts,
    theme: options.theme,
    selected: options.selected,
    showCodes: options.showCodes,
  };
  const countOf = (key: RegionKey) => state.counts[key] ?? 0;
  let max = 1;

  const map = L.map(host, {
    zoomControl: false,
    attributionControl: false,
    // Never hijack page scroll. The map sits mid-page, so a wheel-zoom here would
    // trap someone trying to scroll past it — a classic way to make a landing page
    // feel broken.
    scrollWheelZoom: false,
    doubleClickZoom: false,
    // Leaflet's own keyboard handling would put the canvas in the tab order as a
    // single opaque stop. The region list is the operable surface instead.
    keyboard: false,
  });
  map.setMaxBounds(MAX_VIEW);
  const zoom = L.control.zoom({ position: 'bottomright' }).addTo(map);
  // The zoom buttons are links, and the host is aria-hidden: in the Tab order, each
  // would be a stop that says nothing (the Section 508 review, 14 November). They stay
  // for a pointer; a keyboard chooses a region in the list, and the map flies there.
  zoom
    .getContainer()
    ?.querySelectorAll('a')
    .forEach((link) => link.setAttribute('tabindex', '-1'));

  /**
   * The view the map should be showing, and how it was framed.
   *
   * Kept because the ResizeObserver below has to restore *this*, not the home view.
   * Without it, arriving on `?region=GBCC` zoomed to the Great Basin and was then
   * immediately yanked back out to the whole country when the observer fired on
   * first layout — a deep link that visibly undid itself.
   */
  let view: { bounds: L.LatLngBoundsExpression; options?: L.FitBoundsOptions } = { bounds: HOME_VIEW };

  // Leaflet measures its container once, at construction. In a CSS grid, the
  // container has no final width at that moment — so the map computes a zero-size
  // viewport, requests a handful of zoom-2 tiles and renders a sliver. It looks exactly
  // like "tiles failed to load", which is the wrong thing to go debugging.
  //
  // A ResizeObserver fixes it for every case at once: first layout, the section's
  // breakpoint, a window drag, and the panel reflowing when the region list wraps.
  // The view is fitted again because invalidateSize alone keeps the stale centre and
  // leaves Alaska cropped. It jumps: a refit is layout, not a journey.
  const observer = new ResizeObserver(() => {
    map.invalidateSize({ animate: false });
    map.fitBounds(view.bounds, { ...view.options, animate: false });
  });
  observer.observe(host);

  /* ---- Basemap, swapped with the theme ---- */
  let tiles: L.TileLayer | null = null;
  function drawTiles() {
    tiles?.remove();
    // No Referer: Esri needs nothing from the page to serve a tile, so it isn't told
    // which site, or which address on it, the visitor is reading.
    const layer = L.tileLayer(BASEMAP.tiles[state.theme], { maxZoom: 8, minZoom: 2, referrerPolicy: 'no-referrer' });
    layer.on('tileerror', () => options.onTiles(true));
    layer.on('tileload', () => options.onTiles(false));
    layer.addTo(map);
    tiles = layer;
  }

  /* ---- The GACC codes' colours, which the theme sets ---- */
  function paintCodes() {
    host.style.setProperty('--map-label', RAMPS[state.theme].label.text);
    host.style.setProperty('--map-label-halo', RAMPS[state.theme].label.halo);
  }

  /* ---- Region polygons ---- */
  const layers = new Map<RegionKey, L.GeoJSON>();
  const styleOf = (key: RegionKey) => regionStyle(key, countOf(key), max, state.theme, state.selected);

  /** A region's tooltip: its name, and how many are filed under it. */
  function tip(key: RegionKey) {
    const box = document.createElement('span');
    const name = document.createElement('strong');
    name.textContent = REGIONS[key].short;
    box.append(name, document.createElement('br'), options.submissions(countOf(key)));
    return box;
  }

  function drawRegions() {
    layers.forEach((layer) => layer.remove());
    layers.clear();
    max = Math.max(1, ...GACC_LIST.map((region) => countOf(region.key)));
    for (const feature of boundaries.features) {
      const key = feature.properties.key as RegionKey;
      const layer = L.geoJSON(feature as unknown as GeoJSON.Feature, {
        style: styleOf(key),
        // The tooltip gives each shape focus listeners, which Chromium takes to mean an
        // SVG shape belongs in the Tab order. It doesn't: it's aria-hidden, and Enter
        // on it does nothing (the Section 508 review, 14 November). Set as each shape
        // is drawn, which waits for the map's first view.
        onEachFeature: (_feature, shape) =>
          shape.on('add', () => (shape as L.Path).getElement()?.setAttribute('tabindex', '-1')),
      });
      layer.on('click', () => options.onSelect(state.selected === key ? null : key));
      layer.on('mouseover', () =>
        layer.setStyle(hoveredStyle(key, countOf(key), max, state.theme, state.selected)),
      );
      layer.on('mouseout', () => layer.setStyle(styleOf(key)));
      layer.bindTooltip(tip(key), { direction: 'top', className: 'fh-map__tip' });
      layer.addTo(map);
      layers.set(key, layer);
    }
  }

  function restyleRegions() {
    layers.forEach((layer, key) => layer.setStyle(styleOf(key)));
  }

  /* ---- GACC code labels ----
     Small, and non-interactive so they never swallow a click meant for the region
     underneath. Anchored to the hand-placed centres in the vocabulary rather than to
     polygon centroids: EACC and SACC are shapes whose centroid can land in a
     neighbour. The toggle exists because ten labels on a small map can crowd it, and
     a reader who knows the regions by shape may prefer them off. */
  let labels: L.LayerGroup | null = null;
  function drawCodes() {
    labels?.remove();
    labels = null;
    if (!state.showCodes) return;

    const group = L.layerGroup();
    for (const region of GACC_LIST) {
      if (!region.center) continue;
      const isSelected = state.selected === region.key;
      // An element, which Leaflet appends, rather than a string it would parse as HTML.
      const code = document.createElement('span');
      code.textContent = region.key;
      L.marker(region.center, {
        interactive: false,
        keyboard: false,
        icon: L.divIcon({
          className: `fh-map__code${isSelected ? ' fh-map__code--on' : ''}${
            state.selected && !isSelected ? ' fh-map__code--dim' : ''
          }`,
          html: code,
          iconSize: undefined,
        }),
      }).addTo(group);
    }
    group.addTo(map);
    labels = group;
  }

  /* ---- Fly to the selected region ---- */
  /** Frames a view: flying there, or, at first or for less motion, jumping. */
  function frame(bounds: L.LatLngBoundsExpression, fit: L.FitBoundsOptions, duration: number, jump: boolean) {
    view = { bounds, options: fit };
    if (jump || still()) map.fitBounds(bounds, { ...fit, animate: false });
    else map.flyToBounds(bounds, { ...fit, duration });
  }

  /** Frames the selected region, or the lower 48 for none; `first`, the view the map opens on. */
  function fly(first = false) {
    const { selected } = state;
    const off = selected ? OFF_FRAME[selected] : undefined;
    const layer = selected ? layers.get(selected) : undefined;
    if (off) frame(off, { maxZoom: 6 }, 0.8, first);
    else if (layer) frame(layer.getBounds(), { padding: [24, 24], maxZoom: 6 }, 0.7, first);
    // NATIONAL, INTL and UNKNOWN have nowhere to fly to, so once the map is open it
    // holds still and only the results list beside it changes. Moving the map for them
    // would imply a location that the answer explicitly does not have.
    else if (!selected || first) frame(HOME_VIEW, {}, 0.6, first);
  }

  // The regions and codes wait for the map's first view, which opens where the address
  // points without flying there; the basemap comes last, so its first tiles are that
  // view's, not the lower 48's on the way.
  paintCodes();
  drawRegions();
  drawCodes();
  fly(true);
  drawTiles();

  return {
    update(changes) {
      const before = state;
      state = { ...state, ...changes };
      const changed = (name: keyof MapState) => name in changes && changes[name] !== before[name];
      if (changed('theme')) {
        paintCodes();
        drawTiles();
      }
      if (changed('counts')) drawRegions();
      else if (changed('theme') || changed('selected')) restyleRegions();
      if (changed('selected') || changed('showCodes')) drawCodes();
      if (changed('selected') || changed('counts')) fly();
    },
    remove() {
      observer.disconnect();
      map.remove();
      layers.clear();
    },
  };
}
