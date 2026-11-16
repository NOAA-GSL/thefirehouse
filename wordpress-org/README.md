# WordPress.org listing

What a listing in WordPress.org's plugin directory would need beyond the plugin's zip.
The plugin isn't submitted: this folder is ready for the day GSL decides to, and the
list below says what that waits on.

## What's here

- `assets/`: the images the directory shows beside a plugin, which go to the SVN
  repository's top-level `assets/` folder, not into the zip.
  - `icon-128x128.png`, `icon-256x256.png`: the FireHouse mark on white.
  - `banner-772x250.png`, `banner-1544x500.png`: the wordmark over the link-preview
    image's ember-to-teal rule. The directory prints the plugin's name over the lower
    left, which the wordmark keeps clear of.
  - `screenshot-1.png` to `screenshot-7.png`: the captions are readme.txt's
    `== Screenshots ==`, in the same order.

  `node scripts/wporg-assets.mjs` makes them again: the icon and banner from the logos'
  originals in `src/assets/logos/source/`, and the screenshots from the local WordPress
  (`docker-compose.yml`). The script's comment says how; the dashboard screenshots need
  a signed-in administrator's cookie. The screenshots are cropped to the FireHouse
  area, so GSL's header and footer and the dashboard's menu, toolbar and footer (which
  names WordPress's version) stay out.
- The plugin's `readme.txt` is already in the directory's format: its header, a short
  description under 150 characters, five tags, Installation, Frequently Asked
  Questions, Screenshots, External services (the coverage map's Esri tiles, with
  Esri's terms and privacy statement) and Changelog.
- The zip comes from CI (`scripts/plugin-zip.mjs`), and Plugin Check, which runs the
  directory's own checks, reads what it holds on every change to the plugin.

## Before submitting

1. **The decision to list it.** A listing is public and speaks for NOAA, so it goes
   through communications clearance (CLAUDE.md, "When a person decides" and the
   Communications Clearance Officer row), and the version submitted through GSL's
   security review, as every release does. Clearance covers the screenshots too: they
   show researchers' names and abstracts, published on the site, in a new place, and
   one shows a synthesis marked "Draft, pending team review". Take them again first
   (`node scripts/wporg-assets.mjs`): they show the site as of 16 November 2026, with
   its "AI-synthesized" badge, which the site's wording changes to follow Executive
   Order 14434 ("SI", for Super Intelligence).
2. **Whether it fits the directory.** The directory is for plugins other sites can
   use. The FireHouse's content comes from a seed bundle built from this repository,
   and it is tested only with GSL's classic theme, so the review team may ask how
   another site would use it. Have the answer ready.
3. **An account.** A WordPress.org account to own the plugin, ideally one GSL controls
   rather than a person's. Then add `Contributors:` to readme.txt's header with its
   username, and any others: the directory drops a name that isn't a WordPress.org
   username. The line is left out until then.
4. **The slug.** WordPress.org makes the slug from the plugin's name, `the-firehouse`.
   The plugin's folder and text domain are `firehouse`, and Plugin Check holds the text
   domain to the slug, so ask the review team for `firehouse` when submitting. Both
   were free on 16 November 2026.
5. **Licences.** The plugin is Apache-2.0, which is compatible with GPLv3, as the
   directory requires. It bundles Lucide's icons (ISC), Leaflet (BSD 2-Clause), and
   Archivo and Public Sans (SIL Open Font License 1.1); readme.txt names each, and
   their licences are beside them. The review team confirms these.
6. **The version.** Set `Tested up to` to the newest WordPress at the time, and add an
   `== Upgrade Notice ==` entry for the version submitted.
7. **Take out the Update URI.** The directory refuses a plugin with an `Update URI`
   header. Remove it from `firehouse.php`, and `exclude-checks: plugin_updater` from
   the Plugin Check step in `.github/workflows/wordpress-plugin.yml` (and the README's
   local command), in the version submitted.
8. **After approval.** The directory's SVN repository gets the plugin's files in
   `trunk/` and a `tags/<version>/` copy, and this folder's `assets/` in `assets/`. The
   zip's contents are what goes in `trunk/`.

## Until then

GSL's sites install the reviewed zip from the GitHub release. WordPress offers updates
from WordPress.org for an installed plugin whose folder matches a listed slug, so if
another plugin were ever listed as `firehouse`, GSL's dashboards would offer it as an
update to this one. `firehouse.php`'s `Update URI` header names GitHub instead, so
WordPress never asks WordPress.org about it. Plugin Check flags that header, since the
directory doesn't allow it, so CI leaves that one check out until the plugin is
submitted (step 7 above).
