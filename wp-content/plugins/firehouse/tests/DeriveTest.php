<?php
/**
 * Runs shared/cases/derive.json against domain/class-derive.php.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Firehouse\Domain\Derive;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Derive, against the same cases as src/content/derive.ts.
 */
final class DeriveTest extends DomainTestCase {

	/**
	 * One shared case.
	 *
	 * @param array<string, mixed> $shared_case The case: `fn`, `args`, `view` and `expected`.
	 */
	#[DataProvider( 'cases' )]
	public function test_shared_case( array $shared_case ): void {
		$content = Cases::site();
		$args    = array_map( static fn( mixed $arg ): mixed => Cases::resolve_arg( $arg, $content ), $shared_case['args'] );
		$result  = self::call( $shared_case['fn'], $args );
		if ( isset( $shared_case['view'] ) ) {
			$result = self::view( $shared_case['view'], $result );
		}

		$this->assertSame( Cases::canonical( $shared_case['expected'] ), Cases::canonical( $result ) );
	}

	/**
	 * The cases.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function cases(): array {
		return Cases::provider( 'derive.json' );
	}

	/**
	 * Titles of letters and spaces sort as localeCompare sorts them, with intl or
	 * without it: ignoring case, then lowercase first.
	 *
	 * @param callable $compare compare_titles(), or the comparison without intl.
	 */
	#[DataProvider( 'title_comparisons' )]
	public function test_titles_compare_ignoring_case_then_lowercase_first( callable $compare ): void {
		$titles = array( 'beta study', 'Alpha study', 'alpha study', 'Beta study', 'gamma' );
		usort( $titles, $compare );

		$this->assertSame( array( 'alpha study', 'Alpha study', 'beta study', 'Beta study', 'gamma' ), $titles );
	}

	/**
	 * Both comparisons, so the one without intl is tested on a machine that has intl.
	 *
	 * @return array<string, array{callable}>
	 */
	public static function title_comparisons(): array {
		return array(
			'compare_titles'              => array( array( Derive::class, 'compare_titles' ) ),
			'compare_titles_without_intl' => array( array( Derive::class, 'compare_titles_without_intl' ) ),
		);
	}

	/**
	 * A need of "0" counts, as it does in JavaScript, although PHP calls it empty.
	 */
	public function test_a_need_of_zero_counts(): void {
		$content                           = Cases::site();
		$content['projects'][0]['needs'][] = array( 'need' => '0' );

		$this->assertSame( '3', Derive::stat_value( array( 'source' => 'needCount' ), $content ) );
	}

	/**
	 * The synthesis line is on every page that shows the synthesis, so a date that
	 * isn't text or a count that isn't a number is passed over, not a crash.
	 */
	public function test_malformed_synthesis_values_are_passed_over(): void {
		$content                                     = Cases::site();
		$content['topicSummaries'][0]['updatedAt']   = array();
		$content['topicSummaries'][1]['sourceCount'] = array( 'not a count' );

		$info = Derive::synthesis_info( $content );

		$this->assertSame( '2026-10-27', $info['updatedAt'] );
		$this->assertSame( 3, $info['sourceCount'] );
		$this->assertSame(
			2,
			Derive::need_mentions(
				array(
					'mentions' => array(),
					'projects' => array( 'a', 'b' ),
				)
			)
		);
		$this->assertSame( '', Derive::stat_value( array(), $content ) );
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
			'publishedProjects' => Derive::published_projects( ...$args ),
			'resolveStatValue'  => Derive::stat_value( ...$args ),
			'buildTopicCards'   => Derive::topic_cards( ...$args ),
			'findProjectBySlug' => Derive::project_by_slug( ...$args ),
			'sortByRecency'     => self::sorted( $args[0] ),
			'needMentions'      => Derive::need_mentions( ...$args ),
			'synthesisInfo'     => Derive::synthesis_info( ...$args ),
			'effectiveRegions'  => Derive::effective_regions( ...$args ),
			'projectsInRegion'  => Derive::projects_in_region( ...$args ),
			'regionCounts'      => Derive::region_counts( ...$args ),
		};
	}

	/**
	 * Projects sorted with by_recency.
	 *
	 * @param list<array<string, mixed>> $projects The projects.
	 * @return list<array<string, mixed>>
	 */
	private static function sorted( array $projects ): array {
		usort( $projects, array( Derive::class, 'by_recency' ) );
		return $projects;
	}

	/**
	 * Turns a result into the JSON a case expects.
	 *
	 * @param string $view   `slugs`, `slug` or `cards`.
	 * @param mixed  $result The result.
	 * @return mixed
	 */
	private static function view( string $view, mixed $result ): mixed {
		return match ( $view ) {
			'slugs' => Cases::pluck( $result, 'slug' ),
			'slug'  => $result['slug'] ?? null,
			'cards' => array_map(
				static fn( array $card ): array => array(
					'topic' => $card['topic']['key'],
					'needs' => Cases::pluck( $card['needs'], 'text' ),
				),
				$result
			),
		};
	}
}
