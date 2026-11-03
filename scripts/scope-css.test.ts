import { describe, expect, it } from 'vitest';
import { scopeFirehouse, scopeSelector } from './scope-css';

describe('scopeSelector', () => {
  it.each([
    // The page's own styles land on the wrapper.
    [':root', '.fh-app'],
    ['html', '.fh-app'],
    ['body', '.fh-app'],
    ['body::before', '.fh-app::before'],
    ['body > .fh-skip-link', '.fh-app > .fh-skip-link'],
    // A condition on <html> stays there, above the wrapper.
    ["[data-theme='dark']", "[data-theme='dark'] .fh-app"],
    ["[data-theme='dark'] .fh-brand__img--light", "[data-theme='dark'] .fh-app .fh-brand__img--light"],
    [":root:not([data-theme='dark'])", ":root:not([data-theme='dark']) .fh-app"],
    ['html.js .fh-x', 'html.js .fh-app .fh-x'],
    // Everything else is prefixed.
    // A bare `*` reached <body>, so it keeps the wrapper too.
    ['*', '.fh-app, .fh-app *'],
    ['*::after', '.fh-app::after, .fh-app *::after'],
    ['* > p', '.fh-app * > p'],
    [':focus-visible', '.fh-app :focus-visible'],
    ['img', '.fh-app img'],
    ['.fh-btn:hover > svg', '.fh-app .fh-btn:hover > svg'],
    [':where(h1, h2)', '.fh-app :where(h1, h2)'],
    ["[aria-pressed='true']", ".fh-app [aria-pressed='true']"],
    // A space inside brackets or a :not() isn't a combinator.
    ["[title='a b'] .fh-x", ".fh-app [title='a b'] .fh-x"],
    [":root:not([data-x='a b'], .y z) .fh-x", ":root:not([data-x='a b'], .y z) .fh-app .fh-x"],
    // A class that only starts with the wrapper's name isn't the wrapper.
    ['.fh-application', '.fh-app .fh-application'],
    ['  .fh-x  ', '.fh-app .fh-x'],
  ])('%s becomes %s', (selector, scoped) => {
    expect(scopeSelector(selector)).toBe(scoped);
  });

  it.each(['.fh-app', '.fh-app .fh-x', "[data-theme='dark'] .fh-app .fh-x", '.fh-app::before'])(
    'leaves %s, which already names the wrapper, alone',
    (selector) => {
      expect(scopeSelector(selector)).toBe(selector);
      expect(scopeSelector(scopeSelector(selector))).toBe(selector);
    },
  );
});

describe('scopeFirehouse', () => {
  const rule = (selectors: string[], file: string, parent?: { type: string; name?: string }) => ({
    selectors,
    parent,
    source: { input: { file } },
  });

  it('scopes rules, but not keyframe steps or an excluded file', () => {
    const rules = [
      rule(['.fh-x', 'p'], 'C:\\site\\src\\pages\\X.css'),
      rule(['from', '50%'], '/site/src/pages/X.css', { type: 'atrule', name: 'keyframes' }),
      rule(['body'], '/site/src/styles/page-shell.css'),
      rule(['.fh-y'], '/site/src/styles/tokens/colors.css', { type: 'atrule', name: 'media' }),
    ];
    scopeFirehouse({ exclude: [/\/src\/styles\/page-shell\.css$/] }).Once({
      walkRules: (callback) => rules.forEach(callback),
    });
    expect(rules.map((r) => r.selectors)).toEqual([
      ['.fh-app .fh-x', '.fh-app p'],
      ['from', '50%'],
      ['body'],
      ['.fh-app .fh-y'],
    ]);
  });
});
