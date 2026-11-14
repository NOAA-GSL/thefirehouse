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
 * compared. A page built but not yet comparable (UNCOMPARED) is listed with why.
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
 * not built yet. Each page adds itself here once it is built.
 */
export const BUILT = ['about', 'home', 'not-found', 'project--', 'projects', 'topic--'];

/**
 * Pages the plugin has built that can't match the reference yet, with why: listed, not
 * compared. Each comes off when it can match.
 */
export const UNCOMPARED = {};

/**
 * Parts of a built page that come in a later phase, by the region's name in the
 * reference: they're left out of the reference before comparing, and listed. The
 * landing page's coverage map comes in Phase 5.
 */
export const LATER = { home: ['Where the research comes from'] };

/** A snapshot's lines without each named region and everything inside it. */
export function withoutRegions(lines, names = []) {
  const out = [];
  let skipping = -1;
  for (const line of lines) {
    const indent = line.search(/\S/);
    if (skipping >= 0 && indent > skipping) continue;
    skipping = -1;
    const region = line.match(/^\s*- region "(.*)"/);
    if (region && names.includes(region[1])) {
      skipping = indent;
      continue;
    }
    out.push(line);
  }
  return out;
}

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
  // The trailing slash comes off the path itself, before any query or fragment.
  const cut = address.search(/[?#]/);
  const base = cut < 0 ? address : address.slice(0, cut);
  return (base.length > 1 && base.endsWith('/') ? base.slice(0, -1) : base) + (cut < 0 ? '' : address.slice(cut));
}

/**
 * A link's destination, for comparing: a Firehouse address as the site writes it, and a
 * root-relative link anywhere else on the WordPress site made absolute. On the site,
 * `/projects` is the explorer; on WordPress it would be one of the theme's own pages,
 * so it mustn't match the reference.
 */
export function siteLink(value, origin = ORIGIN) {
  const address = siteAddress(value, origin);
  if (address !== value) return address;
  return value.startsWith('/') && !value.startsWith('//') ? `${origin}${value}` : value;
}

/**
 * The plugin's snapshot lines with its addresses written as the site's: links through
 * `siteLink`, and the 404's quoted address, which is text, through `siteAddress`.
 */
export function normalize(lines, origin = ORIGIN) {
  return lines.map((line) => {
    const match = line.match(/^(\s*- (\/url|code): )("?)(.*?)\3$/);
    if (!match) return line;
    const address = match[2] === '/url' ? siteLink(match[4], origin) : siteAddress(match[4], origin);
    return `${match[1]}${match[3]}${address}${match[3]}`;
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
  const notBuilt = new Set(names.filter((name) => !isBuilt(name) && !(name in UNCOMPARED)));
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
        const later = LATER[name] ?? [];
        const expected = withoutRegions(mainOf(readFileSync(resolve(ROOT, 'reference/aria', widthName, `${name}.yml`), 'utf8')), later);
        const actual = normalize(mainOf(await page.locator('main').ariaSnapshot()));
        const changes = diff(expected, actual);
        if (changes.length === 0) {
          console.log(`ok    ${widthName} ${name}${later.length > 0 ? ` (without, for later: ${later.join(', ')})` : ''}`);
        } else {
          fail(`DIFF  ${widthName} ${name} (${path}): - reference, + plugin\n    ${changes.join('\n    ')}`);
        }
      }
      await context.close();
    }
  } finally {
    await browser.close();
  }

  for (const [name, why] of Object.entries(UNCOMPARED)) console.log(`\nBuilt, not compared yet: ${name}, ${why}.`);
  if (notBuilt.size > 0) console.log(`\nNot built yet: ${[...notBuilt].join(', ')}`);
  console.log(`\n${compared - failed} of ${compared} comparisons match.`);
  if (failed > 0 || compared === 0) process.exitCode = 1;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  await main();
}
