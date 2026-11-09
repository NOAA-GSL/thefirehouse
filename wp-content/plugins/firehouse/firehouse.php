<?php
/**
 * Plugin Name:       The Firehouse
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
 * addresses, the Firehouse Editor role, and the Firehouse settings and Import
 * screens, keeps WordPress's `year` query variable off Firehouse pages, and puts
 * every Firehouse page in the theme's header and footer with its own stylesheets and
 * the Firehouse bar (templates/frame.php, blocks/subnav/). domain/ holds the content
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
require_once __DIR__ . '/domain/class-normalize.php';
require_once __DIR__ . '/domain/class-email-shape.php';

require_once __DIR__ . '/includes/class-meta.php';
require_once __DIR__ . '/includes/class-post-types.php';
require_once __DIR__ . '/includes/class-query.php';
require_once __DIR__ . '/includes/class-capabilities.php';
require_once __DIR__ . '/includes/class-installer.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-store.php';
require_once __DIR__ . '/includes/class-seed-bundle.php';
require_once __DIR__ . '/includes/class-importer.php';
require_once __DIR__ . '/includes/class-import-screen.php';
require_once __DIR__ . '/includes/class-frame.php';
require_once __DIR__ . '/includes/class-icons.php';
require_once __DIR__ . '/includes/class-markup.php';
require_once __DIR__ . '/includes/class-not-found.php';
require_once __DIR__ . '/includes/class-verbatim.php';
require_once __DIR__ . '/includes/class-subnav.php';
require_once __DIR__ . '/includes/class-blocks.php';

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
 *   print nothing, though the Firehouse bar's script module still loads there.
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
 * On every public page:
 *
 * - Frame::guess_404 (the `pre_redirect_guess_404_permalink` filter) runs on every
 *   missing address and changes WordPress's answer only under /firehouse/, where it
 *   makes the Firehouse's own guess.
 * - Frame::is_firehouse_request decides, on template_redirect, template_include,
 *   wp_enqueue_scripts and body_class, whether the request is for a Firehouse page.
 *   Only for one does the plugin replace the template, add its stylesheets and body
 *   class, and turn off WordPress's text rewrites (Verbatim). Anywhere else, these
 *   hooks change nothing.
 *
 * From the permission and field filters (Capabilities, Meta), which run on every
 * capability check, save, sign-in and query:
 *
 * - Anyone holding firehouse_edit_pages (the Firehouse Editor role holds it) is lent
 *   four of WordPress's page capabilities: edit_pages, edit_others_pages,
 *   edit_published_pages and publish_pages. WordPress's checks on a single page are
 *   narrowed to the Firehouse pages, but code that checks those four capabilities
 *   directly sees them granted.
 * - On any page on the site, a signed-in user who may not delete it can't move it into
 *   or out of the trash, by any route.
 * - A Firehouse field is checked whenever it's added or updated through add_post_meta
 *   or update_post_meta, meta_input included. WordPress's by-ID route for custom
 *   fields isn't checked; the fields' edit_post_meta check refuses it to everyone but
 *   a multisite super admin. Deletes aren't checked. Other meta is left alone.
 * - Every sign-in is checked, to refuse XML-RPC to anyone with the Firehouse Editor
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
Blocks::hook();
Verbatim::hook();
