<?php
/**
 * Tests for the FireHouse → Synthesis screen.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Synthesis_Screen;

/**
 * The synthesis screen checks a pasted synthesis and, once nothing is wrong, marks it
 * reviewed: the four topics get its needs, the reviewer, the date, the count and the
 * tool. Its download is the NotebookLM source of the projects the site shows.
 */
final class SynthesisScreenTest extends WordPressTestCase {

	/**
	 * Posts by ID: two projects, 11 shown and 12 a draft, and the four topics.
	 *
	 * @var array<int, \WP_Post>
	 */
	private array $posts = array();

	/**
	 * Meta by post ID and key.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $meta = array();

	/**
	 * The projects a visitor may see, by ID.
	 *
	 * @var list<int>
	 */
	private array $public = array( 11 );

	/**
	 * Transients by name.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * What wp_update_post() was given.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $updated = array();

	/**
	 * A WordPress with two projects and the four topics.
	 */
	protected function setUp(): void {
		parent::setUp();
		$post        = static fn( int $id, string $type, string $name, string $status = 'publish' ): \WP_Post => new \WP_Post(
			array(
				'ID'          => $id,
				'post_type'   => $type,
				'post_name'   => $name,
				'post_status' => $status,
				'post_title'  => $name,
			)
		);
		$this->posts = array(
			11 => $post( 11, 'fh_project', 'smoke-study' ),
			12 => $post( 12, 'fh_project', 'draft-study', 'draft' ),
			40 => $post( 40, 'fh_topic', 'observe' ),
			41 => $post( 41, 'fh_topic', 'forecast' ),
			42 => $post( 42, 'fh_topic', 'warning' ),
			43 => $post( 43, 'fh_topic', 'governance' ),
		);
		$this->meta  = array(
			11 => array(
				'fh_id'             => 'R_smoke',
				'fh_title'          => 'Smoke study',
				'fh_completionYear' => 2025,
				'fh_authors'        => array( array( 'name' => 'Jo Smith' ) ),
				'fh_irbApproved'    => true,
			),
			12 => array(
				'fh_id'             => 'R_draft',
				'fh_title'          => 'Draft study',
				'fh_completionYear' => 2026,
				'fh_authors'        => array(),
			),
			40 => array( 'fh_order' => 1 ),
			41 => array( 'fh_order' => 2 ),
			42 => array( 'fh_order' => 3 ),
			43 => array( 'fh_order' => 4 ),
		);
		Functions\when( 'get_posts' )->alias(
			function ( array $args ): array {
				if ( isset( $args['meta_query'] ) ) {
					$ids = $this->public;
					return 'ids' === ( $args['fields'] ?? '' ) ? $ids : array_map( fn( int $id ): \WP_Post => $this->posts[ $id ], $ids );
				}
				return array_values( array_filter( $this->posts, static fn( \WP_Post $post ): bool => $post->post_type === $args['post_type'] ) );
			}
		);
		Functions\when( 'metadata_exists' )->alias( fn( string $type, int $id, string $key ): bool => array_key_exists( $key, $this->meta[ $id ] ?? array() ) );
		Functions\when( 'get_post_meta' )->alias( fn( int $id, string $key ): mixed => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'get_transient' )->alias( fn( string $name ): mixed => $this->transients[ $name ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $name, mixed $value ): bool {
				$this->transients[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $name ): bool {
				$was = isset( $this->transients[ $name ] );
				unset( $this->transients[ $name ] );
				return $was;
			}
		);
		Functions\when( 'wp_update_post' )->alias(
			function ( array $postarr ): int {
				$this->updated[] = $postarr;
				return $postarr['ID'];
			}
		);
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'get_current_user_id' )->justReturn( 6 );
		Functions\when( 'wp_get_current_user' )->justReturn( (object) array( 'display_name' => 'Pat Reviewer' ) );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_slash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $text ): string => trim( $text ) );
		Functions\when( 'wp_generate_password' )->justReturn( 'the-token' );
		Functions\when( 'wp_date' )->justReturn( '2026-11-16' );
		Functions\when( 'get_permalink' )->alias( fn( int $id ): string => '/firehouse/topics/' . $this->posts[ $id ]->post_name . '/' );
		Functions\when( 'rest_validate_value_from_schema' )->justReturn( true );
		Functions\when( 'kses_remove_filters' )->justReturn( null );
		Functions\when( 'kses_init_filters' )->justReturn( null );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'get_admin_page_title' )->justReturn( 'FireHouse synthesis' );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'wp_nonce_url' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( string $path ): string => '/wp-admin/' . $path );
		Functions\when( 'submit_button' )->alias(
			static function ( string $text ): void {
				echo '<button>' . $text . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A test's stand-in.
			}
		);
		Functions\stubEscapeFunctions();
		Functions\when( 'esc_textarea' )->alias( static fn( string $text ): string => htmlspecialchars( $text, ENT_QUOTES ) );
	}

	/**
	 * Clears the form and the screen's state.
	 */
	protected function tearDown(): void {
		$_POST = array();
		( new \ReflectionProperty( Synthesis_Screen::class, 'state' ) )->setValue( null, null );
		parent::tearDown();
	}

	/**
	 * A synthesis naming the shown project in one need.
	 *
	 * @param string $project How the need names its project.
	 */
	private static function synthesis( string $project = 'smoke-study' ): string {
		$areas = array();
		foreach ( array( 'observe', 'forecast', 'warning', 'governance' ) as $key ) {
			$areas[] = array(
				'topic'    => $key,
				'topNeeds' => 'forecast' === $key ? array(
					array(
						'text'     => 'Clearer smoke forecasts',
						'mentions' => 1,
						'projects' => array( $project ),
					),
				) : array(),
			);
		}
		return (string) json_encode( $areas );
	}

	/**
	 * Sends a form to the screen.
	 *
	 * @param array<string, string> $fields The form's fields.
	 */
	private static function send( array $fields ): void {
		$_POST = $fields;
		Synthesis_Screen::handle();
	}

	/**
	 * The screen as drawn.
	 */
	private static function drawn(): string {
		ob_start();
		Synthesis_Screen::render();
		return (string) ob_get_clean();
	}

	/**
	 * A check that finds problems says each, keeps the paste to fix, and writes and
	 * keeps nothing.
	 */
	public function test_a_check_with_problems_says_each(): void {
		self::send(
			array(
				'firehouse_step'      => 'check',
				'firehouse_synthesis' => self::synthesis( 'draft-study' ),
			)
		);
		$page = self::drawn();

		$this->assertSame( 'Not ready: FireHouse synthesis', Synthesis_Screen::title( 'FireHouse synthesis' ) );
		$this->assertStringContainsString( 'names “draft-study”, Draft study, which the site doesn’t show', $page );
		$this->assertStringContainsString( 'draft-study', $page, 'The paste, to fix.' );
		$this->assertStringNotContainsString( 'Mark reviewed and publish', $page );
		$this->assertSame( array(), $this->transients );
		$this->assertSame( array(), $this->updated );
	}

	/**
	 * A check with nothing wrong shows the needs and waits to be marked reviewed,
	 * offering the person's name and NotebookLM, and writes nothing yet.
	 */
	public function test_a_good_check_waits_to_be_marked_reviewed(): void {
		self::send(
			array(
				'firehouse_step'      => 'check',
				'firehouse_synthesis' => self::synthesis( 'R_smoke' ),
			)
		);
		$page = self::drawn();

		$this->assertSame( 'Ready to mark reviewed: FireHouse synthesis', Synthesis_Screen::title( 'FireHouse synthesis' ) );
		$this->assertStringContainsString( '<td>Clearer smoke forecasts</td><td>1</td><td>Smoke study</td>', $page );
		$this->assertStringContainsString( 'value="Pat Reviewer"', $page );
		$this->assertStringContainsString( 'value="NotebookLM"', $page );
		$this->assertSame( 'the-token', $this->transients['firehouse_synthesis_6']['token'] );
		$this->assertSame( array( 11 ), $this->transients['firehouse_synthesis_6']['needs']['forecast'][0]['projects'] );
		$this->assertSame( array(), $this->updated );
	}

	/**
	 * Marking it reviewed publishes it to every topic, with the reviewer, the date,
	 * how many projects the site shows and the tool, and clears the check.
	 */
	public function test_marking_it_reviewed_publishes_it(): void {
		self::send(
			array(
				'firehouse_step'      => 'check',
				'firehouse_synthesis' => self::synthesis(),
			)
		);
		self::send(
			array(
				'firehouse_step'     => 'publish',
				'firehouse_token'    => 'the-token',
				'firehouse_reviewer' => ' Stephanie Hoekstra and Emily Wells ',
				'firehouse_model'    => 'NotebookLM',
			)
		);

		$this->assertSame( 'Published: FireHouse synthesis', Synthesis_Screen::title( 'FireHouse synthesis' ) );
		$this->assertSame( array( 40, 41, 42, 43 ), array_column( $this->updated, 'ID' ) );
		$this->assertSame(
			array(
				'fh_topNeeds'    => array(
					array(
						'text'     => 'Clearer smoke forecasts',
						'mentions' => 1,
						'projects' => array( 11 ),
					),
				),
				'fh_updatedAt'   => '2026-11-16',
				'fh_sourceCount' => 1,
				'fh_model'       => 'NotebookLM',
				'fh_reviewedBy'  => 'Stephanie Hoekstra and Emily Wells',
			),
			$this->updated[1]['meta_input']
		);
		$this->assertSame( array(), $this->updated[0]['meta_input']['fh_topNeeds'], 'An area with no need.' );
		$this->assertSame( array(), $this->transients, 'The check is cleared.' );
		$page = self::drawn();
		$this->assertStringContainsString( 'Published: the synthesis reviewed by Stephanie Hoekstra and Emily Wells', $page );
		$this->assertStringContainsString( 'Marked reviewed by Pat Reviewer, who was signed in', $page );
	}

	/**
	 * A second click, an expired check or a wrong token publishes nothing.
	 */
	public function test_a_check_no_longer_waiting_publishes_nothing(): void {
		self::send(
			array(
				'firehouse_step'     => 'publish',
				'firehouse_token'    => 'the-token',
				'firehouse_reviewer' => 'Pat',
				'firehouse_model'    => 'NotebookLM',
			)
		);
		$this->assertSame( 'Not published: FireHouse synthesis', Synthesis_Screen::title( 'FireHouse synthesis' ) );

		self::send(
			array(
				'firehouse_step'      => 'check',
				'firehouse_synthesis' => self::synthesis(),
			)
		);
		self::send(
			array(
				'firehouse_step'     => 'publish',
				'firehouse_token'    => 'another-token',
				'firehouse_reviewer' => 'Pat',
				'firehouse_model'    => 'NotebookLM',
			)
		);
		$this->assertStringContainsString( 'Not published: this check isn’t waiting any more', self::drawn() );
		$this->assertSame( array(), $this->updated );
	}

	/**
	 * Of two clicks at once, only the request that deletes the waiting check publishes:
	 * the other finds it gone, though it read it first.
	 */
	public function test_of_two_clicks_at_once_one_publishes(): void {
		self::send(
			array(
				'firehouse_step'      => 'check',
				'firehouse_synthesis' => self::synthesis(),
			)
		);
		$kept = $this->transients['firehouse_synthesis_6'];
		Functions\when( 'get_transient' )->justReturn( $kept );
		Functions\when( 'delete_transient' )->justReturn( false );
		self::send(
			array(
				'firehouse_step'     => 'publish',
				'firehouse_token'    => 'the-token',
				'firehouse_reviewer' => 'Pat',
				'firehouse_model'    => 'NotebookLM',
			)
		);

		$this->assertSame( 'Not published: FireHouse synthesis', Synthesis_Screen::title( 'FireHouse synthesis' ) );
		$this->assertSame( array(), $this->updated );
	}

	/**
	 * Reviewed by and Made with that would show nothing, hold a character visitors
	 * wouldn't see, or run past 200 characters publish nothing.
	 */
	public function test_reviewed_by_and_made_with_must_show_as_typed(): void {
		self::send(
			array(
				'firehouse_step'      => 'check',
				'firehouse_synthesis' => self::synthesis(),
			)
		);
		self::send(
			array(
				'firehouse_step'     => 'publish',
				'firehouse_token'    => 'the-token',
				'firehouse_reviewer' => "\u{200B}",
				'firehouse_model'    => "NotebookLM\u{202E}" . str_repeat( 'x', 200 ),
			)
		);
		$page = self::drawn();

		$this->assertStringContainsString( 'Reviewed by is empty', $page );
		$this->assertStringContainsString( 'Made with holds a character visitors wouldn’t see (U+202E)', $page );
		$this->assertStringContainsString( 'Made with is longer than 200 characters.', $page );
		$this->assertSame( array(), $this->updated );
	}

	/**
	 * The review shows a need's text and its projects' titles as the pages will:
	 * "R&amp;D" as typed, not as "R&D".
	 */
	public function test_the_review_shows_text_as_the_pages_will(): void {
		$this->meta[11]['fh_title'] = 'Smoke &amp; heat';
		self::send(
			array(
				'firehouse_step'      => 'check',
				'firehouse_synthesis' => str_replace( 'Clearer smoke forecasts', 'R&amp;D <forecasts>', self::synthesis() ),
			)
		);

		$this->assertStringContainsString( '<td>R&amp;amp;D &lt;forecasts&gt;</td><td>1</td><td>Smoke &amp;amp; heat</td>', self::drawn() );
	}

	/**
	 * An empty Reviewed by or Made with publishes nothing, says which, and keeps the
	 * check waiting.
	 */
	public function test_who_reviewed_it_and_what_made_it_are_needed(): void {
		self::send(
			array(
				'firehouse_step'      => 'check',
				'firehouse_synthesis' => self::synthesis(),
			)
		);
		self::send(
			array(
				'firehouse_step'     => 'publish',
				'firehouse_token'    => 'the-token',
				'firehouse_reviewer' => ' ',
				'firehouse_model'    => '',
			)
		);
		$page = self::drawn();

		$this->assertSame( 'Not published: FireHouse synthesis', Synthesis_Screen::title( 'FireHouse synthesis' ) );
		$this->assertStringContainsString( 'Reviewed by is empty', $page );
		$this->assertStringContainsString( 'Made with is empty', $page );
		$this->assertArrayHasKey( 'firehouse_synthesis_6', $this->transients );
		$this->assertSame( array(), $this->updated );
	}

	/**
	 * If a project it names was unpublished since the check, marking it reviewed
	 * publishes nothing and shows the check as things stand.
	 */
	public function test_a_project_unpublished_since_the_check_stops_it(): void {
		self::send(
			array(
				'firehouse_step'      => 'check',
				'firehouse_synthesis' => self::synthesis(),
			)
		);
		$this->public = array();
		self::send(
			array(
				'firehouse_step'     => 'publish',
				'firehouse_token'    => 'the-token',
				'firehouse_reviewer' => 'Pat',
				'firehouse_model'    => 'NotebookLM',
			)
		);
		$page = self::drawn();

		$this->assertSame( 'Not published: FireHouse synthesis', Synthesis_Screen::title( 'FireHouse synthesis' ) );
		$this->assertStringContainsString( 'Not published: the projects changed since the check', $page );
		$this->assertStringContainsString( 'which the site doesn’t show', $page );
		$this->assertSame( array(), $this->updated );
	}

	/**
	 * Someone who may not publish a topic is refused before anything is read.
	 */
	public function test_only_editors_may_use_it(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'wp_die' )->twice()->andThrow( new \RuntimeException( 'refused' ) );

		foreach ( array( 'handle', 'download' ) as $method ) {
			try {
				Synthesis_Screen::$method();
				$this->fail( $method . ' went on.' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'refused', $e->getMessage() );
			}
		}
	}

	/**
	 * The NotebookLM source holds the projects the site shows and the four topics.
	 */
	public function test_the_source_holds_what_the_site_shows(): void {
		Functions\when( 'wp_specialchars_decode' )->alias( static fn( string $text ): string => ucfirst( $text ) );
		$source = Synthesis_Screen::source();

		$this->assertStringContainsString( 'It contains all 1 published projects', $source );
		$this->assertStringContainsString( "## Project 1: Smoke study\n\n- **Project ID:** R_smoke\n- **Page:** /projects/smoke-study\n- **Researchers:** Jo Smith", $source );
		$this->assertStringNotContainsString( 'Draft study', $source );
		$this->assertStringContainsString( "### Observe\n", $source );
		$this->assertLessThan( strpos( $source, '### Forecast' ), strpos( $source, '### Observe' ), 'By their order.' );
	}

	/**
	 * The screen adds its page and its download.
	 */
	public function test_it_hooks_its_page_and_download(): void {
		Synthesis_Screen::hook();

		$this->assertNotFalse( has_action( 'admin_menu', array( Synthesis_Screen::class, 'add_page' ) ) );
		$this->assertNotFalse( has_action( 'admin_post_firehouse_notebook_source', array( Synthesis_Screen::class, 'download' ) ) );
	}
}
