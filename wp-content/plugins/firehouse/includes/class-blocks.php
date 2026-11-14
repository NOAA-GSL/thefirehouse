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
 * Registers each block in blocks/, described by its block.json, by way of a generated
 * manifest. Each renders on the server (render.php); one that is interactive has a
 * view script module for the Interactivity API (WORDPRESS-PLAN.md, Blocks).
 *
 * Registration runs on every request on the site, so WordPress takes the blocks'
 * metadata from blocks/blocks-manifest.php, one generated array that opcache keeps
 * compiled, instead of reading and decoding each block.json (Phase 4's weight). It
 * still checks each block's folder and block.json are there and resolves their paths,
 * and needs each block.json to find the block's render.php and to translate its
 * title. Without the manifest WordPress refuses the collection, flags it as a developer
 * error, and reads the files, as before; a damaged one stops every request, like any
 * damaged PHP file in the plugin.
 */
final class Blocks {

	/**
	 * The blocks, by folder in blocks/.
	 */
	public const NAMES = array( 'about-audiences', 'about-cta', 'about-intro', 'about-mission', 'about-process', 'about-sections', 'about-team', 'explorer', 'hero', 'not-found', 'project', 'stats', 'submit-band', 'subnav', 'top-needs', 'topic' );

	/**
	 * The handle of the script that shows the blocks in the block editor.
	 */
	public const EDITOR_SCRIPT = 'firehouse-block-editor';

	/**
	 * Hooks the registration, and the blocks' script for the block editor.
	 */
	public static function hook(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_editor' ) );
	}

	/**
	 * Loads assets/js/block-editor.js in every block editor on the site, with each
	 * block's name and the attributes its block.json declares, from the manifest. It
	 * shows each block read-only, listing what the page stores in it, until Phase 6 lets
	 * editors change that (WORDPRESS-PLAN.md), and adds nothing to the inserter. Plain
	 * JavaScript with no build step: its dependencies are WordPress's own packages, and
	 * it is versioned by the plugin's version and the file's modified time, as the
	 * stylesheets are.
	 */
	public static function enqueue_editor(): void {
		$root = dirname( __DIR__ );
		wp_enqueue_script(
			self::EDITOR_SCRIPT,
			plugins_url( 'assets/js/block-editor.js', $root . '/firehouse.php' ),
			array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ),
			VERSION . '-' . (int) filemtime( $root . '/assets/js/block-editor.js' ),
			false
		);
		$manifest = include $root . '/blocks/blocks-manifest.php';
		$blocks   = array();
		foreach ( self::NAMES as $name ) {
			$attributes                     = is_array( $manifest ) ? ( $manifest[ $name ]['attributes'] ?? array() ) : array();
			$blocks[ 'firehouse/' . $name ] = array_keys( is_array( $attributes ) ? $attributes : array() );
		}
		wp_add_inline_script( self::EDITOR_SCRIPT, 'window.firehouseBlocks = ' . wp_json_encode( $blocks ) . ';', 'before' );
		wp_set_script_translations( self::EDITOR_SCRIPT, 'firehouse' );
	}

	/**
	 * Registers the blocks, with their metadata from the manifest, and the site's search
	 * as a script module, which the explorer's view script imports by name
	 * (blocks/explorer/view.asset.php). WordPress loads the module only on a page whose
	 * script imports it.
	 */
	public static function register(): void {
		$root   = dirname( __DIR__ );
		$blocks = $root . '/blocks';
		wp_register_script_module(
			Explorer_Page::SEARCH_MODULE,
			plugins_url( 'assets/search/search.js', $root . '/firehouse.php' ),
			array(),
			VERSION . '-' . (int) filemtime( $root . '/assets/search/search.js' )
		);
		wp_register_block_metadata_collection( $blocks, $blocks . '/blocks-manifest.php' );
		foreach ( self::NAMES as $name ) {
			register_block_type( $blocks . '/' . $name );
		}
	}
}
