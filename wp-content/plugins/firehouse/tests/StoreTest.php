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
