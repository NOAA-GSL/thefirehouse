import { createHash } from 'node:crypto';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';
import postcss from 'postcss';
import { describe, expect, it } from 'vitest';
import { decodeCssEscapes } from './scope-css.ts';

/**
 * The plugin's fonts, as scripts/fetch-fonts.mjs left them. fonts.css is the one
 * stylesheet the plugin loads outside .fh-app, and firehouse.css's checks never read
 * it, so this does: only the site's own two families, only the files beside it, and
 * each file the one SOURCES.json says came from Google.
 */
const ASSETS = fileURLToPath(new URL('../wp-content/plugins/firehouse/assets/', import.meta.url));
const FONTS = resolve(ASSETS, 'fonts');
const css = readFileSync(resolve(ASSETS, 'css/fonts.css'), 'utf8');
const sources = JSON.parse(readFileSync(resolve(FONTS, 'SOURCES.json'), 'utf8'));
const onDisk = readdirSync(FONTS);

const SRC = /^url\('\.\.\/fonts\/([a-z0-9-]+\.woff2)'\) format\('woff2'\)$/;
const FAMILIES = ["'Archivo'", "'Public Sans'"];
const DESCRIPTORS = ['font-family', 'font-style', 'font-weight', 'font-stretch', 'font-display', 'src', 'unicode-range'];

/**
 * fonts.css line by line, as scripts/fetch-fonts.mjs writes it. PostCSS and a browser
 * tokenize some CSS differently (a backslash before `/*`, say), so text PostCSS reads
 * as a comment can be rules to a browser. This grammar has no room for that: no
 * backslash, no comment but the header and the subset labels, and each descriptor
 * with the one shape its value can take.
 */
const LINE = [
  /^$/,
  /^\/\* [a-z-]+ \*\/$/,
  /^@font-face \{$/,
  /^  font-family: '(?:Archivo|Public Sans)';$/,
  /^  font-style: (?:normal|italic);$/,
  /^  font-weight: [1-9]00;$/,
  /^  font-stretch: 100%;$/,
  /^  font-display: swap;$/,
  /^  src: url\('\.\.\/fonts\/[a-z0-9-]+\.woff2'\) format\('woff2'\);$/,
  /^  unicode-range: U\+[0-9A-F?]+(?:-[0-9A-F]+)?(?:, U\+[0-9A-F?]+(?:-[0-9A-F]+)?)*;$/,
  /^\}$/,
];
const REQUIRED = ['font-family', 'font-style', 'font-weight', 'font-display', 'src', 'unicode-range'];

describe('fonts.css, line by line', () => {
  const lines = css.replace(/\r\n/g, '\n').split('\n');
  const headerEnd = lines.indexOf(' */');

  it('opens with one comment that closes only on its last line', () => {
    expect(lines[0]).toBe('/*');
    expect(headerEnd).toBeGreaterThan(0);
    for (const line of lines.slice(1, headerEnd)) expect(line, line).toMatch(/^ \*(?: [^*\\]*(?:\*(?!\/)[^*\\]*)*)?$/);
  });

  it('holds nothing but the generated lines, with no backslash anywhere', () => {
    expect(css).not.toContain('\\');
    for (const line of lines.slice(headerEnd + 1)) {
      expect(LINE.some((pattern) => pattern.test(line)), JSON.stringify(line)).toBe(true);
    }
  });

  it('gives every @font-face each descriptor it needs, once', () => {
    const blocks = css.slice(css.indexOf(' */\n') + 4).split('@font-face {').slice(1);
    expect(blocks.length).toBeGreaterThan(0);
    for (const block of blocks) {
      const body = block.slice(0, block.indexOf('}'));
      const names = [...body.matchAll(/^  ([a-z-]+):/gm)].map((match) => match[1]);
      expect(names, body).toEqual([...new Set(names)]);
      for (const name of REQUIRED) expect(names, body).toContain(name);
    }
  });
});

describe('fonts.css', () => {
  const root = postcss.parse(css);
  const faces = root.nodes.filter((node) => node.type !== 'comment');

  it('holds only @font-face rules for the site’s two families', () => {
    expect(faces.length).toBeGreaterThan(0);
    for (const face of faces) {
      expect(face.type === 'atrule' && face.name === 'font-face', `${face.toString().slice(0, 60)}`).toBe(true);
      for (const node of face.nodes) {
        expect(node.type).toBe('decl');
        expect(DESCRIPTORS).toContain(decodeCssEscapes(node.prop).toLowerCase());
      }
      // A browser takes the last of a repeated descriptor, so a second font-family
      // could register a family outside the list: each may appear once.
      const props = face.nodes.map((node) => decodeCssEscapes(node.prop).toLowerCase());
      expect(props, face.toString().slice(0, 80)).toEqual([...new Set(props)]);
      const families = face.nodes.filter((node) => decodeCssEscapes(node.prop).toLowerCase() === 'font-family');
      expect(families).toHaveLength(1);
      expect(FAMILIES).toContain(decodeCssEscapes(families[0].value));
    }
  });

  it('points every face at a file beside it, and loads nothing else', () => {
    const referenced = new Set();
    for (const face of faces) {
      for (const node of face.nodes) {
        if (decodeCssEscapes(node.prop).toLowerCase() === 'src') {
          const file = SRC.exec(node.value)?.[1];
          expect(file, node.value).toBeTruthy();
          expect(existsSync(resolve(FONTS, file)), file).toBe(true);
          referenced.add(file);
        } else {
          expect(decodeCssEscapes(node.value), `${node.prop}: ${node.value}`).not.toMatch(/url\(|src\(|local\(|image/i);
        }
      }
    }
    expect([...referenced].sort()).toEqual(onDisk.filter((name) => name.endsWith('.woff2')).sort());
  });
});

describe('assets/fonts', () => {
  const listed = { ...sources.fonts, ...sources.licences };

  it('holds exactly the files SOURCES.json lists', () => {
    expect(onDisk.filter((name) => name !== 'SOURCES.json').sort()).toEqual(Object.keys(listed).sort());
  });

  it.each(Object.entries(listed))('%s is the file its source served', (name, { source, sha256, bytes }) => {
    const data = readFileSync(resolve(FONTS, name));
    expect(data.length).toBe(bytes);
    expect(createHash('sha256').update(data).digest('hex')).toBe(sha256);
    if (name.endsWith('.woff2')) {
      expect(source).toMatch(/^https:\/\/fonts\.gstatic\.com\/s\//);
      expect(data.subarray(0, 4).toString('latin1')).toBe('wOF2');
    } else {
      // Each family's licence from that family's own folder: public-sans from ofl/publicsans/.
      const family = name.replace(/-OFL\.txt$/, '').replace(/-/g, '');
      expect(source).toBe(source.replace(/\/ofl\/[a-z]+\/OFL\.txt$/, `/ofl/${family}/OFL.txt`));
      expect(source).toMatch(/^https:\/\/raw\.githubusercontent\.com\/google\/fonts\/[0-9a-f]{40}\/ofl\/[a-z]+\/OFL\.txt$/);
      expect(data.toString('utf8')).toContain('SIL OPEN FONT LICENSE Version 1.1');
    }
  });
});
