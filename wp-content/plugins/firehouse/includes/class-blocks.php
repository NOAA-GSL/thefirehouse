<?php
/**
 * The plugin's blocks.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers each block in blocks/ from its block.json. Each renders on the server
 * (render.php); one that is interactive has a view script module for the Interactivity
 * API (WORDPRESS-PLAN.md, Blocks).
 */
final class Blocks {

	/**
	 * The blocks, by folder in blocks/.
	 */
	public const NAMES = array( 'not-found', 'subnav', 'topic' );

	/**
	 * Hooks the registration.
	 */
	public static function hook(): void {
		add_action( 'init', array( self::class, 'register' ) );
	}

	/**
	 * Registers the blocks.
	 */
	public static function register(): void {
		foreach ( self::NAMES as $name ) {
			register_block_type( dirname( __DIR__ ) . '/blocks/' . $name );
		}
	}
}
