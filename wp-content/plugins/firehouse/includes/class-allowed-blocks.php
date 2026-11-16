<?php
/**
 * The FireHouse pages hold only FireHouse blocks.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A page in the FireHouse tree holds only FireHouse blocks: no core block, no Custom
 * HTML, and nothing but spaces between them (decided by Eric, 14 November). A core
 * block's italic and captions would come out in a slant the fonts don't have, its
 * markup would be the page's, and the page's words are the FireHouse blocks'
 * attributes, which Page_Words keeps as typed only while the page holds nothing else.
 *
 * - The block editor offers only FireHouse blocks on such a page. None of them is in
 *   the inserter, so it offers nothing, and the layout is locked as well
 *   (Blocks::lock_layout()).
 * - A save through the REST API, which the block editor saves with, that would give
 *   such a page anything else is refused, and says why. An autosave is left alone:
 *   WordPress would save a refusal as the autosave, and an autosave isn't the page.
 * - Any other route (another editor, another plugin, WP-CLI) is refused by the
 *   backstop, which WordPress reports as empty content.
 *
 * It holds for everyone, administrators too. A save that leaves the content as it's
 * stored isn't refused, so a page that already holds another block can still be
 * trashed, restored, published or unpublished, and the other block taken out. Empty
 * content is allowed. Revisions are left alone: restoring one saves the page, and is
 * refused like any save. The pages the Import screen creates are new, so in no tree
 * yet, and hold only FireHouse blocks.
 */
final class Allowed_Blocks {

	/**
	 * Hooks the block editor's list, the REST API's save and the backstop.
	 */
	public static function hook(): void {
		add_filter( 'allowed_block_types_all', array( self::class, 'offer' ), 10, 2 );
		add_filter( 'rest_pre_insert_page', array( self::class, 'refuse_rest' ), 10, 2 );
		add_filter( 'wp_insert_post_empty_content', array( self::class, 'refuse_write' ), 10, 2 );
	}

	/**
	 * The blocks the block editor offers: on a page in the FireHouse tree, only the
	 * FireHouse blocks, whatever was allowed before; in any other editor, what was.
	 *
	 * @param mixed $allowed What's allowed so far: true for every block, or their names.
	 * @param mixed $context What the editor edits: WP_Block_Editor_Context, its `post` the post.
	 * @return mixed
	 */
	public static function offer( mixed $allowed, mixed $context ): mixed {
		$post = is_object( $context ) && isset( $context->post ) ? $context->post : null;
		if ( ! $post instanceof \WP_Post || ! self::of_the_tree( $post ) ) {
			return $allowed;
		}
		return array_keys( Blocks::attributes() );
	}

	/**
	 * Refuses a save through the REST API that would give a page in the FireHouse tree
	 * anything but FireHouse blocks, and says why. The block editor shows the message.
	 *
	 * @param \stdClass|\WP_Error $prepared The page as the request would save it, unslashed.
	 * @param \WP_REST_Request    $request  The request.
	 * @return \stdClass|\WP_Error
	 */
	public static function refuse_rest( \stdClass|\WP_Error $prepared, \WP_REST_Request $request ): \stdClass|\WP_Error {
		if ( is_wp_error( $prepared ) || empty( $prepared->ID ) || ! isset( $prepared->post_content ) || ! is_string( $prepared->post_content ) ) {
			return $prepared;
		}
		if ( str_ends_with( $request->get_route(), '/autosaves' ) ) {
			return $prepared;
		}
		if ( ! self::refuses( get_post( (int) $prepared->ID ), $prepared->post_content ) ) {
			return $prepared;
		}
		return new \WP_Error( 'firehouse_only_firehouse_blocks', self::why(), array( 'status' => 400 ) );
	}

	/**
	 * The backstop for every other route: refuses a save that would give a page in the
	 * FireHouse tree anything but FireHouse blocks. WordPress reports it as empty
	 * content.
	 *
	 * @param bool                 $refuse  Whether WordPress, or an earlier filter, would refuse it already.
	 * @param array<string, mixed> $postarr The post being saved, slashed.
	 */
	public static function refuse_write( bool $refuse, array $postarr ): bool {
		if ( $refuse ) {
			return true;
		}
		$id      = (int) ( $postarr['ID'] ?? 0 );
		$content = $postarr['post_content'] ?? null;
		if ( 0 === $id || ! is_string( $content ) ) {
			return false;
		}
		// A revision has an ID of its own, so it's never the page.
		return self::refuses( get_post( $id ), wp_unslash( $content ) );
	}

	/**
	 * Whether content holds nothing but FireHouse blocks, with only spaces around and
	 * inside them, whatever their attributes (Page_Words::only_firehouse_blocks()), or
	 * nothing at all.
	 *
	 * @param string $content The content, unslashed.
	 */
	public static function holds_only_firehouse_blocks( string $content ): bool {
		return '' === trim( $content, " \t\n\r" ) || Page_Words::only_firehouse_blocks( parse_blocks( $content ) );
	}

	/**
	 * Whether this content is refused for this post: it's a page in the FireHouse tree,
	 * the content isn't what it holds now, and holds something other than FireHouse
	 * blocks.
	 *
	 * @param mixed  $post    The post being saved, as stored.
	 * @param string $content Its content as it would be saved, unslashed.
	 */
	private static function refuses( mixed $post, string $content ): bool {
		if ( ! $post instanceof \WP_Post || ! self::of_the_tree( $post ) ) {
			return false;
		}
		return ! self::holds_only_firehouse_blocks( $content ) && $content !== $post->post_content;
	}

	/**
	 * Whether a post is a page in the FireHouse tree.
	 *
	 * @param \WP_Post $post The post.
	 */
	private static function of_the_tree( \WP_Post $post ): bool {
		return 'page' === $post->post_type && Capabilities::in_tree( $post );
	}

	/**
	 * Why a save was refused, and what to do.
	 */
	private static function why(): string {
		return __( 'This page wasn’t saved: a FireHouse page holds only its FireHouse blocks, so no other block, such as a paragraph, an image or Custom HTML, and no text between them. Take out what was added, then save again.', 'firehouse' );
	}
}
