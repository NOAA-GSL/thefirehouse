import { describe, expect, it } from 'vitest';
import { BUILT, LATER, diff, isBuilt, mainOf, normalize, siteAddress, siteLink, withoutRegions } from './compare-plugin.mjs';

const ORIGIN = 'http://localhost:8090';

describe('compare-plugin', () => {
  it('writes the plugin’s addresses as the site’s', () => {
    expect(siteAddress(`${ORIGIN}/firehouse/`, ORIGIN)).toBe('/');
    expect(siteAddress(`${ORIGIN}/firehouse`, ORIGIN)).toBe('/');
    expect(siteAddress(`${ORIGIN}/firehouse/projects/`, ORIGIN)).toBe('/projects');
    expect(siteAddress(`${ORIGIN}/firehouse/topics/observe/`, ORIGIN)).toBe('/topics/observe');
    expect(siteAddress('/firehouse/no-such-page', ORIGIN)).toBe('/no-such-page');
    // The slash comes off before a query or fragment, as the site writes those links.
    expect(siteAddress(`${ORIGIN}/firehouse/projects/?topic=observe`, ORIGIN)).toBe('/projects?topic=observe');
    expect(siteAddress('/firehouse/#top-needs', ORIGIN)).toBe('/#top-needs');
    expect(siteAddress('/firehouse/?q=a/', ORIGIN)).toBe('/?q=a/');
    // Anything that isn't a Firehouse address is left exactly as it is, so a link to one
    // of the theme's own pages can't pass for the site's.
    expect(siteAddress(`${ORIGIN}/projects/`, ORIGIN)).toBe(`${ORIGIN}/projects/`);
    expect(siteAddress('/projects/', ORIGIN)).toBe('/projects/');
    expect(siteAddress(`${ORIGIN}/firehouses/x/`, ORIGIN)).toBe(`${ORIGIN}/firehouses/x/`);
    expect(siteAddress('https://docs.google.com/forms/x/', ORIGIN)).toBe('https://docs.google.com/forms/x/');
    expect(siteAddress('#main', ORIGIN)).toBe('#main');
  });

  it('knows which reference pages the plugin has built, by name or kind', () => {
    const built = ['not-found', 'topic--'];
    expect(isBuilt('not-found', built)).toBe(true);
    expect(isBuilt('topic--observe', built)).toBe(true);
    expect(isBuilt('topic', built)).toBe(false);
    expect(isBuilt('project--x', built)).toBe(false);
    expect(isBuilt('home', built)).toBe(false);
  });

  it('leaves out a region that comes later, and everything inside it, by its name', () => {
    const lines = [
      '- main:',
      '  - region "Top needs":',
      '    - heading "Top needs" [level=2]',
      '  - region "Where the research comes from":',
      '    - heading "Where the research comes from" [level=2]',
      '    - button "GACC labels" [pressed]',
      '  - region "Have a finding?":',
      '    - heading "Have a finding?" [level=2]',
    ];
    expect(withoutRegions(lines, ['Where the research comes from'])).toEqual([
      '- main:',
      '  - region "Top needs":',
      '    - heading "Top needs" [level=2]',
      '  - region "Have a finding?":',
      '    - heading "Have a finding?" [level=2]',
    ]);
    expect(withoutRegions(lines)).toEqual(lines);
  });

  it('leaves out only parts of pages it has built', () => {
    expect(isBuilt('home')).toBe(true);
    for (const name of Object.keys(LATER)) expect(isBuilt(name, BUILT)).toBe(true);
  });

  it('takes the main landmark, and nothing after it', () => {
    const snapshot = ['- banner:', '  - link "Home"', '- main:', '  - heading "A" [level=1]', '  - list:', '    - listitem: x', '- contentinfo:', '  - text: y'].join('\n');
    expect(mainOf(snapshot)).toEqual(['- main:', '  - heading "A" [level=1]', '  - list:', '    - listitem: x']);
    expect(mainOf('  - main:\n    - text: a\n  - contentinfo')).toEqual(['- main:', '  - text: a']);
    expect(mainOf('- banner:')).toEqual([]);
  });

  it('rewrites only link and quoted-address lines', () => {
    expect(
      normalize(['- link "Home":', `  - /url: ${ORIGIN}/firehouse/`, '- code: /firehouse/nope', '- text: /firehouse/ stays', `  - /url: "${ORIGIN}/firehouse/about/"`], ORIGIN),
    ).toEqual(['- link "Home":', '  - /url: /', '- code: /nope', '- text: /firehouse/ stays', '  - /url: "/about"']);
  });

  it('never lets a link outside the Firehouse pass for one of the site’s', () => {
    // On the site these are home and the explorer; on WordPress, the theme's own pages.
    expect(siteLink('/', ORIGIN)).toBe(`${ORIGIN}/`);
    expect(siteLink('/projects', ORIGIN)).toBe(`${ORIGIN}/projects`);
    expect(siteLink('/projects/', ORIGIN)).toBe(`${ORIGIN}/projects/`);
    expect(siteLink(`${ORIGIN}/projects`, ORIGIN)).toBe(`${ORIGIN}/projects`);
    expect(normalize(['  - /url: /', '  - /url: /projects'], ORIGIN)).toEqual([`  - /url: ${ORIGIN}/`, `  - /url: ${ORIGIN}/projects`]);
    // Firehouse links, in-page anchors and other sites are as before.
    expect(siteLink(`${ORIGIN}/firehouse/projects/`, ORIGIN)).toBe('/projects');
    expect(siteLink('/firehouse/', ORIGIN)).toBe('/');
    expect(siteLink('#main', ORIGIN)).toBe('#main');
    expect(siteLink('//cdn.example.org/x', ORIGIN)).toBe('//cdn.example.org/x');
    expect(siteLink('https://docs.google.com/forms/x', ORIGIN)).toBe('https://docs.google.com/forms/x');
    // The 404's quoted address is text, so it only loses the prefix.
    expect(normalize(['- code: /projects'], ORIGIN)).toEqual(['- code: /projects']);
  });

  it('reports what differs, and nothing when nothing does', () => {
    expect(diff(['a', 'b', 'c'], ['a', 'b', 'c'])).toEqual([]);
    expect(diff(['a', 'b', 'c'], ['a', 'x', 'c'])).toEqual(['+ x', '- b']);
    expect(diff(['a'], ['a', 'b'])).toEqual(['+ b']);
    expect(diff(['a', 'b'], ['b'])).toEqual(['- a']);
  });
});
