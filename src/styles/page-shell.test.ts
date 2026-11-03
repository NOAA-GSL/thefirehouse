import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * The page shell paints the canvas in the atmosphere's base colour, which it can't
 * read from the wrapper's tokens, so it repeats the value. This keeps the two equal.
 */
const read = (path: string) => readFileSync(fileURLToPath(new URL(path, import.meta.url)), 'utf8');

/** The declarations of the first block whose selector is exactly `selector`. */
function block(css: string, selector: string): string {
  for (const [, head, body] of css.replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
    if (head.trim() === selector) return body;
  }
  throw new Error(`No ${selector} block.`);
}

/** A custom property's value in a block, following `var()` into the palette. */
function token(body: string, name: string, palette: string): string {
  const value = new RegExp(`${name}:\\s*([^;]+);`).exec(body)?.[1].trim();
  if (!value) throw new Error(`No ${name}.`);
  const alias = /^var\((--[\w-]+)\)$/.exec(value)?.[1];
  return alias ? token(palette, alias, palette) : value.toLowerCase();
}

describe('page-shell.css', () => {
  const palette = block(read('./tokens/colors.css'), ':root');
  const glass = read('./tokens/glass.css');
  const shell = read('./page-shell.css');

  it.each([
    ['light', ':root', 'html'],
    ['dark', "[data-theme='dark']", "html[data-theme='dark']"],
  ])('paints the %s canvas in --atmos-base', (_, tokens, canvas) => {
    const base = token(block(glass, tokens), '--atmos-base', palette);
    expect(/background:\s*([^;]+);/.exec(block(shell, canvas))?.[1].trim().toLowerCase()).toBe(base);
  });
});
