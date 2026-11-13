import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { readCases, site } from '../src/content/cases';
import { formatByline } from '../src/content/format';
import { parseProjectQuery } from '../src/content/search';

/**
 * The explorer's view script (wp-content/plugins/firehouse/blocks/explorer/view.js),
 * run without WordPress: its imports stand in for the Interactivity API, and for the
 * site's search through the module the plugin ships (assets/search/search.js), so what
 * it lists is what the browser lists. The words are the plugin's English ones, as
 * Explorer_Page::state() sends them.
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
  // As in WordPress 6.7, which has none: the script then runs every action as is.
  withSyncEvent: undefined,
}));
vi.mock('@firehouse/search', async () => await import('../wp-content/plugins/firehouse/assets/search/search.js'));

const lib = await import('../wp-content/plugins/firehouse/assets/search/search.js');
const view = await import('../wp-content/plugins/firehouse/blocks/explorer/view.js');

const ARCHIVE = 'https://example.test/firehouse/projects/';
const vocabulary = JSON.parse(readFileSync(fileURLToPath(new URL('../wp-content/plugins/firehouse/shared/vocabulary.json', import.meta.url)), 'utf8'));
const shortOf = (list) => Object.fromEntries(list.map((item) => [item.key, item.short]));

/** The state the server sends, for the shared site's published projects and a query. */
function serverState(query = '') {
  const parsed = parseProjectQuery(new URLSearchParams(query));
  return {
    projects: site()
      .projects.filter((project) => project.published)
      .map((project) => ({ ...project, url: `${ARCHIVE}${project.slug}/`, byline: formatByline(project.authors) })),
    query: parsed,
    text: parsed.q.replace(/[\r\n]/g, ' '),
    url: ARCHIVE,
    landing: '#fh-content',
    short: { phase: shortOf(vocabulary.firePhases), region: shortOf(vocabulary.regions) },
    sorts: { relevance: 'Best match', newest: 'Newest first', oldest: 'Oldest first', title: 'Title A–Z' },
    strings: {
      hint: 'Searches titles, authors, abstracts, and findings. Results update as you type. Press Escape to clear.',
      filter: { phase: 'Phase: %s', region: 'GACC: %s', year: 'Year: %s', type: 'Type: %s' },
      remove: 'Remove filter %s',
      of: ['of %s project', 'of %s projects'],
      count: ['project', 'projects'],
    },
  };
}

describe('the explorer view script, against the shared cases', () => {
  const { cases } = readCases('explorer.json');

  it.each(cases.map((c) => [c.name, c]))('%s', (_name, shared) => {
    const data = serverState(shared.query);
    const query = data.query;
    expect(view.sortOptions(lib, data, query).map(({ key: _key, ...option }) => option)).toEqual(shared.sortOptions);
    expect(view.applied(lib, data, query).map((filter) => filter.label)).toEqual(shared.applied);
    const shown = view.results(lib, data, query).length;
    expect(view.countRest(data, shown, data.projects.length, view.isFiltered(lib, query))).toBe(shared.countRest);
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

  it('keys a repeated phase apart, and drops one the vocabulary lacks', () => {
    const data = serverState();
    data.projects = [{ ...data.projects[0], firePhases: ['during', 'during', 'not-a-phase'] }];
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

  it('writes an address as the site writes it', () => {
    const query = parseProjectQuery(new URLSearchParams('q=smoke%0Afire&type=R%26D&sort=title'));
    expect(view.addressOf(lib, ARCHIVE, query)).toBe(`${ARCHIVE}?q=smoke%0Afire&type=R%26D&sort=title`);
    expect(view.addressOf(lib, ARCHIVE, view.cleared(query), '#fh-content')).toBe(`${ARCHIVE}?sort=title#fh-content`);
    expect(view.addressOf(lib, ARCHIVE, parseProjectQuery(new URLSearchParams('')))).toBe(ARCHIVE);
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
});

describe('the explorer store', () => {
  let replaced;
  let focused;
  beforeEach(() => {
    vi.useFakeTimers();
    replaced = [];
    focused = [];
    vi.stubGlobal('window', { history: { state: null, replaceState: (_state, _title, url) => replaced.push(url) } });
    vi.stubGlobal('document', { getElementById: (id) => ({ focus: () => focused.push(id) }) });
    Object.assign(definition.state, serverState(), { filtersOpen: false });
  });
  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });
  const { state, actions } = { get state() { return definition.state; }, get actions() { return definition.actions; } };

  it('is the explorer\'s', () => {
    expect(definition.namespace).toBe('firehouse/explorer');
  });

  it('shows what the script adds, and folds the filters until asked', () => {
    expect(state.live).toBe(true);
    expect(state.facetsOpen).toBe(false);
    actions.toggleFilters();
    expect([state.filtersOpen, state.facetsOpen]).toEqual([true, true]);
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
    expect([state.query.q, replaced]).toEqual(['smoke', [`${ARCHIVE}?q=smoke`]]);
    actions.type({ target: { value: 'smoke fire' } });
    let prevented = false;
    actions.submit({ preventDefault: () => (prevented = true) });
    expect([prevented, state.query.q, replaced.at(-1)]).toEqual([true, 'smoke fire', `${ARCHIVE}?q=smoke+fire`]);
    vi.advanceTimersByTime(view.DEBOUNCE_MS);
    expect(replaced).toHaveLength(2);
  });

  it('clears the search on Escape, after the pause', () => {
    state.text = 'smoke';
    state.query = { ...state.query, q: 'smoke' };
    let prevented = false;
    actions.keydown({ key: 'Escape', preventDefault: () => (prevented = true) });
    expect([prevented, state.text, state.query.q]).toEqual([true, '', 'smoke']);
    vi.advanceTimersByTime(view.DEBOUNCE_MS);
    expect(state.query.q).toBe('');
    prevented = false;
    actions.keydown({ key: 'Escape', preventDefault: () => (prevented = true) });
    expect(prevented).toBe(false);
  });

  it('changes a filter, or the sort, at once', () => {
    context = { facet: 'year' };
    actions.choose({ target: { value: '2025' } });
    expect([state.query.year, replaced.at(-1)]).toEqual([2025, `${ARCHIVE}?year=2025`]);
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
    actions.clearSearch({ preventDefault: () => {} });
    expect([state.text, state.query.q, state.query.phase, focused]).toEqual(['', '', 'during', ['fh-explorer-q']]);
    state.text = 'fire';
    actions.clearAll({ preventDefault: () => {} });
    expect([state.text, state.query, replaced.at(-1)]).toEqual(['', view.cleared({ sort: 'title' }), `${ARCHIVE}?sort=title`]);
  });
});
