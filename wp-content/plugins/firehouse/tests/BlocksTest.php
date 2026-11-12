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
		$registered = array();
		Functions\when( 'register_block_type' )->alias(
			static function ( string $path ) use ( &$registered ): void {
				$registered[] = $path;
			}
		);

		Blocks::register();

		$this->assertSame( array_map( static fn( string $name ): string => dirname( __DIR__ ) . '/blocks/' . $name, array( 'hero', 'not-found', 'project', 'stats', 'submit-band', 'subnav', 'top-needs', 'topic' ) ), $registered );
	}

	/**
	 * The folders in blocks/ are the blocks Blocks registers, so none is left out.
	 */
	public function test_every_folder_is_registered(): void {
		$folders = array_values( array_diff( (array) scandir( self::BLOCKS ), array( '.', '..' ) ) );
		$this->assertSame( Blocks::NAMES, $folders );
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
