<?php
/**
 * Tests for the `year` query variable on Firehouse pages.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Firehouse\Query;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `year` is dropped from Firehouse requests and only from them (rule 7).
 */
final class QueryTest extends WordPressTestCase {

	/**
	 * A Firehouse request loses `year`, whatever its value, and keeps the rest.
	 *
	 * @param array<string, mixed> $vars The request's query variables, without `year`.
	 */
	#[DataProvider( 'firehouse_requests' )]
	public function test_a_firehouse_request_loses_year( array $vars ): void {
		foreach ( array( '2025', array( 'x' ) ) as $year ) {
			$this->assertSame( $vars, Query::request( $vars + array( 'year' => $year ) ) );
		}
	}

	/**
	 * The project archive, a project, a topic, the landing page and a page under it.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function firehouse_requests(): array {
		return array(
			'the project archive'                    => array( array( 'post_type' => 'fh_project' ) ),
			'a project'                              => array(
				array(
					'fh_project' => 'a-project',
					'post_type'  => 'fh_project',
					'name'       => 'a-project',
				),
			),
			'a project, by its query variable alone' => array( array( 'fh_project' => 'a-project' ) ),
			'a topic'                                => array( array( 'fh_topic' => 'observe' ) ),
			'the landing page'                       => array( array( 'pagename' => 'firehouse' ) ),
			'the About page'                         => array( array( 'pagename' => 'firehouse/about' ) ),
		);
	}

	/**
	 * Anywhere else, `year` means what WordPress means by it.
	 *
	 * @param array<string, mixed> $vars The request's query variables.
	 */
	#[DataProvider( 'other_requests' )]
	public function test_any_other_request_keeps_year( array $vars ): void {
		$this->assertSame( $vars, Query::request( $vars ) );
	}

	/**
	 * A date archive, a page whose name starts like the root's, and another post type.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function other_requests(): array {
		return array(
			'a date archive'             => array( array( 'year' => '2025' ) ),
			'a page named like the root' => array(
				array(
					'pagename' => 'firehouses',
					'year'     => '2025',
				),
			),
			'a page under another page'  => array(
				array(
					'pagename' => 'research/firehouse',
					'year'     => '2025',
				),
			),
			'the site’s posts'           => array(
				array(
					'post_type' => 'post',
					'year'      => '2025',
				),
			),
		);
	}
}
