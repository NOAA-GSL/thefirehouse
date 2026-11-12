#!/usr/bin/env node
/**
 * Writes the plugin's icons, logos and link-preview image from the site's own.
 *
 *   node scripts/plugin-assets.mjs           write them
 *   node scripts/plugin-assets.mjs --check   fail if the committed copies are out of date
 *
 * - `wp-content/plugins/firehouse/assets/icons/<name>.svg`: every icon in the site's
 *   registry (`ICON_NAMES` in src/design-system/Icon.tsx), drawn by the site's own
 *   `Icon` component through Vite. The drawing inside is exactly what the site renders;
 *   only the outer <svg> changes. It drops what belongs to one use of an icon (class,
 *   style, `aria-hidden`, `focusable`) and keeps Lucide's 24×24 size, which the
 *   plugin replaces with each use's size where it inlines the icon, as `Icon` does.
 *   Each icon must hold only what shared/icon-svg.json allows, which
 *   tests/IconsTest.php checks again on the files that ship. Lucide's licence goes
 *   beside them as LICENSE.txt.
 * - `wp-content/plugins/firehouse/assets/logos/`: the images `BrandMark` imports,
 *   read from the component and copied unchanged. The NOAA and GSL logos stay with the
 *   static build; GSL's header carries its own.
 * - `wp-content/plugins/firehouse/assets/images/og-image.png`: the site's link-preview
 *   image (`public/og-image.png`), copied unchanged, which Firehouse pages name in their
 *   Open Graph tags (includes/class-head.php).
 *
 * Each folder holds exactly these files, so an icon dropped from the registry goes too.
 */

import { mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { fileURLToPath } from 'node:url';
import { dirname, join, resolve } from 'node:path';
import { createServer } from 'vite';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const ASSETS = resolve(ROOT, 'wp-content/plugins/firehouse/assets');
const RULES = JSON.parse(readFileSync(resolve(ROOT, 'wp-content/plugins/firehouse/shared/icon-svg.json'), 'utf8'));

/**
 * What in an icon's SVG falls outside shared/icon-svg.json: an element, attribute or
 * value it doesn't allow, or anything that isn't a tag (text, a comment, CDATA).
 */
function iconProblems(svg) {
  const problems = [];
  const value = new RegExp(RULES.value);
  const rest = svg.replace(/<\/?([^\s/>]+)((?:\s+[^\s=>]+="[^"]*")*)\s*\/?>/g, (tag, element, attributes) => {
    if (!RULES.elements.includes(element)) problems.push(`<${element}>`);
    for (const [, name, text] of attributes.matchAll(/\s+([^\s=>]+)="([^"]*)"/g)) {
      if (!RULES.attributes.includes(name)) problems.push(`${element} ${name}`);
      else if (name === 'xmlns' ? text !== RULES.namespace : !value.test(text)) problems.push(`${element} ${name}="${text}"`);
    }
    return '';
  });
  if (rest.trim()) problems.push(`text outside the tags: ${rest.trim().slice(0, 60)}`);
  if (!svg.startsWith('<svg ')) problems.push('it doesn’t start with <svg>');
  return problems;
}
// The images BrandMark imports, read from the component so a new one comes along.
const LOGOS = [...readFileSync(resolve(ROOT, 'src/design-system/BrandMark.tsx'), 'utf8').matchAll(/from '\.\.\/assets\/logos\/([\w.-]+\.png)'/g)].map(
  ([, name]) => name,
);
if (LOGOS.length === 0) throw new Error('BrandMark.tsx imports no logo, so there is nothing to copy.');

/** Every file this script owns, by its path under assets/. */
const files = new Map();

// Vite's dependency cache goes in a folder of its own, removed afterwards, so this
// never writes into node_modules/.vite, which `npm run dev` uses.
const cacheDir = mkdtempSync(join(tmpdir(), 'firehouse-assets-'));
const server = await createServer({
  root: ROOT,
  configFile: resolve(ROOT, 'vite.config.ts'),
  cacheDir,
  logLevel: 'error',
  appType: 'custom',
  server: { middlewareMode: true, hmr: false, watch: null },
});
try {
  const { Icon, ICON_NAMES } = await server.ssrLoadModule('/src/design-system/Icon.tsx');
  const { createElement } = await import('react');
  const { renderToStaticMarkup } = await import('react-dom/server');
  for (const name of ICON_NAMES) {
    if (!new RegExp(RULES.name).test(name)) throw new Error(`"${name}" can't be a file name.`);
    const rendered = renderToStaticMarkup(createElement(Icon, { name, size: 24 }));
    // Only the outer <svg> loses its per-use attributes: a <rect> inside needs its size.
    const svg = rendered.replace(/^<svg[^>]*>/, (tag) => tag.replace(/ (?:class|style|aria-hidden|focusable)="[^"]*"/g, ''));
    const drawing = (markup) => markup.slice(markup.indexOf('>') + 1);
    if (drawing(svg) !== drawing(rendered)) throw new Error(`The ${name} icon's drawing changed on its way to the file.`);
    const problems = iconProblems(svg);
    if (problems.length) throw new Error(`The ${name} icon holds what shared/icon-svg.json doesn't allow: ${problems.join('; ')}`);
    files.set(`icons/${name}.svg`, Buffer.from(`${svg}\n`));
  }
} finally {
  await server.close();
  rmSync(cacheDir, { recursive: true, force: true });
}
// Lucide's licence asks for its notice in every copy of the icons.
files.set('icons/LICENSE.txt', readFileSync(resolve(ROOT, 'node_modules/lucide-react/LICENSE')));
for (const name of LOGOS) files.set(`logos/${name}`, readFileSync(resolve(ROOT, 'src/assets/logos', name)));
files.set('images/og-image.png', readFileSync(resolve(ROOT, 'public/og-image.png')));

const folders = [...new Set([...files.keys()].map((path) => path.split('/')[0]))];

/** Text files compare without regard to line endings, which a checkout may change. */
function sameFile(present, expected, path) {
  if (!/\.(?:svg|txt)$/.test(path)) return present.equals(expected);
  const text = (bytes) => bytes.toString('utf8').replace(/\r\n/g, '\n');
  return text(present) === text(expected);
}

if (process.argv.includes('--check')) {
  const stale = [];
  for (const folder of folders) {
    let present = [];
    try {
      present = readdirSync(resolve(ASSETS, folder)).map((name) => `${folder}/${name}`);
    } catch {
      // A missing folder is out of date.
    }
    for (const path of present) if (!files.has(path)) stale.push(`${path} shouldn't be there`);
    for (const [path, bytes] of files) {
      if (!path.startsWith(`${folder}/`)) continue;
      if (!present.includes(path)) stale.push(`${path} is missing`);
      else if (!sameFile(readFileSync(resolve(ASSETS, path)), bytes, path)) stale.push(`${path} differs`);
    }
  }
  if (stale.length) {
    console.error(`The plugin's icons, logos and preview image are out of date with the site's:\n  ${stale.join('\n  ')}\nRun: npm run plugin:assets`);
    process.exit(1);
  }
  console.log(`Icons, logos and the preview image are up to date: ${files.size} files.`);
} else {
  for (const folder of folders) {
    rmSync(resolve(ASSETS, folder), { recursive: true, force: true });
    mkdirSync(resolve(ASSETS, folder), { recursive: true });
  }
  for (const [path, bytes] of files) writeFileSync(resolve(ASSETS, path), bytes);
  console.log(`Wrote ${files.size} files in ${folders.map((folder) => `assets/${folder}/`).join(' and ')}.`);
}
