import { describe, expect, expectTypeOf, it } from 'vitest';
import vocabulary from '../../wp-content/plugins/firehouse/shared/vocabulary.json' with { type: 'json' };
import { isIconName } from './Icon';
import type { FirePhase, GaccKey, NonGaccRegion, PublicationStatus } from './taxonomy';
import type { TopicKey } from './topics';

/**
 * `shared/vocabulary.json` holds the values and `taxonomy.ts`/`topics.ts` the types.
 * These lists are typed against the unions, so leaving a key out (or adding one the
 * union lacks) fails the type check, and the tests then hold the file to the lists.
 */
const FIRE_PHASES = ['before', 'during', 'after', 'long-term'] as const satisfies readonly FirePhase[];
const PUBLICATION_STATUSES = ['published', 'in-review', 'in-preparation', 'unpublished'] as const satisfies readonly PublicationStatus[];
const GACCS = ['AICC', 'NWCC', 'ONCC', 'OSCC', 'NRCC', 'GBCC', 'SWCC', 'RMCC', 'EACC', 'SACC'] as const satisfies readonly GaccKey[];
const NON_GACCS = ['PACIFIC', 'NATIONAL', 'INTL', 'UNKNOWN'] as const satisfies readonly NonGaccRegion[];
const TOPICS = ['observe', 'forecast', 'warning', 'governance'] as const satisfies readonly TopicKey[];

expectTypeOf<Exclude<FirePhase, (typeof FIRE_PHASES)[number]>>().toBeNever();
expectTypeOf<Exclude<PublicationStatus, (typeof PUBLICATION_STATUSES)[number]>>().toBeNever();
expectTypeOf<Exclude<GaccKey, (typeof GACCS)[number]>>().toBeNever();
expectTypeOf<Exclude<NonGaccRegion, (typeof NON_GACCS)[number]>>().toBeNever();
expectTypeOf<Exclude<TopicKey, (typeof TOPICS)[number]>>().toBeNever();

const keys = (list: { key: string }[]) => list.map((item) => item.key);

describe('shared/vocabulary.json', () => {
  it('has the fire phases, in fire-cycle order', () => {
    expect(keys(vocabulary.firePhases)).toEqual(FIRE_PHASES);
  });

  it('has the publication statuses', () => {
    expect(keys(vocabulary.publicationStatuses)).toEqual(PUBLICATION_STATUSES);
  });

  it('has the ten GACCs, then the four other answers', () => {
    expect(keys(vocabulary.regions)).toEqual([...GACCS, ...NON_GACCS]);
  });

  it('maps the GACCs and only the GACCs', () => {
    for (const region of vocabulary.regions) {
      expect(region.mapped, region.key).toBe((GACCS as readonly string[]).includes(region.key));
    }
  });

  it('pins every mapped region, and Pacific, to a place, and nothing else', () => {
    for (const region of vocabulary.regions) {
      const pinned = region.mapped || region.key === 'PACIFIC';
      expect('center' in region, region.key).toBe(pinned);
    }
  });

  it('gives every GACC a Google Form label that ends in its code', () => {
    for (const region of vocabulary.regions.filter((r) => r.mapped)) {
      expect(region.formLabel, region.key).toMatch(new RegExp(`\\(${region.key}\\)$`));
    }
  });

  it('gives every fire phase a Google Form label that starts with its key', () => {
    for (const phase of vocabulary.firePhases) {
      expect(phase.formLabel.split(/\s*\(/)[0].toLowerCase(), phase.key).toBe(phase.key);
    }
  });

  it('has no two options with one Google Form label', () => {
    const labels = [...vocabulary.firePhases, ...vocabulary.regions].map((item) => item.formLabel);
    expect(new Set(labels).size).toBe(labels.length);
  });

  it('has the four topics, each with a known icon', () => {
    expect(keys(vocabulary.topics)).toEqual(TOPICS);
    for (const topic of vocabulary.topics) expect(isIconName(topic.icon), topic.key).toBe(true);
  });
});
