<?php
/**
 * Renders the project explorer, `firehouse/explorer`. Explorer_Page builds the markup.
 *
 * The frame places it on the project archive's address. At any other address, it
 * renders nothing.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( is_post_type_archive( Post_Types::PROJECT ) && Frame::is_firehouse_request() ) {
	echo Explorer_Page::for_request(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Explorer_Page escapes everything it prints.
}
