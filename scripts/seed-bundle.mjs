#!/usr/bin/env node
/**
 * Writes the seed bundle that the plugin's Firehouse → Import screen loads: the six
 * content files in `src/content/data/` (every JSON file there but
 * `gaccBoundaries.json`, which the plugin ships itself) in one JSON file.
 *
 *   node scripts/seed-bundle.mjs           write seed/firehouse-seed.json
 *   node scripts/seed-bundle.mjs <file>    write it somewhere else
 *
 * The bundle holds what the site already publishes and nothing more. The import
 * script has already dropped submitters' emails, job titles and Q10 answers
 * (WORDPRESS-PLAN.md rule 2), and this script stops, writing nothing, if any field
 * holds something shaped like an email address, other than an About page team
 * member's own work address (scripts/email-shape.mjs). The Import screen checks the
 * same again. The About portraits aren't in it: they reach WordPress through the Media
 * Library when the About page is built (Phase 4).
 *
 * `firehouseSeed` marks the file's format, so the Import screen refuses any other JSON
 * file before checking its content.
 */

import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { findEmails } from './email-shape.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const DATA = resolve(HERE, '../src/content/data');
const OUT = resolve(process.argv[2] ?? resolve(HERE, '../seed/firehouse-seed.json'));
const BLOCKS = ['settings', 'topics', 'topicSummaries', 'projects', 'landing', 'about'];

const bundle = { firehouseSeed: 1 };
for (const block of BLOCKS) {
  bundle[block] = JSON.parse(readFileSync(resolve(DATA, `${block}.json`), 'utf8'));
}

const leaks = findEmails(Object.fromEntries(BLOCKS.map((block) => [block, bundle[block]])));
if (leaks.length) {
  console.error('✖ Fields holding something shaped like an email address:');
  for (const path of leaks) console.error(`  ${path}`);
  console.error('\nNothing written.');
  process.exit(1);
}

mkdirSync(dirname(OUT), { recursive: true });
writeFileSync(OUT, `${JSON.stringify(bundle, null, 2)}\n`);
console.log(`Wrote ${OUT}: ${bundle.projects.length} projects, ${bundle.topics.length} topics.`);
