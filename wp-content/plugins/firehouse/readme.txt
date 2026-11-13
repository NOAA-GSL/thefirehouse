=== The FireHouse ===
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: Apache-2.0
License URI: https://www.apache.org/licenses/LICENSE-2.0

Public-facing hub for NOAA GSL's fire weather social science needs and findings.

== Description ==

The FireHouse is a section of GSL's WordPress site, at /firehouse. It is built in
phases; WORDPRESS-PLAN.md in https://github.com/NOAA-GSL/thefirehouse describes them.

It registers the project and topic post types and their fields. Projects are at
/firehouse/projects/ and topics at /firehouse/topics/{key}/. It adds the FireHouse
Editor role, a FireHouse settings screen, and an Import screen that loads the site's
content from a seed bundle. Every FireHouse address (projects, topics and pages under
/firehouse/) gets the plugin's frame: the theme's header and footer, with the
FireHouse area between them in the plugin's own stylesheets and fonts. A FireHouse
bar under the theme's header carries the FireHouse wordmark and links, and the Submit
a Finding button once the settings give it an address. The theme's hero background
and the mission section above its footer are hidden on those pages. Every FireHouse
page ends with a disclaimer that researchers' findings are their own, not NOAA's, and
a missing FireHouse address shows the FireHouse not-found page. Each project's page
shows the researcher's own words, and each topic area's page its top needs, the
published projects behind each, and what the area covers. Each FireHouse page has a
title that names the page, The FireHouse and then the site's own name, a
description, and link-preview tags, unless an SEO plugin is active. On FireHouse
pages, WordPress no longer curls quotes, converts dashes, turns smileys into images or recapitalizes its own name, so researchers' text appears as
they wrote it. The Import screen creates the landing page and the About page from the
seed bundle; the About page's portraits are uploaded to the Media Library by an
administrator. The project explorer, at /firehouse/projects/, lists the projects a
visitor may see with a search, filters that show how many projects each choice
leaves, and a sort. With JavaScript, results update as you type. It works without
JavaScript too, and an address with the static site's parameters, such as /firehouse/projects/?q=smoke&region=NWCC, opens it already
filtered.

The fonts in assets/fonts/, Archivo and Public Sans, are third-party, under the SIL
Open Font License 1.1, whose text is beside them. assets/fonts/SOURCES.json records
where each file came from.

The icons in assets/icons/ are Lucide's, under the ISC License, whose text is beside
them in LICENSE.txt. Lucide credits portions of them to Feather (MIT) there.

The Import screen keeps nothing it doesn't import: the uploaded file isn't saved, and
the checked content waits briefly for the confirmation and is then cleared. The plugin
stores only what the site publishes.

Deactivating the plugin keeps the FireHouse Editor role, which can then only sign in.
Deleting the plugin removes the role and the plugin's capabilities, and users who had
the role are left with no role on the site. The content stays.

An update that changes the role or its capabilities applies the role, and the
administrators' FireHouse capabilities, as the plugin defines them, so a change made
to either with a role editor doesn't last past it.

== Changelog ==

= Unreleased =
Built since 0.2.0, and not yet through a release review; the version stays 0.2.0 until
one is.
* The frame: every FireHouse page in the theme's header and footer, with the plugin's
  stylesheets and self-hosted fonts, and the theme's hero and footer mission section
  hidden.
* The FireHouse bar, the plugin's first block, with its menu on narrow screens.
* The site's icons and logos.
* Researchers' text kept as they wrote it on FireHouse pages.
* The disclaimer at the end of every FireHouse page, and the FireHouse not-found page.
* The project pages: the abstract, takeaways, needs and recommendations and papers as
  the researcher submitted them, the facts at a glance, and the projects either side.
  A need card that scrolls can be reached and scrolled by keyboard. Stored text shows
  exactly as stored, on the topic pages too.
* The landing page, created by the Import screen from the seed bundle when there's
  none: the hero with live counts, the four topic areas' top needs, and the submit
  band. The coverage map comes later.
* The About page, created by the Import screen under the landing page when there's
  none: what the hub is, who it serves, how it works, the team, with portraits an
  administrator uploads to the Media Library before importing (initials otherwise),
  and the submit band. The landing page can't be deleted for good while pages
  outside the trash are under it.
* Each FireHouse page's title names the page and The FireHouse, then the site's own
  name, and carries a description and link-preview tags: a project's page, the
  project's own abstract. Nothing changes on any other page, nor when an SEO plugin is
  active.
* The topic area pages: each area's top needs with the projects behind them, what it
  covers, and the other areas. A need lists only projects a visitor may see:
  published, without a password, and with IRB approval or exemption.
* A project or topic query variable that isn't a usable slug (an array, empty, or 0)
  is dropped, so a malformed one can't cause an error on any address.
* A stored Submit a Finding address that isn't https counts as none, so no button
  points at it.
* Projects and topics are left out of the site's search box (WordPress's search
  across all types), and so out of WordPress's guess at a missing address outside
  /firehouse/. The sitemap still lists the ones a visitor may see, and a project's or
  topic's address cut short still finds it.
* A project a visitor may not see (unpublished, password-protected, or without IRB
  approval or exemption) is off the public side of the site: its address, ?p=, the
  feed, the sitemap, embeds, oEmbed, menus, the redirects built from its ID and the
  not-found guess all leave it out. Someone who may edit it still sees it on its own
  page.
* The project explorer at /firehouse/projects/: a search, filters that show how many
  projects each choice leaves, a sort, and a tile for each project a visitor may see.
  It works without JavaScript: the filters apply when the form is sent, and an address
  such as /firehouse/projects/?q=smoke&region=NWCC opens it already filtered. Search
  engines are asked not to index an address with a query. With JavaScript, results
  update as you type, with no request, and the address follows; on a phone the
  filters fold behind a button. The quick look comes later.
* In the block editor, each FireHouse block shows, read-only, what the page stores in
  it, so that can be told from what FireHouse adds. Changing it comes later. Opening
  and saving a page keeps its FireHouse blocks, the ones they hold included.
* The name the plugin prints is FireHouse: the plugin's name, the role, the admin
  menu, the block titles, and the pages' titles and words. A site that imported its
  content before keeps the old spelling in its settings and in the landing and About
  pages until they are changed.

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
