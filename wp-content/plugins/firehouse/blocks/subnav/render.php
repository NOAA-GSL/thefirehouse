<?php
/**
 * Renders the Firehouse bar, `firehouse/subnav`. Subnav builds the markup.
 *
 * The frame places the bar once on each Firehouse page (Subnav::place()), and the
 * block prints it only then. It stays out of the inserter, but anyone who can write a
 * post can still type its comment, so a copy in any content, a Firehouse page's
 * included, prints nothing.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( Subnav::placing() && Frame::is_firehouse_request() ) {
	echo Subnav::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Subnav escapes everything it prints.
}
