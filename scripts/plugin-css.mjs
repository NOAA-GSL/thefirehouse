#!/usr/bin/env node
/**
 * Writes the plugin's stylesheet, `wp-content/plugins/firehouse/assets/css/firehouse.css`,
 * from the site's own CSS.
 *
 *   node scripts/plugin-css.mjs           write it
 *   node scripts/plugin-css.mjs --check   fail if the committed copy is out of date
 *
 * It runs the site's Vite build without writing anything, so the plugin gets the same
 * rules, scoped under `.fh-app` by the same PostCSS plugin (`scripts/scope-css.ts`),
 * in the order the site loads them. That order matters: the shared rules in
 * `src/styles/global.css` come after the components' and win their ties.
 *
 * Left out, because GSL's theme does their job under the plugin: the static build's
 * page shell, gov banner and footer (`REACT_ONLY`; they're found among the JS imports,
 * so each has to stay imported by a component, not by a CSS `@import`). Left for
 * later: the coverage map's chunk, which loads with the map (`DEFERRED`, Phase 5), and
 * the Google Fonts `@import`, since the fonts will be the plugin's own to load. Any
 * other stylesheet the build makes stops the script, so none is dropped unnoticed.
 *
 * The output is checked before it's written: every rule must sit inside `.fh-app`,
 * and nothing may load from anywhere (`@import`, or a `url()` other than `data:`). It
 * isn't minified, so a change to it reads in review.
 */

import { readFileSync, realpathSync, writeFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import postcss from 'postcss';
import { build } from 'vite';
import { isAnchored } from './scope-css.ts';

// Vite reports module ids with the drive letter as the file system has it, whatever
// case the shell started in.
const ROOT = realpathSync.native(resolve(dirname(fileURLToPath(import.meta.url)), '..'));
const OUT = resolve(ROOT, 'wp-content/plugins/firehouse/assets/css/firehouse.css');
const REACT_ONLY = ['src/styles/page-shell.css', 'src/design-system/GovBanner.css', 'src/design-system/SiteFooter.css'];
const DEFERRED = ['RegionMap'];

const posix = (path) => path.replace(/\\/g, '/');
const leftOut = new Set(REACT_ONLY.map((file) => posix(resolve(ROOT, file))));

/** Loads each React-only stylesheet as empty, so the rest keep their places. */
function leaveOut() {
  const seen = new Set();
  return {
    name: 'firehouse-plugin-css-leave-out',
    enforce: 'pre',
    load(id) {
      const file = posix(id.split('?')[0]);
      if (!leftOut.has(file)) return null;
      seen.add(file);
      return '';
    },
    buildEnd() {
      const missing = [...leftOut].filter((file) => !seen.has(file));
      if (missing.length) throw new Error(`No longer imported from a component, so nothing to leave out: ${missing.join(', ')}`);
    },
  };
}

const result = await build({
  root: ROOT,
  configFile: resolve(ROOT, 'vite.config.ts'),
  logLevel: 'warn',
  plugins: [leaveOut()],
  build: { write: false, minify: false, cssMinify: false, reportCompressedSize: false, copyPublicDir: false },
});
const output = (Array.isArray(result) ? result : [result]).flatMap((bundle) => bundle.output);
const chunks = new Map(output.filter((file) => file.type === 'chunk').map((chunk) => [chunk.fileName, chunk]));
const entry = [...chunks.values()].find((chunk) => chunk.isEntry);
if (!entry) throw new Error('The build has no entry chunk.');

// The entry's stylesheets, in the order a page loads them: the chunks it imports
// first, depth first, then its own, as Vite orders the links in index.html.
const sheets = [];
const visited = new Set();
(function collect(chunk) {
  if (visited.has(chunk.fileName)) return;
  visited.add(chunk.fileName);
  for (const name of chunk.imports) collect(chunks.get(name));
  for (const sheet of chunk.viteMetadata?.importedCss ?? []) if (!sheets.includes(sheet)) sheets.push(sheet);
})(entry);
if (sheets.length === 0) throw new Error('The build produced no stylesheet for the entry.');

const deferred = new Set(
  [...chunks.values()]
    .filter((chunk) => DEFERRED.includes(chunk.name))
    .flatMap((chunk) => [...(chunk.viteMetadata?.importedCss ?? [])]),
);
const unaccounted = output
  .filter((file) => file.type === 'asset' && file.fileName.endsWith('.css'))
  .map((file) => file.fileName)
  .filter((name) => !sheets.includes(name) && !deferred.has(name));
if (unaccounted.length) throw new Error(`Stylesheets neither in the plugin's copy nor deferred: ${unaccounted.join(', ')}`);

const body = sheets
  .map((name) => output.find((file) => file.fileName === name).source)
  .join('\n')
  .replace(/\r\n/g, '\n')
  .replace(/^@import url\((['"]?)https:\/\/fonts\.googleapis\.com\/[^)]*\1\);?\n?/gm, '')
  .trim();

// Fail closed: whatever the strip above missed, or a later change adds, stops here.
const problems = [];
postcss.parse(body).walk((node) => {
  if (node.type === 'atrule' && node.name.toLowerCase() === 'import') problems.push(`@import ${node.params}`);
  if (node.type === 'decl' && /url\(\s*(?!['"]?data:)/i.test(node.value)) problems.push(`${node.prop}: ${node.value}`);
  if (node.type === 'rule' && !(node.parent?.type === 'atrule' && /keyframes$/i.test(node.parent.name))) {
    for (const selector of node.selectors) if (!isAnchored(selector)) problems.push(`unscoped selector ${selector}`);
  }
});
if (problems.length) throw new Error(`firehouse.css would leak or load something:\n  ${problems.join('\n  ')}`);

const css = `/*
 * The Firehouse plugin's stylesheet. Generated by scripts/plugin-css.mjs from the
 * site's own CSS in src/: change those files, then run \`npm run plugin:css\`.
 *
 * Every rule is scoped under .fh-app (WORDPRESS-PLAN.md rule 9), in the order the
 * site loads them. Left out: the static build's page shell, gov banner and footer,
 * which GSL's theme replaces; the coverage map, which arrives with the map; and the
 * fonts, which the plugin will load itself.
 */

${body}
`;

if (process.argv.includes('--check')) {
  let current = '';
  try {
    current = readFileSync(OUT, 'utf8').replace(/\r\n/g, '\n');
  } catch {
    // Missing counts as out of date.
  }
  if (current !== css) {
    console.error(`${posix(OUT.slice(ROOT.length + 1))} is out of date with src/. Run: npm run plugin:css`);
    process.exit(1);
  }
  console.log('firehouse.css is up to date.');
} else {
  mkdirSync(dirname(OUT), { recursive: true });
  writeFileSync(OUT, css);
  console.log(`Wrote ${OUT}: ${css.length} bytes from ${sheets.length} stylesheet${sheets.length === 1 ? '' : 's'}.`);
}
