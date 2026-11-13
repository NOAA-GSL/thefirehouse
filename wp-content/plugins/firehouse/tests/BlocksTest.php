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
use PHPUnit\Framework\Attributes\DataProvider;

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
	 * Every block registers from its own folder.
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

		Blocks::register();

		$this->assertSame( array_map( static fn( string $name ): string => dirname( __DIR__ ) . '/blocks/' . $name, array( 'about-audiences', 'about-cta', 'about-intro', 'about-mission', 'about-process', 'about-sections', 'about-team', 'hero', 'not-found', 'project', 'stats', 'submit-band', 'subnav', 'top-needs', 'topic' ) ), $registered );
		// The manifest comes first, so no block reads its block.json.
		$this->assertSame( array( array( dirname( __DIR__ ) . '/blocks', dirname( __DIR__ ) . '/blocks/blocks-manifest.php', 0 ) ), $collections );
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
	 * WordPress registers the blocks from it, not from the files.
	 */
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
	 * since a string followed by one would call the function it names.
	 */
	public function test_the_manifest_holds_only_literals(): void {
		$kept = array();
		foreach ( \PhpToken::tokenize( (string) file_get_contents( self::BLOCKS . 'blocks-manifest.php' ) ) as $token ) {
			if ( $token->is( array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ) ) ) {
				continue;
			}
			$kept[] = match ( true ) {
				$token->is( T_OPEN_TAG )                                                    => '<?php',
				$token->is( T_CONSTANT_ENCAPSED_STRING ) && "'ABSPATH'" !== $token->text => 'S',
				$token->is( T_LNUMBER )                                                     => 'N',
				default                                                                     => $token->text,
			};
		}
		$this->assertMatchesRegularExpression(
			'/\A<\?php declare \( strict_types = N \) ; if \( ! defined \( \'ABSPATH\' \) \) \{ exit ; \} return array \((?: (?:array \(|\)|,|=>|S|N|true|false|null))* ;\z/',
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
	 * inserter, and its view script, if it has one, declares the Interactivity API.
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
			$this->assertTrue( $block['supports']['interactivity'] );
			$asset = require $folder . 'view.asset.php';
			$this->assertSame( array( '@wordpress/interactivity' ), $asset['dependencies'] );
			$this->assertSame( (string) filemtime( $folder . 'view.js' ), $asset['version'] );
		}
	}
}
