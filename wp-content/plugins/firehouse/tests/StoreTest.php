<?php
/**
 * Tests for reading and writing projects and topics.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Store;

/**
 * Posts read back in types.ts's shape, and writes keep text exactly as given.
 */
final class StoreTest extends WordPressTestCase {

	/**
	 * A project's meta, as WordPress stores it: numbers and true as text.
	 *
	 * @var array<string, mixed>
	 */
	private const STORED = array(
		'fh_id'             => 'R_1',
		'fh_title'          => 'Smoke & Fire: <5 km>',
		'fh_completionYear' => '2025',
		'fh_irbApproved'    => '1',
		'fh_methods'        => array( 'Survey' ),
	);

	/**
	 * Stubs WordPress's meta functions over STORED.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'metadata_exists' )->alias( static fn( string $type, int $id, string $key ): bool => array_key_exists( $key, self::STORED ) );
		Functions\when( 'get_post_meta' )->alias( static fn( int $id, string $key ): mixed => self::STORED[ $key ] ?? '' );
	}

	/**
	 * A project reads back with its schema's types, its slug and status from the
	 * post, its title from meta, and no key for a field that isn't stored.
	 */
	public function test_a_project_reads_back_in_its_shape(): void {
		$post = new \WP_Post(
			array(
				'ID'          => 50,
				'post_type'   => 'fh_project',
				'post_name'   => 'a-project',
				'post_status' => 'draft',
				'post_title'  => 'Smoke &amp; Fire: &lt;5 km&gt;',
			)
		);

		$project = Store::project( $post );

		$this->assertSame( 50, $project['post_id'] );
		$this->assertSame( 'draft', $project['status'] );
		$this->assertSame(
			array(
				'slug'           => 'a-project',
				'published'      => false,
				'id'             => 'R_1',
				'title'          => 'Smoke & Fire: <5 km>',
				'completionYear' => 2025,
				'methods'        => array( 'Survey' ),
				'irbApproved'    => true,
			),
			$project['fields']
		);
	}

	/**
	 * A stored false is an empty string, and reads back as false.
	 */
	public function test_false_reads_back_as_false(): void {
		Functions\when( 'metadata_exists' )->justReturn( true );
		Functions\when( 'get_post_meta' )->alias( static fn( int $id, string $key ): mixed => 'fh_irbApproved' === $key ? '' : null );

		$this->assertFalse( Store::meta( 50, 'fh_project' )['irbApproved'] );
	}

	/**
	 * A visitor sees only projects that are published, without a password, and with
	 * IRB approval stored as true, whatever else the caller asks for, and other plugins'
	 * SQL filters stay out.
	 */
	public function test_the_public_projects_are_published_open_and_approved(): void {
		$expected = array(
			'post_type'    => 'fh_project',
			'post_status'  => 'publish',
			'has_password' => false,
			'meta_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the arguments under test.
				array(
					'key'   => 'fh_irbApproved',
					'value' => '1',
					'type'  => 'BINARY',
				),
			),
		);

		$this->assertSame( $expected, Store::public_projects_query() );
		$this->assertSame(
			$expected + array( 'post__in' => array( 11 ) ),
			Store::public_projects_query(
				array(
					'post_status'      => 'any',
					'has_password'     => null,
					'suppress_filters' => false,
					'meta_query'       => array(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the arguments under test.
					'post__in'         => array( 11 ),
				)
			)
		);
	}

	/**
	 * The count is of the projects a visitor may see.
	 */
	public function test_the_public_count_counts_the_public_projects(): void {
		Functions\expect( 'get_posts' )->once()->andReturnUsing(
			function ( array $args ): array {
				$this->assertSame( Store::public_projects_query(), array_diff_key( $args, array_flip( array( 'fields', 'numberposts', 'orderby' ) ) ) );
				$this->assertSame( 'ids', $args['fields'] );
				$this->assertSame( -1, $args['numberposts'] );
				return array( 11, 12, 14 );
			}
		);

		$this->assertSame( 3, Store::public_project_count() );
	}

	/**
	 * A loaded project is public by the same test as the query: published, without a
	 * password, and with its approval read back as exactly true.
	 *
	 * @dataProvider public_or_not
	 *
	 * @param array<string, mixed> $post     The post's fields.
	 * @param mixed                $approval What its approval reads back as.
	 * @param bool                 $expected Whether a visitor may see it.
	 */
	public function test_a_project_is_public_by_the_same_test( array $post, mixed $approval, bool $expected ): void {
		Functions\when( 'get_post_meta' )->alias( static fn( int $id, string $key ): mixed => 'fh_irbApproved' === $key ? $approval : '' );

		$this->assertSame( $expected, Store::is_public( new \WP_Post( $post + array( 'post_type' => 'fh_project' ) ) ) );
	}

	/**
	 * Projects, and whether a visitor may see each.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: mixed, 2: bool}>
	 */
	public static function public_or_not(): array {
		return array(
			'published and approved' => array( array(), '1', true ),
			'not approved, or none'  => array( array(), '', false ),
			'"1 " stored'            => array( array(), '1 ', false ),
			'a draft'                => array( array( 'post_status' => 'draft' ), '1', false ),
			'a password'             => array( array( 'post_password' => 'secret' ), '1', false ),
			'not a project'          => array( array( 'post_type' => 'page' ), '1', false ),
		);
	}

	/**
	 * A topic's label is its title, stored escaped and read back as written.
	 */
	public function test_a_topic_label_reads_back_unescaped(): void {
		Functions\when( 'metadata_exists' )->justReturn( false );
		Functions\when( 'wp_specialchars_decode' )->alias( static fn( string $text ): string => htmlspecialchars_decode( $text, ENT_QUOTES ) );
		$post = new \WP_Post(
			array(
				'ID'         => 40,
				'post_type'  => 'fh_topic',
				'post_name'  => 'observe',
				'post_title' => 'Fire &amp; Smoke &lt;b&gt;',
			)
		);

		$topic = Store::topic( $post );

		$this->assertSame( 'observe', $topic['key'] );
		$this->assertSame( array( 'label' => 'Fire & Smoke <b>' ), $topic['fields'] );
	}

	/**
	 * If WordPress refuses an update, the fields removed for it are put back.
	 */
	public function test_a_refused_update_puts_removed_fields_back(): void {
		$restored = array();
		Functions\when( 'wp_slash' )->returnArg();
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'kses_remove_filters' )->justReturn();
		Functions\when( 'delete_post_meta' )->justReturn( true );
		Functions\when( 'wp_update_post' )->justReturn( new \WP_Error( 'db_update_error', 'Could not update.' ) );
		Functions\when( 'update_post_meta' )->alias(
			static function ( int $id, string $key, mixed $value ) use ( &$restored ): bool {
				$restored[ $key ] = $value;
				return true;
			}
		);

		$result = Store::save( 'fh_project', 50, array(), array( 'title' => 'New' ), array( 'methods' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array( 'fh_methods' => array( 'Survey' ) ), $restored );
	}

	/**
	 * A project post's title is the researcher's, escaped for WordPress's lists.
	 */
	public function test_the_post_title_is_an_escaped_label(): void {
		$this->assertSame( 'Smoke &amp; Fire: &lt;5 km&gt;', Store::label( 'Smoke & Fire: <5 km>' ) );
	}

	/**
	 * A new post is saved slashed, so WordPress's unslashing leaves the text as given,
	 * with its fields under their keys; kses is off while it saves, and the plugin is
	 * marked as writing.
	 */
	public function test_a_save_keeps_text_as_given(): void {
		$events = array();
		$slash  = static function ( mixed $value ) use ( &$slash ): mixed {
			return is_array( $value ) ? array_map( $slash, $value ) : ( is_string( $value ) ? addslashes( $value ) : $value );
		};
		Functions\when( 'wp_slash' )->alias( $slash );
		Functions\when( 'has_filter' )->justReturn( 10 );
		Functions\when( 'kses_remove_filters' )->alias(
			static function () use ( &$events ): void {
				$events[] = 'kses off';
			}
		);
		Functions\when( 'kses_init_filters' )->alias(
			static function () use ( &$events ): void {
				$events[] = 'kses on';
			}
		);
		Functions\when( 'wp_insert_post' )->alias(
			static function ( array $postarr, bool $wp_error ) use ( &$events ): int {
				$events[] = array( $postarr, $wp_error, Store::writing() );
				return 51;
			}
		);

		$id = Store::save( 'fh_project', 0, array( 'post_name' => 'a-project' ), array( 'abstract' => 'C:\temp and "quotes"' ), array() );

		$this->assertSame( 51, $id );
		$this->assertSame( 'kses off', $events[0] );
		$this->assertSame( 'C:\\\\temp and \\"quotes\\"', $events[1][0]['meta_input']['fh_abstract'] );
		$this->assertSame( 'fh_project', $events[1][0]['post_type'] );
		$this->assertTrue( $events[1][1] );
		$this->assertTrue( $events[1][2], 'The plugin is marked as writing during the save.' );
		$this->assertSame( 'kses on', $events[2] );
		$this->assertFalse( Store::writing() );
	}

	/**
	 * An existing post's removed fields are deleted before it is saved, so the
	 * revision WordPress makes holds the result.
	 */
	public function test_an_update_deletes_removed_fields_first(): void {
		$events = array();
		Functions\when( 'wp_slash' )->returnArg();
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'kses_remove_filters' )->justReturn();
		Functions\expect( 'kses_init_filters' )->never();
		Functions\when( 'delete_post_meta' )->alias(
			static function ( int $id, string $key ) use ( &$events ): bool {
				$events[] = 'delete ' . $key;
				return true;
			}
		);
		Functions\when( 'wp_update_post' )->alias(
			static function ( array $postarr ) use ( &$events ): int {
				$events[] = 'update ' . $postarr['ID'];
				return $postarr['ID'];
			}
		);

		Store::save( 'fh_project', 50, array(), array( 'title' => 'New' ), array( 'org' ) );

		$this->assertSame( array( 'delete fh_org', 'update 50' ), $events );
	}
}
