/**
 * Formats an ISO date for the "Last reviewed" lines on the landing and topic pages.
 *
 * Pinned to UTC on purpose: `topicSummaries.json` carries date-only strings, which
 * `Date` parses as midnight UTC. Rendering those in the viewer's local zone shows
 * the previous day to anyone west of Greenwich — including every NOAA office.
 */
export function formatReviewDate(iso: string): string {
  return new Date(iso).toLocaleDateString('en-US', {
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
 * `max: 0` to show everyone, since that is the page people cite from.
 *
 * Note this is the *display* byline, not the citation byline: APA has its own
 * ampersand-and-initials rules and an entirely different truncation threshold, so it
 * gets its own builder rather than trying to make this one serve both.
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
