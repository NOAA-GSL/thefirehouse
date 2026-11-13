import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { EXPLORER, UNUSED } from './plugin-css-sources.mjs';

/**
 * The plugin's stylesheet leaves some of the site's stylesheets out until a page the
 * plugin renders needs them (scripts/plugin-css-sources.mjs): the explorer's, until
 * Phase 5, and those of the design system's components no page renders. A class only
 * those style would render unstyled in the plugin, focus rings included, so the
 * plugin's markup mustn't use one until its file is taken off the list.
 */
const root = (path) => fileURLToPath(new URL(`../${path}`, import.meta.url));
const PLUGIN = 'wp-content/plugins/firehouse/';

/** The `fh-` classes a stylesheet's selectors name, comments aside. */
const classes = (css) => new Set([...css.replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/\.(fh-[\w-]+)/g)].map((match) => match[1]));

const styled = new Set(
  ['assets/css/firehouse.css', 'assets/css/frame.css'].flatMap((file) => [...classes(readFileSync(root(PLUGIN + file), 'utf8'))]),
);
/** For each left-out stylesheet, the classes only it and the other left-out ones style. */
const leftOutFiles = [...EXPLORER, ...UNUSED].map((file) => [file, [...classes(readFileSync(root(file), 'utf8'))].filter((name) => !styled.has(name))]);
const leftOutOnly = [...new Set(leftOutFiles.flatMap(([, names]) => names))].sort();

/** What the plugin prints: its PHP, scripts and JSON, not its tests, assets or dependencies. */
const markup = readdirSync(root(PLUGIN), { recursive: true })
  .map((path) => String(path).replace(/\\/g, '/'))
  .filter((path) => /\.(php|js|html|json)$/.test(path) && !/^(tests|vendor|node_modules|assets)\//.test(path));

/**
 * Each of `names` that `text` uses as a class: whole, not part of a longer name, or as
 * the start of one built in pieces (`'fh-tile__' . $part`, `fh-btn--%s`).
 */
export function uses(text, names) {
  return names.filter((name) => new RegExp(`(?<![\\w-])${name}(?:(?![\\w-])|(?:__|--)(?![\\w-]))`).test(text));
}

describe('the stylesheets the plugin leaves out', () => {
  it('each style a class nothing else in the plugin does', () => {
    // So the check below has something to find in every file on the lists.
    for (const [file, names] of leftOutFiles) expect(names.length, file).toBeGreaterThan(0);
  });

  it('style nothing the plugin prints', () => {
    expect(markup.length).toBeGreaterThan(20);
    const found = markup.flatMap((path) => uses(readFileSync(root(PLUGIN + path), 'utf8'), leftOutOnly).map((name) => `${path}: ${name}`));
    expect(found).toEqual([]);
  });

  it('finds a class whole or built in pieces, not inside another name', () => {
    expect(uses('<li class="fh-tile fh-tile--wide">', ['fh-tile'])).toEqual(['fh-tile']);
    expect(uses("'fh-tile' . '__link'", ['fh-tile'])).toEqual(['fh-tile']);
    expect(uses("'fh-tile__' . $part", ['fh-tile'])).toEqual(['fh-tile']);
    expect(uses('class="fh-tile--%s"', ['fh-tile'])).toEqual(['fh-tile']);
    expect(uses('`fh-tile__${part}`', ['fh-tile'])).toEqual(['fh-tile']);
    expect(uses('<li class="fh-tile__link">', ['fh-tile'])).toEqual([]);
    expect(uses('<li class="fh-tiles">', ['fh-tile'])).toEqual([]);
    expect(uses('<li class="my-fh-tile">', ['fh-tile'])).toEqual([]);
  });
});
