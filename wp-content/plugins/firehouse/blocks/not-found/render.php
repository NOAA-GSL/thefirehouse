<?php
/**
 * Renders the Firehouse not-found page, `firehouse/not-found`. Not_Found builds the
 * markup.
 *
 * The frame places it on a missing address under /firehouse/. Typed into a post
 * anywhere else, it renders nothing.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( is_404() && Frame::is_firehouse_request() ) {
	echo Not_Found::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Not_Found escapes everything it prints.
}
