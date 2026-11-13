<?php
/**
 * The icons the plugin ships, checked against shared/icon-svg.json, as
 * scripts/plugin-assets.mjs checks them before writing: the templates inline these
 * into GSL's pages, so each may hold only Lucide's plain shapes.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every icon in assets/icons/icons.php, against the shared list.
 */
final class IconsTest extends TestCase {

	/**
	 * The folder the icons ship in.
	 */
	private const ICONS = __DIR__ . '/../assets/icons/';

	/**
	 * What the generated file returns: each icon's SVG, by name.
	 *
	 * @return mixed
	 */
	private static function file(): mixed {
		return require self::ICONS . 'icons.php';
	}

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
	 * Every icon, keyed by its name.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function icons(): array {
		$icons = array();
		foreach ( (array) self::file() as $name => $svg ) {
			$icons[ (string) $name ] = array( $svg );
		}
		return $icons;
	}

	/**
	 * What in one icon falls outside the list: an element, attribute or value it
	 * doesn't allow, or anything that isn't a tag. The same reading as
	 * iconProblems() in scripts/plugin-assets.mjs: its patterns end at `$` as
	 * JavaScript's do, not before a final newline (`D`).
	 *
	 * @param string $svg One icon's SVG.
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
						: 1 === preg_match( '/' . $rules['value'] . '/D', $attribute[2] );
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
	 * An icon is a string that holds only what the list allows, and parses as XML.
	 *
	 * @param mixed $svg The icon's SVG.
	 */
	#[DataProvider( 'icons' )]
	public function test_icon_holds_only_what_the_list_allows( mixed $svg ): void {
		$this->assertIsString( $svg );
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
			'<svg xmlns="http://www.w3.org/2000/svg" width="24' . "\n" . '"></svg>',
		);
		foreach ( $bad as $svg ) {
			$this->assertNotSame( array(), self::problems( $svg ), $svg );
		}
	}

	/**
	 * The file holds an icon for each name the vocabulary has and no other, each name
	 * as the list allows, and the folder holds only the file and the licence.
	 */
	public function test_the_folder_holds_the_vocabulary_icons_and_the_licence(): void {
		$this->assertIsArray( self::file() );
		$names = array_keys( self::file() );
		$rules = self::shared( 'icon-svg.json' );
		sort( $names );
		$wanted = self::shared( 'vocabulary.json' )['icons'];
		sort( $wanted );

		$this->assertSame( $wanted, $names );
		foreach ( $names as $name ) {
			$this->assertMatchesRegularExpression( '/' . $rules['name'] . '/D', $name );
		}
		$files = array_values( array_diff( (array) scandir( self::ICONS ), array( '.', '..' ) ) );
		sort( $files );
		$this->assertSame( array( 'LICENSE.txt', 'icons.php' ), $files );
	}

	/**
	 * The file is its header and one array of string literals, with nothing that runs,
	 * whatever array it returns: Icons includes it on every page that draws an icon, so
	 * code added around the same array would run there. Read as tokens, never included.
	 * A change to the header that scripts/plugin-assets.mjs writes changes this test too.
	 */
	public function test_the_file_holds_only_string_literals(): void {
		$kept = array();
		foreach ( \PhpToken::tokenize( (string) file_get_contents( self::ICONS . 'icons.php' ) ) as $token ) {
			if ( $token->is( array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ) ) ) {
				continue;
			}
			$kept[] = match ( true ) {
				$token->is( T_OPEN_TAG )                                                    => '<?php',
				$token->is( T_CONSTANT_ENCAPSED_STRING ) && "'ABSPATH'" !== $token->text => 'S',
				default                                                                     => $token->text,
			};
		}
		$this->assertMatchesRegularExpression(
			'/\A<\?php declare \( strict_types = 1 \) ; if \( ! defined \( \'ABSPATH\' \) \) \{ exit ; \} return array \((?: S => S ,)* \) ;\z/',
			implode( ' ', $kept )
		);
	}
}
