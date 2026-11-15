<?php
/**
 * Tests for the post types.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Meta;
use Firehouse\Post_Types;

/**
 * The project and topic post types: their addresses, screens and capabilities.
 */
final class PostTypesTest extends WordPressTestCase {

	/**
	 * Both post types are registered, then every field of each as meta, with the
	 * arguments Meta gives it.
	 */
	public function test_registers_both_post_types_then_their_fields(): void {
		$order = array();
		Functions\when( 'register_post_type' )->alias(
			static function ( string $type ) use ( &$order ): void {
				$order[] = $type;
			}
		);
		$meta = array();
		Functions\when( 'register_post_meta' )->alias(
			static function ( string $type, string $key, array $args ) use ( &$order, &$meta ): bool {
				$order[]               = 'meta';
				$meta[ $type ][ $key ] = $args;
				return true;
			}
		);

		Post_Types::register();

		$this->assertSame( array( 'fh_project', 'fh_topic' ), array_slice( $order, 0, 2 ), 'The post types come first: a field’s revisions need its post type’s.' );
		foreach ( array( 'fh_project', 'fh_topic' ) as $type ) {
			$expected = array();
			foreach ( Meta::fields( $type ) as $name => $schema ) {
				$expected[ Meta::key( $name ) ] = Meta::args( $schema );
			}
			$this->assertSame( $expected, $meta[ $type ] );
		}
	}

	/**
	 * The addresses are the React site's, under /firehouse/, with no topic archive.
	 */
	public function test_addresses_are_the_react_sites(): void {
		$project = Post_Types::project_args();
		$topic   = Post_Types::topic_args();

		$this->assertSame( 'firehouse/projects', $project['has_archive'] );
		$this->assertSame( 'firehouse/projects', $project['rewrite']['slug'] );
		$this->assertFalse( $project['rewrite']['with_front'] );
		$this->assertFalse( $topic['has_archive'] );
		$this->assertSame( 'firehouse/topics', $topic['rewrite']['slug'] );
		$this->assertFalse( $topic['rewrite']['with_front'] );
	}

	/**
	 * Neither is in the REST API, both keep revisions, and neither has a body to edit.
	 * A project has no title box either: its title is a field, kept verbatim (rule 1).
	 */
	public function test_neither_is_in_the_rest_api_and_both_keep_revisions(): void {
		foreach ( array( Post_Types::project_args(), Post_Types::topic_args() ) as $args ) {
			$this->assertFalse( $args['show_in_rest'] );
			$this->assertContains( 'revisions', $args['supports'] );
			$this->assertNotContains( 'editor', $args['supports'] );
			$this->assertNotContains( 'custom-fields', $args['supports'], 'The Custom Fields box would let anyone edit survey text raw.' );
		}
		$this->assertNotContains( 'title', Post_Types::project_args()['supports'] );
	}

	/**
	 * Both are public, so the sitemap lists them, and kept out of the site's search,
	 * which also keeps WordPress's guess at a missing address elsewhere from landing on
	 * one (Frame::guess_404 makes the Firehouse's own).
	 */
	public function test_both_are_public_but_out_of_site_search(): void {
		foreach ( array( Post_Types::project_args(), Post_Types::topic_args() ) as $args ) {
			$this->assertTrue( $args['public'] );
			$this->assertTrue( $args['exclude_from_search'] );
		}
	}

	/**
	 * Each has its own capabilities, so editing them doesn't take the site's post
	 * capabilities, and no one adds either in the admin: projects come through the
	 * Import screen and topics are fixed.
	 */
	public function test_each_has_its_own_capabilities_and_no_one_adds_one(): void {
		$project = Post_Types::project_args();
		$topic   = Post_Types::topic_args();

		$this->assertSame( array( 'fh_project', 'fh_projects' ), $project['capability_type'] );
		$this->assertSame( array( 'fh_topic', 'fh_topics' ), $topic['capability_type'] );
		$this->assertTrue( $project['map_meta_cap'] );
		$this->assertTrue( $topic['map_meta_cap'] );
		$this->assertSame( 'do_not_allow', $project['capabilities']['create_posts'] );
		$this->assertSame( 'do_not_allow', $topic['capabilities']['create_posts'] );
	}
}
