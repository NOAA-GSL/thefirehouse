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
content from a seed bundle. It has no page templates yet, so the site's theme shows
those addresses with its own templates.

The fonts in assets/fonts/, Archivo and Public Sans, are third-party, under the SIL
Open Font License 1.1, whose text is beside them. assets/fonts/SOURCES.json records
where each file came from.

The Import screen keeps nothing it doesn't import: the uploaded file isn't saved, and
the checked content waits briefly for the confirmation and is then cleared. The plugin
stores only what the site publishes.

Deactivating the plugin keeps the Firehouse Editor role, which can then only sign in.
Deleting the plugin removes the role and the Firehouse capabilities, and users who had
the role are left with no role on the site. The content stays.

== Changelog ==

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
