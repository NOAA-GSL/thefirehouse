<?php
/**
 * Tests for the Firehouse bar.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Settings;
use Firehouse\Subnav;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The bar links to the Firehouse pages, marks the current one, and renders the
 * site's header markup with the menu's Interactivity API directives.
 */
final class SubnavTest extends WordPressTestCase {

	/**
	 * The Firehouse root page's ID in these cases.
	 */
	private const ROOT = 7;

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
		Functions\when( 'plugins_url' )->alias( static fn( string $path ): string => 'https://example.test/wp-content/plugins/firehouse/' . $path );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component = -1 ): mixed => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- the stand-in for wp_parse_url.
		self::settings( array() );
		self::resolve( array() );
	}

	/**
	 * Stands in for the stored settings.
	 *
	 * @param array<string, mixed> $stored What get_option() returns.
	 */
	private static function settings( array $stored ): void {
		Functions\when( 'get_option' )->justReturn( $stored );
	}

	/**
	 * Stands in for the query WordPress ran.
	 *
	 * @param array<string, mixed> $query What it found: `archive` or `singular` (a post
	 *                                    type), or `page` (its ID), with `uri`.
	 */
	private static function resolve( array $query ): void {
		$page = isset( $query['page'] ) ? new \WP_Post(
			array(
				'ID'        => $query['page'],
				'post_type' => 'page',
			)
		) : null;
		Functions\when( 'is_post_type_archive' )->alias( static fn( string $type ): bool => ( $query['archive'] ?? null ) === $type );
		Functions\when( 'is_singular' )->alias( static fn( string $type ): bool => ( $query['singular'] ?? null ) === $type );
		Functions\when( 'is_page' )->justReturn( null !== $page );
		Functions\when( 'get_queried_object' )->justReturn( $page );
		Functions\when( 'get_posts' )->justReturn( array( self::ROOT ) );
		Functions\when( 'get_post_ancestors' )->justReturn( $query['ancestors'] ?? array() );
		Functions\when( 'get_page_uri' )->justReturn( $query['uri'] ?? '' );
	}

	/**
	 * Each kind of page and the link it marks as current.
	 *
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function pages(): array {
		return array(
			'the explorer'                     => array( array( 'archive' => 'fh_project' ), 'projects' ),
			'a project'                        => array( array( 'singular' => 'fh_project' ), 'projects' ),
			'a topic'                          => array( array( 'singular' => 'fh_topic' ), '' ),
			'the landing page'                 => array(
				array(
					'page' => self::ROOT,
					'uri'  => 'firehouse',
				),
				'home',
			),
			'the About page'                   => array(
				array(
					'page'      => 12,
					'ancestors' => array( self::ROOT ),
					'uri'       => 'firehouse/about',
				),
				'about',
			),
			'a page under About'               => array(
				array(
					'page'      => 13,
					'ancestors' => array( 12, self::ROOT ),
					'uri'       => 'firehouse/about/team',
				),
				'about',
			),
			'another page in the tree'         => array(
				array(
					'page'      => 14,
					'ancestors' => array( self::ROOT ),
					'uri'       => 'firehouse/about-us',
				),
				'',
			),
			'a page named so outside the tree' => array(
				array(
					'page' => 3,
					'uri'  => 'firehouse/about',
				),
				'',
			),
			'anything else'                    => array( array(), '' ),
		);
	}

	/**
	 * The current page's link, and the links in order with their addresses.
	 *
	 * @param array<string, mixed> $query   What the query found.
	 * @param string               $current The current link's key, or ''.
	 */
	#[DataProvider( 'pages' )]
	public function test_the_links_and_the_current_page( array $query, string $current ): void {
		self::resolve( $query );

		$this->assertSame( $current, Subnav::current() );
		$keys = array_keys( Settings::NAV );
		$this->assertSame(
			array(
				array(
					'label'   => 'Home',
					'url'     => 'https://example.test/firehouse/',
					'current' => 'home' === $current,
				),
				array(
					'label'   => 'Explore Projects',
					'url'     => 'https://example.test/firehouse/projects/',
					'current' => 'projects' === $current,
				),
				array(
					'label'   => 'About',
					'url'     => 'https://example.test/firehouse/about/',
					'current' => 'about' === $current,
				),
			),
			Subnav::links(),
			implode( ',', $keys )
		);
	}

	/**
	 * The bar and the drawer each carry every link, and only the current one is marked,
	 * as the site's header marks it.
	 */
	public function test_the_bar_and_the_drawer_mark_the_current_link(): void {
		self::resolve( array( 'archive' => 'fh_project' ) );
		$html = Subnav::render();

		$this->assertSame( 2, substr_count( $html, '<a class="fh-header__link" href="https://example.test/firehouse/">Home</a>' ) );
		$this->assertSame( 2, substr_count( $html, '<a class="fh-header__link fh-header__link--active" href="https://example.test/firehouse/projects/" aria-current="page">Explore Projects</a>' ) );
		$this->assertSame( 2, substr_count( $html, 'aria-current=' ) );
		$this->assertStringContainsString( 'href="https://example.test/firehouse/" class="fh-header__lockup" aria-label="The Firehouse — home"', $html );
		$this->assertStringContainsString( '<span class="fh-header__wordmark">The Firehouse</span>', $html );
		$this->assertSame( 2, substr_count( $html, 'aria-label="Firehouse"' ) );
		$this->assertStringNotContainsString( '<header', $html );
	}

	/**
	 * The menu: the button controls the drawer, which starts hidden, and the Interactivity
	 * API binds both to the context. The button keeps one name, and `aria-expanded`
	 * carries the state. No directive sits on an `<svg>`, which WordPress can't process
	 * when it renders the page.
	 */
	public function test_the_menu_starts_closed_and_binds_to_its_context(): void {
		$html = Subnav::render();

		$this->assertStringContainsString( '<div class="fh-header" data-wp-interactive="firehouse/subnav" data-wp-context=\'{"open":false}\' data-wp-on-document--keydown="actions.closeOnEscape" data-wp-on-window--pageshow="actions.closeOnRestore" data-wp-on--focusout="actions.closeOnFocusOut">', $html );
		$this->assertStringContainsString( 'aria-expanded="false" aria-controls="' . Subnav::DRAWER_ID . '" data-wp-bind--aria-expanded="context.open" data-wp-on--click="actions.toggle"', $html );
		$this->assertStringContainsString( '<div id="' . Subnav::DRAWER_ID . '" class="fh-header__drawer" hidden data-wp-bind--hidden="!context.open">', $html );
		$this->assertMatchesRegularExpression( '#<span class="fh-header__menu-state" data-wp-bind--hidden="context.open"><svg[^>]*>.*?</svg></span>#s', $html );
		$this->assertMatchesRegularExpression( '#<span class="fh-header__menu-state" hidden data-wp-bind--hidden="!context.open"><svg[^>]*>.*?</svg></span>#s', $html );
		$this->assertMatchesRegularExpression( '#<span class="fh-visually-hidden">Firehouse menu</span>\s*</button>#', $html );
		$this->assertStringNotContainsString( 'Open menu', $html );
		$this->assertDoesNotMatchRegularExpression( '#<svg[^>]*data-wp-#', $html );
		$this->assertSame( substr_count( $html, '<svg' ), substr_count( $html, 'aria-hidden="true" focusable="false"' ) );
	}

	/**
	 * The store and every action the markup names are ones view.js defines, so a rename
	 * on either side can't leave the menu dead.
	 */
	public function test_the_markup_names_what_view_js_defines(): void {
		$js   = (string) file_get_contents( dirname( __DIR__ ) . '/blocks/subnav/view.js' );
		$html = Subnav::render();

		$this->assertSame( 1, preg_match( '/data-wp-interactive="([^"]+)"/', $html, $store ) );
		$this->assertStringContainsString( "store( '" . $store[1] . "', {", $js );
		preg_match_all( '/"actions\.(\w+)"/', $html, $actions );
		$this->assertSame( array( 'closeOnEscape', 'closeOnRestore', 'closeOnFocusOut', 'toggle' ), array_values( array_unique( $actions[1] ) ) );
		foreach ( $actions[1] as $action ) {
			$this->assertMatchesRegularExpression( '/^\t\t' . $action . '\(/m', $js, $action );
		}
	}

	/**
	 * The settings' words are printed as text, never as markup.
	 */
	public function test_the_settings_are_escaped(): void {
		self::settings(
			array(
				'siteName'      => '<b>Site</b>',
				'submitLabel'   => '<i>Send</i>',
				'submitFormUrl' => 'https://forms.example.org/x',
				'navLabels'     => array( 'about' => '<script>x</script>' ),
			)
		);
		$html = Subnav::render();

		foreach ( array( '<b>', '<i>', '<script>' ) as $markup ) {
			$this->assertStringNotContainsString( $markup, $html );
		}
		$this->assertStringContainsString( '&lt;b&gt;Site&lt;/b&gt;', $html );
		$this->assertStringContainsString( '&lt;script&gt;x&lt;/script&gt;', $html );
	}

	/**
	 * A survey on another site opens in a new tab and says so, in the bar at the small
	 * size and in the drawer at the medium one; one on this site opens in place.
	 */
	public function test_the_submit_button_says_when_it_leaves_the_site(): void {
		self::settings( array( 'submitFormUrl' => 'https://docs.google.com/forms/d/x/viewform' ) );
		$html = Subnav::render();
		$this->assertSame( 1, substr_count( $html, '<a href="https://docs.google.com/forms/d/x/viewform" class="fh-btn fh-btn--accent fh-btn--sm" target="_blank" rel="noopener noreferrer"><svg' ) );
		$this->assertSame( 1, substr_count( $html, '<a href="https://docs.google.com/forms/d/x/viewform" class="fh-btn fh-btn--accent fh-btn--md" target="_blank" rel="noopener noreferrer"><svg' ) );
		$this->assertSame( 2, substr_count( $html, 'Submit a Finding<span class="fh-visually-hidden"> (opens in a new tab)</span></a>' ) );

		self::settings( array( 'submitFormUrl' => 'https://example.test/firehouse/submit/' ) );
		$html = Subnav::render();
		$this->assertSame( 2, substr_count( $html, 'href="https://example.test/firehouse/submit/"' ) );
		$this->assertStringNotContainsString( 'target=', $html );
		$this->assertStringNotContainsString( 'opens in a new tab', $html );
	}

	/**
	 * With no survey address yet, there's no Submit button to point at a wrong one.
	 */
	public function test_no_survey_address_no_submit_button(): void {
		$html = Subnav::render();

		$this->assertStringNotContainsString( 'fh-btn', $html );
		$this->assertStringNotContainsString( 'fh-header__cta', $html );
	}
}
