#!/usr/bin/env node
/**
 * Compares the WordPress plugin's pages with the reference the React site captured
 * (`npm run reference`): the accessibility tree of each page's main landmark, at
 * desktop and phone width, in the light theme, against reference/aria/<width>/<page>.yml.
 *
 *   npm run plugin:compare
 *   PLUGIN_URL=http://localhost:8090 npm run plugin:compare
 *
 * It needs a WordPress running the plugin inside GSL's theme: the repository's
 * docker-compose stack, set up with `bin/wp-init` (README, The WordPress plugin). So
 * it isn't part of CI. A page the plugin hasn't built yet shows the frame's placeholder,
 * or answers 404 where WordPress has no page there yet; it is listed as not built, not
 * compared.
 *
 * The two sites' addresses differ only by the plugin's /firehouse/ prefix and
 * WordPress's trailing slash, so both come off the plugin's links and its quoted
 * 404 address before comparing. Anything else that differs is reported, line by line,
 * and the script exits with an error.
 */

import { readdirSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const ORIGIN = (process.env.PLUGIN_URL ?? 'http://localhost:8090').replace(/\/$/, '');
const PREFIX = '/firehouse';
const WIDTHS = { desktop: { width: 1280, height: 900 }, phone: { width: 390, height: 844 } };
const PLACEHOLDER = 'This page is being built.';

/**
 * The reference pages the plugin has built: a name, or a prefix ending in `--` for a
 * kind of page (`topic--`). Each one must be there and match; the rest are listed as
 * not built yet. Each Phase 4 page adds itself here.
 */
export const BUILT = ['not-found'];

/** Whether a reference page is one the plugin has built. */
export function isBuilt(name, built = BUILT) {
  return built.some((entry) => (entry.endsWith('--') ? name.startsWith(entry) : name === entry));
}

/** The plugin's address for a reference page. */
function pluginPath(name) {
  if (name === 'home') return `${PREFIX}/`;
  if (name === 'not-found') return `${PREFIX}/no-such-page/`;
  const [kind, key] = name.split('--');
  if (kind === 'project') return `${PREFIX}/projects/${key}/`;
  if (kind === 'topic') return `${PREFIX}/topics/${key}/`;
  return `${PREFIX}/${name}/`;
}

/** The main landmark's lines in an ARIA snapshot, from `- main` to the next sibling. */
export function mainOf(snapshot) {
  const lines = snapshot.split('\n');
  const start = lines.findIndex((line) => /^\s*- main\b/.test(line));
  if (start < 0) return [];
  const indent = lines[start].search(/\S/);
  const end = lines.findIndex((line, i) => i > start && line.trim() !== '' && line.search(/\S/) <= indent);
  return lines.slice(start, end < 0 ? undefined : end).map((line) => line.slice(indent)).filter((line) => line.trim() !== '');
}

/**
 * One of the plugin's addresses as the React site writes it: no origin, no /firehouse
 * prefix, no trailing slash. Anything else, a link to one of the theme's own pages
 * included, is left as it is, so it can't pass for a Firehouse link.
 */
export function siteAddress(value, origin = ORIGIN) {
  const path = value.startsWith(origin) ? value.slice(origin.length) : value;
  if (path !== PREFIX && !path.startsWith(`${PREFIX}/`)) return value;
  const address = path.slice(PREFIX.length) || '/';
  return address.length > 1 && address.endsWith('/') ? address.slice(0, -1) : address;
}

/** The plugin's snapshot lines with its addresses written as the site's. */
export function normalize(lines, origin = ORIGIN) {
  return lines.map((line) => {
    const match = line.match(/^(\s*- (?:\/url|code): )("?)(.*?)\2$/);
    return match ? `${match[1]}${match[2]}${siteAddress(match[3], origin)}${match[2]}` : line;
  });
}

/** The differing lines of two lists, as a short unified diff. */
export function diff(expected, actual) {
  const n = expected.length;
  const m = actual.length;
  const lcs = Array.from({ length: n + 1 }, () => new Array(m + 1).fill(0));
  for (let i = n - 1; i >= 0; i--) {
    for (let j = m - 1; j >= 0; j--) {
      lcs[i][j] = expected[i] === actual[j] ? lcs[i + 1][j + 1] + 1 : Math.max(lcs[i + 1][j], lcs[i][j + 1]);
    }
  }
  const out = [];
  let i = 0;
  let j = 0;
  while (i < n || j < m) {
    if (i < n && j < m && expected[i] === actual[j]) {
      i++;
      j++;
    } else if (j < m && (i >= n || lcs[i][j + 1] >= lcs[i + 1][j])) {
      out.push(`+ ${actual[j++]}`);
    } else {
      out.push(`- ${expected[i++]}`);
    }
  }
  return out;
}

async function main() {
  const names = readdirSync(resolve(ROOT, 'reference/aria/desktop'))
    .filter((file) => file.endsWith('.yml'))
    .map((file) => file.slice(0, -'.yml'.length));
  const browser = await chromium.launch({ channel: process.env.REFERENCE_BROWSER ?? 'msedge' });
  const notBuilt = new Set(names.filter((name) => !isBuilt(name)));
  let compared = 0;
  let failed = 0;
  const fail = (message) => {
    failed++;
    console.log(message);
  };

  try {
    for (const [widthName, viewport] of Object.entries(WIDTHS)) {
      const context = await browser.newContext({ viewport, reducedMotion: 'reduce' });
      // GSL's light/dark switch keeps its choice here; the reference is the light theme.
      await context.addInitScript(() => localStorage.setItem('gsl-theme-preference', 'light'));
      const page = await context.newPage();
      for (const name of names.filter((n) => isBuilt(n))) {
        const path = pluginPath(name);
        compared++;
        const response = await page.goto(`${ORIGIN}${path}`, { waitUntil: 'networkidle' });
        // The not-found page must answer 404, or search engines index every bad link;
        // every other built page must answer 200 and be more than the placeholder.
        const status = response?.status();
        if (status !== (name === 'not-found' ? 404 : 200)) {
          fail(`FAIL  ${widthName} ${name} (${path}): answered ${status}`);
          continue;
        }
        if ((await page.locator('main').innerText()).includes(PLACEHOLDER)) {
          fail(`FAIL  ${widthName} ${name} (${path}): still the placeholder`);
          continue;
        }
        const expected = mainOf(readFileSync(resolve(ROOT, 'reference/aria', widthName, `${name}.yml`), 'utf8'));
        const actual = normalize(mainOf(await page.locator('main').ariaSnapshot()));
        const changes = diff(expected, actual);
        if (changes.length === 0) {
          console.log(`ok    ${widthName} ${name}`);
        } else {
          fail(`DIFF  ${widthName} ${name} (${path}): - reference, + plugin\n    ${changes.join('\n    ')}`);
        }
      }
      await context.close();
    }
  } finally {
    await browser.close();
  }

  if (notBuilt.size > 0) console.log(`\nNot built yet: ${[...notBuilt].join(', ')}`);
  console.log(`\n${compared - failed} of ${compared} comparisons match.`);
  if (failed > 0 || compared === 0) process.exitCode = 1;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  await main();
}
