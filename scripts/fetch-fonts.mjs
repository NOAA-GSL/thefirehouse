#!/usr/bin/env node
/**
 * Saves the site's fonts beside the plugin, so neither the plugin nor the static
 * build loads them from Google (WORDPRESS-PLAN.md open question 10).
 *
 *   node scripts/fetch-fonts.mjs
 *
 * It asks Google Fonts for the stylesheet the site used to `@import`, as a current
 * browser would, and saves each font file it names, unchanged, in
 * `wp-content/plugins/firehouse/assets/fonts/`. It then writes
 * `wp-content/plugins/firehouse/assets/css/fonts.css`: Google's `@font-face` rules,
 * pointing at those files. The fonts' licence, the SIL Open Font License, comes from
 * Google's fonts repository and is saved beside them.
 *
 * Run it again only to refresh the fonts. Its output is committed, so builds and CI
 * never reach Google. Google serves variable fonts, one file for every weight of a
 * style, split by script into subsets, each loaded only when a page uses its range.
 */

import { mkdirSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const FONTS = resolve(ROOT, 'wp-content/plugins/firehouse/assets/fonts');
const CSS = resolve(ROOT, 'wp-content/plugins/firehouse/assets/css/fonts.css');

// The families, weights and styles src/styles/tokens/typography.css loaded from Google.
const STYLESHEET =
  'https://fonts.googleapis.com/css2?family=Archivo:ital,wght@0,500;0,600;0,700;0,800;1,600&family=Archivo+Expanded:wght@700;800&family=Public+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&display=swap';
// Google picks file formats by browser; this asks as a current Edge does.
const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0';
const LICENCES = {
  archivo: 'https://raw.githubusercontent.com/google/fonts/main/ofl/archivo/OFL.txt',
  'public-sans': 'https://raw.githubusercontent.com/google/fonts/main/ofl/publicsans/OFL.txt',
};

async function get(url, as) {
  const response = await fetch(url, { headers: { 'User-Agent': BROWSER } });
  if (!response.ok) throw new Error(`${url}: ${response.status} ${response.statusText}`);
  return as === 'bytes' ? Buffer.from(await response.arrayBuffer()) : response.text();
}

const slug = (text) => text.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
const google = await get(STYLESHEET, 'text');

// Each face: `/* subset */ @font-face { … src: url(…) format('woff2'); … }`.
const faces = [...google.matchAll(/\/\*\s*([\w-]+)\s*\*\/\s*@font-face\s*\{([^}]*)\}/g)].map(([, subset, body]) => {
  const field = (name) => new RegExp(`${name}:\\s*([^;]+);`).exec(body)?.[1].trim();
  const url = /src:\s*url\(([^)]+)\)/.exec(body)?.[1];
  const family = field('font-family')?.replace(/['"]/g, '');
  if (!url || !family) throw new Error(`A face Google returned has no family or file:\n${body}`);
  return { subset, body, url, family, style: field('font-style') ?? 'normal' };
});
if (faces.length === 0) throw new Error('Google returned no faces.');

// One file per family, style and subset: the weights of a style share a variable font.
const files = new Map();
for (const face of faces) {
  const name = `${slug(face.family)}-${face.style}-${face.subset}.woff2`;
  const other = [...files].find(([url, saved]) => saved === name && url !== face.url);
  if (other) throw new Error(`${name} would hold two different files: ${other[0]} and ${face.url}`);
  files.set(face.url, name);
}

rmSync(FONTS, { recursive: true, force: true });
mkdirSync(FONTS, { recursive: true });
for (const [url, name] of files) writeFileSync(resolve(FONTS, name), await get(url, 'bytes'));
const families = new Set(faces.map((face) => slug(face.family)));
for (const [family, url] of Object.entries(LICENCES)) {
  if (families.has(family)) writeFileSync(resolve(FONTS, `${family}-OFL.txt`), await get(url, 'text'));
}

const missing = ['Archivo', 'Archivo Expanded', 'Public Sans'].filter((family) => !faces.some((face) => face.family === family));
const rules = faces
  .map(({ subset, body, url }) => `/* ${subset} */\n@font-face {${body.replace(url, `'../fonts/${files.get(url)}'`)}}`)
  .join('\n');

writeFileSync(
  CSS,
  `/*
 * The site's fonts, self-hosted (WORDPRESS-PLAN.md open question 10): Google Fonts'
 * own @font-face rules and files, saved by scripts/fetch-fonts.mjs. Both families are
 * under the SIL Open Font License, saved beside the files in ../fonts/.${
   missing.length
     ? `\n *\n * Google serves no ${missing.join(' or ')}, so the token that names it falls back\n * to the next family in its list, as it did when the site loaded Google's stylesheet.`
     : ''
 }
 *
 * Loaded on its own, not inside firehouse.css. To use GSL's fonts instead, stop
 * loading it (src/main.tsx imports it for the static build; the plugin loads it with
 * its templates) and point --font-display, --font-display-expanded and --font-body in
 * src/styles/tokens/typography.css at Montserrat and Nunito Sans.
 */

${rules}
`,
);

console.log(`Saved ${files.size} font files for ${faces.length} faces in ${FONTS}`);
console.log(`Wrote ${CSS}`);
if (missing.length) console.log(`Google served no ${missing.join(', ')}.`);
console.log(readdirSync(FONTS).join('\n'));
