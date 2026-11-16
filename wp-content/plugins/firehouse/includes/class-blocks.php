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
	 * (Page_Words).
	 *
	 * Only a page in the FireHouse tree made of nothing but FireHouse blocks is locked
	 * (Page_Words::only_firehouse_blocks()), whatever attributes they hold, so a block
	 * with a note on it doesn't unlock the page. An empty page there can still be
	 * written, and one holding another block can have it taken out, which also lets
	 * Page_Words keep its words as typed again; it's locked when the editor next opens
	 * it. Other posts are left as they are.
	 *
	 * @param array<string, mixed> $settings The block editor's settings.
	 * @param mixed                $context  What the editor edits: WP_Block_Editor_Context, its `post` the post.
	 * @return array<string, mixed>
	 */
	public static function lock_layout( array $settings, mixed $context ): array {
		$post = is_object( $context ) && isset( $context->post ) ? $context->post : null;
		if ( $post instanceof \WP_Post && 'page' === $post->post_type && Capabilities::in_tree( $post ) && Page_Words::only_firehouse_blocks( parse_blocks( $post->post_content ) ) ) {
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
			array( 'wp-a11y', 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-element', 'wp-i18n' ),
			VERSION . '-' . (int) filemtime( $root . '/assets/js/block-editor.js' ),
			false
		);
		wp_add_inline_script( self::EDITOR_SCRIPT, 'window.firehouseBlocks = ' . wp_json_encode( self::attributes() ) . '; window.firehousePortraits = ' . wp_json_encode( self::portraits( get_post() ) ) . ';', 'before' );
		wp_set_script_translations( self::EDITOR_SCRIPT, 'firehouse' );
	}

	/**
	 * The portraits a team card on a FireHouse page can show, for its picker in the
	 * block editor: the images attached to a page in the FireHouse tree, where an
	 * administrator puts a new one, and those the page's cards already use, as the
	 * import binds them. Only an image the About page would show (About_Page::shows()).
	 * The FireHouse Editor role has no Media Library (decided 15 November), so this is
	 * the whole choice. None for any other post.
	 *
	 * @param mixed $post The post the editor edits.
	 * @return list<array{id: int, title: string, url: string}>
	 */
	public static function portraits( mixed $post ): array {
		if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type || ! Capabilities::in_tree( $post ) ) {
			return array();
		}
		$attached = get_posts(
			array(
				'post_type'       => 'attachment',
				'post_mime_type'  => 'image',
				'post_status'     => 'inherit',
				'post_parent__in' => Capabilities::tree_ids(),
				'numberposts'     => 100,
				'orderby'         => 'title',
				'order'           => 'ASC',
				'fields'          => 'ids',
			)
		);
		$ids      = array_merge( array_map( 'intval', is_array( $attached ) ? $attached : array() ), self::used_portraits( parse_blocks( $post->post_content ) ) );

		$portraits = array();
		foreach ( array_unique( $ids ) as $id ) {
			if ( ! About_Page::shows( $id ) ) {
				continue;
			}
			$url         = wp_get_attachment_image_url( $id, 'thumbnail' );
			$portraits[] = array(
				'id'    => $id,
				'title' => wp_specialchars_decode( get_the_title( $id ), ENT_QUOTES ),
				'url'   => is_string( $url ) ? $url : '',
			);
		}
		return $portraits;
	}

	/**
	 * The portraits the team cards in parsed content use, by ID.
	 *
	 * @param array<int, mixed> $blocks The blocks, as parse_blocks() gives them.
	 * @return list<int>
	 */
	private static function used_portraits( array $blocks ): array {
		$ids = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( 'firehouse/about-team' === ( $block['blockName'] ?? null ) && is_array( $block['attrs']['members'] ?? null ) ) {
				foreach ( $block['attrs']['members'] as $member ) {
					if ( is_array( $member ) && is_int( $member['photoId'] ?? null ) && $member['photoId'] > 0 ) {
						$ids[] = $member['photoId'];
					}
				}
			}
			if ( is_array( $block['innerBlocks'] ?? null ) ) {
				$ids = array_merge( $ids, self::used_portraits( $block['innerBlocks'] ) );
			}
		}
		return $ids;
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
