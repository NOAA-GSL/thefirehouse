<?php
/**
 * Tests for a topic area's page.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Topic_Page;

/**
 * A topic page shows the area's top needs, each traced to the published projects
 * behind it, with what the area covers and the other areas, as the site's does.
 */
final class TopicPageTest extends WordPressTestCase {

	/**
	 * Stands in for WordPress's addresses, escaping and shared JSON.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_json_file_decode' )->alias(
			static fn( string $filename, array $options = array() ): mixed => json_decode( (string) file_get_contents( $filename ), ! empty( $options['associative'] ) )
		);
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://example.test' . $path );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component = -1 ): mixed => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- the stand-in for wp_parse_url.
	}

	/**
	 * Two published projects, by post ID.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function projects(): array {
		return array(
			11 => array(
				'url'            => 'https://example.test/firehouse/projects/igniting-impact/',
				'slug'           => 'igniting-impact',
				'title'          => 'Igniting Impact: <Advancing> Decision Support',
				'completionYear' => 2026,
			),
			12 => array(
				'url'            => 'https://example.test/firehouse/projects/fwt-002/',
				'slug'           => 'fwt-002',
				'title'          => 'Fire Weather Testbed #002',
				'completionYear' => 2025,
			),
		);
	}

	/**
	 * The page for the Observations area, with whatever is changed.
	 *
	 * @param array<string, mixed>       $topic    Fields to change on the topic.
	 * @param list<array<string, mixed>> $needs    The needs, or null for two.
	 * @param array<string, mixed>       $settings Settings to change.
	 */
	private static function render( array $topic = array(), ?array $needs = null, array $settings = array() ): string {
		$needs ??= Topic_Page::needs(
			array(
				array(
					'text'     => 'Local fuel conditions in "Red Flag" decisions',
					'mentions' => 2,
					'projects' => array( 11, 12 ),
				),
				array(
					'text'     => 'Hotspot alerts by text',
					'mentions' => 1,
					'projects' => array( 12 ),
				),
			),
			self::projects()
		);
		return Topic_Page::render(
			$topic + array(
				'key'         => 'observe',
				'label'       => 'Observations and Monitoring',
				'short'       => 'Observations',
				'covers'      => array( 'Surface observation networks', 'Remote sensing & satellites' ),
				'updatedAt'   => '2026-09-17',
				'sourceCount' => 4,
				'model'       => 'Claude',
			),
			$needs,
			array(
				array(
					'key'   => 'forecast',
					'label' => 'Forecasts and Modeling',
					'short' => 'Forecasts',
				),
				array(
					'key'   => 'warning',
					'label' => 'Warnings and Immediate Response',
					'short' => 'Warnings',
				),
			),
			8,
			$settings + array(
				'submitLabel'   => 'Submit a Finding',
				'submitFormUrl' => 'https://forms.example.org/firehouse',
			)
		);
	}

	/**
	 * The page is the site's: the area's colours, the breadcrumb, its tag and heading,
	 * the needs with their counts and projects, what it covers, the Submit button and
	 * the other areas, in order.
	 */
	public function test_a_topic_page(): void {
		$html = self::render();

		$this->assertStringContainsString( '<div class="fh-topic" style="--topic-text: var(--topic-observe-text); --topic-tint: var(--topic-observe-tint); --topic-border: var(--topic-observe-border); --topic-fill: var(--topic-observe-fill)">', $html );
		$this->assertStringContainsString( '<nav class="fh-crumbs" aria-label="Breadcrumb">', $html );
		$this->assertStringContainsString( '<a href="https://example.test/firehouse/">Home</a>', $html );
		$this->assertStringContainsString( '<a href="https://example.test/firehouse/#top-needs">Top needs</a>', $html );
		$this->assertStringContainsString( '<span aria-current="page">Observations</span>', $html );
		$this->assertStringContainsString( '<span class="fh-topic-tag fh-topic-tag--md" style="--tag-text:var(--topic-observe-text);', $html );
		$this->assertStringContainsString( '<h1 class="fh-topic__heading">Observations and Monitoring</h1>', $html );
		$this->assertSame( 1, substr_count( $html, '<h1' ) );

		$this->assertMatchesRegularExpression( '#<h2 id="needs-heading" class="fh-topic__subheading">\s*Top 2 needs\s*</h2>#', $html );
		$this->assertStringContainsString( '<strong>AI-synthesized</strong> across 4 of 8 projects. <strong>Draft, pending team review</strong> · updated <time datetime="2026-09-17">September 17, 2026</time>.', $html );
		$this->assertStringContainsString( '<a href="https://example.test/firehouse/#needs-source">How these needs are synthesized</a>', $html );

		$this->assertStringContainsString( '<span class="fh-need__rank" aria-hidden="true">1</span>', $html );
		$this->assertStringContainsString( '<p class="fh-need__text">Local fuel conditions in &quot;Red Flag&quot; decisions</p>', $html );
		$this->assertStringContainsString( '<p class="fh-need__meta">Project count: 2</p>', $html );
		$this->assertStringContainsString( '<ul class="fh-need__sources" aria-label="Projects behind need 1">', $html );
		$this->assertMatchesRegularExpression( '#<a class="fh-need__source" href="https://example.test/firehouse/projects/igniting-impact/"><svg[^>]*width="14"[^>]*>.*?</svg><span class="fh-need__source-title">Igniting Impact: &lt;Advancing&gt; Decision Support</span><span class="fh-need__source-year">2026</span></a>#s', $html );
		$this->assertStringContainsString( '<ul class="fh-need__sources" aria-label="Projects behind need 2">', $html );

		$this->assertStringContainsString( '<aside class="fh-topic__aside" aria-labelledby="covers-heading">', $html );
		$this->assertStringContainsString( '<li>Remote sensing &amp; satellites</li>', $html );
		$this->assertMatchesRegularExpression( '#<a href="https://forms.example.org/firehouse" class="fh-btn fh-btn--accent fh-btn--sm" target="_blank" rel="noopener noreferrer"><svg[^>]*width="14"[^>]*>.*?</svg>Submit a Finding<span class="fh-visually-hidden"> \(opens in a new tab\)</span></a>#s', $html );

		$this->assertStringContainsString( '<nav class="fh-topic__more fh-container" aria-labelledby="more-heading">', $html );
		$forecast = strpos( $html, 'href="https://example.test/firehouse/topics/forecast/"' );
		$warning  = strpos( $html, 'href="https://example.test/firehouse/topics/warning/"' );
		$this->assertNotFalse( $forecast );
		$this->assertGreaterThan( $forecast, $warning );
		$this->assertStringContainsString( '<span class="fh-topic__more-label">Forecasts and Modeling</span>', $html );
		$this->assertStringNotContainsString( 'topics/observe/', $html );
	}

	/**
	 * With no needs yet, the page says so and the heading drops the count.
	 */
	public function test_a_topic_with_no_needs(): void {
		$html = self::render( array(), array() );

		$this->assertMatchesRegularExpression( '#<h2 id="needs-heading" class="fh-topic__subheading">\s*Top needs\s*</h2>#', $html );
		$this->assertStringContainsString( '<p class="fh-topic__empty">No needs have been published for this area yet.</p>', $html );
		$this->assertStringNotContainsString( 'fh-topic__needs-list', $html );
	}

	/**
	 * A need with no count and no projects shows neither; one with a count of one says
	 * "need", singular.
	 */
	public function test_a_need_shows_only_what_it_has(): void {
		$html = self::render( array(), Topic_Page::needs( array( array( 'text' => 'A need on its own' ) ), array() ) );

		$this->assertMatchesRegularExpression( '#<h2 id="needs-heading" class="fh-topic__subheading">\s*Top 1 need\s*</h2>#', $html );
		$this->assertStringContainsString( '<p class="fh-need__text">A need on its own</p>', $html );
		$this->assertStringNotContainsString( 'fh-need__meta', $html );
		$this->assertStringNotContainsString( 'fh-need__sources', $html );
	}

	/**
	 * Without what it covers, the aside isn't labelled by a heading it doesn't hold;
	 * without a survey address, there's no Submit button to go nowhere; with neither,
	 * there's no empty landmark.
	 */
	public function test_the_aside_holds_only_what_there_is(): void {
		$html = self::render( array( 'covers' => array() ) );
		$this->assertStringContainsString( '<aside class="fh-topic__aside">', $html );
		$this->assertStringNotContainsString( 'covers-heading', $html );
		$this->assertStringContainsString( 'fh-topic__contribute', $html );

		$html = self::render( array(), null, array( 'submitFormUrl' => '' ) );
		$this->assertStringContainsString( '<aside class="fh-topic__aside" aria-labelledby="covers-heading">', $html );
		$this->assertStringNotContainsString( 'fh-topic__contribute', $html );
		$this->assertStringNotContainsString( 'fh-btn', $html );

		$html = self::render( array( 'covers' => array() ), null, array( 'submitFormUrl' => '' ) );
		$this->assertStringNotContainsString( '<aside', $html );
	}

	/**
	 * Without a source count, the synthesis is taken to have read every published
	 * project; with a reviewer, it isn't a draft.
	 */
	public function test_the_synthesis_note_reads_the_topic(): void {
		$html = self::render(
			array(
				'sourceCount' => null,
				'reviewedBy'  => 'Pat Reviewer',
			)
		);

		$this->assertStringContainsString( '<p class="fh-synthesis-note"><span class="fh-synthesis-note__dot" aria-hidden="true"></span><span><strong>AI-synthesized</strong> across all 8 projects. Reviewed by Pat Reviewer on <time datetime="2026-09-17">September 17, 2026</time>.</span></p>', $html );
	}

	/**
	 * A key outside the vocabulary can't name a CSS variable or an address of its own:
	 * it gets the first area's.
	 */
	public function test_an_unknown_key_is_the_first_area(): void {
		$html = self::render( array( 'key' => 'x); color: red' ) );

		$this->assertStringContainsString( '--topic-text: var(--topic-observe-text)', $html );
		$this->assertStringNotContainsString( 'color: red', $html );
	}

	/**
	 * The needs keep the synthesis's order and its counts. A project that isn't
	 * published is left out and the need is recounted, as the site's normalize step
	 * does; a need that names no projects keeps its stored count.
	 */
	public function test_the_needs_name_only_published_projects(): void {
		$needs = Topic_Page::needs(
			array(
				array(
					'text'     => 'All published',
					'mentions' => 3,
					'projects' => array( 12, 11 ),
				),
				array(
					'text'     => 'One a draft',
					'mentions' => 3,
					'projects' => array( 11, 99 ),
				),
				array(
					'text'     => 'No count stored',
					'projects' => array( 11, 12 ),
				),
				array(
					'text'     => 'No projects named',
					'mentions' => 4,
				),
				array(
					'text'     => 'A zero kept',
					'mentions' => 0,
					'projects' => array(),
				),
			),
			self::projects()
		);

		$this->assertSame( array( 'All published', 'One a draft', 'No count stored', 'No projects named', 'A zero kept' ), array_column( $needs, 'text' ) );
		$this->assertSame( array( 3, 1, 2, 4, 0 ), array_column( $needs, 'mentions' ) );
		$this->assertSame( array( 'fwt-002', 'igniting-impact' ), array_column( $needs[0]['sources'], 'slug' ) );
		$this->assertSame( array( 'igniting-impact' ), array_column( $needs[1]['sources'], 'slug' ) );
		$this->assertSame( array(), $needs[3]['sources'] );
	}

	/**
	 * A stored need that isn't a need, or has no text to show, is passed over: not a
	 * fatal error on a public page, nor a blank item.
	 */
	public function test_a_malformed_need_is_passed_over(): void {
		$malformed = array( 'text', 3, null, new \stdClass(), array(), array( 'text' => array( 'A need' ) ), array( 'text' => 3 ), array( 'text' => '' ), array( 'text' => " \n" ) );

		$needs = Topic_Page::needs( array_merge( $malformed, array( array( 'text' => 'A need' ) ) ), array() );

		$this->assertSame( array( 'A need' ), array_column( $needs, 'text' ) );
		$this->assertSame( array(), Topic_Page::projects( array( 'text', null, new \stdClass(), array( 'projects' => 'x' ) ) ) );
	}

	/**
	 * The page shows the first five needs, and one passed over doesn't take a place.
	 */
	public function test_the_top_five_are_needs(): void {
		$needs = array( array( 'text' => 'One' ), array(), array( 'text' => '' ) );
		foreach ( array( 'Two', 'Three', 'Four', 'Five', 'Six' ) as $text ) {
			$needs[] = array( 'text' => $text );
		}

		$this->assertSame( array( 'One', 'Two', 'Three', 'Four', 'Five' ), array_column( Topic_Page::top( $needs ), 'text' ) );
		$this->assertSame( array(), Topic_Page::top( 'not needs' ) );
		$this->assertSame( array(), Topic_Page::top( null ) );
	}

	/**
	 * The projects behind the needs come in one lookup of their post IDs, of projects a
	 * visitor may see (Store::public_projects_query()). Each has its own address.
	 */
	public function test_the_projects_are_published_ones_by_id(): void {
		Functions\expect( 'get_posts' )->once()->andReturnUsing(
			function ( array $args ): array {
				$this->assertSame( \Firehouse\Store::public_projects_query(), array_diff_key( $args, array_flip( array( 'post__in', 'numberposts' ) ) ) );
				$this->assertSame( array( 11, 12, 13 ), $args['post__in'] );
				$this->assertSame( -1, $args['numberposts'] );
				return array(
					self::post( 12, 'fwt-002' ),
				);
			}
		);
		Functions\when( 'metadata_exists' )->alias( static fn( string $type, int $id, string $key ): bool => 'fh_irbApproved' === $key );
		Functions\when( 'get_post_meta' )->justReturn( '1' );
		Functions\when( 'get_permalink' )->alias( static fn( \WP_Post $post ): string => 'https://example.test/firehouse/projects/' . $post->post_name . '/' );

		$projects = Topic_Page::projects(
			array(
				array( 'projects' => array( 11, 12, 'x', 0 ) ),
				array( 'projects' => array( 12, 13 ) ),
			)
		);

		$this->assertSame( array( 12 ), array_keys( $projects ) );
		$this->assertSame( 'https://example.test/firehouse/projects/fwt-002/', $projects[12]['url'] );
		$this->assertSame( 'fwt-002', $projects[12]['slug'] );
	}

	/**
	 * A project the query returns is still left out unless its approval reads back as
	 * exactly true, the site's own test: a value written around Meta, such as "1 " or a
	 * missing key, mustn't pass the database's comparison and fail the site's.
	 *
	 * @dataProvider unapproved
	 *
	 * @param bool  $exists Whether the approval is stored.
	 * @param mixed $stored What is stored.
	 */
	public function test_an_unapproved_project_is_left_out( bool $exists, mixed $stored ): void {
		Functions\when( 'get_posts' )->justReturn( array( self::post( 11, 'igniting-impact' ) ) );
		Functions\when( 'metadata_exists' )->alias( static fn( string $type, int $id, string $key ): bool => $exists && 'fh_irbApproved' === $key );
		Functions\when( 'get_post_meta' )->justReturn( $stored );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.test/firehouse/projects/igniting-impact/' );

		$this->assertSame( array(), Topic_Page::projects( array( array( 'projects' => array( 11 ) ) ) ) );
	}

	/**
	 * Approvals that aren't true.
	 *
	 * @return array<string, array{0: bool, 1: mixed}>
	 */
	public static function unapproved(): array {
		return array(
			'false'           => array( true, '' ),
			'missing'         => array( false, '' ),
			'a trailing gap'  => array( true, '1 ' ),
			'text that reads' => array( true, 'true' ),
		);
	}

	/**
	 * A published project post.
	 *
	 * @param int    $id   Its ID.
	 * @param string $slug Its slug.
	 */
	private static function post( int $id, string $slug ): \WP_Post {
		return new \WP_Post(
			array(
				'ID'          => $id,
				'post_type'   => 'fh_project',
				'post_name'   => $slug,
				'post_status' => 'publish',
			)
		);
	}
}
