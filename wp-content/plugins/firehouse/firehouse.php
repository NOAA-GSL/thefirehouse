<?php
/**
 * Plugin Name:       The Firehouse
 * Plugin URI:        https://github.com/NOAA-GSL/thefirehouse
 * Description:       Public-facing hub for NOAA GSL's fire weather social science needs and findings.
 * Version:           0.1.0
 * Requires at least: 6.7
 * Requires PHP:      8.1
 * Author:            NOAA Global Systems Laboratory
 * Author URI:        https://gsl.noaa.gov
 * License:           Apache-2.0
 * License URI:       https://www.apache.org/licenses/LICENSE-2.0
 * Text Domain:       firehouse
 *
 * Registers nothing yet: it loads the domain logic in domain/, plain PHP that adds no
 * hooks. WORDPRESS-PLAN.md in the repository describes what this plugin becomes,
 * phase by phase.
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
const VERSION = '0.1.0';

require_once __DIR__ . '/domain/class-content-error.php';
require_once __DIR__ . '/domain/class-js.php';
require_once __DIR__ . '/domain/class-vocabulary.php';
require_once __DIR__ . '/domain/class-format.php';
require_once __DIR__ . '/domain/class-derive.php';
require_once __DIR__ . '/domain/class-normalize.php';
