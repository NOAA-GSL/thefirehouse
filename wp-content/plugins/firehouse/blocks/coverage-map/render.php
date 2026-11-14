<?php
/**
 * Renders the coverage map, `firehouse/coverage-map`, on the landing page. Coverage_Map builds it.
 *
 * Typed into a post outside the Firehouse, it renders nothing.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( Frame::is_firehouse_request() ) {
	echo Coverage_Map::for_request(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Coverage_Map escapes everything it prints.
}
