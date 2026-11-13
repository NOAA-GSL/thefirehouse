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
 *
 * Registration runs on every request on the site, so WordPress takes the blocks'
 * metadata from blocks/blocks-manifest.php, one generated array that opcache keeps
 * compiled, instead of reading and decoding each block.json (Phase 4's weight). It
 * still checks each block.json is there. Without the manifest WordPress refuses the
 * collection, flags it as a developer error, and reads the files, as before.
 */
final class Blocks {

	/**
	 * The blocks, by folder in blocks/.
	 */
	public const NAMES = array( 'about-audiences', 'about-cta', 'about-intro', 'about-mission', 'about-process', 'about-sections', 'about-team', 'hero', 'not-found', 'project', 'stats', 'submit-band', 'subnav', 'top-needs', 'topic' );

	/**
	 * Hooks the registration.
	 */
	public static function hook(): void {
		add_action( 'init', array( self::class, 'register' ) );
	}

	/**
	 * Registers the blocks, with their metadata from the manifest.
	 */
	public static function register(): void {
		$blocks = dirname( __DIR__ ) . '/blocks';
		wp_register_block_metadata_collection( $blocks, $blocks . '/blocks-manifest.php' );
		foreach ( self::NAMES as $name ) {
			register_block_type( $blocks . '/' . $name );
		}
	}
}
