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
