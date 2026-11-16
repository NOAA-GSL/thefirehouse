import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { body, build, helpJson, HELP, inline, readme, SCREENS } from './plugin-help.mjs';

const pages = new Map([['02-projects.md', 'projects']]);
const lines = (text) => text.split('\n');

describe('inline Markdown', () => {
  it('escapes text and renders bold, emphasis, code and links', () => {
    expect(inline('A & B "quoted" **bold** *em* `<code>`', 'x', pages)).toBe(
      'A &amp; B &quot;quoted&quot; <strong>bold</strong> <em>em</em> <code>&lt;code&gt;</code>',
    );
    expect(inline('[NOAA](https://www.noaa.gov/) and [projects](02-projects.md)', 'x', pages)).toBe(
      '<a href="https://www.noaa.gov/">NOAA</a> and <a href="{{guide}}#fh-help-projects">projects</a>',
    );
    expect(inline("Don't stop! snake_case is fine.", 'x', pages)).toBe("Don't stop! snake_case is fine.");
  });

  it('refuses HTML, other links and stray markup', () => {
    expect(() => inline('<b>bold</b>', 'x', pages)).toThrow(/isn't Markdown/);
    expect(() => inline('[x](http://example.org)', 'x', pages)).toThrow(/neither https/);
    expect(() => inline('[x](javascript:alert(1))', 'x', pages)).toThrow(/neither https/);
    expect(() => inline('[x](03-missing.md)', 'x', pages)).toThrow(/neither https/);
    expect(() => inline('5 * 3', 'x', pages)).toThrow(/isn't Markdown/);
  });
});

describe('a page body', () => {
  const images = () => new Map([['shot.webp', { width: 800, height: 400, used: false }]]);

  it('turns headings down a level, and makes lists, notes and paragraphs', () => {
    const { html, tab } = body(
      lines(
        [
          '## Steps',
          '',
          'A paragraph',
          'over two lines.',
          '',
          '1. First',
          '   continued.',
          '2. Second',
          '   - inner',
          '   - inner two',
          '',
          '> A note.',
          '',
          '### Smaller',
        ].join('\n'),
      ),
      'page.md',
      pages,
      images(),
    );
    expect(html).toBe(
      [
        '<h3>Steps</h3>',
        '<p>A paragraph over two lines.</p>',
        '<ol><li>First continued.</li><li>Second<ul><li>inner</li><li>inner two</li></ul></li></ol>',
        '<div class="fh-help__note"><p>A note.</p></div>',
        '<h4>Smaller</h4>',
      ].join('\n'),
    );
    expect(tab).toBe(html);
  });

  it('puts images in figures on the Help page only, with their size', () => {
    const found = images();
    const { html, tab } = body(lines('Text.\n\n![What it shows](images/shot.webp "A caption")'), 'page.md', pages, found);
    expect(html).toContain(
      '<figure class="fh-help__figure"><img src="{{images}}/shot.webp" alt="What it shows" width="800" height="400" loading="lazy"><figcaption>A caption</figcaption></figure>',
    );
    expect(tab).toBe('<p>Text.</p>');
    expect(found.get('shot.webp').used).toBe(true);
  });

  it('refuses what it doesn\'t handle', () => {
    expect(() => body(lines('# Second title'), 'p.md', pages, images())).toThrow(/only ## and ###/);
    expect(() => body(lines('![x](images/missing.webp)'), 'p.md', pages, images())).toThrow(/isn't in help\/images/);
    expect(() => body(lines('![x](https://example.org/a.png)'), 'p.md', pages, images())).toThrow(/an image is/);
    expect(() => body(lines('    code block'), 'p.md', pages, images())).toThrow(/indented line/);
    expect(() => body(lines('- item\n    too far'), 'p.md', pages, images())).toThrow(/indented 4 spaces/);
  });
});

describe('the guide', () => {
  const guide = build();

  it('is what help.json and README.md hold', () => {
    expect(readFileSync(resolve(HELP, 'help.json'), 'utf8').replace(/\r\n/g, '\n')).toBe(helpJson(guide));
    expect(readFileSync(resolve(HELP, 'README.md'), 'utf8').replace(/\r\n/g, '\n')).toBe(readme(guide));
  });

  it('has a page for every FireHouse screen, so each has a Help tab', () => {
    const named = new Set(guide.flatMap((page) => page.screens));
    expect([...named].sort()).toEqual([...SCREENS].sort());
  });

  it('names the same screens as the plugin', () => {
    const php = readFileSync(resolve(HELP, '../includes/class-help.php'), 'utf8');
    const list = php.match(/public const SCREENS = array\(([^)]*)\);/)?.[1];
    expect(list, 'Help::SCREENS in class-help.php').toBeTruthy();
    expect([...list.matchAll(/'([a-z-]+)'/g)].map((m) => m[1])).toEqual(SCREENS);
  });

  it('starts with the editors\' pages, and every page has a summary', () => {
    const audiences = guide.map((page) => page.audience);
    expect(audiences.indexOf('administrators')).toBeGreaterThan(audiences.lastIndexOf('editors'));
    for (const page of guide) expect(page.summary.length, page.file).toBeGreaterThan(20);
  });
});
