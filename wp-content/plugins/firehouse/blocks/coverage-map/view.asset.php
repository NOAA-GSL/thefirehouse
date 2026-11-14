<?php
/**
 * What the coverage map's view script needs, for WordPress's script modules: the
 * Interactivity API, and the map's drawing (Coverage_Map::MAP_MODULE, which
 * Blocks::register() registers), which the script imports only once the page is up,
 * so WordPress maps it without loading it first. Written by hand, since view.js has no
 * build step. The version is the file's modified time, so a changed script is never
 * served from a stale cache.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'dependencies' => array(
		'@wordpress/interactivity',
		array(
			'id'     => '@firehouse/coverage-map',
			'import' => 'dynamic',
		),
	),
	'version'      => (string) filemtime( __DIR__ . '/view.js' ),
);
