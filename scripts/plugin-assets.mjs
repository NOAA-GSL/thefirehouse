#!/usr/bin/env node
/**
 * Writes the plugin's icons and logos from the site's own.
 *
 *   node scripts/plugin-assets.mjs           write them
 *   node scripts/plugin-assets.mjs --check   fail if the committed copies are out of date
 *
 * - `wp-content/plugins/firehouse/assets/icons/<name>.svg`: every icon in the site's
 *   registry (`ICON_NAMES` in src/design-system/Icon.tsx), drawn by the site's own
 *   `Icon` component through Vite, so each is the SVG the site renders. The attributes
 *   that belong to one use of an icon (size, class, style, `aria-hidden`, `focusable`)
 *   are left off: the plugin adds them where it inlines the icon, as `Icon` does.
 * - `wp-content/plugins/firehouse/assets/logos/`: the four Firehouse images `BrandMark`
 *   shows, copied unchanged. The NOAA and GSL logos stay with the static build; GSL's
 *   header carries its own.
 *
 * Each folder holds exactly these files, so an icon dropped from the registry goes too.
 */

import { mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { createServer } from 'vite';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const ASSETS = resolve(ROOT, 'wp-content/plugins/firehouse/assets');
const LOGOS = ['firehouse-mark.png', 'firehouse-mark-on-dark.png', 'firehouse-wordmark.png', 'firehouse-wordmark-on-dark.png'];

/** Every file this script owns, by its path under assets/. */
const files = new Map();

const server = await createServer({
  root: ROOT,
  configFile: resolve(ROOT, 'vite.config.ts'),
  logLevel: 'error',
  appType: 'custom',
  server: { middlewareMode: true, hmr: false },
});
try {
  const { Icon, ICON_NAMES } = await server.ssrLoadModule('/src/design-system/Icon.tsx');
  const { createElement } = await import('react');
  const { renderToStaticMarkup } = await import('react-dom/server');
  for (const name of ICON_NAMES) {
    const svg = renderToStaticMarkup(createElement(Icon, { name }))
      .replace(/ (?:class|style|aria-hidden|focusable|width|height)="[^"]*"/g, '')
      .replace('<svg ', '<svg width="24" height="24" ');
    if (!/^<svg width="24" height="24" xmlns="http:\/\/www\.w3\.org\/2000\/svg" viewBox="0 0 24 24"[^>]*>[\s\S]+<\/svg>$/.test(svg)) {
      throw new Error(`The ${name} icon didn't render as the expected SVG:\n${svg}`);
    }
    files.set(`icons/${name}.svg`, Buffer.from(`${svg}\n`));
  }
} finally {
  await server.close();
}
for (const name of LOGOS) files.set(`logos/${name}`, readFileSync(resolve(ROOT, 'src/assets/logos', name)));

const folders = [...new Set([...files.keys()].map((path) => path.split('/')[0]))];

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
      else if (!readFileSync(resolve(ASSETS, path)).equals(bytes)) stale.push(`${path} differs`);
    }
  }
  if (stale.length) {
    console.error(`The plugin's icons and logos are out of date with src/:\n  ${stale.join('\n  ')}\nRun: npm run plugin:assets`);
    process.exit(1);
  }
  console.log(`Icons and logos are up to date: ${files.size} files.`);
} else {
  for (const folder of folders) {
    rmSync(resolve(ASSETS, folder), { recursive: true, force: true });
    mkdirSync(resolve(ASSETS, folder), { recursive: true });
  }
  for (const [path, bytes] of files) writeFileSync(resolve(ASSETS, path), bytes);
  console.log(`Wrote ${files.size} files in ${folders.map((folder) => `assets/${folder}/`).join(' and ')}.`);
}
