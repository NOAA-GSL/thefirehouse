=== The Firehouse ===
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: Apache-2.0
License URI: https://www.apache.org/licenses/LICENSE-2.0

Public-facing hub for NOAA GSL's fire weather social science needs and findings.

== Description ==

The Firehouse as a section of GSL's WordPress site, at /firehouse. It is built in
phases; WORDPRESS-PLAN.md in https://github.com/NOAA-GSL/thefirehouse describes them.

It registers the project and topic post types and their fields. Projects are at
/firehouse/projects/ and topics at /firehouse/topics/{key}/. It adds the Firehouse
Editor role, a Firehouse settings screen, and an Import screen that loads the site's
content from a seed bundle. Every Firehouse address (projects, topics and pages under
/firehouse/) gets the plugin's frame: the theme's header and footer, with the
Firehouse area between them in the plugin's own stylesheets and fonts. A Firehouse
bar under the theme's header carries the Firehouse wordmark and links, and the Submit
a Finding button once the settings give it an address. The theme's hero background
and the mission section above its footer are hidden on those pages. Every Firehouse
page ends with a disclaimer that researchers' findings are their own, not NOAA's, and
a missing Firehouse address shows the Firehouse not-found page. Each topic area's
page shows its top needs, the published projects behind each, and what the area
covers. On Firehouse pages, WordPress no longer curls quotes, converts dashes, turns
smileys into images or recapitalizes its own name, so researchers' text appears as
they wrote it. The other pages come in a later phase; until then the frame shows a
placeholder.

The fonts in assets/fonts/, Archivo and Public Sans, are third-party, under the SIL
Open Font License 1.1, whose text is beside them. assets/fonts/SOURCES.json records
where each file came from.

The icons in assets/icons/ are Lucide's, under the ISC License, whose text is beside
them in LICENSE.txt. Lucide credits portions of them to Feather (MIT) there.

The Import screen keeps nothing it doesn't import: the uploaded file isn't saved, and
the checked content waits briefly for the confirmation and is then cleared. The plugin
stores only what the site publishes.

Deactivating the plugin keeps the Firehouse Editor role, which can then only sign in.
Deleting the plugin removes the role and the Firehouse capabilities, and users who had
the role are left with no role on the site. The content stays.

== Changelog ==

= Unreleased =
Built since 0.2.0, and not yet through a release review; the version stays 0.2.0 until
one is.
* The frame: every Firehouse page in the theme's header and footer, with the plugin's
  stylesheets and self-hosted fonts, and the theme's hero and footer mission section
  hidden.
* The Firehouse bar, the plugin's first block, with its menu on narrow screens.
* The site's icons and logos.
* Researchers' text kept as they wrote it on Firehouse pages.
* The disclaimer at the end of every Firehouse page, and the Firehouse not-found page.
* The topic area pages: each area's top needs with the projects behind them, what it
  covers, and the other areas. A need lists only projects a visitor may see:
  published, without a password, and with IRB approval or exemption.
* A project or topic query variable that isn't a usable slug (an array, empty, or 0)
  is dropped, so a malformed one can't cause an error on any address.
* A stored Submit a Finding address that isn't https counts as none, so no button
  points at it.
* Projects and topics are left out of the site's search box (WordPress's search
  across all types), and so out of WordPress's guess at a missing address outside
  /firehouse/. The sitemap still lists them, and a project's or topic's address cut
  short still finds it.

= 0.2.0 =
* Project and topic post types at the Firehouse addresses, with their fields as post
  meta, checked on every write and kept in revisions.
* The Firehouse Editor role, limited to Firehouse content and the Firehouse pages.
* Firehouse settings: the site name, the Submit a Finding label and address, and the
  navigation labels.
* The Import screen: a dry run of a seed bundle, then the import.
* WordPress's `year` query variable is kept off Firehouse pages.

= 0.1.0 =
* Empty plugin, so the local WordPress, coding standards and tests can be set up.
