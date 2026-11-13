/**
 * The plugin's block editor script (wp-content/plugins/firehouse/assets/js/block-editor.js),
 * run against a stand-in for WordPress's `wp` packages, with the plugin's real blocks as
 * WordPress hands them to the editor: each block.json's title, attributes and
 * allowedBlocks, plus the attributes WordPress adds to every block.
 *
 * Saving must keep the page's blocks: every block saves its inner blocks, so one that
 * holds others can't lose them. Each block lists what the page stores in it, as text.
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
  BLOCKS.map((block) => [
    block.name,
    { title: block.title, allowedBlocks: block.allowedBlocks, attributes: { ...block.attributes, lock: {}, metadata: {}, className: {}, style: {} } },
  ]),
);
// What includes/class-blocks.php passes: each block's declared attributes.
const DECLARED = Object.fromEntries(BLOCKS.map((block) => [block.name, Object.keys(block.attributes ?? {})]));

const InnerBlocks = Object.assign(() => null, { Content: () => null });
const Placeholder = () => null;

/** Runs the script, and returns what it registered. */
function load(declared = DECLARED) {
  const registered = new Map();
  const wp = {
    blocks: {
      registerBlockType: (name, settings) => registered.set(name, settings),
      getBlockType: (name) => TYPES[name],
    },
    element: { createElement: (type, props, ...children) => ({ type, props: props ?? {}, children: children.flat() }) },
    blockEditor: { useBlockProps: () => ({ className: 'wp-block' }), InnerBlocks },
    components: { Placeholder },
    i18n: { __: (text) => text },
  };
  new Function('window', SOURCE)({ wp, firehouseBlocks: declared });
  return registered;
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

describe('the block editor script', () => {
  it('registers every block the plugin has', () => {
    expect([...load().keys()].sort()).toEqual(BLOCKS.map((block) => block.name).sort());
    expect(BLOCKS.length).toBeGreaterThan(10);
  });

  it('does nothing outside the block editor', () => {
    expect(() => new Function('window', SOURCE)({})).not.toThrow();
  });

  it('saves every block’s inner blocks, so a block that holds others never loses them', () => {
    for (const [name, settings] of load()) {
      const saved = settings.save();
      expect(saved.type, name).toBe(InnerBlocks.Content);
    }
    // The two blocks that hold others today, by their block.json.
    expect(BLOCKS.filter((block) => block.allowedBlocks?.length).map((block) => block.name).sort()).toEqual(['firehouse/about-sections', 'firehouse/hero']);
  });

  it('lists what the page stores, as text, and none of WordPress’s own attributes', () => {
    const tree = load().get('firehouse/hero').edit({
      attributes: {
        eyebrow: 'NOAA GSL',
        heading: '<b>Fire</b> & weather',
        body: '',
        primaryCta: { label: 'Submit a Finding', href: '' },
        secondaryCta: { label: 'Explore Projects', to: '/projects' },
        lock: { move: true },
        className: 'x',
      },
    });
    const words = text(tree);
    expect(words).toEqual(
      expect.arrayContaining([
        'FireHouse hero',
        'Line above the heading',
        'NOAA GSL',
        'Heading',
        '<b>Fire</b> & weather',
        'Body',
        '(nothing stored)',
        'First button',
        'Submit a Finding',
        'The Submit a Finding address in FireHouse → Settings',
        'FireHouse page',
        '/projects',
      ]),
    );
    expect(words.join(' ')).not.toMatch(/Lock|Class name|Metadata/);
    expect(SOURCE).not.toMatch(/dangerouslySetInnerHTML|RawHTML/);
    // The explanation is a paragraph inside the box, not the Placeholder's instructions,
    // which WordPress hides in a narrow box and reads aloud.
    const placeholder = find(tree, Placeholder)[0];
    expect(placeholder.props.instructions).toBeUndefined();
    expect(text(find(placeholder, 'p')[0])[0]).toMatch(/^This page stores what’s listed here, and the parts below store their own\. FireHouse adds the rest/);
    // The counts it holds follow it, locked.
    expect(find(tree, InnerBlocks)[0].props).toMatchObject({ templateLock: 'all', renderAppender: false });
  });

  it('shows words as paragraphs, other lists numbered, and yes or no', () => {
    const registered = load();
    const mission = registered.get('firehouse/about-mission').edit({ attributes: { heading: 'Mission', paragraphs: ['One.', 'Two.'] } });
    expect(find(mission, 'ol')).toEqual([]);
    expect(text(find(mission, 'p').slice(1))).toEqual(['One.', 'Two.']);
    const counts = registered.get('firehouse/stats').edit({ attributes: { items: [{ label: 'Projects', accent: true }, { label: 'Topic areas', accent: false }] } });
    expect(find(counts, 'ol')).toHaveLength(1);
    expect(text(counts)).toEqual(expect.arrayContaining(['Entries', 'Projects', 'Highlighted', 'Yes', 'Topic areas', 'No']));
  });

  it('says when FireHouse adds a block, and when a block only holds others', () => {
    const registered = load();
    expect(text(registered.get('firehouse/subnav').edit({ attributes: {} }))).toContain('FireHouse adds this part of the page. Nothing in it is stored in this page.');
    expect(text(registered.get('firehouse/about-sections').edit({ attributes: {} }))).toContain('Holds the parts below, which store their own. Nothing else in it is stored in this page.');
  });

  it('shows a stored key that names something on every object as text', () => {
    const stored = JSON.parse('{"label":"Explore","__proto__":"x","constructor":"y"}');
    const words = text(load().get('firehouse/submit-band').edit({ attributes: { heading: 'H', body: 'B', cta: stored } }));
    expect(words).toEqual(expect.arrayContaining(['__proto__', 'x', 'Constructor', 'y']));
    expect(words.every((word) => typeof word === 'string')).toBe(true);
  });
});
