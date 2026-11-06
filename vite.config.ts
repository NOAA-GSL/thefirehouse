import { copyFileSync, existsSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import { defineConfig, type Plugin } from 'vite';
import react from '@vitejs/plugin-react';
import { scopeFirehouse } from './scripts/scope-css';

const OUT_DIR = 'dist';
// From this file's own place, so a build started from another directory finds it.
const FONTS_DIR = fileURLToPath(new URL('./wp-content/plugins/firehouse/assets/fonts/', import.meta.url));

/**
 * Ships the fonts' licences with the fonts. The SIL Open Font License asks every copy
 * of the fonts to carry it, so for each family whose fonts the build emits, its
 * `<family>-OFL.txt` from the plugin goes in the same folder, under its own name. A
 * font whose name doesn't say its family, or a family without its licence beside it
 * in the plugin, stops the build. A build that emits no fonts emits no licences.
 */
function fontLicences(): Plugin {
  return {
    name: 'firehouse-font-licences',
    apply: 'build',
    generateBundle(_, bundle) {
      const emitted = new Map<string, string>();
      for (const file of Object.values(bundle)) {
        if (file.type !== 'asset' || !file.fileName.endsWith('.woff2')) continue;
        const family = /^([a-z0-9-]+?)-(?:normal|italic)-/.exec(file.fileName.split('/').pop() ?? '')?.[1];
        if (!family) throw new Error(`${file.fileName} doesn't name its family, so its licence can't be found.`);
        emitted.set(family, dirname(file.fileName));
      }
      for (const [family, folder] of emitted) {
        const licence = resolve(FONTS_DIR, `${family}-OFL.txt`);
        if (!existsSync(licence)) throw new Error(`${licence} is missing: the ${family} fonts can't ship without it.`);
        this.emitFile({ type: 'asset', fileName: `${folder}/${family}-OFL.txt`, source: readFileSync(licence) });
      }
    },
  };
}

/**
 * Emits `404.html` as a copy of `index.html`.
 *
 * The site is client-routed: `/projects` and `/topics/observe` have no file behind
 * them, so a direct hit, a refresh, or a link shared off a conference slide 404s
 * unless the host serves the app shell for unknown paths. On a server we control
 * that's `public/.htaccess` (Apache) or an nginx `try_files`; on bucket-style hosts
 * — S3, GitHub Pages, Azure Static Web Apps — the only lever is a 404 document, and
 * this is it. Harmless everywhere else. A build that writes nothing, such as
 * `scripts/plugin-css.mjs`, leaves `dist/` alone.
 */
function spaFallback(): Plugin {
  let writes = true;
  let outDir = OUT_DIR;
  return {
    name: 'firehouse-spa-fallback',
    apply: 'build',
    configResolved(config) {
      writes = config.build.write !== false;
      outDir = resolve(config.root, config.build.outDir);
    },
    closeBundle() {
      if (!writes) return;
      const index = resolve(outDir, 'index.html');
      if (existsSync(index)) copyFileSync(index, resolve(outDir, '404.html'));
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
  plugins: [react(), siteMeta(), spaFallback(), fontLicences()],
  // Every rule is scoped under `.fh-app` (`#root` here), as the plugin needs inside
  // GSL's theme (WORDPRESS-PLAN.md rule 9), so the site runs on the same scoped CSS the
  // plugin gets. The page shell styles the static build's own `<html>` and `<body>` and
  // stays outside.
  css: { postcss: { plugins: [scopeFirehouse({ exclude: [/\/src\/styles\/page-shell\.css$/] })] } },
  // PORT lets a harness (or a second checkout) run the dev server somewhere else
  // when 5173 is already taken; 5173 stays the default everyone knows.
  server: { port: Number(process.env.PORT) || 5173 },
});
