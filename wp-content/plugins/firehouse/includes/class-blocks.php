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
	public const NAMES = array( 'about-audiences', 'about-cta', 'about-intro', 'about-mission', 'about-process', 'about-sections', 'about-team', 'coverage-map', 'explorer', 'hero', 'not-found', 'project', 'stats', 'submit-band', 'subnav', 'top-needs', 'topic' );

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
		add_filter( 'block_editor_settings_all', array( self::class, 'lock_layout' ), 10, 2 );
	}

	/**
	 * Locks a FireHouse page's layout in the block editor: its parts can't be added,
	 * moved or removed there, only their words changed (Phase 6's decision). Without it,
	 * Backspace on a part's outline, where the Down arrow takes an editor between parts,
	 * removed the part, and Enter added a paragraph. The lock is the editor's setting
	 * for the page, not a `lock` attribute on each block, which the blocks don't declare
	 * (Page_Words). Other posts are left as they are.
	 *
	 * @param array<string, mixed> $settings The block editor's settings.
	 * @param mixed                $context  What the editor edits: WP_Block_Editor_Context, its `post` the post.
	 * @return array<string, mixed>
	 */
	public static function lock_layout( array $settings, mixed $context ): array {
		$post = is_object( $context ) && isset( $context->post ) ? $context->post : null;
		if ( $post instanceof \WP_Post && 'page' === $post->post_type && Capabilities::in_tree( $post ) ) {
			$settings['templateLock'] = 'all';
		}
		return $settings;
	}

	/**
	 * Loads assets/js/block-editor.js in every block editor on the site, with each
	 * block's name and the attributes its block.json declares, from the manifest. It
	 * shows the landing and About pages' words as text fields in place, and adds nothing
	 * to the inserter. Plain JavaScript with no build step: its dependencies are
	 * WordPress's own packages (`wp-data` for holding a save while a required word is
	 * empty), and it is versioned by the plugin's version and the file's modified time,
	 * as the stylesheets are.
	 */
	public static function enqueue_editor(): void {
		$root = dirname( __DIR__ );
		wp_enqueue_script(
			self::EDITOR_SCRIPT,
			plugins_url( 'assets/js/block-editor.js', $root . '/firehouse.php' ),
			array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-element', 'wp-i18n' ),
			VERSION . '-' . (int) filemtime( $root . '/assets/js/block-editor.js' ),
			false
		);
		wp_add_inline_script( self::EDITOR_SCRIPT, 'window.firehouseBlocks = ' . wp_json_encode( self::attributes() ) . ';', 'before' );
		wp_set_script_translations( self::EDITOR_SCRIPT, 'firehouse' );
	}

	/**
	 * Each block's name and the attributes its block.json declares, from the manifest:
	 * what a page may store in it. WordPress adds others to every block (`lock`,
	 * `metadata`), which no FireHouse block uses.
	 *
	 * @return array<string, list<string>>
	 */
	public static function attributes(): array {
		$manifest = include dirname( __DIR__ ) . '/blocks/blocks-manifest.php';
		$blocks   = array();
		foreach ( self::NAMES as $name ) {
			$attributes                     = is_array( $manifest ) ? ( $manifest[ $name ]['attributes'] ?? array() ) : array();
			$blocks[ 'firehouse/' . $name ] = array_map( 'strval', array_keys( is_array( $attributes ) ? $attributes : array() ) );
		}
		return $blocks;
	}

	/**
	 * Registers the blocks, with their metadata from the manifest, and two script modules
	 * the blocks' view scripts import by name: the site's search, for the explorer's
	 * (blocks/explorer/view.asset.php), and the map's drawing with Leaflet, for the
	 * coverage map's (blocks/coverage-map/view.asset.php), minified unless
	 * `SCRIPT_DEBUG` is on. WordPress loads a module only on a page whose script imports
	 * it.
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
		$map = 'assets/map/map' . wp_scripts_get_suffix() . '.js';
		wp_register_script_module(
			Coverage_Map::MAP_MODULE,
			plugins_url( $map, $root . '/firehouse.php' ),
			array(),
			VERSION . '-' . (int) filemtime( $root . '/' . $map )
		);
		wp_register_block_metadata_collection( $blocks, $blocks . '/blocks-manifest.php' );
		foreach ( self::NAMES as $name ) {
			register_block_type( $blocks . '/' . $name );
		}
	}
}
