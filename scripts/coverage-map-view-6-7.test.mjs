import { afterAll, describe, expect, it, vi } from 'vitest';

/**
 * The coverage map's view script as WordPress 6.7 runs it, with no withSyncEvent() and
 * no splitTask(), and in a page: it marks its region interactive only once its store is
 * there, Clear puts focus on "All submissions", and the map still follows a choice a
 * task later. coverage-map-view.test.mjs covers the rest.
 */
let definition = null;
let context = {};
const attributes = {};
let attributesAtStore = null;
vi.mock('@wordpress/interactivity', () => ({
  store: (namespace, value) => {
    attributesAtStore = { ...attributes };
    definition = { namespace, ...value };
    return value;
  },
  getContext: () => context,
  getElement: () => ({ ref: 'host' }),
  // As in WordPress 6.7, which has neither: the script runs every action as it is, and
  // yields with a timeout.
  withSyncEvent: undefined,
  splitTask: undefined,
}));

const drawn = [];
vi.mock('@firehouse/coverage-map', () => ({
  drawMap: (host, options) => {
    const canvas = { host, options, updates: [], update: (changes) => canvas.updates.push(changes) };
    drawn.push(canvas);
    return canvas;
  },
}));

// The page as the script finds it: the region the server printed, with "All
// submissions" first in its list.
const all = { focus: vi.fn() };
const region = {
  setAttribute: (name, value) => (attributes[name] = value),
  querySelector: (selector) => (selector === '.fh-map__list .fh-map__region' ? all : null),
};
const frames = [];
vi.stubGlobal('document', {
  querySelector: (selector) => (selector === '[data-fh-interactive="firehouse/coverage-map"]' ? region : null),
  documentElement: { getAttribute: () => null },
  createElement: (tag) => ({ tag }),
  head: { append: () => {} },
});
vi.stubGlobal('window', {
  requestAnimationFrame: (callback) => frames.push(callback),
  history: { state: null, replaceState: () => {} },
  location: { pathname: '/firehouse/' },
  MutationObserver: class {
    observe() {}
  },
});

await import('../wp-content/plugins/firehouse/blocks/coverage-map/view.js');
afterAll(() => vi.unstubAllGlobals());

const STRINGS = { heading: ['%1$d project in %2$s', '%1$d projects in %2$s'], submissions: ['%d submission', '%d submissions'] };

describe('the coverage map view script, as WordPress 6.7 runs it, in a page', () => {
  it('marks its region for WordPress only once its store is there', () => {
    expect(attributesAtStore).toEqual({});
    expect(attributes).toEqual({ 'data-wp-interactive': 'firehouse/coverage-map' });
  });

  it('runs its actions as they are, and Clear puts focus on All submissions once the page shows it', () => {
    Object.assign(definition.state, { selected: 'GBCC', projects: [], names: { GBCC: 'Great Basin' }, strings: STRINGS, counts: {}, tileHost: 'https://tiles.example.test' });
    expect(definition.actions.clear.sync).toBeUndefined();
    const event = { preventDefault: vi.fn() };
    definition.actions.clear(event);
    expect(event.preventDefault).toHaveBeenCalled();
    expect(definition.state.selected).toBe(null);
    // A frame later, not at once.
    expect(all.focus).not.toHaveBeenCalled();
    frames.splice(0).forEach((frame) => frame());
    expect(all.focus).toHaveBeenCalledTimes(1);
  });

  it('draws the map, and has it follow a choice a task later', async () => {
    await definition.callbacks.draw();
    const canvas = drawn.at(-1);
    expect(definition.state.drawn).toBe(true);
    context = { key: 'GBCC' };
    definition.actions.select({ preventDefault: () => {} });
    expect(canvas.updates).toEqual([]);
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(canvas.updates).toEqual([{ selected: 'GBCC' }]);
  });
});
