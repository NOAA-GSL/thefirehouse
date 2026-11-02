<?php
/**
 * Reads JSON the way the TypeScript sees it.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decodes JSON into arrays without losing which values were objects.
 *
 * PHP's json_decode() with associative arrays can't tell `{}` from `[]`, or
 * `{"0":"before"}` from `["before"]`, and Normalize relies on that difference as the
 * TypeScript does. The Import screen reads the seed bundle through this, and so do
 * the tests.
 */
final class Json {

	/**
	 * Decodes JSON: lists become lists, objects become associative arrays, and an
	 * object a list would be mistaken for becomes a Json_Object.
	 *
	 * @param string $json The JSON.
	 * @return mixed
	 * @throws \JsonException When it isn't JSON.
	 */
	public static function decode( string $json ): mixed {
		return self::convert( json_decode( $json, false, 512, JSON_THROW_ON_ERROR ) );
	}

	/**
	 * Converts one decoded value, and everything inside it.
	 *
	 * @param mixed $value The value, with objects as stdClass.
	 * @return mixed
	 */
	private static function convert( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'convert' ), $value );
		}
		if ( $value instanceof \stdClass ) {
			$fields = array_map( array( self::class, 'convert' ), get_object_vars( $value ) );
			return array_is_list( $fields ) ? new Json_Object( $fields ) : $fields;
		}
		return $value;
	}
}
