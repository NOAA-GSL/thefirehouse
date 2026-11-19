=== The FireHouse ===
Tags: research, social science, fire weather, wildfire, science communication
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.3.2
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
without it, the list works on its own and the map isn't shown. On a site given a
Google API key, Analyze results on FireHouse → Synthesis has a Gemini model draft the
synthesis, which goes through the same check and review as a pasted one (see External
services). Tools → Site Health gets four FireHouse checks.

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
publish's notice waits an hour; each only for the person it's for. Analyze results keeps
each model's latest draft, with its whole reply, the prompt it was given and the name
of whoever started it, until a newer draft from the same model replaces it; it never
stores the Google API key. Deleting the plugin removes those drafts. An administrator
may save the key on FireHouse → Settings: the project ID as typed and the API key
sealed with the site's salts, in an option of its own that no REST route shows and
whose API key no screen shows, until it's removed there or the plugin is deleted. The synthesis
prompt on FireHouse → Settings is stored only where it differs from the plugin's own,
and isn't published. Apart
from those and the survey form's answers, below, the plugin stores only what the site
publishes.

= The survey form =

The survey form at /firehouse/submit/ is off until an administrator ticks Accept
submissions under FireHouse → Settings; only an administrator can change it. While
it's on, each submission is kept as a draft project, unpublished until a FireHouse
Editor or an administrator publishes it; one without IRB approval never is. Three of
its answers are private: the submitter's email, their job title, and how they've
shared their results. They're kept apart from the project, in one protected field,
and shown only on the project's edit screen, to FireHouse Editors and the site's
administrators. They never appear on a page, in the REST API, in a revision or in
WordPress's export (Tools → Export). They stay until someone deletes the project;
when they should be deleted is a decision for NOAA's privacy review, and WordPress's
personal-data export and erase tools don't find them yet. Beyond what a submission
says, the plugin keeps no record of the people who visit or send the form: no IP
address, no cookie of its own.

Once a submission is saved, the server looks up the title of each paper given by its
DOI (see External services). It also tells the FireHouse team's mailbox
(gsl.firehouse@noaa.gov), through the site's own mail, that a submission has arrived.
That email is fixed text: a link to the draft, a link to every draft, and, when the
submitter answered that the project has no IRB approval, a line saying so. Nothing
the submitter typed is in it, private or not. A developer can send it elsewhere, or
stop it, with the `firehouse_survey_notify` filter; return "" to send none. The
submitter is sent nothing; the page they're taken to thanks them.

FireHouse Editors and administrators can reword each question, and the help under it,
under FireHouse → Settings. Only what differs from the plugin's own wording is stored,
so wording nobody changed follows the plugin's when it's updated. The choices, the
order of the questions, and the line on each private question saying who sees the
answer are fixed in the plugin.

= Deactivating and deleting =

Deactivating the plugin keeps the FireHouse Editor role, which can then only sign in.
Deleting the plugin removes the role and the plugin's capabilities, and users who had
the role are left with no role on the site, and Analyze results' drafts are removed.
The content stays, the survey form's draft projects and their private answers
included.

An update that changes the role or its capabilities, or something else the plugin
stores, applies the role, and the administrators' FireHouse capabilities, as the
plugin defines them, so a change made to either with a role editor doesn't last past
it.

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
   label and address, the navigation labels, and the survey form's questions. Leave
   Accept submissions off until the survey form may take submissions (see The survey
   form, above).
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

Three times: the visitor's browser loads the coverage map's basemap tiles from Esri;
the server looks up a paper's title by its DOI when a survey form submission gives
one; and, only on a site given a Google API key, the server sends the published
projects to Google's Gemini Enterprise Agent Platform when an editor chooses Analyze
results. See External services. When an administrator opens Tools → Site Health, the
plugin's checks also ask the DOI lookups' and Agent Platform's hosts (not Esri's) for
their front pages, and load the site's own FireHouse landing page. The plugin's code on the server
makes no other request to another site, and its fonts, icons and scripts are served from the plugin.

= Who can change what? =

Administrators can do everything, and only they can import or open the survey form.
FireHouse Editors can edit and publish projects and topic areas, change the words on
the landing and About pages, change the other FireHouse settings (the survey form's
questions and the synthesis prompt among them), and have a model draft a synthesis
and publish a reviewed one. They can't change the
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
Editors keep it up to date in the dashboard, and, once an administrator opens it,
each survey form submission arrives as a draft project for them to review.

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

The plugin uses three outside services: the coverage map's basemap tiles, which the
visitor's browser loads; DOI lookups, which the server makes for the survey form; and
Google's Gemini Enterprise Agent Platform, which the server asks for a synthesis, only
on a site given a key.

= The coverage map's tiles =

Its basemap tiles come from
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

= DOI lookups =

When the survey form saves a submission that gives a paper by its DOI (typed, in a
doi.org address, or in a Zenodo record's address), the server asks doi.org for that
paper's citation metadata, so the project's page can show its title, journal and
year. doi.org answers with a redirect to the metadata service of the agency that
registered the DOI, Crossref (api.crossref.org) or DataCite (data.crosscite.org), and
the server then asks that service itself. Each request carries the DOI alone, without
a pasted link's query or fragment; a request for citation metadata; and the plugin's
name as its user agent. It comes from the site's server, so these services see the
server's address, never the submitter's or a visitor's, and nothing else the
submitter typed. A redirect to any other host is refused, and a link without a DOI is
never fetched. The lookups run just after the submission is saved, in WordPress's
scheduled events, and have ten seconds for a submission. What they find is stored
only while the submission is still a draft. One that fails leaves the paper without
a title. A submission without IRB approval, which is never published,
isn't looked up. There is no setting to turn the lookups off.

doi.org: the DOI Foundation publishes no terms of use for looking up a DOI. Its
privacy policy, which says it logs each DOI looked up and the address asking:
https://www.doi.org/privacy-policy. Crossref: its REST API's documentation, which
sets out the conditions of use, that the metadata may be used for any purpose:
https://www.crossref.org/documentation/retrieve-metadata/rest-api/. Its privacy
policy: https://www.crossref.org/operations-and-sustainability/privacy/. DataCite: its
terms and conditions, which cover its website: https://datacite.org/terms-and-conditions/.
Its content negotiation documentation:
https://support.datacite.org/docs/doi-content-negotiation. Its privacy policy:
https://datacite.org/privacy-policy/.

= Google's Gemini Enterprise Agent Platform =

Formerly Vertex AI. Only on a site with a Google API key: a Google Cloud project ID and
an API key from that project, bound to a service account and restricted to Agent
Platform's API. An administrator saves them on FireHouse → Settings, the API
key sealed with the site's salts, or wp-config.php defines them as
FIREHOUSE_GOOGLE_PROJECT and FIREHOUSE_GOOGLE_API_KEY. Without one the plugin never
contacts it, and FireHouse → Synthesis offers only the paste. When a FireHouse Editor
or an administrator chooses Analyze results there, the server sends one request to
aiplatform.us.rep.googleapis.com, Agent Platform's endpoint for the United States
multi-region, for that project and the Gemini model they picked, with the API key in
its x-goog-api-key header. It carries every published project as
its page shows it, word for word (its title, ID and page address, the researchers'
names, organization, year, project type, methods, fire phases, regions, abstract,
takeaways, needs and recommendations, and publications), the four topic areas, and
the prompt on FireHouse → Settings. No unpublished submission, no private answer, no
earlier synthesis and nothing about visitors is sent. It runs in WordPress's scheduled
events, one at a time, and waits up to five minutes for the reply, which the site
keeps as that model's latest draft. The models are Google's own: Gemini
3.8 Flash, Gemini 4 Argon and Gemini 3.5 Flash-Lite.

Google processes each request in the United States: https://docs.cloud.google.com/gemini-enterprise-agent-platform/resources/data-residency.
Google won't use it to train or fine-tune any model without the customer's permission,
and may cache it for up to 24 hours in the same multi-region unless the project turns
caching off: https://docs.cloud.google.com/gemini-enterprise-agent-platform/resources/zero-data-retention.
If its classifiers flag a request as possible abuse, Google may log the prompt for up to
90 days, in the same multi-region, unless the customer's agreement exempts it:
https://docs.cloud.google.com/gemini-enterprise-agent-platform/models/abuse-monitoring.
Google Cloud's terms: https://cloud.google.com/terms, and its Service Specific Terms:
https://cloud.google.com/terms/service-terms. Its privacy notice:
https://cloud.google.com/terms/cloud-privacy-notice.

= Site Health checks =

When someone who may see Tools → Site Health opens it, the plugin checks that the
server can reach doi.org, api.crossref.org and data.crosscite.org, and
aiplatform.us.rep.googleapis.com on a site given a Google API key: one HEAD request to each host's
front page, carrying only the plugin's name as its user agent, no key and nothing
about the site's content or people. It also loads the site's own FireHouse landing
page, as a visitor would, to see whether a cache keeps it. Neither runs in WordPress's
weekly Site Health check, so they happen only while someone is looking. These hosts'
terms and privacy policies are above.

== Changelog ==

= 0.3.2 =
The version offered for GSL's security review, in place of 0.3.1: 0.3.1 and the change
below. Its GitHub release stays a draft until it has passed that review.
* Analyze results asks Google's Gemini Enterprise Agent Platform, formerly Vertex AI,
  in place of Cloudflare Workers AI; GSL's IT services issue the key from a GSL Google
  Cloud project. The models are Gemini 3.8 Flash, picked to start with, Gemini 4 Argon
  and Gemini 3.5 Flash-Lite, through Agent Platform's United States multi-region
  endpoint (aiplatform.us.rep.googleapis.com), where Google keeps the processing in the
  United States: no single US region offers a Gemini model that hasn't been retired.
  The key on FireHouse → Settings is a Google Cloud project ID and an API key, sealed
  as the Cloudflare token was, and sent in a header; in wp-config.php it's
  FIREHOUSE_GOOGLE_PROJECT and FIREHOUSE_GOOGLE_API_KEY. Site Health's checks name the
  new host and key. Updating removes the Workers AI key 0.3.1 kept, and applies the
  FireHouse Editor role again; drafts from Workers AI's models aren't shown any more.
  Delete the Workers AI token itself in Cloudflare's dashboard, and any
  FIREHOUSE_WORKERS_AI_ constants: the plugin can't.

= 0.3.1 =
0.3.0 and the changes below, prepared for GSL's security review in place of 0.3.0 and
replaced by 0.3.2, for the move to Google's Agent Platform, before it was sent; its
GitHub release stays an unpublished draft.
* The Workers AI key can be saved on FireHouse → Settings, by an administrator, for a
  site whose administrators can't edit wp-config.php. The token is sealed with the
  site's AUTH_KEY and AUTH_SALT and never shown again; a key saved there comes before
  one in wp-config.php. It isn't saved on a site whose wp-config.php doesn't set its
  own AUTH_KEY and AUTH_SALT, and Remove the key saved here, or deleting the plugin,
  removes it. Site Health says where the key comes from, and when a saved one can't be
  read. The guide's settings and installing pages say how.
* The tests name the warning Plugin Check gives for their Workers AI key constants
  (VariableConstantNameFound), so Plugin Check on a copy with tests/, as the local site
  has, reports only the Update URI header.

= 0.3.0 =
Everything built since 0.2.0, prepared for GSL's security review and replaced by 0.3.1
before it was sent; its GitHub release stays an unpublished draft.
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
  map's basemap tiles are an outside service: the visitor's browser loads them from
  Esri (see External services). A landing page imported before this
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
* The FireHouse survey form at /firehouse/submit/, off until an administrator ticks
  Accept submissions under FireHouse → Settings: each submission becomes a draft
  project, with the submitter's email, job title and how they shared their results kept
  apart, for FireHouse Editors and administrators only, and left out of WordPress's
  export. It takes a form sent only to its own address from the site's own pages.
* The landing page's "Where these needs come from" in Stephanie and Emily's 11
  November wording. Under Executive Order 14434 the site says "Super Intelligence
  (SI)" for AI: the note on the landing and topic pages says "Drafted with Super
  Intelligence (SI)", "Where these needs come from" and the topic pages say what the
  term means, the project pages say the needs are drafted with it, and the survey
  form's notice says it too. None of the site's own notes names the tool; the survey
  form's notice keeps the Google Form's naming of it. Their new landing heading and About
  words reach a site with its next first import; a site imported before keeps its
  words until an editor changes them in the block editor.
* A survey form submission's papers given by DOI get their title, journal and year
  from doi.org just after it's saved, as the import gets them, and the team mailbox is told of each
  submission with a link to its draft and nothing the submitter typed (see External
  services and The survey form).
* The survey form's questions can be reworded, with the help under each, under
  FireHouse → Settings; a private question's line saying who sees the answer can't be.
  The FireHouse guide's Settings page says how, and the FireHouse menu's screenshot
  shows Help.
* The FireHouse guide has a page on reviewing a survey form submission, on the
  projects list's and a project's Help tabs. A submission's From the survey box says
  its answers can't be changed on the site, where it used to point to the Import screen,
  which never changes a submission, and a "No" to the IRB question shows as no approval
  or exemption rather than "Not recorded".
* Analyze results on FireHouse → Synthesis, on a site with a Workers AI key in
  wp-config.php: a model picked from Meta's Llama 4 Scout, OpenAI's gpt-oss-120b,
  Google's Gemma 4 26B and Thinking Machines' Inkling 2 Small drafts a synthesis in
  the background from the published projects and the prompt under FireHouse →
  Settings, which can be changed there. Each model's latest draft is kept, to compare;
  checking one puts it through the screen's check and Mark reviewed, with the model
  under Made with. The guide's synthesis and settings pages say how (see External
  services).
* Analyze results asks Gemma 4 26B not to think, since its thinking used up its length
  limit, and Inkling 2 Small to reason at "low"; a request Cloudflare stops waiting on
  says the model may be busy.
* Three FireHouse checks on Tools → Site Health: whether the server reaches the
  outside services the plugin uses, whether search engines are kept out of a site
  that isn't production, and whether a cache keeps FireHouse's pages (see External
  services). The guide's installing page says what to do about each.
* The synthesis check gives the nearest page slug for a name that isn't a project's,
  such as one a model shortened, stopped partway or made from the project's title,
  for the reviewer to judge, and refuses a name holding a character that doesn't show.
* Analyze results picks Inkling 2 Small to start with: Llama 4 Scout, picked before,
  took all the time Cloudflare gives a reply with the plugin's prompt, and ran out of
  it with the team's longer one. Inkling 2 Small needs Cloudflare's Workers Paid plan.
* A model's whole reply, under a draft's check, is labelled as giving the entries
  behind each need only if the model gave them, since most don't; the guide says to
  read the projects a need names then. The guide also says the draft's check compares
  only the number of projects, that an editor tries a run that didn't finish again
  before telling an administrator, and when the caching check is critical. Its
  installing page says how a site gets a Workers AI key, and where to look when a run
  never starts. External services says Inkling 2 Small's hosting is
  still to be confirmed with Cloudflare.
* Analyze results, from its Section 508 review: a draft that read a different number of
  projects says so in the summary at the top, which takes the focus, on every check of
  it, where it used to say so after the summary and only on the first; the person who
  starts a run is told so at the top when the screen comes back, and the page's title
  starts "Drafting:" while a run goes; the Check buttons wrap on a narrow screen; a
  failed draft's heading says so; each draft's disclosures are named for its model; a
  reply's warning about a hidden character describes its box; each button has its own
  id. Settings names a hidden character in the synthesis prompt, with its line, and
  says how long a prompt that's too long is.

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
