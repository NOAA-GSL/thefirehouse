<?php
/**
 * The base for tests of includes/.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;

/**
 * What DomainTestCase stands in for, plus WordPress's translation functions, which
 * the post types' labels and the refusal messages call.
 */
abstract class WordPressTestCase extends DomainTestCase {

	/**
	 * Stubs the translation functions as well.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\when( 'is_wp_error' )->alias( static fn( mixed $thing ): bool => $thing instanceof \WP_Error );
	}

	/**
	 * Stands in for serialize_blocks() with a copy of WordPress's: each block as its
	 * comment delimiters, its attributes as JSON with `--`, `<`, `>`, `&` and an escaped
	 * quote written as Unicode escapes, and its inner blocks between.
	 */
	protected static function stand_in_serialize_blocks(): void {
		$serialize = static function ( array $block ) use ( &$serialize ): string {
			$content = '';
			$index   = 0;
			foreach ( $block['innerContent'] as $chunk ) {
				$content .= is_string( $chunk ) ? $chunk : $serialize( $block['innerBlocks'][ $index++ ] );
			}
			$name       = str_starts_with( $block['blockName'], 'core/' ) ? substr( $block['blockName'], 5 ) : $block['blockName'];
			$attributes = '';
			if ( ! empty( $block['attrs'] ) ) {
				$json       = (string) json_encode( $block['attrs'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- standing in for wp_json_encode() inside serialize_block_attributes().
				$json       = (string) preg_replace( array( '/--/', '/</', '/>/', '/&/', '/\\\\"/' ), array( '\\u002d\\u002d', '\\u003c', '\\u003e', '\\u0026', '\\u0022' ), $json );
				$attributes = $json . ' ';
			}
			return '' === $content ? sprintf( '<!-- wp:%s %s/-->', $name, $attributes ) : sprintf( '<!-- wp:%1$s %2$s-->%3$s<!-- /wp:%1$s -->', $name, $attributes, $content );
		};
		Functions\when( 'serialize_blocks' )->alias( static fn( array $blocks ): string => implode( '', array_map( $serialize, $blocks ) ) );
	}

	/**
	 * Counts each expectation set with Functions\expect() as an assertion: some tests
	 * check only that a function was called, or never called.
	 */
	protected function tearDown(): void {
		$this->addToAssertionCount( \Mockery::getContainer()->mockery_getExpectationCount() );
		parent::tearDown();
	}
}
