<?php
/**
 * Renders a project's page, `firehouse/project`. Project_Page builds the markup.
 *
 * The frame places it on a project's address. Typed into a post anywhere else, it
 * renders nothing.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$firehouse_project = get_queried_object();
if ( is_singular( Post_Types::PROJECT ) && $firehouse_project instanceof \WP_Post && Frame::is_firehouse_request() ) {
	echo Project_Page::for_post( $firehouse_project ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Project_Page escapes everything it prints.
}
