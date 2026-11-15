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

/** WordPress's sprintf, for %s, %d and numbered %1$s. */
function sprintf(format, ...args) {
  let next = 0;
  return format.replace(/%(?:(\d+)\$)?[sd]/g, (match, position) => String(args[position ? Number(position) - 1 : next++]));
}

/** Runs the script; returns what it registered, and what it asked of the editor's stores. */
function load(declared = DECLARED, { stores = true } = {}) {
  const registered = new Map();
  const calls = [];
  const cleanups = [];
  const record = (store) => new Proxy({}, { get: (target, action) => (...args) => calls.push([store, action, ...args]) });
  const wp = {
    blocks: {
      registerBlockType: (name, settings) => registered.set(name, settings),
      getBlockType: (name) => TYPES[name],
    },
    element: {
      createElement: (type, props, ...children) => ({ type, props: props ?? {}, children: children.flat() }),
      // Effects run as the block renders; their clean-ups wait for cleanup().
      useEffect: (effect) => {
        const cleanup = effect();
        if (typeof cleanup === 'function') cleanups.push(cleanup);
      },
    },
    blockEditor: { useBlockProps: () => ({ className: 'wp-block' }), InnerBlocks },
    components: { Placeholder, TextControl, TextareaControl },
    data: stores ? { dispatch: (store) => record(store) } : undefined,
    i18n: { __: (text) => text, sprintf },
  };
  new Function('window', SOURCE)({ wp, firehouseBlocks: declared });
  return { registered, calls, cleanup: () => cleanups.splice(0).forEach((fn) => fn()) };
}

/** Renders a block's edit with its attributes; returns the tree and the attribute changes. */
function edit(name, attributes, options) {
  const loaded = load(DECLARED, options);
  const changes = [];
  const tree = loaded.registered.get(name).edit({ attributes, setAttributes: (change) => changes.push(change), clientId: 'abc' });
  return { tree, changes, calls: loaded.calls, cleanup: loaded.cleanup };
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

  it('shows the words as fields, each with its value as stored, and where buttons go', () => {
    const { tree } = edit('firehouse/hero', HERO);
    const fields = controls(tree);
    expect(Object.keys(fields)).toEqual(['Line above the heading', 'Heading', 'First button text', 'Second button text', 'Text']);
    expect(fields.Heading.props.value).toBe('<b>Fire</b> & weather');
    expect(fields.Heading.type).toBe(TextControl);
    expect(fields.Text.type).toBe(TextareaControl);
    expect(fields['First button text'].props.value).toBe('Submit a Finding');
    expect(text(tree)).toEqual(expect.arrayContaining(['FireHouse hero', 'The Submit a Finding address in FireHouse → Settings', '/projects']));
    expect(text(tree).join(' ')).not.toMatch(/Lock|Class name|Metadata/);
    expect(SOURCE).not.toMatch(/dangerouslySetInnerHTML|RawHTML|innerHTML/);
    // The explanation is a paragraph inside the box, not the Placeholder's instructions.
    const placeholder = find(tree, Placeholder)[0];
    expect(placeholder.props.instructions).toBeUndefined();
    expect(text(find(placeholder, 'p')[0])[0]).toMatch(/^The words on this part of the page\. FireHouse adds the rest/);
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

  it('changes one entry of a list, keeping the others and what each stores beside its words', () => {
    const items = [
      { id: 'projects', source: 'publishedProjects', label: 'Projects', caption: '', accent: true },
      { id: 'topics', source: 'topicAreas', label: 'Topic areas', caption: 'Across the hub' },
    ];
    const { tree, changes } = edit('firehouse/stats', { items });
    expect(find(tree, 'legend').map((node) => text(node)[0])).toEqual(['Count 1', 'Count 2']);
    expect(text(tree)).toEqual(expect.arrayContaining(['publishedProjects', 'topicAreas']));
    const labels = [...find(tree, TextControl)].filter((node) => node.props.label === 'Label');
    labels[1].props.onChange('Topics');
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
    expect(fields['Email address'].props.help).toMatch(/@noaa\.gov/);
    expect(text(team.tree)).toEqual(expect.arrayContaining(['Portrait (Media Library ID): ', '7']));
    fields['Biography, paragraph 2'].props.onChange('Later.');
    expect(team.changes).toEqual([{ members: [{ ...members[0], bio: ['First.', 'Later.'] }] }]);
    expect(SOURCE).not.toMatch(/\.push\(|splice\(|concat\(/);
  });

  it('holds the save while a required word is empty, says which, and lets go once it’s filled', () => {
    const empty = edit('firehouse/hero', { ...HERO, heading: '  ', primaryCta: { label: '', href: '' } });
    const fields = controls(empty.tree);
    expect(fields.Heading.props['aria-invalid']).toBe(true);
    expect(fields.Heading.props['aria-required']).toBe(true);
    expect(fields.Heading.props.help).toMatch(/^Required\. It’s empty, so the page can’t be saved\./);
    expect(fields['Line above the heading'].props['aria-required']).toBeUndefined();
    expect(empty.calls).toEqual([
      ['core/editor', 'lockPostSaving', 'firehouse-required-abc'],
      ['core/notices', 'createNotice', 'error', 'FireHouse hero: fill in Heading, First button text. The page can’t be saved while they’re empty.', { id: 'firehouse-required-abc', isDismissible: false }],
    ]);
    // Removed from the page, the block lets go of its hold.
    empty.cleanup();
    expect(empty.calls.slice(2)).toEqual([
      ['core/editor', 'unlockPostSaving', 'firehouse-required-abc'],
      ['core/notices', 'removeNotice', 'firehouse-required-abc'],
    ]);

    const filled = edit('firehouse/hero', HERO);
    expect(controls(filled.tree).Heading.props['aria-invalid']).toBeUndefined();
    expect(filled.calls).toEqual([
      ['core/editor', 'unlockPostSaving', 'firehouse-required-abc'],
      ['core/notices', 'removeNotice', 'firehouse-required-abc'],
    ]);
  });

  it('names a list entry’s empty required word with its entry', () => {
    const { calls } = edit('firehouse/about-process', {
      heading: 'How',
      steps: [{ title: 'One' }, { title: '' }],
      links: [{ label: '', to: '/about' }],
    });
    expect(calls[1][3]).toBe('FireHouse About process: fill in Step 2: Title, Link 1: Link text. The page can’t be saved while they’re empty.');
  });

  it('works where the editor has no stores to hold a save, as in the widgets editor', () => {
    expect(() => edit('firehouse/hero', { ...HERO, heading: '' }, { stores: false })).not.toThrow();
  });

  it('gives every block that stores something fields, which change only what it declares', () => {
    for (const name of EDITABLE) {
      // One of everything: a word, an entry in each list, an empty object.
      const one = { string: 'x', array: [{}], object: {} };
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
    const { registered } = load({ ...DECLARED, 'firehouse/subnav': ['note'] });
    const tree = registered.get('firehouse/subnav').edit({ attributes: { note: JSON.parse('{"label":"Explore","__proto__":"x","constructor":"y"}') }, setAttributes: () => {}, clientId: 'x' });
    const words = text(tree);
    expect(words).toEqual(expect.arrayContaining(['Note', 'Label', 'Explore', '__proto__', 'x', 'Constructor', 'y']));
    expect(words.every((word) => typeof word === 'string')).toBe(true);
    expect(find(tree, TextControl)).toEqual([]);
  });

  it('says when FireHouse adds a block, and when a block only holds others', () => {
    const { registered } = load();
    const render = (name) => registered.get(name).edit({ attributes: {}, setAttributes: () => {}, clientId: 'x' });
    expect(text(render('firehouse/subnav'))).toContain('FireHouse adds this part of the page. Nothing in it is stored in this page.');
    expect(text(render('firehouse/about-sections'))).toContain('Holds the parts below, which store their own. Nothing else in it is stored in this page.');
  });
});
