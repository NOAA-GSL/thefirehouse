#!/usr/bin/env node
/**
 * Writes the plugin's stylesheet from the site's own CSS, twice, in
 * `wp-content/plugins/firehouse/assets/css/`: minified in `firehouse.min.css`, the copy
 * the plugin loads, and readable in `firehouse.css`, the copy a review reads and the
 * plugin loads only under SCRIPT_DEBUG, as WordPress does with its own.
 *
 *   node scripts/plugin-css.mjs           write them
 *   node scripts/plugin-css.mjs --check   fail if either committed copy is out of date
 *
 * It runs the site's Vite build without writing anything or minifying, so the plugin
 * gets the same rules, scoped under `.fh-app` by the same PostCSS plugin
 * (`scripts/scope-css.ts`), in the order the site loads them. That order matters: the
 * shared rules in `src/styles/global.css` come after the components' and win their
 * ties. That is the readable copy. The minified copy is the readable one minified as
 * the site's build minifies its CSS (esbuild, for the build's `cssTarget`), so the
 * two hold the same rules; for older browsers it may write a property out longhand.
 *
 * One stylesheet serves every Firehouse page; WORDPRESS-PLAN.md (Phase 4's weight)
 * says why it isn't split by page.
 *
 * Left out (`LEFT_OUT`): the static build's page shell, gov banner and footer,
 * because GSL's theme does their job under the plugin, and the fonts' stylesheet,
 * which the plugin loads on its own, so switching to GSL's fonts never touches this
 * file. Left out until a page the plugin renders needs them: the explorer's page and
 * the components only it uses (`EXPLORER`, Phase 5), and the design system's
 * components no page renders (`UNUSED`); `scripts/plugin-css.test.mjs` fails if the
 * plugin's markup uses a class only those style. All of these are found among the JS
 * imports, so each has to stay imported from a module, not by a CSS `@import`. All but
 * the fonts must be found, so a stylesheet that moved isn't let in unnoticed; the fonts
 * may go, when the site stops loading them. Left for later: the coverage map's chunk,
 * which loads with the map (`DEFERRED`, Phase 5). Any other stylesheet the build makes
 * stops the script, so none is dropped unnoticed. The lists are in
 * `scripts/plugin-css-sources.mjs`, where the test reads them too.
 *
 * Both copies are checked before either is written: every rule must sit inside
 * `.fh-app`, no at-rule may reach the whole page, and nothing may load from elsewhere.
 * PostCSS reads each first (`outputProblems` in scope-css.ts), then a browser reads
 * the exact text that would be written (`scripts/css-browser-check.mjs`), since the
 * two tokenize some CSS differently.
 */

import { readFileSync, realpathSync, writeFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { gzipSync } from 'node:zlib';
import postcss from 'postcss';
import { build, loadConfigFromFile, transformWithEsbuild } from 'vite';
// Node 22.18 or later strips the types itself; scope-css.ts imports nothing, so no
// `.ts` extension has to appear anywhere TypeScript checks.
import { outputProblems } from './scope-css.ts';
import { browserProblems, openBrowser } from './css-browser-check.mjs';
import { DEFERRED, EXPLORER, LEFT_OUT, MAY_GO, UNUSED } from './plugin-css-sources.mjs';

// Vite reports module ids with the drive letter as the file system has it, whatever
// case the shell started in.
const ROOT = realpathSync.native(resolve(dirname(fileURLToPath(import.meta.url)), '..'));
const DIR = resolve(ROOT, 'wp-content/plugins/firehouse/assets/css');
const CONFIG = resolve(ROOT, 'vite.config.ts');

const posix = (path) => path.replace(/\\/g, '/');
const leftOut = new Set([...LEFT_OUT, ...EXPLORER, ...UNUSED].map((file) => posix(resolve(ROOT, file))));
const mayGo = new Set(MAY_GO.map((file) => posix(resolve(ROOT, file))));

/** Loads each left-out stylesheet as empty, so the rest keep their places. */
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
      const missing = [...leftOut].filter((file) => !seen.has(file) && !mayGo.has(file));
      if (missing.length) throw new Error(`No longer imported from a module, so nothing to leave out: ${missing.join(', ')}`);
    },
  };
}

/**
 * The entry's CSS from the site's build, not minified, and the browsers the build
 * targets its CSS at.
 */
async function siteCss() {
  let cssTarget;
  const result = await build({
    root: ROOT,
    configFile: CONFIG,
    logLevel: 'warn',
    plugins: [
      leaveOut(),
      {
        name: 'firehouse-plugin-css-target',
        configResolved(config) {
          cssTarget = config.build.cssTarget;
        },
      },
    ],
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

  const css = sheets
    .map((name) => output.find((file) => file.fileName === name).source)
    .join('\n')
    .replace(/\r\n/g, '\n')
    .trim();
  return { css, cssTarget };
}

// The site's build minifies its CSS with esbuild, Vite's default. The minified copy
// follows it only while vite.config.ts leaves that alone, so a change there stops here.
// Read from the file, not the resolved config, where the React plugin adds esbuild
// options of its own.
const { config: siteConfig } = (await loadConfigFromFile({ command: 'build', mode: 'production' }, CONFIG, ROOT, 'silent')) ?? {};
if (!siteConfig) throw new Error(`${CONFIG} can't be read.`);
// As Vite resolves it: cssMinify, or else whether the build minifies at all.
const cssMinify = siteConfig.build?.cssMinify ?? (siteConfig.build?.minify === undefined ? true : Boolean(siteConfig.build.minify));
if (![true, 'esbuild'].includes(cssMinify) || siteConfig.esbuild !== undefined) {
  throw new Error('vite.config.ts now changes how the site minifies CSS (build.cssMinify, build.minify or esbuild): minify the plugin\'s copy the same way here.');
}

const site = await siteCss();
// As Vite's build minifies CSS with esbuild (its minifyCSS): for the build's CSS
// target, with characters kept as they are.
const { code: minifiedCss, warnings = [] } = await transformWithEsbuild(site.css, 'firehouse.css', {
  loader: 'css',
  minify: true,
  target: site.cssTarget || undefined,
  charset: 'utf8',
  sourcemap: false,
});
// Vite only prints these; here they stop the run, so a rule esbuild can't read as
// written never reaches the copy the plugin loads unseen.
if (warnings.length) {
  throw new Error(`esbuild warned while minifying:\n  ${warnings.map((warning) => warning.text).join('\n  ')}`);
}

const readable = `/*
 * The Firehouse plugin's stylesheet, readable. Generated by scripts/plugin-css.mjs from
 * the site's own CSS in src/: change those files, then run \`npm run plugin:css\`. The
 * plugin loads firehouse.min.css, the same rules minified, and this copy only under
 * SCRIPT_DEBUG.
 *
 * Every rule is scoped under .fh-app (WORDPRESS-PLAN.md rule 9), in the order the
 * site loads them. Left out: the static build's page shell, gov banner and footer,
 * which GSL's theme replaces; the explorer's page and the components only it uses, and
 * the coverage map, which arrive with them in Phase 5; the design system's components
 * no page renders; and the fonts, which the plugin loads on its own from fonts.css.
 */

${site.css}
`;

const minified = `/* The Firehouse plugin's stylesheet, minified. Generated by scripts/plugin-css.mjs from the site's own CSS in src/; firehouse.css holds the same rules, readable. */
${minifiedCss.trim()}
`;

const files = [
  ['firehouse.css', readable],
  ['firehouse.min.css', minified],
];

// Fail closed: anything a source stylesheet or a later change adds stops here.
// PostCSS reads each copy first, then a browser reads the exact text that would be written.
const problems = [];
const browser = await openBrowser();
try {
  for (const [name, css] of files) {
    const found = [...outputProblems(postcss.parse(css)), ...(await browserProblems(browser, css))];
    problems.push(...found.map((problem) => `${name}: ${problem}`));
  }
} finally {
  await browser.close();
}
if (problems.length) throw new Error(`The plugin's stylesheet would reach outside .fh-app or load something:\n  ${problems.join('\n  ')}`);

if (process.argv.includes('--check')) {
  const stale = files.filter(([name, css]) => {
    try {
      return readFileSync(resolve(DIR, name), 'utf8').replace(/\r\n/g, '\n') !== css;
    } catch {
      return true; // Missing counts as out of date.
    }
  });
  if (stale.length) {
    console.error(`${stale.map(([name]) => name).join(' and ')} ${stale.length === 1 ? 'is' : 'are'} out of date with src/. Run: npm run plugin:css`);
    process.exit(1);
  }
  console.log('firehouse.css and firehouse.min.css are up to date.');
} else {
  mkdirSync(DIR, { recursive: true });
  for (const [name, css] of files) {
    writeFileSync(resolve(DIR, name), css);
    console.log(`Wrote ${resolve(DIR, name)}: ${Buffer.byteLength(css)} bytes, ${gzipSync(css).length} compressed.`);
  }
}
