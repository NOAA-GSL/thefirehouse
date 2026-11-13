<?php
/**
 * Runs shared/cases/search.json against domain/class-search.php.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Firehouse\Domain\Js;
use Firehouse\Domain\Search;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Search, against the same cases as src/content/search.ts, and the JavaScript rules it
 * reads a query string by.
 */
final class SearchTest extends DomainTestCase {

	/**
	 * One shared case.
	 *
	 * @param array<string, mixed> $shared_case The case: `fn`, `args`, `view` and `expected`.
	 */
	#[DataProvider( 'cases' )]
	public function test_shared_case( array $shared_case ): void {
		$content = Cases::site();
		$args    = array_map(
			static fn( mixed $arg ): mixed => is_string( $arg ) && str_starts_with( $arg, '@values:' )
				? static fn( array $project ): array => Search::facet_values( $project, substr( $arg, strlen( '@values:' ) ) )
				: Cases::resolve_arg( $arg, $content ),
			$shared_case['args']
		);
		$result  = self::call( $shared_case['fn'], $args );
		if ( 'slugs' === ( $shared_case['view'] ?? null ) ) {
			$result = array_map( static fn( array $project ): string => $project['slug'], $result );
		}

		$this->assertSame( Cases::canonical( $shared_case['expected'] ), Cases::canonical( self::numbers( $result ) ) );
	}

	/**
	 * The cases.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function cases(): array {
		return Cases::provider( 'search.json' );
	}

	/**
	 * A query string's parameters are read as URLSearchParams reads them.
	 */
	public function test_a_query_string_is_read_as_the_browser_reads_it(): void {
		$this->assertSame( array( 'q' => 'smoke alerts' ), Search::params( '?q=smoke+alerts' ) );
		$this->assertSame( array( 'q' => 'a+b c' ), Search::params( 'q=a%2Bb%20c' ) );
		$this->assertSame( array( 'phase' => 'during' ), Search::params( 'phase=during&phase=before' ) );
		$this->assertSame(
			array(
				'q'       => '',
				'phase[]' => 'during',
			),
			Search::params( '&&q&phase%5B%5D=during' )
		);
		$this->assertSame( array( 'q' => "a\u{FFFD}b" ), Search::params( 'q=a%FFb' ) );
		$this->assertSame( array( 'q' => '100%' ), Search::params( 'q=100%' ) );
		$this->assertNull( Search::parse_query( 'phase[]=during' )['phase'] );
	}

	/**
	 * A year is read as JavaScript's Number() reads it, then kept only when it's a
	 * positive whole number.
	 */
	public function test_a_year_is_read_as_javascripts_number_reads_it(): void {
		$years = array(
			array( '2025', 2025 ),
			array( ' 2025 ', 2025 ),
			array( '2025.0', 2025 ),
			array( '2.025e3', 2025 ),
			array( sprintf( '0x%X', 2025 ), 2025 ),
			array( '+2025', 2025 ),
			array( "\u{A0}2025", 2025 ),
			array( '2025.5', null ),
			array( '-2024', null ),
			array( '0', null ),
			array( '', null ),
			array( '2025px', null ),
			array( '1_000', null ),
			array( 'Infinity', null ),
			array( '+' . sprintf( '0x%X', 2025 ), null ),
		);
		foreach ( $years as list( $text, $year ) ) {
			$this->assertSame( $year, Search::parse_query( 'year=' . rawurlencode( $text ) )['year'], $text );
		}
		$this->assertSame( 0.0, Js::number( null ) );
		$this->assertTrue( is_nan( Js::number( '0b12' ) ) );
		$this->assertSame( 5.0, Js::number( '0b101' ) );
		$this->assertSame( 8.0, Js::number( '0o10' ) );
		$this->assertSame( -INF, Js::number( '-Infinity' ) );
		$this->assertSame( INF, Js::number( '1e400' ) );
	}

	/**
	 * A query string is written as URLSearchParams writes one.
	 */
	public function test_a_query_string_is_written_as_the_browser_writes_it(): void {
		$query = Search::parse_query( '' );
		$this->assertSame( 'q=a*b+%26+%C3%A9%7E', Search::serialize_query( array( 'q' => 'a*b & é~' ) + $query ) );
	}

	/**
	 * Without intl, Latin letters still lose their accents, and marks written apart are
	 * dropped; with intl, the fallback's table agrees with the Normalizer.
	 */
	public function test_folding_without_intl_drops_latin_accents(): void {
		// Æ has no decomposition, in JavaScript either, so it only lower-cases.
		$this->assertSame( 'evacuation nandu honolulu æ', Search::fold_without_intl( 'ÉVACUATION Ñandú Hōnolulu Æ' ) );
		$this->assertSame( 'evacuation', Search::fold_without_intl( "E\u{0301}vacuation" ) );
		$this->assertSame( 'ελλάδα', Search::fold_without_intl( 'Ελλάδα' ) );

		if ( ! class_exists( \Normalizer::class ) ) {
			$this->markTestIncomplete( 'The intl extension is missing here, so the table is checked against it elsewhere (CI).' );
		}
		$letters = array();
		foreach ( array( array( 0xC0, 0x24F ), array( 0x1E00, 0x1EFF ) ) as list( $first, $last ) ) {
			for ( $code = $first; $code <= $last; $code++ ) {
				$letters[] = mb_chr( $code, 'UTF-8' );
			}
		}
		$text = implode( ' ', $letters );
		$this->assertSame( Search::fold( $text ), Search::fold_without_intl( $text ) );
	}

	/**
	 * Calls the function a case names.
	 *
	 * @param string  $name The TypeScript name.
	 * @param mixed[] $args The resolved arguments.
	 * @return mixed
	 */
	private static function call( string $name, array $args ): mixed {
		return match ( $name ) {
			'parseProjectQuery'     => Search::parse_query( ...$args ),
			'serializeProjectQuery' => Search::serialize_query( ...$args ),
			'activeFacetCount'      => Search::active_facet_count( ...$args ),
			'effectiveSort'         => Search::effective_sort( ...$args ),
			'scoreProject'          => Search::score_project( ...$args ),
			'filterProjects'        => Search::filter_projects( ...$args ),
			'facetCounts'           => Search::facet_counts( ...$args ),
		};
	}

	/**
	 * A result with every whole float as an integer, since JavaScript has one kind of
	 * number and a case's JSON writes 12, not 12.0.
	 *
	 * @param mixed $value The result.
	 * @return mixed
	 */
	private static function numbers( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'numbers' ), $value );
		}
		return is_float( $value ) && is_finite( $value ) && floor( $value ) === $value ? (int) $value : $value;
	}
}
