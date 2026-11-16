<?php
/**
 * Tests for the survey form in WordPress: its switch, its page, and a submission.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Settings;
use Firehouse\Survey\Survey;

/**
 * The Submit page takes a submission only while the form is open, after its nonce, its
 * hidden field and its start time; a good one becomes a draft project with its private
 * answers apart, and the browser goes on to the thanks.
 */
final class SurveyTest extends WordPressTestCase {

	/**
	 * The stored settings.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings = array( 'surveyOpen' => true );

	/**
	 * What wp_insert_post() was given.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $inserted = array();

	/**
	 * What add_post_meta() was given.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $meta = array();

	/**
	 * Whether the Submit page exists.
	 *
	 * @var bool
	 */
	private bool $page = true;

	/**
	 * A WordPress with the landing page (10) and the Submit page (20) under it.
	 */
	protected function setUp(): void {
		parent::setUp();
		$_POST = array();
		$_GET  = array();
		Functions\when( 'get_option' )->alias( fn( string $name ): mixed => Settings::OPTION === $name ? $this->settings : false );
		Functions\when( 'get_posts' )->alias(
			function ( array $args ): array {
				if ( 'firehouse' === ( $args['name'] ?? null ) ) {
					return array( 10 );
				}
				if ( Survey::SLUG === ( $args['name'] ?? null ) ) {
					return $this->page ? array( 20 ) : array();
				}
				return array();
			}
		);
		Functions\when( 'get_post' )->alias(
			static fn( mixed $id ): ?\WP_Post => 20 === (int) $id ? new \WP_Post(
				array(
					'ID'        => 20,
					'post_type' => 'page',
				)
			) : null
		);
		Functions\when( 'is_page' )->alias( static fn( mixed $id = null ): bool => 20 === $id );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.test/firehouse/submit/' );
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://example.test' . $path );
		Functions\when( 'nocache_headers' )->justReturn( null );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $text ): string => trim( $text ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_slash' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( 'a salt' );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url ): mixed => parse_url( $url ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Standing in for wp_parse_url().
		Functions\when( 'wp_create_nonce' )->justReturn( 'a-nonce' );
		Functions\when( 'wp_verify_nonce' )->alias( static fn( string $nonce ): bool => 'a-nonce' === $nonce );
		Functions\when( 'wp_generate_password' )->justReturn( 'abcdefghijklmnop' );
		Functions\when( 'remove_accents' )->returnArg();
		Functions\when( 'rest_validate_value_from_schema' )->justReturn( true );
		Functions\when( 'kses_remove_filters' )->justReturn( null );
		Functions\when( 'kses_init_filters' )->justReturn( null );
		Functions\when( 'add_query_arg' )->alias( static fn( string $key, string $value, string $url ): string => $url . '?' . $key . '=' . $value );
		Functions\when( 'wp_insert_post' )->alias(
			function ( array $postarr ): int {
				$this->inserted[] = $postarr;
				return 30;
			}
		);
		Functions\when( 'add_post_meta' )->alias(
			function ( ...$args ): bool {
				$this->meta[] = $args;
				return true;
			}
		);
		// The redirect ends the request; the test catches it where exit would come.
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( string $url, int $status ): void {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A test's stand-in; the message goes to the test, never a page.
				throw new \RuntimeException( $status . ' ' . $url );
			}
		);
	}

	/**
	 * Clears the form and the screen's state.
	 */
	protected function tearDown(): void {
		$_POST = array();
		$_GET  = array();
		( new \ReflectionProperty( Survey::class, 'state' ) )->setValue( null, null );
		parent::tearDown();
	}

	/**
	 * A good submission's form fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function good(): array {
		return array(
			'submitter'      => 'Jo Smith',
			'email'          => 'jo@example.org',
			'org'            => 'A university',
			'title'          => 'A smoke study',
			'completionYear' => '2025',
			'irb'            => 'yes',
			'firePhases'     => array( 'during' ),
			'projectType'    => 'research',
			'methods'        => array( 'survey' ),
			'regions'        => array( 'NWCC' ),
			'abstract'       => 'What we studied.',
			'takeaways'      => array( 'One', 'Two' ),
			'needs'          => array( 'Need for more. Recommend more.' ),
		);
	}

	/**
	 * Sends the form, as a researcher would after a minute.
	 *
	 * @param array<string, mixed> $fields Its fields.
	 * @param array<string, mixed> $more   More of the POST.
	 * @return string The redirect, or '' if the page was drawn again.
	 */
	private static function send( array $fields, array $more = array() ): string {
		$_POST = $more + array(
			'firehouse_survey' => $fields,
			'_wpnonce'         => 'a-nonce',
			Survey::STARTED    => Survey::stamp( time() - 60 ),
		);
		try {
			Survey::handle();
		} catch ( \RuntimeException $redirect ) {
			return $redirect->getMessage();
		}
		return '';
	}

	/**
	 * A good submission becomes a draft project, its private answers apart, and the
	 * browser goes on to the thanks; without IRB approval, to the form's own ending.
	 */
	public function test_a_good_submission_becomes_a_draft(): void {
		$this->assertSame( '303 https://example.test/firehouse/submit/?firehouse_sent=yes', self::send( self::good() ) );

		$this->assertCount( 1, $this->inserted );
		$post = $this->inserted[0];
		$this->assertSame( 'fh_project', $post['post_type'] );
		$this->assertSame( 'draft', $post['post_status'] );
		$this->assertSame( 'a-smoke-study', $post['post_name'] );
		$this->assertSame( 0, $post['post_author'] );
		$this->assertSame( 'W_abcdefghijklmnop', $post['meta_input']['fh_id'] );
		$this->assertSame( 'A smoke study', $post['meta_input']['fh_title'] );
		$this->assertArrayNotHasKey( 'fh_email', $post['meta_input'], 'Nothing private in the project (rule 2).' );
		$this->assertSame( array( 30, Survey::PRIVATE_KEY ), array_slice( $this->meta[0], 0, 2 ) );
		$this->assertSame( 'jo@example.org', $this->meta[0][2]['email'] );

		$this->assertSame( '303 https://example.test/firehouse/submit/?firehouse_sent=no-irb', self::send( array( 'irb' => 'no' ) + self::good() ) );
		$this->assertFalse( $this->inserted[1]['meta_input']['fh_irbApproved'], 'Kept, as a draft the publishing rules won’t publish.' );
	}

	/**
	 * A form with a problem is drawn again with what was sent, and nothing is written.
	 */
	public function test_a_problem_draws_the_form_again(): void {
		$this->assertSame( '', self::send( array( 'title' => '' ) + self::good() ) );

		$state = Survey::state();
		$this->assertSame( 'form', $state['step'] );
		$this->assertSame( array( 'title' => 'The project’s title: this is required.' ), $state['problems'] );
		$this->assertSame( 'A university', $state['values']['org'] );
		$this->assertSame( array(), $this->inserted );
	}

	/**
	 * A bot is turned away: the hidden field filled is thanked and dropped, a form sent
	 * too fast or with a forged start time is drawn again, and an old nonce too.
	 */
	public function test_a_bot_is_turned_away(): void {
		$this->assertSame( '303 https://example.test/firehouse/submit/?firehouse_sent=yes', self::send( self::good(), array( Survey::TRAP => 'https://spam.example' ) ) );
		$this->assertSame( array(), $this->inserted );

		$this->assertSame( '', self::send( self::good(), array( Survey::STARTED => Survey::stamp( time() ) ) ) );
		$this->assertArrayHasKey( 'form', Survey::state()['problems'] );
		$this->assertFalse( Survey::took_long_enough( time() - 60 . '.forged' ) );
		$this->assertFalse( Survey::took_long_enough( Survey::stamp( time() - 2 * DAY_IN_SECONDS ) ), 'Too old.' );
		$this->assertTrue( Survey::took_long_enough( Survey::stamp( time() - 10 ) ) );

		$this->assertSame( '', self::send( self::good(), array( '_wpnonce' => 'old' ) ) );
		$this->assertStringStartsWith( 'The form had been open for too long', Survey::state()['problems']['form'] );
		$this->assertSame( array(), $this->inserted );
	}

	/**
	 * An entry's Add and Remove buttons send the form back with one more or one less
	 * box, the focus on the box added or where the removed one was, writing nothing.
	 */
	public function test_an_entry_box_is_added_or_removed(): void {
		self::send( self::good(), array( Survey::CHANGE => 'add:needs' ) );
		$state = Survey::state();
		$this->assertSame( array( 'Need for more. Recommend more.', '' ), $state['values']['needs'] );
		$this->assertSame( 'needs-2', $state['focus'] );
		$this->assertSame( array(), $state['problems'], 'Not checked yet.' );

		self::send( array( 'takeaways' => array( 'One', 'Two', 'Three' ) ) + self::good(), array( Survey::CHANGE => 'remove:takeaways:2' ) );
		$state = Survey::state();
		$this->assertSame( array( 'One', 'Three' ), $state['values']['takeaways'] );
		$this->assertSame( 'takeaways-1', $state['focus'] );
		$this->assertSame( array(), $this->inserted );
	}

	/**
	 * Closed, the page says so and takes nothing; opening it creates the page when
	 * it's missing, and every Submit a Finding button then leads there.
	 */
	public function test_the_form_takes_nothing_until_opened(): void {
		$this->settings = array(
			'surveyOpen'    => false,
			'submitFormUrl' => 'https://forms.example.org/firehouse',
		);
		$this->assertSame( '', self::send( self::good() ) );
		$this->assertSame( array( 'step' => 'closed' ), Survey::state() );
		$this->assertSame( array(), $this->inserted );
		$this->assertSame( 'https://forms.example.org/firehouse', Settings::get()['submitFormUrl'], 'Closed: the survey address.' );

		$this->page = false;
		Survey::opened();
		$this->assertSame( array(), $this->inserted, 'Closed: no page.' );

		$this->settings['surveyOpen'] = true;
		Survey::opened();
		$this->assertSame(
			array(
				'post_type'    => 'page',
				'post_parent'  => 10,
				'post_name'    => 'submit',
				'post_status'  => 'publish',
				'post_title'   => 'Submit a Finding',
				'post_content' => '<!-- wp:firehouse/survey-form /-->',
			),
			$this->inserted[0]
		);
		$this->assertSame( 'https://forms.example.org/firehouse', Settings::get()['submitFormUrl'], 'No page yet: the survey address.' );

		$this->page = true;
		$this->assertSame( 'https://example.test/firehouse/submit/', Settings::get()['submitFormUrl'], 'Open: the Submit page.' );
		$this->assertSame( 'https://forms.example.org/firehouse', Settings::stored()['submitFormUrl'], 'What’s stored is unchanged.' );
	}

	/**
	 * Anywhere but the Submit page, a POST is left alone.
	 */
	public function test_only_the_submit_page_is_handled(): void {
		Functions\when( 'is_page' )->justReturn( false );
		Functions\expect( 'nocache_headers' )->never();
		$this->assertSame( '', self::send( self::good() ) );
		$this->assertSame( array(), $this->inserted );
	}
}
