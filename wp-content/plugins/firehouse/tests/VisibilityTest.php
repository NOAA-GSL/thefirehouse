<?php
/**
 * Tests for which projects a visitor may see, wherever WordPress reaches them.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Visibility;

/**
 * A project a visitor may not see is dropped from what a query found, gets no
 * redirect built from its ID, no oEmbed, no menu item, and no links from its
 * neighbours' heads. Someone who may edit it still sees it at its own address.
 */
final class VisibilityTest extends WordPressTestCase {

	/**
	 * Project 11 is public; 12 lacks IRB approval; 13 has a password; 14 is a draft.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_post_meta' )->alias( static fn( int $id ): string => 12 === $id ? '' : '1' );
		Functions\when( 'get_post' )->alias( static fn( int $id ): ?\WP_Post => in_array( $id, array( 11, 12, 13, 14, 20 ), true ) ? self::post( $id ) : null );
	}

	/**
	 * A post: 11 to 14 are projects, 20 is a page.
	 *
	 * @param int $id Its ID.
	 */
	private static function post( int $id ): \WP_Post {
		return new \WP_Post(
			array(
				'ID'            => $id,
				'post_type'     => 20 === $id ? 'page' : 'fh_project',
				'post_status'   => 14 === $id ? 'draft' : 'publish',
				'post_password' => 13 === $id ? 'secret' : '',
			)
		);
	}

	/**
	 * The IDs of some posts.
	 *
	 * @param mixed $posts The posts.
	 * @return list<int>
	 */
	private static function ids( mixed $posts ): array {
		return array_map( static fn( \WP_Post $post ): int => $post->ID, $posts );
	}

	/**
	 * A query keeps its public projects and its other posts, in order, as a list, and
	 * loses the projects a visitor may not see. Only the published projects without a
	 * password need their fields, and those load in one go.
	 */
	public function test_a_query_loses_the_projects_a_visitor_may_not_see(): void {
		Functions\expect( 'update_postmeta_cache' )->once()->with( array( 12, 11 ) );

		$posts = Visibility::posts( array_map( array( self::class, 'post' ), array( 12, 20, 13, 11, 14 ) ), new \WP_Query() );

		$this->assertSame( array( 20, 11 ), self::ids( $posts ) );
		$this->assertTrue( array_is_list( $posts ) );
	}

	/**
	 * When every project found fails before its approval is read, nothing is loaded.
	 */
	public function test_nothing_loads_when_nothing_needs_its_approval_read(): void {
		Functions\expect( 'update_postmeta_cache' )->never();

		$this->assertSame( array(), Visibility::posts( array( self::post( 13 ), self::post( 14 ) ) ) );
	}

	/**
	 * Someone who may edit a hidden project still sees it on its own page, as they'd
	 * see a draft in preview, and nowhere else: not in a list, a feed or an embed.
	 */
	public function test_an_editor_sees_it_only_on_its_own_page(): void {
		Functions\when( 'update_postmeta_cache' )->justReturn( array() );
		Functions\when( 'current_user_can' )->alias( static fn( string $cap, int $id ): bool => 'edit_post' === $cap && 12 === $id );
		$posts = array( self::post( 12 ) );

		$this->assertSame( array( 12 ), self::ids( Visibility::posts( $posts, new \WP_Query( true, true ) ) ) );
		foreach (
			array(
				'a list'             => new \WP_Query( true, false ),
				'another query'      => new \WP_Query( false, true ),
				'an embed'           => new \WP_Query( true, true, true ),
				'a feed'             => new \WP_Query( true, true, false, true ),
				'no query passed in' => null,
			) as $where => $query
		) {
			$this->assertSame( array(), Visibility::posts( $posts, $query ), $where );
		}
	}

	/**
	 * A query with no project in it is passed through untouched, with nothing loaded.
	 */
	public function test_a_query_without_projects_is_untouched(): void {
		Functions\expect( 'update_postmeta_cache' )->never();
		$posts = array( self::post( 20 ) );

		$this->assertSame( $posts, Visibility::posts( $posts ) );
		$this->assertSame( array(), Visibility::posts( array() ) );
		$this->assertNull( Visibility::posts( null ) );
	}

	/**
	 * The admin's own screens are left alone; a request to admin-ajax isn't.
	 */
	public function test_the_admin_is_left_alone_but_not_admin_ajax(): void {
		Functions\when( 'update_postmeta_cache' )->justReturn( array() );
		$posts = array( self::post( 12 ) );
		Functions\when( 'is_admin' )->justReturn( true );
		$this->assertSame( $posts, Visibility::posts( $posts ) );

		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		$this->assertSame( array(), Visibility::posts( $posts ) );
	}

	/**
	 * No redirect is built from an ID that names a hidden project, on a missing
	 * address or on a feed; every other redirect stands.
	 */
	public function test_no_redirect_built_from_a_hidden_projects_id(): void {
		$var = array();
		Functions\when( 'get_query_var' )->alias(
			static function ( string $name ) use ( &$var ): mixed {
				return $var[ $name ] ?? '';
			}
		);
		$to = 'https://example.test/firehouse/projects/unapproved/feed/';

		$hidden = array(
			array( 'p' => 12 ),
			array( 'page_id' => '13' ),
			array( 'attachment_id' => 14 ),
			array(
				'p'             => 11,
				'attachment_id' => 12,
			),
		);
		foreach ( $hidden as $case ) {
			$var = $case;
			$this->assertFalse( Visibility::redirect( $to ), (string) json_encode( $case ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- a test's failure message.
		}
		foreach ( array( array( 'p' => 11 ), array( 'attachment_id' => 20 ), array( 'p' => 99 ), array() ) as $case ) {
			$var = $case;
			$this->assertSame( $to, Visibility::redirect( $to ), (string) json_encode( $case ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- a test's failure message.
		}
		$this->assertFalse( Visibility::redirect( false ) );
	}

	/**
	 * OEmbed and a changed slug's redirect find no post for a hidden project, whoever
	 * asks: an embed's markup is cached for every visitor.
	 */
	public function test_no_post_for_oembed_or_an_old_slug(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertSame( 0, Visibility::post_id( 12 ) );
		$this->assertSame( 0, Visibility::post_id( '13' ) );
		$this->assertSame( 11, Visibility::post_id( 11 ) );
		$this->assertSame( 20, Visibility::post_id( 20 ) );
		$this->assertSame( 0, Visibility::post_id( 0 ) );
		$this->assertSame( 99, Visibility::post_id( 99 ) );
	}

	/**
	 * A menu item linking to a hidden project is marked invalid; any other is left
	 * alone.
	 */
	public function test_a_menu_item_to_a_hidden_project_is_invalid(): void {
		$item = static fn( string $type, string $kind, int $id ): object => (object) array(
			'type'      => $type,
			'object'    => $kind,
			'object_id' => (string) $id,
		);

		$this->assertTrue( Visibility::menu_item( $item( 'post_type', 'fh_project', 12 ) )->_invalid ?? false );
		$this->assertFalse( isset( Visibility::menu_item( $item( 'post_type', 'fh_project', 11 ) )->_invalid ) );
		$this->assertFalse( isset( Visibility::menu_item( $item( 'post_type', 'page', 20 ) )->_invalid ) );
		$this->assertFalse( isset( Visibility::menu_item( $item( 'custom', 'custom', 12 ) )->_invalid ) );
		$this->assertNull( Visibility::menu_item( null ) );
	}

	/**
	 * A project's head has no previous and next links; another page's keeps them.
	 */
	public function test_a_projects_head_has_no_neighbours(): void {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\expect( 'remove_action' )->never();
		Visibility::head_links();

		Functions\when( 'is_singular' )->alias( static fn( string $type ): bool => 'fh_project' === $type );
		Functions\expect( 'remove_action' )->once()->with( 'wp_head', 'adjacent_posts_rel_link_wp_head' );
		Visibility::head_links();
	}
}
