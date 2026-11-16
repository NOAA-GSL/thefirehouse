<?php
/**
 * Tests for the fields of a project and a topic.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Importer;
use Firehouse\Meta;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The fields come from the schemas, are stored as given, and only valid values are.
 */
final class MetaTest extends WordPressTestCase {

	/**
	 * WordPress's validator, which these tests don't run, passes everything.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'rest_validate_value_from_schema' )->justReturn( true );
	}

	/**
	 * A project's slug and status are the post's own; every other field of the Project
	 * type is meta, the title included (rule 1), under its name with the prefix.
	 */
	public function test_project_fields_are_the_schema_less_the_posts_own(): void {
		$schema = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/shared/project.schema.json' ), true );

		$this->assertSame(
			array_values( array_diff( array_keys( $schema['properties'] ), array( 'slug', 'published' ) ) ),
			array_keys( Meta::fields( 'fh_project' ) )
		);
		$this->assertArrayHasKey( 'title', Meta::fields( 'fh_project' ) );
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
	 * Each field is a single value, out of the REST API, kept in revisions and stored
	 * as given, and no one may change it through WordPress's custom-field routes.
	 */
	public function test_fields_are_single_revisioned_stored_as_given_and_closed(): void {
		foreach ( array( 'fh_project', 'fh_topic' ) as $post_type ) {
			foreach ( Meta::fields( $post_type ) as $name => $schema ) {
				$args = Meta::args( $schema );
				$this->assertSame( $schema['type'], $args['type'], $name );
				$this->assertTrue( $args['single'], $name );
				$this->assertFalse( $args['show_in_rest'], $name );
				$this->assertTrue( $args['revisions_enabled'], $name );
				$this->assertArrayNotHasKey( 'sanitize_callback', $args, $name );
				$this->assertSame( '__return_false', $args['auth_callback'], $name );
			}
		}
	}

	/**
	 * A restore puts back a project's editorial fields and none of the survey's, so it
	 * can't roll back a researcher's words or the IRB approval (rules 1 and 4); it puts
	 * back all of a topic's fields.
	 */
	public function test_a_restore_puts_back_only_a_projects_editorial_fields(): void {
		$restored = array_keys( array_filter( Meta::fields( 'fh_project' ), static fn( array $schema, string $name ): bool => Meta::restored( 'fh_project', $name ), ARRAY_FILTER_USE_BOTH ) );
		$this->assertSame( array( 'summary', 'publicationStatus', 'fullRecordUrl' ), $restored );
		foreach ( array( 'irbApproved', 'title', 'abstract', 'needs', 'org', 'geo', 'id' ) as $name ) {
			$this->assertFalse( Meta::restored( 'fh_project', $name ), $name );
		}
		foreach ( array_keys( Meta::fields( 'fh_topic' ) ) as $name ) {
			$this->assertTrue( Meta::restored( 'fh_topic', $name ), $name );
		}
	}

	/**
	 * The fields a restore puts back are the importer's editorial fields that are
	 * meta, so a new editorial field can't be kept by the import and missed by a
	 * restore.
	 */
	public function test_the_restored_fields_are_the_imports_editorial_ones(): void {
		$this->assertSame( array_values( array_diff( Importer::EDITORIAL, array( 'slug', 'published' ) ) ), Meta::PROJECT_RESTORED );
	}

	/**
	 * The revisioned keys: none of the fields while an autosave of a project or topic
	 * is made, so WordPress copies none from the request, or while a field is read,
	 * so a preview reads the post's own; a project's editorial ones only while a
	 * revision is restored; every field anywhere else, a revision's own copy included;
	 * and other post types' lists as they are.
	 *
	 * @dataProvider revision_key_cases
	 *
	 * @param string   $doing     The action or filter under way, or ''.
	 * @param string   $post_type The post type.
	 * @param string[] $expected  The keys left.
	 */
	public function test_the_revisioned_keys_are_narrowed_where_written_back( string $doing, string $post_type, array $expected ): void {
		Functions\when( 'doing_action' )->alias( static fn( string $action ): bool => $doing === $action );
		Functions\when( 'doing_filter' )->alias( static fn( string $filter ): bool => $doing === $filter );
		$this->assertSame( $expected, Meta::revision_keys( array( 'fh_summary', 'other_key', 'fh_org', 'fh_reviewedBy' ), $post_type ) );
	}

	/**
	 * The action under way, the post type, and the keys left.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string[]}>
	 */
	public static function revision_key_cases(): array {
		$all = array( 'fh_summary', 'other_key', 'fh_org', 'fh_reviewedBy' );
		return array(
			'project autosave' => array( 'wp_creating_autosave', 'fh_project', array( 'other_key' ) ),
			'topic autosave'   => array( 'wp_creating_autosave', 'fh_topic', array( 'other_key' ) ),
			'post autosave'    => array( 'wp_creating_autosave', 'post', $all ),
			'project restore'  => array( 'wp_restore_post_revision', 'fh_project', array( 'fh_summary', 'other_key' ) ),
			'topic restore'    => array( 'wp_restore_post_revision', 'fh_topic', $all ),
			'post restore'     => array( 'wp_restore_post_revision', 'post', $all ),
			'project preview'  => array( 'get_post_metadata', 'fh_project', array( 'other_key' ) ),
			'topic preview'    => array( 'get_post_metadata', 'fh_topic', array( 'other_key' ) ),
			'post preview'     => array( 'get_post_metadata', 'post', $all ),
			'project revision' => array( '', 'fh_project', $all ),
		);
	}

	/**
	 * A value is checked against its own field's schema by WordPress's validator.
	 */
	public function test_a_value_is_checked_by_wordpress_against_its_fields_schema(): void {
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
	 * WordPress's refusal is passed on.
	 */
	public function test_wordpress_refusal_is_passed_on(): void {
		$refusal = new \WP_Error( 'rest_invalid_type', 'Not a year.' );
		Functions\when( 'rest_validate_value_from_schema' )->justReturn( $refusal );

		$this->assertSame( $refusal, Meta::validate( 'fh_project', 'completionYear', 'soon' ) );
	}

	/**
	 * A value WordPress's validator would let through as convertible is refused when
	 * its PHP type isn't the schema's, because it is stored as given.
	 *
	 * @param string $name  The field.
	 * @param mixed  $value The value.
	 * @param string $where Where the message says it is wrong.
	 */
	#[DataProvider( 'wrong_types' )]
	public function test_a_value_of_the_wrong_type_is_refused( string $name, mixed $value, string $where ): void {
		$post_type = 'topNeeds' === $name ? 'fh_topic' : 'fh_project';

		$result = Meta::validate( $post_type, $name, $value );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'firehouse_wrong_type', $result->get_error_code() );
		$this->assertStringStartsWith( $where . ' has to be', $result->get_error_message() );
	}

	/**
	 * Values WordPress's validator converts: text for a number or a boolean, a
	 * comma-separated string for a list, and a slug where a post ID belongs.
	 *
	 * @return array<string, array{string, mixed, string}>
	 */
	public static function wrong_types(): array {
		return array(
			'a year as text'        => array( 'completionYear', '2025', 'completionYear' ),
			'false as text'         => array( 'irbApproved', 'false', 'irbApproved' ),
			'0 for false'           => array( 'irbApproved', 0, 'irbApproved' ),
			'methods as one string' => array( 'methods', 'Focus groups, Interviews', 'methods' ),
			'a list with a number'  => array( 'takeaways', array( 'One', 2 ), 'takeaways[1]' ),
			'an object as a list'   => array( 'geo', array( 'NWCC' ), 'geo' ),
			'a region list as text' => array( 'geo', array( 'regions' => 'NWCC' ), 'geo.regions' ),
			'a post ID as text'     => array( 'topNeeds', array( array( 'text' => 'A need', 'projects' => array( '12' ) ) ), 'topNeeds[0].projects[0]' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		);
	}

	/**
	 * Values of the right type pass, an empty object included.
	 */
	public function test_values_of_the_right_type_pass(): void {
		$this->assertTrue( Meta::validate( 'fh_project', 'completionYear', 2025 ) );
		$this->assertTrue( Meta::validate( 'fh_project', 'completionYear', 2025.0 ) );
		$this->assertTrue( Meta::validate( 'fh_project', 'irbApproved', false ) );
		$this->assertTrue( Meta::validate( 'fh_project', 'papers', array( array() ) ) );
		$this->assertTrue( Meta::validate( 'fh_project', 'geo', array( 'regions' => array( 'NWCC' ), 'note' => 'Front Range' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertTrue( Meta::validate( 'fh_topic', 'topNeeds', array( array( 'text' => 'A need', 'projects' => array( 12 ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
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
	 * Whatever code writes a field, a value validate() rejects isn't stored; a valid
	 * one is left to WordPress. Other keys, other post types and revisions aren't
	 * checked.
	 */
	public function test_every_write_of_a_field_is_checked(): void {
		$posts = array(
			50 => new \WP_Post( array( 'ID' => 50, 'post_type' => 'fh_project' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			60 => new \WP_Post( array( 'ID' => 60, 'post_type' => 'revision' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			70 => new \WP_Post( array( 'ID' => 70, 'post_type' => 'post' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		);
		Functions\when( 'get_post' )->alias( static fn( int $id ): ?\WP_Post => $posts[ $id ] ?? null );

		$this->assertFalse( Meta::refuse_invalid( null, 50, 'fh_completionYear', 'not a year' ) );
		$this->assertNull( Meta::refuse_invalid( null, 50, 'fh_completionYear', 2025 ) );
		$this->assertNull( Meta::refuse_invalid( null, 50, '_edit_lock', 'anything' ) );
		$this->assertNull( Meta::refuse_invalid( null, 50, 'fh_not_a_field', 'anything' ) );
		$this->assertNull( Meta::refuse_invalid( null, 60, 'fh_completionYear', 'not a year' ) );
		$this->assertNull( Meta::refuse_invalid( null, 70, 'fh_completionYear', 'not a year' ) );
		$this->assertTrue( Meta::refuse_invalid( true, 50, 'fh_completionYear', 'not a year' ), 'Another filter’s answer stands.' );
	}

	/**
	 * A number or true or false may arrive as WordPress stores it, as text, as it does
	 * when a revision is restored; any other text in such a field is still refused.
	 */
	public function test_stored_text_for_a_number_or_boolean_passes(): void {
		$project = new \WP_Post( array( 'ID' => 50, 'post_type' => 'fh_project' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		Functions\when( 'get_post' )->justReturn( $project );

		$this->assertNull( Meta::refuse_invalid( null, 50, 'fh_completionYear', '2025' ) );
		$this->assertNull( Meta::refuse_invalid( null, 50, 'fh_irbApproved', '1' ) );
		$this->assertNull( Meta::refuse_invalid( null, 50, 'fh_irbApproved', '' ) );
		$this->assertFalse( Meta::refuse_invalid( null, 50, 'fh_irbApproved', 'false' ) );
		$this->assertFalse( Meta::refuse_invalid( null, 50, 'fh_methods', 'Survey' ) );
		$this->assertSame( 2025, Meta::typed( '2025', 'number' ) );
		$this->assertSame( 'false', Meta::typed( 'false', 'boolean' ) );
		foreach ( array( '2025.0', ' 2025', '1e3', '007' ) as $text ) {
			$this->assertFalse( Meta::refuse_invalid( null, 50, 'fh_completionYear', $text ), $text . ' isn’t how WordPress stores a number.' );
		}
	}

	/**
	 * The synthesis's fields are topic fields, and the rest of a topic's fields are
	 * its own: the bundle check keeps them apart.
	 */
	public function test_the_synthesis_fields_are_topic_fields(): void {
		$this->assertSame( array(), array_diff( Meta::SYNTHESIS_FIELDS, array_keys( Meta::fields( 'fh_topic' ) ) ) );
		$this->assertContains( 'covers', array_diff( array_keys( Meta::fields( 'fh_topic' ) ), Meta::SYNTHESIS_FIELDS ) );
	}

	/**
	 * A field holds one value, so a second one isn't added; a first one is checked.
	 */
	public function test_a_second_value_is_not_added(): void {
		$project = new \WP_Post( array( 'ID' => 50, 'post_type' => 'fh_project' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		Functions\when( 'get_post' )->justReturn( $project );
		Functions\when( 'metadata_exists' )->justReturn( true );
		$this->assertFalse( Meta::refuse_add( null, 50, 'fh_completionYear', 1999 ) );

		Functions\when( 'metadata_exists' )->justReturn( false );
		$this->assertNull( Meta::refuse_add( null, 50, 'fh_completionYear', 1999 ) );
		$this->assertFalse( Meta::refuse_add( null, 50, 'fh_completionYear', 'not a year' ) );
		$this->assertNull( Meta::refuse_add( null, 50, '_edit_lock', 'anything' ) );
	}
}
