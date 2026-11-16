<?php
/**
 * Tests for the FireHouse → Synthesis screen.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Analysis;
use Firehouse\Synthesis_Screen;
use Firehouse\Workers_Ai;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

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
	 * Options by name, with the site's date and time formats.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array(
		'date_format' => 'j F Y',
		'time_format' => 'g:i a',
	);

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
		Functions\when( 'wp_generate_password' )->justReturn( 'TheToken000000000001' );
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
		Functions\when( 'get_option' )->alias( fn( string $name, mixed $fallback = false ): mixed => $this->options[ $name ] ?? $fallback );
		Functions\when( 'update_option' )->alias(
			function ( string $name, mixed $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'selected' )->alias(
			static function ( mixed $selected, mixed $current ): string {
				$attribute = (string) $selected === (string) $current ? " selected='selected'" : '';
				echo $attribute; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A test's stand-in for selected().
				return $attribute;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\when( 'spawn_cron' )->justReturn( null );
		Functions\when( 'wp_json_encode' )->alias( static fn( mixed $data ): string => (string) json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standing in for wp_json_encode().
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( array $response ): int => $response['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( array $response ): string => $response['body'] );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		// The redirect ends the request; the test catches it where exit would come.
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( string $url ): void {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A test's stand-in; the message goes to the test, never a page.
				throw new \RuntimeException( 'redirect ' . $url );
			}
		);
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
		$this->assertSame( 'TheToken000000000001', $this->transients['firehouse_synthesis_6'], 'The latest check, by its token.' );
		$this->assertSame( array( 11 ), $this->transients['firehouse_synthesis_6_TheToken000000000001']['needs']['forecast'][0]['projects'] );
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
				'firehouse_token'    => 'TheToken000000000001',
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
				'firehouse_token'    => 'TheToken000000000001',
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
				'firehouse_token'    => 'AnotherToken00000002',
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
		$kept = $this->transients['firehouse_synthesis_6_TheToken000000000001'];
		Functions\when( 'get_transient' )->justReturn( $kept );
		Functions\when( 'delete_transient' )->justReturn( false );
		self::send(
			array(
				'firehouse_step'     => 'publish',
				'firehouse_token'    => 'TheToken000000000001',
				'firehouse_reviewer' => 'Pat',
				'firehouse_model'    => 'NotebookLM',
			)
		);

		$this->assertSame( 'Not published: FireHouse synthesis', Synthesis_Screen::title( 'FireHouse synthesis' ) );
		$this->assertSame( array(), $this->updated );
	}

	/**
	 * Two clicks that both read the waiting check before either claims it: the first
	 * publishes, and checking it again stores nothing for the second to claim, so the
	 * second publishes nothing. A click with an older token can't claim a newer check.
	 */
	public function test_a_click_can_claim_only_its_own_check(): void {
		self::send(
			array(
				'firehouse_step'      => 'check',
				'firehouse_synthesis' => self::synthesis(),
			)
		);
		$kept   = $this->transients['firehouse_synthesis_6_TheToken000000000001'];
		$read   = function ( string $name ) use ( $kept ): mixed {
			return 'firehouse_synthesis_6_TheToken000000000001' === $name ? $kept : ( $this->transients[ $name ] ?? false );
		};
		$review = array(
			'firehouse_step'     => 'publish',
			'firehouse_token'    => 'TheToken000000000001',
			'firehouse_reviewer' => 'Pat',
			'firehouse_model'    => 'NotebookLM',
		);
		Functions\when( 'get_transient' )->alias( $read );

		self::send( $review );
		$this->assertCount( 4, $this->updated, 'The first click publishes.' );
		$this->assertSame( array(), $this->transients, 'Checking it again stored nothing.' );
		self::send( $review );
		$this->assertCount( 4, $this->updated, 'The second, which read the check too, publishes nothing.' );
		$this->assertSame( 'Not published: FireHouse synthesis', Synthesis_Screen::title( 'FireHouse synthesis' ) );

		// A newer check, waiting under its own token, isn't the older click's to claim.
		Functions\when( 'wp_generate_password' )->justReturn( 'NewerToken0000000003' );
		self::send(
			array(
				'firehouse_step'      => 'check',
				'firehouse_synthesis' => self::synthesis(),
			)
		);
		self::send( $review );
		$this->assertCount( 4, $this->updated );
		$this->assertArrayHasKey( 'firehouse_synthesis_6_NewerToken0000000003', $this->transients );
	}

	/**
	 * A token not shaped like one is never looked up.
	 */
	public function test_a_token_not_shaped_like_one_publishes_nothing(): void {
		Functions\expect( 'get_transient' )->never();
		self::send(
			array(
				'firehouse_step'     => 'publish',
				'firehouse_token'    => 'x_TheToken000000000001',
				'firehouse_reviewer' => 'Pat',
				'firehouse_model'    => 'NotebookLM',
			)
		);
		$this->assertSame( 'Not published: FireHouse synthesis', Synthesis_Screen::title( 'FireHouse synthesis' ) );
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
				'firehouse_token'    => 'TheToken000000000001',
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
				'firehouse_token'    => 'TheToken000000000001',
				'firehouse_reviewer' => ' ',
				'firehouse_model'    => '',
			)
		);
		$page = self::drawn();

		$this->assertSame( 'Not published: FireHouse synthesis', Synthesis_Screen::title( 'FireHouse synthesis' ) );
		$this->assertStringContainsString( 'Reviewed by is empty', $page );
		$this->assertStringContainsString( 'Made with is empty', $page );
		$this->assertArrayHasKey( 'firehouse_synthesis_6_TheToken000000000001', $this->transients );
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
				'firehouse_token'    => 'TheToken000000000001',
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
	 * Gives the site a Workers AI key, as wp-config.php would. Only for a test in its own
	 * process: a constant can't be taken away again.
	 */
	private static function key(): void {
		define( Workers_Ai::ACCOUNT, '0123456789abcdef0123456789abcdef' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The plugin's own constant, as wp-config.php sets it.
		define( Workers_Ai::TOKEN, 'test-token_0123456789ABCDEFGHIJ' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The plugin's own constant, as wp-config.php sets it.
	}

	/**
	 * A model's latest draft, done, with the synthesis's JSON.
	 *
	 * @param string $project How the need names its project.
	 * @return array<string, mixed>
	 */
	private static function draft( string $project = 'smoke-study' ): array {
		return array(
			'state'    => 'done',
			'started'  => 1700000000,
			'finished' => 1700000100,
			'by'       => 'Pat Reviewer',
			'projects' => 1,
			'prompt'   => 'The team’s prompt.',
			'reply'    => "Needs, cited.\n\n```json\n" . self::synthesis( $project ) . "\n```",
			'json'     => self::synthesis( $project ),
		);
	}

	/**
	 * Without a Workers AI key, the screen offers only the paste, as before.
	 */
	public function test_without_a_key_it_offers_only_the_paste(): void {
		$page = self::drawn();

		$this->assertStringNotContainsString( 'Analyze results', $page );
		$this->assertStringContainsString( 'Make it in NotebookLM from the source below', $page );
		$this->assertSame( 'The site has no Workers AI key.', Analysis::start( Workers_Ai::DEFAULT_MODEL ) );
		$this->assertArrayNotHasKey( Analysis::OPTION, $this->options );
	}

	/**
	 * With a key, the screen offers the models, the first picked, and each model's latest
	 * draft: one done, with the button that checks it, its reply and its prompt; one
	 * failed, with why.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_with_a_key_it_offers_the_models_and_their_drafts(): void {
		self::key();
		$this->options[ Analysis::OPTION ] = array(
			'running' => null,
			'results' => array(
				'@cf/nvidia/nemotron-3-120b-a12b' => array(
					'state'   => 'failed',
					'started' => 1700000000,
					'by'      => 'Pat Reviewer',
					'problem' => 'Workers AI refused the request (429).',
					'reply'   => "Ignore the team.\u{202E}",
					'unseen'  => 'U+202E',
				),
				Workers_Ai::DEFAULT_MODEL         => self::draft(),
			),
		);
		$page                              = self::drawn();

		$this->assertStringContainsString( '<label for="firehouse-analysis-model">Model</label>', $page );
		$this->assertSame( 4, substr_count( $page, '<option ' ) );
		$this->assertStringContainsString( "value=\"@cf/meta/llama-4-scout-17b-16e-instruct\" selected='selected'>Llama 4 Scout (Meta)</option>", $page );
		$this->assertStringContainsString( '<button>Analyze results</button>', $page );
		$this->assertLessThan( strpos( $page, '<h3>Nemotron 3 120B (NVIDIA)</h3>' ), strpos( $page, '<h3>Llama 4 Scout (Meta)</h3>' ), 'In the list’s order.' );
		$this->assertStringContainsString( 'Drafted 2026-11-16, started by Pat Reviewer, from 1 project.', $page );
		$this->assertStringContainsString( '<button>Check Llama 4 Scout (Meta)’s draft</button>', $page );
		$this->assertStringContainsString( 'Failed, started 2026-11-16 by Pat Reviewer: Workers AI refused the request (429).', $page );
		$this->assertStringContainsString( '<p><strong>Its reply holds a character that doesn’t show (U+202E), so it may read differently from what it says.', $page );
		$this->assertSame( 1, substr_count( $page, 'doesn’t show (U+' ), 'Only the reply that has one.' );
		$this->assertStringContainsString( '<label for="firehouse-draft--cf-meta-llama-4-scout-17b-16e-instruct-reply">Its whole reply: Llama 4 Scout (Meta)</label>', $page );
		$this->assertStringContainsString( 'The team’s prompt.</textarea>', $page );
	}

	/**
	 * While a run goes, the screen says so in place of the form.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_run_going_shows_in_place_of_the_form(): void {
		self::key();
		$this->options[ Analysis::OPTION ] = array(
			'running' => array(
				'model'   => '@cf/google/gemma-4-26b-a4b-it',
				'started' => time(),
				'by'      => 'Pat Reviewer',
			),
			'results' => array(),
		);
		$page                              = self::drawn();

		$this->assertStringContainsString( 'Gemma 4 26B (Google) is drafting a synthesis, started at 2026-11-16 by Pat Reviewer.', $page );
		$this->assertStringNotContainsString( '<select', $page );
	}

	/**
	 * Analyze results starts a run in the background with the model picked, and returns
	 * to the screen, so reloading it doesn't start another; while it goes, another is
	 * refused.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_analyze_starts_one_run_at_a_time(): void {
		self::key();
		$scheduled = array();
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( int $time, string $hook ) use ( &$scheduled ): bool {
				$scheduled[] = $hook;
				return true;
			}
		);
		try {
			self::send(
				array(
					'firehouse_step'           => 'analyze',
					'firehouse_analysis_model' => '@cf/openai/gpt-oss-120b',
				)
			);
			$this->fail( 'It should return to the screen.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirect /wp-admin/edit.php?post_type=fh_project&page=firehouse-synthesis', $e->getMessage() );
		}
		$this->assertSame( '@cf/openai/gpt-oss-120b', $this->options[ Analysis::OPTION ]['running']['model'] );
		$this->assertSame( 'Pat Reviewer', $this->options[ Analysis::OPTION ]['running']['by'] );
		$this->assertSame( array( Analysis::EVENT ), $scheduled );

		self::send(
			array(
				'firehouse_step'           => 'analyze',
				'firehouse_analysis_model' => Workers_Ai::DEFAULT_MODEL,
			)
		);
		$this->assertSame( 'Not started: FireHouse synthesis', Synthesis_Screen::title( 'FireHouse synthesis' ) );
		$this->assertStringContainsString( 'gpt-oss-120b (OpenAI) is still drafting one.', self::drawn() );
	}

	/**
	 * A run the site can't schedule says so at once, and leaves nothing going.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_run_that_cant_be_scheduled_says_so(): void {
		self::key();
		Functions\when( 'wp_schedule_single_event' )->justReturn( false );
		$spawned = false;
		Functions\when( 'spawn_cron' )->alias(
			static function () use ( &$spawned ): void {
				$spawned = true;
			}
		);

		$this->assertSame( 'The site couldn’t schedule the run. Try again; if it happens again, tell an administrator.', Analysis::start( Workers_Ai::DEFAULT_MODEL ) );
		$this->assertNull( $this->options[ Analysis::OPTION ]['running'] );
		$this->assertFalse( $spawned );
	}

	/**
	 * The run sends the published projects, between markers, and the prompt, and keeps
	 * the reply's JSON as the model's latest draft; the run is then over.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_run_keeps_the_models_draft(): void {
		self::key();
		$this->options[ Analysis::OPTION ] = array(
			'running' => array(
				'model'   => Workers_Ai::DEFAULT_MODEL,
				'started' => time(),
				'by'      => 'Pat Reviewer',
			),
			'results' => array(),
		);
		$sent                              = array();
		Functions\when( 'wp_safe_remote_post' )->alias(
			function ( string $url, array $args ) use ( &$sent ): array {
				$sent = json_decode( $args['body'], true );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => (string) json_encode( array( 'result' => array( 'response' => "Needs.\n\n```json\n" . self::synthesis() . "\n```" ) ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- A test's response.
				);
			}
		);

		Analysis::run();

		$user = $sent['messages'][1]['content'];
		$this->assertStringContainsString( '## Project 1: Smoke study', $user );
		$this->assertStringNotContainsString( 'Draft study', $user );
		$this->assertStringEndsWith( Analysis::own_prompt(), $user );
		$kept = $this->options[ Analysis::OPTION ];
		$this->assertNull( $kept['running'] );
		$this->assertSame( 'done', $kept['results'][ Workers_Ai::DEFAULT_MODEL ]['state'] );
		$this->assertSame( self::synthesis(), $kept['results'][ Workers_Ai::DEFAULT_MODEL ]['json'] );
		$this->assertSame( 1, $kept['results'][ Workers_Ai::DEFAULT_MODEL ]['projects'] );
	}

	/**
	 * A run that fails is kept as the model's latest, with why; one already asked, as by
	 * an event that fired twice, isn't asked again.
	 */
	public function test_a_run_that_fails_says_why(): void {
		$this->options[ Analysis::OPTION ] = array(
			'running' => array(
				'model'   => Workers_Ai::DEFAULT_MODEL,
				'started' => time(),
				'by'      => 'Pat Reviewer',
				'asked'   => true,
			),
			'results' => array(),
		);
		Analysis::run();
		$this->assertSame( array(), $this->options[ Analysis::OPTION ]['results'], 'Asked already.' );

		unset( $this->options[ Analysis::OPTION ]['running']['asked'] );
		Analysis::run();
		$result = $this->options[ Analysis::OPTION ]['results'][ Workers_Ai::DEFAULT_MODEL ];
		$this->assertSame( 'failed', $result['state'] );
		$this->assertSame( 'The site has no Workers AI key.', $result['problem'] );
		$this->assertNull( $this->options[ Analysis::OPTION ]['running'] );
	}

	/**
	 * A run finishing after another editor started a newer one leaves the newer one
	 * going, and a newer draft from the same model is kept over its own: it reads what
	 * the database holds, not what it wrote before asking.
	 */
	public function test_a_late_run_leaves_newer_ones_alone(): void {
		$older                             = array(
			'model'   => Workers_Ai::DEFAULT_MODEL,
			'started' => time() - 60,
			'by'      => 'Pat Reviewer',
		);
		$newer                             = array(
			'model'   => '@cf/google/gemma-4-26b-a4b-it',
			'started' => time(),
			'by'      => 'Sam Editor',
		);
		$this->options[ Analysis::OPTION ] = array(
			'running' => $older,
			'results' => array(),
		);
		$reads                             = 0;
		Functions\when( 'wp_cache_delete' )->alias(
			function () use ( &$reads ): bool {
				++$reads;
				return true;
			}
		);
		// While it gathers the projects, the run is taken to have failed and a newer
		// one starts.
		$projects = $this->posts;
		Functions\when( 'get_posts' )->alias(
			function ( array $args ) use ( $newer, $projects ): array {
				$this->options[ Analysis::OPTION ]['running'] = $newer;
				if ( isset( $args['meta_query'] ) ) {
					return 'ids' === ( $args['fields'] ?? '' ) ? array( 11 ) : array( $projects[11] );
				}
				return array_values( array_filter( $projects, static fn( \WP_Post $post ): bool => $post->post_type === $args['post_type'] ) );
			}
		);

		Analysis::run();

		$this->assertSame( 2, $reads, 'The database, before and after.' );
		$this->assertSame( $newer, $this->options[ Analysis::OPTION ]['running'] );
		$this->assertSame( 'failed', $this->options[ Analysis::OPTION ]['results'][ Workers_Ai::DEFAULT_MODEL ]['state'] );

		$this->options[ Analysis::OPTION ] = array(
			'running' => $older,
			'results' => array( Workers_Ai::DEFAULT_MODEL => array( 'started' => time() ) + self::draft() ),
		);
		Analysis::run();
		$this->assertSame( 'done', $this->options[ Analysis::OPTION ]['results'][ Workers_Ai::DEFAULT_MODEL ]['state'], 'The newer draft, kept.' );
	}

	/**
	 * A project holding a character no one would see but a model would read stops the
	 * run before anything is sent, naming the project.
	 */
	public function test_a_hidden_character_stops_the_run(): void {
		$this->meta[11]['fh_abstract']     = "Smoke.\u{E0041}\u{E0042}";
		$this->options[ Analysis::OPTION ] = array(
			'running' => array(
				'model'   => Workers_Ai::DEFAULT_MODEL,
				'started' => time(),
				'by'      => 'Pat Reviewer',
			),
			'results' => array(),
		);
		Functions\expect( 'wp_safe_remote_post' )->never();

		Analysis::run();

		$this->assertSame( 'Project 1, “Smoke study”, holds a character that doesn’t show (U+E0041), which a model would read though no one sees it, so nothing was sent. Its text has to be corrected from the survey record first: tell the developer.', $this->options[ Analysis::OPTION ]['results'][ Workers_Ai::DEFAULT_MODEL ]['problem'] );
	}

	/**
	 * A run whose event never fired is kept as failed after half an hour; one that asked
	 * is kept as failed once its request would have given up.
	 */
	public function test_a_run_going_too_long_failed(): void {
		$this->options[ Analysis::OPTION ] = array(
			'running' => array(
				'model'   => Workers_Ai::DEFAULT_MODEL,
				'started' => time() - 1200,
				'by'      => 'Pat Reviewer',
			),
			'results' => array(),
		);
		$this->assertNotNull( Analysis::state()['running'], 'Twenty minutes: a system cron may not have run yet.' );

		$this->options[ Analysis::OPTION ]['running']['started'] = time() - 3600;
		$state = Analysis::state();
		$this->assertNull( $state['running'] );
		$this->assertStringStartsWith( 'It never started:', $state['results'][ Workers_Ai::DEFAULT_MODEL ]['problem'] );
		$this->assertSame( $state, $this->options[ Analysis::OPTION ] );

		$this->options[ Analysis::OPTION ] = array(
			'running' => array(
				'model'   => Workers_Ai::DEFAULT_MODEL,
				'started' => time() - 3600,
				'by'      => 'Pat Reviewer',
				'asked'   => time() - 240,
			),
			'results' => array(),
		);
		$this->assertNotNull( Analysis::state()['running'], 'Asked four minutes ago: still within its time.' );

		$this->options[ Analysis::OPTION ]['running']['asked'] = time() - 660;
		$this->assertStringStartsWith( 'It didn’t finish:', Analysis::state()['results'][ Workers_Ai::DEFAULT_MODEL ]['problem'] );
	}

	/**
	 * Checking a model's draft puts its JSON through the check and offers the model for
	 * Made with, which a check after editing the JSON keeps.
	 */
	public function test_checking_a_draft_offers_its_model(): void {
		$this->options[ Analysis::OPTION ] = array(
			'running' => null,
			'results' => array( Workers_Ai::DEFAULT_MODEL => self::draft() ),
		);
		self::send(
			array(
				'firehouse_step'           => 'take',
				'firehouse_analysis_model' => Workers_Ai::DEFAULT_MODEL,
			)
		);
		$page = self::drawn();

		$this->assertSame( 'Ready to mark reviewed: FireHouse synthesis', Synthesis_Screen::title( 'FireHouse synthesis' ) );
		$this->assertStringContainsString( 'id="firehouse-model" name="firehouse_model" class="regular-text" value="Llama 4 Scout (Meta), on Cloudflare Workers AI"', $page );
		$this->assertStringContainsString( '<input type="hidden" name="firehouse_made_with" value="Llama 4 Scout (Meta), on Cloudflare Workers AI" />', $page );
		$this->assertStringContainsString( 'Made with will start as “Llama 4 Scout (Meta), on Cloudflare Workers AI” when you mark it reviewed.', $page, 'Shown, not only carried.' );
		$this->assertStringNotContainsString( 'This draft read', $page, 'It read as many projects as the site shows.' );

		self::send(
			array(
				'firehouse_step'      => 'check',
				'firehouse_synthesis' => self::synthesis( 'R_smoke' ),
				'firehouse_made_with' => 'Llama 4 Scout (Meta), on Cloudflare Workers AI',
			)
		);
		$this->assertStringContainsString( 'value="Llama 4 Scout (Meta), on Cloudflare Workers AI"', self::drawn() );

		self::send(
			array(
				'firehouse_step'      => 'check',
				'firehouse_synthesis' => self::synthesis( 'R_smoke' ),
				'firehouse_made_with' => "Llama\u{202E}",
			)
		);
		$this->assertStringContainsString( 'value="NotebookLM"', self::drawn(), 'A name that wouldn’t show as typed isn’t offered.' );
	}

	/**
	 * A draft that read a different number of projects from the number the site shows now
	 * says so when checked.
	 */
	public function test_a_draft_that_read_fewer_projects_says_so(): void {
		$this->options[ Analysis::OPTION ] = array(
			'running' => null,
			'results' => array( Workers_Ai::DEFAULT_MODEL => array( 'projects' => 2 ) + self::draft() ),
		);
		self::send(
			array(
				'firehouse_step'           => 'take',
				'firehouse_analysis_model' => Workers_Ai::DEFAULT_MODEL,
			)
		);

		$this->assertStringContainsString( 'This draft read 2 projects, and the site shows 1 now. The topic pages would say the synthesis read all 1, so have the model draft it again before you mark it reviewed.', self::drawn() );
	}

	/**
	 * A draft that isn't kept, or a model not offered, checks nothing and says so.
	 */
	public function test_a_draft_not_kept_says_so(): void {
		foreach ( array( Workers_Ai::DEFAULT_MODEL, '@cf/qwen/qwq-32b' ) as $model ) {
			self::send(
				array(
					'firehouse_step'           => 'take',
					'firehouse_analysis_model' => $model,
				)
			);
			$this->assertSame( 'Not checked: FireHouse synthesis', Synthesis_Screen::title( 'FireHouse synthesis' ) );
			$page = self::drawn();
			$this->assertStringContainsString( '<h2>Not checked</h2>', $page );
			$this->assertStringContainsString( 'That draft isn’t kept any more.', $page );
			$this->assertSame( array(), $this->transients );
		}
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
