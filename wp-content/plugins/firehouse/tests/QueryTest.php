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
	 * A project or topic variable that names no post is dropped, on any address, and
	 * doesn't make the request a Firehouse one, so `year` stays (rule 13).
	 *
	 * @param array<string, mixed> $vars     The request's query variables.
	 * @param array<string, mixed> $expected What WordPress's query gets.
	 */
	#[DataProvider( 'variables_that_name_no_post' )]
	public function test_a_variable_that_names_no_post_is_dropped( array $vars, array $expected ): void {
		$this->assertSame( $expected, Query::request( $vars ) );
	}

	/**
	 * Arrays, empty strings and `0`, which WordPress reads as empty.
	 *
	 * @return array<string, array{array<string, mixed>, array<string, mixed>}>
	 */
	public static function variables_that_name_no_post(): array {
		return array(
			'an array on the project archive'  => array(
				array(
					'post_type'  => 'fh_project',
					'fh_project' => array( 'x' ),
				),
				array( 'post_type' => 'fh_project' ),
			),
			'an array on a page of the theme'  => array(
				array(
					'pagename'   => 'about',
					'fh_project' => array( 'x' ),
				),
				array( 'pagename' => 'about' ),
			),
			'an empty topic on a page'         => array(
				array(
					'pagename' => 'about',
					'fh_topic' => '',
				),
				array( 'pagename' => 'about' ),
			),
			'an empty topic on a date archive' => array(
				array(
					'year'     => '2025',
					'fh_topic' => '',
				),
				array( 'year' => '2025' ),
			),
			'a 0 project on the posts'         => array(
				array(
					'fh_project' => '0',
					'year'       => '2025',
				),
				array( 'year' => '2025' ),
			),
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
