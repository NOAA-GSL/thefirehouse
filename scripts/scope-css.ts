/**
 * Walls the site's CSS in under one wrapper class (WORDPRESS-PLAN.md rule 9), so it
 * can sit inside GSL's theme without reaching the theme's header and footer.
 *
 * Every selector is rewritten to match only inside the wrapper:
 *
 *   :root, html, body       .fh-app             tokens and page styles land on the wrapper
 *   body::before            .fh-app::before
 *   *                       .fh-app, .fh-app *  a bare `*` reached <body>, so the wrapper too
 *   [data-theme='dark'] x   [data-theme='dark'] .fh-app x
 *   :root:not([…]) x        :root:not([…]) .fh-app x
 *   anything else           .fh-app anything else
 *
 * A condition on the page stays above the wrapper: the theme attribute, which both the
 * static build's ThemeProvider and GSL's light/dark switch set on `<html>`, and a class
 * Leaflet puts on `<body>` while a map is dragged.
 *
 * Most rules gain one class, so they keep their order against each other. The page's
 * own rules change weight instead: `:root` stays (0,1,0) as `.fh-app`, `html` and
 * `body` trade an element for a class, and a theme condition gains the class on top of
 * its attribute. So dark tokens now outweigh light ones rather than following them.
 *
 * The plugin refuses to let a rule out: a selector that could match outside the
 * wrapper, or that still names `html`, `body` or `:root` past its page condition
 * (where it would match nothing), stops the build. Vite runs it over the static build,
 * with `#root` as the wrapper, so the site itself runs on the scoped CSS, and
 * `scripts/plugin-css.mjs` takes the plugin's stylesheet from that same build.
 */

export const SCOPE = '.fh-app';

/** Attributes set on `<html>`, so a selector that starts with one is about the page. */
const ROOT_ATTRIBUTES = ['data-theme'];
/** Classes set on `<body>` by code outside the site: Leaflet's, during a drag. */
const ROOT_CLASSES = ['leaflet-dragging'];

/**
 * Splits a selector at its first combinator: the leading compound selector, and the
 * rest with the combinator still on it. Brackets, parentheses and quotes are skipped,
 * so `:not(a b)` and `[title='a b']` stay whole.
 */
function splitLeading(selector: string): [string, string] {
  let depth = 0;
  let quote = '';
  for (let i = 0; i < selector.length; i++) {
    const c = selector[i];
    if (quote) {
      if (c === '\\') i++;
      else if (c === quote) quote = '';
    } else if (c === '"' || c === "'") quote = c;
    else if (c === '\\') i++;
    else if (c === '(' || c === '[') depth++;
    else if (c === ')' || c === ']') depth--;
    else if (depth === 0 && /[\s>+~]/.test(c)) return [selector.slice(0, i), selector.slice(i)];
  }
  return [selector, ''];
}

const escapeRegExp = (text: string) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/** A compound selector that stands for the page, above the wrapper. */
function isPageCondition(compound: string): boolean {
  if (/^(?::root|html)(?![\w-])/.test(compound)) return true;
  const attribute = /^\[\s*([\w-]+)/.exec(compound)?.[1];
  if (attribute && ROOT_ATTRIBUTES.includes(attribute)) return true;
  return ROOT_CLASSES.some((name) => compound === `.${name}`);
}

/**
 * True when a selector can only match inside the wrapper: it starts at the wrapper,
 * or at a page condition and then the wrapper, and nothing after the wrapper is one of
 * its siblings.
 */
export function isAnchored(selector: string, scope = SCOPE): boolean {
  const wrapper = new RegExp(`^${escapeRegExp(scope)}(?![\\w-])(?:::?[\\w-]+)?$`);
  let [leading, rest] = splitLeading(selector.trim());
  if (!wrapper.test(leading)) {
    if (!isPageCondition(leading) || !/^\s+[^\s>+~]/.test(rest)) return false;
    [leading, rest] = splitLeading(rest.trim());
    if (!wrapper.test(leading)) return false;
  }
  return !/^\s*[+~]/.test(rest);
}

/**
 * Rewrites one selector (no top-level commas) to match only inside the wrapper: one
 * selector back, or two for a bare `*`. A selector already anchored there is left
 * alone, so running twice is safe.
 */
export function scopeSelectors(selector: string, scope = SCOPE): string[] {
  const trimmed = selector.trim();
  if (isAnchored(trimmed, scope)) return [trimmed];
  const [leading, rest] = splitLeading(trimmed);

  // The page itself: its styles move onto the wrapper, keeping any pseudo-element.
  const page = /^(?::root|html|body)(?=$|::)/.exec(leading);
  if (page) return [`${scope}${leading.slice(page[0].length)}${rest}`];

  // A bare `*` matched <body> too, so the wrapper keeps it alongside its contents.
  if (rest === '' && /^\*(?=$|::)/.test(leading)) return [`${scope}${leading.slice(1)}`, `${scope} ${leading}`];

  // A condition on the page stays above the wrapper.
  if (isPageCondition(leading)) return [`${leading} ${scope}${rest}`];

  return [`${scope} ${trimmed}`];
}

/** `scopeSelectors`, joined as a selector list. */
export const scopeSelector = (selector: string, scope = SCOPE) => scopeSelectors(selector, scope).join(', ');

/**
 * Why a scoped selector can't stand, or '' if it can: it could match outside the
 * wrapper, or past the wrapper it names the page or the wrapper again, where it
 * matches nothing.
 */
export function scopeProblem(selector: string, scope = SCOPE): string {
  if (!isAnchored(selector, scope)) return `"${selector}" can match outside ${scope}`;
  const [leading, rest] = splitLeading(selector.trim());
  const after = isPageCondition(leading) ? splitLeading(rest.trim())[1] : rest;
  const page = [
    /(?:^|[\s>+~(,])(?:html|body)(?![\w-])/,
    /:root(?![\w-])/,
    new RegExp(`${escapeRegExp(scope)}(?![\\w-])`),
    ...ROOT_ATTRIBUTES.map((name) => new RegExp(`\\[\\s*${name}(?![\\w-])`)),
    ...ROOT_CLASSES.map((name) => new RegExp(`\\.${name}(?![\\w-])`)),
  ];
  if (page.some((pattern) => pattern.test(after))) {
    return `"${selector}" names the page or ${scope} inside ${scope}, where it matches nothing`;
  }
  return '';
}

/** The parts of a parsed stylesheet `outputProblems` walks. */
interface CssNode {
  type: string;
  name?: string;
  params?: string;
  prop?: string;
  value?: string;
  selectors?: string[];
  parent?: CssNode;
}

/** At-rules that only ever hold or condition scoped rules. */
const CONTAINED_AT_RULES = ['media', 'supports', 'container'];

/**
 * What a finished stylesheet would do outside the wrapper, as a list: an unscoped
 * selector, an at-rule that reaches the whole page (`@font-face`, `@property`,
 * `@layer`, a keyframes name without the `fh-` prefix), or anything that loads from
 * elsewhere (`@import`, a `url()` or `image-set()` other than `data:` or a fragment).
 */
export function outputProblems(root: { walk(callback: (node: CssNode) => void): void }, scope = SCOPE): string[] {
  const problems: string[] = [];
  root.walk((node) => {
    if (node.type === 'atrule') {
      const name = (node.name ?? '').toLowerCase();
      if (/keyframes$/.test(name)) {
        if (!/^fh-/.test(node.params ?? '')) problems.push(`@${node.name} ${node.params} isn't named fh-…`);
      } else if (!CONTAINED_AT_RULES.includes(name)) problems.push(`@${node.name} ${node.params ?? ''}`.trim());
    }
    if (node.type === 'decl' && /url\((?!\s*['"]?\s*(?:data:|#))|image-set\(/i.test(node.value ?? '')) {
      problems.push(`${node.prop}: ${node.value}`);
    }
    if (node.type === 'rule' && !(node.parent?.type === 'atrule' && /keyframes$/i.test(node.parent.name ?? ''))) {
      for (const selector of node.selectors ?? []) if (!isAnchored(selector, scope)) problems.push(`unscoped ${selector}`);
    }
  });
  return problems;
}

/** The parts of PostCSS's types this plugin touches. */
interface CssRule {
  selectors: string[];
  parent?: { type: string; name?: string; parent?: unknown };
  source?: { input?: { file?: string }; start?: { line: number } };
}

/** True when a rule sits inside another rule, as CSS nesting writes it. */
function isNested(rule: CssRule): boolean {
  for (let node = rule.parent as { type?: string; parent?: unknown } | undefined; node; node = node.parent as typeof node) {
    if (node.type === 'rule') return true;
  }
  return false;
}
interface CssRoot {
  walkRules(callback: (rule: CssRule) => void): void;
}

/**
 * The PostCSS plugin. It walks each stylesheet once, after Vite has inlined its
 * `@import`s. A rule from a file whose path matches `exclude` is left as it is: the
 * static build's page shell styles its own `<html>` and `<body>`, outside the wrapper.
 */
export function scopeFirehouse({ scope = SCOPE, exclude = [] as RegExp[] } = {}) {
  return {
    postcssPlugin: 'firehouse-scope',
    Once(root: CssRoot) {
      root.walkRules((rule) => {
        const file = (rule.source?.input?.file ?? '').replace(/\\/g, '/').replace(/\?.*$/, '');
        if (exclude.some((pattern) => pattern.test(file))) return;
        if (rule.parent?.type === 'atrule' && /keyframes$/i.test(rule.parent.name ?? '')) return;
        const where = `${file}:${rule.source?.start?.line ?? '?'}`;
        // A nested rule is scoped through its parent; prefixing it again would match nothing.
        if (isNested(rule)) throw new Error(`${where}: nested CSS rules aren't supported by the scoping.`);
        const scoped = rule.selectors.flatMap((selector) => scopeSelectors(selector, scope));
        const problems = scoped.map((selector) => scopeProblem(selector, scope)).filter(Boolean);
        if (problems.length) throw new Error(`${where}: ${problems.join('; ')}.`);
        rule.selectors = scoped;
      });
    },
  };
}
