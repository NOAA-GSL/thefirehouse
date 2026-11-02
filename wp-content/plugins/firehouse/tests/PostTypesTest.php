<?php
/**
 * Tests for the post types and their fields.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Meta;
use Firehouse\Post_Types;

/**
 * The project and topic post types: their addresses, capabilities and fields.
 */
final class PostTypesTest extends WordPressTestCase {

	/**
	 * Both post types are registered, then every field of each as meta.
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
		$this->assertSame( array_map( array( Meta::class, 'key' ), array_keys( Meta::fields( 'fh_project' ) ) ), array_keys( $meta['fh_project'] ) );
		$this->assertSame( array_map( array( Meta::class, 'key' ), array_keys( Meta::fields( 'fh_topic' ) ) ), array_keys( $meta['fh_topic'] ) );
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
	 */
	public function test_neither_is_in_the_rest_api_and_both_keep_revisions(): void {
		foreach ( array( Post_Types::project_args(), Post_Types::topic_args() ) as $args ) {
			$this->assertFalse( $args['show_in_rest'] );
			$this->assertContains( 'revisions', $args['supports'] );
			$this->assertNotContains( 'editor', $args['supports'] );
			$this->assertNotContains( 'custom-fields', $args['supports'], 'The Custom Fields box would let anyone edit survey text raw.' );
		}
	}

	/**
	 * Each has its own capabilities, so editing them doesn't take the site's post
	 * capabilities, and no one can add a topic.
	 */
	public function test_each_has_its_own_capabilities_and_no_one_adds_a_topic(): void {
		$project = Post_Types::project_args();
		$topic   = Post_Types::topic_args();

		$this->assertSame( array( 'fh_project', 'fh_projects' ), $project['capability_type'] );
		$this->assertSame( array( 'fh_topic', 'fh_topics' ), $topic['capability_type'] );
		$this->assertTrue( $project['map_meta_cap'] );
		$this->assertTrue( $topic['map_meta_cap'] );
		$this->assertSame( 'do_not_allow', $topic['capabilities']['create_posts'] );
	}

	/**
	 * A project's slug, title and status are the post's own; every other field of the
	 * Project type is meta, under its name with the prefix.
	 */
	public function test_project_fields_are_the_schema_less_the_posts_own(): void {
		$schema = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/shared/project.schema.json' ), true );

		$this->assertSame(
			array_values( array_diff( array_keys( $schema['properties'] ), array( 'slug', 'title', 'published' ) ) ),
			array_keys( Meta::fields( 'fh_project' ) )
		);
		$this->assertSame( 'fh_completionYear', Meta::key( 'completionYear' ) );
	}

	/**
	 * A topic's needs point to projects by post ID, not slug (rule 5).
	 */
	public function test_topic_needs_point_to_projects_by_post_id(): void {
		$need = Meta::fields( 'fh_topic' )['topNeeds']['items'];

		$this->assertSame( array( 'type' => 'integer' ), $need['properties']['projects']['items'] );
		$this->assertSame( 'string', $need['properties']['text']['type'] );
	}

	/**
	 * Each field is a single value, out of the REST API, kept in revisions, and stored
	 * as given: no sanitizing callback can rewrite researchers' text.
	 */
	public function test_fields_are_single_revisioned_and_stored_as_given(): void {
		foreach ( array( 'fh_project', 'fh_topic' ) as $post_type ) {
			foreach ( Meta::fields( $post_type ) as $name => $schema ) {
				$args = Meta::args( $schema );
				$this->assertSame( $schema['type'], $args['type'], $name );
				$this->assertTrue( $args['single'], $name );
				$this->assertFalse( $args['show_in_rest'], $name );
				$this->assertTrue( $args['revisions_enabled'], $name );
				$this->assertArrayNotHasKey( 'sanitize_callback', $args, $name );
			}
		}
	}

	/**
	 * A value is checked against its own field's schema, by WordPress's validator.
	 */
	public function test_a_value_is_checked_against_its_fields_schema(): void {
		$seen = null;
		Functions\when( 'rest_validate_value_from_schema' )->alias(
			static function ( mixed $value, array $schema, string $param ) use ( &$seen ): bool {
				$seen = array( $value, $schema, $param );
				return true;
			}
		);

		$this->assertTrue( Meta::validate( 'fh_project', 'completionYear', 2025 ) );
		$this->assertSame( array( 2025, Meta::fields( 'fh_project' )['completionYear'], 'completionYear' ), $seen );
	}

	/**
	 * A field that doesn't exist is an error, not a pass.
	 */
	public function test_an_unknown_field_is_an_error(): void {
		$result = Meta::validate( 'fh_project', 'slug', 'a-slug' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'firehouse_unknown_field', $result->get_error_code() );
	}

	/**
	 * Changing a field takes the right to edit its post.
	 */
	public function test_changing_a_field_takes_editing_its_post(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'edit_post', 12 )->andReturn( false );

		$this->assertFalse( Meta::can_edit( true, 'fh_summary', 12 ) );
	}
}
