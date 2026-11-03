/**
 * Walls the site's CSS in under one wrapper class (WORDPRESS-PLAN.md rule 9), so it
 * can sit inside GSL's theme without reaching the theme's header and footer.
 *
 * Every selector is rewritten to match only inside the wrapper:
 *
 *   :root, html, body       .fh-app             tokens and page styles land on the wrapper
 *   body::before            .fh-app::before
 *   *                       .fh-app, .fh-app *  the wrapper and everything in it
 *   [data-theme='dark'] x   [data-theme='dark'] .fh-app x
 *   :root:not([…]) x        :root:not([…]) .fh-app x
 *   anything else           .fh-app anything else
 *
 * The theme attribute stays on `<html>`, where both the static build's ThemeProvider
 * and GSL's own light/dark switch set it. Every rule gains the same one class, so the
 * site's rules keep their order against each other.
 *
 * Vite runs this over every stylesheet the static build imports, with `#root` as the
 * wrapper, so the reference screenshots check it. `scripts/plugin-css.mjs` takes the
 * plugin's stylesheet from that same build.
 */

export const SCOPE = '.fh-app';

/** Attributes set on `<html>`, so a selector that starts with one is about the page. */
const ROOT_ATTRIBUTES = ['data-theme'];

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

/**
 * Rewrites one selector (no top-level commas) to match only inside the wrapper. A
 * selector that already names the wrapper is left alone, so running twice is safe.
 */
export function scopeSelector(selector: string, scope = SCOPE): string {
  const trimmed = selector.trim();
  const named = new RegExp(`${scope.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(?![\\w-])`);
  if (named.test(trimmed)) return trimmed;
  const [leading, rest] = splitLeading(trimmed);

  // The page itself: its styles move onto the wrapper, keeping any pseudo-element.
  const page = /^(?::root|html|body)(?=$|::)/.exec(leading);
  if (page) return `${scope}${leading.slice(page[0].length)}${rest}`;

  // A bare `*` matched <body> too, so the wrapper keeps it alongside its contents.
  if (rest === '' && /^\*(?=$|::)/.test(leading)) return `${scope}${leading.slice(1)}, ${scope} ${leading}`;

  // A condition on the page, such as the theme: it stays on <html>, above the wrapper.
  const attribute = /^\[\s*([\w-]+)/.exec(leading)?.[1];
  if (/^(?::root|html)/.test(leading) || (attribute && ROOT_ATTRIBUTES.includes(attribute))) {
    return `${leading} ${scope}${rest}`;
  }

  return `${scope} ${trimmed}`;
}

/** The parts of PostCSS's types this plugin touches. */
interface CssRule {
  selectors: string[];
  parent?: { type: string; name?: string };
  source?: { input?: { file?: string } };
}
interface CssRoot {
  walkRules(callback: (rule: CssRule) => void): void;
}

/**
 * The PostCSS plugin. It walks each stylesheet once, after Vite has inlined its
 * `@import`s. A rule from a file whose path matches `exclude` is left as it is: the
 * static build's page shell styles its own `<body>`, outside the wrapper.
 */
export function scopeFirehouse({ scope = SCOPE, exclude = [] as RegExp[] } = {}) {
  return {
    postcssPlugin: 'firehouse-scope',
    Once(root: CssRoot) {
      root.walkRules((rule) => {
        const file = (rule.source?.input?.file ?? '').replace(/\\/g, '/');
        if (exclude.some((pattern) => pattern.test(file))) return;
        if (rule.parent?.type === 'atrule' && /keyframes$/i.test(rule.parent.name ?? '')) return;
        rule.selectors = rule.selectors.map((selector) => scopeSelector(selector, scope));
      });
    },
  };
}
