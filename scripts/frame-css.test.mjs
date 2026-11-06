import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import postcss from 'postcss';
import { describe, expect, it } from 'vitest';
import { outputProblems } from './scope-css.ts';

/**
 * frame.css is written by hand and loaded on every Firehouse page after firehouse.css,
 * so it gets the same checks: every rule inside `.fh-app`, no at-rule that reaches the
 * whole page, nothing that loads. The one rule allowed outside the wrapper is on
 * Firehouse pages only (the body class includes/class-frame.php adds), and hides the
 * theme's hero background.
 */
const css = readFileSync(fileURLToPath(new URL('../wp-content/plugins/firehouse/assets/css/frame.css', import.meta.url)), 'utf8');
const ALLOWED_OUTSIDE = ['.fh-page .gsl-hero-section'];

describe('frame.css', () => {
  it('stays inside the wrapper but for the theme’s hero on Firehouse pages', () => {
    const problems = outputProblems(postcss.parse(css)).filter(
      (problem) => !ALLOWED_OUTSIDE.some((selector) => problem === `unscoped ${selector}`),
    );
    expect(problems).toEqual([]);
  });

  it('only hides what it reaches outside the wrapper', () => {
    postcss.parse(css).walkRules((rule) => {
      if (!rule.selectors.some((selector) => ALLOWED_OUTSIDE.includes(selector))) return;
      expect(rule.selectors).toEqual(ALLOWED_OUTSIDE.filter((selector) => rule.selectors.includes(selector)));
      expect(rule.nodes.map((node) => `${node.prop}: ${node.value}`)).toEqual(['display: none']);
    });
  });
});
