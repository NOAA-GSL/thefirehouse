/**
 * The plugin's block editor script (wp-content/plugins/firehouse/assets/js/block-editor.js),
 * run against a stand-in for WordPress's `wp` packages, with the plugin's real blocks as
 * WordPress hands them to the editor: each block.json's title, attributes and
 * allowedBlocks, plus the attributes WordPress adds to every block.
 *
 * Saving must keep the page's blocks: every block saves its inner blocks, so one that
 * holds others can't lose them. The landing and About pages' blocks show their words
 * as text fields, and a change stores only that word, keeping everything else the block
 * stores; a word that can't be empty holds the save while it is.
 */
import { readdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const PLUGIN = fileURLToPath(new URL('../wp-content/plugins/firehouse/', import.meta.url));
const SOURCE = readFileSync(join(PLUGIN, 'assets/js/block-editor.js'), 'utf8');
const BLOCKS = readdirSync(join(PLUGIN, 'blocks'), { withFileTypes: true })
  .filter((entry) => entry.isDirectory())
  .map((entry) => JSON.parse(readFileSync(join(PLUGIN, 'blocks', entry.name, 'block.json'), 'utf8')));

// What WordPress hands the editor for each block: block.json's fields, and its own.
const TYPES = Object.fromEntries(
  BLOCKS.map((block) => [block.name, { title: block.title, allowedBlocks: block.allowedBlocks, attributes: { ...block.attributes, lock: {}, metadata: {} } }]),
);
// What includes/class-blocks.php passes: each block's declared attributes.
const DECLARED = Object.fromEntries(BLOCKS.map((block) => [block.name, Object.keys(block.attributes ?? {})]));
// The blocks whose words are fields: every block that stores something.
const EDITABLE = BLOCKS.filter((block) => Object.keys(block.attributes ?? {}).length).map((block) => block.name);

const InnerBlocks = Object.assign(() => null, { Content: () => null });
const Placeholder = () => null;
const TextControl = () => null;
const TextareaControl = () => null;
const SelectControl = () => null;
const Button = () => null;

/** WordPress's sprintf, for %s, %d and numbered %1$s. */
function sprintf(format, ...args) {
  let next = 0;
  return format.replace(/%(?:(\d+)\$)?[sd]/g, (match, position) => String(args[position ? Number(position) - 1 : next++]));
}

/**
 * Runs the script, with stores that record what's asked of them, what's spoken to screen
 * readers, and a small stand-in for React's effects: each mounted block keeps each of its
 * effects' dependencies and clean-up, by the order it calls them, runs the clean-up and
 * the effect again when they change (or every draw, for one with none), and the
 * clean-ups when unmounted.
 */
function load(declared = DECLARED, { stores = true, focused = [], frame = null, portraits = [] } = {}) {
  const registered = new Map();
  const calls = [];
  const spoken = [];
  let current = null;
  const record = (store) => new Proxy({}, { get: (target, action) => (...args) => calls.push([store, action, ...args]) });
  const wp = {
    blocks: {
      registerBlockType: (name, settings) => registered.set(name, settings),
      getBlockType: (name) => TYPES[name],
    },
    element: {
      createElement: (type, props, ...children) => ({ type, props: props ?? {}, children: children.flat() }),
      useEffect: (effect, deps) => {
        const slots = current.slots;
        const slot = (slots[current.index++] ??= { deps: null, cleanup: null });
        if (deps && slot.deps && deps.length === slot.deps.length && deps.every((dep, index) => dep === slot.deps[index])) return;
        if (slot.cleanup) slot.cleanup();
        slot.deps = deps ?? null;
        const cleanup = effect();
        slot.cleanup = typeof cleanup === 'function' ? cleanup : null;
      },
    },
    blockEditor: { useBlockProps: () => ({ className: 'wp-block' }), InnerBlocks },
    components: { Placeholder, TextControl, TextareaControl, SelectControl, Button },
    data: stores ? { dispatch: (store) => record(store), select: () => null } : undefined,
    i18n: { __: (text) => text, _x: (text) => text, _n: (one, many, count) => (count === 1 ? one : many), sprintf },
    a11y: { speak: (message) => spoken.push(message) },
  };
  const window = {
    wp,
    firehouseBlocks: declared,
    firehousePortraits: portraits,
    setTimeout: (fn) => fn(),
    // The field "Go to" focuses, found by its data attribute, in the editor's frame if
    // there's one, else in the page.
    document: { querySelector: (selector) => (selector.startsWith('iframe') ? frame : { focus: () => focused.push(selector) }) },
  };
  new Function('window', SOURCE)(window);

  /** Mounts a block: its tree, its attribute changes, and how to render it again or remove it. */
  function mount(name, attributes, clientId = 'abc') {
    const slots = [];
    const changes = [];
    const render = (next) => {
      current = { slots, index: 0 };
      return registered.get(name).edit({ attributes: next, setAttributes: (change) => changes.push(change), clientId });
    };
    return { tree: render(attributes), changes, rerender: render, unmount: () => slots.forEach((slot) => slot.cleanup && slot.cleanup()) };
  }
  return { registered, calls, spoken, mount };
}

/** Renders a block's edit with its attributes; returns the tree, the changes, the stores' calls and what's spoken. */
function edit(name, attributes, options) {
  const loaded = load(DECLARED, options);
  const mounted = loaded.mount(name, attributes);
  return { ...mounted, calls: loaded.calls, spoken: loaded.spoken };
}

/** Every one of the cards' buttons in a rendered tree, by its words. */
function buttons(tree) {
  return Object.fromEntries(find(tree, Button).map((node) => [text(node).join(''), node]));
}

/** Every string in a rendered tree, in order, the placeholder's label included. */
function text(node) {
  if (node === null || node === undefined || node === false) return [];
  if (typeof node === 'string') return [node];
  if (Array.isArray(node)) return node.flatMap(text);
  const own = node.type === Placeholder ? [node.props.label] : [];
  return [...own, ...node.children.flatMap(text)];
}

/** Every element of a type in a rendered tree. */
function find(node, type) {
  if (!node || typeof node !== 'object') return [];
  if (Array.isArray(node)) return node.flatMap((child) => find(child, type));
  return [...(node.type === type ? [node] : []), ...node.children.flatMap((child) => find(child, type))];
}

/** Every field in a rendered tree, one line or a few, by its label. */
function controls(tree) {
  return Object.fromEntries([...find(tree, TextControl), ...find(tree, TextareaControl)].map((node) => [node.props.label, node]));
}

/** A field's help, as its words. */
function help(field) {
  return text(field.props.help).join('');
}

const HERO = {
  eyebrow: 'NOAA GSL',
  heading: '<b>Fire</b> & weather',
  body: 'Research',
  primaryCta: { label: 'Submit a Finding', href: '' },
  secondaryCta: { label: 'Explore Projects', to: '/projects' },
  lock: { move: true },
};

describe('the block editor script', () => {
  it('registers every block the plugin has', () => {
    expect([...load().registered.keys()].sort()).toEqual(BLOCKS.map((block) => block.name).sort());
    expect(BLOCKS.length).toBeGreaterThan(10);
  });

  it('does nothing outside the block editor', () => {
    expect(() => new Function('window', SOURCE)({})).not.toThrow();
  });

  it('saves every block’s inner blocks, so a block that holds others never loses them', () => {
    for (const [name, settings] of load().registered) {
      const saved = settings.save();
      expect(saved.type, name).toBe(InnerBlocks.Content);
    }
    // The two blocks that hold others today, by their block.json.
    expect(BLOCKS.filter((block) => block.allowedBlocks?.length).map((block) => block.name).sort()).toEqual(['firehouse/about-sections', 'firehouse/hero']);
  });

  it('shows the words as fields in a group named for the block, each with its value as stored, and where buttons go', () => {
    const { tree } = edit('firehouse/hero', HERO);
    const fields = controls(tree);
    expect(Object.keys(fields)).toEqual(['Line above the heading', 'Heading', 'First button text', 'Second button text', 'Text']);
    expect(fields.Heading.props.value).toBe('<b>Fire</b> & weather');
    expect(fields.Heading.props['data-firehouse-field']).toBe('fh-abc-heading');
    // No control is given an ID: WordPress's own ties each label to its field, and a
    // textarea given another loses its label.
    expect(Object.values(fields).map((field) => field.props.id)).toEqual(Object.values(fields).map(() => undefined));
    expect(fields.Heading.type).toBe(TextControl);
    expect(fields.Text.type).toBe(TextareaControl);
    expect(fields['First button text'].props.value).toBe('Submit a Finding');
    expect(text(tree)).toEqual(expect.arrayContaining(['FireHouse hero', 'The Submit a Finding address in FireHouse → Settings', '/projects']));
    expect(text(tree).join(' ')).not.toMatch(/Lock|Class name|Metadata/);
    expect(SOURCE).not.toMatch(/dangerouslySetInnerHTML|RawHTML|innerHTML/);
    expect(find(tree, 'div').find((node) => node.props.role === 'group').props['aria-label']).toBe('FireHouse hero');
    // The explanation is a paragraph inside the box, not the Placeholder's instructions,
    // and says how to move between the page's parts by keyboard.
    const placeholder = find(tree, Placeholder)[0];
    expect(placeholder.props.instructions).toBeUndefined();
    expect(text(find(placeholder, 'p')[0])[0]).toMatch(/^The words on this part of the page\. FireHouse adds everything else.*the Down arrow at the end of a field moves on to the next part’s fields\. The parts below have their own\.$/);
    // The counts it holds follow it, locked.
    expect(find(tree, InnerBlocks)[0].props).toMatchObject({ templateLock: 'all', renderAppender: false });
  });

  it('stores a changed word as typed, keeping the rest of what the block stores', () => {
    const { tree, changes } = edit('firehouse/hero', HERO);
    const fields = controls(tree);
    fields.Heading.props.onChange('R&D <5 km>');
    fields['First button text'].props.onChange('Share a finding');
    expect(changes).toEqual([{ heading: 'R&D <5 km>' }, { primaryCta: { label: 'Share a finding', href: '' } }]);
  });

  it('shows a button the page doesn’t have as none, with no field and no hold', () => {
    const { tree, calls } = edit('firehouse/hero', { ...HERO, secondaryCta: {} });
    expect(Object.keys(controls(tree))).not.toContain('Second button text');
    expect(text(tree)).toEqual(expect.arrayContaining(['Second button: ', 'None on this part of the page']));
    expect(calls.map((call) => call[1])).toEqual(['unlockPostSaving', 'removeNotice']);
  });

  it('changes one entry of a list, keeping the others and what each stores beside its words', () => {
    const items = [
      { id: 'projects', source: 'publishedProjectCount', label: 'Projects', caption: '', accent: true },
      { id: 'submissions', source: 'static', value: 'Open', label: 'For submissions', caption: 'Any time' },
    ];
    const { tree, changes } = edit('firehouse/stats', { items });
    expect(find(tree, 'legend').map((node) => text(node)[0])).toEqual(['Count 1', 'Count 2']);
    expect(text(tree)).toEqual(expect.arrayContaining(['The projects visitors can see', 'Not counted: it shows the words below as written']));
    // A count that shows words, not a number, has them as a field.
    const fields = [...find(tree, TextControl)];
    expect(fields.filter((node) => node.props.label === 'Shown as').map((node) => node.props.value)).toEqual(['Open']);
    fields.filter((node) => node.props.label === 'Label')[1].props.onChange('Topics');
    expect(changes).toEqual([{ items: [items[0], { ...items[1], label: 'Topics' }] }]);
    expect(changes[0].items[0]).toBe(items[0]);
  });

  it('changes one paragraph, inside an entry too, and never adds or removes one', () => {
    const mission = edit('firehouse/about-mission', { heading: 'Mission', paragraphs: ['One.', 'Two.'] });
    controls(mission.tree)['Paragraph 2'].props.onChange('Second.');
    expect(mission.changes).toEqual([{ paragraphs: ['One.', 'Second.'] }]);

    const members = [{ name: 'Ana', photo: 'ana', photoId: 7, bio: ['First.', 'Second.'] }];
    const team = edit('firehouse/about-team', { heading: 'Team', email: 'team@noaa.gov', members });
    const fields = controls(team.tree);
    expect(Object.keys(fields)).toEqual(expect.arrayContaining(['Name', 'Biography, paragraph 1', 'Biography, paragraph 2', 'Portrait description', 'Email address']));
    expect(help(fields['Email address'])).toMatch(/@noaa\.gov/);
    fields['Biography, paragraph 2'].props.onChange('Later.');
    expect(team.changes).toEqual([{ members: [{ ...members[0], bio: ['First.', 'Later.'] }] }]);
    // The stored list isn't changed in place: the change is a new one.
    expect(members[0].bio).toEqual(['First.', 'Second.']);
  });

  it('adds and removes a paragraph, putting the focus on it or the next, and says so', () => {
    const focused = [];
    const mission = edit('firehouse/about-mission', { heading: 'Mission', paragraphs: ['One.', 'Two.'] }, { focused });
    const actions = buttons(mission.tree);
    expect(Object.keys(actions)).toEqual(['Remove Paragraph 1', 'Remove Paragraph 2', 'Add a paragraph']);
    actions['Add a paragraph'].props.onClick();
    expect(mission.changes.at(-1)).toEqual({ paragraphs: ['One.', 'Two.', ''] });
    mission.rerender({ heading: 'Mission', paragraphs: ['One.', 'Two.', ''] });
    expect(focused.at(-1)).toBe('[data-firehouse-field="fh-abc-paragraphs-2"]');
    expect(mission.spoken).toEqual(['Paragraph added.']);

    actions['Remove Paragraph 1'].props.onClick();
    expect(mission.changes.at(-1)).toEqual({ paragraphs: ['Two.'] });
    mission.rerender({ heading: 'Mission', paragraphs: ['Two.'] });
    expect(focused.at(-1)).toBe('[data-firehouse-field="fh-abc-paragraphs-0"]');
    expect(mission.spoken.at(-1)).toBe('Paragraph 1 removed.');

    // With none, there's still the button to add one.
    expect(Object.keys(buttons(edit('firehouse/about-mission', { heading: 'Mission', paragraphs: [] }).tree))).toEqual(['Add a paragraph']);
  });

  it('adds, moves and removes a team member, the focus following, and leaves fixed lists fixed', () => {
    const focused = [];
    const members = [
      { name: 'Ana', photoId: 7, bio: ['A.'] },
      { name: 'Ben', bio: ['B.'] },
    ];
    const team = edit('firehouse/about-team', { heading: 'Team', members }, { focused });
    const actions = buttons(team.tree);
    // In each card, inside its group: its biography's, then its own; then one to add.
    expect(find(team.tree, Button).map((node) => text(node).join(''))).toEqual([
      'Remove Biography, paragraph 1',
      'Add a paragraph to the biography',
      'Move Team member 1 down',
      'Remove Team member 1',
      'Remove Biography, paragraph 1',
      'Add a paragraph to the biography',
      'Move Team member 2 up',
      'Remove Team member 2',
      'Add a team member',
    ]);

    actions['Move Team member 2 up'].props.onClick();
    expect(team.changes.at(-1)).toEqual({ members: [members[1], members[0]] });
    team.rerender({ heading: 'Team', members: [members[1], members[0]] });
    expect(focused.at(-1)).toBe('[data-firehouse-action="fh-abc-members-0-up"]');
    expect(team.spoken.at(-1)).toBe('Team member 2 moved: it’s now Team member 1.');

    actions['Add a team member'].props.onClick();
    expect(team.changes.at(-1)).toEqual({ members: [...members, { name: '', bio: [''] }] });
    team.rerender({ heading: 'Team', members: [...members, { name: '', bio: [''] }] });
    expect(focused.at(-1)).toBe('[data-firehouse-field="fh-abc-members-2-name"]');
    // A new member has no name yet, so the save is held until they're named.
    expect(team.calls.filter((call) => call[1] === 'lockPostSaving')).toHaveLength(1);

    actions['Remove Team member 1'].props.onClick();
    expect(team.changes.at(-1)).toEqual({ members: [members[1]] });
    expect(team.spoken.at(-1)).toBe('Team member 1 removed.');

    // The audiences and the steps stay as many as they are.
    const audiences = edit('firehouse/about-audiences', { heading: 'Who', items: [{ title: 'One' }, { title: 'Two' }] });
    expect(find(audiences.tree, Button)).toEqual([]);
  });

  it('chooses a team member’s portrait from the FireHouse portraits, or none', () => {
    const portraits = [
      { id: 7, title: 'ana', url: 'https://example.org/ana-150x150.webp' },
      { id: 9, title: 'ben', url: '' },
    ];
    const members = [{ name: 'Ana', photo: 'ana', photoId: 7, photoAlt: '', bio: [] }];
    const team = edit('firehouse/about-team', { heading: 'Team', members }, { portraits });
    const picker = find(team.tree, SelectControl)[0];
    expect(picker.props.label).toBe('Portrait');
    expect(picker.props.value).toBe('7');
    expect(picker.props.options).toEqual([
      { label: 'None: the card shows initials', value: '' },
      { label: 'ana', value: '7' },
      { label: 'ben', value: '9' },
    ]);
    expect(find(team.tree, 'img')[0].props).toMatchObject({ src: 'https://example.org/ana-150x150.webp', alt: '' });
    picker.props.onChange('9');
    expect(team.changes.at(-1)).toEqual({ members: [{ ...members[0], photoId: 9 }] });
    picker.props.onChange('');
    expect(team.changes.at(-1).members[0]).not.toHaveProperty('photoId');
    expect(team.changes.at(-1).members[0].photo).toBe('ana');

    // A portrait set some other way is still named, so choosing again doesn't lose it.
    const other = edit('firehouse/about-team', { heading: 'Team', members: [{ name: 'Ana', photoId: 42 }] }, { portraits });
    expect(find(other.tree, SelectControl)[0].props.options.at(-1)).toEqual({ label: 'Image 42, not among the FireHouse portraits', value: '42' });
  });

  it('shows where a link goes, a page or an address, and one that goes nowhere as left out', () => {
    const { tree, calls } = edit('firehouse/about-process', {
      heading: 'How',
      steps: [],
      links: [{ label: 'Needs', to: '/#needs-source' }, { label: 'Survey', href: '' }, { label: '', to: '' }],
    });
    expect(text(tree)).toEqual(expect.arrayContaining(['/#needs-source', 'The Submit a Finding address in FireHouse → Settings', 'Nowhere, so the page leaves it out']));
    expect([...find(tree, TextControl)].filter((node) => node.props.label === 'Link text')).toHaveLength(2);
    expect(calls.map((call) => call[1])).toEqual(['unlockPostSaving', 'removeNotice']);
  });

  it('shows a button stored with only its text, which goes to the survey’s address, and keeps it while it’s emptied', () => {
    const hero = edit('firehouse/hero', { ...HERO, secondaryCta: { label: 'Explore' } });
    expect(controls(hero.tree)['Second button text'].props.value).toBe('Explore');
    expect(text(hero.tree)).toContain('The Submit a Finding address in FireHouse → Settings');
    // Emptied, the field stays, marked, and the save is held, rather than the button
    // being left out of the page.
    const emptied = hero.rerender({ ...HERO, secondaryCta: { label: '' } });
    expect(controls(emptied)['Second button text'].props['aria-invalid']).toBe(true);
    expect(hero.calls.filter((call) => call[1] === 'lockPostSaving')).toHaveLength(1);
  });

  it('goes to a field in the editor’s frame when there is one', () => {
    const focused = [];
    const frame = { contentDocument: { querySelector: (selector) => ({ focus: () => focused.push('frame ' + selector) }) } };
    const { calls } = edit('firehouse/about-intro', { heading: '' }, { frame });
    calls[1][4].actions[0].onClick();
    expect(focused).toEqual(['frame [data-firehouse-field="fh-abc-heading"]']);
  });

  it('goes to the field each kind of empty word is in', () => {
    const cases = [
      ['firehouse/hero', { ...HERO, primaryCta: { label: '', href: '' } }],
      ['firehouse/stats', { items: [{ source: 'static', value: '', label: 'Open' }] }],
      ['firehouse/about-process', { heading: 'How', steps: [{ title: 'One' }, { title: '' }], links: [] }],
      ['firehouse/about-team', { heading: 'Team', members: [{ name: '', bio: [] }] }],
    ];
    for (const [name, attributes] of cases) {
      const focused = [];
      const { tree, calls } = edit(name, attributes, { focused });
      calls[1][4].actions[0].onClick();
      const fields = [...find(tree, TextControl), ...find(tree, TextareaControl)].map((field) => field.props['data-firehouse-field']);
      expect(fields, name).toContain(focused[0].slice('[data-firehouse-field="'.length, -2));
    }
  });

  it('shows an entry that isn’t one as it is, never as fields, and keeps it when another changes', () => {
    const steps = [[], 'odd', { title: 'Two' }];
    const { tree, changes, calls } = edit('firehouse/about-process', { heading: 'How', steps, links: [] });
    expect(controls(tree)['Title']).toBeDefined();
    expect(find(tree, 'fieldset')).toHaveLength(1);
    expect(calls.map((call) => call[1])).toEqual(['unlockPostSaving', 'removeNotice']);
    controls(tree)['Title'].props.onChange('2');
    expect(changes).toEqual([{ steps: [steps[0], 'odd', { title: '2' }] }]);
  });

  it('marks an empty required word, holds the save, says which, goes to it, and lets go once it’s filled', () => {
    const focused = [];
    const empty = edit('firehouse/hero', { ...HERO, heading: '  ', primaryCta: { label: '', href: '' } }, { focused });
    const fields = controls(empty.tree);
    expect(fields.Heading.props['aria-invalid']).toBe(true);
    expect(fields.Heading.props['aria-required']).toBe(true);
    expect(fields.Heading.props.style).toEqual({ outline: '2px solid #cc1818', outlineOffset: '1px' });
    expect(help(fields.Heading)).toBe('Required. Error: it’s empty, so the page can’t be saved.');
    expect(fields['Line above the heading'].props['aria-required']).toBeUndefined();
    expect(fields['Line above the heading'].props.style).toBeUndefined();
    expect(empty.calls.slice(0, 1)).toEqual([['core/editor', 'lockPostSaving', 'firehouse-required-abc']]);
    const [, , status, message, options] = empty.calls[1];
    expect([status, message]).toEqual(['error', 'FireHouse hero: fill in Heading, First button text. The page can’t be saved while they’re empty.']);
    expect(options).toMatchObject({ id: 'firehouse-required-abc', isDismissible: false });
    expect(options.actions.map((action) => action.label)).toEqual(['Go to Heading in FireHouse hero']);
    options.actions[0].onClick();
    expect(empty.calls[2]).toEqual(['core/block-editor', 'selectBlock', 'abc']);
    expect(focused).toEqual(['[data-firehouse-field="fh-abc-heading"]']);

    // Filled in, the hold and the notice go; one gap left reads in the singular.
    empty.rerender({ ...HERO, primaryCta: { label: '', href: '' } });
    expect(empty.calls.slice(3)).toEqual([
      ['core/editor', 'unlockPostSaving', 'firehouse-required-abc'],
      ['core/notices', 'removeNotice', 'firehouse-required-abc'],
      ['core/editor', 'lockPostSaving', 'firehouse-required-abc'],
      ['core/notices', 'createNotice', 'error', 'FireHouse hero: fill in First button text. The page can’t be saved while it’s empty.', expect.objectContaining({ id: 'firehouse-required-abc' })],
    ]);
    const before = empty.calls.length;
    empty.rerender({ ...HERO, primaryCta: { label: '', href: '' }, body: 'Changed' });
    expect(empty.calls).toHaveLength(before);
    empty.rerender(HERO);
    expect(empty.calls.slice(before)).toEqual([
      ['core/editor', 'unlockPostSaving', 'firehouse-required-abc'],
      ['core/notices', 'removeNotice', 'firehouse-required-abc'],
      ['core/editor', 'unlockPostSaving', 'firehouse-required-abc'],
      ['core/notices', 'removeNotice', 'firehouse-required-abc'],
    ]);
  });

  it('lets each block hold and let go of its own hold', () => {
    const { calls, mount } = load();
    const hero = mount('firehouse/hero', { ...HERO, heading: '' }, 'one');
    mount('firehouse/about-intro', { heading: '' }, 'two');
    expect(calls.filter((call) => call[1] === 'lockPostSaving').map((call) => call[2])).toEqual(['firehouse-required-one', 'firehouse-required-two']);
    const before = calls.length;
    hero.rerender(HERO);
    expect(calls.slice(before).map((call) => call[2])).toEqual(['firehouse-required-one', 'firehouse-required-one', 'firehouse-required-one', 'firehouse-required-one']);
    hero.unmount();
  });

  it('names a list entry’s empty required word with its entry', () => {
    const { calls } = edit('firehouse/about-process', {
      heading: 'How',
      steps: [{ title: 'One' }, { title: '' }],
      links: [{ label: '', to: '/about' }],
    });
    expect(calls[1][3]).toBe('FireHouse About process: fill in Step 2: Title, Link 1: Link text. The page can’t be saved while they’re empty.');
    expect(calls[1][4].actions[0].label).toBe('Go to Step 2: Title in FireHouse About process');
  });

  it('works where the editor has no stores to hold a save, as in the widgets editor', () => {
    expect(() => edit('firehouse/hero', { ...HERO, heading: '' }, { stores: false })).not.toThrow();
  });

  it('gives every block that stores something fields, which change only what it declares', () => {
    for (const name of EDITABLE) {
      // One of everything: a word, an entry in each list, an object with somewhere to go.
      const one = { string: 'x', array: [{}], object: { href: '' } };
      const attributes = Object.fromEntries(DECLARED[name].map((key) => [key, one[TYPES[name].attributes[key].type] ?? TYPES[name].attributes[key].default]));
      const { tree, changes } = edit(name, attributes);
      const fields = [...find(tree, TextControl), ...find(tree, TextareaControl)];
      expect(fields.length, name).toBeGreaterThan(0);
      fields.forEach((field) => field.props.onChange('y'));
      for (const change of changes) {
        expect(DECLARED[name], name).toEqual(expect.arrayContaining(Object.keys(change)));
      }
    }
  });

  it('lists what a block without fields stores, read-only, as text', () => {
    const { mount } = load({ ...DECLARED, 'firehouse/subnav': ['note'] });
    const { tree } = mount('firehouse/subnav', { note: JSON.parse('{"label":"Explore","__proto__":"x","constructor":"y"}') });
    const words = text(tree);
    expect(words).toEqual(expect.arrayContaining(['Note', 'Label', 'Explore', '__proto__', 'x', 'Constructor', 'y']));
    expect(words.every((word) => typeof word === 'string')).toBe(true);
    expect(find(tree, TextControl)).toEqual([]);
  });

  it('says when FireHouse adds a block, and when a block only holds others', () => {
    const { mount } = load();
    expect(text(mount('firehouse/subnav', {}).tree)).toContain('FireHouse adds this part of the page. Nothing in it is stored in this page.');
    expect(text(mount('firehouse/about-sections', {}).tree)).toContain('Holds the parts below, which store their own. Nothing else in it is stored in this page.');
  });
});
