<?php
/**
 * Tests for finding text shaped like an email address.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Firehouse\Domain\Email_Shape;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * PHP passes the same cases as scripts/email-shape.mjs, and finds the same paths.
 */
final class EmailShapeTest extends DomainTestCase {

	/**
	 * Each shared case.
	 *
	 * @param array<string, mixed> $shared_case The case.
	 */
	#[DataProvider( 'cases' )]
	public function test_shared_case( array $shared_case ): void {
		$this->assertSame( $shared_case['email'], Email_Shape::has_email( $shared_case['text'] ), 'email' );
		$this->assertSame( $shared_case['team'], Email_Shape::is_team_email( $shared_case['text'] ), 'team' );
	}

	/**
	 * The cases in shared/cases/email-shape.json.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function cases(): array {
		return Cases::provider( 'email-shape.json' );
	}

	/**
	 * Each shared `find` case: the paths found, in any order.
	 *
	 * @param array<string, mixed> $find_case The case.
	 */
	#[DataProvider( 'find_cases' )]
	public function test_shared_find_case( array $find_case ): void {
		$found    = Email_Shape::find( $find_case['blocks'] );
		$expected = $find_case['paths'];
		sort( $found );
		sort( $expected );

		$this->assertSame( $expected, $found );
	}

	/**
	 * The `find` cases in shared/cases/email-shape.json.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function find_cases(): array {
		$cases = array();
		foreach ( Cases::read( 'email-shape.json' )['find'] as $find_case ) {
			$cases[ $find_case['name'] ] = array( $find_case );
		}
		return $cases;
	}
}
