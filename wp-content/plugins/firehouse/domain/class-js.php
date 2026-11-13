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
		// Unchanged rather than emptied if the text isn't valid UTF-8, which the u
		// modifier refuses.
		return preg_replace( '/^[' . self::SPACE . ']+|[' . self::SPACE . ']+$/uD', '', $text ) ?? $text;
	}

	/**
	 * A string as JavaScript's Number() reads it: trimmed, empty as 0, a decimal with
	 * an optional sign, point and exponent, Infinity, or an unsigned 0x, 0o or 0b
	 * integer; NAN for anything else, such as "12px", "1_000" or non-ASCII digits. Null
	 * is 0, as Number(null) is.
	 *
	 * @param string|null $text The text.
	 */
	public static function number( ?string $text ): float {
		$text = null === $text ? '' : self::trim( $text );
		if ( '' === $text ) {
			return 0.0;
		}
		if ( 1 === preg_match( '/^([+-]?)Infinity$/D', $text, $sign ) ) {
			return '-' === $sign[1] ? -INF : INF;
		}
		if ( 1 === preg_match( '/^[+-]?(?:[0-9]+\.?[0-9]*|\.[0-9]+)(?:[eE][+-]?[0-9]+)?$/D', $text ) ) {
			return (float) $text;
		}
		if ( 1 === preg_match( '/^0([xXoObB])([0-9a-fA-F]+)$/D', $text, $parts ) ) {
			$bases  = array(
				'x' => 16,
				'o' => 8,
				'b' => 2,
			);
			$base   = $bases[ strtolower( $parts[1] ) ];
			$digits = strtolower( $parts[2] );
			if ( strspn( $digits, substr( '0123456789abcdef', 0, $base ) ) !== strlen( $digits ) ) {
				return NAN;
			}
			$value = 0.0;
			foreach ( str_split( $digits ) as $digit ) {
				$value = $value * $base + hexdec( $digit );
			}
			return $value;
		}
		return NAN;
	}
}
