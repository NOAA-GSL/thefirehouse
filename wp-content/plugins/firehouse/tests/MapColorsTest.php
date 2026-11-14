<?php
/**
 * Runs shared/cases/coverage-map.json against domain/class-map-colors.php.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Firehouse\Domain\Js;
use Firehouse\Domain\Map_Colors;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Map_Colors, against the same cases as src/design-system/heat.ts, and Js::round(),
 * which its mix rounds with.
 */
final class MapColorsTest extends DomainTestCase {

	/**
	 * One shared case.
	 *
	 * @param array<string, mixed> $shared_case The case: `fn`, `args` and `expected`.
	 */
	#[DataProvider( 'cases' )]
	public function test_shared_case( array $shared_case ): void {
		$args   = $shared_case['args'];
		$result = match ( $shared_case['fn'] ) {
			'heat' => Map_Colors::heat( ...$args ),
			'mix'  => Map_Colors::mix( ...$args ),
		};

		$this->assertSame( Cases::canonical( $shared_case['expected'] ), Cases::canonical( $result ) );
	}

	/**
	 * The cases.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function cases(): array {
		return Cases::provider( 'coverage-map.json' );
	}

	/**
	 * Each theme's ramp is the shared file's, as the site reads it.
	 */
	public function test_ramps_are_the_shared_files(): void {
		$file = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/shared/coverage-map.json' ), true );
		$this->assertSame( $file['ramps']['dark'], Map_Colors::ramp( 'dark' ) );
		$this->assertSame( $file['ramps']['light'], Map_Colors::ramp( 'light' ) );
		$this->assertSame( array( 'stops', 'empty', 'selected', 'label' ), array_keys( Map_Colors::ramp( 'light' ) ) );
	}

	/**
	 * Math.round()'s answers: a tie goes up, toward positive infinity, and a number a
	 * hair under a half goes down, where PHP's round() before 8.4 took it up.
	 */
	public function test_round_is_javascripts(): void {
		$cases = array(
			array( 2.5, 3.0 ),
			array( -2.5, -2.0 ),
			array( 127.5, 128.0 ),
			array( 166.49999999999997, 166.0 ),
			array( 0.49999999999999994, 0.0 ),
			array( 2.4, 2.0 ),
			array( -2.6, -3.0 ),
			array( 255.0, 255.0 ),
			array( 0.0, 0.0 ),
		);
		foreach ( $cases as [ $value, $expected ] ) {
			$this->assertSame( $expected, Js::round( $value ), (string) $value );
		}
	}
}
