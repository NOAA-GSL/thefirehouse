<?php
/**
 * PHPUnit bootstrap. The tests run without WordPress; Brain Monkey stands in for
 * the WordPress functions a test needs.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

use Brain\Monkey;
use Brain\Monkey\Functions;

require dirname( __DIR__, 4 ) . '/vendor/autoload.php';

// The plugin's files exit early without it.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// firehouse.php adds its hooks as it loads. Brain Monkey takes them, and forgets them
// before the first test, which hooks what it tests itself.
Monkey\setUp();
Functions\when( 'register_activation_hook' )->justReturn();
Functions\when( 'register_deactivation_hook' )->justReturn();
require_once dirname( __DIR__ ) . '/firehouse.php';
Monkey\tearDown();

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/Cases.php';
require_once __DIR__ . '/DomainTestCase.php';
require_once __DIR__ . '/WordPressTestCase.php';
