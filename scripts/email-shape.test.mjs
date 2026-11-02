import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { findEmails, hasEmail, isTeamEmail } from './email-shape.mjs';

/**
 * The shared cases, which the plugin's PHP runs too, and a scan of the site's content
 * files, so a hand edit that puts an email address in them fails CI before it is
 * public (rule 2).
 */

const read = (path) => JSON.parse(readFileSync(fileURLToPath(new URL(path, import.meta.url)), 'utf8'));
const { cases } = read('../wp-content/plugins/firehouse/shared/cases/email-shape.json');

describe('shared/email-shape.json', () => {
  it.each(cases.map((c) => [c.name, c]))('%s', (_name, c) => {
    expect(hasEmail(c.text)).toBe(c.email);
    expect(isTeamEmail(c.text)).toBe(c.team);
  });
});

describe('findEmails', () => {
  it('finds an address anywhere, by path, and passes a team member’s own address', () => {
    const blocks = {
      projects: [{ abstract: 'Write to jane@gmail.com.' }],
      about: {
        team: {
          members: [
            { name: 'A', email: 'a.person@noaa.gov' },
            { name: 'B', email: 'b@noaa.gov; c@gmail.com' },
            { name: 'C', bio: ['Reach me at c@noaa.gov.'] },
          ],
        },
        'team.members.0.email': 'd@gmail.com',
      },
    };
    expect(findEmails(blocks)).toEqual([
      'projects.0.abstract',
      'about.team.members.1.email',
      'about.team.members.2.bio.0',
      'about.team.members.0.email',
    ]);
  });

  it('finds nothing in the site’s content files', () => {
    const names = ['settings', 'topics', 'topicSummaries', 'projects', 'landing', 'about'];
    const blocks = Object.fromEntries(names.map((name) => [name, read(`../src/content/data/${name}.json`)]));
    expect(findEmails(blocks)).toEqual([]);
  });
});
