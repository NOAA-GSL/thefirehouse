=== The FireHouse ===
Tags: research, social science, fire weather, wildfire, science communication
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: Apache-2.0
License URI: https://www.apache.org/licenses/LICENSE-2.0

Public-facing hub for NOAA GSL's fire weather social science needs and findings.

== Description ==

The FireHouse gathers completed fire weather social science research in one place:
each project in its researchers' own words, and, across all of them, what forecasters,
land managers and emergency managers need, by topic area. Visitors read and search
the projects. FireHouse Editors, a role the plugin adds, review and publish them, keep
the topic areas' words and the landing and About pages up to date, and publish a
reviewed synthesis of the needs. It is built for NOAA's Global Systems Laboratory
(GSL) and GSL's classic theme.

The FireHouse is a section of GSL's WordPress site, at /firehouse. It is built in
phases; WORDPRESS-PLAN.md in https://github.com/NOAA-GSL/thefirehouse describes them.

= What it adds =

It registers the project and topic post types and their fields. Projects are at
/firehouse/projects/ and topics at /firehouse/topics/{key}/. It adds the FireHouse
Editor role, a FireHouse settings screen, an Import screen that loads the site's
content from a seed bundle, and edit screens for projects and topics, where editors
change the FireHouse team's fields and see the survey's answers and the synthesis
read-only. Editors change the landing and About pages' words in the block editor,
where those pages hold only FireHouse blocks, and publish a reviewed synthesis on
FireHouse → Synthesis. The landing page can't be moved to the Trash while a
published or scheduled page is under it. A portrait added later is uploaded by an
administrator and attached to the About page, where the team card's picker offers
it; FireHouse Editors have no Media Library. Every
FireHouse address (projects, topics and pages under /firehouse/) gets the plugin's
frame: the theme's header and footer, with the
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
leaves, and a sort. With JavaScript, results update as you type and as each filter
changes; without it, the filters apply when the form is sent. Either way, an address
with the static site's parameters, such as /firehouse/projects/?q=smoke&region=NWCC, opens it already
filtered. The landing page's coverage map shows where the research comes from: a map
of the Geographic Area Coordination Centers, coloured by how many projects are filed
under each, a count for every region, and the projects filed under the one chosen.
With JavaScript, a choice on the map or in the list changes the page in place;
without it, the list works on its own and the map isn't shown.

= Third-party code and fonts =

The fonts in assets/fonts/, Archivo and Public Sans, are third-party, under the SIL
Open Font License 1.1, whose text is beside them. assets/fonts/SOURCES.json records
where each file came from.

The icons in assets/icons/ are Lucide's, under the ISC License, whose text is beside
them in LICENSE.txt. Lucide credits portions of them to Feather (MIT) there.

The coverage map's drawing in assets/map/ includes Leaflet, under the BSD 2-Clause
License, whose text is beside it in LICENSE.txt. The map's region boundaries are
simplified from the National Interagency Fire Center's National GACC Boundaries.

= What it stores =

The Import screen keeps nothing it doesn't import: the uploaded file isn't saved, and
the checked content waits briefly for the confirmation and is then cleared. The
synthesis screen keeps a checked synthesis for at most thirty minutes; an edit screen
keeps refused input for five minutes, and the settings screen for one; a refused
publish's notice waits an hour; each only for the person it's for. The plugin
stores only what the site publishes.

= Deactivating and deleting =

Deactivating the plugin keeps the FireHouse Editor role, which can then only sign in.
Deleting the plugin removes the role and the plugin's capabilities, and users who had
the role are left with no role on the site. The content stays.

An update that changes the role or its capabilities applies the role, and the
administrators' FireHouse capabilities, as the plugin defines them, so a change made
to either with a role editor doesn't last past it.

== Installation ==

The plugin is installed from its zip, the file WordPress takes at Plugins → Add New
Plugin → Upload Plugin. Each version's zip is attached to its release in the source
repository (https://github.com/NOAA-GSL/thefirehouse/releases). A zip of any commit on
one of the repository's branches is on that commit's "WordPress plugin" run in the
repository's Actions, under Artifacts; it hasn't been through GSL's security review,
so it belongs only on a local or test site. A pull request's run builds that pull
request's code, which may come from outside the repository: don't install its zip.

1. In the dashboard, go to Plugins → Add New Plugin, choose Upload Plugin, choose the
   zip, and choose Install Now.
2. Activate the plugin. The FireHouse menu appears in the dashboard.
3. As an administrator, go to FireHouse → Import and load the site's seed bundle: a
   dry run first, then the import. It creates the projects, the topic areas, the
   settings, and the landing and About pages.
4. Go to FireHouse → Settings and check the site name, the Submit a Finding button's
   label and address, and the navigation labels.
5. Give the people who keep the FireHouse up to date the FireHouse Editor role.

To update, upload the new version's zip the same way, and choose Replace current with
uploaded. The content and the settings stay.

== Frequently Asked Questions ==

= Does it work with any theme? =

It is built for, and tested with, GSL's classic theme. It draws each FireHouse page
between the theme's own header and footer (get_header() and get_footer()), so it needs
a classic theme; it isn't built for block themes. The FireHouse area keeps its own
look in any theme.

= Does it contact other sites? =

Only through the visitor's browser, for the coverage map's basemap tiles: see External
services. The plugin's own code on the server makes no request to another site, and
its fonts, icons and scripts are served from the plugin.

= Who can change what? =

Administrators can do everything, and only they can import. FireHouse Editors can edit
and publish projects and topic areas, change the words on the landing and About pages,
change the FireHouse settings, and publish a reviewed synthesis. They can't change the
site's other posts, pages or menus, add or remove pages, or use the Media Library.

= Does it change what researchers wrote? =

No. A project's survey answers are shown read-only on its edit screen, and printed on
its page as submitted. The FireHouse team's own fields (a summary, the publication
status, a geography note and a full-record link) are kept apart from them.

= Does it work without JavaScript? =

Yes. With JavaScript, the explorer's results follow the search as you type and the
coverage map is drawn. Without it, the filters apply when the form is sent, and the
map's list of regions works on its own.

= Where does the content come from? =

The first load comes from a seed bundle, built from the source repository's content
files, which an administrator loads on the Import screen. After that, FireHouse
Editors keep it up to date in the dashboard.

== Screenshots ==

1. The landing page: the hub's counts and the top needs in each of the four topic
   areas.
2. Where the research comes from: the coverage map, coloured by how many projects are
   filed under each Geographic Area Coordination Center, with a count for every
   region.
3. The project explorer, with its search, filters and sort.
4. A project's page, in its researchers' own words, with its facts at a glance.
5. A topic area's page: its top needs, and the published projects behind each.
6. A project's edit screen: the FireHouse team's fields above the researcher's
   answers, which are read-only.
7. FireHouse → Synthesis, where an editor checks a synthesis and publishes it once it
   has been reviewed.

== External services ==

The coverage map is the plugin's one outside service. Its basemap tiles come from
Esri (server.arcgisonline.com). On every view of the landing page with JavaScript (or
of any page the coverage map block is placed on), without the visitor scrolling to
the map or using it, the page's script has the browser connect to Esri as it starts
loading the map. That connection carries no request: it shows Esri the visitor's IP
address and nothing more, even if the map then fails to load. The browser then loads
the tiles straight from Esri when the map is drawn, and again as the visitor moves or
zooms the map, picks a region, or switches between light and dark. As with any image
a page loads from another site, each tile request gives Esri the visitor's
IP address, the browser's usual headers (such as its name and version and its
preferred languages), any cookies the browser keeps for Esri where it allows them,
and, through the tiles it asks for, the part of the map in view. The plugin sends no
Referer with them, so Esri isn't told which site or page the visitor is reading, and
the server never calls Esri. Esri's terms require its attribution, which is printed
under the map. Without the tiles, the map still draws the regions on a blank
background. A site with a Content Security Policy must allow images from
https://server.arcgisonline.com for the tiles to show; blocking them there doesn't
stop the early connection, which a Content Security Policy doesn't govern. The plugin
has no setting to change the tiles' host or turn them off. Another host means changing
the basemap in shared/coverage-map.json and rebuilding the map's script; no tiles at
all also means changing the map's drawing code and its early connection. The source
repository's README describes both.

The tiles are Esri's World Light Gray Canvas and World Dark Gray Canvas basemaps. Esri's
terms of use: https://www.esri.com/en-us/legal/terms/master-agreement. Esri's privacy
statement: https://www.esri.com/en-us/privacy/privacy-statements/privacy-statement.

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
  none: the hero with live counts, the four topic areas' top needs, where the research
  comes from, and the submit band. Where the research comes from is a map of the
  Geographic Area Coordination Centers, coloured by how many projects are filed under
  each, with a count for every region and the projects filed under the one chosen.
  With JavaScript, a choice on the map or in the list changes the page in place, and
  the address with it. Without it, the list works alone, and the map isn't shown. The
  map's basemap tiles are the plugin's one outside service: the visitor's browser
  loads them from Esri (see External services). A landing page imported before this
  doesn't have that section: add `<!-- wp:firehouse/coverage-map /-->` before its
  submit band in the block editor's Code editor.
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
  follow the search as you type and each filter as it changes, with no request, and
  the address follows; on a phone the filters fold behind a button; and each tile's
  Quick look button opens a preview of the project in a dialog.
* In the block editor, each FireHouse block shows what the page stores in it, apart
  from what FireHouse adds. Opening and saving a page keeps its FireHouse blocks, the
  ones they hold included.
* A project's edit screen: the FireHouse team's fields (its summary, publication
  status, geography note and full-record link), saved with the project and kept as
  typed, and the researcher's survey answers, read-only, exactly as submitted. A save
  with a field that's wrong, such as a link that isn't a web address, saves none of
  them, and the screen comes back with what was typed and each problem marked. The
  project's other changes, such as publishing it, are still saved.
* Restoring a project's revision puts back only the FireHouse team's summary,
  publication status and full-record link, never the researcher's answers or the
  project's IRB approval, which the revisions still record. An autosave of a project
  or topic takes no FireHouse field from the request, and WordPress's custom-fields
  tools can't change a FireHouse field on a project, a topic or a revision of either.
* A project without IRB approval can't be published, scheduled or made private
  through WordPress's editing screens: its edit screen says why, Quick Edit refuses
  with the reason, bulk edit keeps it a draft and says so, and the projects list marks
  it "No IRB approval". Saving a published project without approval makes it a draft.
  A project's address can't change once it has been published, since links to it may
  have been shared, unless another published project took it while it was in the
  trash. A draft can't take another project's address. WordPress's own
  title for a project is always the researcher's.
* A project's edit screen keeps its security token fresh along with WordPress's, as
  after signing in again, and if it goes stale anyway, says the editorial fields
  weren't saved rather than dropping them quietly.
* A topic's edit screen: the FireHouse team's words for the area (its short label,
  description and what it covers, one item a line), saved together, all or none, under
  its label, and the synthesis of its needs, read-only. A topic always keeps a label:
  an empty one leaves the old one in place, saves none of the other fields, and the
  screen says so.
* The project and topic screens refuse text with characters that can't be stored,
  rather than saving the other fields without it.
* Saving a project or topic no longer makes the editor its author, so no editor's name
  is published as its author in link previews or feeds.
* The landing and About pages keep an editor's words as typed: "R&D" no longer becomes
  "R&amp;D" when a FireHouse Editor saves the page.
* The landing and About pages' words are text fields in the block editor: headings,
  text, button and link text, the counts' labels, and each card's, step's and team
  member's words. A heading or button left empty is marked, and the editor holds the
  save until it's filled. The pages' layout is locked in the editor: their parts
  can't be added, moved or removed there.
* The About page's team cards can be added, removed and reordered in the block editor,
  each with a portrait chosen from the FireHouse portraits, and the mission's and each
  biography's paragraphs added and removed.
* The FireHouse pages hold only FireHouse blocks, for administrators too: the block
  editor offers no other block there, and a save that would add one (a paragraph, an
  image, Custom HTML) or text between the blocks is refused; the block editor says why.
  That includes a page created under the landing page or moved there. A page moved
  there brings the pages under it, including any in the Trash, so it can't be moved
  while one of them holds another block; the block editor names them. A page that
  already holds another block keeps it, and can still be published, unpublished or
  moved to the Trash, but no change to its content saves until that block is taken
  out.
* The FireHouse landing page can't be moved to the Trash while a published or
  scheduled page is anywhere under it, even under a page that's in the Trash, since
  that page would still open at an address under the trashed landing page. The Pages
  screens and the block editor name those pages; make them drafts or move them to the
  Trash first.
* The name the plugin prints is FireHouse: the plugin's name, the role, the admin
  menu, the block titles, and the pages' titles and words. A site that imported its
  content before keeps the old spelling in its settings and in the landing and About
  pages until they are changed.
* A project's Publish box says what Preview shows: the project as last saved or
  updated, not the changes on the screen; and a topic's says Preview Changes shows its label as typed
  and its other words as last updated. The topics list has no Quick Edit, bulk Edit
  or checkboxes.
* A project's or topic's Preview shows all its fields as stored. It had shown only
  the first item of each list (one author, one fire phase, one need) and no geography,
  with a PHP warning.
* FireHouse → Synthesis, for FireHouse Editors and administrators: download the
  NotebookLM source, paste the JSON NotebookLM gives, check it, and mark it reviewed
  to publish it to the topic pages and the landing page. Each topic keeps a revision
  of the synthesis before; restoring it is the undo.
* The plugin's zip, the file Plugins → Add New Plugin → Upload Plugin takes, is built
  by CI from each commit (npm run plugin:zip), without the tests, and Plugin Check
  reads what it holds. A version tag drafts a GitHub release with it. This readme has
  WordPress.org's sections: Installation, Frequently Asked Questions, Screenshots, and
  External services, which describes the coverage map's tiles and links Esri's terms
  and privacy statement. The plugin header's Update URI names GitHub, so WordPress never
  offers an update from WordPress.org for it, even if another plugin is listed there as
  firehouse.
* The FireHouse guide: FireHouse → Help, for FireHouse Editors and administrators, in
  plain language with screenshots, and on each FireHouse screen the part for that
  screen under the Help tab. Pages for administrators show only to them. It comes from
  Markdown pages in help/, so it describes the version installed.

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
