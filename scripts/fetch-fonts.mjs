#!/usr/bin/env node
/**
 * Saves the site's fonts beside the plugin, so neither the plugin nor the static
 * build loads them from Google (WORDPRESS-PLAN.md open question 10).
 *
 *   node scripts/fetch-fonts.mjs
 *
 * It asks Google Fonts for the faces in `WANTED`, as a modern browser would: the
 * stylesheet the site used to `@import`, less its italic faces (see `WANTED`). It
 * saves each font file Google names, unchanged, in
 * `wp-content/plugins/firehouse/assets/fonts/`, with the fonts' licence, the SIL Open
 * Font License, from a pinned commit of Google's fonts repository. It writes
 * `wp-content/plugins/firehouse/assets/css/fonts.css`, Google's `@font-face` rules
 * rebuilt from their checked fields and pointed at those files, and
 * `assets/fonts/SOURCES.json`, where each file came from with its SHA-256.
 * `scripts/fonts.test.mjs` checks the committed files against both.
 *
 * Run it again only to refresh the fonts. Its output is committed, so builds and CI
 * never reach Google or GitHub. The families, weights and styles are listed below,
 * not read from src/styles/tokens/typography.css, so a new weight starts here.
 *
 * Google serves variable fonts: one file for every weight of a style, split by script
 * into subsets, each loaded only when a page uses its range. Everything is fetched
 * and checked before anything is written, so a run that stops changes nothing.
 * SOURCES.json is written after the files it describes, so a run that fails while
 * writing leaves the old record for the next run to check against, or a broken one
 * the next run stops on.
 *
 * A file keeps its name from run to run, and fonts.css and the frame's preload
 * (`Frame::PRELOADED_FONTS`) ask for it by that name, with no version, so a cache could
 * go on serving its old bytes. Until the names carry each file's hash, a run that
 * would change a saved file's bytes stops (WORDPRESS-PLAN.md, Phase 3's fonts).
 */

import { createHash } from 'node:crypto';
import { mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve, sep } from 'node:path';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const FONTS = resolve(ROOT, 'wp-content/plugins/firehouse/assets/fonts');
const CSS = resolve(ROOT, 'wp-content/plugins/firehouse/assets/css/fonts.css');

// The faces the site asks for: family, style, weights. Google must return each of
// them, and nothing else.
//
// No italics: nothing the site or the plugin writes is set in italic (fonts.test.mjs
// holds them to that), so no browser ever downloaded the italic faces the design
// system's stylesheet asked for. Text set in italic needs its face added here, or the
// browser slants the upright one. That includes text an editor sets in italic in a
// page of the Firehouse tree, if Phase 6 lets them.
const WANTED = [
  ['Archivo', 'normal', [500, 600, 700, 800]],
  ['Public Sans', 'normal', [400, 500, 600, 700]],
];
// Asked for, as the site's stylesheet did, but Google serves none: the stats and the
// wordmark name Archivo Expanded first and fall back to Archivo. If Google starts
// serving it, the run stops, since its faces then belong in WANTED and its licence in
// LICENCES.
const NOT_SERVED = ['Archivo Expanded'];
const STYLESHEET =
  'https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700;800&family=Archivo+Expanded:wght@700;800&family=Public+Sans:wght@400;500;600;700&display=swap';
// Google picks file formats by browser; this asks as a modern Chromium browser does.
const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
const LICENCE_COMMIT = '86aced91de05cd4f96a06838124cca45be337b2b';
const LICENCES = {
  archivo: `https://raw.githubusercontent.com/google/fonts/${LICENCE_COMMIT}/ofl/archivo/OFL.txt`,
  'public-sans': `https://raw.githubusercontent.com/google/fonts/${LICENCE_COMMIT}/ofl/publicsans/OFL.txt`,
};
const MAX_FONT_BYTES = 256 * 1024;

async function get(url) {
  const response = await fetch(url, { headers: { 'User-Agent': BROWSER }, redirect: 'error' });
  if (!response.ok) throw new Error(`${url}: ${response.status} ${response.statusText}`);
  return response;
}

const sha256 = (bytes) => createHash('sha256').update(bytes).digest('hex');
const slug = (text) => text.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

/** One face, its fields checked: anything unexpected stops the run. */
function parseFace(subset, body) {
  const fields = Object.fromEntries(
    body
      .split(';')
      .map((line) => line.trim())
      .filter(Boolean)
      .map((line) => {
        const at = line.indexOf(':');
        if (at < 0) throw new Error(`A face has a line that isn't a descriptor: ${line}`);
        return [line.slice(0, at).trim(), line.slice(at + 1).trim()];
      }),
  );
  const known = ['font-family', 'font-style', 'font-weight', 'font-stretch', 'font-display', 'src', 'unicode-range'];
  const unknown = Object.keys(fields).filter((name) => !known.includes(name));
  if (unknown.length) throw new Error(`A face has descriptors this script doesn't copy: ${unknown.join(', ')}`);

  const family = /^'([A-Za-z ]+)'$/.exec(fields['font-family'] ?? '')?.[1];
  const style = fields['font-style'];
  const weight = Number(fields['font-weight']);
  const src = /^url\((https:\/\/fonts\.gstatic\.com\/s\/[a-z0-9]+\/v\d+\/[\w-]+\.woff2)\) format\('woff2'\)$/.exec(fields.src ?? '');
  const range = fields['unicode-range'];
  if (!family) throw new Error(`A face has an unexpected family: ${fields['font-family']}`);
  if (!['normal', 'italic'].includes(style)) throw new Error(`${family} has an unexpected style: ${style}`);
  if (!Number.isInteger(weight)) throw new Error(`${family} has an unexpected weight: ${fields['font-weight']}`);
  if (fields['font-stretch'] !== undefined && fields['font-stretch'] !== '100%') {
    throw new Error(`${family} has an unexpected stretch: ${fields['font-stretch']}`);
  }
  if (fields['font-display'] !== 'swap') throw new Error(`${family} doesn't swap: ${fields['font-display']}`);
  if (!src) throw new Error(`${family} ${style} ${weight} has a source this script doesn't take: ${fields.src}`);
  if (!/^U\+[0-9A-F?]+(?:-[0-9A-F]+)?(?:, U\+[0-9A-F?]+(?:-[0-9A-F]+)?)*$/i.test(range ?? '')) {
    throw new Error(`${family} has an unexpected unicode-range: ${range}`);
  }
  return { subset, family, style, weight, stretch: fields['font-stretch'], url: src[1], range };
}

const google = await (await get(STYLESHEET)).text();
const blocks = [...google.matchAll(/\/\*\s*([a-z-]+)\s*\*\/\s*@font-face\s*\{([^}]*)\}/g)];
const declared = (google.match(/@font-face/g) ?? []).length;
if (blocks.length !== declared) throw new Error(`Google returned ${declared} faces, ${blocks.length} of them labelled with a subset.`);
const faces = blocks.map(([, subset, body]) => parseFace(subset, body));

const served = NOT_SERVED.filter((family) => faces.some((face) => face.family === family));
if (served.length) throw new Error(`Google now serves ${served.join(', ')}: add its faces to WANTED and its licence to LICENCES.`);
const requested = new Set(WANTED.flatMap(([family, style, weights]) => weights.map((weight) => `${family} ${style} ${weight}`)));
const strangers = faces.filter((face) => !requested.has(`${face.family} ${face.style} ${face.weight}`));
if (strangers.length) {
  throw new Error(`Google returned faces not asked for: ${[...new Set(strangers.map((f) => `${f.family} ${f.style} ${f.weight}`))].join(', ')}`);
}
for (const [family, style, weights] of WANTED) {
  for (const weight of weights) {
    if (!faces.some((f) => f.family === family && f.style === style && f.weight === weight && f.subset === 'latin')) {
      throw new Error(`Google returned no latin ${family} ${style} ${weight}.`);
    }
  }
}

// One file per family, style and subset: the weights of a style share a variable font.
const files = new Map();
for (const face of faces) {
  const name = `${slug(face.family)}-${face.style}-${face.subset}.woff2`;
  if (!resolve(FONTS, name).startsWith(FONTS + sep)) throw new Error(`${name} would land outside ${FONTS}`);
  const other = [...files].find(([url, saved]) => saved === name && url !== face.url);
  if (other) throw new Error(`${name} would hold two different files: ${other[0]} and ${face.url}`);
  files.set(face.url, name);
}

// Everything is fetched and checked before anything is written.
const saved = new Map();
for (const [url, name] of files) {
  const response = await get(url);
  if (response.headers.get('content-type') !== 'font/woff2') throw new Error(`${url} isn't font/woff2`);
  const bytes = Buffer.from(await response.arrayBuffer());
  if (bytes.length > MAX_FONT_BYTES || bytes.subarray(0, 4).toString('latin1') !== 'wOF2') {
    throw new Error(`${url} isn't a woff2 font under ${MAX_FONT_BYTES} bytes`);
  }
  saved.set(name, { url, bytes });
}
const families = new Set(faces.map((face) => slug(face.family)));
for (const [family, url] of Object.entries(LICENCES)) {
  if (!families.has(family)) continue;
  const text = await (await get(url)).text();
  if (!text.includes('SIL OPEN FONT LICENSE Version 1.1') || !/^Copyright/m.test(text)) {
    throw new Error(`${url} doesn't read as an OFL 1.1 licence`);
  }
  saved.set(`${family}-OFL.txt`, { url, bytes: Buffer.from(text) });
}

// A saved font whose bytes would change under the same name stops the run (see the
// top of this file). Each font file downloaded (not the licences) is compared with the
// saved file and with the hash SOURCES.json records for it, so neither one missing lets
// a change through. A font in neither is new, and fine, as is one no longer asked for.
// A font or a SOURCES.json that's there but can't be read stops the run.
const readSaved = (name) => {
  try {
    return readFileSync(resolve(FONTS, name));
  } catch (error) {
    if (error.code === 'ENOENT') return undefined;
    throw new Error(`${resolve(FONTS, name)} can't be read (${error.code ?? error.message}).`, { cause: error });
  }
};
const record = readSaved('SOURCES.json');
let recorded = {};
if (record !== undefined) {
  try {
    recorded = JSON.parse(record.toString('utf8')).fonts;
  } catch (error) {
    throw new Error(`${resolve(FONTS, 'SOURCES.json')} can't be read: ${error.message}`, { cause: error });
  }
  if (typeof recorded !== 'object' || recorded === null || Array.isArray(recorded)) {
    throw new Error(`${resolve(FONTS, 'SOURCES.json')} has no "fonts" object.`);
  }
}
const changed = [];
for (const [name, { bytes }] of saved) {
  if (!name.endsWith('.woff2')) continue;
  const onDisk = readSaved(name);
  const differsFromFile = onDisk !== undefined && !onDisk.equals(bytes);
  const differsFromRecord = Object.hasOwn(recorded, name) && recorded[name]?.sha256 !== sha256(bytes);
  if (differsFromFile || differsFromRecord) {
    const from = [differsFromFile && 'the saved file', differsFromRecord && 'its hash in SOURCES.json'].filter(Boolean);
    changed.push(`${name} (${from.join(' and ')})`);
  }
}
if (changed.length) {
  throw new Error(
    `Downloaded fonts differ from what's saved: ${changed.join(', ')}. If a saved font or SOURCES.json was changed ` +
      'here, restore it from git and run again. If Google now serves different bytes, give the font files names with ' +
      'their hash first, and the preload in Frame::PRELOADED_FONTS with them, or browsers may keep the old files ' +
      '(WORDPRESS-PLAN.md, Phase 3).',
  );
}

const describe = (map) =>
  Object.fromEntries([...map].sort().map(([name, { url, bytes }]) => [name, { source: url, sha256: sha256(bytes), bytes: bytes.length }]));
const sources = {
  stylesheet: STYLESHEET,
  fetched: new Date().toISOString().slice(0, 10),
  fonts: describe(new Map([...saved].filter(([name]) => name.endsWith('.woff2')))),
  licences: describe(new Map([...saved].filter(([name]) => name.endsWith('.txt')))),
};

const rules = faces
  .map(
    (face) => `/* ${face.subset} */
@font-face {
  font-family: '${face.family}';
  font-style: ${face.style};
  font-weight: ${face.weight};${face.stretch ? `\n  font-stretch: ${face.stretch};` : ''}
  font-display: swap;
  src: url('../fonts/${files.get(face.url)}') format('woff2');
  unicode-range: ${face.range};
}`,
  )
  .join('\n');

const css = `/*
 * The site's fonts, self-hosted (WORDPRESS-PLAN.md open question 10): Google Fonts'
 * own @font-face rules and files, saved by scripts/fetch-fonts.mjs, with where each
 * file came from in ../fonts/SOURCES.json. Both families are under the SIL Open Font
 * License, saved beside the files in ../fonts/.${
  NOT_SERVED.length
    ? `\n *\n * Google serves no ${NOT_SERVED.join(' or ')}, so the token that names it falls back\n * to the next family in its list, as it did when the site loaded Google's stylesheet.`
    : ''
}
 *
 * Loaded on its own, never inside firehouse.css: the static build imports it in
 * src/main.tsx, and the plugin loads it on Firehouse pages (Frame). To use GSL's fonts
 * instead, stop loading it in both places and point the three font tokens in
 * src/styles/tokens/typography.css at Montserrat and Nunito Sans. The static build
 * would then need its own way to load those, or it falls back to system fonts.
 */

${rules}
`;

// Written over the saved files, SOURCES.json last, and only then are files no longer
// asked for removed. Every font written has passed the check above, so a run that
// fails partway leaves SOURCES.json's old record, or a broken one the next run stops on.
// Names are kept regardless of case: on Windows and macOS, a file written over one
// whose name differs only in case keeps the old name, and mustn't then be removed.
mkdirSync(FONTS, { recursive: true });
for (const [name, { bytes }] of saved) writeFileSync(resolve(FONTS, name), bytes);
writeFileSync(CSS, css);
writeFileSync(resolve(FONTS, 'SOURCES.json'), `${JSON.stringify(sources, null, 2)}\n`);
const kept = new Set([...saved.keys(), 'SOURCES.json'].map((name) => name.toLowerCase()));
for (const name of readdirSync(FONTS)) {
  if (!kept.has(name.toLowerCase())) rmSync(resolve(FONTS, name), { recursive: true, force: true });
}

console.log(`Saved ${files.size} font files for ${faces.length} faces, and ${saved.size - files.size} licences, in ${FONTS}`);
console.log(`Wrote ${CSS}`);
if (NOT_SERVED.length) console.log(`Google served no ${NOT_SERVED.join(', ')}.`);
console.log(readdirSync(FONTS).join('\n'));
