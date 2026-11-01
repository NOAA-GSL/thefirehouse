import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { normalizeSiteContent, type RawSiteContent } from './normalize';
import type { Project, SiteContent } from './types';

/**
 * Loads the shared test cases in `wp-content/plugins/firehouse/shared/cases/`.
 *
 * The cases are plain JSON, inputs and expected outputs, so the plugin's PHPUnit tests
 * can run the same ones against the PHP port and the two can't drift. Expected values
 * are compared as JSON: `undefined` fields are absent and a Map is an object, which is
 * what PHP's `json_decode` hands back too.
 *
 * Arguments can name the shared site instead of repeating it:
 *  - `"@site"`, the site in `site.json` after `normalizeSiteContent`;
 *  - `"@published"`, its published projects, which is what the explorer searches;
 *  - `"@project:<slug>"`, one of its projects.
 */

const CASES = new URL('../../wp-content/plugins/firehouse/shared/cases/', import.meta.url);

export function readCases<T>(file: string): T {
  return JSON.parse(readFileSync(fileURLToPath(new URL(file, CASES)), 'utf8')) as T;
}

/** A fresh copy of `site.json`, before normalisation. */
export function rawSite(): RawSiteContent {
  return readCases<RawSiteContent>('site.json');
}

export function site(): SiteContent {
  return normalizeSiteContent(rawSite());
}

export function resolveArg(arg: unknown, content: SiteContent): unknown {
  if (arg === '@site') return content;
  if (arg === '@published') return content.projects.filter((project) => project.published);
  if (typeof arg === 'string' && arg.startsWith('@project:')) {
    const slug = arg.slice('@project:'.length);
    const project = content.projects.find((p) => p.slug === slug);
    if (!project) throw new Error(`No project "${slug}" in site.json.`);
    return project;
  }
  return arg;
}

/** JSON's view of a value: no `undefined`, Maps as objects. */
export function asJson(value: unknown): unknown {
  return JSON.parse(
    JSON.stringify(value, (_key, v) => (v instanceof Map ? Object.fromEntries(v) : v)),
  ) as unknown;
}

export const slugs = (projects: Project[]) => projects.map((project) => project.slug);

/* --------------------------------------------------------------- JSON Pointer -- */

function tokens(pointer: string): string[] {
  if (pointer === '') return [];
  return pointer
    .slice(1)
    .split('/')
    .map((token) => token.replace(/~1/g, '/').replace(/~0/g, '~'));
}

export function pointerGet(document: unknown, pointer: string): unknown {
  let node = document;
  for (const token of tokens(pointer)) {
    if (node === null || typeof node !== 'object') return undefined;
    node = (node as Record<string, unknown>)[token];
  }
  return node;
}

function parentOf(document: unknown, pointer: string): [Record<string, unknown>, string] {
  const path = tokens(pointer);
  const last = path.pop();
  const parent = path.reduce<unknown>(
    (node, token) => (node as Record<string, unknown>)[token],
    document,
  );
  if (last === undefined || parent === null || typeof parent !== 'object') {
    throw new Error(`Can't reach ${pointer}.`);
  }
  return [parent as Record<string, unknown>, last];
}

export function pointerSet(document: unknown, pointer: string, value: unknown): void {
  const [parent, key] = parentOf(document, pointer);
  parent[key] = value;
}

export function pointerRemove(document: unknown, pointer: string): void {
  const [parent, key] = parentOf(document, pointer);
  if (Array.isArray(parent)) parent.splice(Number(key), 1);
  else delete parent[key];
}
