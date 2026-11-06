import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { localAdapter } from './adapters/local';
import { needMentions } from './derive';
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

  // The About page says the order of the top needs is determined by project count,
  // with ties going to the need with broader interagency or multi-regional
  // applicability: Stephanie's answer of 6 November, relayed by Eric. The tie-break
  // is a reviewer's judgement; the count order is checked here, on the content the
  // pages render, so a project unpublished later, which lowers the counts shown,
  // fails it too.
  it('lists each topic area’s needs by project count, highest first, as the pages show them', async () => {
    const content = await localAdapter.load();
    for (const summary of content.topicSummaries) {
      const counts = summary.topNeeds.map(needMentions);
      expect(counts, summary.topic).toEqual([...counts].sort((a, b) => b - a));
    }
  });
});
