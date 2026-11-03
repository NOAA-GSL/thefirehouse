/**
 * Reads a stylesheet as a browser does, and reports what it would do outside `.fh-app`.
 *
 * `outputProblems` in scope-css.ts reads PostCSS's parse, and PostCSS tokenizes some
 * CSS differently from a browser: a backslash before `/*`, a newline inside a string,
 * `url (` with a space. Text one reads as a comment or a string, the other can read as
 * rules. So the check that counts is the browser's own: the stylesheet is served to a
 * page as a file, every other request is refused and recorded, and the browser's rule
 * tree is walked.
 *
 *   - Any request beyond the stylesheet itself (an `@import`, say) is a problem.
 *   - Style rules must have every selector inside `.fh-app` (`isAnchored`), and none
 *     may nest another rule. `@media`, `@supports` and `@container` may hold them, and
 *     `@keyframes` named `fh-…` hold keyframes. Any other rule is a problem.
 *   - No declaration, custom properties included, may load anything (`loadsSomething`).
 *     The browser hands values back with its own escapes decoded.
 *
 * It drives a browser already installed on the machine, as `npm run reference` does:
 * Edge on Windows, Chrome elsewhere (GitHub's Ubuntu runners have it), or whatever
 * REFERENCE_BROWSER names.
 */

import { chromium } from 'playwright-core';
import { isAnchored, loadsSomething, splitSelectorList } from './scope-css.ts';

const ORIGIN = 'https://firehouse-css-check.test';
const CONTAINERS = ['CSSMediaRule', 'CSSSupportsRule', 'CSSContainerRule'];

export const browserChannel = () => process.env.REFERENCE_BROWSER ?? (process.platform === 'win32' ? 'msedge' : 'chrome');

/** Opens a browser for `browserProblems`; close it when done. */
export const openBrowser = () => chromium.launch({ channel: browserChannel() });

/** What `css` would do outside the wrapper, read by the browser `browser`. */
export async function browserProblems(browser, css) {
  const page = await browser.newPage();
  const requests = [];
  try {
    await page.route('**/*', (route) => {
      const url = route.request().url();
      if (url === `${ORIGIN}/`) {
        return route.fulfill({ contentType: 'text/html', body: '<!doctype html><link rel="stylesheet" href="/firehouse.css">' });
      }
      if (url === `${ORIGIN}/firehouse.css`) return route.fulfill({ contentType: 'text/css', body: css });
      requests.push(url);
      return route.abort();
    });
    await page.goto(`${ORIGIN}/`, { waitUntil: 'load' });

    const rules = await page.evaluate((containers) => {
      const out = [];
      const declarations = (style) => [...style].map((property) => [property, style.getPropertyValue(property)]);
      const walk = (list, where) => {
        for (const rule of list) {
          const kind = rule.constructor.name;
          if (kind === 'CSSStyleRule') {
            out.push({ kind, where, selector: rule.selectorText, declarations: declarations(rule.style), nested: rule.cssRules?.length ?? 0 });
          } else if (kind === 'CSSKeyframesRule') {
            out.push({ kind, where, name: rule.name });
            for (const frame of rule.cssRules) out.push({ kind: frame.constructor.name, where: 'keyframes', declarations: declarations(frame.style) });
          } else if (containers.includes(kind)) {
            out.push({ kind, where });
            walk(rule.cssRules, kind);
          } else {
            out.push({ kind, where, text: rule.cssText.slice(0, 120) });
          }
        }
      };
      walk(document.styleSheets[0]?.cssRules ?? [], 'top');
      return out;
    }, CONTAINERS);

    const problems = requests.map((url) => `requests ${url}`);
    for (const rule of rules) {
      if (rule.kind === 'CSSStyleRule') {
        for (const selector of splitSelectorList(rule.selector)) if (!isAnchored(selector)) problems.push(`unscoped ${selector}`);
        if (rule.nested) problems.push(`nested rules in ${rule.selector}`);
      } else if (rule.kind === 'CSSKeyframesRule') {
        if (!/^fh-/.test(rule.name)) problems.push(`@keyframes ${rule.name} isn't named fh-…`);
      } else if (!(CONTAINERS.includes(rule.kind) || (rule.kind === 'CSSKeyframeRule' && rule.where === 'keyframes'))) {
        problems.push(`${rule.kind}${rule.text ? ` ${rule.text}` : ''}`);
      }
      for (const [property, value] of rule.declarations ?? []) {
        if (loadsSomething(value)) problems.push(`${property}: ${value}`);
      }
    }
    return problems;
  } finally {
    await page.close();
  }
}
