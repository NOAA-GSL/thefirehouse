import { afterAll, describe, expect, it, vi } from 'vitest';

/**
 * The explorer's view script as WordPress 6.7 runs it, with no withSyncEvent(), and in
 * a page: it marks its region interactive only once its store is there, and keeps what
 * the visitor changed, and where their focus was, before WordPress takes the page over.
 * explorer-view.test.mjs covers the rest.
 */
let definition = null;
const attributes = {};
let attributesAtStore = null;
vi.mock('@wordpress/interactivity', () => ({
  store: (namespace, value) => {
    attributesAtStore = { ...attributes };
    definition = { namespace, ...value };
    return value;
  },
  getContext: () => ({}),
  getElement: () => ({}),
  // As in WordPress 6.7, which has none: the script then runs every action as it is.
  withSyncEvent: undefined,
}));
vi.mock('@firehouse/search', async () => await import('../wp-content/plugins/firehouse/assets/search/search.js'));

// The page as the script finds it: the region the server printed, "smo" typed before
// the script arrived, and focus in the search box, outside the filters.
const listeners = [];
const facets = { id: 'fh-explorer-facets' };
const box = { value: 'smo', defaultValue: '', isConnected: true, focus: vi.fn(), closest: () => null };
const phase = {
  options: [{ value: '', defaultSelected: true }, { value: 'during', defaultSelected: false }],
  selectedIndex: 0,
  isConnected: true,
  focus: vi.fn(),
  closest: (selector) => (selector === '#fh-explorer-facets' ? facets : null),
};
const region = {
  setAttribute: (name, value) => (attributes[name] = value),
  contains: (node) => node === box || node === phase,
  addEventListener: (type, listener, capture) => listeners.push({ type, listener, capture }),
  removeEventListener: (type, listener) => listeners.splice(listeners.findIndex((l) => l.type === type && l.listener === listener), 1),
};
const body = {};
const replaced = [];
const page = {
  body,
  activeElement: box,
  querySelector: (selector) => (selector === '[data-fh-interactive="firehouse/explorer"]' ? region : selector.includes('name="phase"') ? phase : null),
  getElementById: () => box,
};
vi.stubGlobal('document', page);
vi.stubGlobal('window', { scrollY: 0, requestAnimationFrame: (callback) => callback(), history: { state: null, replaceState: (_s, _t, url) => replaced.push(url) }, location: { pathname: '/firehouse/projects/' } });

await import('../wp-content/plugins/firehouse/blocks/explorer/view.js');
afterAll(() => vi.unstubAllGlobals());

const fire = (type, target) => listeners.filter((l) => l.type === type).forEach((l) => l.listener({ target }));

describe('the explorer view script, as WordPress 6.7 runs it, in a page', () => {
  it('runs its actions as they are', () => {
    expect(definition.actions.submit.sync).toBeUndefined();
    const event = { button: 0, preventDefault: vi.fn() };
    Object.assign(definition.state, { query: { q: '', phase: null, region: null, year: null, type: null, sort: null }, text: '' });
    definition.actions.submit(event);
    expect(event.preventDefault).toHaveBeenCalled();
  });

  it('marks its region for WordPress only once its store is there', () => {
    expect(attributesAtStore).toEqual({});
    expect(attributes).toEqual({ 'data-wp-interactive': 'firehouse/explorer' });
  });

  it("folds the filters for a visitor who isn't in them, and keeps them open once they go in", () => {
    expect(definition.state.filtersOpen).toBe(false);
    fire('focusin', phase);
    expect(definition.state.filtersOpen).toBe(true);
  });

  it('follows what the visitor changes until the takeover, then takes it in and stops following', () => {
    // After the script ran, but before WordPress took over: "k" typed, a phase chosen.
    box.value = 'smok';
    fire('input', box);
    phase.selectedIndex = 1;
    fire('change', phase);
    fire('focusin', phase);
    expect(listeners.map((l) => [l.type, l.capture])).toEqual([['input', true], ['change', true], ['focusin', true]]);

    // The takeover dropped focus to the page.
    page.activeElement = body;
    Object.assign(definition.state, { query: { q: '', phase: null, region: null, year: null, type: null, sort: null }, text: '' });
    definition.callbacks.adopt();
    expect([definition.state.text, definition.state.query.q, definition.state.query.phase, replaced.at(-1)]).toEqual(['smok', 'smok', 'during', '/firehouse/projects/?q=smok&phase=during']);
    expect(phase.focus).toHaveBeenCalledTimes(1);
    expect(listeners).toEqual([]);
  });

});
