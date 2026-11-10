<?php
/**
 * Renders the top needs, `firehouse/top-needs`, on the landing page. Landing_Page builds it.
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
	echo Landing_Page::top_needs( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Landing_Page escapes everything it prints.
}
