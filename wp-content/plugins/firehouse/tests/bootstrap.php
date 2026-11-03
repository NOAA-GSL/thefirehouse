<?php
/**
 * PHPUnit bootstrap. The tests run without WordPress; Brain Monkey stands in for
 * the WordPress functions a test needs.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

require dirname( __DIR__, 4 ) . '/vendor/autoload.php';

// The plugin's files exit early without it.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once dirname( __DIR__ ) . '/firehouse.php';
require_once __DIR__ . '/Cases.php';
require_once __DIR__ . '/DomainTestCase.php';
