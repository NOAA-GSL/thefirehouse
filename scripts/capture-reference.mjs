#!/usr/bin/env node
/**
 * Captures the reference the WordPress plugin is built to match: an accessibility
 * snapshot and a screenshot of every route of the React site, in light and dark, at
 * desktop and phone width.
 *
 *   npm run build && npm run reference
 *
 * Writes:
 *  - reference/aria/<width>/<page>.yml — the accessibility tree (Playwright's ARIA
 *    snapshot), taken in the light theme. Committed: it's text, so a change to the
 *    site's structure shows up in review, and the plugin's pages are checked
 *    against it.
 *  - reference/screenshots/<theme>-<width>/<page>.png — full-page screenshots.
 *    Not committed (they're large); rerun this script on the same commit to get them
 *    back.
 *
 * It serves `dist/` with Vite's preview server and drives a browser already installed
 * on the machine (Edge by default; set REFERENCE_BROWSER=chrome for Chrome), so
 * nothing is downloaded. Motion is reduced so no screenshot lands mid-animation. The
 * map's basemap tiles still come from Esri, so screenshots of the map can differ
 * slightly between runs; the ARIA snapshots don't.
 */

import { mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { preview } from 'vite';
import { chromium } from 'playwright-core';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const OUT = resolve(ROOT, 'reference');
const read = (path) => JSON.parse(readFileSync(resolve(ROOT, path), 'utf8'));

const projects = read('src/content/data/projects.json').filter(
  (project) => project.published && project.irbApproved === true,
);
const topics = read('src/content/data/topics.json');

const PAGES = [
  ['home', '/'],
  ['projects', '/projects'],
  ['about', '/about'],
  ...projects.map((project) => [`project--${project.slug}`, `/projects/${project.slug}`]),
  ...topics.map((topic) => [`topic--${topic.key}`, `/topics/${topic.key}`]),
  ['not-found', '/no-such-page'],
];

const WIDTHS = { desktop: { width: 1280, height: 900 }, phone: { width: 390, height: 844 } };
const THEMES = ['light', 'dark'];

const server = await preview({ root: ROOT, logLevel: 'warn', preview: { port: 4317, strictPort: true } });
const origin = server.resolvedUrls.local[0].replace(/\/$/, '');
const browser = await chromium.launch({ channel: process.env.REFERENCE_BROWSER ?? 'msedge' });

try {
  rmSync(resolve(OUT, 'aria'), { recursive: true, force: true });
  rmSync(resolve(OUT, 'screenshots'), { recursive: true, force: true });

  for (const [widthName, viewport] of Object.entries(WIDTHS)) {
    for (const theme of THEMES) {
      const context = await browser.newContext({ viewport, reducedMotion: 'reduce' });
      // ThemeProvider reads the stored choice before the first paint.
      await context.addInitScript((value) => localStorage.setItem('firehouse-theme', value), theme);
      const page = await context.newPage();

      for (const [name, path] of PAGES) {
        await page.goto(`${origin}${path}`, { waitUntil: 'networkidle' });
        await page.evaluate(() => document.fonts.ready);

        const shots = resolve(OUT, 'screenshots', `${theme}-${widthName}`);
        mkdirSync(shots, { recursive: true });
        await page.screenshot({ path: resolve(shots, `${name}.png`), fullPage: true });

        if (theme === 'light') {
          const aria = resolve(OUT, 'aria', widthName);
          mkdirSync(aria, { recursive: true });
          writeFileSync(resolve(aria, `${name}.yml`), `${await page.locator('body').ariaSnapshot()}\n`);
        }
        console.log(`${widthName} ${theme} ${path}`);
      }
      await context.close();
    }
  }
} finally {
  await browser.close();
  await new Promise((done) => server.httpServer.close(done));
}
