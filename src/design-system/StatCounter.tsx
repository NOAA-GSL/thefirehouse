import type { CSSProperties } from 'react';
import './StatCounter.css';

export interface StatCounterProps {
  value: string | number;
  label: string;
  caption?: string;
  accent?: boolean;
  style?: CSSProperties;
}

/**
 * Large numeral for the hub's live stats, with label and optional caption.
 *
 * Values are read straight from the content layer rather than typed in, so the
 * count updates itself as projects are added in the CMS (creative brief §5.1:
 * "live, auto-updating stats"). See `content/derive.ts`.
 */
export function StatCounter({ value, label, caption, accent = false, style }: StatCounterProps) {
  return (
    <div className="fh-stat" style={style}>
      <span className={`fh-stat__value${accent ? ' fh-stat__value--accent' : ''}`}>{value}</span>
      <span className="fh-stat__label">{label}</span>
      {caption && <span className="fh-stat__caption">{caption}</span>}
    </div>
  );
}
