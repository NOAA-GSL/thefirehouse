#!/usr/bin/env node
/**
 * Builds the FireHouse guide that the plugin shows in the dashboard, from the Markdown
 * pages in wp-content/plugins/firehouse/help/.
 *
 *   node scripts/plugin-help.mjs           write help/help.json and help/README.md
 *   node scripts/plugin-help.mjs --check   fail if either is out of date
 *
 * Any other argument stops it, so a mistyped flag never rewrites the files.
 *
 * The Markdown pages are the guide's source: people edit them, and GitHub shows them.
 * help.json is what the plugin reads (includes/class-help.php): each page's title,
 * audience, the screens whose Help tab shows it, its first paragraph as a summary, and
 * its HTML twice, for FireHouse → Help with its screenshots and for a screen's Help tab
 * without them. README.md is the guide's contents page on GitHub. CI runs --check, so
 * the dashboard's guide can't fall behind its Markdown, and the tests below hold the
 * pages to the screens the plugin has.
 *
 * A page is named NN-slug.md (the number orders the guide) and starts:
 *
 *   # The page's title
 *
 *   <!-- help audience: editors; screens: project-list, project-edit -->
 *
 * audience is `editors` (FireHouse Editors and administrators see it) or
 * `administrators` (only administrators). screens are the keys in SCREENS below; a
 * page may name none. The comment is hidden on GitHub.
 *
 * The Markdown is a small set, and anything outside it stops the build with the line,
 * rather than turn into something unexpected on the screen:
 *
 * - `## Heading` and `### Heading` (the page's title is the only `#`);
 * - paragraphs, whose lines join;
 * - lists, `- item` or `1. item`, a list inside an item indented to the item's text;
 * - `> ` notes, shown boxed;
 * - an image alone in a paragraph, `![What it shows](images/name.webp "Caption")`,
 *   whose file must be in help/images/ (and every file there must be used);
 * - inline `**bold**`, `*emphasis*`, `` `code` ``, and links: `[text](https://...)`,
 *   `[text](NN-slug.md)` for another page of the guide.
 *
 * Text is escaped; no HTML passes through.
 */

import { readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, resolve } from 'node:path';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
export const HELP = resolve(ROOT, 'wp-content/plugins/firehouse/help');

/**
 * The screens a page can name, as includes/class-help.php recognises them.
 * tests/HelpTest.php holds the two lists together.
 */
export const SCREENS = [
  'project-list',
  'project-edit',
  'topic-list',
  'topic-edit',
  'synthesis',
  'settings',
  'import',
];

export const AUDIENCES = ['editors', 'administrators'];

/** Placeholders class-help.php replaces with the images' and the Help page's addresses. */
export const IMAGES = '{{images}}';
export const GUIDE = '{{guide}}';

const escape = (text) =>
  text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

class HelpError extends Error {}

/** Width and height of a WebP or PNG, from its header. */
export function imageSize(buffer, name) {
  if (buffer.toString('ascii', 0, 4) === 'RIFF' && buffer.toString('ascii', 8, 12) === 'WEBP') {
    const chunk = buffer.toString('ascii', 12, 16);
    if (chunk === 'VP8X') {
      return { width: 1 + buffer.readUIntLE(24, 3), height: 1 + buffer.readUIntLE(27, 3) };
    }
    if (chunk === 'VP8 ') {
      return { width: buffer.readUInt16LE(26) & 0x3fff, height: buffer.readUInt16LE(28) & 0x3fff };
    }
    if (chunk === 'VP8L') {
      const bits = buffer.readUInt32LE(21);
      return { width: 1 + (bits & 0x3fff), height: 1 + ((bits >> 14) & 0x3fff) };
    }
  }
  if (buffer.readUInt32BE(0) === 0x89504e47) {
    return { width: buffer.readUInt32BE(16), height: buffer.readUInt32BE(20) };
  }
  throw new HelpError(`${name} isn't a WebP or PNG image`);
}

/**
 * Inline Markdown to HTML. `pages` maps NN-slug.md to its slug, for links between pages.
 */
export function inline(text, where, pages) {
  let html = '';
  let rest = text;
  const patterns = [
    [/^`([^`]+)`/, (m) => `<code>${escape(m[1])}</code>`],
    [/^\*\*([^*]+)\*\*/, (m) => `<strong>${inline(m[1], where, pages)}</strong>`],
    [/^\*([^*\s][^*]*)\*/, (m) => `<em>${inline(m[1], where, pages)}</em>`],
    [
      /^\[([^\]]+)\]\(([^)\s]+)\)/,
      (m) => {
        const [, label, href] = m;
        if (/^https:\/\/[^\s"<>]+$/.test(href)) {
          return `<a href="${escape(href)}">${inline(label, where, pages)}</a>`;
        }
        if (pages.has(href)) {
          return `<a href="${GUIDE}#fh-help-${pages.get(href)}">${inline(label, where, pages)}</a>`;
        }
        throw new HelpError(`${where}: a link goes to ${href}, which is neither https:// nor a page of the guide`);
      },
    ],
  ];
  outer: while (rest) {
    for (const [pattern, render] of patterns) {
      const match = rest.match(pattern);
      if (match) {
        html += render(match);
        rest = rest.slice(match[0].length);
        continue outer;
      }
    }
    if (/^(!\[|<|\[|\*|`|_{2})/.test(rest)) {
      throw new HelpError(`${where}: "${rest.slice(0, 30)}" isn't Markdown the guide handles`);
    }
    const plain = rest.match(/^[^`*[!<_]+|^./s)[0];
    html += escape(plain);
    rest = rest.slice(plain.length);
  }
  return html;
}

/**
 * A page's body, as HTML for the Help page and for a Help tab. Headings go down a
 * level: the page's title is the Help page's h2, so `##` is an h3.
 */
export function body(lines, file, pages, images) {
  const full = [];
  const tab = [];
  const both = (html) => {
    full.push(html);
    tab.push(html);
  };
  let i = 0;
  const at = () => `${file}:${i + 1}`;

  const paragraph = () => {
    const parts = [];
    while (i < lines.length && lines[i].trim() && !/^(#|>|- |\d+\. |!\[)/.test(lines[i])) {
      if (/^\s/.test(lines[i])) throw new HelpError(`${at()}: an indented line outside a list`);
      parts.push(lines[i].trim());
      i++;
    }
    return parts.join(' ');
  };

  const list = (indent) => {
    const ordered = /^\d+\. /.test(lines[i].slice(indent));
    const tag = ordered ? 'ol' : 'ul';
    const marker = ordered ? /^(\d+)\. / : /^- /;
    const items = [];
    while (i < lines.length) {
      const line = lines[i];
      if (!line.trim()) {
        // A blank line inside a list ends it unless the list goes on after it.
        const next = lines[i + 1] ?? '';
        if (next.startsWith(' '.repeat(indent)) && marker.test(next.slice(indent))) {
          i++;
          continue;
        }
        break;
      }
      const own = line.slice(indent);
      if (!line.startsWith(' '.repeat(indent)) || !marker.test(own)) break;
      const width = own.match(marker)[0].length;
      const text = [own.slice(width).trim()];
      const nested = [];
      i++;
      while (i < lines.length && lines[i].trim()) {
        const inner = lines[i];
        const depth = inner.length - inner.trimStart().length;
        if (depth === indent + width && /^(- |\d+\. )/.test(inner.trimStart())) {
          nested.push(list(indent + width));
          continue;
        }
        if (depth === indent + width && !nested.length) {
          text.push(inner.trim());
          i++;
          continue;
        }
        if (depth <= indent) break;
        throw new HelpError(`${at()}: a list line indented ${depth} spaces; this item's text starts at ${indent + width}`);
      }
      items.push(`<li>${inline(text.join(' '), at(), pages)}${nested.join('')}</li>`);
    }
    return `<${tag}>${items.join('')}</${tag}>`;
  };

  while (i < lines.length) {
    const line = lines[i];
    if (!line.trim()) {
      i++;
      continue;
    }
    let match;
    if ((match = line.match(/^(##|###) (.+)$/))) {
      const level = match[1].length + 1;
      both(`<h${level}>${inline(match[2].trim(), at(), pages)}</h${level}>`);
      i++;
    } else if (line.startsWith('#')) {
      throw new HelpError(`${at()}: only ## and ### headings go in a page; its title is the one #`);
    } else if ((match = line.match(/^!\[([^\]]+)\]\((images\/[a-z0-9-]+\.(?:webp|png))(?: "([^"]+)")?\)$/))) {
      const [, alt, src, caption] = match;
      const name = src.slice('images/'.length);
      if (!images.has(name)) throw new HelpError(`${at()}: ${src} isn't in help/images/`);
      const { width, height } = images.get(name);
      images.get(name).used = true;
      const img = `<img src="${IMAGES}/${name}" alt="${escape(alt)}" width="${width}" height="${height}" loading="lazy">`;
      full.push(
        `<figure class="fh-help__figure">${img}${caption ? `<figcaption>${inline(caption, at(), pages)}</figcaption>` : ''}</figure>`,
      );
      i++;
    } else if (line.startsWith('![')) {
      throw new HelpError(`${at()}: an image is ![what it shows](images/name.webp "Caption"), alone on its line`);
    } else if (line.startsWith('> ')) {
      const parts = [];
      while (i < lines.length && lines[i].startsWith('>')) {
        parts.push(lines[i].replace(/^>\s?/, ''));
        i++;
      }
      const paras = parts
        .join('\n')
        .split(/\n\s*\n/)
        .map((p) => `<p>${inline(p.replace(/\n/g, ' ').trim(), at(), pages)}</p>`);
      both(`<div class="fh-help__note">${paras.join('')}</div>`);
    } else if (/^(- |\d+\. )/.test(line)) {
      both(list(0));
    } else if (/^\s/.test(line)) {
      throw new HelpError(`${at()}: an indented line outside a list`);
    } else {
      both(`<p>${inline(paragraph(), at(), pages)}</p>`);
    }
  }
  return { html: full.join('\n'), tab: tab.join('\n') };
}

/** Reads every page and image, and returns the guide, or throws on the first problem. */
export function build(dir = HELP) {
  const files = readdirSync(dir)
    .filter((name) => name.endsWith('.md') && name !== 'README.md')
    .sort();
  const pages = new Map();
  for (const file of files) {
    const match = file.match(/^\d\d-([a-z0-9]+(?:-[a-z0-9]+)*)\.md$/);
    if (!match) throw new HelpError(`${file}: a page is named NN-slug.md, such as 02-projects.md`);
    pages.set(file, match[1]);
  }

  const images = new Map();
  for (const name of readdirSync(join(dir, 'images'))) {
    if (!/^[a-z0-9-]+\.(webp|png)$/.test(name)) throw new HelpError(`images/${name}: name images in lower case, a-z, 0-9 and -`);
    images.set(name, { ...imageSize(readFileSync(join(dir, 'images', name)), `images/${name}`), used: false });
  }

  const guide = files.map((file) => {
    const lines = readFileSync(join(dir, file), 'utf8').replace(/\r\n/g, '\n').split('\n');
    const title = lines[0].match(/^# (.+)$/)?.[1].trim();
    if (!title) throw new HelpError(`${file}:1: a page starts with "# Its title"`);
    const meta = lines[2]?.match(/^<!-- help audience: ([a-z]+); screens:\s*([a-z, -]*?)\s*-->$/);
    if (lines[1] !== '' || !meta) {
      throw new HelpError(`${file}:3: after the title and a blank line, <!-- help audience: editors; screens: project-edit -->`);
    }
    const audience = meta[1];
    if (!AUDIENCES.includes(audience)) throw new HelpError(`${file}:3: audience is ${AUDIENCES.join(' or ')}`);
    const screens = meta[2].split(',').map((s) => s.trim()).filter(Boolean);
    for (const screen of screens) {
      if (!SCREENS.includes(screen)) throw new HelpError(`${file}:3: no screen is called ${screen}; they are ${SCREENS.join(', ')}`);
    }
    const rest = lines.slice(3);
    const { html, tab } = body(rest, file, pages, images);
    const first = html.match(/^<p>(.*?)<\/p>/);
    if (!first) throw new HelpError(`${file}: a page's first paragraph, under the comment, is its summary`);
    const summary = first[1].replace(/<[^>]+>/g, '').replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
    return { slug: pages.get(file), file, title, audience, screens, summary, html, tab };
  });

  const unused = [...images].filter(([, image]) => !image.used).map(([name]) => `images/${name}`);
  if (unused.length) throw new HelpError(`no page shows ${unused.join(', ')}: use it or delete it`);
  return guide;
}

export function helpJson(guide) {
  const pages = guide.map(({ slug, title, audience, screens, summary, html, tab }) => ({
    slug,
    title,
    audience,
    screens,
    summary,
    html,
    tab,
  }));
  return `${JSON.stringify(
    {
      $comment:
        'Written by scripts/plugin-help.mjs from the Markdown pages beside this file. Change those and run npm run plugin:help; CI fails if this falls behind.',
      pages,
    },
    null,
    2,
  )}\n`;
}

export function readme(guide) {
  const section = (audience, heading) => {
    const pages = guide.filter((page) => page.audience === audience);
    return [`## ${heading}`, '', ...pages.map((page) => `- [${page.title}](${page.file}): ${page.summary}`), ''];
  };
  return [
    '# The FireHouse guide',
    '',
    'How to keep the FireHouse up to date in WordPress, for FireHouse Editors and the',
    "site's administrators. The plugin shows this guide in the dashboard, at FireHouse →",
    'Help, and each FireHouse screen shows its part under the Help tab at the top right.',
    '',
    ...section('editors', 'For FireHouse Editors'),
    ...section('administrators', 'For administrators'),
    '## Changing this guide',
    '',
    'This file is written by `npm run plugin:help` from the pages beside it, which are the',
    "guide's source; so is `help.json`, which the plugin reads. Edit a page, then run",
    '`npm run plugin:help`; CI fails if either file falls behind. `scripts/plugin-help.mjs`',
    'says what Markdown a page may use. `node scripts/help-screenshots.mjs` takes the',
    "screenshots in `images/` again from the local WordPress. A change to a FireHouse",
    "screen changes its page here in the same pull request.",
    '',
  ].join('\n');
}

function main() {
  const unknown = process.argv.slice(2).filter((arg) => arg !== '--check');
  if (unknown.length) throw new Error(`Unknown arguments: ${unknown.join(' ')}. The only one is --check.`);
  const check = process.argv.includes('--check');

  let guide;
  try {
    guide = build();
  } catch (error) {
    if (!(error instanceof HelpError)) throw error;
    console.error(`plugin-help: ${error.message}`);
    process.exit(1);
  }
  const outputs = [
    ['help.json', helpJson(guide)],
    ['README.md', readme(guide)],
  ];
  const stale = [];
  for (const [name, content] of outputs) {
    const path = join(HELP, name);
    let present = null;
    try {
      present = readFileSync(path, 'utf8').replace(/\r\n/g, '\n');
    } catch {
      // Missing: written below, or reported as stale.
    }
    if (present === content) continue;
    if (check) stale.push(name);
    else writeFileSync(path, content);
  }
  if (stale.length) {
    console.error(`plugin-help: help/${stale.join(' and help/')} out of date with the guide's pages. Run: npm run plugin:help`);
    process.exit(1);
  }
  console.log(`The FireHouse guide: ${guide.length} pages${check ? ', up to date' : ''}.`);
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) main();
