<?php
/**
 * The base for tests of domain/.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Stands in for the few WordPress functions domain/ calls: the escaping functions,
 * for refusal messages, and wp_json_file_decode().
 */
abstract class DomainTestCase extends TestCase {

	/**
	 * Starts Brain Monkey and stubs the functions.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_json_file_decode' )->alias(
			static fn( string $filename, array $options = array() ): mixed => json_decode( (string) file_get_contents( $filename ), ! empty( $options['associative'] ) )
		);
	}

	/**
	 * Stops Brain Monkey.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
