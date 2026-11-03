import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { browserProblems, openBrowser } from './css-browser-check.mjs';

// Each case is read by a real browser, so these take a few seconds.
let browser;
beforeAll(async () => {
  browser = await openBrowser();
}, 60_000);
afterAll(() => browser?.close());

const check = (css) => browserProblems(browser, css);

describe('browserProblems', () => {
  it('passes scoped rules, contained at-rules, fh- keyframes and inline URLs', async () => {
    const css = [
      '.fh-app, .fh-app * { box-sizing: border-box }',
      "[data-theme='dark'] .fh-app .x { color: red }",
      '.fh-app .x { background: url(data:image/svg+xml,a) }',
      '@media (width < 9px) { .fh-app .z { color: red } }',
      '@supports (display: grid) { .fh-app { display: grid } }',
      '@keyframes fh-drift { from { opacity: 0 } to { opacity: 1 } }',
    ].join('\n');
    expect(await check(css)).toEqual([]);
  }, 30_000);

  // CSS that PostCSS reads as a comment, a string or a bracket, where a browser reads
  // rules: each hides an unscoped `.y` that would fetch from off-site.
  it.each([
    ['a backslash before /*', '.fh-app .x { color: a\\/* ; background: url(https://example.com/p1.png) } .y { background: url(https://example.com/p2.png) } .fh-app .q { --y: */ b }'],
    ['a newline inside a string', '.fh-app [title="a\n] {} .y { background: url(https://example.com/a.png) } .fh-app [title="] { color: red }'],
    ['url with a space before its bracket', '.fh-app .x { --a: url (a")/*") ; } .y { background: url(https://example.com/a.png) } .fh-app .q { --b: */ }'],
    ['a fragment url hiding a comment', '.fh-app .x { --a: url( #/*); } .y { background: url(https://example.com/a.png) } .fh-app .q { --b: */ ) }'],
  ])('sees through %s', async (_, css) => {
    const problems = (await check(css)).join('\n');
    expect(problems).toMatch(/unscoped \.y/);
    expect(problems).toMatch(/example\.com/);
  }, 30_000);

  it.each([
    ['@import url(https://example.com/x.css);', /requests https:\/\/example\.com\/x\.css|CSSImportRule/],
    ['.fh-app .x { background: u\\72l("https://example.com/a.png") }', /background-image: url\("https:\/\/example\.com/],
    ['.fh-app .x { --x: u\\72l(https://example.com/a.png) }', /--x:/],
    ['@font-face { font-family: X; src: url(https://example.com/x.woff2) }', /CSSFontFaceRule/],
    ['@layer base { .fh-app .x { color: red } }', /CSSLayerBlockRule/],
    ['@keyframes pulse { from { opacity: 0 } }', /isn't named fh-/],
    ['.fh-app ~ footer { color: red }', /unscoped \.fh-app ~ footer/],
    ['.fh-app .p { .y { color: red } }', /nested rules/],
  ])('refuses %s', async (css, problem) => {
    expect((await check(css)).join('\n')).toMatch(problem);
  }, 30_000);
});
