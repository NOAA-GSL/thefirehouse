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
	 * Counts each expectation set with Functions\expect() as an assertion: some tests
	 * check only that a function was called, or never called.
	 */
	protected function tearDown(): void {
		$this->addToAssertionCount( \Mockery::getContainer()->mockery_getExpectationCount() );
		parent::tearDown();
	}
}
