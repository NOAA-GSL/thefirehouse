import { afterAll, describe, expect, it, vi } from 'vitest';

/**
 * The explorer's view script as WordPress 6.7 runs it, with no withSyncEvent(), and in
 * a page: it marks its region interactive only once it has loaded, and keeps what the
 * visitor changed before then. explorer-view.test.mjs covers the rest.
 */
let definition = null;
vi.mock('@wordpress/interactivity', () => ({
  store: (namespace, value) => {
    definition = { namespace, ...value };
    return value;
  },
  getContext: () => ({}),
  getElement: () => ({}),
  // As in WordPress 6.7, which has none: the script then runs every action as it is.
  withSyncEvent: undefined,
}));
vi.mock('@firehouse/search', async () => await import('../wp-content/plugins/firehouse/assets/search/search.js'));

// The page as the script finds it: the region the server printed, a search typed and a
// phase chosen before the script arrived, and focus in the search box.
const attributes = {};
const box = { value: 'smoke', defaultValue: '', focus: vi.fn() };
const region = { setAttribute: (name, value) => (attributes[name] = value), contains: (node) => node === box };
const phase = { options: [{ value: '', defaultSelected: true }, { value: 'during', defaultSelected: false }], selectedIndex: 1 };
const replaced = [];
vi.stubGlobal('document', {
  activeElement: box,
  querySelector: (selector) => (selector === '[data-fh-interactive="firehouse/explorer"]' ? region : selector.includes('name="phase"') ? phase : null),
  getElementById: () => box,
});
vi.stubGlobal('window', { scrollY: 0, history: { state: null, replaceState: (_s, _t, url) => replaced.push(url) }, location: { pathname: '/firehouse/projects/' } });

await import('../wp-content/plugins/firehouse/blocks/explorer/view.js');
afterAll(() => vi.unstubAllGlobals());

describe('the explorer view script, as WordPress 6.7 runs it, in a page', () => {
  it('runs its actions as they are', () => {
    expect(definition.actions.submit.sync).toBeUndefined();
    const event = { button: 0, preventDefault: vi.fn() };
    Object.assign(definition.state, { query: { q: '', phase: null, region: null, year: null, type: null, sort: null }, text: '' });
    definition.actions.submit(event);
    expect(event.preventDefault).toHaveBeenCalled();
  });

  it('marks its region for WordPress only once it has loaded', () => {
    expect(attributes).toEqual({ 'data-wp-interactive': 'firehouse/explorer' });
  });

  it('keeps the filters open while the visitor is in them', () => {
    expect(definition.state.filtersOpen).toBe(false);
  });

  it('takes in what the visitor typed and chose before it loaded', () => {
    Object.assign(definition.state, { query: { q: '', phase: null, region: null, year: null, type: null, sort: null }, text: '' });
    definition.callbacks.adopt();
    expect([definition.state.text, definition.state.query.q, definition.state.query.phase, replaced.at(-1)]).toEqual(['smoke', 'smoke', 'during', '?q=smoke&phase=during']);
  });
});
