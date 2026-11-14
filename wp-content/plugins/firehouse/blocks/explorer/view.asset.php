<?php
/**
 * What the explorer's view script needs, for WordPress's script modules: the
 * Interactivity API, and the site's search (Explorer_Page::SEARCH_MODULE, which
 * Blocks::register() registers), which WordPress maps for its imports. Written by hand,
 * since view.js has no build step. The version is the file's modified time, so a
 * changed script is never served from a stale cache.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'dependencies' => array( '@wordpress/interactivity', '@firehouse/search' ),
	'version'      => (string) filemtime( __DIR__ . '/view.js' ),
);
