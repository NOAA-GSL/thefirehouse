<?php
/**
 * Tests for the landing page's blocks.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Landing_Page;

/**
 * The landing page is the site's: the hero with its live counts, a card for each
 * published topic area with its first needs as the topic pages count them, where the
 * needs come from, and the submit band; the counts come from the projects a visitor may
 * see. Its words are blocks' attributes, built from the bundle's landing content.
 */
final class LandingPageTest extends WordPressTestCase {

	/**
	 * The survey address the settings hold.
	 */
	private const SURVEY = 'https://forms.example.org/firehouse';

	/**
	 * Stands in for WordPress: three published projects, of which 12 has no IRB
	 * approval; two published topics and a draft one, with their syntheses; and the
	 * settings, with a survey address.
	 */
	protected function setUp(): void {
		parent::setUp();
		Landing_Page::forget();
		self::stand_in_serialize_blocks();
		Functions\stubEscapeFunctions();
		Functions\when( 'esc_html' )->alias( static fn( string $text ): string => htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false ) );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_json_file_decode' )->alias(
			static fn( string $filename, array $options = array() ): mixed => json_decode( (string) file_get_contents( $filename ), ! empty( $options['associative'] ) )
		);
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://example.test' . $path );
		Functions\when( 'plugins_url' )->alias( static fn( string $path ): string => '/wp-content/plugins/firehouse/' . $path );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component = -1 ): mixed => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- the stand-in for wp_parse_url.
		Functions\when( 'wp_specialchars_decode' )->alias( static fn( string $text ): string => htmlspecialchars_decode( $text, ENT_QUOTES ) );
		Functions\when( 'get_option' )->justReturn( array( 'submitFormUrl' => self::SURVEY ) );
		Functions\when( 'get_permalink' )->alias( static fn( \WP_Post $post ): string => 'https://example.test/firehouse/projects/' . $post->post_name . '/' );

		$meta = self::meta();
		Functions\when( 'get_posts' )->alias(
			static function ( array $args ): array {
				$posts = array_map(
					static fn( int $id ): \WP_Post => new \WP_Post(
						array(
							'ID'          => $id,
							'post_type'   => $id < 20 ? 'fh_project' : 'fh_topic',
							'post_status' => 23 === $id ? 'draft' : 'publish',
							'post_name'   => array(
								11 => 'smoke-alerts',
								12 => 'unapproved',
								13 => 'red-flag',
								21 => 'warning',
								22 => 'observe',
								23 => 'forecast',
							)[ $id ],
							'post_title'  => array(
								21 => 'Warnings &amp; Response',
								22 => 'Observations and Monitoring',
								23 => 'Forecasts and Modeling',
							)[ $id ] ?? '',
						)
					),
					'fh_project' === $args['post_type'] ? array( 11, 12, 13 ) : array( 21, 22, 23 )
				);
				return isset( $args['post__in'] ) ? array_values( array_filter( $posts, static fn( \WP_Post $post ): bool => in_array( $post->ID, $args['post__in'], true ) ) ) : $posts;
			}
		);
		Functions\when( 'metadata_exists' )->alias( static fn( string $type, int $id, string $key ): bool => isset( $meta[ $id ][ $key ] ) );
		Functions\when( 'get_post_meta' )->alias( static fn( int $id, string $key ): mixed => $meta[ $id ][ $key ] ?? '' );
	}

	/**
	 * What each post stores: the projects' approval, title, year and needs, and the
	 * topics' order, short label and synthesis, each need naming projects by post ID.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function meta(): array {
		$synthesis = array(
			'fh_updatedAt'   => '2026-09-17',
			'fh_sourceCount' => 2,
			'fh_model'       => 'Claude',
		);
		return array(
			11 => array(
				'fh_irbApproved'    => '1',
				'fh_title'          => 'Smoke alerts',
				'fh_completionYear' => '2025',
				'fh_needs'          => array(
					array( 'need' => 'Need for alerts: by text.' ),
					array( 'recommendation' => 'On its own.' ),
					array( 'need' => '0' ),
				),
			),
			12 => array(
				'fh_irbApproved' => '',
				'fh_title'       => 'Unapproved',
				'fh_needs'       => array( array( 'need' => 'Not counted.' ) ),
			),
			13 => array(
				'fh_irbApproved'    => '1',
				'fh_title'          => 'Red Flag <study>',
				'fh_completionYear' => '2026',
				'fh_needs'          => array( array( 'need' => 'Need for fuel data: in warnings.' ) ),
			),
			21 => array(
				'fh_order'    => '2',
				'fh_short'    => 'Warnings',
				'fh_topNeeds' => array(),
			) + array_map( 'strval', $synthesis ),
			22 => array(
				'fh_order'    => '1',
				'fh_short'    => 'Observations',
				'fh_topNeeds' => array(
					array(
						'text'     => 'Hotspot alerts by text',
						'mentions' => 2,
						'projects' => array( 11, 12 ),
					),
					array(
						'text'     => 'Fuel conditions in "Red Flag" decisions',
						'mentions' => 1,
						'projects' => array( 13 ),
					),
					array(
						'text'     => 'A third need',
						'mentions' => 1,
						'projects' => array(),
					),
				),
			) + array_map( 'strval', $synthesis ),
			23 => array(
				'fh_order'    => '3',
				'fh_short'    => 'Forecasts',
				'fh_topNeeds' => array(),
			),
		);
	}

	/**
	 * The landing content as the bundle has it, normalized: its submit links already
	 * carry the bundle's survey address.
	 *
	 * @return array<string, mixed>
	 */
	private static function landing(): array {
		return array(
			'hero'         => array(
				'eyebrow'      => 'NOAA / Global Systems Laboratory',
				'heading'      => 'A shared home for fire weather social science',
				'body'         => 'What\'s already known -- and how to add to it.',
				'primaryCta'   => array(
					'label' => 'Submit a Finding',
					'href'  => self::SURVEY,
				),
				'secondaryCta' => array(
					'label' => 'Explore Projects',
					'to'    => '/projects',
				),
				'imageAlt'     => '',
			),
			'stats'        => array(
				array(
					'id'      => 'projects',
					'source'  => 'publishedProjectCount',
					'label'   => 'Projects analyzed',
					'caption' => 'Completed research',
				),
				array(
					'id'     => 'needs',
					'source' => 'needCount',
					'label'  => 'End-user needs',
				),
				array(
					'id'     => 'open',
					'source' => 'static',
					'value'  => 'Open',
					'label'  => 'For submissions',
					'accent' => true,
				),
			),
			'topicSection' => array(
				'eyebrow' => 'Synthesized across every project',
				'heading' => 'Top needs across submitted projects',
				'body'    => 'Read together <as one body of evidence>.',
			),
			'needsPerCard' => 2,
			'submitBand'   => array(
				'heading' => 'Have a finding to share?',
				'body'    => 'Describe what you learned.',
				'cta'     => array(
					'label' => 'Submit a Finding',
					'href'  => 'https://elsewhere.example.org/survey',
				),
			),
		);
	}

	/**
	 * The page's blocks: the hero with the counts inside it, the top needs, and the
	 * submit band, each with its words as attributes. A link to the bundle's survey
	 * address is stored empty, so it follows the settings; any other is kept.
	 */
	public function test_the_blocks_built_from_the_bundle(): void {
		$blocks = Landing_Page::blocks( self::landing(), self::SURVEY );

		$this->assertMatchesRegularExpression( '#^<!-- wp:firehouse/hero (\{.*?\}) --><!-- wp:firehouse/stats (\{.*?\}) /--><!-- /wp:firehouse/hero --><!-- wp:firehouse/top-needs (\{.*?\}) /--><!-- wp:firehouse/submit-band (\{.*?\}) /-->$#', $blocks );
		preg_match_all( '#<!-- wp:\S+ (\{.*?\}) /?-->#', $blocks, $found );
		$attributes = array_map( static fn( string $json ): array => json_decode( $json, true ), $found[1] );

		$this->assertSame(
			array(
				'eyebrow'      => 'NOAA / Global Systems Laboratory',
				'heading'      => 'A shared home for fire weather social science',
				'body'         => 'What\'s already known -- and how to add to it.',
				'primaryCta'   => array(
					'label' => 'Submit a Finding',
					'href'  => '',
				),
				'secondaryCta' => array(
					'label' => 'Explore Projects',
					'to'    => '/projects',
				),
			),
			$attributes[0]
		);
		$this->assertSame( array( 'items' => self::landing()['stats'] ), $attributes[1] );
		$this->assertSame( self::landing()['topicSection'] + array( 'needsPerCard' => 2 ), $attributes[2] );
		$this->assertSame( 'https://elsewhere.example.org/survey', $attributes[3]['cta']['href'] );
		$this->assertStringNotContainsString( '--', str_replace( array( '<!--', '-->' ), '', $blocks ) );
		$this->assertStringNotContainsString( '<as', $blocks );
	}

	/**
	 * A link goes to a Firehouse route under /firehouse/, its fragment kept; to an
	 * address as given; or, stored empty, to the settings' survey address. Anything
	 * else, or no survey address, is nowhere.
	 */
	public function test_where_a_link_goes(): void {
		$this->assertSame( 'https://example.test/firehouse/projects/', Landing_Page::link( array( 'to' => '/projects' ) ) );
		$this->assertSame( 'https://example.test/firehouse/', Landing_Page::link( array( 'to' => '/' ) ) );
		$this->assertSame( 'https://example.test/firehouse/#top-needs', Landing_Page::link( array( 'to' => '/#top-needs' ) ) );
		$this->assertSame( 'https://elsewhere.example.org/', Landing_Page::link( array( 'href' => 'https://elsewhere.example.org/' ) ) );
		$this->assertSame( self::SURVEY, Landing_Page::link( array( 'href' => '' ) ) );
		$this->assertSame( self::SURVEY, Landing_Page::link( array() ) );
		$this->assertSame( '', Landing_Page::link( 'https://example.org/' ) );

		Functions\when( 'get_option' )->justReturn( array() );
		$this->assertSame( '', Landing_Page::link( array( 'href' => '' ) ) );
	}

	/**
	 * The hero is the site's: the wordmark, the words, the Submit and Explore buttons
	 * with their styles, and the counts beside them.
	 */
	public function test_the_hero(): void {
		$attributes = json_decode( (string) preg_replace( '#^<!-- wp:firehouse/hero (\{.*?\}) -->.*$#s', '$1', Landing_Page::blocks( self::landing(), self::SURVEY ) ), true );
		$html       = Landing_Page::hero( $attributes, '[stats]' );

		$this->assertStringStartsWith( '<section class="fh-hero"><div class="fh-hero__media" aria-hidden="true"><div class="fh-hero__smoke"></div><div class="fh-hero__scrim"></div></div><div class="fh-hero__content fh-container"><div class="fh-hero__grid"><div class="fh-hero__panel"><img class="fh-brand fh-brand--dark fh-hero__logo" src="/wp-content/plugins/firehouse/assets/logos/firehouse-wordmark-on-dark.png" alt="FireHouse — Fire Weather Research Hub" style="height:72px"><span class="fh-hero__eyebrow">NOAA / Global Systems Laboratory</span><h1 class="fh-hero__heading">A shared home for fire weather social science</h1><p class="fh-hero__body">What&#039;s already known -- and how to add to it.</p><div class="fh-hero__actions">', $html );
		$this->assertMatchesRegularExpression( '#<a href="https://forms.example.org/firehouse" class="fh-btn fh-btn--accent fh-btn--lg fh-btn--block-mobile" target="_blank" rel="noopener noreferrer"><svg[^>]*width="18"[^>]*>.*?</svg>Submit a Finding<span class="fh-visually-hidden"> \(opens in a new tab\)</span></a><a href="https://example.test/firehouse/projects/" class="fh-btn fh-btn--secondary fh-btn--lg fh-btn--on-dark fh-btn--block-mobile">Explore Projects<svg[^>]*width="18"[^>]*>.*?</svg></a></div></div>\[stats\]</div></div><span class="fh-hero__horizon" aria-hidden="true"></span></section>$#s', $html );
		$this->assertSame( 1, substr_count( $html, '<h1' ) );

		Functions\when( 'get_option' )->justReturn( array() );
		$this->assertStringNotContainsString( 'Submit a Finding', Landing_Page::hero( $attributes, '' ) );
	}

	/**
	 * The counts are the site's: the projects a visitor may see, the needs they record
	 * (an entry with a need, "0" included, but not a recommendation alone), and a static
	 * value as given, the accent on the one that has it.
	 */
	public function test_the_counts(): void {
		$html = Landing_Page::stats( array( 'items' => self::landing()['stats'] ) );

		$this->assertSame(
			'<ul class="fh-hero__stats" aria-label="Hub statistics">'
				. '<li class="fh-stat-pane"><div class="fh-stat"><span class="fh-stat__value">2</span><span class="fh-stat__label">Projects analyzed</span><span class="fh-stat__caption">Completed research</span></div></li>'
				. '<li class="fh-stat-pane"><div class="fh-stat"><span class="fh-stat__value">3</span><span class="fh-stat__label">End-user needs</span></div></li>'
				. '<li class="fh-stat-pane"><div class="fh-stat"><span class="fh-stat__value fh-stat__value--accent">Open</span><span class="fh-stat__label">For submissions</span></div></li>'
				. '</ul>',
			$html
		);
	}

	/**
	 * The top needs: the section's words, the synthesis note without its review status,
	 * a card for each published area in order with its first needs, counted among the
	 * projects a visitor may see, and where the needs come from, naming the tool.
	 */
	public function test_the_top_needs(): void {
		$html = Landing_Page::top_needs( self::landing()['topicSection'] + array( 'needsPerCard' => 2 ) );

		$this->assertStringStartsWith( '<section class="fh-topics fh-container" id="top-needs" aria-labelledby="topics-heading"><div class="fh-topics__intro"><span class="fh-eyebrow">Synthesized across every project</span><h2 id="topics-heading" class="fh-topics__heading">Top needs across submitted projects</h2><p class="fh-topics__body">Read together &lt;as one body of evidence&gt;.</p><p class="fh-synthesis-note fh-topics__note"><span class="fh-synthesis-note__dot" aria-hidden="true"></span><span><strong>AI-synthesized</strong> across all 2 projects.</span></p></div>', $html );

		$this->assertSame( 2, substr_count( $html, '<article class="fh-topic-card fh-glass"' ) );
		$this->assertLessThan( strpos( $html, 'href="https://example.test/firehouse/topics/warning/"' ), strpos( $html, 'href="https://example.test/firehouse/topics/observe/"' ) );
		$this->assertStringNotContainsString( 'topics/forecast', $html );
		$this->assertStringContainsString( '<article class="fh-topic-card fh-glass" style="--card-tint: var(--topic-observe-tint)"><span class="fh-topic-card__wash" aria-hidden="true"></span><span class="fh-topic-tag fh-topic-tag--md"', $html );
		$this->assertStringContainsString( '<h3 class="fh-topic-card__title"><a class="fh-topic-card__link" href="https://example.test/firehouse/topics/observe/">Observations and Monitoring</a></h3><ol class="fh-needs"><li class="fh-needs__item"><span class="fh-needs__rank" aria-hidden="true">1</span><span class="fh-needs__text">Hotspot alerts by text</span><span class="fh-needs__count"><span class="fh-visually-hidden">. </span>Project count: 1</span></li><li class="fh-needs__item"><span class="fh-needs__rank" aria-hidden="true">2</span><span class="fh-needs__text">Fuel conditions in &quot;Red Flag&quot; decisions</span><span class="fh-needs__count"><span class="fh-visually-hidden">. </span>Project count: 1</span></li></ol>', $html );
		$this->assertStringNotContainsString( 'A third need', $html );
		$this->assertStringContainsString( '<a class="fh-topic-card__link" href="https://example.test/firehouse/topics/warning/">Warnings &amp; Response</a></h3><p class="fh-topic-card__empty">No needs have been published for this area yet.</p>', $html );
		$this->assertMatchesRegularExpression( '#<div class="fh-topic-card__foot"><span class="fh-topic-card__cue" aria-hidden="true">View topic area and sources<svg[^>]*width="15"[^>]*>.*?</svg></span></div></article>#s', $html );

		$this->assertStringContainsString( '<aside class="fh-source" id="needs-source" aria-labelledby="source-heading"><div class="fh-source__copy"><h3 id="source-heading" class="fh-source__heading">Where these needs come from</h3><p class="fh-source__body">Topic areas are not assigned project by project. Every project in The Firehouse is read together, with Claude. The end-user needs', $html );
		$this->assertMatchesRegularExpression( '#</p></div><a href="https://example.test/firehouse/projects/" class="fh-btn fh-btn--secondary fh-btn--md">Explore all projects<svg[^>]*>.*?</svg></a></aside></section>$#s', $html );
	}

	/**
	 * The submit band: its words and the Submit button, which is left out with no
	 * address to go to.
	 */
	public function test_the_submit_band(): void {
		$attributes = array(
			'heading' => 'Have a finding to share?',
			'body'    => 'Describe what you learned.',
			'cta'     => array(
				'label' => 'Submit a Finding',
				'href'  => '',
			),
		);

		$this->assertMatchesRegularExpression( '#^<section class="fh-cta-band" aria-labelledby="submit-heading"><div class="fh-cta-band__inner fh-container fh-glass"><span class="fh-cta-band__glow" aria-hidden="true"></span><div class="fh-cta-band__copy"><h2 id="submit-heading" class="fh-cta-band__heading">Have a finding to share\?</h2><p class="fh-cta-band__body">Describe what you learned.</p></div><a href="https://forms.example.org/firehouse" class="fh-btn fh-btn--accent fh-btn--lg fh-btn--block-mobile" target="_blank" rel="noopener noreferrer">.*?Submit a Finding.*?</a></div></section>$#s', Landing_Page::submit_band( $attributes ) );

		Functions\when( 'get_option' )->justReturn( array() );
		$this->assertStringNotContainsString( 'fh-btn', Landing_Page::submit_band( $attributes ) );
	}

	/**
	 * A route keeps its query and fragment, and gets the leading slash it lacks.
	 */
	public function test_a_route_keeps_its_query(): void {
		$this->assertSame( 'https://example.test/firehouse/projects/?topics=warning', Landing_Page::link( array( 'to' => '/projects?topics=warning' ) ) );
		$this->assertSame( 'https://example.test/firehouse/projects/', Landing_Page::link( array( 'to' => 'projects' ) ) );
		$this->assertSame( 'https://example.test/firehouse/projects/#grid', Landing_Page::link( array( 'to' => '/projects/#grid' ) ) );
	}

	/**
	 * A card shows as many needs as the block asks for, past the topic pages' five, or
	 * none; a topic with no short label gets the vocabulary's on its tag.
	 */
	public function test_a_card_shows_what_the_block_asks_for(): void {
		$needs = array_map(
			static fn( int $n ): array => array(
				'text'     => 'Need ' . $n,
				'mentions' => 1,
			),
			range( 1, 7 )
		);
		$topic = array(
			'key'      => 'observe',
			'label'    => 'Observations and Monitoring',
			'topNeeds' => $needs,
		);

		$this->assertCount( 6, Landing_Page::cards( array( $topic ), 6, array() )[0]['needs'] );
		$this->assertCount( 0, Landing_Page::cards( array( $topic ), 0, array() )[0]['needs'] );
		$this->assertNull( Landing_Page::cards( array( $topic ), 2, array() )[0]['short'] );
	}

	/**
	 * A stat's accent reads as JavaScript reads it: any value that isn't falsy.
	 */
	public function test_a_stats_accent_is_javascripts(): void {
		$stat = array(
			'source' => 'static',
			'value'  => 'Open',
			'label'  => 'For submissions',
		);

		$this->assertStringContainsString( 'fh-stat__value--accent', Landing_Page::stats( array( 'items' => array( $stat + array( 'accent' => 'yes' ) ) ) ) );
		$this->assertStringNotContainsString( 'fh-stat__value--accent', Landing_Page::stats( array( 'items' => array( $stat + array( 'accent' => '' ) ) ) ) );
	}

	/**
	 * A button whose address WordPress refuses is left out, not printed going nowhere.
	 */
	public function test_a_refused_address_has_no_button(): void {
		Functions\when( 'esc_url_raw' )->alias( static fn( string $url ): string => str_starts_with( $url, 'https://' ) ? $url : '' );
		$attributes = array(
			'heading' => 'Have a finding to share?',
			'body'    => 'Describe what you learned.',
			'cta'     => array(
				'label' => 'Submit a Finding',
				'href'  => 'javascript:alert(1)',
			),
		);

		$this->assertStringNotContainsString( 'fh-btn', Landing_Page::submit_band( $attributes ) );
		$this->assertStringNotContainsString( 'fh-btn--accent', Landing_Page::hero( array( 'primaryCta' => $attributes['cta'] ), '' ) );
	}

	/**
	 * Only a link's label and where it goes, and only what a stat has, reach the page:
	 * nothing else in the bundle is stored.
	 */
	public function test_unknown_keys_dont_reach_the_page(): void {
		$landing                               = self::landing();
		$landing['hero']['primaryCta']['note'] = 'not for the page';
		$landing['stats'][0]['secret']         = 'not for the page either';
		$landing['hero']['heading']            = array( 'not text, so not for the page' );
		$landing['submitBand']['cta']['label'] = array( 'nor this' );
		$landing['topicSection']['body']       = 7;

		$blocks = Landing_Page::blocks( $landing, self::SURVEY );

		$this->assertStringNotContainsString( 'not for the page', $blocks );
		$this->assertStringNotContainsString( 'nor this', $blocks );
		$this->assertStringContainsString( '"heading":""', $blocks );
		$this->assertStringContainsString( '"accent":true', $blocks );
	}

	/**
	 * What the page is computed from is read once a request: the projects a visitor may
	 * see with their needs, and the published topics in order. The cards count from it,
	 * with no lookup of their own.
	 */
	public function test_the_content_is_read_once(): void {
		$content = Landing_Page::content();

		$this->assertCount( 2, $content['projects'] );
		$this->assertSame( array( 'observe', 'warning' ), array_column( $content['topics'], 'key' ) );
		$this->assertSame( array( 'observe', 'warning' ), array_column( $content['topicSummaries'], 'topic' ) );

		Functions\expect( 'get_posts' )->never();
		$this->assertSame( $content, Landing_Page::content() );
		Landing_Page::top_needs( array( 'needsPerCard' => 2 ) );
	}
}
