import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { readCases, site } from '../src/content/cases';
import { formatByline } from '../src/content/format';
import { parseProjectQuery } from '../src/content/search';

/**
 * The explorer's view script (wp-content/plugins/firehouse/blocks/explorer/view.js),
 * run without WordPress: its imports stand in for the Interactivity API (as WordPress
 * 6.8 and later have it, with withSyncEvent()), and for the site's search through the
 * module the plugin ships (assets/search/search.js), so what it lists is what the
 * browser lists. Its words are shared/cases/explorer.json's, which
 * tests/ExplorerPageTest.php holds Explorer_Page::state() to. explorer-view-6-7.test.mjs
 * runs it as WordPress 6.7 has it, and in a page.
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
vi.mock('@firehouse/search', async () => await import('../wp-content/plugins/firehouse/assets/search/search.js'));

const lib = await import('../wp-content/plugins/firehouse/assets/search/search.js');
const view = await import('../wp-content/plugins/firehouse/blocks/explorer/view.js');

const ARCHIVE = 'https://example.test/firehouse/projects/';
const PATH = '/firehouse/projects/';
const shared = readCases('explorer.json');
const plugin = (path) => fileURLToPath(new URL(`../wp-content/plugins/firehouse/${path}`, import.meta.url));
const vocabulary = JSON.parse(readFileSync(plugin('shared/vocabulary.json'), 'utf8'));
const shortOf = (list) => Object.fromEntries(list.map((item) => [item.key, item.short]));

/** The state the server sends (Explorer_Page::state()), for the shared site's published projects and a query. */
function serverState(query = '') {
  const parsed = parseProjectQuery(new URLSearchParams(query));
  return {
    projects: site()
      .projects.filter((project) => project.published)
      .map((project) => ({ ...project, url: `${ARCHIVE}${project.slug}/`, byline: formatByline(project.authors), fullByline: formatByline(project.authors, 0), publicationStatus: project.publicationStatus ?? null })),
    query: parsed,
    text: parsed.q.replace(/[\r\n]/g, ' '),
    url: ARCHIVE,
    landing: '#fh-content',
    short: { phase: shortOf(vocabulary.firePhases), region: shortOf(vocabulary.regions), status: Object.fromEntries(vocabulary.publicationStatuses.map((item) => [item.key, item.label])) },
    sorts: shared.sorts,
    strings: shared.strings,
  };
}

describe('the explorer view script, against the shared cases', () => {
  it.each(shared.cases.map((c) => [c.name, c]))('%s', (_name, theCase) => {
    const data = serverState(theCase.query);
    const query = data.query;
    expect(view.sortOptions(lib, data, query).map(({ key: _key, ...option }) => option)).toEqual(theCase.sortOptions);
    expect(view.applied(lib, data, query).map((filter) => filter.label)).toEqual(theCase.applied);
    const shown = view.results(lib, data, query).length;
    expect(view.countRest(data, shown, data.projects.length, view.isFiltered(lib, query))).toBe(theCase.countRest);
  });
});

describe('the explorer view script', () => {
  it('lists what the site lists, each project as its tile shows it', () => {
    const data = serverState('q=smoke');
    const listed = view.results(lib, data, data.query);
    expect(listed.map((project) => project.slug)).toEqual(lib.filterProjects(data.projects, data.query).map((project) => project.slug));
    const tile = listed[0];
    const project = data.projects.find((candidate) => candidate.slug === tile.slug);
    expect(tile).toEqual({
      slug: project.slug,
      url: `${ARCHIVE}${project.slug}/`,
      title: project.title,
      projectType: project.projectType ?? '',
      summary: project.summary,
      meta: `${formatByline(project.authors)} · ${project.completionYear}`,
      phases: project.firePhases.map((phase, index) => ({ key: `${index}-${phase}`, label: vocabulary.firePhases.find((item) => item.key === phase).short })),
    });
  });

  it('keys a repeated phase apart, and drops one the vocabulary lacks, an inherited name included', () => {
    const data = serverState();
    data.projects = [{ ...data.projects[0], firePhases: ['during', 'during', 'not-a-phase', 'constructor'] }];
    expect(view.results(lib, data, data.query)[0].phases).toEqual([
      { key: '0-during', label: 'During' },
      { key: '1-during', label: 'During' },
    ]);
  });

  it('links each applied filter to the query without it, landing on the content', () => {
    const data = serverState('q=smoke&phase=during&year=2025');
    expect(view.applied(lib, data, data.query)).toEqual([
      { facet: 'phase', label: 'Phase: During', name: 'Remove filter Phase: During', href: `${ARCHIVE}?q=smoke&year=2025#fh-content` },
      { facet: 'year', label: 'Year: 2025', name: 'Remove filter Year: 2025', href: `${ARCHIVE}?q=smoke&phase=during#fh-content` },
    ]);
  });

  it('keeps a researcher\'s dollar signs as written in a filter\'s name too', () => {
    const data = serverState('type=Cost+%24%24+and+%24%26+%24%60x');
    expect(view.applied(lib, data, data.query)[0].name).toBe('Remove filter Type: Cost $$ and $& $`x');
    expect(view.fill('of %s', '$&')).toBe('of $&');
  });

  it('writes an address as the site writes it', () => {
    const query = parseProjectQuery(new URLSearchParams('q=smoke%0Afire&type=R%26D&sort=title'));
    expect(view.addressOf(lib, ARCHIVE, query)).toBe(`${ARCHIVE}?q=smoke%0Afire&type=R%26D&sort=title`);
    expect(view.addressOf(lib, ARCHIVE, view.cleared(query), '#fh-content')).toBe(`${ARCHIVE}?sort=title#fh-content`);
    expect(view.addressOf(lib, ARCHIVE, parseProjectQuery(new URLSearchParams('')))).toBe(ARCHIVE);
  });

  it('puts focus back only where the takeover dropped it to the page, or in the search if the script hid it', () => {
    const body = {};
    const search = { id: 'search' };
    const there = { isConnected: true, closest: () => null };
    const hidden = { isConnected: true, closest: (selector) => (selector === '[hidden]' ? {} : null) };
    expect(view.refocusTarget(there, body, body, search)).toBe(there);
    expect(view.refocusTarget(there, null, body, search)).toBe(there);
    expect(view.refocusTarget(hidden, body, body, search)).toBe(search);
    // Focus still on an element just hidden is as good as dropped; on one still shown, it stays.
    expect(view.refocusTarget(hidden, hidden, body, search)).toBe(search);
    expect(view.refocusTarget(there, there, body, search)).toBeNull();
    expect(view.refocusTarget(there, { id: 'a header link' }, body, search)).toBeNull();
    expect(view.refocusTarget({ isConnected: false }, body, body, search)).toBeNull();
    expect(view.refocusTarget(null, body, body, search)).toBeNull();
  });

  it('reads an option as the query holds it', () => {
    expect(view.facetValue('phase', '')).toBeNull();
    expect(view.facetValue('year', '2025')).toBe(2025);
    expect(view.facetValue('type', 'Research Study')).toBe('Research Study');
  });

  it('counts the plural as the site does', () => {
    const data = serverState();
    expect(view.countRest(data, 1, 8, false)).toBe('project');
    expect(view.countRest(data, 0, 8, false)).toBe('projects');
    expect(view.countRest(data, 0, 1, true)).toBe('of 1 project');
  });

  it('finds what the visitor changed before it loaded, against the server\'s own choices', () => {
    const option = (value, selected, defaultSelected) => ({ value, selected, defaultSelected });
    const select = (options) => ({ options, selectedIndex: options.findIndex((o) => o.selected) });
    const selects = {
      phase: select([option('', false, true), option('during', true, false)]),
      year: select([option('', true, false), option('2030', false, false)]),
      sort: select([option('', false, true), option('title', true, false)]),
      type: select([option('', true, true)]),
    };
    const doc = {
      getElementById: () => ({ value: 'smoke', defaultValue: '' }),
      querySelector: (selector) => selects[selector.match(/name="(\w+)"/)[1]] ?? null,
    };
    // The year select holds a filter it can't show, so it shows its first option, which
    // isn't a change; the type select's choice is the server's.
    expect(view.changedBeforeLoad(doc)).toEqual({ text: 'smoke', patch: { phase: 'during', sort: 'title' } });
    selects.year = select([option('', false, false), option('2030', true, false)]);
    expect(view.changedBeforeLoad(doc).patch.year).toBe(2030);
    expect(view.changedBeforeLoad({ getElementById: () => ({ value: 'a', defaultValue: 'a' }), querySelector: () => null })).toEqual({ patch: {} });
  });
});

describe('the quick look', () => {
  const project = (more = {}) => ({ ...serverState().projects[0], ...more });

  it('shows a project as the site\'s ProjectDetailModal does', () => {
    const data = serverState();
    const one = project({
      projectType: 'Research Study',
      title: 'R&amp;D <one>',
      fullByline: 'A. Name, B. Name and C. Name',
      org: 'An Org',
      completionYear: 2025,
      firePhases: ['before', 'not-a-phase', 'during'],
      publicationStatus: 'in-review',
      abstract: 'An abstract.',
      takeaways: ['One', 'Two'],
      needs: [{ need: 'N1', recommendation: 'R1' }, { need: 'N2' }, { need: null, recommendation: 'R3' }, { need: 'N4' }, { need: 'N5' }],
      papers: [{ title: 'A paper' }],
    });
    expect(view.quickLook(data, one)).toEqual({
      slug: one.slug,
      title: 'R&amp;D <one>',
      kicker: 'Research Study',
      byline: 'A. Name, B. Name and C. Name, An Org · 2025',
      phases: [{ key: '0-before', label: 'Before' }, { key: '1-during', label: 'During' }],
      status: 'In review',
      hasMeta: true,
      abstract: 'An abstract.',
      needs: [{ key: '0', text: 'N1' }, { key: '1', text: 'N2' }, { key: '2', text: 'N4' }],
      more: 'and 1 more on the project page',
      rest: 'Also on the full page: 2 takeaways, 2 recommendations, 1 related paper.',
      url: one.url,
    });
  });

  it('leaves out what a project lacks', () => {
    const look = view.quickLook(serverState(), project({ projectType: null, org: null, firePhases: [], publicationStatus: 'constructor', abstract: null, takeaways: [], needs: [], papers: [] }));
    expect([look.kicker, look.status, look.hasMeta, look.abstract, look.needs, look.more, look.rest]).toEqual(['', '', false, '', [], '', '']);
    expect(look.byline).not.toContain(', null');
    expect(view.quickLook(serverState(), project({ takeaways: ['One'], needs: [{ recommendation: 'R' }], papers: [] })).rest).toBe('Also on the full page: 1 takeaway, 1 recommendation.');
  });

  it('binds to nothing while closed', () => {
    expect(Object.keys(view.NO_LOOK).sort()).toEqual(Object.keys(view.quickLook(serverState(), project())).sort());
    expect(Object.isFrozen(view.NO_LOOK)).toBe(true);
  });
});

describe('the explorer\'s markup and its store', () => {
  const php = readFileSync(plugin('includes/class-explorer-page.php'), 'utf8');
  const paths = (pattern) => [...new Set([...php.matchAll(pattern)].map((match) => match[1]))];

  it('binds only what the store and the server\'s state hold', () => {
    const server = serverState();
    // A name the store defines (a getter, read only by name here) or the server sends, and
    // a path into what the server sends.
    const known = (path) => {
      const [first, ...rest] = path.split('.');
      if (rest.length === 0) return first in definition.state || first in server;
      // The quick look binds into what it shows, which has NO_LOOK's shape.
      const root = first === 'peek' ? view.NO_LOOK : server[first];
      return rest.reduce((value, key) => value?.[key], root) !== undefined;
    };
    const found = paths(/"!?state\.([\w.]+)"/g).concat(paths(/::state\.([\w.]+)/g));
    expect(found.length).toBeGreaterThan(20);
    for (const path of found) expect(known(path), `state.${path}`).toBe(true);
  });

  it('binds only what each list\'s items hold', () => {
    const data = serverState('q=smoke&phase=during');
    const items = {
      project: view.results(lib, data, data.query)[0],
      filter: view.applied(lib, data, data.query)[0],
      option: view.sortOptions(lib, data, data.query)[0],
      phase: view.results(lib, data, data.query)[0].phases[0],
      need: view.quickLook(data, data.projects[0]).needs[0],
    };
    const found = paths(/"!?context\.(\w+\.[\w.]+)"/g);
    expect(found.length).toBeGreaterThan(10);
    for (const path of found) {
      const [item, ...rest] = path.split('.');
      const value = rest.reduce((at, key) => at?.[key], items[item]);
      expect(value, `context.${path}`).not.toBeUndefined();
    }
  });

  it('calls only the store\'s actions and callbacks, and marks its region by the store\'s name', () => {
    for (const name of paths(/"actions\.(\w+)"/g)) expect(definition.actions[name], `actions.${name}`).toBeTypeOf('function');
    for (const name of paths(/"callbacks\.(\w+)"/g)) expect(definition.callbacks[name], `callbacks.${name}`).toBeTypeOf('function');
    expect(php).toContain('data-fh-interactive="<?php echo esc_attr( self::STORE ); ?>"');
    expect(php).toContain(`public const STORE = '${view.STORE}';`);
  });
});

describe('the explorer store', () => {
  let replaced;
  let focused;
  beforeEach(() => {
    vi.useFakeTimers();
    view.forget();
    replaced = [];
    focused = [];
    vi.stubGlobal('window', { history: { state: null, replaceState: (_state, _title, url) => replaced.push(url) }, location: { pathname: PATH } });
    vi.stubGlobal('document', { getElementById: (id) => ({ focus: () => focused.push(id) }) });
    Object.assign(definition.state, serverState(), { filtersOpen: false, peekSlug: null });
  });
  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });
  const { state, actions } = { get state() { return definition.state; }, get actions() { return definition.actions; } };
  const click = (more = {}) => ({ button: 0, preventDefault: vi.fn(), ...more });

  it('is the explorer\'s', () => {
    expect(definition.namespace).toBe('firehouse/explorer');
  });

  it('runs each action that prevents the event\'s default as the event happens', () => {
    for (const name of ['keydown', 'submit', 'clearSearch', 'removeFilter', 'clearAll']) expect(definition.actions[name].sync, name).toBe(true);
    for (const name of ['type', 'choose', 'chooseSort', 'toggleFilters']) expect(definition.actions[name].sync, name).toBeUndefined();
  });

  it('shows what the script adds, and folds the filters until asked', () => {
    expect(state.live).toBe(true);
    expect(state.facetsOpen).toBe(false);
    actions.toggleFilters();
    expect([state.filtersOpen, state.facetsOpen]).toEqual([true, true]);
  });

  it("keeps each tile's object from one query to the next, so a tile kept isn't redrawn", () => {
    const first = new Map(state.results.map((tile) => [tile.slug, tile]));
    state.query = { ...state.query, q: 'smoke' };
    for (const tile of state.results) expect(tile).toBe(first.get(tile.slug));
  });

  it('works out the page from the query', () => {
    expect([state.searching, state.filtered, state.hasResults, state.shown, state.countRest]).toEqual([false, false, true, 4, 'projects']);
    state.query = { ...state.query, q: ' smoke ', phase: 'during' };
    expect([state.searching, state.filtered, state.facetCount, state.quoted]).toEqual([true, true, 1, '“smoke”']);
    expect(state.countRest).toBe('of 4 projects');
    expect(state.results.map((project) => project.slug)).toEqual(view.results(lib, state, state.query).map((project) => project.slug));
    expect(state.clearSearchHref).toBe(`${ARCHIVE}?phase=during#fh-content`);
    expect(state.clearAllHref).toBe(`${ARCHIVE}#fh-content`);
  });

  it('writes each option\'s count, and disables one that would leave nothing unless it is chosen', () => {
    state.query = { ...state.query, region: 'INTL' };
    context = { facet: 'region', value: 'INTL', label: 'International' };
    expect([state.optionText, state.optionSelected, state.optionDisabled]).toEqual(['International (0)', true, false]);
    // A region's count holds the other filters, not the region chosen.
    context = { facet: 'region', value: 'PACIFIC', label: 'Hawaii / Pacific' };
    expect([state.optionText, state.optionSelected, state.optionDisabled]).toEqual(['Hawaii / Pacific (1)', false, false]);
    context = { facet: 'region', value: 'UNKNOWN', label: 'Not sure' };
    expect([state.optionText, state.optionSelected, state.optionDisabled]).toEqual(['Not sure (0)', false, true]);
    context = { facet: 'region', value: '' };
    expect(state.optionSelected).toBe(false);
    context = { facet: 'region' };
    expect(state.facetSet).toBe(true);
    context = { facet: 'year', value: '2025', label: '2025' };
    expect(state.optionText).toBe('2025 (0)');
  });

  it('waits out a pause in typing before the results and the address follow; Enter applies at once', () => {
    actions.type({ target: { value: 'smoke' } });
    expect([state.text, state.query.q, replaced]).toEqual(['smoke', '', []]);
    vi.advanceTimersByTime(view.DEBOUNCE_MS);
    expect([state.query.q, replaced]).toEqual(['smoke', [`${PATH}?q=smoke`]]);
    actions.type({ target: { value: 'smoke fire' } });
    const event = click();
    actions.submit(event);
    expect([event.preventDefault.mock.calls.length, state.query.q, replaced.at(-1)]).toEqual([1, 'smoke fire', `${PATH}?q=smoke+fire`]);
    vi.advanceTimersByTime(view.DEBOUNCE_MS);
    expect(replaced).toHaveLength(2);
  });

  it('clears the search on Escape, after the pause', () => {
    state.text = 'smoke';
    state.query = { ...state.query, q: 'smoke' };
    const escape = { key: 'Escape', preventDefault: vi.fn() };
    actions.keydown(escape);
    expect([escape.preventDefault.mock.calls.length, state.text, state.query.q]).toEqual([1, '', 'smoke']);
    vi.advanceTimersByTime(view.DEBOUNCE_MS);
    expect([state.query.q, replaced.at(-1)]).toEqual(['', PATH]);
    actions.keydown(escape);
    expect(escape.preventDefault.mock.calls.length).toBe(1);
  });

  it('changes a filter, or the sort, at once', () => {
    context = { facet: 'year' };
    actions.choose({ target: { value: '2025' } });
    expect([state.query.year, replaced.at(-1)]).toEqual([2025, `${PATH}?year=2025`]);
    actions.choose({ target: { value: '' } });
    expect(state.query.year).toBeNull();
    actions.chooseSort({ target: { value: 'title' } });
    expect(state.query.sort).toBe('title');
    actions.chooseSort({ target: { value: '' } });
    expect(state.query.sort).toBeNull();
  });

  it('clears the search, or everything but the sort, and puts focus in the search', () => {
    state.text = 'smoke';
    state.query = { ...state.query, q: 'smoke', phase: 'during', sort: 'title' };
    actions.clearSearch(click());
    expect([state.text, state.query.q, state.query.phase, focused]).toEqual(['', '', 'during', ['fh-explorer-q']]);
    state.text = 'fire';
    actions.clearAll(click());
    expect([state.text, state.query, replaced.at(-1)]).toEqual(['', view.cleared({ sort: 'title' }), `${PATH}?sort=title`]);
  });

  it('leaves a click with a modifier key to the browser, which opens the link', () => {
    state.query = { ...state.query, phase: 'during' };
    for (const key of ['ctrlKey', 'metaKey', 'shiftKey', 'altKey']) {
      for (const name of ['clearSearch', 'removeFilter', 'clearAll']) {
        const event = click({ [key]: true });
        actions[name](event);
        expect(event.preventDefault, `${name} ${key}`).not.toHaveBeenCalled();
      }
    }
    actions.clearAll(click({ button: 1 }));
    expect([state.query.phase, replaced]).toEqual(['during', []]);
  });

  it('opens the quick look for a tile\'s project, and puts everything back when it closes', () => {
    const calls = [];
    const opener = { focus: () => calls.push('opener focused') };
    const quickLook = { showModal: () => calls.push('opened'), close: () => calls.push('closed') };
    const body = { style: { overflow: 'auto' } };
    vi.stubGlobal('document', { body, getElementById: (id) => (id === 'fh-quick-look' ? quickLook : { focus: () => {} }) });
    const project = state.projects[1];
    element = { ref: opener };
    context = { project: { slug: project.slug } };

    actions.peek();
    expect([state.peekSlug, state.peek.title, body.style.overflow, calls]).toEqual([project.slug, project.title, 'hidden', []]);
    vi.advanceTimersByTime(0);
    expect(calls).toEqual(['opened']);

    actions.closePeek();
    actions.peekClosed();
    expect([state.peekSlug, state.peek, body.style.overflow, calls]).toEqual([null, view.NO_LOOK, 'auto', ['opened', 'closed', 'opener focused']]);
  });

  it('closes the quick look on a click around it, not in it', () => {
    const closed = [];
    const quickLook = { close: () => closed.push(true) };
    element = { ref: quickLook };
    actions.peekBackdrop({ target: { tagName: 'P' } });
    expect(closed).toEqual([]);
    actions.peekBackdrop({ target: quickLook });
    expect(closed).toEqual([true]);
  });

  it('moves focus before removing a filter: to the filter after it, else Clear all, else the search', () => {
    // Focus moves while the filter is still applied, so it never falls to the page.
    const focusable = (name) => ({ name, focus: () => focused.push(list.removing && state.query[list.removing] === null ? `${name}, too late` : name) });
    const list = { pills: [focusable('phase'), focusable('year')] };
    list.querySelectorAll = () => list.pills;
    list.querySelector = () => focusable('clear all');
    const remove = (facet) => {
      const ref = list.pills.find((pill) => pill.name === facet);
      ref.closest = () => list;
      element = { ref };
      context = { filter: { facet } };
      list.removing = facet;
      actions.removeFilter(click());
      list.removing = null;
    };

    state.query = { ...state.query, phase: 'during', year: 2025 };
    remove('phase');
    expect([focused.at(-1), state.query.phase]).toEqual(['year', null]);

    state.query = { ...state.query, phase: 'during', year: 2025 };
    remove('year');
    expect(focused.at(-1)).toBe('clear all');

    list.pills = [focusable('year')];
    state.query = { ...state.query, phase: null, year: 2025 };
    remove('year');
    expect(focused.at(-1)).toBe('fh-explorer-q');

    state.text = 'smoke';
    state.query = { ...state.query, q: 'smoke', year: 2025 };
    remove('year');
    expect(focused.at(-1)).toBe('clear all');
  });
});
