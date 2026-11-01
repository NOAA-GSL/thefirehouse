<?php
/**
 * The shared vocabularies, read from shared/vocabulary.json.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fire phases, regions, publication statuses, topic keys and icon names.
 *
 * The values live in shared/vocabulary.json, which the TypeScript site reads too, so
 * PHP and JavaScript can't disagree about a key (rule 8 in WORDPRESS-PLAN.md). This
 * class only reads that file; nothing here adds or renames a value.
 */
final class Vocabulary {

	/**
	 * The decoded file, read once per request.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $data = null;

	/**
	 * The decoded vocabulary file.
	 *
	 * @return array<string, mixed>
	 * @throws \RuntimeException When the file is missing or isn't JSON, which is a
	 *                           broken release rather than anything content can cause.
	 */
	private static function data(): array {
		if ( null === self::$data ) {
			$data = wp_json_file_decode( dirname( __DIR__ ) . '/shared/vocabulary.json', array( 'associative' => true ) );
			if ( ! is_array( $data ) ) {
				throw new \RuntimeException( 'The Firehouse plugin could not read shared/vocabulary.json.' );
			}
			self::$data = $data;
		}
		return self::$data;
	}

	/**
	 * The `key` of every item in one list.
	 *
	 * @param string $name The list: firePhases, publicationStatuses, regions or topics.
	 * @return list<string>
	 */
	private static function keys( string $name ): array {
		return array_column( self::data()[ $name ], 'key' );
	}

	/**
	 * Fire cycle phases, in fire-cycle order.
	 *
	 * @return list<string>
	 */
	public static function fire_phases(): array {
		return self::keys( 'firePhases' );
	}

	/**
	 * Publication statuses.
	 *
	 * @return list<string>
	 */
	public static function publication_statuses(): array {
		return self::keys( 'publicationStatuses' );
	}

	/**
	 * Every region answer: the ten GACCs, then Pacific, national, international and
	 * unknown.
	 *
	 * @return list<string>
	 */
	public static function regions(): array {
		return self::keys( 'regions' );
	}

	/**
	 * The ten Geographic Area Coordination Centers, the regions the map draws.
	 *
	 * @return list<string>
	 */
	public static function gaccs(): array {
		$mapped = array_filter( self::data()['regions'], static fn( array $region ): bool => ! empty( $region['mapped'] ) );
		return array_values( array_column( $mapped, 'key' ) );
	}

	/**
	 * The four topic keys.
	 *
	 * @return list<string>
	 */
	public static function topics(): array {
		return self::keys( 'topics' );
	}

	/**
	 * The icon names content may use.
	 *
	 * @return list<string>
	 */
	public static function icons(): array {
		return self::data()['icons'];
	}

	/**
	 * Whether a value is one of a list's keys. Anything but a string is not.
	 *
	 * @param mixed    $value The value to check.
	 * @param string[] $keys  The keys it may be.
	 */
	public static function is_one_of( mixed $value, array $keys ): bool {
		return is_string( $value ) && in_array( $value, $keys, true );
	}
}
