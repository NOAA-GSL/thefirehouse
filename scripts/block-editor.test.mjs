/**
 * The plugin's block editor script (wp-content/plugins/firehouse/assets/js/block-editor.js),
 * run against a stand-in for WordPress's `wp` packages. Saving must keep the page as
 * stored: a block that holds no others saves nothing, and one that holds others saves
 * only them. Each block shows the words the page stores in it, as text.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const SOURCE = readFileSync(
  fileURLToPath(new URL('../wp-content/plugins/firehouse/assets/js/block-editor.js', import.meta.url)),
  'utf8',
);

const InnerBlocks = Object.assign(() => null, { Content: () => null });
const Placeholder = () => null;

/** Runs the script with these block types, and returns what it registered. */
function load(types, names = Object.keys(types)) {
  const registered = new Map();
  const wp = {
    blocks: {
      registerBlockType: (name, settings) => registered.set(name, settings),
      getBlockType: (name) => types[name],
    },
    element: { createElement: (type, props, ...children) => ({ type, props: props ?? {}, children: children.flat() }) },
    blockEditor: { useBlockProps: () => ({ className: 'wp-block' }), InnerBlocks },
    components: { Placeholder },
    i18n: { __: (text) => text },
  };
  new Function('window', SOURCE)({ wp, firehouseBlocks: names });
  return registered;
}

/** Every string in a rendered tree, in order, the placeholder's label and instructions included. */
function text(node) {
  if (node === null || node === undefined || node === false) return [];
  if (typeof node === 'string') return [node];
  if (Array.isArray(node)) return node.flatMap(text);
  const own = node.type === Placeholder ? [node.props.label, node.props.instructions] : [];
  return [...own, ...node.children.flatMap(text)];
}

const TYPES = {
  'firehouse/hero': {
    title: 'FireHouse hero',
    allowedBlocks: ['firehouse/stats'],
    attributes: { eyebrow: {}, heading: {}, body: {}, primaryCta: {}, lock: {}, metadata: {}, className: {} },
  },
  'firehouse/stats': { title: 'FireHouse live counts', attributes: { items: {} } },
  'firehouse/about-sections': { title: 'FireHouse About sections', allowedBlocks: ['firehouse/about-mission'], attributes: { lock: {} } },
  'firehouse/subnav': { title: 'FireHouse bar', attributes: { lock: {}, metadata: {} } },
};

describe('the block editor script', () => {
  it('registers every block it is given', () => {
    expect([...load(TYPES).keys()]).toEqual(Object.keys(TYPES));
  });

  it('does nothing outside the block editor', () => {
    expect(() => new Function('window', SOURCE)({})).not.toThrow();
  });

  it('saves only the blocks a block holds, and nothing for one that holds none', () => {
    const registered = load(TYPES);
    for (const name of ['firehouse/hero', 'firehouse/about-sections']) {
      const saved = registered.get(name).save();
      expect(saved.type).toBe(InnerBlocks.Content);
      expect(saved.children).toEqual([]);
    }
    for (const name of ['firehouse/stats', 'firehouse/subnav']) expect(registered.get(name).save()).toBeNull();
  });

  it('shows the words the page stores, as text, and none of WordPress’s own attributes', () => {
    const tree = load(TYPES).get('firehouse/hero').edit({
      attributes: {
        eyebrow: 'NOAA GSL',
        heading: '<b>Fire</b> & weather',
        body: '',
        primaryCta: { label: 'Explore', href: '/firehouse/projects/' },
        lock: { move: true },
        className: 'x',
      },
    });
    const words = text(tree);
    expect(words).toEqual(
      expect.arrayContaining(['FireHouse hero', 'Eyebrow', 'NOAA GSL', 'Heading', '<b>Fire</b> & weather', 'Body', '(empty)', 'First button', 'Label', 'Explore', 'Link', '/firehouse/projects/']),
    );
    expect(words.join(' ')).not.toMatch(/Lock|Class name|Metadata/);
    expect(SOURCE).not.toMatch(/dangerouslySetInnerHTML|RawHTML/);
    // The counts it holds follow it, locked.
    const inner = tree.children.find((child) => child?.type === InnerBlocks);
    expect(inner.props).toMatchObject({ templateLock: 'all', renderAppender: false });
  });

  it('lists a list, and says when a block stores nothing', () => {
    const registered = load(TYPES);
    const counts = text(registered.get('firehouse/stats').edit({ attributes: { items: [{ label: 'Projects' }, { label: 'Topic areas' }] } }));
    expect(counts).toEqual(expect.arrayContaining(['Items', 'Label', 'Projects', 'Topic areas']));
    expect(text(registered.get('firehouse/subnav').edit({ attributes: {} }))).toContain('Printed by the plugin. Nothing in it is stored in this page.');
    expect(text(registered.get('firehouse/about-sections').edit({ attributes: {} }))).toContain('Holds the parts below. Nothing else in it is stored in this page.');
  });
});
