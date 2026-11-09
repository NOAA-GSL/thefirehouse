<?php
/**
 * Tests for the Firehouse not-found page.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Not_Found;

/**
 * A missing address gets the site's 404: the way back, the explorer, the four topic
 * areas, and the requested address, escaped and cut short.
 */
final class NotFoundTest extends WordPressTestCase {

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
		Functions\when( 'wp_unslash' )->alias( 'stripslashes' );
		// Not_Found always strips, so the stand-in does.
		Functions\when( 'wp_check_invalid_utf8' )->alias( static fn( string $text ): string => (string) iconv( 'UTF-8', 'UTF-8//IGNORE', $text ) );
	}

	/**
	 * Clears the main request after each test.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wp'] );
		parent::tearDown();
	}

	/**
	 * Renders the page for a request path.
	 *
	 * @param string $request The path, without its slashes.
	 */
	private static function render( string $request ): string {
		$wp            = new \WP();
		$wp->request   = $request;
		$GLOBALS['wp'] = $wp; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the main request, stood in for.
		return Not_Found::render();
	}

	/**
	 * The page says what happened and offers the hub's structure, as the site's does.
	 */
	public function test_a_missing_page_offers_the_way_back(): void {
		$html = self::render( 'firehouse/nothing-here' );

		$this->assertStringContainsString( '<span class="fh-eyebrow">Error 404</span>', $html );
		$this->assertStringContainsString( '<h1 class="fh-not-found__heading">Page not found</h1>', $html );
		$this->assertStringContainsString( 'That page doesn’t exist, or it may have moved.', $html );
		$this->assertStringContainsString( 'Requested address <code class="fh-not-found__path">/firehouse/nothing-here</code>', $html );
		$this->assertMatchesRegularExpression( '#<a href="https://example.test/firehouse/" class="fh-btn fh-btn--primary fh-btn--md">Back to The Firehouse<svg[^>]*width="16"#', $html );
		$this->assertStringContainsString( '<a href="https://example.test/firehouse/projects/" class="fh-btn fh-btn--secondary fh-btn--md">Explore projects</a>', $html );
		$this->assertStringContainsString( '<nav class="fh-not-found__topics" aria-labelledby="not-found-topics">', $html );
		$this->assertStringContainsString( '<h2 id="not-found-topics"', $html );
		foreach ( array( 'observe', 'forecast', 'warning', 'governance' ) as $key ) {
			$this->assertStringContainsString( '<a class="fh-not-found__topic-link" href="https://example.test/firehouse/topics/' . $key . '/"><span class="fh-topic-tag fh-topic-tag--sm"', $html );
		}
		$this->assertStringContainsString( '<span class="fh-not-found__topic-label">Strategic Adaptation and Institutional Governance</span>', $html );
		$this->assertSame( 1, substr_count( $html, '<h1' ) );
	}

	/**
	 * A missing topic area is named as one.
	 */
	public function test_a_missing_topic_area_says_so(): void {
		foreach ( array( 'firehouse/topics/fire', 'firehouse/topics' ) as $request ) {
			$html = self::render( $request );
			$this->assertStringContainsString( '<h1 class="fh-not-found__heading">Topic area not found</h1>', $html, $request );
			$this->assertStringContainsString( 'The Firehouse organizes its findings into four topic areas', $html, $request );
		}
	}

	/**
	 * The requested address is quoted as text, never markup, and cut to 64 characters.
	 */
	public function test_the_requested_address_is_escaped_and_cut_short(): void {
		$html = self::render( 'firehouse/<script>alert(1)</script>' );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '/firehouse/&lt;script&gt;alert(1)&lt;/script&gt;</code>', $html );

		// WordPress hands over the request slashed; the visitor typed no backslash.
		$this->assertStringContainsString( '<code class="fh-not-found__path">/firehouse/o&#039;brien</code>', self::render( "firehouse/o\\'brien" ) );
		// A path that isn't valid UTF-8 still quotes what it can, not nothing.
		$this->assertStringContainsString( '<code class="fh-not-found__path">/firehouse/ab</code>', self::render( "firehouse/a\xffb" ) );

		$long = 'firehouse/' . str_repeat( 'é', 80 );
		$html = self::render( $long );
		$this->assertStringContainsString( '<code class="fh-not-found__path">' . mb_substr( '/' . $long, 0, 64 ) . '…</code>', $html );
	}
}
