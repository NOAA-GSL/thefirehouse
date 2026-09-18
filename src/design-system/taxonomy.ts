/**
 * Structural vocabularies that came out of the Qualtrics survey.
 *
 * Same rule as `topics.ts`: these keys are the contract between the survey export,
 * the import script, the URL query string and the UI, so they live in code. Only the
 * human-readable labels are display concerns, and they live here too because —
 * unlike topic areas — there is no CMS record behind them to override.
 *
 * The `FIRE_PHASES` values are the survey's own Q9 choices (export of 2026-09-14).
 * "After" first appeared in the export of 2026-09-18. If the survey gains another
 * phase, the import script warns on it and it gets added to the array below —
 * nothing else moves except `projects.json`.
 */

/* ---------------------------------------------------------------- fire cycle -- */

export type FirePhase = 'before' | 'during' | 'after' | 'long-term';

export interface FirePhaseDefinition {
  key: FirePhase;
  /** Compact label for chips, pills and dense metadata rows — the survey's lead word. */
  short: string;
  /** What the phase covers, from the survey choice text. Used on the X-ray page. */
  label: string;
  /** The survey's examples, where it gives them. */
  description?: string;
}

/**
 * Ordered by where they sit in the fire cycle, not alphabetically — the order is
 * the point. Filter UIs and metadata rows both render straight from this array.
 */
export const FIRE_PHASE_LIST: FirePhaseDefinition[] = [
  {
    key: 'before',
    short: 'Before',
    label: 'Pre-fire planning, prevention, and mitigation',
    description: 'e.g., community wildfire prevention plans, fuel management, communication',
  },
  {
    key: 'during',
    short: 'During',
    label: 'Fire detection, active suppression, and downstream impacts',
    description: 'e.g., tactical/strategic operations, smoke, evacuations',
  },
  {
    key: 'after',
    short: 'After',
    label: 'Postfire recovery and rehabilitation',
  },
  {
    key: 'long-term',
    short: 'Long-term',
    label: 'Planning, adaptation, and policy',
  },
];

export const FIRE_PHASES: Record<FirePhase, FirePhaseDefinition> = Object.fromEntries(
  FIRE_PHASE_LIST.map((phase) => [phase.key, phase]),
) as Record<FirePhase, FirePhaseDefinition>;

export const FIRE_PHASE_KEYS = FIRE_PHASE_LIST.map((phase) => phase.key);

export function isFirePhase(value: unknown): value is FirePhase {
  return typeof value === 'string' && value in FIRE_PHASES;
}

/* --------------------------------------------------------- publication status -- */

export type PublicationStatus = 'published' | 'in-review' | 'in-preparation' | 'unpublished';

export interface PublicationStatusDefinition {
  key: PublicationStatus;
  label: string;
  /**
   * Which status band the badge uses. `neutral` deliberately covers "unpublished":
   * an unpublished project is a normal state in this dataset, not an error, and
   * colouring it red would editorialise about someone's research.
   */
  tone: 'positive' | 'info' | 'neutral';
}

export const PUBLICATION_STATUS_LIST: PublicationStatusDefinition[] = [
  { key: 'published', label: 'Published', tone: 'positive' },
  { key: 'in-review', label: 'In review', tone: 'info' },
  { key: 'in-preparation', label: 'In preparation', tone: 'info' },
  { key: 'unpublished', label: 'Unpublished', tone: 'neutral' },
];

export const PUBLICATION_STATUSES: Record<PublicationStatus, PublicationStatusDefinition> =
  Object.fromEntries(
    PUBLICATION_STATUS_LIST.map((status) => [status.key, status]),
  ) as Record<PublicationStatus, PublicationStatusDefinition>;

export function isPublicationStatus(value: unknown): value is PublicationStatus {
  return typeof value === 'string' && value in PUBLICATION_STATUSES;
}

/* -------------------------------------------------------------- coordination -- */

/**
 * Coordination regions, exactly as specified in `FirehouseFormAdditions.pdf`.
 *
 * These string values ARE the Qualtrics recode values — the export reads `ONCC`,
 * not `Northern California (ONCC)`. That is the spec's first setting, and its
 * reasoning is worth keeping in view: display text gets reworded over time, and if
 * the code travelled with the label then every rewording would silently break the
 * map. So the code is the contract and the label is free to change.
 *
 * The ten GACCs have boundaries on the map. The four values below them do not, and
 * that is the point of having them:
 *
 *  - `PACIFIC` exists because the authoritative NIFC boundary file folds the
 *    Pacific islands into Northern California. Without its own option, Hawaii
 *    research would be filed under ONCC — wrong, and invisibly so.
 *  - `NATIONAL`, `INTL` and `UNKNOWN` exist so that someone whose work doesn't sit
 *    in one region picks the honest answer instead of the nearest wrong one. As the
 *    spec puts it: an answer flagged UNKNOWN is much easier to fix later than one
 *    that is confidently wrong.
 */

/** The ten GACCs — the only regions with boundaries on the map. */
export type GaccKey =
  | 'AICC'
  | 'NWCC'
  | 'ONCC'
  | 'OSCC'
  | 'NRCC'
  | 'GBCC'
  | 'SWCC'
  | 'RMCC'
  | 'EACC'
  | 'SACC';

/** Everything that isn't one of them. */
export type NonGaccRegion = 'PACIFIC' | 'NATIONAL' | 'INTL' | 'UNKNOWN';

export type RegionKey = GaccKey | NonGaccRegion;

export interface RegionDefinition {
  key: RegionKey;
  /** Exactly what the respondent sees in Qualtrics. */
  label: string;
  /** Short form for map labels, badges and dense metadata rows. */
  short: string;
  /**
   * Whether this region has a boundary polygon. `false` regions are real answers
   * that simply cannot be drawn — they are listed beside the map rather than
   * dropped, because a submission count that silently excludes them is wrong.
   */
  mapped: boolean;
  /** [lat, lng] anchor for the heat marker. Only meaningful when `mapped`. */
  center?: [number, number];
}

export const GACC_LIST: RegionDefinition[] = [
  { key: 'AICC', label: 'Alaska (AICC)', short: 'Alaska', mapped: true, center: [64.5, -152.5] },
  { key: 'NWCC', label: 'Northwest — OR, WA (NWCC)', short: 'Northwest', mapped: true, center: [45.6, -120.5] },
  { key: 'ONCC', label: 'Northern California (ONCC)', short: 'N. California', mapped: true, center: [40.2, -121.5] },
  { key: 'OSCC', label: 'Southern California (OSCC)', short: 'S. California', mapped: true, center: [34.6, -118.3] },
  { key: 'NRCC', label: 'Northern Rockies — MT, N. ID, ND (NRCC)', short: 'N. Rockies', mapped: true, center: [46.9, -108.5] },
  { key: 'GBCC', label: 'Great Basin — UT, NV, S. ID (GBCC)', short: 'Great Basin', mapped: true, center: [40.2, -114.5] },
  { key: 'SWCC', label: 'Southwest — AZ, NM (SWCC)', short: 'Southwest', mapped: true, center: [34.2, -108.5] },
  { key: 'RMCC', label: 'Rocky Mountain — CO, WY, SD, NE, KS (RMCC)', short: 'Rocky Mountain', mapped: true, center: [41.5, -102.5] },
  { key: 'EACC', label: 'Eastern Area (EACC)', short: 'Eastern Area', mapped: true, center: [42.5, -84.0] },
  { key: 'SACC', label: 'Southern Area — incl. PR & USVI (SACC)', short: 'Southern Area', mapped: true, center: [33.0, -88.0] },
];

/**
 * The four non-GACC answers.
 *
 * `PACIFIC` carries a centre because it can honestly be pinned to a place even
 * though the boundary file has no polygon for it. The other three cannot be pinned
 * anywhere at all without inventing a location, so they have none and are rendered
 * only in the list beside the map.
 */
export const NON_GACC_LIST: RegionDefinition[] = [
  { key: 'PACIFIC', label: 'Hawaii / Pacific Islands', short: 'Hawaii / Pacific', mapped: false, center: [20.8, -156.9] },
  { key: 'NATIONAL', label: 'National / not region-specific', short: 'National', mapped: false },
  { key: 'INTL', label: 'Outside the U.S.', short: 'International', mapped: false },
  { key: 'UNKNOWN', label: 'Not sure', short: 'Not sure', mapped: false },
];

export const REGION_LIST: RegionDefinition[] = [...GACC_LIST, ...NON_GACC_LIST];

export const REGIONS: Record<RegionKey, RegionDefinition> = Object.fromEntries(
  REGION_LIST.map((region) => [region.key, region]),
) as Record<RegionKey, RegionDefinition>;

/** Kept as the historical name — the ten GACCs specifically. */
export const GACCS = Object.fromEntries(
  GACC_LIST.map((region) => [region.key, region]),
) as Record<GaccKey, RegionDefinition>;

export const GACC_KEYS = GACC_LIST.map((region) => region.key) as GaccKey[];
export const REGION_KEYS = REGION_LIST.map((region) => region.key);

export function isGaccKey(value: unknown): value is GaccKey {
  return typeof value === 'string' && (GACC_KEYS as string[]).includes(value);
}

export function isRegionKey(value: unknown): value is RegionKey {
  return typeof value === 'string' && value in REGIONS;
}
