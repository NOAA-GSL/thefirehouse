<?php
/**
 * Renders a topic area's page, `firehouse/topic`. Topic_Page builds the markup.
 *
 * The frame places it on a topic's address. Typed into a post anywhere else, it
 * renders nothing.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$firehouse_topic = Topic_Page::current();
if ( null !== $firehouse_topic ) {
	echo Topic_Page::for_post( $firehouse_topic ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Topic_Page escapes everything it prints.
}
