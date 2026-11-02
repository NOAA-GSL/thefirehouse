<?php
/**
 * The fields of a project and a topic, as post meta.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers each field of a project and a topic as post meta, from the schemas in
 * shared/, which scripts/project-schema.mjs generates from src/content/types.ts.
 *
 * Each field is stored under its name in types.ts with the `fh_` prefix, so another
 * plugin's meta of the same name can't reach it. None is sanitized on save: survey
 * text is stored as the researcher wrote it (rule 1) and escaped where it's shown.
 * Neither post type is in the REST API, so WordPress doesn't check a value against
 * its schema on its own; whatever writes a field checks it with validate() first.
 */
final class Meta {

	/**
	 * The prefix on every meta key.
	 */
	public const PREFIX = 'fh_';

	/**
	 * A project's fields that the post itself holds: its slug, its title, and whether
	 * it is published, which is its status.
	 */
	private const PROJECT_POST_FIELDS = array( 'slug', 'title', 'published' );

	/**
	 * The schemas, read once per request.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private static array $fields = array();

	/**
	 * Registers the meta of both post types. Runs after the post types are
	 * registered, because a field's revisions need its post type's.
	 */
	public static function register(): void {
		foreach ( array( Post_Types::PROJECT, Post_Types::TOPIC ) as $post_type ) {
			foreach ( self::fields( $post_type ) as $name => $schema ) {
				register_post_meta( $post_type, self::key( $name ), self::args( $schema ) );
			}
		}
	}

	/**
	 * The meta key of a field.
	 *
	 * @param string $name The field's name in types.ts, such as "completionYear".
	 */
	public static function key( string $name ): string {
		return self::PREFIX . $name;
	}

	/**
	 * Each field of a post type that is stored as meta, with its schema.
	 *
	 * A topic's needs point to their projects by post ID, where types.ts and the seed
	 * bundle name them by slug, so renaming a project can't strip a need of its
	 * sources (rule 5). The ID is turned back into a slug when a page is drawn.
	 *
	 * A schema file that can't be read is a broken release, and read() throws.
	 *
	 * @param string $post_type Post_Types::PROJECT or Post_Types::TOPIC.
	 * @return array<string, array<string, mixed>> Schemas keyed by field name.
	 * @throws \InvalidArgumentException When the post type isn't one of the two.
	 */
	public static function fields( string $post_type ): array {
		if ( isset( self::$fields[ $post_type ] ) ) {
			return self::$fields[ $post_type ];
		}

		if ( Post_Types::PROJECT === $post_type ) {
			$fields = array_diff_key( self::read( 'project.schema.json' ), array_flip( self::PROJECT_POST_FIELDS ) );
		} elseif ( Post_Types::TOPIC === $post_type ) {
			$fields = self::read( 'topic.schema.json' );
			$fields['topNeeds']['items']['properties']['projects'] = array(
				'description' => 'The post IDs of the projects the need draws on, most direct first.',
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
			);
		} else {
			throw new \InvalidArgumentException( 'Firehouse has no fields for that post type.' );
		}

		self::$fields[ $post_type ] = $fields;
		return $fields;
	}

	/**
	 * Checks a value against its field's schema, with WordPress's own validator.
	 *
	 * @param string $post_type Post_Types::PROJECT or Post_Types::TOPIC.
	 * @param string $name      The field's name in types.ts.
	 * @param mixed  $value     The value to store.
	 * @return bool|\WP_Error True, or what is wrong with the value.
	 */
	public static function validate( string $post_type, string $name, mixed $value ): bool|\WP_Error {
		$schema = self::fields( $post_type )[ $name ] ?? null;
		if ( null === $schema ) {
			return new \WP_Error( 'firehouse_unknown_field', sprintf( 'A %1$s has no field named %2$s.', $post_type, $name ) );
		}
		return rest_validate_value_from_schema( $value, $schema, $name );
	}

	/**
	 * The arguments a field is registered with.
	 *
	 * @param array<string, mixed> $schema The field's schema.
	 * @return array<string, mixed>
	 */
	public static function args( array $schema ): array {
		return array(
			'type'              => $schema['type'],
			'description'       => $schema['description'] ?? '',
			'single'            => true,
			'show_in_rest'      => false,
			'revisions_enabled' => true,
			'auth_callback'     => array( self::class, 'can_edit' ),
		);
	}

	/**
	 * Whether the current user may change a field: only if they may edit its post.
	 *
	 * @param bool   $allowed  Whether WordPress would allow it.
	 * @param string $meta_key The meta key.
	 * @param int    $post_id  The post.
	 */
	public static function can_edit( bool $allowed, string $meta_key, int $post_id ): bool {
		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * The properties of one schema file in shared/.
	 *
	 * @param string $file The file name.
	 * @return array<string, array<string, mixed>>
	 * @throws \RuntimeException When the file is missing or isn't a schema.
	 */
	private static function read( string $file ): array {
		$schema = wp_json_file_decode( dirname( __DIR__ ) . '/shared/' . $file, array( 'associative' => true ) );
		if ( ! is_array( $schema ) || ! is_array( $schema['properties'] ?? null ) ) {
			throw new \RuntimeException( 'The Firehouse plugin could not read shared/' . esc_html( $file ) . '.' );
		}
		return $schema['properties'];
	}
}
