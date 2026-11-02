<?php
/**
 * Reads the shared test cases, as src/content/cases.ts does for the TypeScript.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Firehouse\Domain\Json;
use Firehouse\Domain\Json_Object;
use Firehouse\Domain\Normalize;

/**
 * The cases in shared/cases/, the shared site they run against, and JSON Pointer
 * helpers for the normalize cases.
 *
 * Arguments can name the shared site instead of repeating it: "@site" is site.json
 * after normalization, "@published" its published projects, and "@project:<slug>"
 * one of its projects.
 */
final class Cases {

	/**
	 * One decoded file in shared/cases/, read through Json::decode() so an empty or
	 * numbered object in a case stays an object, as it does in JavaScript.
	 *
	 * @param string $file The file name.
	 * @return array<string, mixed>
	 */
	public static function read( string $file ): array {
		return Json::decode( (string) file_get_contents( dirname( __DIR__ ) . '/shared/cases/' . $file ) );
	}

	/**
	 * A file's cases as a PHPUnit data provider, keyed by each case's name.
	 *
	 * @param string $file The file name.
	 * @return array<string, array{array<string, mixed>}>
	 * @throws RuntimeException When two cases share a name, which would drop one.
	 */
	public static function provider( string $file ): array {
		$cases = array();
		foreach ( self::read( $file )['cases'] as $case ) {
			$key = ( $case['fn'] ?? '' ) . ': ' . $case['name'];
			if ( isset( $cases[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A test's message goes to the test report, never to a page.
				throw new RuntimeException( 'Two cases share a name in ' . $file . ': ' . $key );
			}
			$cases[ $key ] = array( $case );
		}
		return $cases;
	}

	/**
	 * A fresh copy of site.json, before normalization.
	 *
	 * @return array<string, mixed>
	 */
	public static function raw_site(): array {
		return self::read( 'site.json' );
	}

	/**
	 * The site in site.json, after normalization.
	 *
	 * @return array<string, mixed>
	 */
	public static function site(): array {
		return Normalize::site_content( self::raw_site() );
	}

	/**
	 * Turns "@site", "@published" and "@project:<slug>" into what they name.
	 *
	 * @param mixed                $arg     A case argument.
	 * @param array<string, mixed> $content The normalized site.
	 * @return mixed
	 * @throws \RuntimeException When a named project isn't in site.json.
	 */
	public static function resolve_arg( mixed $arg, array $content ): mixed {
		if ( '@site' === $arg ) {
			return $content;
		}
		if ( '@published' === $arg ) {
			return array_values( array_filter( $content['projects'], static fn( array $project ): bool => ! empty( $project['published'] ) ) );
		}
		if ( is_string( $arg ) && str_starts_with( $arg, '@project:' ) ) {
			$slug = substr( $arg, strlen( '@project:' ) );
			foreach ( $content['projects'] as $project ) {
				if ( $project['slug'] === $slug ) {
					return $project;
				}
			}
			throw new \RuntimeException( 'No such project in site.json.' );
		}
		return $arg;
	}

	/**
	 * One field from each item, as JavaScript's `map(item => item[key])` gives it: an
	 * item without the field gives null rather than being skipped, as array_column()
	 * would skip it.
	 *
	 * @param array<mixed> $items The items.
	 * @param string       $key   The field.
	 * @return list<mixed>
	 */
	public static function pluck( array $items, string $key ): array {
		return array_values( array_map( static fn( mixed $item ): mixed => is_array( $item ) ? ( $item[ $key ] ?? null ) : null, $items ) );
	}

	/**
	 * A value with every object's keys sorted, so two values compare as JSON does:
	 * the same keys and values, in any order.
	 *
	 * @param mixed $value A decoded JSON value, or a result in that shape.
	 * @return mixed
	 */
	public static function canonical( mixed $value ): mixed {
		if ( $value instanceof Json_Object ) {
			$value = $value->getArrayCopy();
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$value = array_map( array( self::class, 'canonical' ), $value );
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		return $value;
	}

	/**
	 * The value at a JSON Pointer, and whether anything is there.
	 *
	 * @param mixed  $document The document.
	 * @param string $pointer  The pointer.
	 * @return array{bool, mixed}
	 */
	public static function pointer_get( mixed $document, string $pointer ): array {
		$node = $document;
		foreach ( self::tokens( $pointer ) as $token ) {
			if ( ! is_array( $node ) || ! array_key_exists( $token, $node ) ) {
				return array( false, null );
			}
			$node = $node[ $token ];
		}
		return array( true, $node );
	}

	/**
	 * Sets the value at a JSON Pointer. Its parent has to exist already.
	 *
	 * @param array<mixed> $document The document, changed in place.
	 * @param string       $pointer  The pointer.
	 * @param mixed        $value    The new value.
	 */
	public static function pointer_set( array &$document, string $pointer, mixed $value ): void {
		$tokens        = self::tokens( $pointer );
		$last          = array_pop( $tokens );
		$node          = &self::parent( $document, $tokens, $pointer );
		$node[ $last ] = $value;
	}

	/**
	 * Removes the value at a JSON Pointer. A list closes the gap, as splice does.
	 *
	 * @param array<mixed> $document The document, changed in place.
	 * @param string       $pointer  The pointer.
	 */
	public static function pointer_remove( array &$document, string $pointer ): void {
		$tokens   = self::tokens( $pointer );
		$last     = array_pop( $tokens );
		$node     = &self::parent( $document, $tokens, $pointer );
		$was_list = array_is_list( $node );
		unset( $node[ $last ] );
		if ( $was_list ) {
			$node = array_values( $node );
		}
	}

	/**
	 * The array a pointer's last token is in, by reference. A case that points
	 * somewhere that isn't there is a broken case, as it is in cases.ts.
	 *
	 * @param array<mixed> $document The document.
	 * @param string[]     $tokens   The tokens before the last one.
	 * @param string       $pointer  The whole pointer, for the message.
	 * @return array<mixed>
	 * @throws RuntimeException When the parent isn't there.
	 */
	private static function &parent( array &$document, array $tokens, string $pointer ): array {
		$node = &$document;
		foreach ( $tokens as $token ) {
			if ( ! is_array( $node ) || ! array_key_exists( $token, $node ) || ! is_array( $node[ $token ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A test's message goes to the test report, never to a page.
				throw new RuntimeException( "Can't reach " . $pointer . '.' );
			}
			$node = &$node[ $token ];
		}
		return $node;
	}

	/**
	 * A pointer's reference tokens, unescaped.
	 *
	 * @param string $pointer The pointer.
	 * @return list<string>
	 */
	private static function tokens( string $pointer ): array {
		if ( '' === $pointer ) {
			return array();
		}
		return array_map(
			static fn( string $token ): string => str_replace( array( '~1', '~0' ), array( '/', '~' ), $token ),
			explode( '/', substr( $pointer, 1 ) )
		);
	}
}
