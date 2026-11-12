<?php
/**
 * Renders the hero, `firehouse/hero`, on the landing page. Landing_Page builds it; $content is the counts, its inner block, already rendered.
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
	echo Landing_Page::hero( $attributes, $content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Landing_Page escapes everything it prints.
}
