/**
 * The PHP search's accent table (`ACCENTED` and `UNACCENTED` in
 * wp-content/plugins/firehouse/domain/class-search.php), which folds Latin letters on a
 * server without PHP's intl extension, checked against what it stands in for:
 * JavaScript's own fold in src/content/search.ts, NFD with the marks U+0300 to U+036F
 * dropped. Every letter in its ranges that the fold changes is in the table, and each
 * maps to what the fold leaves.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const PHP = readFileSync(fileURLToPath(new URL('../wp-content/plugins/firehouse/domain/class-search.php', import.meta.url)), 'utf8');
const constant = (name) => {
  const match = PHP.match(new RegExp(`const ${name} = '([^'\\\\]*)';`));
  if (!match) throw new Error(`${name} isn't in class-search.php`);
  return [...match[1]];
};
const ACCENTED = constant('ACCENTED');
const UNACCENTED = constant('UNACCENTED');
const strip = (letter) => letter.normalize('NFD').replace(/[\u0300-\u036f]/g, '');

describe('the PHP search’s accent table', () => {
  it('pairs each accented letter with one letter', () => {
    expect(ACCENTED).toHaveLength(UNACCENTED.length);
    expect(new Set(ACCENTED).size).toBe(ACCENTED.length);
  });

  it('maps each letter to what JavaScript’s fold leaves of it', () => {
    ACCENTED.forEach((letter, index) => expect(UNACCENTED[index], letter).toBe(strip(letter)));
  });

  it('leaves out no letter in its ranges that the fold changes', () => {
    const changed = [];
    for (const [first, last] of [[0xc0, 0x24f], [0x1e00, 0x1eff]]) {
      for (let code = first; code <= last; code++) {
        const letter = String.fromCodePoint(code);
        if (strip(letter) !== letter) changed.push(letter);
      }
    }
    expect(ACCENTED).toEqual(changed);
  });
});
