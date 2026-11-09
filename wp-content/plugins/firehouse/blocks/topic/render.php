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

$firehouse_topic = get_queried_object();
if ( is_singular( Post_Types::TOPIC ) && $firehouse_topic instanceof \WP_Post && Frame::is_firehouse_request() ) {
	echo Topic_Page::for_post( $firehouse_topic ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Topic_Page escapes everything it prints.
}
