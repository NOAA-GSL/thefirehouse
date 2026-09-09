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
 * Injects the link-preview tags that have to carry an absolute URL.
 *
 * `og:image`, `og:url` and `rel=canonical` are only meaningful as absolute URLs, and
 * the final address is still open (creative brief §8). So they're injected from
 * `VITE_SITE_URL` and simply omitted when it isn't set — a missing og:image degrades
 * to a title-and-description preview, whereas a guessed one would point at a domain
 * that may never exist. Set VITE_SITE_URL once Matt confirms placement.
 */
function siteMeta(): Plugin {
  return {
    name: 'firehouse-site-meta',
    transformIndexHtml: {
      order: 'post',
      handler(html) {
        const site = process.env.VITE_SITE_URL?.trim().replace(/\/+$/, '');
        if (!site) return html;

        const base = (process.env.VITE_BASE_PATH ?? '/').replace(/\/+$/, '');
        const root = `${site}${base}`;
        const tags = [
          `<link rel="canonical" href="${root}/" />`,
          `<meta property="og:url" content="${root}/" />`,
          `<meta property="og:image" content="${root}/og-image.png" />`,
          `<meta name="twitter:image" content="${root}/og-image.png" />`,
        ];
        return html.replace('</head>', `${tags.map((t) => `    ${t}`).join('\n')}\n  </head>`);
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
  server: { port: 5173 },
});
