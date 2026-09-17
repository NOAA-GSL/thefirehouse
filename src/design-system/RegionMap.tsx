import { useEffect, useId, useMemo, useRef, useState, type CSSProperties } from 'react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import boundaries from '../content/data/gaccBoundaries.json';
import { Icon } from './Icon';
import {
  GACC_LIST,
  NON_GACC_LIST,
  REGIONS,
  type RegionKey,
} from './taxonomy';
import './RegionMap.css';

export interface RegionMapProject {
  slug: string;
  title: string;
  year: number | string;
  /**
   * Regions the project counts toward, already expanded by the caller — a national
   * study arrives listing every GACC (see `effectiveRegions` in content/derive).
   */
  regions: RegionKey[];
  /** A nationally representative study; labelled as such in a GACC's list. */
  national?: boolean;
}

export interface RegionMapProps {
  counts: Record<RegionKey, number>;
  projects: RegionMapProject[];
  /** Selected region, or null for "all". Controlled so the page owns the URL. */
  selected: RegionKey | null;
  onSelect: (key: RegionKey | null) => void;
  /** Builds the href for a project. */
  projectHref: (slug: string) => string;
  theme: 'light' | 'dark';
}

/**
 * Coverage map — where the research comes from.
 *
 * Boundaries are the authoritative NIFC file (`National_GACC_Boundaries`, served
 * from NIFC's ArcGIS instance), simplified and committed to the repo rather than
 * fetched at runtime. Committing it means the map still draws when the network is
 * hostile — a conference floor, or a federal network that blocks the host outright.
 * The basemap tiles are the only live dependency, and the map degrades to
 * boundaries-on-a-blank-canvas if they fail rather than showing nothing.
 *
 * ---------------------------------------------------------------------------
 * Accessibility
 * ---------------------------------------------------------------------------
 * A canvas of coloured polygons is not, on its own, usable by a keyboard or a
 * screen reader — and this is a federal site, so "add a table" isn't a nicety.
 *
 * The list beside the map is not a fallback bolted on afterwards. It is the same
 * data, always rendered, always interactive, and it is what a keyboard user
 * actually operates: every region is a real button with a real count, selecting one
 * drives the same state the map polygons do, and the whole thing works with the map
 * scrolled out of view or failing to load. The Leaflet canvas is marked
 * `aria-hidden` precisely so assistive tech is sent to the list instead of into a
 * pile of unlabelled SVG paths.
 *
 * Colour is never the only channel: every region carries its count as a number, and
 * selection is conveyed by the list's `aria-pressed` as well as by the highlight.
 */

/* Esri's free basemaps. Attribution is required by their terms and is rendered
   below the map — do not remove it. */
const BASEMAPS = {
  dark: {
    url: 'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Dark_Gray_Base/MapServer/tile/{z}/{y}/{x}',
    attribution: 'Esri, HERE, Garmin, © OpenStreetMap contributors',
  },
  light: {
    url: 'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Light_Gray_Base/MapServer/tile/{z}/{y}/{x}',
    attribution: 'Esri, HERE, Garmin, © OpenStreetMap contributors',
  },
};

/**
 * Default view: the lower 48.
 *
 * Framing Alaska, Hawaii and CONUS at once is the obvious first instinct and it is
 * wrong — Web Mercator has to zoom out to most of the Pacific to hold all three,
 * and the result is a map where every mainland region is a few pixels across. The
 * default therefore shows the area most submissions are in, and selecting Alaska or
 * the Pacific flies there (see `flyToRegion`), which is also a better answer than a
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

/**
 * Sequential blue ramp for a choropleth over a *small* count range.
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
const RAMPS = {
  dark: {
    stops: ['#3d6fb0', '#4f93d9', '#7fbaf2', '#c4e2ff'],
    empty: { fill: '#6e85af', stroke: '#8fa3c4' },
    selected: '#ffffff',
    label: { text: '#f5f7fb', halo: 'rgba(10, 16, 32, 0.85)' },
  },
  light: {
    stops: ['#8fb8e6', '#4f8fd6', '#2563b8', '#0b3d86'],
    empty: { fill: '#9caece', stroke: '#6e85af' },
    selected: '#0a1020',
    label: { text: '#0a1020', halo: 'rgba(255, 255, 255, 0.9)' },
  },
} as const;

type MapTheme = keyof typeof RAMPS;

function mix(a: string, b: string, t: number): string {
  const pa = [1, 3, 5].map((o) => parseInt(a.slice(o, o + 2), 16));
  const pb = [1, 3, 5].map((o) => parseInt(b.slice(o, o + 2), 16));
  const out = pa.map((v, k) => Math.round(v + (pb[k] - v) * t));
  return `#${out.map((v) => v.toString(16).padStart(2, '0')).join('')}`;
}

function heat(
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

export function RegionMap({
  counts,
  projects,
  selected,
  onSelect,
  projectHref,
  theme,
}: RegionMapProps) {
  const hostRef = useRef<HTMLDivElement>(null);
  const mapRef = useRef<L.Map | null>(null);
  const tilesRef = useRef<L.TileLayer | null>(null);
  const layersRef = useRef<Map<RegionKey, L.Path>>(new Map());
  const labelsRef = useRef<L.LayerGroup | null>(null);
  /**
   * The view the map should currently be showing.
   *
   * Held in a ref because the ResizeObserver below has to restore *this*, not the
   * home view. Without it, arriving on `?region=GBCC` zoomed to the Great Basin and
   * was then immediately yanked back out to the whole country when the observer
   * fired on first layout — a deep link that visibly undid itself.
   */
  const viewRef = useRef<L.LatLngBoundsExpression>(HOME_VIEW);
  const onSelectRef = useRef(onSelect);
  const [tilesFailed, setTilesFailed] = useState(false);
  const [showCodes, setShowCodes] = useState(true);
  const listId = useId();

  onSelectRef.current = onSelect;

  const max = useMemo(
    () => Math.max(1, ...GACC_LIST.map((r) => counts[r.key] ?? 0)),
    [counts],
  );

  const total = useMemo(
    () => projects.filter((p) => p.regions.length > 0).length,
    [projects],
  );

  const nationalCount = useMemo(() => projects.filter((p) => p.national).length, [projects]);

  const shown = useMemo(
    () => (selected ? projects.filter((p) => p.regions.includes(selected)) : []),
    [projects, selected],
  );

  /* ---- Create the map once ---- */
  useEffect(() => {
    if (!hostRef.current || mapRef.current) return;

    const map = L.map(hostRef.current, {
      zoomControl: false,
      attributionControl: false,
      // Never hijack page scroll. The map sits in the hero, so a wheel-zoom here
      // would trap someone trying to scroll past it — a classic way to make a
      // landing page feel broken.
      scrollWheelZoom: false,
      doubleClickZoom: false,
      // Leaflet's own keyboard handling would put the canvas in the tab order as a
      // single opaque stop. The region list is the operable surface instead.
      keyboard: false,
    });

    map.fitBounds(HOME_VIEW);
    map.setMaxBounds(MAX_VIEW);
    L.control.zoom({ position: 'bottomright' }).addTo(map);
    mapRef.current = map;

    // Leaflet measures its container once, at construction. In a CSS grid inside a
    // glass panel inside a hero, the container has no final width at that moment —
    // so the map computes a zero-size viewport, requests a handful of zoom-2 tiles
    // and renders a sliver. It looks exactly like "tiles failed to load", which is
    // the wrong thing to go debugging.
    //
    // A ResizeObserver fixes it for every case at once: first layout, the hero's
    // 1100px breakpoint, a window drag, and the panel reflowing when the region
    // list wraps. `fitBounds` is re-applied because invalidateSize alone keeps the
    // stale centre and leaves Alaska cropped.
    const observer = new ResizeObserver(() => {
      map.invalidateSize({ animate: false });
      map.fitBounds(viewRef.current);
    });
    observer.observe(hostRef.current);

    return () => {
      observer.disconnect();
      map.remove();
      mapRef.current = null;
      layersRef.current.clear();
    };
  }, []);

  /* ---- Basemap, swapped with the theme ---- */
  useEffect(() => {
    const map = mapRef.current;
    if (!map) return;

    tilesRef.current?.remove();
    const config = BASEMAPS[theme];
    const layer = L.tileLayer(config.url, { maxZoom: 8, minZoom: 2 });
    layer.on('tileerror', () => setTilesFailed(true));
    layer.on('tileload', () => setTilesFailed(false));
    layer.addTo(map);
    tilesRef.current = layer;
  }, [theme]);

  /* ---- Region polygons ---- */
  useEffect(() => {
    const map = mapRef.current;
    if (!map) return;

    layersRef.current.forEach((layer) => layer.remove());
    layersRef.current.clear();

    for (const feature of boundaries.features) {
      const key = feature.properties.key as RegionKey;
      const count = counts[key] ?? 0;
      const { fill, stroke, alpha } = heat(count, max, theme);

      const layer = L.geoJSON(feature as unknown as GeoJSON.Feature, {
        style: {
          color: stroke,
          weight: 1.2,
          opacity: 0.9,
          fillColor: fill,
          fillOpacity: alpha,
        },
      });

      layer.on('click', () => onSelectRef.current(selected === key ? null : key));
      layer.on('mouseover', () => layer.setStyle({ weight: 3, fillOpacity: alpha + 0.2 }));
      layer.on('mouseout', () => layer.setStyle({ weight: 1.2, fillOpacity: alpha }));
      layer.bindTooltip(
        `<strong>${REGIONS[key].short}</strong><br>${count} ${count === 1 ? 'submission' : 'submissions'}`,
        { direction: 'top', className: 'fh-map__tip' },
      );
      layer.addTo(map);
      layersRef.current.set(key, layer as unknown as L.Path);
    }
  }, [counts, max, selected, theme]);

  /* ---- GACC code labels ----
     Small, and non-interactive so they never swallow a click meant for the region
     underneath. Anchored to the hand-placed centres in the taxonomy rather than
     to polygon centroids: EACC and SACC are shapes whose centroid can land in a
     neighbour. The toggle exists because ten labels on a small map can crowd it,
     and a reader who knows the regions by shape may prefer them off. */
  useEffect(() => {
    const map = mapRef.current;
    if (!map) return;

    labelsRef.current?.remove();
    labelsRef.current = null;
    if (!showCodes) return;

    const group = L.layerGroup();
    for (const region of GACC_LIST) {
      if (!region.center) continue;
      const isSelected = selected === region.key;
      L.marker(region.center, {
        interactive: false,
        keyboard: false,
        icon: L.divIcon({
          className: `fh-map__code${isSelected ? ' fh-map__code--on' : ''}${
            selected && !isSelected ? ' fh-map__code--dim' : ''
          }`,
          html: region.key,
          iconSize: undefined,
        }),
      }).addTo(group);
    }
    group.addTo(map);
    labelsRef.current = group;
  }, [showCodes, selected]);

  /* ---- Fly to the selected region ---- */
  useEffect(() => {
    const map = mapRef.current;
    if (!map) return;

    if (!selected) {
      viewRef.current = HOME_VIEW;
      map.flyToBounds(HOME_VIEW, { duration: 0.6 });
      return;
    }

    const off = OFF_FRAME[selected];
    if (off) {
      viewRef.current = off;
      map.flyToBounds(off, { duration: 0.8, maxZoom: 6 });
      return;
    }

    const layer = layersRef.current.get(selected);
    if (layer) {
      const bounds = (layer as unknown as L.GeoJSON).getBounds();
      viewRef.current = bounds;
      map.flyToBounds(bounds, { duration: 0.7, padding: [24, 24], maxZoom: 6 });
    }
    // NATIONAL, INTL and UNKNOWN have nowhere to fly to, so the map holds still and
    // only the results list below it changes. Moving the map for them would imply a
    // location that the answer explicitly does not have.
  }, [selected, counts]);

  /* ---- Selection highlight ---- */
  useEffect(() => {
    layersRef.current.forEach((layer, key) => {
      const count = counts[key] ?? 0;
      const { fill, stroke, alpha } = heat(count, max, theme);
      const isSelected = selected === key;
      (layer as unknown as L.GeoJSON).setStyle({
        color: isSelected ? RAMPS[theme].selected : stroke,
        weight: isSelected ? 3.5 : 1.2,
        fillColor: fill,
        // Dim the unselected regions rather than only brightening the selected one:
        // at these counts a single highlight is easy to miss.
        fillOpacity: selected && !isSelected ? alpha * 0.35 : alpha,
      });
    });
  }, [selected, counts, max, theme]);

  const label = (key: RegionKey) => REGIONS[key];

  return (
    <div className="fh-map">
      <div
        className="fh-map__stage"
        style={
          {
            '--map-label': RAMPS[theme].label.text,
            '--map-label-halo': RAMPS[theme].label.halo,
          } as CSSProperties
        }
      >
        {/* aria-hidden: assistive technology is sent to the region list below, which
            carries the same data in an operable form. */}
        <div ref={hostRef} className="fh-map__canvas" aria-hidden="true" />
        <div className="fh-map__scan" aria-hidden="true" />
        {/* A standard toggle: fixed label, state in aria-pressed. It only affects
            what is drawn — the codes are always in the list beside the map. */}
        <button
          type="button"
          className="fh-map__codes-toggle"
          aria-pressed={showCodes}
          onClick={() => setShowCodes((on) => !on)}
        >
          <span className="fh-map__codes-box" aria-hidden="true" />
          GACC labels
        </button>
        {tilesFailed && (
          <p className="fh-map__offline" role="status">
            Basemap unavailable — region boundaries and counts are still shown.
          </p>
        )}
      </div>

      <p className="fh-map__credit">
        Boundaries: NIFC National GACC Boundaries. Basemap: {BASEMAPS[theme].attribution}.
      </p>

      <div className="fh-map__side">
        <div className="fh-map__side-head">
          <h3 className="fh-map__side-title" id={listId}>
            Coverage by Geographic Area Coordination Center
          </h3>
          <p className="fh-map__side-total">
            {total} {total === 1 ? 'submission' : 'submissions'}
          </p>
        </div>

        {nationalCount > 0 && (
          <p className="fh-map__note">
            GACC counts include {nationalCount} national{' '}
            {nationalCount === 1 ? 'study' : 'studies'}, counted once in every area.
          </p>
        )}

        {/* The operable surface. Buttons, not decoration — this is how the map is
            used without a mouse, and how it is read by a screen reader. */}
        <ul className="fh-map__list" aria-labelledby={listId}>
          <li>
            <button
              type="button"
              className={`fh-map__region${selected === null ? ' fh-map__region--on' : ''}`}
              aria-pressed={selected === null}
              onClick={() => onSelect(null)}
            >
              <span className="fh-map__region-name">All submissions</span>
              <span className="fh-map__region-count">{total}</span>
            </button>
          </li>

          {GACC_LIST.map((region) => {
            const count = counts[region.key] ?? 0;
            const { fill } = heat(count, max, theme);
            return (
              <li key={region.key}>
                <button
                  type="button"
                  className={`fh-map__region${selected === region.key ? ' fh-map__region--on' : ''}`}
                  aria-pressed={selected === region.key}
                  onClick={() => onSelect(selected === region.key ? null : region.key)}
                >
                  <span
                    className="fh-map__swatch"
                    aria-hidden="true"
                    style={{ background: count > 0 ? fill : 'transparent' }}
                  />
                  <span className="fh-map__region-name">
                    {region.short}
                    <span className="fh-map__region-code">{region.key}</span>
                  </span>
                  <span className="fh-map__region-count">{count}</span>
                </button>
              </li>
            );
          })}
        </ul>

        {/* The four answers with no boundary. Listed, not hidden — a total that
            silently drops them under-reports what was actually submitted. */}
        <div className="fh-map__offmap">
          <h4 className="fh-map__offmap-title">Not on the map</h4>
          <ul className="fh-map__list">
            {NON_GACC_LIST.map((region) => {
              const count = counts[region.key] ?? 0;
              return (
                <li key={region.key}>
                  <button
                    type="button"
                    className={`fh-map__region fh-map__region--sm${selected === region.key ? ' fh-map__region--on' : ''}`}
                    aria-pressed={selected === region.key}
                    onClick={() => onSelect(selected === region.key ? null : region.key)}
                  >
                    <span className="fh-map__region-name">{region.short}</span>
                    <span className="fh-map__region-count">{count}</span>
                  </button>
                </li>
              );
            })}
          </ul>
        </div>
      </div>

      {/* ---- Projects in the selected region ---- */}
      <div className="fh-map__results" role="region" aria-live="polite">
        {selected ? (
          <>
            <p className="fh-map__results-head">
              <Icon name="map-pin" size={14} />
              {shown.length} {shown.length === 1 ? 'project' : 'projects'} in{' '}
              <strong>{label(selected).short}</strong>
              <button type="button" className="fh-map__clear" onClick={() => onSelect(null)}>
                Clear
              </button>
            </p>
            {shown.length > 0 ? (
              <ul className="fh-map__projects">
                {shown.map((project) => (
                  <li key={project.slug}>
                    <a className="fh-map__project" href={projectHref(project.slug)}>
                      <span>{project.title}</span>
                      {project.national && selected !== 'NATIONAL' && (
                        <span className="fh-map__project-tag">National</span>
                      )}
                      <span className="fh-map__project-year">{project.year}</span>
                      <Icon name="arrow-right" size={14} />
                    </a>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="fh-map__empty">
                No published research is filed under this region yet.
              </p>
            )}
          </>
        ) : (
          <p className="fh-map__hint">
            Select a GACC on the map or in the list to see the research filed there.
          </p>
        )}
      </div>
    </div>
  );
}
