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
 * logic, plain PHP that adds no hooks; includes/ holds what does. WORDPRESS-PLAN.md
 * in the repository describes what this plugin becomes, phase by phase.
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
require_once __DIR__ . '/includes/class-subnav.php';
require_once __DIR__ . '/includes/class-blocks.php';

Post_Types::hook();
Meta::hook();
Query::hook();
Capabilities::hook();
Installer::hook( __FILE__ );
Settings::hook();
Import_Screen::hook();
Frame::hook();
Blocks::hook();
