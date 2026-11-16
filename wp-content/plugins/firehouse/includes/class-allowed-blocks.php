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
 * A page in the FireHouse tree holds only FireHouse blocks: no other block (core,
 * another plugin's, Custom HTML), and nothing but spaces between them (decided by
 * Eric, 14 November). A core block's italic and captions would come out in a slant
 * the fonts don't have, its markup would be the page's, and the page's words are the
 * FireHouse blocks' attributes, which Page_Words keeps as typed only while the page
 * holds nothing else.
 *
 * - The block editor offers only FireHouse blocks on such a page. None of them is in
 *   the inserter, so it offers nothing to add, and the layout is locked as well
 *   (Blocks::lock_layout()).
 * - A save through the REST API, which the block editor saves with, that would leave
 *   a page in the tree holding anything else is refused, and says why. That's a page
 *   in the tree already, one created under it, or one moved into it, together with
 *   the pages under that one, in any status, the trash included, which come with it.
 *   A save is judged by the parent it leaves the page with
 *   (Capabilities::parent_kept()).
 * - Any other save through wp_insert_post() (XML-RPC, WP-CLI, Quick Edit's Parent,
 *   another plugin's) is refused by the backstop. WordPress reports that as "Content,
 *   title, and excerpt are empty." to a caller that asks for errors; Quick Edit
 *   reports nothing, and the row keeps its old parent. A plugin that changes a page's
 *   parent after the backstop, in a later `wp_insert_post_data` or
 *   `wp_insert_post_parent` filter or straight in the database, isn't checked.
 * - The revisions screen's Restore is turned off for a revision that would be
 *   refused, rather than letting WordPress say it restored one it didn't.
 *
 * It holds for everyone, administrators too. A save that leaves a page in the tree
 * with the content it holds already goes through, so a page holding another block
 * from before can still be published, unpublished, trashed or restored; any other
 * change to it is refused until a save takes every other block out. Empty content
 * saves too. An autosave WordPress keeps as a revision is left alone: it isn't the
 * page, and WordPress would save a refusal as the autosave. A draft's autosave by its
 * own author saves the page itself, and is checked like any save. While the landing
 * page is in the trash there is no tree (Capabilities::root_id()), so nothing is
 * checked, and restoring it isn't checked either (left for Eric).
 */
final class Allowed_Blocks {

	/**
	 * How many of the pages that keep a page from being moved in the message names;
	 * the rest it counts.
	 */
	private const NAMED = 5;

	/**
	 * Hooks the block editor's list, the REST API's save, the backstop and the
	 * revisions screen.
	 */
	public static function hook(): void {
		add_filter( 'allowed_block_types_all', array( self::class, 'offer' ), 10, 2 );
		add_filter( 'rest_pre_insert_page', array( self::class, 'refuse_rest' ), 10, 2 );
		add_filter( 'wp_insert_post_empty_content', array( self::class, 'refuse_write' ), 10, 2 );
		add_filter( 'wp_prepare_revision_for_js', array( self::class, 'keep_from_restoring' ), 10, 3 );
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
		if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type || ! Capabilities::in_tree( $post ) ) {
			return $allowed;
		}
		return array_keys( Blocks::attributes() );
	}

	/**
	 * Refuses a save through the REST API that would leave a page in the FireHouse tree
	 * holding anything but FireHouse blocks, and says why. The block editor shows the
	 * message. A request that sends no content is checked with the content the page
	 * holds, which a move into the tree would take there.
	 *
	 * @param \stdClass|\WP_Error $prepared The page as the request would save it, unslashed.
	 * @param \WP_REST_Request    $request  The request.
	 * @return \stdClass|\WP_Error
	 */
	public static function refuse_rest( \stdClass|\WP_Error $prepared, \WP_REST_Request $request ): \stdClass|\WP_Error {
		if ( is_wp_error( $prepared ) || str_ends_with( $request->get_route(), '/autosaves' ) ) {
			return $prepared;
		}
		$stored = empty( $prepared->ID ) ? null : get_post( (int) $prepared->ID );
		if ( ! empty( $prepared->ID ) && ! $stored instanceof \WP_Post ) {
			return $prepared;
		}
		$parent_id = Capabilities::parent_kept( $stored, isset( $prepared->post_parent ) ? (int) $prepared->post_parent : ( $stored instanceof \WP_Post ? $stored->post_parent : 0 ) );
		$content   = self::text( $prepared->post_content ?? null ) ?? ( $stored instanceof \WP_Post ? $stored->post_content : '' );
		$why       = self::refusal( $stored, 'page', $parent_id, $content, static fn( \WP_Post $page ): array => array( $page->post_content ) );
		return null === $why ? $prepared : new \WP_Error( 'firehouse_only_firehouse_blocks', $why, array( 'status' => 400 ) );
	}

	/**
	 * The backstop for every other route: refuses a save that would leave a page in the
	 * FireHouse tree holding anything but FireHouse blocks. WordPress reports it as empty
	 * content.
	 *
	 * The content here has been through WordPress's filters (kses, for someone who may
	 * not post unfiltered HTML), so a save that leaves the content as stored is
	 * recognized by the stored content as those filters would give it, as well as by
	 * the content itself.
	 *
	 * @param bool                 $refuse  Whether WordPress, or an earlier filter, would refuse it already.
	 * @param array<string, mixed> $postarr The post being saved, slashed.
	 */
	public static function refuse_write( bool $refuse, array $postarr ): bool {
		if ( $refuse ) {
			return true;
		}
		$id     = (int) ( $postarr['ID'] ?? 0 );
		$stored = 0 === $id ? null : get_post( $id );
		if ( 0 !== $id && ! $stored instanceof \WP_Post ) {
			return false;
		}
		// A revision has an ID and type of its own, so it's never the page.
		$type      = (string) ( $postarr['post_type'] ?? ( $stored instanceof \WP_Post ? $stored->post_type : 'post' ) );
		$parent_id = Capabilities::parent_kept( $stored, isset( $postarr['post_parent'] ) ? (int) $postarr['post_parent'] : ( $stored instanceof \WP_Post ? $stored->post_parent : 0 ) );
		$sent      = self::text( $postarr['post_content'] ?? null );
		$content   = null === $sent ? ( $stored instanceof \WP_Post ? $stored->post_content : '' ) : wp_unslash( $sent );
		return null !== self::refusal(
			$stored,
			$type,
			$parent_id,
			$content,
			static fn( \WP_Post $page ): array => array( $page->post_content, wp_unslash( (string) sanitize_post_field( 'post_content', wp_slash( $page->post_content ), $page->ID, 'db' ) ) )
		);
	}

	/**
	 * Turns off the revisions screen's Restore for a revision of a page in the tree that
	 * holds anything but FireHouse blocks: restoring it would be refused, and WordPress
	 * would still say it had restored it.
	 *
	 * @param array<string, mixed> $data     The revision, as the revisions screen gets it.
	 * @param \WP_Post             $revision The revision.
	 * @param mixed                $post     The post it's a revision of.
	 * @return array<string, mixed>
	 */
	public static function keep_from_restoring( array $data, \WP_Post $revision, mixed $post ): array {
		if ( empty( $data['restoreUrl'] ) || ! $post instanceof \WP_Post ) {
			return $data;
		}
		if ( null !== self::refusal( $post, $post->post_type, $post->post_parent, $revision->post_content, array( $post->post_content ) ) ) {
			$data['restoreUrl'] = false;
		}
		return $data;
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
	 * Why a save is refused, or null when it isn't. It's refused when it leaves a page
	 * in the FireHouse tree holding something other than FireHouse blocks, unless it's
	 * a page in the tree already keeping what it holds; and when it moves a page into
	 * the tree with a page under it that holds something else, since the pages under it
	 * come into the tree with it, unsaved.
	 *
	 * @param \WP_Post|null     $stored    The post as stored, or null for a new one.
	 * @param string            $type      Its type once saved.
	 * @param int               $parent_id Its parent once saved.
	 * @param string            $content   Its content once saved, unslashed.
	 * @param string[]|\Closure $held      What counts as the content it holds now, or a function of the stored post that gives it.
	 */
	private static function refusal( ?\WP_Post $stored, string $type, int $parent_id, string $content, array|\Closure $held ): ?string {
		if ( ! self::lands_in_tree( $stored, $type, $parent_id ) ) {
			return null;
		}
		$there = $stored instanceof \WP_Post && 'page' === $stored->post_type && Capabilities::in_tree( $stored );
		if ( ! self::holds_only_firehouse_blocks( $content ) ) {
			if ( ! $there || ! in_array( $content, $held instanceof \Closure ? $held( $stored ) : $held, true ) ) {
				return self::why();
			}
			return null;
		}
		if ( ! $there && $stored instanceof \WP_Post ) {
			$below = self::other_blocks_below( $stored->ID );
			if ( array() !== $below ) {
				return self::why_not_moved( $below );
			}
		}
		return null;
	}

	/**
	 * The pages under a page, at any depth and in any registered status, the trash
	 * included (Capabilities::pages_below()), that hold something other than FireHouse
	 * blocks. A page in the trash comes into the tree with the pages under it, and a
	 * restore brings it back with what it holds, which a save keeping it lets through.
	 * It runs only when a page comes into the tree from outside it.
	 *
	 * @param int $id The page's ID.
	 * @return list<\WP_Post>
	 */
	private static function other_blocks_below( int $id ): array {
		return array_values( array_filter( Capabilities::pages_below( $id, Capabilities::every_status() ), static fn( \WP_Post $page ): bool => ! self::holds_only_firehouse_blocks( $page->post_content ) ) );
	}

	/**
	 * Why a page wasn't moved into the tree, naming the pages under it that hold
	 * another block, and what to do. The block editor shows it, so:
	 *
	 * - A page its user may not open (`read_post`) is only counted.
	 * - The first NAMED are named, a page in the trash marked so, and the rest counted.
	 * - A title's angle brackets read as ‹ and ›: the block editor leaves out a save's
	 *   message whole when anything in it looks like a tag.
	 *
	 * @param \WP_Post[] $pages The pages under it that hold another block.
	 */
	private static function why_not_moved( array $pages ): string {
		$named  = array();
		$hidden = 0;
		foreach ( $pages as $page ) {
			if ( ! current_user_can( 'read_post', $page->ID ) ) {
				++$hidden;
				continue;
			}
			$title   = strtr(
				Capabilities::title_of( $page ),
				array(
					'<' => '‹',
					'>' => '›',
				)
			);
			$named[] = 'trash' === $page->post_status
				/* translators: %s: a page's title. */
				? sprintf( __( '%s (in the Trash)', 'firehouse' ), $title )
				: $title;
		}
		$listed = array_slice( $named, 0, self::NAMED );
		$more   = count( $named ) - count( $listed );
		if ( $more > 0 ) {
			/* translators: %d: how many more pages hold another block. */
			$listed[] = sprintf( _n( '%d other page', '%d other pages', $more, 'firehouse' ), $more );
		}
		if ( $hidden > 0 ) {
			/* translators: %d: how many of the pages that hold another block the user may not open. */
			$listed[] = sprintf( _n( '%d page you can’t open', '%d pages you can’t open', $hidden, 'firehouse' ), $hidden );
		}
		return implode(
			' ',
			array(
				__( 'This page wasn’t moved. FireHouse pages hold only FireHouse blocks, and the pages under this one would move with it.', 'firehouse' ),
				sprintf(
					/* translators: %s: the pages under the page being moved that hold another block, as a list. */
					_n( 'One of them holds another block, or text between its blocks: %s.', 'Some of them hold another block, or text between their blocks: %s.', count( $pages ), 'firehouse' ),
					wp_sprintf( '%l', $listed )
				),
				__( 'Pages in the Trash count too, since restoring one brings it back as it is. Take the other blocks out of those pages, or move those pages elsewhere, then move this page again. A page in the Trash can’t be changed or moved there: restore it first, or delete it permanently.', 'firehouse' ),
			)
		);
	}

	/**
	 * Whether a save leaves the post a page in the FireHouse tree: the landing page
	 * itself, or a page whose parent, once saved, is the landing page or in the tree.
	 *
	 * @param \WP_Post|null $stored The post as stored, or null for a new one.
	 * @param string        $type   Its type once saved.
	 * @param int           $parent_id Its parent once saved.
	 */
	private static function lands_in_tree( ?\WP_Post $stored, string $type, int $parent_id ): bool {
		if ( 'page' !== $type ) {
			return false;
		}
		$root = Capabilities::root_id();
		if ( 0 === $root ) {
			return false;
		}
		if ( ( $stored instanceof \WP_Post && $stored->ID === $root ) || $parent_id === $root ) {
			return true;
		}
		$above = $parent_id > 0 ? get_post( $parent_id ) : null;
		return $above instanceof \WP_Post && 'page' === $above->post_type && Capabilities::in_tree( $above );
	}

	/**
	 * Content as text: a string as it is, a number or boolean as WordPress would store
	 * it, and anything else as none.
	 *
	 * @param mixed $content The content sent.
	 */
	private static function text( mixed $content ): ?string {
		return is_scalar( $content ) ? (string) $content : null;
	}

	/**
	 * Why a save was refused, and what to do.
	 */
	private static function why(): string {
		return __( 'This page wasn’t saved: a FireHouse page holds only its FireHouse blocks, so no other block, such as a paragraph, an image or Custom HTML, and no text between them. Take out any other block or text, then save again.', 'firehouse' );
	}
}
