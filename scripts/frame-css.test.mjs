import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import postcss from 'postcss';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { browserProblems, openBrowser } from './css-browser-check.mjs';
import { outputProblems } from './scope-css.ts';

/**
 * frame.css is written by hand and loaded on every Firehouse page after firehouse.css,
 * so it gets the same checks, read as PostCSS reads it and as a browser does: every
 * rule inside `.fh-app`, no at-rule that reaches the whole page, nothing that loads.
 * The one rule allowed outside the wrapper is on Firehouse pages only (the body class
 * includes/class-frame.php adds), and hides the theme's hero background and the mission
 * section above its footer.
 */
const css = readFileSync(fileURLToPath(new URL('../wp-content/plugins/firehouse/assets/css/frame.css', import.meta.url)), 'utf8');
const ALLOWED_OUTSIDE = ['.fh-page .gsl-hero-section', '.fh-page .footer-mission'];
const beyondAllowed = (problems) =>
  problems.filter((problem) => !ALLOWED_OUTSIDE.some((selector) => problem === `unscoped ${selector}`));

// The browser reads the stylesheet as a page would, so this takes a few seconds.
let browser;
beforeAll(async () => {
  browser = await openBrowser();
}, 60_000);
afterAll(() => browser?.close());

describe('frame.css', () => {
  it('stays inside the wrapper but for the theme’s hero on Firehouse pages', () => {
    expect(beyondAllowed(outputProblems(postcss.parse(css)))).toEqual([]);
  });

  it('does the same as a browser reads it', async () => {
    expect(beyondAllowed(await browserProblems(browser, css))).toEqual([]);
  }, 30_000);

  it('only hides what it reaches outside the wrapper', () => {
    let found = 0;
    postcss.parse(css).walkRules((rule) => {
      if (!rule.selectors.some((selector) => ALLOWED_OUTSIDE.includes(selector))) return;
      found += 1;
      expect(rule.selectors).toEqual(ALLOWED_OUTSIDE.filter((selector) => rule.selectors.includes(selector)));
      expect(rule.nodes.map((node) => `${node.prop}: ${node.value}`)).toEqual(['display: none']);
    });
    expect(found).toBe(1);
  });
});
