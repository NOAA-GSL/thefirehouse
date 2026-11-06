<?php
/**
 * Tests for the Firehouse frame.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Frame;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Firehouse requests get the frame, its stylesheets in order and the body class;
 * every other request is left to the theme.
 */
final class FrameTest extends WordPressTestCase {

	/**
	 * Clears the main request after each test.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wp'] );
		parent::tearDown();
	}

	/**
	 * Sets the main request's query variables.
	 *
	 * @param array<string, mixed> $vars The query variables.
	 */
	private static function request( array $vars ): void {
		$wp             = new \WP();
		$wp->query_vars = $vars;
		$GLOBALS['wp']  = $wp; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the main request, stood in for.
	}

	/**
	 * A Firehouse request of each kind, a missing address under one included.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function firehouse_requests(): array {
		return array(
			'the project archive'           => array( array( 'post_type' => 'fh_project' ) ),
			'a project'                     => array( array( 'fh_project' => 'a-project' ) ),
			'a topic'                       => array( array( 'fh_topic' => 'observe' ) ),
			'the landing page'              => array( array( 'pagename' => 'firehouse' ) ),
			'a missing page under the tree' => array( array( 'pagename' => 'firehouse/nothing-here' ) ),
		);
	}

	/**
	 * Requests the theme keeps.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function other_requests(): array {
		return array(
			'the front page'             => array( array() ),
			'a page of the theme'        => array( array( 'pagename' => 'about/history' ) ),
			'a page that only starts so' => array( array( 'pagename' => 'firehouses' ) ),
			'a post'                     => array( array( 'name' => 'news-item' ) ),
			'the posts of another type'  => array( array( 'post_type' => 'post' ) ),
		);
	}

	/**
	 * A Firehouse request gets the plugin's frame template and the body class.
	 *
	 * @param array<string, mixed> $vars The query variables.
	 */
	#[DataProvider( 'firehouse_requests' )]
	public function test_a_firehouse_request_gets_the_frame( array $vars ): void {
		self::request( $vars );

		$this->assertSame( dirname( __DIR__ ) . '/templates/frame.php', Frame::template( '/theme/page.php' ) );
		$this->assertSame( array( 'page', Frame::BODY_CLASS ), Frame::body_class( array( 'page' ) ) );
	}

	/**
	 * Any other request keeps the theme's template and classes, and loads nothing.
	 *
	 * @param array<string, mixed> $vars The query variables.
	 */
	#[DataProvider( 'other_requests' )]
	public function test_any_other_request_is_left_to_the_theme( array $vars ): void {
		self::request( $vars );
		Functions\expect( 'wp_enqueue_style' )->never();

		$this->assertSame( '/theme/page.php', Frame::template( '/theme/page.php' ) );
		$this->assertSame( array( 'page' ), Frame::body_class( array( 'page' ) ) );
		Frame::enqueue();
	}

	/**
	 * Without a parsed request, nothing is a Firehouse request.
	 */
	public function test_no_request_is_no_firehouse_request(): void {
		$this->assertFalse( Frame::is_firehouse_request() );
	}

	/**
	 * The fonts, the site's stylesheet and the frame load in that order, each after the
	 * one before, at the plugin's version.
	 */
	public function test_the_stylesheets_load_in_order(): void {
		self::request( array( 'fh_topic' => 'observe' ) );
		Functions\when( 'plugins_url' )->alias( static fn( string $path ): string => 'https://example.test/wp-content/plugins/firehouse/' . $path );
		$loaded = array();
		Functions\when( 'wp_enqueue_style' )->alias(
			static function ( string $handle, string $src, array $deps, string $version ) use ( &$loaded ): void {
				$loaded[] = array( $handle, basename( $src ), $deps, $version );
			}
		);

		Frame::enqueue();

		$this->assertSame(
			array(
				array( Frame::FONTS, 'fonts.css', array(), \Firehouse\VERSION ),
				array( Frame::STYLE, 'firehouse.css', array( Frame::FONTS ), \Firehouse\VERSION ),
				array( Frame::FRAME, 'frame.css', array( Frame::STYLE ), \Firehouse\VERSION ),
			),
			$loaded
		);
	}

	/**
	 * The placeholder says what it is, escaped, and a missing address says so.
	 */
	public function test_the_placeholder(): void {
		Functions\when( 'esc_html' )->alias( static fn( string $text ): string => htmlspecialchars( $text, ENT_QUOTES ) );
		Functions\when( 'is_404' )->justReturn( true );
		ob_start();
		Frame::placeholder();
		$this->assertStringContainsString( '<h1>Page not found</h1>', (string) ob_get_clean() );

		Functions\when( 'is_404' )->justReturn( false );
		ob_start();
		Frame::placeholder();
		$this->assertStringContainsString( 'This page is being built.', (string) ob_get_clean() );
	}
}
