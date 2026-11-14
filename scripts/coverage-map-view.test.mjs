import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { readCases } from '../src/content/cases';

/**
 * The coverage map's view script (wp-content/plugins/firehouse/blocks/coverage-map/view.js),
 * run without WordPress: its imports stand in for the Interactivity API (as WordPress
 * 6.8 and later have it, with withSyncEvent()), and for the map's module, which only a
 * browser can run. Its words are shared/cases/coverage-map-view.json's, which
 * tests/CoverageMapTest.php holds Coverage_Map to.
 */
let context = {};
let element = {};
let definition = null;
vi.mock('@wordpress/interactivity', () => ({
  store: (namespace, value) => {
    definition = { namespace, ...value };
    return value;
  },
  getContext: () => context,
  getElement: () => element,
  withSyncEvent: (action) => Object.assign(action, { sync: true }),
}));

/** Each map drawn, with what it was given and told. A host of 'broken' throws, as Leaflet might. */
const drawn = [];
vi.mock('@firehouse/coverage-map', () => ({
  drawMap: (host, options) => {
    if (host === 'broken') throw new Error('broken');
    const canvas = { host, options, updates: [], update: (changes) => canvas.updates.push(changes) };
    drawn.push(canvas);
    return canvas;
  },
}));

const view = await import('../wp-content/plugins/firehouse/blocks/coverage-map/view.js');
const shared = readCases('coverage-map-view.json');

/** A small page: a project in two GACCs, a national study (in every GACC), one with none. */
const PROJECTS = [
  { title: 'Smoke & alerts', year: '2025', url: 'https://example.test/a/', regions: ['NWCC', 'ONCC'], national: false },
  { title: 'National outlook', year: '2024', url: 'https://example.test/b/', regions: ['NATIONAL', 'AICC', 'NWCC', 'ONCC', 'GBCC'], national: true },
  { title: 'Unplaced', year: '2023', url: 'https://example.test/c/', regions: [], national: false },
];
const NAMES = { NWCC: 'Northwest', GBCC: 'Great Basin', NATIONAL: 'National', INTL: 'International' };

describe('the coverage map view script, against the shared cases', () => {
  it.each(shared.headings)('splits the heading for $count', ({ count, parts }) => {
    expect(view.heading(shared.strings, count)).toEqual(parts);
  });

  it.each(shared.reordered)('splits a translation that reorders it: $format', ({ format, count, parts }) => {
    expect(view.heading({ heading: [format, format] }, count)).toEqual(parts);
  });

  it.each(shared.submissions)('says $text on the map', ({ count, text }) => {
    expect(view.submissions(shared.strings, count)).toBe(text);
  });
});

describe('what the script works out', () => {
  const data = { projects: PROJECTS, names: NAMES, strings: shared.strings };

  it('lists a region’s projects in the page’s order, and none when none is selected', () => {
    expect(view.shownIn(PROJECTS, 'NWCC').map((p) => p.url)).toEqual(['https://example.test/a/', 'https://example.test/b/']);
    expect(view.shownIn(PROJECTS, 'INTL')).toEqual([]);
    expect(view.shownIn(PROJECTS, null)).toEqual([]);
  });

  it('announces the heading as one line, and nothing for none', () => {
    expect(view.announcement(data, 'NWCC')).toBe('2 projects in Northwest');
    expect(view.announcement(data, 'GBCC')).toBe('1 project in Great Basin');
    expect(view.announcement(data, 'INTL')).toBe('0 projects in International');
    expect(view.announcement(data, null)).toBe('');
  });

  it('writes the address as the site does', () => {
    expect(view.addressOf('/firehouse/', 'GBCC')).toBe('/firehouse/?region=GBCC');
    expect(view.addressOf('/firehouse/', null)).toBe('/firehouse/');
  });

  it('reads the theme from GSL’s switch', () => {
    expect(view.themeOf({ getAttribute: () => 'dark' })).toBe('dark');
    expect(view.themeOf({ getAttribute: () => 'light' })).toBe('light');
    expect(view.themeOf({ getAttribute: () => null })).toBe('light');
  });
});

describe('the store', () => {
  let state;
  let actions;
  let replaced;
  let observed;

  beforeEach(() => {
    ({ state, actions } = definition);
    Object.assign(state, { selected: null, projects: PROJECTS, names: NAMES, strings: shared.strings, counts: { NWCC: 2, GBCC: 1 }, announced: '', showCodes: true, tilesFailed: false, failed: false });
    replaced = [];
    observed = [];
    vi.stubGlobal('window', {
      history: { state: { kept: true }, replaceState: (...args) => replaced.push(args) },
      location: { pathname: '/firehouse/' },
      requestAnimationFrame: (callback) => callback(),
      MutationObserver: class {
        constructor(callback) {
          this.callback = callback;
        }
        observe(target, options) {
          observed.push({ target, options, callback: this.callback });
        }
      },
    });
    vi.stubGlobal('document', { documentElement: { theme: 'light', getAttribute() { return this.theme; } } });
  });
  afterEach(() => {
    vi.unstubAllGlobals();
    context = {};
  });

  it('is the store the server’s markup names', () => {
    expect(definition.namespace).toBe('firehouse/coverage-map');
  });

  it('works out the results for the selection', () => {
    expect([state.none, state.shown, state.empty, state.name]).toEqual([true, [], false, '']);
    state.selected = 'NWCC';
    expect([state.none, state.shown.length, state.empty, state.before, state.name, state.after]).toEqual([false, 2, false, '2 projects in ', 'Northwest', '']);
    state.selected = 'INTL';
    expect([state.shown.length, state.empty, state.before]).toEqual([0, true, '0 projects in ']);
  });

  it('presses the selected region’s button, and All submissions for none', () => {
    context = { key: null };
    expect(state.pressed).toBe(true);
    state.selected = 'GBCC';
    expect(state.pressed).toBe(false);
    context = { key: 'GBCC' };
    expect(state.pressed).toBe(true);
  });

  it('tags a national study anywhere but National', () => {
    context = { project: PROJECTS[1] };
    state.selected = 'NWCC';
    expect(state.tagged).toBe(true);
    state.selected = 'NATIONAL';
    expect(state.tagged).toBe(false);
    context = { project: PROJECTS[0] };
    state.selected = 'NWCC';
    expect(state.tagged).toBe(false);
  });

  it('selects a region in place: the page, the status line and the address', () => {
    const event = { preventDefault: vi.fn() };
    context = { key: 'NWCC' };
    expect(actions.select.sync).toBe(true);
    actions.select(event);
    expect(event.preventDefault).toHaveBeenCalled();
    expect([state.selected, state.announced]).toEqual(['NWCC', '2 projects in Northwest']);
    expect(replaced.at(-1)).toEqual([{ kept: true }, '', '/firehouse/?region=NWCC']);

    // Pressed again, it selects none, and says nothing.
    actions.select(event);
    expect([state.selected, state.announced]).toEqual([null, '']);
    expect(replaced.at(-1)[2]).toBe('/firehouse/');

    // All submissions selects none.
    state.selected = 'GBCC';
    context = { key: null };
    actions.select(event);
    expect(state.selected).toBe(null);
  });

  it('clears in place', () => {
    const event = { preventDefault: vi.fn() };
    state.selected = 'GBCC';
    expect(actions.clear.sync).toBe(true);
    actions.clear(event);
    expect(event.preventDefault).toHaveBeenCalled();
    expect([state.selected, state.announced, replaced.at(-1)[2]]).toEqual([null, '', '/firehouse/']);
  });

  it('draws the map from the state, and keeps it following the selection, the labels and the theme', async () => {
    state.selected = 'GBCC';
    element = { ref: 'host' };
    await definition.callbacks.draw();
    const canvas = drawn.at(-1);
    expect(canvas.host).toBe('host');
    expect(canvas.options).toMatchObject({ counts: { NWCC: 2, GBCC: 1 }, theme: 'light', selected: 'GBCC', showCodes: true });
    expect(canvas.options.submissions(1)).toBe('1 submission');

    canvas.options.onSelect('NWCC');
    expect([state.selected, state.announced]).toEqual(['NWCC', '2 projects in Northwest']);
    expect(canvas.updates.at(-1)).toEqual({ selected: 'NWCC' });

    actions.toggleCodes();
    expect(state.showCodes).toBe(false);
    expect(canvas.updates.at(-1)).toEqual({ showCodes: false });

    canvas.options.onTiles(true);
    expect(state.tilesFailed).toBe(true);
    canvas.options.onTiles(false);
    expect(state.tilesFailed).toBe(false);

    const { target, options, callback } = observed.at(-1);
    expect(options).toEqual({ attributes: true, attributeFilter: ['data-theme'] });
    target.theme = 'dark';
    callback();
    expect(canvas.updates.at(-1)).toEqual({ theme: 'dark' });
    expect(state.failed).toBe(false);
  });

  it('leaves the map out if it can’t be drawn', async () => {
    element = { ref: 'broken' };
    await definition.callbacks.draw();
    expect(state.failed).toBe(true);
  });
});
