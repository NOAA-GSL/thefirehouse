import { describe, expect, it } from 'vitest';
import { diff, isBuilt, mainOf, normalize, siteAddress } from './compare-plugin.mjs';

const ORIGIN = 'http://localhost:8090';

describe('compare-plugin', () => {
  it('writes the plugin’s addresses as the site’s', () => {
    expect(siteAddress(`${ORIGIN}/firehouse/`, ORIGIN)).toBe('/');
    expect(siteAddress(`${ORIGIN}/firehouse`, ORIGIN)).toBe('/');
    expect(siteAddress(`${ORIGIN}/firehouse/projects/`, ORIGIN)).toBe('/projects');
    expect(siteAddress(`${ORIGIN}/firehouse/topics/observe/`, ORIGIN)).toBe('/topics/observe');
    expect(siteAddress('/firehouse/no-such-page', ORIGIN)).toBe('/no-such-page');
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

  it('reports what differs, and nothing when nothing does', () => {
    expect(diff(['a', 'b', 'c'], ['a', 'b', 'c'])).toEqual([]);
    expect(diff(['a', 'b', 'c'], ['a', 'x', 'c'])).toEqual(['+ x', '- b']);
    expect(diff(['a'], ['a', 'b'])).toEqual(['+ b']);
    expect(diff(['a', 'b'], ['b'])).toEqual(['- a']);
  });
});
