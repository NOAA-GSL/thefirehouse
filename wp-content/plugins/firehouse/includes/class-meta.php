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
 * text, the project's title included, is stored as plain-string meta exactly as the
 * researcher wrote it (rule 1), and escaped where it's shown.
 *
 * Only the plugin's own code writes these fields, through Store. WordPress's custom
 * field screens and requests can't: they ask for edit_post_meta, which no one is
 * given, so they can't change a survey field around rule 4. Neither post type is in
 * the REST API, so WordPress would never check a value against its schema on its own;
 * refuse_invalid() checks every write with validate() instead.
 */
final class Meta {

	/**
	 * The prefix on every meta key.
	 */
	public const PREFIX = 'fh_';

	/**
	 * A project's fields that the post itself holds: its slug, and whether it is
	 * published, which is its status.
	 */
	private const PROJECT_POST_FIELDS = array( 'slug', 'published' );

	/**
	 * A topic's fields that come from its synthesis (TopicSummary in types.ts); the
	 * rest of its fields are the topic's own (TopicContent).
	 */
	public const SYNTHESIS_FIELDS = array( 'topNeeds', 'updatedAt', 'sourceCount', 'model', 'reviewedBy' );

	/**
	 * Each schema type, in words, for a message.
	 */
	private const TYPE_WORDS = array(
		'string'  => 'text',
		'boolean' => 'true or false',
		'integer' => 'a whole number',
		'number'  => 'a number',
		'array'   => 'a list',
		'object'  => 'an object',
	);

	/**
	 * The schemas, read once per request.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private static array $fields = array();

	/**
	 * Hooks the check on every write of a field, whatever code makes it.
	 */
	public static function hook(): void {
		add_filter( 'add_post_metadata', array( self::class, 'refuse_add' ), 10, 4 );
		add_filter( 'update_post_metadata', array( self::class, 'refuse_invalid' ), 10, 4 );
	}

	/**
	 * Refuses to add a second value to a field, which holds one, and otherwise checks
	 * the value as refuse_invalid() does.
	 *
	 * @param mixed  $check      Null to let WordPress store it, or another filter's answer.
	 * @param int    $object_id  The post.
	 * @param string $meta_key   The meta key.
	 * @param mixed  $meta_value The value, unslashed.
	 * @return mixed False to refuse; otherwise $check.
	 */
	public static function refuse_add( mixed $check, int $object_id, string $meta_key, mixed $meta_value ): mixed {
		if ( null === $check && null !== self::field_of( $object_id, $meta_key ) && metadata_exists( 'post', $object_id, $meta_key ) ) {
			return false;
		}
		return self::refuse_invalid( $check, $object_id, $meta_key, $meta_value );
	}

	/**
	 * Refuses to store a field of a project or topic that validate() rejects, so no
	 * route can put a value of the wrong kind into a field. A valid value is stored as
	 * given; a revision's copy isn't checked, since its post was.
	 *
	 * A number or true or false may arrive as WordPress stores it, as text ("2025",
	 * "1"), as it does when a revision is restored; it is read the way Store reads it
	 * back. A refusal only makes WordPress's function return false, with no message:
	 * code that writes a field calls validate() first to say what's wrong.
	 *
	 * @param mixed  $check      Null to let WordPress store it, or another filter's answer.
	 * @param int    $object_id  The post.
	 * @param string $meta_key   The meta key.
	 * @param mixed  $meta_value The value, unslashed.
	 * @return mixed False to refuse; otherwise $check.
	 */
	public static function refuse_invalid( mixed $check, int $object_id, string $meta_key, mixed $meta_value ): mixed {
		$field = null === $check ? self::field_of( $object_id, $meta_key ) : null;
		if ( null === $field ) {
			return $check;
		}
		list( $post_type, $name ) = $field;
		$value                    = self::typed( $meta_value, self::fields( $post_type )[ $name ]['type'] );
		return true === self::validate( $post_type, $name, $value ) ? $check : false;
	}

	/**
	 * A stored value with its schema's type: WordPress keeps a number or a boolean
	 * stored on its own as text. Only text in the form WordPress itself writes counts
	 * ("2025", not "2025.0" or " 2025"; "1" or "" for true or false); anything else is
	 * left as it is. Lists and objects come back as stored.
	 *
	 * @param mixed  $value The stored value.
	 * @param string $type  The schema's type.
	 * @return mixed
	 */
	public static function typed( mixed $value, string $type ): mixed {
		$number = is_string( $value ) && is_numeric( $value ) && (string) ( $value + 0 ) === $value;
		return match ( true ) {
			'number' === $type && $number                      => $value + 0,
			'integer' === $type && $number && ! str_contains( $value, '.' ) => (int) $value,
			'boolean' === $type && '1' === $value              => true,
			'boolean' === $type && '' === $value               => false,
			default                                            => $value,
		};
	}

	/**
	 * The post type and field a meta key is, on a project or topic, or null.
	 *
	 * @param int    $object_id The post.
	 * @param string $meta_key  The meta key.
	 * @return array{0: string, 1: string}|null
	 */
	private static function field_of( int $object_id, string $meta_key ): ?array {
		if ( ! str_starts_with( $meta_key, self::PREFIX ) ) {
			return null;
		}
		$post = get_post( $object_id );
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, array( Post_Types::PROJECT, Post_Types::TOPIC ), true ) ) {
			return null;
		}
		$name = substr( $meta_key, strlen( self::PREFIX ) );
		return array_key_exists( $name, self::fields( $post->post_type ) ) ? array( $post->post_type, $name ) : null;
	}

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
	 * sources (rule 5). Pages will turn the ID back into a slug when they're drawn
	 * (Phase 4).
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
	 * Checks a value against its field's schema: with WordPress's own validator, then
	 * for the exact type of every part of it.
	 *
	 * WordPress's validator is written for REST input, which it converts afterwards, so
	 * it lets "2025" pass as a number, "false" as a boolean and "a, b" as a list. A field
	 * here is stored as given, so it must already be what its schema says.
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
		$valid = rest_validate_value_from_schema( $value, $schema, $name );
		if ( true !== $valid ) {
			return $valid;
		}
		$wrong = self::wrong_type( $value, $schema, $name );
		if ( null !== $wrong ) {
			return new \WP_Error( 'firehouse_wrong_type', sprintf( '%1$s has to be %2$s.', $wrong[0], self::TYPE_WORDS[ $wrong[1] ] ?? $wrong[1] ) );
		}
		return true;
	}

	/**
	 * The first part of a value whose PHP type isn't its schema's, if any. A list must
	 * be a PHP list; an object must be an array that isn't one, or empty, since an
	 * empty JSON object decodes to an empty array.
	 *
	 * @param mixed                $value  The value.
	 * @param array<string, mixed> $schema Its schema.
	 * @param string               $path   Where it is, for the message.
	 * @return array{0: string, 1: string}|null Where, and the type it should be.
	 */
	public static function wrong_type( mixed $value, array $schema, string $path ): ?array {
		$type = $schema['type'] ?? null;
		$ok   = match ( $type ) {
			'string'  => is_string( $value ),
			'boolean' => is_bool( $value ),
			'integer' => is_int( $value ),
			'number'  => is_int( $value ) || is_float( $value ),
			'array'   => is_array( $value ) && array_is_list( $value ),
			'object'  => is_array( $value ) && ( array() === $value || ! array_is_list( $value ) ),
			default   => true,
		};
		if ( ! $ok ) {
			return array( $path, (string) $type );
		}
		if ( 'array' === $type ) {
			foreach ( $value as $index => $item ) {
				$wrong = self::wrong_type( $item, $schema['items'] ?? array(), sprintf( '%s[%d]', $path, $index ) );
				if ( null !== $wrong ) {
					return $wrong;
				}
			}
		}
		if ( 'object' === $type ) {
			foreach ( $value as $key => $item ) {
				$wrong = isset( $schema['properties'][ $key ] ) ? self::wrong_type( $item, $schema['properties'][ $key ], $path . '.' . $key ) : null;
				if ( null !== $wrong ) {
					return $wrong;
				}
			}
		}
		return null;
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
			'auth_callback'     => '__return_false',
		);
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
