<?php
/**
 * Text shaped like an email address, by shared/email-shape.json.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds text shaped like an email address where none belongs, by the pattern the
 * survey import and the seed bundle script use too (scripts/email-shape.mjs). It is
 * the server's backstop for rule 2: a submitter's email never reaches the site. The
 * cases both languages must pass are in shared/cases/email-shape.json.
 */
final class Email_Shape {

	/**
	 * The decoded file, read once per request.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $shape = null;

	/**
	 * Whether text holds something shaped like an email address.
	 *
	 * @param string $text The text.
	 */
	public static function has_email( string $text ): bool {
		return 1 === preg_match( self::regex( 'pattern' ), $text );
	}

	/**
	 * Whether the whole of a text is a team member's work address, the one email the
	 * site shows on purpose.
	 *
	 * @param string $text The text.
	 */
	public static function is_team_email( string $text ): bool {
		return 1 === preg_match( self::regex( 'teamEmail' ), $text );
	}

	/**
	 * The path of every string in the content that holds something shaped like an
	 * email address, except an About page team member's own `email` field when the
	 * whole of it is a team address.
	 *
	 * @param array<string, mixed> $blocks The content, by block: settings, about and the rest.
	 * @return list<string> Each path, such as "projects.3.abstract". Never the value.
	 */
	public static function find( array $blocks ): array {
		$found = array();
		foreach ( $blocks as $name => $block ) {
			self::scan( $block, array( (string) $name ), $found );
		}
		return $found;
	}

	/**
	 * Looks through one value, and everything inside it.
	 *
	 * @param mixed    $value The value.
	 * @param string[] $path  Where it is.
	 * @param string[] $found The paths found so far; this adds to them.
	 */
	private static function scan( mixed $value, array $path, array &$found ): void {
		if ( is_string( $value ) ) {
			$team_field = 5 === count( $path ) && array( 'about', 'team', 'members' ) === array_slice( $path, 0, 3 ) && 1 === preg_match( '/^\d+$/', $path[3] ) && 'email' === $path[4];
			if ( self::has_email( $value ) && ! ( $team_field && self::is_team_email( $value ) ) ) {
				$found[] = implode( '.', $path );
			}
			return;
		}
		if ( $value instanceof Json_Object ) {
			$value = $value->getArrayCopy();
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $inner ) {
				self::scan( $inner, array_merge( $path, array( (string) $key ) ), $found );
			}
		}
	}

	/**
	 * One of the file's patterns, as a PHP regular expression.
	 *
	 * @param string $name `pattern` or `teamEmail`.
	 * @throws \RuntimeException When the file is missing or isn't the shape file, which
	 *                           is a broken release rather than anything content can cause.
	 */
	private static function regex( string $name ): string {
		if ( null === self::$shape ) {
			$shape = wp_json_file_decode( dirname( __DIR__ ) . '/shared/email-shape.json', array( 'associative' => true ) );
			if ( ! is_array( $shape ) || ! is_string( $shape['pattern'] ?? null ) || ! is_string( $shape['teamEmail'] ?? null ) ) {
				throw new \RuntimeException( 'The Firehouse plugin could not read shared/email-shape.json.' );
			}
			self::$shape = $shape;
		}
		// D: a closing $ means the end, as in JavaScript, not the end or a final newline.
		return '~' . self::$shape[ $name ] . '~iuD';
	}
}
