<?php
/**
 * The coverage map's colours, ported from src/design-system/heat.ts.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A region's colours on the coverage map, from its count and the busiest region's,
 * on one of the two ramps in shared/coverage-map.json, one per basemap. heat.ts says
 * why the ramps are blue, normalized against the busiest region, and two.
 * shared/cases/coverage-map.json holds this and heat.ts to the same answers.
 */
final class Map_Colors {

	/**
	 * The decoded file, read once per request.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $data = null;

	/**
	 * One theme's ramp: its `stops`, its `empty` fill and stroke, the `selected`
	 * outline and the `label` colours.
	 *
	 * @param string $theme `dark` or `light`.
	 * @return array<string, mixed>
	 * @throws \RuntimeException When the file is missing or isn't JSON, which is a
	 *                           broken release rather than anything content can cause.
	 */
	public static function ramp( string $theme ): array {
		if ( null === self::$data ) {
			$data = wp_json_file_decode( dirname( __DIR__ ) . '/shared/coverage-map.json', array( 'associative' => true ) );
			if ( ! is_array( $data ) ) {
				throw new \RuntimeException( 'The FireHouse plugin could not read shared/coverage-map.json.' );
			}
			self::$data = $data;
		}
		return self::$data['ramps'][ $theme ];
	}

	/**
	 * A region's fill, outline and fill opacity. A region with none is the ramp's empty
	 * colours, almost unfilled; otherwise its count is placed between one and the
	 * busiest region's, along the ramp's stops, and its outline is pulled toward the
	 * ramp's high-contrast end, so a sparse region still has a crisp edge.
	 *
	 * @param int    $count The region's count.
	 * @param int    $max   The busiest region's count; at 1 or less, any count above 0
	 *                      is the top of the ramp.
	 * @param string $theme `dark` or `light`.
	 * @return array{fill: string, stroke: string, alpha: float}
	 */
	public static function heat( int $count, int $max, string $theme ): array {
		$ramp = self::ramp( $theme );
		if ( 0 === $count ) {
			return array(
				'fill'   => $ramp['empty']['fill'],
				'stroke' => $ramp['empty']['stroke'],
				'alpha'  => 0.05,
			);
		}

		$t      = $max <= 1 ? 1.0 : ( $count - 1 ) / ( $max - 1 );
		$stops  = $ramp['stops'];
		$last   = count( $stops ) - 1;
		$scaled = $t * $last;
		$i      = (int) min( floor( $scaled ), $last - 1 );
		$local  = $scaled - $i;

		return array(
			'fill'   => self::mix( $stops[ $i ], $stops[ $i + 1 ], $local ),
			'stroke' => self::mix( $stops[ max( $i, 1 ) ], $stops[ min( $i + 2, $last ) ], $local ),
			'alpha'  => 0.38 + 0.4 * $t,
		);
	}

	/**
	 * Mixes two `#rrggbb` colours, $t of the way from the first to the second, each
	 * channel rounded as JavaScript rounds it.
	 *
	 * @param string    $a The first colour.
	 * @param string    $b The second.
	 * @param float|int $t How far along, from 0 to 1.
	 */
	public static function mix( string $a, string $b, float|int $t ): string {
		$out = '#';
		foreach ( array( 1, 3, 5 ) as $at ) {
			$from = hexdec( substr( $a, $at, 2 ) );
			$to   = hexdec( substr( $b, $at, 2 ) );
			$out .= str_pad( dechex( (int) Js::round( $from + ( $to - $from ) * $t ) ), 2, '0', STR_PAD_LEFT );
		}
		return $out;
	}
}
