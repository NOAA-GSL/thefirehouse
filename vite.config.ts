import { copyFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { defineConfig, type Plugin } from 'vite';
import react from '@vitejs/plugin-react';

const OUT_DIR = 'dist';

/**
 * Emits `404.html` as a copy of `index.html`.
 *
 * The site is client-routed: `/projects` and `/topics/observe` have no file behind
 * them, so a direct hit, a refresh, or a link shared off a conference slide 404s
 * unless the host serves the app shell for unknown paths. On a server we control
 * that's `public/.htaccess` (Apache) or an nginx `try_files`; on bucket-style hosts
 * — S3, GitHub Pages, Azure Static Web Apps — the only lever is a 404 document, and
 * this is it. Harmless everywhere else.
 */
function spaFallback(): Plugin {
  return {
    name: 'firehouse-spa-fallback',
    apply: 'build',
    closeBundle() {
      const index = resolve(OUT_DIR, 'index.html');
      if (existsSync(index)) copyFileSync(index, resolve(OUT_DIR, '404.html'));
    },
  };
}

/**
 * Injects the link-preview image, which has to carry an absolute URL.
 *
 * `og:image` is only meaningful as an absolute URL, so it's injected from
 * `VITE_SITE_URL` and simply omitted when that isn't set — a missing og:image degrades
 * to a title-and-description preview, whereas a guessed one would point at a domain
 * that may never exist.
 *
 * There is no `rel=canonical` or `og:url`. Every route is served the same index.html
 * and nothing rewrites the head at runtime, so either tag would tell search engines
 * and link previews that each project and topic page is the home page.
 *
 * `VITE_NOINDEX=1` also asks search engines not to index any page, for a copy that
 * isn't the public site, such as the one on GSL's test site. A `robots.txt` can't do
 * that job here: under a sub-path like `/firehouse/` it isn't at the site's root, so
 * crawlers never read it. `1`, `true` and `yes` turn it on; empty, `0`, `false` and
 * `no` leave it off; anything else stops the build, so a value meant to say "off"
 * can't take the public site out of search. The build prints which tags it added.
 *
 * Both are read from the shell's environment at build time, not from `.env` files,
 * which Vite loads for the app's code but not for this config.
 */
function siteMeta(): Plugin {
  return {
    name: 'firehouse-site-meta',
    transformIndexHtml: {
      order: 'post',
      handler(html) {
        const tags: string[] = [];
        const noindex = process.env.VITE_NOINDEX?.trim() ?? '';
        if (/^(1|true|yes)$/i.test(noindex)) tags.push('<meta name="robots" content="noindex" />');
        else if (!/^(|0|false|no)$/i.test(noindex)) {
          throw new Error(`VITE_NOINDEX="${noindex}" is neither on (1, true, yes) nor off (0, false, no).`);
        }

        const site = process.env.VITE_SITE_URL?.trim().replace(/\/+$/, '');
        if (site) {
          const base = (process.env.VITE_BASE_PATH ?? '/').replace(/\/+$/, '');
          const image = `${site}${base}/og-image.png`;
          tags.push(
            `<meta property="og:image" content="${image}" />`,
            `<meta name="twitter:image" content="${image}" />`,
          );
        }

        if (tags.length === 0) return html;
        console.log(`\nfirehouse-site-meta added:\n${tags.map((t) => `  ${t}`).join('\n')}`);
        return html.replace('</head>', () => `${tags.map((t) => `    ${t}`).join('\n')}\n  </head>`);
      },
    },
  };
}

// `base` is intentionally configurable. Hosting is still open (see creative brief §8):
// the site may end up at sites.gsl/firehouse, so it must be able to build for a
// sub-path without code changes. Set VITE_BASE_PATH at build time.
export default defineConfig({
  base: process.env.VITE_BASE_PATH ?? '/',
  build: { outDir: OUT_DIR },
  plugins: [react(), siteMeta(), spaFallback()],
  // PORT lets a harness (or a second checkout) run the dev server somewhere else
  // when 5173 is already taken; 5173 stays the default everyone knows.
  server: { port: Number(process.env.PORT) || 5173 },
});
