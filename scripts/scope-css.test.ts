import postcss from 'postcss';
import { describe, expect, it } from 'vitest';
import { isAnchored, outputProblems, scopeFirehouse, scopeProblem, scopeSelector } from './scope-css';

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
    // What a selector that already named the wrapper becomes: it would match nothing.
    ['.fh-app .fh-app ~ footer', 'names the page or .fh-app'],
    ['.fh-app .fh-app.is-x .y', 'names the page or .fh-app'],
    [".fh-app [data-theme='dark'] .x", 'names the page or .fh-app'],
    ['.fh-app .leaflet-dragging .x', 'names the page or .fh-app'],
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

  it.each([
    'body ~ footer { color: red }',
    "[data-theme='dark'] body::before { color: red }",
    'html body .x { color: red }',
    '.fh-app ~ footer { color: red }',
    '.p { .y { color: red } }',
  ])('stops the build on %s', (css) => expect(() => run(css)).toThrow(/X\.css:1:/));
});

describe('outputProblems', () => {
  const problems = (css: string) => outputProblems(postcss.parse(css));

  it('passes scoped rules, contained at-rules, fh- keyframes and inline URLs', () => {
    expect(
      problems(
        [
          ".fh-app .x { background: url(data:image/svg+xml,a) } .fh-app .y { behavior: url(#default#VML) }",
          '@media (width < 9px) { .fh-app .z { color: red } }',
          '@supports (display: grid) { .fh-app { display: grid } }',
          '@container (width > 1px) { .fh-app .c { color: red } }',
          '@keyframes fh-drift { from { opacity: 0 } 50% { opacity: 1 } }',
          ".fh-app .q { background: url( 'data:image/png;base64,AA' ) }",
        ].join('\n'),
      ),
    ).toEqual([]);
  });

  it.each([
    ['@import url(https://example.com/x.css);', '@import'],
    ['@import "https://example.com/x.css";', '@import'],
    ['.fh-app .x { background: url(//example.com/a.png) }', 'url(//example.com'],
    ['.fh-app .x { background: url(a.png) }', 'url(a.png)'],
    ['.fh-app .x { background: image-set("a.png" 1x) }', 'image-set'],
    ['@font-face { font-family: X; src: local(X) }', '@font-face'],
    ['@property --x { syntax: "*"; inherits: false }', '@property'],
    ['@layer base;', '@layer'],
    ['@keyframes pulse { from { opacity: 0 } }', "isn't named fh-"],
    ['.x { color: red }', 'unscoped .x'],
    ['.fh-app ~ footer { color: red }', 'unscoped .fh-app ~ footer'],
  ])('refuses %s', (css, problem) => {
    expect(problems(css).join(' ')).toContain(problem);
  });
});
