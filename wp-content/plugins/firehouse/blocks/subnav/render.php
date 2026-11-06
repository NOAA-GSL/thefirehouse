<?php
/**
 * Renders the Firehouse bar, `firehouse/subnav`. Subnav builds the markup.
 *
 * The frame places the bar once on each Firehouse page. The block stays out of the
 * inserter, but anyone who can write a post can still type its comment, so it renders
 * nothing anywhere but a Firehouse page.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( Frame::is_firehouse_request() ) {
	echo Subnav::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Subnav escapes everything it prints.
}
