<?php
/**
 * The icons the plugin ships, checked against shared/icon-svg.json, as
 * scripts/plugin-assets.mjs checks them before writing: the templates inline these
 * files into GSL's pages, so each may hold only Lucide's plain shapes.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every file in assets/icons/, against the shared list.
 */
final class IconsTest extends TestCase {

	/**
	 * The folder the icons ship in.
	 */
	private const ICONS = __DIR__ . '/../assets/icons/';

	/**
	 * Decodes one file in shared/.
	 *
	 * @param string $name The file name.
	 * @return array<string, mixed>
	 */
	private static function shared( string $name ): array {
		return json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/shared/' . $name ), true, 512, JSON_THROW_ON_ERROR );
	}

	/**
	 * Every icon file, keyed by its name.
	 *
	 * @return array<string, array{string}>
	 */
	public static function icons(): array {
		$icons = array();
		foreach ( (array) glob( self::ICONS . '*.svg' ) as $path ) {
			$icons[ basename( (string) $path, '.svg' ) ] = array( (string) $path );
		}
		return $icons;
	}

	/**
	 * What in one icon falls outside the list: an element, attribute or value it
	 * doesn't allow, or anything that isn't a tag. The same reading as
	 * iconProblems() in scripts/plugin-assets.mjs.
	 *
	 * @param string $svg The file's text.
	 * @return list<string>
	 */
	private static function problems( string $svg ): array {
		$rules    = self::shared( 'icon-svg.json' );
		$problems = array();
		$rest     = (string) preg_replace_callback(
			'/<\/?([^\s\/>]+)((?:\s+[^\s=>]+="[^"]*")*)\s*\/?>/',
			static function ( array $tag ) use ( $rules, &$problems ): string {
				if ( ! in_array( $tag[1], $rules['elements'], true ) ) {
					$problems[] = '<' . $tag[1] . '>';
				}
				preg_match_all( '/\s+([^\s=>]+)="([^"]*)"/', $tag[2], $attributes, PREG_SET_ORDER );
				foreach ( $attributes as $attribute ) {
					$valid = 'xmlns' === $attribute[1]
						? $rules['namespace'] === $attribute[2]
						: 1 === preg_match( '/' . $rules['value'] . '/', $attribute[2] );
					if ( ! in_array( $attribute[1], $rules['attributes'], true ) || ! $valid ) {
						$problems[] = $tag[1] . ' ' . $attribute[1] . '="' . $attribute[2] . '"';
					}
				}
				return '';
			},
			$svg
		);
		if ( '' !== trim( $rest ) ) {
			$problems[] = 'text outside the tags';
		}
		if ( ! str_starts_with( $svg, '<svg ' ) ) {
			$problems[] = 'it doesn’t start with <svg>';
		}
		return $problems;
	}

	/**
	 * An icon holds only what the list allows, and parses as XML.
	 *
	 * @param string $path The icon's file.
	 */
	#[DataProvider( 'icons' )]
	public function test_icon_holds_only_what_the_list_allows( string $path ): void {
		$svg = (string) file_get_contents( $path );
		$this->assertSame( array(), self::problems( $svg ) );
		$this->assertNotFalse( simplexml_load_string( $svg, 'SimpleXMLElement', LIBXML_NONET ) );
	}

	/**
	 * The list refuses what an inlined SVG must never carry.
	 */
	public function test_the_check_refuses_what_could_run_or_load(): void {
		$bad = array(
			'<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>',
			'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
			'<svg xmlns="http://www.w3.org/2000/svg"><use href="https://example.com/x.svg#a"></use></svg>',
			'<svg xmlns="http://www.w3.org/2000/svg"><foreignObject></foreignObject></svg>',
			'<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0" style="fill:url(#a)"></path></svg>',
			'<svg xmlns="http://www.w3.org/1999/xhtml"></svg>',
			'<svg xmlns="http://www.w3.org/2000/svg"><!-- note --></svg>',
		);
		foreach ( $bad as $svg ) {
			$this->assertNotSame( array(), self::problems( $svg ), $svg );
		}
	}

	/**
	 * The folder holds one file per icon the vocabulary names, with the licence, and
	 * each name can be a file name.
	 */
	public function test_the_folder_holds_the_vocabulary_icons_and_the_licence(): void {
		$names = array_keys( self::icons() );
		$rules = self::shared( 'icon-svg.json' );
		sort( $names );
		$wanted = self::shared( 'vocabulary.json' )['icons'];
		sort( $wanted );

		$this->assertSame( $wanted, $names );
		foreach ( $names as $name ) {
			$this->assertMatchesRegularExpression( '/' . $rules['name'] . '/', $name );
		}
		$files = array_values( array_diff( (array) scandir( self::ICONS ), array( '.', '..' ) ) );
		$this->assertSame( array( 'LICENSE.txt' ), array_values( preg_grep( '/\.svg$/', $files, PREG_GREP_INVERT ) ) );
	}
}
