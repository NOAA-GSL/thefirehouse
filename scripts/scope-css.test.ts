import postcss from 'postcss';
import { describe, expect, it } from 'vitest';
import { isAnchored, scopeFirehouse, scopeProblem, scopeSelector } from './scope-css';

describe('scopeSelector', () => {
  it.each([
    // The page's own styles land on the wrapper.
    [':root', '.fh-app'],
    ['html', '.fh-app'],
    ['body', '.fh-app'],
    ['body::before', '.fh-app::before'],
    ['body > .fh-skip-link', '.fh-app > .fh-skip-link'],
    // A bare `*` reached <body>, so it keeps the wrapper too.
    ['*', '.fh-app, .fh-app *'],
    ['*::after', '.fh-app::after, .fh-app *::after'],
    ['* > p', '.fh-app * > p'],
    // A condition on the page stays above the wrapper.
    ["[data-theme='dark']", "[data-theme='dark'] .fh-app"],
    ["[data-theme='dark'] .fh-brand__img--light", "[data-theme='dark'] .fh-app .fh-brand__img--light"],
    [":root:not([data-theme='dark'])", ":root:not([data-theme='dark']) .fh-app"],
    ['html.js .fh-x', 'html.js .fh-app .fh-x'],
    ['.leaflet-dragging .leaflet-grab', '.leaflet-dragging .fh-app .leaflet-grab'],
    // Everything else is prefixed.
    [':focus-visible', '.fh-app :focus-visible'],
    ['img', '.fh-app img'],
    ['.fh-btn:hover > svg', '.fh-app .fh-btn:hover > svg'],
    [':where(h1, h2)', '.fh-app :where(h1, h2)'],
    ["[aria-pressed='true']", ".fh-app [aria-pressed='true']"],
    ['html-viewer .fh-x', '.fh-app html-viewer .fh-x'],
    // A space inside brackets or a :not() isn't a combinator.
    ["[title='a b'] .fh-x", ".fh-app [title='a b'] .fh-x"],
    [":root:not([data-x='a b'], .y z) .fh-x", ":root:not([data-x='a b'], .y z) .fh-app .fh-x"],
    // Naming the wrapper somewhere isn't the same as starting inside it.
    ['.fh-application', '.fh-app .fh-application'],
    ['.fh-app ~ footer', '.fh-app .fh-app ~ footer'],
    [':not(.fh-app) > .fh-x', '.fh-app :not(.fh-app) > .fh-x'],
    ["[data-x='.fh-app'] .fh-y", ".fh-app [data-x='.fh-app'] .fh-y"],
    ['  .fh-x  ', '.fh-app .fh-x'],
  ])('%s becomes %s', (selector, scoped) => {
    expect(scopeSelector(selector)).toBe(scoped);
  });

  it.each(['.fh-app', '.fh-app .fh-x', '.fh-app > .fh-x', "[data-theme='dark'] .fh-app .fh-x", '.fh-app::before'])(
    'leaves %s, which already starts inside the wrapper, alone',
    (selector) => {
      expect(scopeSelector(selector)).toBe(selector);
      expect(scopeSelector(scopeSelector(selector))).toBe(selector);
    },
  );
});

describe('isAnchored', () => {
  it.each(['.fh-app', '.fh-app *', '.fh-app:hover', ':root:not([x]) .fh-app', '.leaflet-dragging .fh-app .x'])(
    '%s stays inside',
    (selector) => expect(isAnchored(selector)).toBe(true),
  );
  it.each(['.fh-x', '.fh-app ~ footer', '.fh-app + *', "[data-theme='dark'] > .fh-app", '.x .fh-app', ':not(.fh-app)'])(
    '%s can reach outside',
    (selector) => expect(isAnchored(selector)).toBe(false),
  );
});

describe('scopeProblem', () => {
  it.each([
    ['.fh-app ~ footer', 'can match outside'],
    ["[data-theme='dark'] .fh-app body::before", 'names the page'],
    ['.fh-app body .x', 'names the page'],
    ['.fh-app :root', 'names the page'],
  ])('refuses %s', (selector, problem) => expect(scopeProblem(selector)).toContain(problem));
  it.each(['.fh-app tbody', '.fh-app .body-copy', '.fh-app html-viewer', ':root:not([x]) .fh-app .x'])(
    'accepts %s',
    (selector) => expect(scopeProblem(selector)).toBe(''),
  );
});

describe('scopeFirehouse', () => {
  const run = (css: string, from = '/site/src/pages/X.css') =>
    postcss([scopeFirehouse({ exclude: [/\/src\/styles\/page-shell\.css$/] })]).process(css, { from }).css;

  it('scopes rules, but not keyframe steps', () => {
    expect(run('.fh-x, p { color: red }\n@media (width < 9px) { .fh-y { color: blue } }\n@keyframes fh-z { from { opacity: 0 } 50% { opacity: 1 } }')).toBe(
      '.fh-app .fh-x, .fh-app p { color: red }\n@media (width < 9px) { .fh-app .fh-y { color: blue } }\n@keyframes fh-z { from { opacity: 0 } 50% { opacity: 1 } }',
    );
  });

  it.each(['/site/src/styles/page-shell.css', 'C:\\site\\src\\styles\\page-shell.css', '/site/src/styles/page-shell.css?direct'])(
    'leaves the page shell (%s) alone',
    (from) => expect(run('body { margin: 0 }', from)).toBe('body { margin: 0 }'),
  );

  it.each(['body ~ footer { color: red }', "[data-theme='dark'] body::before { color: red }", 'html body .x { color: red }'])(
    'stops the build on %s',
    (css) => expect(() => run(css)).toThrow(/X\.css:1:/),
  );
});
