import vocabulary from '../../wp-content/plugins/firehouse/shared/vocabulary.json' with { type: 'json' };

/**
 * Structural vocabularies that came out of the survey.
 *
 * The values live in `wp-content/plugins/firehouse/shared/vocabulary.json`, which the
 * WordPress plugin reads too, so the two can never disagree about a key. The types
 * stay here, and `vocabulary.test.ts` checks that the file and the unions match.
 *
 * These keys are the contract between the survey export, the import script, the URL
 * query string and the UI. Only the labels are display concerns. Each option also
 * carries `formLabel`, exactly as the Google Form shows it, for reading its exports.
 *
 * If the survey gains another fire phase, the import script warns on it and it gets
 * added to the JSON file and the union below — nothing else moves except
 * `projects.json`.
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
  /** The option exactly as the Google Form shows it. */
  formLabel: string;
}

/**
 * Ordered by where they sit in the fire cycle, not alphabetically — the order is
 * the point. Filter UIs and metadata rows both render straight from this array.
 */
export const FIRE_PHASE_LIST = vocabulary.firePhases as FirePhaseDefinition[];

export const FIRE_PHASES: Record<FirePhase, FirePhaseDefinition> = Object.fromEntries(
  FIRE_PHASE_LIST.map((phase) => [phase.key, phase]),
) as Record<FirePhase, FirePhaseDefinition>;

export const FIRE_PHASE_KEYS = FIRE_PHASE_LIST.map((phase) => phase.key);

export function isFirePhase(value: unknown): value is FirePhase {
  return typeof value === 'string' && Object.hasOwn(FIRE_PHASES, value);
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

export const PUBLICATION_STATUS_LIST =
  vocabulary.publicationStatuses as PublicationStatusDefinition[];

export const PUBLICATION_STATUSES: Record<PublicationStatus, PublicationStatusDefinition> =
  Object.fromEntries(
    PUBLICATION_STATUS_LIST.map((status) => [status.key, status]),
  ) as Record<PublicationStatus, PublicationStatusDefinition>;

export function isPublicationStatus(value: unknown): value is PublicationStatus {
  return typeof value === 'string' && Object.hasOwn(PUBLICATION_STATUSES, value);
}

/* -------------------------------------------------------------- coordination -- */

/**
 * Coordination regions, exactly as specified in `FirehouseFormAdditions.pdf`.
 *
 * The keys are the codes, never the labels. Display text gets reworded over time,
 * and if the code travelled with the label then every rewording would silently break
 * the map. So the code is the contract and the label is free to change. The ten GACC
 * labels on the Google Form end in their code, which is how an export is read.
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
  /** The site's label for the region. */
  label: string;
  /** Short form for map labels, badges and dense metadata rows. */
  short: string;
  /** The option exactly as the Google Form shows it. */
  formLabel: string;
  /**
   * Whether this region has a boundary polygon. `false` regions are real answers
   * that simply cannot be drawn — they are listed beside the map rather than
   * dropped, because a submission count that silently excludes them is wrong.
   */
  mapped: boolean;
  /**
   * [lat, lng] anchor for the heat marker. `PACIFIC` has one although it has no
   * boundary, because it can honestly be pinned to a place; NATIONAL, INTL and
   * UNKNOWN cannot be pinned anywhere without inventing a location, so they have
   * none and are rendered only in the list beside the map.
   */
  center?: [number, number];
}

export const REGION_LIST = vocabulary.regions as RegionDefinition[];

export const GACC_LIST: RegionDefinition[] = REGION_LIST.filter((region) => region.mapped);

/** The four non-GACC answers. */
export const NON_GACC_LIST: RegionDefinition[] = REGION_LIST.filter((region) => !region.mapped);

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
  return typeof value === 'string' && Object.hasOwn(REGIONS, value);
}
