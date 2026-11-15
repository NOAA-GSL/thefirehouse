<?php
/**
 * The words editors type into the FireHouse pages' blocks, kept as typed.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps what an editor types into the FireHouse pages' blocks as typed.
 *
 * The landing and About pages' words are their blocks' attributes. A FireHouse Editor
 * may not post unfiltered HTML, so WordPress runs kses over every attribute string
 * of a page they save: "R&D" becomes "R&amp;D", which the page then shows as
 * written, and "<5 km>" loses its "<". On those pages kses has nothing to protect.
 * Every FireHouse block prints its words escaped and checks its links (Markup), and
 * saves no markup of its own: its render.php prints it. So when a page in the
 * FireHouse tree, or a revision of one, is saved with content made of nothing but
 * FireHouse blocks, the content is kept as it was sent, before kses.
 *
 * Anything else keeps WordPress's kses: content with markup or text between the
 * blocks or inside one, a block that isn't FireHouse's, a page outside the tree. The
 * hero and the About sections print what's inside them raw, their inner blocks, so a
 * block may hold only other FireHouse blocks, with nothing but spaces around them.
 */
final class Page_Words {

	/**
	 * Hooks the save filter, late, so it judges the content as WordPress would store it.
	 */
	public static function hook(): void {
		add_filter( 'wp_insert_post_data', array( self::class, 'keep_as_typed' ), PHP_INT_MAX - 10, 3 );
	}

	/**
	 * A FireHouse page's content as it was sent, when kses ran over it and it holds
	 * only FireHouse blocks (only_firehouse()); otherwise as WordPress would store it.
	 *
	 * @param array<string, mixed> $data                The post's fields as they will be saved, slashed.
	 * @param array<string, mixed> $postarr             The post as given, sanitized.
	 * @param array<string, mixed> $unsanitized_postarr The post as given, before kses.
	 * @return array<string, mixed>
	 */
	public static function keep_as_typed( array $data, array $postarr, array $unsanitized_postarr = array() ): array {
		// Without kses, as for someone who may post unfiltered HTML, the content is kept
		// as sent already.
		if ( false === has_filter( 'content_save_pre', 'wp_filter_post_kses' ) ) {
			return $data;
		}
		$sent = $unsanitized_postarr['post_content'] ?? null;
		if ( ! is_string( $sent ) || ! self::of_a_firehouse_page( $data, $postarr ) ) {
			return $data;
		}
		if ( ! self::only_firehouse( parse_blocks( wp_unslash( $sent ) ) ) ) {
			return $data;
		}
		$data['post_content'] = $sent;
		return $data;
	}

	/**
	 * Whether the post saved is a page in the FireHouse tree, or a revision or
	 * autosave of one. The ID decides, not the type sent: a new post is in no tree.
	 *
	 * @param array<string, mixed> $data    The post's fields as they will be saved.
	 * @param array<string, mixed> $postarr The post as given.
	 */
	private static function of_a_firehouse_page( array $data, array $postarr ): bool {
		$id = 'revision' === ( $data['post_type'] ?? null ) ? (int) ( $data['post_parent'] ?? 0 ) : (int) ( $postarr['ID'] ?? 0 );
		if ( 0 === $id ) {
			return false;
		}
		$page = get_post( $id );
		return $page instanceof \WP_Post && 'page' === $page->post_type && Capabilities::in_tree( $page );
	}

	/**
	 * Whether parsed content is only FireHouse blocks: each one a FireHouse block
	 * (Blocks::NAMES), with nothing but spaces around it or inside it, and holding
	 * only FireHouse blocks. Content with no block at all isn't.
	 *
	 * @param array<int, mixed> $blocks The blocks, as parse_blocks() gives them.
	 */
	public static function only_firehouse( array $blocks ): bool {
		$found = false;
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				return false;
			}
			$name = $block['blockName'] ?? null;
			if ( null === $name ) {
				// What's between blocks: only spaces and line breaks.
				if ( ! self::blank( $block['innerHTML'] ?? '' ) ) {
					return false;
				}
				continue;
			}
			if ( ! is_string( $name ) || ! str_starts_with( $name, 'firehouse/' ) || ! in_array( substr( $name, strlen( 'firehouse/' ) ), Blocks::NAMES, true ) ) {
				return false;
			}
			if ( ! self::blank( $block['innerHTML'] ?? '' ) ) {
				return false;
			}
			foreach ( is_array( $block['innerContent'] ?? null ) ? $block['innerContent'] : array() as $part ) {
				if ( null !== $part && ! self::blank( $part ) ) {
					return false;
				}
			}
			$inner = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array();
			if ( array() !== $inner && ! self::only_firehouse( $inner ) ) {
				return false;
			}
			$found = true;
		}
		return $found;
	}

	/**
	 * Whether markup between or inside blocks is only spaces and line breaks.
	 *
	 * @param mixed $html The markup.
	 */
	private static function blank( mixed $html ): bool {
		return is_string( $html ) && '' === trim( $html, " \t\n\r" );
	}
}
