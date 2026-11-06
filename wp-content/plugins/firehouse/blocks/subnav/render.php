<?php
/**
 * Renders the Firehouse bar, `firehouse/subnav`. Subnav builds the markup.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo Subnav::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Subnav escapes everything it prints.
