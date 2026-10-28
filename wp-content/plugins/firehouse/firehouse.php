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
 * Registers nothing yet. WORDPRESS-PLAN.md in the repository describes what this
 * plugin becomes, phase by phase.
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
