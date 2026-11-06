<?php
/**
 * WordPress's query variables, on Firehouse pages.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the explorer's `?year=` from meaning what WordPress means by it (rule 7).
 *
 * `year` is one of WordPress's public query variables. Left in,
 * /firehouse/projects/?year=2025 would ask for projects created in WordPress in 2025,
 * which is the migration date for all of them, and a Firehouse page with `?year=` would
 * find nothing. It is dropped from the main query on Firehouse requests, and the
 * explorer reads the parameter itself.
 *
 * The project and topic query variables are dropped too when they don't name a post,
 * as WordPress drops its own (rule 13). `?fh_project[]=x` would otherwise reach
 * WordPress's query as an array and stop the page with an error, on any address, and
 * an empty `?fh_topic=` would make any page look like a Firehouse request.
 */
final class Query {

	/**
	 * The Firehouse page tree's root: the landing page, at /firehouse/.
	 */
	public const ROOT_PATH = 'firehouse';

	/**
	 * Hooks the filter.
	 */
	public static function hook(): void {
		add_filter( 'request', array( self::class, 'request' ) );
	}

	/**
	 * Drops a project or topic query variable that names no post, then `year` from a
	 * Firehouse request's query variables.
	 *
	 * @param array<string, mixed> $vars The request's query variables.
	 * @return array<string, mixed>
	 */
	public static function request( array $vars ): array {
		foreach ( array( Post_Types::PROJECT, Post_Types::TOPIC ) as $type ) {
			if ( array_key_exists( $type, $vars ) && ! self::names_a_post( $vars[ $type ] ) ) {
				unset( $vars[ $type ] );
			}
		}
		if ( array_key_exists( 'year', $vars ) && self::is_firehouse( $vars ) ) {
			unset( $vars['year'] );
		}
		return $vars;
	}

	/**
	 * Whether a request is for a Firehouse page: a project, a topic, the project
	 * archive, or a page in the Firehouse tree.
	 *
	 * @param array<string, mixed> $vars The request's query variables.
	 */
	public static function is_firehouse( array $vars ): bool {
		$types = array( Post_Types::PROJECT, Post_Types::TOPIC );
		if ( in_array( $vars['post_type'] ?? null, $types, true ) ) {
			return true;
		}
		foreach ( $types as $type ) {
			if ( self::names_a_post( $vars[ $type ] ?? null ) ) {
				return true;
			}
		}
		$pagename = $vars['pagename'] ?? null;
		return is_string( $pagename ) && self::under_root( $pagename );
	}

	/**
	 * Whether a path is the Firehouse root or under it.
	 *
	 * @param string $path A path without its leading or trailing slash.
	 */
	public static function under_root( string $path ): bool {
		return self::ROOT_PATH === $path || str_starts_with( $path, self::ROOT_PATH . '/' );
	}

	/**
	 * Whether a query variable's value could name a post: a string WordPress doesn't
	 * read as empty, which it ignores.
	 *
	 * @param mixed $value The value.
	 */
	private static function names_a_post( mixed $value ): bool {
		return is_string( $value ) && '' !== $value && '0' !== $value;
	}
}
