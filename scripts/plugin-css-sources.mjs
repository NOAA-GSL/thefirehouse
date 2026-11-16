/**
 * Which of the site's stylesheets the plugin's stylesheet leaves out, for
 * `scripts/plugin-css.mjs`, which says why, and for `scripts/plugin-css.test.mjs`,
 * which fails if the plugin's markup uses a class only a left-out file styles. Paths
 * are from the repository's root.
 */

/** What GSL's theme does under the plugin, and the fonts, which the plugin loads on its own. */
export const LEFT_OUT = [
  'src/styles/page-shell.css',
  'src/design-system/GovBanner.css',
  'src/design-system/SiteFooter.css',
  'wp-content/plugins/firehouse/assets/css/fonts.css',
];

/** TopicTagRow, which the design system exports and no page renders. Field.css styles the survey form's fields. */
export const UNUSED = ['src/design-system/TopicTagRow.css'];

/** Left out, but allowed to stop being imported: the fonts, when the site stops loading them. */
export const MAY_GO = ['wp-content/plugins/firehouse/assets/css/fonts.css'];

/**
 * Chunks whose stylesheets the plugin loads on their own, not in firehouse.css: the
 * coverage map's, which `scripts/plugin-css.mjs` writes as coverage-map.css and
 * coverage-map.min.css. That script stops if there's more than this one.
 */
export const DEFERRED = ['RegionMap'];
