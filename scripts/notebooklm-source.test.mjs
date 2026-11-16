import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { buildNotebookSource } from './notebooklm-source.mjs';

/**
 * The shared cases, which the plugin's Download NotebookLM source (Notebook_Source in
 * PHP) runs too, so the two documents stay the same byte for byte.
 */

const { cases } = JSON.parse(
  readFileSync(fileURLToPath(new URL('../wp-content/plugins/firehouse/shared/cases/notebooklm-source.json', import.meta.url)), 'utf8'),
);

describe('shared/cases/notebooklm-source.json', () => {
  it.each(cases.map((c) => [c.name, c]))('%s', (_name, c) => {
    expect(buildNotebookSource(c.projects, c.topics)).toBe(c.expected);
  });
});
