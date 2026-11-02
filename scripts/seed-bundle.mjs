#!/usr/bin/env node
/**
 * Writes the seed bundle that the plugin's Firehouse → Import screen loads: the six
 * content files in `src/content/data/` in one JSON file.
 *
 *   node scripts/seed-bundle.mjs           write seed/firehouse-seed.json
 *   node scripts/seed-bundle.mjs <file>    write it somewhere else
 *
 * The bundle holds what the site already publishes and nothing more. The import
 * script has already dropped submitters' emails, job titles and Q10 answers
 * (WORDPRESS-PLAN.md rule 2), and this script stops, writing nothing, if any field
 * other than an About page team member's email holds something shaped like an email
 * address. The About portraits aren't in it: they reach WordPress through the Media
 * Library when the About page is built (Phase 4).
 *
 * `firehouseSeed` marks the file's format, so the Import screen can refuse any other
 * JSON file before it reads one.
 */

import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const DATA = resolve(HERE, '../src/content/data');
const OUT = resolve(process.argv[2] ?? resolve(HERE, '../seed/firehouse-seed.json'));
const BLOCKS = ['settings', 'topics', 'topicSummaries', 'projects', 'landing', 'about'];

/**
 * Anything shaped like an email address, as `import-survey.mjs` finds them. A slash
 * or colon can't sit beside the @, so "https://medium.com/@name.surname/post" isn't one.
 */
const EMAIL = /[^\s@<>()/:,;"']+@[^\s@<>()/:,;"']+\.[a-z]{2,}/i;

/** A team member's work email, which the About page shows on purpose. */
const TEAM_EMAIL = /^about\.team\.members\.\d+\.email$/;

const bundle = { firehouseSeed: 1 };
for (const block of BLOCKS) {
  bundle[block] = JSON.parse(readFileSync(resolve(DATA, `${block}.json`), 'utf8'));
}

const leaks = [];
const scan = (value, path) => {
  if (typeof value === 'string') {
    if (EMAIL.test(value) && !TEAM_EMAIL.test(path)) leaks.push(path);
  } else if (value && typeof value === 'object') {
    for (const [key, inner] of Object.entries(value)) scan(inner, `${path}.${key}`);
  }
};
for (const block of BLOCKS) scan(bundle[block], block);

if (leaks.length) {
  console.error('✖ Fields holding something shaped like an email address:');
  for (const path of leaks) console.error(`  ${path}`);
  console.error('\nNothing written.');
  process.exit(1);
}

mkdirSync(dirname(OUT), { recursive: true });
writeFileSync(OUT, `${JSON.stringify(bundle, null, 2)}\n`);
console.log(`Wrote ${OUT}: ${bundle.projects.length} projects, ${bundle.topics.length} topics.`);
