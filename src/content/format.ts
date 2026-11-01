/**
 * An ISO date, or a date and time; nothing looser ("tomorrow") counts. Captured in
 * parts so the calendar can be checked.
 */
const ISO_DATE =
  /^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?(Z|[+-]\d{2}:\d{2})?)?$/;

/** Whether a written date and time is on the calendar and the clock. */
function isRealDate(parts: RegExpExecArray): boolean {
  const [year, month, day, hour, minute, second] = parts.slice(1, 7).map((part) => Number(part ?? 0));
  // setUTCFullYear, not Date.UTC, which would read the years 0–99 as 1900–1999.
  const date = new Date(0);
  date.setUTCFullYear(year, month - 1, day);
  return (
    date.getUTCFullYear() === year &&
    date.getUTCMonth() === month - 1 &&
    date.getUTCDate() === day &&
    hour < 24 &&
    minute < 60 &&
    second < 60
  );
}

/**
 * Formats an ISO date for the "Last reviewed" lines on the landing and topic pages.
 *
 * Pinned to UTC on purpose: `topicSummaries.json` carries date-only strings, which
 * `Date` parses as midnight UTC. Rendering those in the viewer's local zone shows
 * the previous day to anyone west of Greenwich — including every NOAA office. A time
 * written without a zone is read in UTC too, where `Date` would read local time.
 * Anything that isn't an ISO date comes back unchanged, and so does one that isn't on
 * the calendar (February 30, month 00), which `Date` would roll over into a date
 * nobody wrote.
 */
export function formatReviewDate(iso: string): string {
  const match = ISO_DATE.exec(iso);
  if (!match || !isRealDate(match)) return iso;
  const date = new Date(iso.includes('T') && !match[7] ? `${iso}Z` : iso);
  if (Number.isNaN(date.getTime())) return iso;
  return date.toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    timeZone: 'UTC',
  });
}

/**
 * Joins an author list into a display byline.
 *
 * Truncates with "et al." past three names — a grid tile has one line for this, and
 * a six-author byline would push the year off the end. The X-ray page passes
 * `max: 0` to show everyone, since that is the project's own page.
 */
export function formatByline(authors: { name: string }[], max = 3): string {
  if (authors.length === 0) return 'Unattributed';

  const names = authors.map((author) => author.name);
  if (max > 0 && names.length > max) {
    return `${names.slice(0, max).join(', ')} et al.`;
  }
  if (names.length === 1) return names[0];
  return `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
}

/**
 * Splits a need into its lead ("Need for X among Y:") and the rest, for display.
 *
 * The survey asks respondents to open every entry with that phrase, so bolding it
 * lets a reader scan a long list by *what* is needed and *by whom*. Nothing is
 * rewritten: `lead + ' ' + rest` is the original text, punctuation included.
 *
 * Returns no lead when the entry doesn't follow the pattern closely enough — e.g. a
 * respondent who left out the colon, so the "lead" would swallow half the paragraph.
 * Bolding a whole paragraph is worse than bolding nothing.
 */
export function splitNeedLead(need: string): { lead?: string; rest: string } {
  const match = need.match(/^(Need (?:for|to)\b.*?[:.])\s+(\S[\s\S]*)$/);
  if (!match || match[1].split(/\s+/).length > 18) return { rest: need };
  return { lead: match[1], rest: match[2] };
}

/**
 * A link: a scheme, then the host (after any user, up to the last "@", and before any
 * port; an IPv6 host in brackets), then the path. Read as written rather than through
 * `URL`, which percent-encodes and normalizes, so a SICI DOI keeps its angle brackets
 * and the PHP port can match it exactly.
 */
const LINK = /^[a-z][a-z0-9+.-]*:\/\/(?:[^/?#]*@)?(\[[^\]/?#]*\]|[^:/?#]*)(?::[^/?#]*)?([^?#]*)/i;

/** "doi.org/10.1175/…" → a readable label for a link that has no published title. */
export function linkLabel(url: string): string {
  const match = LINK.exec(url);
  if (!match || !match[1]) return url;
  // ASCII letters only, as PHP's strtolower() does, so the two can't differ.
  const host = match[1].replace(/[A-Z]+/g, (letters) => letters.toLowerCase());
  return `${host.replace(/^www\./, '')}${match[2].replace(/\/+$/, '')}`;
}
