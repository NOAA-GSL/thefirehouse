import { useEffect, useId, useLayoutEffect, useMemo, useRef, useState } from 'react';
import 'leaflet/dist/leaflet.css';
import { heat } from './heat';
import { Icon } from './Icon';
import { BASEMAP, drawMap, type MapCanvas } from './mapCanvas';
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
 * The map itself is drawn by `mapCanvas.ts`, which the plugin's view script draws from
 * too; this component holds the selection and renders everything around the map: the
 * labels toggle, the credit, the region list and the selected region's projects.
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

/** "4 projects in": what comes before the region's name, in the results' heading and
    the status line alike. */
const projectsIn = (count: number) => `${count} ${count === 1 ? 'project' : 'projects'} in`;

export function RegionMap({
  counts,
  projects,
  selected,
  onSelect,
  projectHref,
  theme,
}: RegionMapProps) {
  const hostRef = useRef<HTMLDivElement>(null);
  const canvasRef = useRef<MapCanvas | null>(null);
  const onSelectRef = useRef(onSelect);
  const allRef = useRef<HTMLButtonElement>(null);
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

  /**
   * What the status line says: how many projects the region just chosen holds, and
   * nothing else. A live region around the results read the whole list out on every
   * change. It stays empty until the selection changes, since what the page opened
   * with isn't news, and empties again when none is selected, which the button used
   * says itself: Clear leaves focus on "All submissions", now pressed, and a region's
   * own button, pressed again, is no longer pressed.
   */
  const [announced, setAnnounced] = useState({ selected, text: '' });
  if (announced.selected !== selected) {
    setAnnounced({
      selected,
      text: selected ? `${projectsIn(shown.length)} ${REGIONS[selected].short}` : '',
    });
  }

  /* Clear goes once none is selected, so focus moves to "All submissions", then the
     pressed one, rather than falling back to the top of the page. The router applies
     a choice in a transition, a moment after the click, so focus moves when that
     commits, in the same task that removes Clear: moved any sooner, it would land on
     a button still saying it isn't pressed. A choice made before the commit cancels
     the move. */
  const focusAllOnClear = useRef(false);
  const clear = () => {
    focusAllOnClear.current = true;
    onSelect(null);
  };
  useLayoutEffect(() => {
    if (focusAllOnClear.current && selected === null) allRef.current?.focus();
    focusAllOnClear.current = false;
  }, [selected]);

  /* ---- The map: drawn once, then told what changed ---- */
  const initial = useRef({ counts, theme, selected, showCodes });
  useEffect(() => {
    if (!hostRef.current) return;
    const canvas = drawMap(hostRef.current, {
      ...initial.current,
      submissions: (count) => `${count} ${count === 1 ? 'submission' : 'submissions'}`,
      onSelect: (key) => onSelectRef.current(key),
      onTiles: setTilesFailed,
    });
    canvasRef.current = canvas;
    return () => {
      canvas.remove();
      canvasRef.current = null;
    };
  }, []);

  useEffect(() => {
    canvasRef.current?.update({ counts, theme, selected, showCodes });
  }, [counts, theme, selected, showCodes]);

  const label = (key: RegionKey) => REGIONS[key];

  return (
    <div className="fh-map">
      <div className="fh-map__stage">
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
        Boundaries: NIFC National GACC Boundaries. Basemap: {BASEMAP.attribution}.
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
              ref={allRef}
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

      {/* ---- Projects in the selected region ----
          A heading to find them by, and no live region around them: the status line
          at the end announces what changed. */}
      <div className="fh-map__results">
        {selected ? (
          <>
            <div className="fh-map__results-head">
              <Icon name="map-pin" size={14} />
              <h3 className="fh-map__results-title">
                {projectsIn(shown.length)} <strong>{label(selected).short}</strong>
              </h3>
              <button type="button" className="fh-map__clear" onClick={clear}>
                Clear
              </button>
            </div>
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
        <p className="fh-visually-hidden" role="status">
          {announced.text}
        </p>
      </div>
    </div>
  );
}
