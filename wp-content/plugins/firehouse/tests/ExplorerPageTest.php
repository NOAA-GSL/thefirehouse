<?php
/**
 * Tests for the project explorer.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Domain\Derive;
use Firehouse\Domain\Search;
use Firehouse\Explorer_Page;
use Firehouse\Store;

/**
 * The explorer lists what the site's explorer lists for the same address, in the
 * site's markup, with every control in one form that works without JavaScript.
 */
final class ExplorerPageTest extends WordPressTestCase {

	private const URL = 'https://example.test/firehouse/projects/';

	/**
	 * Stands in for WordPress's addresses, escaping and shared JSON. esc_html() leaves a
	 * character reference alone, as WordPress's does, where Brain Monkey's would escape
	 * it again.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\stubEscapeFunctions();
		Functions\when( 'esc_html' )->alias( static fn( string $text ): string => htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false ) );
		Functions\when( 'wp_json_file_decode' )->alias(
			static fn( string $filename, array $options = array() ): mixed => json_decode( (string) file_get_contents( $filename ), ! empty( $options['associative'] ) )
		);
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://example.test' . $path );
	}

	/**
	 * The shared site's published projects, each with its address, in the site's order.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function projects(): array {
		return array_map(
			static fn( array $project ): array => array( 'url' => self::URL . $project['slug'] . '/' ) + $project,
			Derive::published_projects( Cases::site() )
		);
	}

	/**
	 * The page for a query string.
	 *
	 * @param string                          $query    The query string.
	 * @param list<array<string, mixed>>|null $projects The projects, or null for the shared site's.
	 */
	private static function render( string $query = '', ?array $projects = null ): string {
		return Explorer_Page::render( $projects ?? self::projects(), Search::parse_query( $query ), self::URL );
	}

	/**
	 * A select's markup, by its parameter's name.
	 *
	 * @param string $html The page.
	 * @param string $name The parameter.
	 */
	private static function select( string $html, string $name ): string {
		preg_match( '#<select id="fh-explorer-' . $name . '" name="' . $name . '" form="fh-explorer-form">.*?</select>#s', $html, $match );
		return $match[0] ?? '';
	}

	/**
	 * With nothing asked, every project shows, newest first, under a count, with the
	 * search and the five selects in one GET form and a button that sends it.
	 */
	public function test_the_page_with_no_query(): void {
		$html     = self::render();
		$projects = self::projects();

		$this->assertStringContainsString( '<h1 class="fh-explorer__heading">Completed research projects</h1>', $html );
		$this->assertStringContainsString( '<search class="fh-explorer__toolbar" aria-label="Search and filter projects">', $html );
		$this->assertStringContainsString( '<form class="fh-search" id="fh-explorer-form" method="get" action="' . self::URL . '">', $html );
		$this->assertStringContainsString( '<input id="fh-explorer-q" class="fh-search__input" type="search" name="q" value="" placeholder="Search projects" autocomplete="off" spellcheck="false" enterkeyhint="search" aria-describedby="fh-explorer-q-hint">', $html );
		$this->assertStringNotContainsString( 'fh-search__clear', $html );
		foreach ( array( 'phase', 'region', 'year', 'type', 'sort' ) as $name ) {
			$this->assertNotSame( '', self::select( $html, $name ), $name );
		}
		$this->assertStringContainsString( '<button type="submit" form="fh-explorer-form" class="fh-btn fh-btn--secondary fh-btn--md">Update results</button>', $html );
		$this->assertMatchesRegularExpression( '#<p class="fh-explorer__count" role="status">\s*<strong>' . count( $projects ) . '</strong> projects\s*</p>#', $html );
		$this->assertStringNotContainsString( 'fh-explorer__applied', $html );
		$this->assertStringNotContainsString( 'fh-filter--set', $html );

		preg_match_all( '#<a class="fh-tile__link" href="([^"]*)">#', $html, $links );
		$this->assertSame( array_column( Search::filter_projects( $projects, Search::parse_query( '' ) ), 'url' ), $links[1] );
		$this->assertCount( count( $projects ), $links[1] );
	}

	/**
	 * Each option says how many projects it would leave; one that would leave none is
	 * disabled. The GACCs and the other regions are in groups, as on the site; years
	 * run newest first and types in order.
	 */
	public function test_the_options_and_their_counts(): void {
		$html = self::render();

		$this->assertStringContainsString( '<option value="">Any phase</option><option value="before">Pre-fire planning, prevention, and mitigation (1)</option>', self::select( $html, 'phase' ) );
		$region = self::select( $html, 'region' );
		$this->assertStringContainsString( '<option value="">Any area</option><optgroup label="Geographic Area Coordination Centers"><option value="AICC">Alaska (AICC) (1)</option>', $region );
		// The national study counts in every GACC.
		$this->assertStringContainsString( '<option value="GBCC">Great Basin (GBCC) (1)</option>', $region );
		$this->assertStringContainsString( '<optgroup label="Not on the map"><option value="PACIFIC">Hawaii / Pacific (1)</option>', $region );
		$this->assertStringContainsString( '<option value="INTL" disabled>International (0)</option>', $region );
		$this->assertStringContainsString( '<option value="">Any year</option><option value="2025">2025 (2)</option><option value="2024">2024 (2)</option></select>', self::select( $html, 'year' ) );
		$this->assertStringContainsString( '<option value="">Any type</option><option value="Program Evaluation">Program Evaluation (1)</option><option value="Research Study">Research Study (2)</option><option value="Testbed Evaluation">Testbed Evaluation (1)</option></select>', self::select( $html, 'type' ) );
	}

	/**
	 * 2025 and 2025.0 are one year, as one number in JavaScript; a project without a
	 * type adds no option.
	 */
	public function test_a_whole_float_year_is_one_option(): void {
		$projects                      = self::projects();
		$projects[0]['completionYear'] = (float) $projects[0]['completionYear'];
		unset( $projects[1]['projectType'] );

		$html = self::render( '', $projects );

		$this->assertStringContainsString( '<option value="2025">2025 (2)</option><option value="2024">2024 (2)</option></select>', self::select( $html, 'year' ) );
		$this->assertStringContainsString( '<option value="Research Study">Research Study (1)</option>', self::select( $html, 'type' ) );
		$this->assertStringContainsString( '<span class="fh-tile__byline">', $html );
		$this->assertStringNotContainsString( '2025.0', $html );
	}

	/**
	 * The sort the page falls back to is sent as nothing, so it stays the fallback: a
	 * search with the sort untouched lists by best match, as on the site. A sort chosen
	 * is sent as itself.
	 */
	public function test_the_fallback_sort_is_sent_as_nothing(): void {
		$this->assertSame(
			'<select id="fh-explorer-sort" name="sort" form="fh-explorer-form"><option value="" selected>Newest first</option><option value="oldest">Oldest first</option><option value="title">Title A–Z</option></select>',
			self::select( self::render(), 'sort' )
		);
		$this->assertSame(
			'<select id="fh-explorer-sort" name="sort" form="fh-explorer-form"><option value="" selected>Best match</option><option value="newest">Newest first</option><option value="oldest">Oldest first</option><option value="title">Title A–Z</option></select>',
			self::select( self::render( 'q=smoke' ), 'sort' )
		);
		$this->assertSame(
			'<select id="fh-explorer-sort" name="sort" form="fh-explorer-form"><option value="relevance">Best match</option><option value="newest">Newest first</option><option value="oldest">Oldest first</option><option value="title" selected>Title A–Z</option></select>',
			self::select( self::render( 'q=smoke&sort=title' ), 'sort' )
		);
		// Best match without text to match falls back to newest, which is then sent.
		$this->assertSame(
			'<select id="fh-explorer-sort" name="sort" form="fh-explorer-form"><option value="newest" selected>Newest first</option><option value="oldest">Oldest first</option><option value="title">Title A–Z</option></select>',
			self::select( self::render( 'sort=relevance' ), 'sort' )
		);
		$this->assertStringNotContainsString( 'fh-filter--set', self::render( 'sort=title' ) );
	}

	/**
	 * A search shows what it matched of how many, a link that clears it, and Clear all,
	 * which keeps the sort, as the site's does.
	 */
	public function test_a_search(): void {
		$html = self::render( 'q=+smoke+&sort=title' );

		$this->assertMatchesRegularExpression( '#role="status">\s*Showing <strong>1</strong> of 4 projects matching <strong>“smoke”</strong>\s*</p>#', $html );
		$this->assertStringContainsString( 'name="q" value=" smoke "', $html );
		$this->assertStringContainsString( '<a class="fh-search__clear" href="' . self::URL . '?sort=title" aria-label="Clear search">', $html );
		$this->assertStringContainsString( '<ul class="fh-explorer__applied" aria-label="Applied filters">', $html );
		$this->assertStringContainsString( '<li><a class="fh-explorer__clear-all" href="' . self::URL . '?sort=title">Clear all</a></li>', $html );
		$this->assertStringNotContainsString( 'fh-pill', $html );
		$this->assertSame( 1, substr_count( $html, 'class="fh-tile__link"' ) );
	}

	/**
	 * Each filter applied is a link to the same address without it, named for what it
	 * removes, and its select shows it as set.
	 */
	public function test_each_filter_is_a_link_that_removes_it(): void {
		// Brain Monkey's esc_url() leaves an & as it is, where WordPress's writes &#038;.
		$html = self::render( 'q=smoke&phase=during&region=NWCC&year=2025&type=Testbed+Evaluation&sort=oldest' );

		$this->assertStringContainsString( '<li><a class="fh-pill" href="' . self::URL . '?q=smoke&region=NWCC&year=2025&type=Testbed+Evaluation&sort=oldest" aria-label="Remove filter Phase: During">Phase: During<svg', $html );
		$this->assertStringContainsString( 'href="' . self::URL . '?q=smoke&phase=during&year=2025&type=Testbed+Evaluation&sort=oldest" aria-label="Remove filter GACC: Northwest">GACC: Northwest<svg', $html );
		$this->assertStringContainsString( 'aria-label="Remove filter Year: 2025">Year: 2025<svg', $html );
		$this->assertStringContainsString( 'href="' . self::URL . '?q=smoke&phase=during&region=NWCC&year=2025&sort=oldest" aria-label="Remove filter Type: Testbed Evaluation">', $html );
		$this->assertSame( 4, substr_count( $html, '<div class="fh-filter fh-filter--set">' ) );
		$this->assertStringContainsString( '<option value="during" selected>', $html );
		$this->assertStringContainsString( '<option value="NWCC" selected>', $html );
		$this->assertStringContainsString( '<option value="2025" selected>', $html );
		$this->assertStringContainsString( '<option value="Testbed Evaluation" selected>', $html );
	}

	/**
	 * A filter that leaves nothing stays chosen and enabled, so the select shows it, and
	 * the empty state offers to clear everything.
	 */
	public function test_a_filter_that_leaves_nothing(): void {
		$html = self::render( 'region=INTL' );

		$this->assertStringContainsString( '<option value="INTL" selected>International (0)</option>', $html );
		$this->assertMatchesRegularExpression( '#role="status">\s*Showing <strong>0</strong> of 4 projects\s*</p>#', $html );
		$this->assertStringContainsString( '<p class="fh-explorer__empty-title">No projects match your search and filters.</p>', $html );
		$this->assertStringContainsString( '<a class="fh-explorer__empty-action" href="' . self::URL . '">Clear search and filters</a>', $html );
		$this->assertStringNotContainsString( 'fh-explorer__grid', $html );
	}

	/**
	 * With no projects to show, the page says so and points to the topic areas.
	 */
	public function test_no_projects(): void {
		$html = self::render( '', array() );

		$this->assertMatchesRegularExpression( '#role="status">\s*<strong>0</strong> projects\s*</p>#', $html );
		$this->assertStringContainsString( '<p class="fh-explorer__empty-title">No published projects are available yet.</p>', $html );
		$this->assertStringContainsString( '<a class="fh-explorer__empty-action" href="https://example.test/firehouse/#top-needs">Browse topic areas</a>', $html );
		$this->assertStringNotContainsString( 'fh-explorer__empty-body', $html );
	}

	/**
	 * One project is "1 project", as the site says it.
	 */
	public function test_one_project_is_singular(): void {
		$this->assertMatchesRegularExpression( '#role="status">\s*<strong>1</strong> project\s*</p>#', self::render( '', array( self::projects()[0] ) ) );
		$this->assertMatchesRegularExpression( '#Showing <strong>1</strong> of 1 project\s*</p>#', self::render( 'phase=during', array( self::projects()[0] ) ) );
	}

	/**
	 * A tile, as the site's ProjectTile: the type above the title, the title's link, the
	 * summary, the byline and year, and the phases' short names.
	 */
	public function test_a_tile(): void {
		$this->assertSame(
			'<article class="fh-tile"><span class="fh-tile__glow" aria-hidden="true"></span><span class="fh-tile__body"><span class="fh-tile__kicker">Research Study</span><h3 class="fh-tile__title"><a class="fh-tile__link" href="https://example.test/firehouse/projects/one/">One</a></h3><p class="fh-tile__summary">A summary.</p></span><span class="fh-tile__meta"><span class="fh-tile__byline">A. Name, B. Name, C. Name et al. · 2025</span>' . \Firehouse\Icons::svg( 'arrow-up-right', 16 ) . '</span><span class="fh-tile__foot"><span class="fh-tile__phases"><span class="fh-tile__phase">Before</span><span class="fh-tile__phase">Long-term</span></span></span></article>',
			Explorer_Page::tile(
				array(
					'url'            => 'https://example.test/firehouse/projects/one/',
					'title'          => 'One',
					'summary'        => 'A summary.',
					'projectType'    => 'Research Study',
					'completionYear' => 2025.0,
					'firePhases'     => array( 'before', 'not-a-phase', 'long-term' ),
					'authors'        => array( array( 'name' => 'A. Name' ), 'B. Plain', array( 'name' => 'B. Name' ), array( 'name' => null ), array( 'name' => 'C. Name' ), array( 'name' => 'D. Name' ) ),
				)
			)
		);
		$bare = Explorer_Page::tile(
			array(
				'url'            => 'https://example.test/firehouse/projects/two/',
				'title'          => 'Two',
				'completionYear' => 2024,
			)
		);
		$this->assertStringNotContainsString( 'fh-tile__kicker', $bare );
		$this->assertStringNotContainsString( 'fh-tile__foot', $bare );
		$this->assertStringContainsString( '<span class="fh-tile__byline">Unattributed · 2024</span>', $bare );
	}

	/**
	 * The words in the address and the researchers' words are printed as they are,
	 * escaped: a character reference in a title shows as typed.
	 */
	public function test_words_are_escaped_as_they_are(): void {
		$projects                   = self::projects();
		$projects[0]['title']       = 'R&amp;D <one>';
		$projects[0]['projectType'] = 'Type "<b>"';
		$html                       = self::render( 'q=%22%3E%3Cscript%3E&type=Type+%22%3Cb%3E%22', $projects );

		$this->assertStringContainsString( 'name="q" value="&quot;&gt;&lt;script&gt;"', $html );
		$this->assertStringContainsString( 'matching <strong>“&quot;&gt;&lt;script&gt;”</strong>', $html );
		$this->assertStringContainsString( 'aria-label="Remove filter Type: Type &quot;&lt;b&gt;&quot;">Type: Type &quot;&lt;b&gt;&quot;<svg', $html );
		$this->assertStringContainsString( '<option value="Type &quot;&lt;b&gt;&quot;" selected>Type &quot;&lt;b&gt;&quot; (0)</option>', $html );
		$html = self::render( '', $projects );
		$this->assertStringContainsString( '>R&amp;amp;D &lt;one&gt;</a></h3>', $html );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	/**
	 * The query is read from the address as the browser sent it, unslashed, and not
	 * from QUERY_STRING, which a server's rewrite rules can change.
	 */
	public function test_the_query_comes_from_the_address(): void {
		$server = $_SERVER;
		try {
			$_SERVER['REQUEST_URI']  = "/firehouse/projects/?q=O\\'Brien&q=second&phase=after";
			$_SERVER['QUERY_STRING'] = 'q=/firehouse/projects/&q=O%27Brien';
			Functions\when( 'wp_unslash' )->alias( 'stripslashes' );
			Functions\when( 'get_posts' )->justReturn( array() );
			Functions\when( 'get_post_type_archive_link' )->justReturn( self::URL );

			$html = Explorer_Page::for_request();

			$this->assertStringContainsString( 'name="q" value="O&#039;Brien"', $html );
			$this->assertStringContainsString( '<option value="after" selected>', $html );
			$this->assertStringContainsString( '<form class="fh-search" id="fh-explorer-form" method="get" action="' . self::URL . '">', $html );

			$_SERVER['REQUEST_URI'] = '/firehouse/projects/';
			$this->assertStringContainsString( 'name="q" value=""', Explorer_Page::for_request() );
		} finally {
			$_SERVER = $server; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- puts back what the test changed.
		}
	}

	/**
	 * The projects are those a visitor may see, in import order, each with its address.
	 * One the site's own test refuses, or without a slug, a title as text or a year as
	 * a number, is left out.
	 */
	public function test_the_projects_a_visitor_may_see(): void {
		// Each post's stored fields; one left out isn't stored at all.
		$stored = array(
			31 => array(
				'fh_title'          => 'First',
				'fh_completionYear' => '2025',
				'fh_irbApproved'    => '1',
			),
			32 => array(
				'fh_title'          => 'No approval',
				'fh_completionYear' => '2025',
				'fh_irbApproved'    => '',
			),
			33 => array(
				'fh_completionYear' => '2024',
				'fh_irbApproved'    => '1',
			),
			34 => array(
				'fh_title'       => 'No year',
				'fh_irbApproved' => '1',
			),
			35 => array(
				'fh_title'          => 'Last',
				'fh_completionYear' => '2019.5',
				'fh_irbApproved'    => '1',
			),
			36 => array(
				'fh_title'          => 'No slug',
				'fh_completionYear' => '2025',
				'fh_irbApproved'    => '1',
			),
		);
		Functions\expect( 'get_posts' )->once()->andReturnUsing(
			function ( array $args ) use ( $stored ): array {
				$this->assertSame(
					Store::public_projects_query(
						array(
							'numberposts' => -1,
							'orderby'     => 'ID',
							'order'       => 'ASC',
						)
					),
					$args
				);
				return array_map( static fn( int $id ): \WP_Post => self::post( $id, 36 === $id ? '' : null ), array_keys( $stored ) );
			}
		);
		Functions\when( 'metadata_exists' )->alias( static fn( string $type, int $id, string $key ): bool => array_key_exists( $key, $stored[ $id ] ) );
		Functions\when( 'get_post_meta' )->alias( static fn( int $id, string $key ): string => $stored[ $id ][ $key ] ?? '' );
		Functions\when( 'get_permalink' )->alias( static fn( \WP_Post $post ): string => self::URL . $post->post_name . '/' );

		$projects = Explorer_Page::projects();

		$this->assertSame( array( 'First', 'Last' ), array_column( $projects, 'title' ) );
		$this->assertSame( array( self::URL . 'p31/', self::URL . 'p35/' ), array_column( $projects, 'url' ) );
		$this->assertSame( array( 'p31', 'p35' ), array_column( $projects, 'slug' ) );
		$this->assertSame( array( 2025, 2019.5 ), array_column( $projects, 'completionYear' ) );
	}

	/**
	 * A published project post without a password.
	 *
	 * @param int    $id   Its ID.
	 * @param string $slug Its slug, or null for one made from the ID.
	 */
	private static function post( int $id, ?string $slug = null ): \WP_Post {
		return new \WP_Post(
			array(
				'ID'          => $id,
				'post_type'   => 'fh_project',
				'post_status' => 'publish',
				'post_name'   => $slug ?? 'p' . $id,
			)
		);
	}
}
