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
 * written, and "<5 km>" is dropped. On those pages kses has nothing to protect. Every
 * FireHouse block prints its words escaped and checks its links (Markup), and saves
 * no markup of its own: its render.php prints it. So when a page in the FireHouse
 * tree, or a revision of one, is saved with content made of nothing but FireHouse
 * blocks, each holding only the attributes its block.json declares, the content is
 * kept as it was sent, before any of WordPress's filters on it: kses, and core's
 * strip of a block's custom CSS from those who may not edit CSS, which a FireHouse
 * block can't hold here. It's stored as WordPress's own serializer writes it, which
 * escapes what could end a block's comment early.
 *
 * Anything else keeps WordPress's filters: content with markup or text between the
 * blocks or inside one, a block that isn't FireHouse's, an attribute its block.json
 * doesn't declare (`style`, `className`, `lock`, `metadata`), a page outside the
 * tree or being moved. So a WordPress feature that adds `metadata` to a block, such
 * as a note on it, sends the whole page's words back through kses until it's gone;
 * the blocks turn off renaming, the commonest one. The hero and the About sections print what's inside them raw,
 * their inner blocks, so a block may hold only other FireHouse blocks, with nothing
 * but spaces around them.
 */
final class Page_Words {

	/**
	 * Hooks the save filter, late, so it has the last word on the content.
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
	 * @param array<string, mixed> $unsanitized_postarr The post as given, before WordPress's filters.
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
		$blocks = parse_blocks( wp_unslash( $sent ) );
		if ( ! self::only_firehouse( $blocks ) ) {
			return $data;
		}
		$data['post_content'] = wp_slash( self::storable( serialize_blocks( $blocks ) ) );
		return $data;
	}

	/**
	 * Whether the post saved is a page in the FireHouse tree that stays where it is,
	 * or a revision or autosave of one. The stored post decides, not the type sent: a
	 * new post is in no tree.
	 *
	 * @param array<string, mixed> $data    The post's fields as they will be saved.
	 * @param array<string, mixed> $postarr The post as given.
	 */
	private static function of_a_firehouse_page( array $data, array $postarr ): bool {
		$revision = 'revision' === ( $data['post_type'] ?? null );
		$id       = $revision ? (int) ( $data['post_parent'] ?? 0 ) : (int) ( $postarr['ID'] ?? 0 );
		if ( 0 === $id ) {
			return false;
		}
		$page = get_post( $id );
		if ( ! $page instanceof \WP_Post || 'page' !== $page->post_type || ! Capabilities::in_tree( $page ) ) {
			return false;
		}
		return $revision || (int) ( $data['post_parent'] ?? -1 ) === $page->post_parent;
	}

	/**
	 * Whether parsed content is only FireHouse blocks: each one a FireHouse block
	 * holding only the attributes its block.json declares (Blocks::attributes()), with
	 * nothing but spaces around it or inside it, and holding only FireHouse blocks.
	 * Content with no block at all isn't.
	 *
	 * @param array<int, mixed> $blocks The blocks, as parse_blocks() gives them.
	 */
	public static function only_firehouse( array $blocks ): bool {
		return self::of_firehouse_blocks( $blocks, Blocks::attributes() );
	}

	/**
	 * The check only_firehouse() makes, with each block's declared attributes read once.
	 *
	 * @param array<int, mixed>           $blocks   The blocks, as parse_blocks() gives them.
	 * @param array<string, list<string>> $declared Each block's declared attributes.
	 */
	private static function of_firehouse_blocks( array $blocks, array $declared ): bool {
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
			if ( ! is_string( $name ) || ! isset( $declared[ $name ] ) ) {
				return false;
			}
			$attrs = $block['attrs'] ?? array();
			if ( ! is_array( $attrs ) || array() !== array_diff( array_map( 'strval', array_keys( $attrs ) ), $declared[ $name ] ) ) {
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
			if ( array() !== $inner && ! self::of_firehouse_blocks( $inner, $declared ) ) {
				return false;
			}
			$found = true;
		}
		return $found;
	}

	/**
	 * Content WordPress's database can store: on a posts table whose character set
	 * can't hold an emoji, each one written as JSON escapes, which read back as the
	 * emoji. wp_insert_post() writes them as HTML entities there, which a block's
	 * attribute would show as written. Content only_firehouse() accepts holds text
	 * only in its blocks' attributes, which are JSON.
	 *
	 * @param string $content The serialized blocks.
	 */
	private static function storable( string $content ): string {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col_charset' ) || ! in_array( $wpdb->get_col_charset( $wpdb->posts, 'post_content' ), array( 'utf8', 'utf8mb3' ), true ) ) {
			return $content;
		}
		// Content that failed, which a parse of valid JSON can't give, is stored as it is,
		// for the database to refuse, rather than emptied.
		return preg_replace_callback(
			'/[\x{10000}-\x{10FFFF}]/u',
			static function ( array $emoji ): string {
				$json = wp_json_encode( $emoji[0] );
				return is_string( $json ) ? substr( $json, 1, -1 ) : $emoji[0];
			},
			$content
		) ?? $content;
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
