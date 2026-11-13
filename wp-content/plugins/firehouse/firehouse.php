<?php
/**
 * Plugin Name:       The FireHouse
 * Plugin URI:        https://github.com/NOAA-GSL/thefirehouse
 * Description:       Public-facing hub for NOAA GSL's fire weather social science needs and findings.
 * Version:           0.2.0
 * Requires at least: 6.7
 * Requires PHP:      8.1
 * Author:            NOAA Global Systems Laboratory
 * Author URI:        https://gsl.noaa.gov
 * License:           Apache-2.0
 * License URI:       https://www.apache.org/licenses/LICENSE-2.0
 * Text Domain:       firehouse
 *
 * Registers the project and topic post types and their fields at the Firehouse
 * addresses, the FireHouse Editor role, and the Firehouse settings and Import
 * screens; keeps WordPress's `year` query variable off Firehouse pages, and keeps
 * projects a visitor may not see off the public site (Visibility); and puts every
 * Firehouse page (the landing, About, project, topic and not-found pages, and the
 * explorer's placeholder until Phase 5) in the theme's header and footer, with its own stylesheets, the Firehouse bar, and its title and link-preview
 * tags (templates/frame.php, blocks/, Head). domain/ holds the content
 * logic, plain PHP that adds no hooks; includes/ holds what does. The comment above
 * the hook calls below lists where the plugin acts beyond the Firehouse pages.
 * WORDPRESS-PLAN.md in the repository describes what this plugin becomes, phase by
 * phase.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin version. Kept in step with the header above and readme.txt.
 */
const VERSION = '0.2.0';

require_once __DIR__ . '/domain/class-content-error.php';
require_once __DIR__ . '/domain/class-js.php';
require_once __DIR__ . '/domain/class-json-object.php';
require_once __DIR__ . '/domain/class-json.php';
require_once __DIR__ . '/domain/class-vocabulary.php';
require_once __DIR__ . '/domain/class-format.php';
require_once __DIR__ . '/domain/class-derive.php';
require_once __DIR__ . '/domain/class-search.php';
require_once __DIR__ . '/domain/class-normalize.php';
require_once __DIR__ . '/domain/class-email-shape.php';

require_once __DIR__ . '/includes/class-meta.php';
require_once __DIR__ . '/includes/class-post-types.php';
require_once __DIR__ . '/includes/class-query.php';
require_once __DIR__ . '/includes/class-capabilities.php';
require_once __DIR__ . '/includes/class-installer.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-store.php';
require_once __DIR__ . '/includes/class-visibility.php';
require_once __DIR__ . '/includes/class-seed-bundle.php';
require_once __DIR__ . '/includes/class-importer.php';
require_once __DIR__ . '/includes/class-import-screen.php';
require_once __DIR__ . '/includes/class-frame.php';
require_once __DIR__ . '/includes/class-icons.php';
require_once __DIR__ . '/includes/class-markup.php';
require_once __DIR__ . '/includes/class-not-found.php';
require_once __DIR__ . '/includes/class-page-blocks.php';
require_once __DIR__ . '/includes/class-landing-page.php';
require_once __DIR__ . '/includes/class-about-page.php';
require_once __DIR__ . '/includes/class-project-page.php';
require_once __DIR__ . '/includes/class-topic-page.php';
require_once __DIR__ . '/includes/class-verbatim.php';
require_once __DIR__ . '/includes/class-subnav.php';
require_once __DIR__ . '/includes/class-blocks.php';
require_once __DIR__ . '/includes/class-head.php';

/*
 * Where the plugin acts beyond the Firehouse pages and its own admin screens:
 *
 * On every request of any kind (public pages, the admin, REST, cron, WP-CLI), signed
 * in or not, when WordPress starts up (init):
 *
 * - Post_Types::register registers the project and topic post types, their fields, and
 *   their addresses in WordPress's table of addresses (the rewrite rules). Both types
 *   are public, so they're in WordPress's sitemap. They're excluded from search, so
 *   the site's search box, any query on the site for posts of 'any' type, and
 *   WordPress's guess at a missing address all leave them out. A search that names
 *   one of the types still finds them.
 *   Their query variables (fh_project, fh_topic) are read on any address. The fields
 *   are read from shared/*.schema.json; if one is missing or malformed, this throws,
 *   and every request on the site fails until the plugin's files are fixed or its
 *   folder renamed.
 * - Blocks::register registers the Firehouse blocks. They're kept out of the block
 *   inserter and declare no stylesheet. Typed into content outside the Firehouse, they
 *   print nothing, though the Firehouse bar's and the project page's script modules
 *   still load there. Their metadata comes from blocks/blocks-manifest.php, a
 *   generated PHP file WordPress includes; if it's damaged, this throws, and every
 *   request on the site fails. A missing one is harmless: WordPress then reads each
 *   block's block.json.
 * - Installer::maybe_upgrade compares the stored revision with Installer::REVISION.
 *   When they differ, on whichever request comes first, it replaces the Firehouse
 *   Editor role, adds and removes Firehouse capabilities on the administrator role (no
 *   other role), and deletes the stored rewrite rules, which WordPress then rebuilds
 *   for the whole site. Activating the plugin does the same. Deactivating it deletes
 *   the rules and the stored revision and keeps the role, whose users can then only
 *   sign in; uninstall.php removes the role and the Firehouse capabilities.
 *
 * Wherever WordPress reads a request's address (public pages, feeds, REST, and the
 * admin's post and media lists):
 *
 * - Query::request (the `request` filter) drops a project or topic query variable that
 *   isn't a string (an array, say) or is "" or "0".
 *
 * On every query that returns posts (not IDs only) and doesn't suppress filters,
 * outside the admin's own screens: public pages, feeds, the sitemap, REST, admin-ajax
 * and cron, but not WP-CLI unless it's running cron:
 *
 * - Visibility::posts (the `posts_results` filter) looks at what the query found and
 *   drops a project a visitor may not see: one that isn't published, has a password,
 *   or lacks IRB approval. Someone who may edit it still sees it on its own page, and
 *   nowhere else. A query that found no project is passed through untouched. One that
 *   found projects loads the published ones' fields in one query if they aren't
 *   loaded yet, which for WordPress's sitemap, which never loads them, is one query
 *   more. A list can come back short, and its `found_posts` still counts what was
 *   dropped.
 *
 * Wherever WordPress builds a redirect, looks up an embed or builds a menu (public
 * pages, feeds, REST's oEmbed endpoint, admin-ajax, and the admin's Menus screen):
 *
 * - Visibility::redirect (the `redirect_canonical` filter) refuses any redirect on a
 *   request whose `?p=`, `?page_id=` or `?attachment_id=` is a project a visitor may
 *   not see, or one of its revisions, which covers the redirects WordPress builds
 *   from that ID on a missing address or a feed. Visibility::post_id does the
 *   same for oEmbed (`oembed_request_post_id`) and a changed slug's redirect
 *   (`old_slug_redirect_post_id`), whoever asks, and Visibility::menu_item marks a
 *   menu item invalid when it links to one (`wp_setup_nav_menu_item`).
 *
 * On every public page:
 *
 * - Visibility::head_links removes WordPress's previous and next links from a
 *   project's head, and from no other page.
 * - Frame::guess_404 (the `pre_redirect_guess_404_permalink` filter) runs on every
 *   missing address and changes WordPress's answer only under /firehouse/, where it
 *   makes the Firehouse's own guess.
 * - Frame::is_firehouse_request decides, on template_redirect, template_include,
 *   wp_enqueue_scripts, wp_preload_resources and body_class, whether the request is
 *   for a Firehouse page. Only for one does the plugin replace the template, add its
 *   stylesheets, its font preload and its body class, and turn off WordPress's text
 *   rewrites (Verbatim). Anywhere else, these hooks change nothing.
 * - Head::document_title (the `pre_get_document_title` filter) and Head::tags (on
 *   wp_head) give a Firehouse page its title, description and link-preview tags. They
 *   change nothing on any other page, nor anywhere while an SEO plugin is active or
 *   the `firehouse_head_tags` filter says no.
 *
 * In every block editor on the site (for any post or page, the site editor and the
 * widgets), on enqueue_block_editor_assets:
 *
 * - Blocks::enqueue_editor loads assets/js/block-editor.js: it gives the Firehouse
 *   blocks a read-only view there and their save, makes no requests, and adds nothing
 *   to the inserter.
 *
 * From the permission and field filters (Capabilities, Meta), which run on every
 * capability check, save, sign-in and query:
 *
 * - Anyone holding firehouse_edit_pages (the FireHouse Editor role holds it) is lent
 *   four of WordPress's page capabilities: edit_pages, edit_others_pages,
 *   edit_published_pages and publish_pages. WordPress's checks on a single page are
 *   narrowed to the Firehouse pages, but code that checks those four capabilities
 *   directly sees them granted.
 * - On any page on the site, a signed-in user who may not delete it can't move it into
 *   or out of the trash, by any route.
 * - Every deletion for good is checked (the `pre_delete_post` filter): only the
 *   Firehouse landing page is refused, while pages outside the trash are under it, and
 *   only on the Pages screens does the refusal stop the request to say why. Any other
 *   deletion is WordPress's to decide.
 * - A Firehouse field is checked whenever it's added or updated through add_post_meta
 *   or update_post_meta, meta_input included. WordPress's by-ID route for custom
 *   fields isn't checked; the fields' edit_post_meta check refuses it to everyone but
 *   a multisite super admin. Deletes aren't checked. Other meta is left alone.
 * - Every sign-in is checked, to refuse XML-RPC to anyone with the FireHouse Editor
 *   role. Every query is checked too, but only the admin Pages list of a user whose
 *   page capabilities are lent is narrowed.
 *
 * Beyond that, these change only Firehouse content and what Firehouse editors may do.
 * Settings and Import_Screen hook only the admin.
 */
Post_Types::hook();
Meta::hook();
Query::hook();
Capabilities::hook();
Installer::hook( __FILE__ );
Settings::hook();
Import_Screen::hook();
Frame::hook();
Visibility::hook();
Blocks::hook();
Verbatim::hook();
Head::hook();
