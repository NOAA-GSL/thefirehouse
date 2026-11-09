<?php
/**
 * Tests for keeping researchers' words as they wrote them.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Verbatim;

/**
 * On a Firehouse page WordPress's three text rewrites are off; anywhere else they
 * are left alone (rule 1).
 */
final class VerbatimTest extends WordPressTestCase {

	/**
	 * Where WordPress hooks `capital_P_dangit`.
	 */
	private const DANGIT = array( 'the_content', 'the_title', 'wp_title', 'document_title' );

	/**
	 * Stands in for WordPress's own filters and the query.
	 *
	 * @param bool $firehouse Whether the request is a Firehouse page.
	 */
	private static function setup_request( bool $firehouse ): void {
		foreach ( self::DANGIT as $hook ) {
			add_filter( $hook, 'capital_P_dangit', 11 );
		}
		Functions\when( 'is_embed' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( $firehouse );
		Functions\when( 'is_post_type_archive' )->justReturn( false );
		Functions\when( 'is_page' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
	}

	/**
	 * A Firehouse page: texturizing and smilies off, read afresh, and the
	 * capitalization filter gone from every hook it was on.
	 */
	public function test_a_firehouse_page_keeps_text_verbatim(): void {
		self::setup_request( true );
		// The re-read only helps if the switch is already in place when it happens.
		Functions\expect( 'wptexturize' )->once()->with( ' ', true )->andReturnUsing(
			function (): string {
				$this->assertNotFalse( has_filter( 'run_wptexturize', '__return_false' ), 'The switch is added before wptexturize() reads it.' );
				return ' ';
			}
		);

		Verbatim::keep();

		$this->assertNotFalse( has_filter( 'run_wptexturize', '__return_false' ) );
		$this->assertNotFalse( has_filter( 'pre_option_use_smilies', '__return_zero' ) );
		foreach ( self::DANGIT as $hook ) {
			$this->assertFalse( has_filter( $hook, 'capital_P_dangit' ), $hook );
		}
	}

	/**
	 * Any other page is left as WordPress would have it.
	 */
	public function test_any_other_page_is_left_alone(): void {
		self::setup_request( false );
		Functions\expect( 'wptexturize' )->never();

		Verbatim::keep();

		$this->assertFalse( has_filter( 'run_wptexturize', '__return_false' ) );
		$this->assertFalse( has_filter( 'pre_option_use_smilies', '__return_zero' ) );
		foreach ( self::DANGIT as $hook ) {
			$this->assertNotFalse( has_filter( $hook, 'capital_P_dangit' ), $hook );
		}
	}
}
