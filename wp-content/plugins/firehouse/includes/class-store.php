<?php
/**
 * Projects and topics as WordPress stores them.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads project and topic posts back into the shapes of src/content/types.ts, and
 * writes them.
 *
 * A project's slug and `published` flag are the post's slug and status; a topic's key
 * and label are its slug and title. Every other field is meta (see Meta), the
 * project's title included, read back with the type its schema gives, since WordPress
 * returns a stored number or true as text. A project post's own title is a label for
 * WordPress's lists and any theme template: the researcher's title, escaped, so a
 * template that prints it raw can't be made to run markup. A topic's label is stored
 * escaped the same way, and read back unescaped.
 *
 * Writes keep researchers' text exactly as given (rule 1). WordPress strips one level
 * of backslashes from what it saves, so every write is slashed first, and the kses
 * filters are off while it runs. Pages escape the text when they show it.
 */
final class Store {

	/**
	 * The statuses a Firehouse post can have, the trash included.
	 */
	private const STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' );

	/**
	 * Whether save() is running, so the filters that keep a topic's key and status
	 * let the plugin's own writes through.
	 *
	 * @var bool
	 */
	private static bool $writing = false;

	/**
	 * Whether the plugin itself is saving a post right now.
	 */
	public static function writing(): bool {
		return self::$writing;
	}

	/**
	 * A project post's title: the researcher's, escaped for WordPress's lists.
	 *
	 * @param string $title The project's title, as the researcher wrote it.
	 */
	public static function label( string $title ): string {
		return esc_html( $title );
	}

	/**
	 * Every project post, oldest first.
	 *
	 * @return list<array{post_id: int, status: string, fields: array<string, mixed>}>
	 */
	public static function projects(): array {
		return array_map( array( self::class, 'project' ), self::posts( Post_Types::PROJECT ) );
	}

	/**
	 * Every topic post, oldest first.
	 *
	 * @return list<array{post_id: int, status: string, key: string, fields: array<string, mixed>}>
	 */
	public static function topics(): array {
		return array_map( array( self::class, 'topic' ), self::posts( Post_Types::TOPIC ) );
	}

	/**
	 * A project post, as types.ts has a project.
	 *
	 * @param \WP_Post $post The post.
	 * @return array{post_id: int, status: string, fields: array<string, mixed>}
	 */
	public static function project( \WP_Post $post ): array {
		$fields = array(
			'slug'      => $post->post_name,
			'published' => 'publish' === $post->post_status,
		) + self::meta( $post->ID, Post_Types::PROJECT );
		return array(
			'post_id' => $post->ID,
			'status'  => $post->post_status,
			'fields'  => $fields,
		);
	}

	/**
	 * A topic post: its key, its label (its title, which is stored escaped like a
	 * project's label), and its fields as Meta has them, a need's projects still as
	 * post IDs.
	 *
	 * @param \WP_Post $post The post.
	 * @return array{post_id: int, status: string, key: string, fields: array<string, mixed>}
	 */
	public static function topic( \WP_Post $post ): array {
		return array(
			'post_id' => $post->ID,
			'status'  => $post->post_status,
			'key'     => $post->post_name,
			'fields'  => array( 'label' => wp_specialchars_decode( $post->post_title, ENT_QUOTES ) ) + self::meta( $post->ID, Post_Types::TOPIC ),
		);
	}

	/**
	 * A post's stored fields, each with its schema's type. A field with no meta is
	 * left out, as JSON leaves out a missing key.
	 *
	 * @param int    $post_id   The post.
	 * @param string $post_type Its post type.
	 * @return array<string, mixed>
	 */
	public static function meta( int $post_id, string $post_type ): array {
		$fields = array();
		foreach ( Meta::fields( $post_type ) as $name => $schema ) {
			if ( metadata_exists( 'post', $post_id, Meta::key( $name ) ) ) {
				$fields[ $name ] = Meta::typed( get_post_meta( $post_id, Meta::key( $name ), true ), $schema['type'] );
			}
		}
		return $fields;
	}

	/**
	 * Saves a post and its fields: creates it when $post_id is 0, or changes it.
	 *
	 * Fields in $delete are removed before the post is saved, and fields in $set are
	 * saved with it, so the revision WordPress makes holds the result. If WordPress
	 * refuses the save, the removed fields are put back.
	 *
	 * @param string               $post_type The post type.
	 * @param int                  $post_id   The post, or 0 for a new one.
	 * @param array<string, mixed> $post      Post fields: post_title, post_name, post_status, post_author.
	 * @param array<string, mixed> $set       Fields to save, by name.
	 * @param string[]             $delete    Fields to remove, by name.
	 * @return int|\WP_Error The post's ID, or why it wasn't saved.
	 */
	public static function save( string $post_type, int $post_id, array $post, array $set, array $delete ): int|\WP_Error {
		$removed = array();
		foreach ( $delete as $name ) {
			$removed[ $name ] = get_post_meta( $post_id, Meta::key( $name ), true );
			delete_post_meta( $post_id, Meta::key( $name ) );
		}
		$saved = self::write( $post_type, $post_id, $post, $set );
		if ( is_wp_error( $saved ) ) {
			foreach ( $removed as $name => $value ) {
				update_post_meta( $post_id, Meta::key( $name ), wp_slash( $value ) );
			}
		}
		return $saved;
	}

	/**
	 * Inserts or updates the post itself, its fields with it.
	 *
	 * @param string               $post_type The post type.
	 * @param int                  $post_id   The post, or 0 for a new one.
	 * @param array<string, mixed> $post      Post fields.
	 * @param array<string, mixed> $set       Fields to save, by name.
	 * @return int|\WP_Error The post's ID, or why it wasn't saved.
	 */
	private static function write( string $post_type, int $post_id, array $post, array $set ): int|\WP_Error {

		$meta = array();
		foreach ( $set as $name => $value ) {
			$meta[ Meta::key( $name ) ] = $value;
		}
		$postarr = array_merge(
			$post,
			array(
				'post_type'  => $post_type,
				'meta_input' => $meta,
			)
		);

		$kses = (bool) has_filter( 'title_save_pre', 'wp_filter_kses' );
		kses_remove_filters();
		self::$writing = true;
		try {
			if ( 0 === $post_id ) {
				return wp_insert_post( wp_slash( $postarr ), true );
			}
			$postarr['ID'] = $post_id;
			return wp_update_post( wp_slash( $postarr ), true );
		} finally {
			self::$writing = false;
			if ( $kses ) {
				kses_init_filters();
			}
		}
	}

	/**
	 * Every post of a type, in any status, oldest first. get_posts() leaves other
	 * plugins' query filters out.
	 *
	 * @param string $post_type The post type.
	 * @return \WP_Post[]
	 */
	private static function posts( string $post_type ): array {
		return get_posts(
			array(
				'post_type'   => $post_type,
				'post_status' => self::STATUSES,
				'numberposts' => -1,
				'orderby'     => 'ID',
				'order'       => 'ASC',
			)
		);
	}
}
