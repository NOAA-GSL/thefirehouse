<?php
/**
 * Tests for the plugin's blocks.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Blocks;
use Firehouse\Coverage_Map;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;

/**
 * Each block registers from its folder, and its block.json names the files that
 * render it and run it.
 */
final class BlocksTest extends WordPressTestCase {

	/**
	 * The blocks folder.
	 */
	private const BLOCKS = __DIR__ . '/../blocks/';

	/**
	 * Every block registers from its own folder, and the two script modules the view
	 * scripts import by name: the site's search, and the map's drawing, minified unless
	 * `SCRIPT_DEBUG` is on.
	 */
	public function test_each_block_registers_from_its_folder(): void {
		$registered  = array();
		$collections = array();
		Functions\when( 'register_block_type' )->alias(
			static function ( string $path ) use ( &$registered ): void {
				$registered[] = $path;
			}
		);
		Functions\when( 'wp_register_block_metadata_collection' )->alias(
			static function ( string $path, string $manifest ) use ( &$collections, &$registered ): void {
				$collections[] = array( $path, $manifest, count( $registered ) );
			}
		);

		$modules = array();
		Functions\when( 'plugins_url' )->alias( static fn( string $path ): string => 'https://example.test/wp-content/plugins/firehouse/' . $path );
		Functions\when( 'wp_register_script_module' )->alias(
			static function ( string $id, string $src, array $deps, string $version ) use ( &$modules ): void {
				$modules[] = array( $id, $src, $deps, $version );
			}
		);

		Functions\when( 'wp_scripts_get_suffix' )->justReturn( '.min' );

		Blocks::register();

		// The explorer's view script imports the site's search by this name, and the
		// coverage map's the map's drawing.
		$version = static fn( string $path ): string => \Firehouse\VERSION . '-' . filemtime( dirname( __DIR__ ) . '/' . $path );
		$this->assertSame(
			array(
				array( '@firehouse/search', 'https://example.test/wp-content/plugins/firehouse/assets/search/search.js', array(), $version( 'assets/search/search.js' ) ),
				array( Coverage_Map::MAP_MODULE, 'https://example.test/wp-content/plugins/firehouse/assets/map/map.min.js', array(), $version( 'assets/map/map.min.js' ) ),
			),
			$modules
		);

		$this->assertSame( array_map( static fn( string $name ): string => dirname( __DIR__ ) . '/blocks/' . $name, array( 'about-audiences', 'about-cta', 'about-intro', 'about-mission', 'about-process', 'about-sections', 'about-team', 'coverage-map', 'explorer', 'hero', 'not-found', 'project', 'stats', 'submit-band', 'subnav', 'top-needs', 'topic' ) ), $registered );
		// The manifest comes first, so no block reads its block.json.
		$this->assertSame( array( array( dirname( __DIR__ ) . '/blocks', dirname( __DIR__ ) . '/blocks/blocks-manifest.php', 0 ) ), $collections );

		// Under SCRIPT_DEBUG, the readable copy.
		$modules = array();
		Functions\when( 'wp_scripts_get_suffix' )->justReturn( '' );
		Blocks::register();
		$this->assertSame( 'https://example.test/wp-content/plugins/firehouse/assets/map/map.js', $modules[1][1] );
	}

	/**
	 * The block editor loads the blocks' editor script, on WordPress's own packages,
	 * versioned by the plugin and the file, with every block's name and the attributes
	 * its block.json declares before it, and the plugin's translations.
	 */
	public function test_the_editor_script_loads_with_every_block_name(): void {
		$enqueued     = array();
		$inline       = array();
		$translations = array();
		Functions\when( 'plugins_url' )->alias( static fn( string $path ): string => 'https://example.test/wp-content/plugins/firehouse/' . $path );
		Functions\when( 'wp_json_encode' )->alias( static fn( mixed $data ): string => (string) json_encode( $data ) );
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( ...$args ) use ( &$enqueued ): void {
				$enqueued[] = $args;
			}
		);
		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( ...$args ) use ( &$inline ): void {
				$inline[] = $args;
			}
		);
		Functions\when( 'wp_set_script_translations' )->alias(
			static function ( ...$args ) use ( &$translations ): void {
				$translations[] = $args;
			}
		);

		Blocks::enqueue_editor();

		$file = dirname( __DIR__ ) . '/assets/js/block-editor.js';
		$this->assertFileExists( $file );
		$this->assertSame(
			array(
				array(
					Blocks::EDITOR_SCRIPT,
					'https://example.test/wp-content/plugins/firehouse/assets/js/block-editor.js',
					array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ),
					\Firehouse\VERSION . '-' . filemtime( $file ),
					false,
				),
			),
			$enqueued
		);
		$blocks = array();
		foreach ( Blocks::NAMES as $name ) {
			$block                          = json_decode( (string) file_get_contents( self::BLOCKS . $name . '/block.json' ), true );
			$blocks[ 'firehouse/' . $name ] = array_keys( $block['attributes'] ?? array() );
		}
		$this->assertSame( array( 'eyebrow', 'heading', 'body', 'primaryCta', 'secondaryCta' ), $blocks['firehouse/hero'] );
		$this->assertSame( array(), $blocks['firehouse/subnav'] );
		$this->assertSame( array( array( Blocks::EDITOR_SCRIPT, 'window.firehouseBlocks = ' . json_encode( $blocks ) . ';', 'before' ) ), $inline );
		$this->assertSame( array( array( Blocks::EDITOR_SCRIPT, 'firehouse' ) ), $translations );
	}

	/**
	 * The folders in blocks/ are the blocks Blocks registers, so none is left out, and
	 * beside them is only the manifest.
	 */
	public function test_every_folder_is_registered(): void {
		$entries = array_values( array_diff( (array) scandir( self::BLOCKS ), array( '.', '..' ) ) );
		$folders = array_values( array_filter( $entries, static fn( string $entry ): bool => is_dir( self::BLOCKS . $entry ) ) );
		$this->assertSame( Blocks::NAMES, $folders );
		$this->assertSame( array( 'blocks-manifest.php' ), array_values( array_diff( $entries, $folders ) ) );
	}

	/**
	 * The manifest is every block's block.json, as WordPress would decode it, by folder:
	 * WordPress registers the blocks from it, not from the files. It runs the manifest,
	 * so only once the manifest is shown to hold nothing that runs.
	 */
	#[Depends( 'test_the_manifest_holds_only_literals' )]
	public function test_the_manifest_is_each_block_json(): void {
		$expected = array();
		foreach ( Blocks::NAMES as $name ) {
			$expected[ $name ] = json_decode( (string) file_get_contents( self::BLOCKS . $name . '/block.json' ), true, 512, JSON_THROW_ON_ERROR );
		}
		ksort( $expected, SORT_STRING );

		$this->assertSame( $expected, require self::BLOCKS . 'blocks-manifest.php', 'blocks/blocks-manifest.php is out of date with the block.json files. Run: npm run plugin:blocks' );
	}

	/**
	 * The manifest is its header and one array of literals, with nothing that runs:
	 * WordPress includes it on every request, so code added around the same array would
	 * run there. Read as tokens, never included. A parenthesis may only open an array,
	 * since a string followed by one would call the function it names. Strings and whole
	 * numbers stand in as `<str>` and `<int>`, which no token can be, so a constant can't
	 * pass for one. This holds the file to literals; the test above, which depends on
	 * it, holds it to the right ones in the right shape.
	 */
	public function test_the_manifest_holds_only_literals(): void {
		$kept = array();
		foreach ( \PhpToken::tokenize( (string) file_get_contents( self::BLOCKS . 'blocks-manifest.php' ) ) as $token ) {
			if ( $token->is( array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ) ) ) {
				continue;
			}
			$kept[] = match ( true ) {
				$token->is( T_OPEN_TAG )                                                    => '<?php',
				$token->is( T_CONSTANT_ENCAPSED_STRING ) && "'ABSPATH'" !== $token->text => '<str>',
				$token->is( T_LNUMBER )                                                     => '<int>',
				default                                                                     => $token->text,
			};
		}
		$this->assertMatchesRegularExpression(
			'/\A<\?php declare \( strict_types = <int> \) ; if \( ! defined \( \'ABSPATH\' \) \) \{ exit ; \} return array \((?: (?:array \(|\)|,|=>|<str>|(?:- )?<int>|true|false|null))* ;\z/',
			implode( ' ', $kept )
		);
	}

	/**
	 * The blocks, by folder.
	 *
	 * @return array<string, array{string}>
	 */
	public static function blocks(): array {
		return array_combine( Blocks::NAMES, array_map( static fn( string $name ): array => array( $name ), Blocks::NAMES ) );
	}

	/**
	 * A block is named after its folder, renders on the server, stays out of the
	 * inserter, and its view script, if it has one, declares the Interactivity API, and
	 * each module it imports: one it imports only once the page is up, as dynamic.
	 *
	 * @param string $name The block's folder.
	 */
	#[DataProvider( 'blocks' )]
	public function test_a_block_names_its_files( string $name ): void {
		$folder = self::BLOCKS . $name . '/';
		$block  = json_decode( (string) file_get_contents( $folder . 'block.json' ), true );

		$this->assertSame( 'firehouse/' . $name, $block['name'] );
		$this->assertSame( 3, $block['apiVersion'] );
		$this->assertSame( 'firehouse', $block['textdomain'] );
		$this->assertSame( 'file:./render.php', $block['render'] );
		$this->assertFileExists( $folder . 'render.php' );
		$this->assertFalse( $block['supports']['inserter'] );

		if ( isset( $block['viewScriptModule'] ) ) {
			$this->assertSame( 'file:./view.js', $block['viewScriptModule'] );
			// The explorer and the coverage map print their own page and don't ask
			// WordPress to process their directives (Explorer_Page says why); the explorer
			// imports the site's search too, and the coverage map the map's drawing, once
			// the page is up.
			$prints = in_array( $name, array( 'explorer', 'coverage-map' ), true );
			$this->assertSame( ! $prints, $block['supports']['interactivity'] ?? false );
			$asset = require $folder . 'view.asset.php';
			$this->assertSame(
				array(
					'explorer'     => array( '@wordpress/interactivity', '@firehouse/search' ),
					'coverage-map' => array(
						'@wordpress/interactivity',
						array(
							'id'     => Coverage_Map::MAP_MODULE,
							'import' => 'dynamic',
						),
					),
				)[ $name ] ?? array( '@wordpress/interactivity' ),
				$asset['dependencies']
			);
			// WordPress maps only the modules declared, so a script that imports another
			// would fail to load: what it imports is what it declares, in order, and what
			// it imports later, by name, is declared dynamic.
			$script = (string) file_get_contents( $folder . 'view.js' );
			preg_match_all( '/^import\b[^\'"]*[\'"]([^\'"]+)[\'"]/m', $script, $imports );
			$this->assertSame( array_values( array_filter( $asset['dependencies'], 'is_string' ) ), $imports[1] );
			foreach ( array_filter( $asset['dependencies'], 'is_array' ) as $dependency ) {
				$this->assertSame( 'dynamic', $dependency['import'] );
				$this->assertStringContainsString( "export const MAP_MODULE = '" . $dependency['id'] . "';", $script );
				$this->assertStringContainsString( 'await import( MAP_MODULE )', $script );
			}
			$this->assertSame( (string) filemtime( $folder . 'view.js' ), $asset['version'] );
		}
	}
}
