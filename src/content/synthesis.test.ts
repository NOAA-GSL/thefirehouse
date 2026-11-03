import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import type { TopicSummary } from './types';

/**
 * The site labels each need's count "Project count", so a count must equal the
 * projects the need names. The 17 September draft counted survey entries instead, and
 * four of its needs come out higher. They are pinned here so no new mismatch slips in
 * beside them; when the reviewed rerun replaces the draft, this test fails until the
 * list is emptied.
 */
const DRAFT_2026_09_17 = [
  'Hotspot alerts that reach field partners by text when internet is unreliable',
  'Consistent Red Flag Warning criteria and timing across neighboring offices',
  'Integrated Warning Team frameworks with clear, locally scalable roles',
  'Staffing capacity and organizational buy-in to sustain interagency teams',
];

const summaries = JSON.parse(
  readFileSync(fileURLToPath(new URL('./data/topicSummaries.json', import.meta.url)), 'utf8'),
) as TopicSummary[];

describe('topicSummaries.json', () => {
  it('gives each need a project count equal to the projects it names', () => {
    const mismatched = summaries
      .flatMap((summary) => summary.topNeeds)
      .filter((need) => typeof need !== 'string')
      .filter((need) => need.mentions != null && need.projects && need.mentions !== need.projects.length)
      .map((need) => need.text);
    expect(mismatched.sort()).toEqual([...DRAFT_2026_09_17].sort());
  });
});
