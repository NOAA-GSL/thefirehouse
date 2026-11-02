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
	 * Drops `year` from a Firehouse request's query variables.
	 *
	 * @param array<string, mixed> $vars The request's query variables.
	 * @return array<string, mixed>
	 */
	public static function request( array $vars ): array {
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
			if ( isset( $vars[ $type ] ) ) {
				return true;
			}
		}
		$pagename = $vars['pagename'] ?? null;
		return is_string( $pagename ) && ( self::ROOT_PATH === $pagename || str_starts_with( $pagename, self::ROOT_PATH . '/' ) );
	}
}
