<?php
/**
 * Runs shared/cases/search.json against domain/class-search.php.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Firehouse\Domain\Derive;
use Firehouse\Domain\Js;
use Firehouse\Domain\Search;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

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
	 * The query string's own reading, beyond what the shared cases see through
	 * parse_query(): a leading "?", empty pairs, and names kept as written.
	 */
	public function test_a_query_string_is_split_as_the_browser_splits_it(): void {
		$this->assertSame( array( 'q' => 'smoke alerts' ), Search::params( '?q=smoke+alerts' ) );
		$this->assertSame(
			array(
				'q'       => '',
				'phase[]' => 'during',
			),
			Search::params( '&&q&phase%5B%5D=during' )
		);
	}

	/**
	 * Js::number() as JavaScript's Number() reads what a year's cases don't reach.
	 */
	public function test_numbers_read_as_javascript_reads_them(): void {
		$this->assertSame( 0.0, Js::number( null ) );
		$this->assertSame( 0.0, Js::number( ' ' ) );
		$this->assertTrue( is_nan( Js::number( '0b12' ) ) );
		$this->assertSame( 5.0, Js::number( '0b101' ) );
		$this->assertSame( 8.0, Js::number( '0o10' ) );
		$this->assertSame( -INF, Js::number( '-Infinity' ) );
		$this->assertSame( INF, Js::number( '1e400' ) );
		$this->assertSame( 0.5, Js::number( '.5' ) );
	}

	/**
	 * A word's length and its typos count UTF-16 code units, as JavaScript's strings do:
	 * a letter beyond the Basic Multilingual Plane is two. (Values checked in Node.)
	 */
	public function test_typos_count_utf16_code_units(): void {
		$this->assertFalse( Search::within_one_edit( "abc\u{20000}", 'abcd' ) );
		$this->assertTrue( Search::within_one_edit( "\u{20000}\u{20000}", "\u{20000}\u{20001}" ) );
		$project = array(
			'title' => "\u{20000}\u{20000} smoke",
			'slug'  => 'x',
		);
		// Two such letters are four units long, so a typo of them counts at half weight.
		$this->assertSame( 3.0, Search::score_project( $project, "\u{20000}\u{20001}" ) );
	}

	/**
	 * An entry in the wrong shape, such as a paper stored as plain text, gives nothing to
	 * search rather than an error on every search, as in the TypeScript.
	 */
	public function test_entries_in_the_wrong_shape_are_passed_over(): void {
		$project            = Cases::site()['projects'][0];
		$project['papers']  = array( 'Smith 2024, Journal of Fire', null, 7 );
		$project['authors'] = array( 'A. Name', array( 'name' => null ) );
		$project['needs']   = array( 'a need as text' );
		$project['geo']     = 'not an object';

		$this->assertGreaterThan( 0.0, Search::score_project( $project, $project['title'] ) );
		$this->assertSame( 0.0, Search::score_project( $project, 'Journal' ) );
	}

	/**
	 * Phases or regions stored as anything but a list give none, and a region that isn't
	 * text is passed over: one such record can't stop the explorer (rule 6).
	 */
	public function test_phases_and_regions_in_the_wrong_shape_give_none(): void {
		$projects                      = Derive::published_projects( Cases::site() );
		$projects[0]['firePhases']     = 'during';
		$projects[1]['geo']            = array( 'regions' => 'NATIONAL' );
		$projects[2]['geo']            = 'not an object';
		$projects[3]['geo']['regions'] = array( 'NWCC', array( 'NATIONAL' ), null );
		$query                         = array(
			'phase'  => 'during',
			'region' => 'NWCC',
		) + Search::parse_query( '' );
		$values                        = static fn( string $facet ): \Closure => static fn( array $project ): array => Search::facet_values( $project, $facet );

		$this->assertSame( array(), Search::facet_values( $projects[0], 'phase' ) );
		$this->assertSame( array(), Search::facet_values( $projects[1], 'region' ) );
		$this->assertSame( array(), Search::facet_values( $projects[2], 'region' ) );
		$this->assertSame( array( 'NWCC' ), Search::facet_values( $projects[3], 'region' ) );
		$this->assertNotContains( $projects[0]['slug'], array_column( Search::filter_projects( $projects, $query ), 'slug' ) );
		$this->assertArrayNotHasKey( 'NATIONAL', Search::facet_counts( $projects, Search::parse_query( '' ), 'region', $values( 'region' ) ) );
	}

	/**
	 * Scores worked out once and passed in give what scoring each time gives, for every
	 * sort, filter and count.
	 */
	public function test_scores_passed_in_give_the_same_results(): void {
		$projects = Derive::published_projects( Cases::site() );
		foreach ( array( '', 'q=smoke', 'q=smoke+fire&sort=title', 'q=forcast&phase=during', 'q=zzzz', 'phase=before&sort=oldest', 'q=evac&region=NWCC' ) as $string ) {
			$query  = Search::parse_query( $string );
			$scores = Search::scores( $projects, $query['q'] );
			$this->assertSame( array_map( static fn( array $project ): float => Search::score_project( $project, $query['q'] ), $projects ), $scores, $string );
			$this->assertSame( Search::filter_projects( $projects, $query ), Search::filter_projects( $projects, $query, $scores ), $string );
			foreach ( Search::FACETS as $facet ) {
				$values = static fn( array $project ): array => Search::facet_values( $project, $facet );
				$this->assertSame( Search::facet_counts( $projects, $query, $facet, $values ), Search::facet_counts( $projects, $query, $facet, $values, $scores ), "$string $facet" );
			}
		}
	}

	/**
	 * A year stored as 2025.0 is the year 2025, in the filter and the counts alike, as one
	 * number in JavaScript; a fractional one is a value of its own.
	 */
	public function test_a_whole_float_year_is_that_year(): void {
		$projects                      = Derive::published_projects( Cases::site() );
		$projects[0]['completionYear'] = (float) $projects[0]['completionYear'];
		$year                          = (int) $projects[0]['completionYear'];
		$query                         = array( 'year' => $year ) + Search::parse_query( '' );

		$this->assertContains( $projects[0]['slug'], array_column( Search::filter_projects( $projects, $query ), 'slug' ) );
		$counts = Search::facet_counts( $projects, Search::parse_query( '' ), 'year', static fn( array $project ): array => Search::facet_values( $project, 'year' ) );
		$this->assertArrayHasKey( $year, $counts );
		$this->assertSame( array_sum( $counts ), count( $projects ) );

		$projects[1]['completionYear'] = 2025.5;
		$counts                        = Search::facet_counts( $projects, Search::parse_query( '' ), 'year', static fn( array $project ): array => Search::facet_values( $project, 'year' ) );
		$this->assertSame( 1, $counts['2025.5'] );
	}

	/**
	 * A long query of misspelt words, which once took seconds a request, stays quick:
	 * only the first 20 words count, a repeated word is scored once, and a field's
	 * words are measured once.
	 */
	public function test_a_long_misspelt_query_stays_quick(): void {
		$projects = Derive::published_projects( Cases::site() );
		$query    = Search::parse_query( 'q=' . str_repeat( 'fyre+wiht+dtaa+', 600 ) );
		$started  = hrtime( true );
		Search::filter_projects( $projects, $query );
		foreach ( Search::FACETS as $facet ) {
			Search::facet_counts( $projects, $query, $facet, static fn( array $project ): array => Search::facet_values( $project, $facet ) );
		}
		$this->assertLessThan( 1.0, ( hrtime( true ) - $started ) / 1e9 );
	}

	/**
	 * The fallback's table is read as UTF-8 whatever the server's default multibyte
	 * encoding, so a site set to another one still searches. In a process of its own,
	 * since the table is built once and kept.
	 */
	#[RunInSeparateProcess]
	public function test_folding_without_intl_ignores_the_default_encoding(): void {
		mb_internal_encoding( 'ISO-8859-1' );
		$this->assertSame( 'evacuation', Search::fold_without_intl( 'Évacuation' ) );
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
				// Not mb_chr(), which Plugin Check refuses: newer WordPress ships its own copy.
				$letters[] = (string) mb_convert_encoding( pack( 'N', $code ), 'UTF-8', 'UTF-32BE' );
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
			'scores'                => Search::scores( ...$args ),
			'filterProjectsScored'  => Search::filter_projects( $args[0], $args[1], Search::scores( $args[0], $args[1]['q'] ) ),
			'facetCountsScored'     => Search::facet_counts( $args[0], $args[1], $args[2], $args[3], Search::scores( $args[0], $args[1]['q'] ) ),
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
