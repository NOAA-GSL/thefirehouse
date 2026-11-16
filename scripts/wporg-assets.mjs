#!/usr/bin/env node
/**
 * Makes the images WordPress.org's plugin directory shows beside a plugin, for the day
 * the plugin is submitted there (wordpress-org/README.md says what that waits on).
 *
 *   node scripts/wporg-assets.mjs                 the icon, the banner and the screenshots
 *   node scripts/wporg-assets.mjs --images-only   the icon and the banner only
 *
 * Writes wordpress-org/assets/, which goes to the plugin's SVN assets/ folder, not into
 * the plugin's zip:
 *  - icon-128x128.png, icon-256x256.png: the FireHouse mark, from its original
 *    (src/assets/logos/source/firehouse-mark-original.png), on white.
 *  - banner-772x250.png, banner-1544x500.png: the wordmark, from its original, on
 *    white over the link-preview image's ember-to-teal rule (public/og-image.png).
 *  - screenshot-1.png to screenshot-7.png: the captions are readme.txt's
 *    == Screenshots ==, in the same order as SHOTS below.
 *
 * The screenshots come from the local WordPress (docker-compose.yml; BASE_URL, default
 * http://localhost:8090) at 1280 pixels wide, with motion reduced. Each is cropped to
 * the FireHouse area: GSL's header and footer, and the dashboard's menu, toolbar,
 * notices and footer (which names WordPress's version), are left out, so nothing about
 * GSL's site beyond the FireHouse pages shows. The two dashboard screens need a signed-in
 * administrator: set WP_COOKIE to that session's Cookie header, for example from the
 * browser's developer tools. Without it, the script makes the public pages' screenshots
 * and says which it skipped. The project and topic shown are the first in PROJECT and
 * TOPIC below; the map's basemap tiles come from Esri, so its screenshot can differ a
 * little between runs.
 *
 * It drives a browser already installed on the machine (Edge by default; set
 * REFERENCE_BROWSER=chrome for Chrome), as scripts/capture-reference.mjs does.
 */

import { mkdirSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const OUT = resolve(ROOT, 'wordpress-org/assets');
const BASE = (process.env.BASE_URL ?? 'http://localhost:8090').replace(/\/$/, '');
const BROWSER = process.env.REFERENCE_BROWSER === 'chrome' ? 'chrome' : 'msedge';
const IMAGES_ONLY = process.argv.includes('--images-only');

const PROJECT = 'california-tribal-advisors-share-contemporary-environmental';
const TOPIC = 'warning';

const dataUrl = (path, type) =>
  `data:${type};base64,${readFileSync(resolve(ROOT, path)).toString('base64')}`;
const MARK = dataUrl('src/assets/logos/source/firehouse-mark-original.png', 'image/png');
const WORDMARK = dataUrl('src/assets/logos/source/firehouse-wordmark-original.png', 'image/png');

// The link-preview image's rule: 12 of its 630 pixels, ember to teal.
const RULE = 'linear-gradient(90deg, #f2762e, #22bed6)';

const icon = (size) => `
  <body style="margin:0;width:${size}px;height:${size}px;background:#fff;display:grid;place-items:center">
    <img src="${MARK}" alt="" style="width:${Math.round(size * 0.8)}px;height:auto">
  </body>`;

// WordPress.org prints the plugin's name over the banner's lower left, so the wordmark
// sits to the right of centre and clear of the bottom.
const banner = (width, height) => `
  <body style="margin:0;width:${width}px;height:${height}px;background:#fff;position:relative;overflow:hidden">
    <img src="${WORDMARK}" alt=""
      style="position:absolute;right:${Math.round(width * 0.08)}px;top:${Math.round(height * 0.17)}px;width:${Math.round(width * 0.56)}px;height:auto">
    <div style="position:absolute;left:0;right:0;bottom:0;height:${Math.round(height * 0.04)}px;background:${RULE}"></div>
  </body>`;

/**
 * The screenshots, in readme.txt's order. `top` is where the crop starts (the FireHouse
 * bar, so GSL's header is left out) and `height` how far it runs; `element` crops to one
 * element instead, with a margin of the page's background, and no taller than `height`
 * when it's given. `admin` shots need WP_COOKIE.
 */
const SHOTS = [
  { file: 'screenshot-1.png', path: '/firehouse/', top: '.fh-header', height: 900 },
  { file: 'screenshot-2.png', path: '/firehouse/', element: '.fh-coverage-section' },
  { file: 'screenshot-3.png', path: '/firehouse/projects/', top: '.fh-header', height: 1000 },
  { file: 'screenshot-4.png', path: `/firehouse/projects/${PROJECT}/`, top: '.fh-header', height: 1100 },
  { file: 'screenshot-5.png', path: `/firehouse/topics/${TOPIC}/`, top: '.fh-header', height: 1000 },
  { file: 'screenshot-6.png', path: '/wp-admin/edit.php?post_type=fh_project', admin: 'project', element: '#wpbody-content', height: 1150 },
  { file: 'screenshot-7.png', path: '/wp-admin/admin.php?page=firehouse-synthesis', admin: true, element: '#wpbody-content' },
];

// Left out of the dashboard's screenshots: who is signed in, GSL's menu and plugins'
// notices, and the footer with WordPress's version.
const ADMIN_HIDE = `
  #wpadminbar, #adminmenumain, #wpfooter, #screen-meta-links, .notice, .update-nag,
  #wp-auth-check-wrap { display: none !important; }
  html.wp-toolbar { padding-top: 0 !important; }
  #wpcontent { margin-left: 0 !important; padding: 0 16px !important; }
`;

function cookies() {
  const header = process.env.WP_COOKIE ?? '';
  const { hostname } = new URL(BASE);
  return header
    .split(';')
    .map((pair) => pair.trim())
    .filter(Boolean)
    .map((pair) => {
      const at = pair.indexOf('=');
      return { name: pair.slice(0, at), value: pair.slice(at + 1), domain: hostname, path: '/' };
    });
}

async function render(page, html, file, width, height) {
  await page.setViewportSize({ width, height });
  await page.setContent(html);
  await page.evaluate(() => Promise.all([...document.images].map((image) => image.decode())));
  await page.screenshot({ path: resolve(OUT, file), omitBackground: false });
  console.log(`wordpress-org/assets/${file}`);
}

/** The first project's edit screen, found from the projects list. */
async function projectEditUrl(page) {
  await page.goto(`${BASE}/wp-admin/edit.php?post_type=fh_project&post_status=publish`);
  const href = await page.locator('#the-list a.row-title').first().getAttribute('href');
  if (!href) throw new Error('no published project in the projects list');
  return href;
}

async function shoot(page, shot) {
  let url = `${BASE}${shot.path}`;
  if (shot.admin === 'project') url = await projectEditUrl(page);
  await page.goto(url, { waitUntil: 'networkidle' });
  if (page.url().includes('wp-login.php')) throw new Error('WP_COOKIE is not a signed-in session');
  if (shot.admin) await page.addStyleTag({ content: ADMIN_HIDE });
  // Let the map draw and its tiles settle.
  await page.waitForTimeout(shot.path === '/firehouse/' ? 1500 : 300);

  const path = resolve(OUT, shot.file);
  if (shot.element) {
    const box = await page.locator(shot.element).first().boundingBox();
    if (!box) throw new Error(`${shot.element} isn't on ${shot.path}`);
    const scrollY = await page.evaluate(() => window.scrollY);
    const margin = 16;
    const x = Math.max(0, box.x - margin);
    const y = Math.max(0, box.y + scrollY - margin);
    const height = box.height + 2 * margin;
    await page.screenshot({
      path,
      fullPage: true,
      clip: { x, y, width: box.width + 2 * margin, height: shot.height ? Math.min(shot.height, height) : height },
    });
  } else {
    const box = await page.locator(shot.top).first().boundingBox();
    if (!box) throw new Error(`${shot.top} isn't on ${shot.path}`);
    const scrollY = await page.evaluate(() => window.scrollY);
    const full = await page.evaluate(() => document.documentElement.scrollHeight);
    const y = box.y + scrollY;
    await page.screenshot({
      path,
      fullPage: true,
      clip: { x: 0, y, width: 1280, height: Math.min(shot.height, full - y) },
    });
  }
  console.log(`wordpress-org/assets/${shot.file}`);
}

async function main() {
  mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ channel: BROWSER });
  try {
    const images = await browser.newPage();
    await render(images, icon(128), 'icon-128x128.png', 128, 128);
    await render(images, icon(256), 'icon-256x256.png', 256, 256);
    await render(images, banner(772, 250), 'banner-772x250.png', 772, 250);
    await render(images, banner(1544, 500), 'banner-1544x500.png', 1544, 500);
    if (IMAGES_ONLY) return;

    const context = await browser.newContext({
      viewport: { width: 1280, height: 900 },
      reducedMotion: 'reduce',
      colorScheme: 'light',
    });
    const signedIn = cookies();
    if (signedIn.length) await context.addCookies(signedIn);
    const page = await context.newPage();
    for (const shot of SHOTS) {
      if (shot.admin && !signedIn.length) {
        console.warn(`skipped ${shot.file} (${shot.path}): set WP_COOKIE to a signed-in administrator's session`);
        continue;
      }
      await shoot(page, shot);
    }
  } finally {
    await browser.close();
  }
}

await main();
