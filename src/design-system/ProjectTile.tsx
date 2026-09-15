import type { CSSProperties } from 'react';
import { Link } from 'react-router-dom';
import { Icon } from './Icon';
import './ProjectTile.css';

export interface ProjectTileProps {
  /** Short label above the title, e.g. the project type ("Testbed Evaluation"). */
  kicker?: string;
  title: string;
  summary: string;
  /** Pre-formatted byline — the tile shouldn't know how an author list is joined. */
  byline: string;
  year: number | string;
  /** Where the tile navigates, e.g. `/projects/red-flag-thresholds`. */
  to: string;
  /** Compact fire-cycle-phase labels, rendered in the footer meta row. */
  phases?: string[];
  /** Opens the quick-look preview. Omit to hide the control entirely. */
  onPreview?: () => void;
  style?: CSSProperties;
}

/**
 * Tile for the project explorer grid.
 *
 * It is a LINK, not a button. It used to open a modal, which meant no URL, no
 * middle-click, no "open in new tab", no right-click-copy-address and nothing to
 * cite — all of which are things people actually do with a list of research, and
 * all of which a <button> silently swallows.
 *
 * The link sits on the title and is stretched over the whole tile by CSS. A tile-
 * wide <a> would also work, but its accessible name would be the tag, the title,
 * the summary, the byline and every phase run together; this way the click target
 * is the whole card while the name stays the project title.
 *
 * `onPreview` adds a quick-look control for scanning without leaving the grid. It
 * has to sit *above* the stretched link (see the CSS) or the link would swallow
 * its clicks — the standard trap with this pattern.
 */
export function ProjectTile({
  kicker,
  title,
  summary,
  byline,
  year,
  to,
  phases = [],
  onPreview,
  style,
}: ProjectTileProps) {
  return (
    <article className="fh-tile" style={style}>
      <span className="fh-tile__glow" aria-hidden="true" />

      <span className="fh-tile__body">
        {kicker && <span className="fh-tile__kicker">{kicker}</span>}
        <h3 className="fh-tile__title">
          <Link className="fh-tile__link" to={to}>
            {title}
          </Link>
        </h3>
        <p className="fh-tile__summary">{summary}</p>
      </span>

      <span className="fh-tile__meta">
        <span className="fh-tile__byline">
          {byline} · {year}
        </span>
        <Icon name="arrow-up-right" size={16} />
      </span>

      {(phases.length > 0 || onPreview) && (
        <span className="fh-tile__foot">
          <span className="fh-tile__phases">
            {phases.map((phase) => (
              <span key={phase} className="fh-tile__phase">
                {phase}
              </span>
            ))}
          </span>

          {onPreview && (
            <button type="button" className="fh-tile__peek" onClick={onPreview}>
              <Icon name="eye" size={14} />
              Quick look
              {/* The visible label is fine in context, but a screen reader user
                  tabbing through twelve of these hears "Quick look" twelve times
                  with nothing to tell them apart. */}
              <span className="fh-visually-hidden">at {title}</span>
            </button>
          )}
        </span>
      )}
    </article>
  );
}
