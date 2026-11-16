#!/usr/bin/env node
/**
 * Takes the screenshots in the FireHouse guide (wp-content/plugins/firehouse/help/images/)
 * again, from the local WordPress (docker-compose.yml; BASE_URL, default
 * http://localhost:8090), after a FireHouse screen changes.
 *
 *   WP_COOKIE='wordpress_…=…; wordpress_logged_in_…=…' node scripts/help-screenshots.mjs
 *   … node scripts/help-screenshots.mjs settings import     only those
 *
 * Every screenshot is of the dashboard, so it needs a signed-in administrator: set
 * WP_COOKIE to that session's Cookie header, for example from the browser's developer
 * tools. Each is cropped to what its page of the guide is about. The toolbar (who is
 * signed in), notices from other plugins, and the footer (which names WordPress's
 * version) are hidden, so nothing about the site beyond the FireHouse shows; only the
 * FireHouse menu's own screenshot shows the menu, cropped to the FireHouse part.
 * The project and topic shown are the first published project and the topic in TOPIC.
 * Two need a site with a Workers AI key (needsKey): taking every screenshot skips them
 * on a site without one, and naming them fails there.
 *
 * The images go into a public repository and the plugin's zip, so nothing that isn't
 * public goes into them: the Projects list is the Published view, which lists no
 * survey submission or other draft, and a project's Private answers box (a submitter's
 * email, job title and sharing answers) is hidden, as publishing doesn't make those
 * public.
 *
 * Images are WebP, encoded by the browser at quality QUALITY, so the plugin's zip stays
 * small. Then run npm run plugin:help, since help.json records each image's size.
 *
 * It drives a browser already installed on the machine (Edge by default; set
 * REFERENCE_BROWSER=chrome for Chrome), as scripts/capture-reference.mjs does.
 */

import { writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const OUT = resolve(ROOT, 'wp-content/plugins/firehouse/help/images');
const BASE = (process.env.BASE_URL ?? 'http://localhost:8090').replace(/\/$/, '');
const BROWSER = process.env.REFERENCE_BROWSER === 'chrome' ? 'chrome' : 'msedge';
const TOPIC = 'warning';
const QUALITY = 0.9;
const PUBLISHED = '/wp-admin/edit.php?post_type=fh_project&post_status=publish';

const HIDE = `
  #wpadminbar, #wpfooter, #screen-meta-links, .notice, .update-nag, #wp-auth-check-wrap { display: none !important; }
  #firehouse-private { display: none !important; }
  html.wp-toolbar { padding-top: 0 !important; }
`;
const HIDE_MENU = `
  #adminmenumain { display: none !important; }
  #wpcontent { margin-left: 0 !important; padding: 0 16px !important; }
`;

/**
 * Each screenshot: the page it's on, what's in it (a selector, cropped no taller than
 * `height`), and whether the menu shows.
 */
const SHOTS = {
  'firehouse-menu': { path: PUBLISHED, element: '#menu-posts-fh_project', menu: true },
  'projects-list': { path: PUBLISHED, element: '#wpbody-content', height: 460 },
  'project-screen': { project: true, element: '#wpbody-content', height: 1150 },
  'topic-fields': { topic: TOPIC, element: '#wpbody-content', height: 760 },
  'synthesis-screen': { path: '/wp-admin/edit.php?post_type=fh_project&page=firehouse-synthesis', element: '#wpbody-content', height: 440 },
  // Only on a site with a Workers AI key (needsKey), and with drafts kept to show.
  'analyze-results': { path: '/wp-admin/edit.php?post_type=fh_project&page=firehouse-synthesis', element: '#firehouse-analyze', height: 700, needsKey: true },
  // The survey form's switch as a new site has it, whatever this copy's is.
  settings: { path: '/wp-admin/edit.php?post_type=fh_project&page=firehouse-settings', element: '#wpbody-content', height: 900, untick: '#firehouse-surveyOpen' },
  'settings-questions': { path: '/wp-admin/edit.php?post_type=fh_project&page=firehouse-settings', element: '#firehouse-questions', height: 560 },
  // Only on a site with a Workers AI key.
  'settings-prompt': { path: '/wp-admin/edit.php?post_type=fh_project&page=firehouse-settings', element: 'tr:has(#firehouse-synthesisPrompt)', height: 640, needsKey: true },
  import: { path: '/wp-admin/edit.php?post_type=fh_project&page=firehouse-import', element: '#wpbody-content', height: 320 },
  'landing-hero-fields': { landing: true, block: 'firehouse/hero', height: 600 },
};

function cookies() {
  const { hostname } = new URL(BASE);
  return (process.env.WP_COOKIE ?? '')
    .split(';')
    .map((pair) => pair.trim())
    .filter((pair) => pair.includes('='))
    .map((pair) => {
      const at = pair.indexOf('=');
      return { name: pair.slice(0, at), value: pair.slice(at + 1), domain: hostname, path: '/' };
    });
}

/** The first link in a list screen's table. */
async function firstRow(page, path) {
  await page.goto(`${BASE}${path}`);
  const href = await page.locator('#the-list a.row-title').first().getAttribute('href');
  if (!href) throw new Error(`nothing listed at ${path}`);
  return href;
}

/** The edit screen of the post a public FireHouse address shows, from its body class. */
async function editUrl(page, path, pattern) {
  const response = await page.request.get(`${BASE}${path}`);
  const id = (await response.text()).match(pattern)?.[1];
  if (!id) throw new Error(`nothing at ${path}`);
  return `${BASE}/wp-admin/post.php?post=${id}&action=edit`;
}

const topicUrl = (page, key) => editUrl(page, `/firehouse/topics/${key}/`, /\bpostid-(\d+)/);
const landingUrl = (page) => editUrl(page, '/firehouse/', /\bpage-id-(\d+)/);

/** A PNG screenshot, re-encoded as WebP by the browser. */
async function webp(page, png) {
  const data = await page.evaluate(
    async ([src, quality]) => {
      const image = new Image();
      image.src = src;
      await image.decode();
      const canvas = document.createElement('canvas');
      canvas.width = image.naturalWidth;
      canvas.height = image.naturalHeight;
      canvas.getContext('2d').drawImage(image, 0, 0);
      return canvas.toDataURL('image/webp', quality);
    },
    [`data:image/png;base64,${png.toString('base64')}`, QUALITY],
  );
  if (!data.startsWith('data:image/webp')) throw new Error('the browser did not encode WebP');
  return Buffer.from(data.slice(data.indexOf(',') + 1), 'base64');
}

async function shoot(page, name, shot, named) {
  let url = `${BASE}${shot.path ?? ''}`;
  if (shot.project) url = await firstRow(page, PUBLISHED);
  if (shot.topic) url = await topicUrl(page, shot.topic);
  if (shot.landing) url = await landingUrl(page);
  await page.goto(url, { waitUntil: 'load' });
  if (page.url().includes('wp-login.php')) throw new Error('WP_COOKIE is not a signed-in session');
  await page.addStyleTag({ content: HIDE + (shot.menu ? '' : HIDE_MENU) });
  // Only in the picture: nothing is saved.
  if (shot.untick) await page.locator(shot.untick).evaluate((box) => (box.checked = false));

  let box;
  if (shot.block) {
    await page.waitForSelector('iframe[name="editor-canvas"]', { timeout: 60000 });
    // The block editor's welcome guide, if it opens, would cover the page.
    await page.evaluate(() => window.wp?.data?.dispatch('core/preferences')?.set('core/edit-post', 'welcomeGuide', false));
    await page.keyboard.press('Escape');
    const block = page.frameLocator('iframe[name="editor-canvas"]').locator(`[data-type="${shot.block}"]`).first();
    await block.waitFor();
    await page.waitForTimeout(800);
    box = await block.boundingBox();
  } else {
    await page.waitForTimeout(300);
    // A section only a site with a Workers AI key has is skipped when every screenshot
    // is taken, and fails when it's asked for by name.
    if (shot.needsKey && !named && !(await page.locator(shot.element).count())) {
      console.log(`skipped ${name}: the site has no Workers AI key`);
      return;
    }
    box = await page.locator(shot.element).first().boundingBox();
  }
  if (!box) throw new Error(`${name}: nothing to crop to`);
  const scrollY = await page.evaluate(() => window.scrollY);
  const margin = 12;
  const y = Math.max(0, box.y + scrollY - margin);
  const height = box.height + 2 * margin;
  const png = await page.screenshot({
    fullPage: !shot.block,
    clip: {
      x: Math.max(0, box.x - margin),
      y: shot.block ? Math.max(0, box.y - margin) : y,
      width: box.width + 2 * margin,
      height: shot.height ? Math.min(shot.height, height) : height,
    },
  });
  writeFileSync(resolve(OUT, `${name}.webp`), await webp(page, png));
  console.log(`help/images/${name}.webp`);
}

async function main() {
  const wanted = process.argv.slice(2);
  for (const name of wanted) if (!SHOTS[name]) throw new Error(`no screenshot is called ${name}`);
  const signedIn = cookies();
  if (!signedIn.length) throw new Error("set WP_COOKIE to a signed-in administrator's session");

  const browser = await chromium.launch({ channel: BROWSER });
  try {
    const context = await browser.newContext({
      viewport: { width: 1280, height: 950 },
      reducedMotion: 'reduce',
      colorScheme: 'light',
    });
    await context.addCookies(signedIn);
    const page = await context.newPage();
    for (const [name, shot] of Object.entries(SHOTS)) {
      if (!wanted.length || wanted.includes(name)) await shoot(page, name, shot, wanted.includes(name));
    }
  } finally {
    await browser.close();
  }
}

await main();
