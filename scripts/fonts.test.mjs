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

describe('fonts.css', () => {
  const root = postcss.parse(css);
  const faces = root.nodes.filter((node) => node.type !== 'comment');

  it('holds only @font-face rules for the site’s two families', () => {
    expect(faces.length).toBeGreaterThan(0);
    for (const face of faces) {
      expect(face.type === 'atrule' && face.name === 'font-face', `${face.toString().slice(0, 60)}`).toBe(true);
      for (const node of face.nodes) {
        expect(node.type).toBe('decl');
        expect(DESCRIPTORS).toContain(node.prop);
      }
      expect(FAMILIES).toContain(decodeCssEscapes(face.nodes.find((node) => node.prop === 'font-family')?.value ?? ''));
    }
  });

  it('points every face at a file beside it, and loads nothing else', () => {
    const referenced = new Set();
    for (const face of faces) {
      for (const node of face.nodes) {
        if (node.prop === 'src') {
          const file = SRC.exec(node.value)?.[1];
          expect(file, node.value).toBeTruthy();
          expect(existsSync(resolve(FONTS, file)), file).toBe(true);
          referenced.add(file);
        } else {
          expect(decodeCssEscapes(node.value), `${node.prop}: ${node.value}`).not.toMatch(/url\(|src\(|image/i);
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
      expect(source).toMatch(/^https:\/\/raw\.githubusercontent\.com\/google\/fonts\/[0-9a-f]{40}\/ofl\//);
      expect(data.toString('utf8')).toContain('SIL OPEN FONT LICENSE Version 1.1');
    }
  });
});
