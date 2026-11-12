<?php
/**
 * Renders the About page's process, `firehouse/about-process`. About_Page builds it.
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
	echo About_Page::process( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- About_Page escapes everything it prints.
}
