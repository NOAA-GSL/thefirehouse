<?php
/**
 * JavaScript's rules, where PHP's differ and the TypeScript relies on them.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Truthiness and whitespace as JavaScript has them.
 *
 * The TypeScript this plugin ports tests values with `if (value)` and trims with
 * `trim()`. PHP's nearest equivalents differ at the edges: empty() calls "0" and an
 * empty array empty, which JavaScript calls true, and trim() leaves the non-breaking
 * and other Unicode spaces JavaScript removes. Where the two must agree, domain/ uses
 * these.
 */
final class Js {

	/**
	 * JavaScript's whitespace, the set `\s` and trim() use, for a PCRE character class
	 * with the `u` modifier.
	 */
	public const SPACE = '\t\n\x{0B}\f\r \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

	/**
	 * Whether JavaScript would treat a value as true: anything but null, false, 0, NaN
	 * and the empty string.
	 *
	 * @param mixed $value The value.
	 */
	public static function truthy( mixed $value ): bool {
		if ( is_float( $value ) && is_nan( $value ) ) {
			return false;
		}
		return ! in_array( $value, array( null, false, '', 0, 0.0 ), true );
	}

	/**
	 * Trims JavaScript's whitespace from both ends.
	 *
	 * @param string $text The text, as valid UTF-8.
	 */
	public static function trim( string $text ): string {
		return (string) preg_replace( '/^[' . self::SPACE . ']+|[' . self::SPACE . ']+$/uD', '', $text );
	}
}
