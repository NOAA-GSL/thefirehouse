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
const { cases, find } = read('../wp-content/plugins/firehouse/shared/cases/email-shape.json');

describe('shared/email-shape.json', () => {
  it.each(cases.map((c) => [c.name, c]))('%s', (_name, c) => {
    expect(hasEmail(c.text)).toBe(c.email);
    expect(isTeamEmail(c.text)).toBe(c.team);
  });
});

describe('findEmails', () => {
  // In any order: PHP keeps a JSON object's key order, JavaScript puts number-like keys first.
  it.each(find.map((c) => [c.name, c]))('%s', (_name, c) => {
    expect(findEmails(c.blocks).sort()).toEqual([...c.paths].sort());
  });

  it('finds nothing in the site’s content files', () => {
    const names = ['settings', 'topics', 'topicSummaries', 'projects', 'landing', 'about'];
    const blocks = Object.fromEntries(names.map((name) => [name, read(`../src/content/data/${name}.json`)]));
    expect(findEmails(blocks)).toEqual([]);
  });
});
