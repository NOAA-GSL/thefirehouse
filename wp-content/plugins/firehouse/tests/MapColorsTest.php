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
	 * Each theme's ramp has four stops, and every colour mix() reads is #rrggbb: on
	 * anything else the two languages part ways (with #fff, JavaScript writes "NaN"
	 * where hexdec() reads 0).
	 */
	public function test_each_ramp_is_written_as_mix_reads_it(): void {
		foreach ( array( 'dark', 'light' ) as $theme ) {
			$ramp = Map_Colors::ramp( $theme );
			$this->assertCount( 4, $ramp['stops'], $theme );
			foreach ( array( ...$ramp['stops'], $ramp['empty']['fill'], $ramp['empty']['stroke'], $ramp['selected'] ) as $colour ) {
				$this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/D', $colour, $theme );
			}
		}
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
