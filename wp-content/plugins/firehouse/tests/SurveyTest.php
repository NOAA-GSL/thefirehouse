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
	 * Whether a Submit page that isn't published is there.
	 *
	 * @var bool
	 */
	private bool $unpublished = false;

	/**
	 * The drafts deleted for good.
	 *
	 * @var list<int>
	 */
	private array $deleted = array();

	/**
	 * What wp_mail() was given.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $mail = array();

	/**
	 * The addresses asked for over HTTP.
	 *
	 * @var list<string>
	 */
	private array $requests = array();

	/**
	 * A WordPress with the landing page (10) and the Submit page (20) under it.
	 */
	protected function setUp(): void {
		parent::setUp();
		$_POST                     = array();
		$_GET                      = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REQUEST_URI']    = '/firehouse/submit/';
		Functions\when( 'get_option' )->alias( fn( string $name ): mixed => Settings::OPTION === $name ? $this->settings : false );
		Functions\when( 'get_posts' )->alias(
			function ( array $args ): array {
				if ( 'firehouse' === ( $args['name'] ?? null ) ) {
					return array( 10 );
				}
				if ( Survey::SLUG === ( $args['name'] ?? null ) ) {
					if ( 'publish' !== $args['post_status'] ) {
						return $this->page || $this->unpublished ? array( 20 ) : array();
					}
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
		Functions\when( 'is_page' )->alias( static fn( mixed $id = null ): bool => null === $id || 20 === $id );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.test/firehouse/submit/' );
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://example.test' . $path );
		Functions\when( 'nocache_headers' )->justReturn( null );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_unique_post_slug' )->returnArg();
		Functions\when( 'wp_delete_post' )->alias(
			function ( int $id ): bool {
				$this->deleted[] = $id;
				return true;
			}
		);
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $text ): string => trim( $text ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_slash' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( 'a salt' );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $part = -1 ): mixed => parse_url( $url, $part ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Standing in for wp_parse_url().
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
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://example.test/wp-admin/' . $path );
		Functions\when( 'is_email' )->alias( static fn( string $email ): string|false => filter_var( $email, FILTER_VALIDATE_EMAIL ) );
		Functions\when( 'wp_mail' )->alias(
			function ( ...$args ): bool {
				$this->mail[] = $args;
				return true;
			}
		);
		// No network: each lookup fails, and keeps nothing.
		Functions\when( 'wp_safe_remote_get' )->alias(
			function ( string $url ): \WP_Error {
				$this->requests[] = $url;
				return new \WP_Error( 'http_request_failed', 'No network in tests.' );
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
		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_SEC_FETCH_SITE'] );
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

	/**
	 * A form sent anywhere but the page's own address, as its permalink spells it,
	 * writes nothing, though WordPress found the page from what the POST named: a rate
	 * limit on the page's address would never see it. So does one a browser says came
	 * from another site.
	 *
	 * @param array<string, string> $server What the request says of itself.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'elsewhere' )]
	public function test_a_form_sent_from_elsewhere_writes_nothing( array $server ): void {
		Functions\expect( 'status_header' )->once()->with( 400 );
		$_SERVER = $server + $_SERVER;
		$this->assertSame( '', self::send( self::good() ) );

		$state = Survey::state();
		$this->assertSame( array( 'form' ), array_keys( $state['problems'] ) );
		$this->assertSame( '', $state['values']['title'], 'Nothing sent from elsewhere is shown.' );
		$this->assertSame( array(), $this->inserted );
	}

	/**
	 * Requests that aren't a form sent from the Submit page itself.
	 *
	 * @return array<string, array{0: array<string, string>}>
	 */
	public static function elsewhere(): array {
		return array(
			'another page naming it'  => array( array( 'REQUEST_URI' => '/about/' ) ),
			'the home page'           => array( array( 'REQUEST_URI' => '/' ) ),
			'without its slash'       => array( array( 'REQUEST_URI' => '/firehouse/submit' ) ),
			'in capitals'             => array( array( 'REQUEST_URI' => '/Firehouse/submit/' ) ),
			'through index.php'       => array( array( 'REQUEST_URI' => '/index.php/firehouse/submit/' ) ),
			'another site, by origin' => array( array( 'HTTP_ORIGIN' => 'https://elsewhere.example' ) ),
			'another site, by fetch'  => array( array( 'HTTP_SEC_FETCH_SITE' => 'cross-site' ) ),
			'a null origin elsewhere' => array(
				array(
					'HTTP_ORIGIN'         => 'null',
					'HTTP_SEC_FETCH_SITE' => 'cross-site',
				),
			),
			'a neighbouring site'     => array(
				array(
					'HTTP_ORIGIN'         => 'https://other.example.test',
					'HTTP_SEC_FETCH_SITE' => 'same-site',
				),
			),
			'not a POST'              => array( array( 'REQUEST_METHOD' => 'PUT' ) ),
		);
	}

	/**
	 * The site's own page goes on to the rest of the checks: by its host, whatever the
	 * scheme it's reached by; by a browser saying it's the same origin; with an origin
	 * of "null", which a browser sends from the site's own page when the page asks for
	 * no referrer; or naming no origin, as a script's or an older browser's may not.
	 *
	 * @param array<string, string> $server What the request says of itself.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'here' )]
	public function test_a_form_sent_from_the_page_is_taken( array $server ): void {
		$_SERVER = $server + $_SERVER;
		$this->assertSame( '303 https://example.test/firehouse/submit/?firehouse_sent=yes', self::send( self::good() ) );
		$this->assertCount( 1, $this->inserted );
	}

	/**
	 * Requests from the Submit page itself.
	 *
	 * @return array<string, array{0: array<string, string>}>
	 */
	public static function here(): array {
		return array(
			'its host, another scheme'   => array( array( 'HTTP_ORIGIN' => 'http://EXAMPLE.test' ) ),
			'the same origin'            => array( array( 'HTTP_SEC_FETCH_SITE' => 'same-origin' ) ),
			'no referrer, by fetch'      => array(
				array(
					'HTTP_ORIGIN'         => 'null',
					'HTTP_SEC_FETCH_SITE' => 'same-origin',
				),
			),
			'no referrer, older browser' => array( array( 'HTTP_ORIGIN' => 'null' ) ),
			'naming nothing'             => array( array() ),
		);
	}

	/**
	 * The wait counts from when the form was first drawn: adding a box, or a problem,
	 * keeps the time it was sent with, so sending straight after isn't refused. A time
	 * this site didn't sign starts the wait again.
	 */
	public function test_the_wait_counts_from_the_first_drawing(): void {
		$first = Survey::stamp( time() - 60 );
		self::send( self::good(), array( Survey::CHANGE => 'add:needs' ) );
		$this->assertSame( $first, Survey::state()['started'] );
		$this->assertSame( 'https://example.test/firehouse/submit/', Survey::state()['action'], 'Sent to the address with no fragment.' );

		self::send( array( 'title' => '' ) + self::good() );
		$this->assertSame( $first, Survey::state()['started'] );

		self::send(
			self::good(),
			array(
				Survey::CHANGE  => 'add:needs',
				Survey::STARTED => time() - 60 . '.forged',
			)
		);
		$this->assertNotSame( $first, Survey::state()['started'] );
		$this->assertTrue( Survey::is_stamp( Survey::state()['started'] ) );

		$this->assertNotSame( hash_hmac( 'sha256', (string) ( time() - 60 ), 'a salt' ), explode( '.', $first )[1], 'Signed under its own name, not as a bare time.' );
	}

	/**
	 * The private answers are slashed for add_post_meta(), which unslashes them, so a
	 * backslash is kept. If they can't be written, the draft is deleted and the form
	 * asks for it again; with none given, nothing is written for them.
	 */
	public function test_the_private_answers_are_kept_or_the_draft_goes(): void {
		Functions\when( 'wp_slash' )->alias( static fn( mixed $value ): mixed => is_array( $value ) ? array_map( static fn( mixed $item ): mixed => is_string( $item ) ? addslashes( $item ) : $item, $value ) : $value );
		self::send( array( 'jobTitle' => 'Hydrologist \\ IMET' ) + self::good() );
		$this->assertSame( 'Hydrologist \\\\ IMET', $this->meta[0][2]['jobTitle'] );

		Functions\when( 'add_post_meta' )->justReturn( false );
		$this->assertSame( '', self::send( self::good() ) );
		$this->assertSame( array( 30 ), $this->deleted );
		$this->assertStringStartsWith( 'Your answers couldn’t be saved just now.', Survey::state()['problems']['form'] );

		Functions\when( 'add_post_meta' )->alias(
			function ( ...$args ): bool {
				$this->meta[] = $args;
				return true;
			}
		);
		$this->meta = array();
		$this->assertStringEndsWith( 'firehouse_sent=yes', self::send( array_diff_key( self::good(), array( 'email' => true ) ) ) );
		$this->assertSame( array(), $this->meta, 'No private answers given: none written.' );
	}

	/**
	 * A link holding an email address is refused, as the other words the site shows
	 * are, and nothing is saved (rule 2).
	 */
	public function test_nothing_shaped_like_an_email_is_saved(): void {
		$this->assertSame( '', self::send( array( 'links' => array( 'https://example.org/report?contact=jo@example.org' ) ) + self::good() ) );
		$this->assertArrayHasKey( 'links-1', Survey::state()['problems'] );
		$this->assertSame( array(), $this->inserted );
	}

	/**
	 * WordPress's export (Tools → Export) leaves the private answers out, and every other
	 * field as it was. No Firehouse field's key is theirs, so they're in no schema, no
	 * REST response and no revision.
	 */
	public function test_the_private_answers_are_left_out_of_the_export(): void {
		$this->assertTrue( Survey::skip_in_export( false, Survey::PRIVATE_KEY ) );
		$this->assertFalse( Survey::skip_in_export( false, 'fh_title' ) );
		$this->assertTrue( Survey::skip_in_export( true, '_edit_lock' ), 'What another filter left out stays out.' );
		foreach ( array_keys( \Firehouse\Meta::fields( 'fh_project' ) ) as $name ) {
			$this->assertNotSame( Survey::PRIVATE_KEY, \Firehouse\Meta::key( $name ) );
		}
	}

	/**
	 * After a send, the page's title says what happened, so a screen reader says it as
	 * the page loads; anywhere else, and before a send, it's left as it was.
	 */
	public function test_the_title_says_what_happened(): void {
		Functions\stubTranslationFunctions();
		$this->assertSame( 'Submit a Finding', Survey::document_title( 'Submit a Finding' ), 'Not the Submit page.' );

		self::send( array( 'title' => '' ) + self::good() );
		$this->assertSame( 'Error: Submit a Finding', Survey::document_title( 'Submit a Finding' ) );
		$this->assertSame( '', Survey::document_title( '' ), 'No title of its own: left to WordPress.' );

		self::send( self::good(), array( Survey::CHANGE => 'add:needs' ) );
		$this->assertSame( 'Submit a Finding', Survey::document_title( 'Submit a Finding' ), 'A box added: no problem yet.' );

		$_POST = array();
		$_GET  = array( 'firehouse_sent' => 'yes' );
		Survey::handle();
		$this->assertSame( 'Thank you: Submit a Finding', Survey::document_title( 'Submit a Finding' ) );
	}

	/**
	 * A Submit page that's there but not published (a draft, say) isn't made again
	 * beside it at submit-2: it's left for an administrator to publish.
	 */
	public function test_an_unpublished_submit_page_is_not_made_again(): void {
		$this->page        = false;
		$this->unpublished = true;
		Survey::opened();
		$this->assertSame( array(), $this->inserted );
	}

	/**
	 * Each submission saved is reported to the team mailbox with a link to its draft,
	 * in fixed text: nothing the submitter typed, private or not, is in it. One
	 * without IRB approval says so. A form drawn again sends nothing.
	 */
	public function test_the_team_mailbox_is_told(): void {
		$sent = array(
			'title'    => 'A smoke study about https://spam.example',
			'jobTitle' => 'Hydrologist',
		) + self::good();
		self::send( $sent );

		$this->assertCount( 1, $this->mail );
		[ $to, $subject, $body ] = $this->mail[0];
		$this->assertSame( 'gsl.firehouse@noaa.gov', $to );
		$this->assertSame( 'New FireHouse submission', $subject );
		$this->assertStringContainsString( 'https://example.test/wp-admin/post.php?post=30&action=edit', $body );
		$this->assertStringContainsString( 'https://example.test/wp-admin/edit.php?post_type=fh_project&post_status=draft', $body );
		foreach ( array( 'smoke', 'spam.example', 'jo@example.org', 'Hydrologist', 'Jo Smith', 'A university', 'What we studied' ) as $typed ) {
			$this->assertStringNotContainsString( $typed, $subject . $body, 'Nothing typed reaches the email.' );
		}

		self::send( array( 'irb' => 'no' ) + self::good() );
		$this->assertSame( 'New FireHouse submission, without IRB approval', $this->mail[1][1] );
		$this->assertStringContainsString( 'no IRB approval or exemption, so it can’t be published', $this->mail[1][2] );

		self::send( array( 'title' => '' ) + self::good() );
		self::send( self::good(), array( Survey::CHANGE => 'add:needs' ) );
		$this->assertCount( 2, $this->mail, 'Nothing saved: nothing sent.' );
	}

	/**
	 * The `firehouse_survey_notify` filter changes where the report goes, or with ""
	 * sends none; something that isn't an address sends none either.
	 */
	public function test_the_report_can_go_elsewhere_or_nowhere(): void {
		\Brain\Monkey\Filters\expectApplied( 'firehouse_survey_notify' )->once()->with( Survey::NOTIFY )->andReturn( 'test-team@example.org' );
		Survey::notify( 30, true );
		$this->assertSame( 'test-team@example.org', $this->mail[0][0] );

		foreach ( array( '', 'not an address', array( 'a@example.org' ) ) as $to ) {
			\Brain\Monkey\Filters\expectApplied( 'firehouse_survey_notify' )->once()->andReturn( $to );
			Survey::notify( 30, true );
		}
		$this->assertCount( 1, $this->mail );
	}

	/**
	 * A paper given by its DOI is looked up as the submission is saved, through
	 * doi.org; a link without one is never fetched. A lookup that fails keeps the
	 * paper as it was sent.
	 */
	public function test_a_doi_is_looked_up_and_a_link_never_is(): void {
		self::send( array( 'links' => array( 'https://example.org/report', 'https://doi.org/10.1175/WCAS-D-21-0042.1' ) ) + self::good() );

		$this->assertSame( array( 'https://doi.org/10.1175/WCAS-D-21-0042.1' ), $this->requests );
		$this->assertSame(
			array(
				array( 'url' => 'https://example.org/report' ),
				array( 'doi' => '10.1175/WCAS-D-21-0042.1' ),
			),
			$this->inserted[0]['meta_input']['fh_papers']
		);
	}
}
