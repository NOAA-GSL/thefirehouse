<?php
/**
 * Runs shared/cases/normalize.json against domain/class-normalize.php.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Firehouse\Domain\Content_Error;
use Firehouse\Domain\Normalize;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Normalize, against the same cases as src/content/normalize.ts.
 */
final class NormalizeTest extends DomainTestCase {

	/**
	 * In broken_blocks(), the block is removed rather than replaced.
	 */
	private const REMOVE = '(remove)';

	/**
	 * One shared case: changes to site.json, then a refusal or checks on the result.
	 *
	 * @param array<string, mixed> $shared_case The case: `set`, `remove`, `throws` and `checks`.
	 */
	#[DataProvider( 'cases' )]
	public function test_shared_case( array $shared_case ): void {
		$raw = Cases::raw_site();
		foreach ( $shared_case['set'] ?? array() as $change ) {
			Cases::pointer_set( $raw, $change['path'], $change['value'] );
		}
		foreach ( $shared_case['remove'] ?? array() as $path ) {
			Cases::pointer_remove( $raw, $path );
		}

		if ( ! empty( $shared_case['throws'] ) ) {
			$this->expectException( Content_Error::class );
			Normalize::site_content( $raw );
			return;
		}

		$result = Normalize::site_content( $raw );
		foreach ( $shared_case['checks'] ?? array() as $check ) {
			$path              = $check['path'];
			[ $found, $value ] = Cases::pointer_get( $result, $path );

			if ( ! empty( $check['absent'] ) ) {
				$this->assertFalse( $found, $path );
			}
			if ( isset( $check['length'] ) ) {
				$this->assertCount( $check['length'], $value, $path );
			}
			if ( array_key_exists( 'equals', $check ) ) {
				$actual = isset( $check['pluck'] ) ? Cases::pluck( $value, $check['pluck'] ) : $value;
				$this->assertSame( Cases::canonical( $check['equals'] ), Cases::canonical( $actual ), $path );
			}
		}
	}

	/**
	 * The cases.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function cases(): array {
		return Cases::provider( 'normalize.json' );
	}

	/**
	 * A refusal says it is one.
	 */
	public function test_a_refusal_is_marked_as_content(): void {
		$raw = Cases::raw_site();
		unset( $raw['settings'] );

		$this->expectException( Content_Error::class );
		$this->expectExceptionMessageMatches( '/^\[content\] /' );
		Normalize::site_content( $raw );
	}

	/**
	 * Where the TypeScript would crash on a missing or malformed block, PHP refuses it.
	 * A shared case can't say this: the TypeScript refuses with a TypeError, not a
	 * content error.
	 *
	 * @param string $pointer The block.
	 * @param mixed  $value   What to put there, or REMOVE.
	 */
	#[DataProvider( 'broken_blocks' )]
	public function test_a_broken_block_is_refused( string $pointer, mixed $value ): void {
		$raw = Cases::raw_site();
		if ( self::REMOVE === $value ) {
			Cases::pointer_remove( $raw, $pointer );
		} else {
			Cases::pointer_set( $raw, $pointer, $value );
		}

		$this->expectException( Content_Error::class );
		Normalize::site_content( $raw );
	}

	/**
	 * Blocks the TypeScript reads without checking, removed or replaced with something
	 * that isn't what it reads.
	 *
	 * @return array<string, array{string, mixed}>
	 */
	public static function broken_blocks(): array {
		return array(
			'no topics'                     => array( '/topics', self::REMOVE ),
			'no projects'                   => array( '/projects', self::REMOVE ),
			'no syntheses'                  => array( '/topicSummaries', self::REMOVE ),
			'a synthesis with no needs'     => array( '/topicSummaries/0/topNeeds', self::REMOVE ),
			'a need that is null'           => array( '/topicSummaries/0/topNeeds/0', null ),
			'a need with projects as a map' => array( '/topicSummaries/0/topNeeds/1/projects', array( 'first' => 'smoke-alerts-for-evacuation' ) ),
			'no landing page'               => array( '/landing', self::REMOVE ),
			'no hero'                       => array( '/landing/hero', self::REMOVE ),
			'no hero link'                  => array( '/landing/hero/primaryCta', self::REMOVE ),
			'no nav'                        => array( '/settings/nav', self::REMOVE ),
			'no About call to action'       => array( '/about/cta', self::REMOVE ),
			'team members as text'          => array( '/about/team/members', 'Hal Member' ),
			'audience cards as text'        => array( '/about/audiences/items', 'Forecasters' ),
		);
	}

	/**
	 * A need of "0" is a need, as it is in JavaScript, although PHP calls it empty.
	 */
	public function test_a_need_of_zero_is_kept(): void {
		$raw                         = Cases::raw_site();
		$raw['projects'][0]['needs'] = array( array( 'need' => '0' ) );

		$this->assertSame( array( array( 'need' => '0' ) ), Normalize::site_content( $raw )['projects'][0]['needs'] );
	}
}
