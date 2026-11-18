<?php
/**
 * Tests for the Workers AI key saved on FireHouse → Settings.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Site_Health;
use Firehouse\Workers_Ai;
use Firehouse\Workers_Ai_Key;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Only an administrator saves the key; its token is sealed under the site's salts, never
 * shown again, and opens only with them; a save with anything wrong changes nothing; and
 * Workers_Ai uses it before wp-config.php's constants. Each test runs in its own process,
 * since the salts and the constants are constants. Sealing needs PHP's sodium extension
 * here, where WordPress's own copy of libsodium isn't loaded; CI checks it's there.
 */
#[RequiresFunction( 'sodium_crypto_secretbox' )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class WorkersAiKeyTest extends WordPressTestCase {

	/**
	 * An account ID shaped like Cloudflare's.
	 */
	private const ACCOUNT_ID = '0123456789abcdef0123456789abcdef';

	/**
	 * Another.
	 */
	private const OTHER_ACCOUNT = 'fedcba9876543210fedcba9876543210';

	/**
	 * A token shaped like Cloudflare's.
	 */
	private const API_TOKEN = 'saved-token_0123456789ABCDEFGHIJ';

	/**
	 * Options by name.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * The settings errors added, as get_settings_errors() gives them.
	 *
	 * @var list<array{setting: string, code: string, message: string, type: string}>
	 */
	private array $errors = array();

	/**
	 * What wp_salt( 'auth' ) gives.
	 *
	 * @var string
	 */
	private string $salt = 'wp-config-key|wp-config-salt';

	/**
	 * A site whose wp-config.php sets the salts, viewed by an administrator.
	 */
	protected function setUp(): void {
		parent::setUp();
		define( 'AUTH_KEY', 'wp-config-key|' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress's own constant, as wp-config.php sets it.
		define( 'AUTH_SALT', 'wp-config-salt' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress's own constant, as wp-config.php sets it.
		Functions\when( 'get_option' )->alias( fn( string $name, mixed $fallback = false ): mixed => $this->options[ $name ] ?? $fallback );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_salt' )->alias( fn(): string => $this->salt );
		Functions\when( 'add_settings_error' )->alias(
			function ( string $setting, string $code, string $message, string $type = 'error' ): void {
				$this->errors[] = array(
					'setting' => $setting,
					'code'    => $code,
					'message' => $message,
					'type'    => $type,
				);
			}
		);
		Functions\when( 'get_settings_errors' )->alias( fn( string $setting = '' ): array => array_values( array_filter( $this->errors, static fn( array $error ): bool => '' === $setting || $setting === $error['setting'] ) ) );
		Functions\when( 'wp_specialchars_decode' )->alias( static fn( string $text ): string => htmlspecialchars_decode( $text, ENT_QUOTES ) );
	}

	/**
	 * Saves through the form, as the screen sends it, and keeps what WordPress would.
	 *
	 * @param array<string, string> $form The form's fields.
	 * @return mixed What the save returned.
	 */
	private function save( array $form ): mixed {
		$saved                                   = Workers_Ai_Key::sanitize( $form );
		$this->options[ Workers_Ai_Key::OPTION ] = $saved;
		return $saved;
	}

	/**
	 * A key typed in is saved with its token sealed, and opens again for Workers_Ai, which
	 * then has a key from Settings.
	 */
	public function test_a_saved_token_is_sealed_and_opens_again(): void {
		$saved = $this->save(
			array(
				'account'    => ' ' . self::ACCOUNT_ID . ' ',
				'typedToken' => self::API_TOKEN . "\n",
			)
		);

		$this->assertSame( array(), $this->errors );
		$this->assertSame( self::ACCOUNT_ID, $saved['account'] );
		$this->assertStringStartsWith( 'fh1.', $saved['token'] );
		$this->assertStringNotContainsString( self::API_TOKEN, (string) json_encode( $saved ), 'Never in the clear.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- A test's look at what's stored.
		$this->assertNotSame( $saved['token'], Workers_Ai_Key::sanitize( array( 'typedToken' => self::API_TOKEN ) + $saved )['token'], 'A new nonce each time.' );
		$this->assertSame(
			array(
				'account' => self::ACCOUNT_ID,
				'token'   => self::API_TOKEN,
			),
			Workers_Ai_Key::credentials()
		);
		$this->assertTrue( Workers_Ai_Key::saved() );
		$this->assertFalse( Workers_Ai_Key::unreadable() );
		$this->assertTrue( Workers_Ai::configured() );
		$this->assertSame( Workers_Ai::FROM_SETTINGS, Workers_Ai::source() );
		$this->assertSame( 'Analyze results uses the key saved here.', Workers_Ai_Key::status() );
	}

	/**
	 * Under other salts, as on another site or after they're changed, the token doesn't
	 * open: Analyze results is off, and the screen and Site Health say to type it again.
	 */
	public function test_other_salts_dont_open_it(): void {
		$this->save(
			array(
				'account'    => self::ACCOUNT_ID,
				'typedToken' => self::API_TOKEN,
			)
		);
		$this->salt = 'another-site';

		$this->assertNull( Workers_Ai_Key::credentials() );
		$this->assertTrue( Workers_Ai_Key::unreadable() );
		$this->assertFalse( Workers_Ai::configured() );
		$this->assertStringContainsString( 'Analyze results is turned off', Workers_Ai_Key::status() );
		$health = Site_Health::ai_key();
		$this->assertSame( 'recommended', $health['status'] );
		$this->assertSame( 'FireHouse’s saved Workers AI key can’t be read', $health['label'] );

		// Tampered with, it doesn't open either.
		$this->salt                                       = 'wp-config-key|wp-config-salt';
		$this->options[ Workers_Ai_Key::OPTION ]['token'] = substr_replace( $this->options[ Workers_Ai_Key::OPTION ]['token'], '00', -2 );
		$this->assertNull( Workers_Ai_Key::credentials() );
		$this->options[ Workers_Ai_Key::OPTION ]['token'] = 'fh1.zz';
		$this->assertNull( Workers_Ai_Key::credentials() );
	}

	/**
	 * The key saved here comes before wp-config.php's: Cloudflare is asked with it. When
	 * it doesn't open, wp-config.php's is used.
	 */
	public function test_the_saved_key_comes_before_wp_configs(): void {
		define( Workers_Ai::ACCOUNT, self::OTHER_ACCOUNT ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- The plugin's own constant, as wp-config.php sets it.
		define( Workers_Ai::TOKEN, 'wp-config-token_0123456789ABCDEF' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- The plugin's own constant, as wp-config.php sets it.
		$this->assertSame( Workers_Ai::FROM_CONFIG, Workers_Ai::source() );
		$this->assertSame( 'Analyze results uses the key in wp-config.php. A key saved here would be used in its place.', Workers_Ai_Key::status() );

		$this->save(
			array(
				'account'    => self::ACCOUNT_ID,
				'typedToken' => self::API_TOKEN,
			)
		);
		$asked = array();
		Functions\when( 'wp_json_encode' )->alias( static fn( mixed $data ): string => (string) json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standing in for wp_json_encode().
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"success":true,"result":{"response":"A reply"}}' );
		Functions\when( 'wp_safe_remote_post' )->alias(
			static function ( string $url, array $args ) use ( &$asked ): array {
				$asked[] = array( $url, $args['headers']['Authorization'] );
				return array();
			}
		);

		$this->assertSame( Workers_Ai::FROM_SETTINGS, Workers_Ai::source() );
		$this->assertSame( 'A reply', Workers_Ai::ask( Workers_Ai::DEFAULT_MODEL, array() )['text'] ?? null );
		$this->assertStringContainsString( '/accounts/' . self::ACCOUNT_ID . '/', $asked[0][0] );
		$this->assertSame( 'Bearer ' . self::API_TOKEN, $asked[0][1] );

		$this->salt = 'changed';
		$this->assertSame( Workers_Ai::FROM_CONFIG, Workers_Ai::source() );
		$this->assertStringContainsString( 'Analyze results uses the key in wp-config.php', Workers_Ai_Key::status() );
	}

	/**
	 * An empty token keeps the one saved, so the account can change alone; once the salts
	 * have changed, the token has to be typed again.
	 */
	public function test_an_empty_token_keeps_the_saved_one(): void {
		$first  = $this->save(
			array(
				'account'    => self::ACCOUNT_ID,
				'typedToken' => self::API_TOKEN,
			)
		);
		$second = $this->save(
			array(
				'account'    => self::OTHER_ACCOUNT,
				'typedToken' => '',
			)
		);

		$this->assertSame( self::OTHER_ACCOUNT, $second['account'] );
		$this->assertSame( $first['token'], $second['token'] );

		$this->salt = 'changed';
		$this->assertSame(
			$second,
			$this->save(
				array(
					'account'    => self::ACCOUNT_ID,
					'typedToken' => '',
				)
			)
		);
		$this->assertSame( array( 'firehouse-workers-ai-token' => 'The saved API token can’t be read any more, because the site’s salts have changed. Type it again.' ), Workers_Ai_Key::errors() );
	}

	/**
	 * A save with anything wrong changes nothing and says what: an account ID or a token
	 * not shaped like Cloudflare's, no token at all, or salts WordPress keeps in the
	 * database rather than taking from wp-config.php.
	 */
	public function test_a_save_with_a_problem_changes_nothing(): void {
		$this->options[ Workers_Ai_Key::OPTION ] = array( 'kept' => true );
		$cases                                   = array(
			'firehouse-workers-ai-account' => array(
				'account'    => 'ABC',
				'typedToken' => self::API_TOKEN,
			),
			'firehouse-workers-ai-token'   => array(
				'account'    => self::ACCOUNT_ID,
				'typedToken' => "a-token\r\nX-Other: 1 and more",
			),
		);
		foreach ( $cases as $field => $form ) {
			$this->errors = array();
			$this->assertSame( array( 'kept' => true ), Workers_Ai_Key::sanitize( $form ), $field );
			$this->assertSame( array( $field ), array_keys( Workers_Ai_Key::errors() ), $field );
		}

		$this->errors = array();
		$this->assertSame(
			array( 'kept' => true ),
			Workers_Ai_Key::sanitize(
				array(
					'account'    => self::ACCOUNT_ID,
					'typedToken' => ' ',
				)
			)
		);
		$this->assertSame( array( 'firehouse-workers-ai-token' => 'Type the API token.' ), Workers_Ai_Key::errors() );

		// WordPress's salts when wp-config.php's are missing, empty or repeated.
		$this->errors = array();
		$this->salt   = 'kept-in-the-database';
		$this->assertFalse( Workers_Ai_Key::salts_in_config() );
		$this->assertSame(
			array( 'kept' => true ),
			Workers_Ai_Key::sanitize(
				array(
					'account'    => self::ACCOUNT_ID,
					'typedToken' => self::API_TOKEN,
				)
			)
		);
		$this->assertStringContainsString( 'doesn’t set its own AUTH_KEY and AUTH_SALT', Workers_Ai_Key::errors()['firehouse-workers-ai-token'] ?? '' );
	}

	/**
	 * Only an administrator changes the key: anyone else's save keeps it as it is.
	 */
	public function test_only_an_administrator_changes_it(): void {
		Functions\when( 'current_user_can' )->alias( static fn( string $capability ): bool => 'manage_options' !== $capability );

		foreach ( array( array( 'remove' => '1' ), array( 'account' => self::ACCOUNT_ID, 'typedToken' => self::API_TOKEN ) ) as $form ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Two short forms.
			$this->assertSame( array(), Workers_Ai_Key::sanitize( $form ) );
		}
		$this->assertSame( array(), $this->errors );
	}

	/**
	 * "Remove the key" takes it away.
	 */
	public function test_remove_takes_the_key_away(): void {
		$this->save(
			array(
				'account'    => self::ACCOUNT_ID,
				'typedToken' => self::API_TOKEN,
			)
		);

		$this->assertSame(
			array(),
			$this->save(
				array(
					'account'    => self::ACCOUNT_ID,
					'typedToken' => '',
					'remove'     => '1',
				)
			)
		);
		$this->assertFalse( Workers_Ai_Key::saved() );
		$this->assertNull( Workers_Ai::source() );
		$this->assertSame( 'The site has no Workers AI key, so Analyze results is turned off.', Workers_Ai_Key::status() );
	}

	/**
	 * WordPress sanitizes a new option twice: the second time, what the first returned
	 * is kept as it is. A sealed token that doesn't open isn't.
	 */
	public function test_the_second_pass_keeps_what_the_first_returned(): void {
		$first = Workers_Ai_Key::sanitize(
			array(
				'account'    => self::ACCOUNT_ID,
				'typedToken' => self::API_TOKEN,
			)
		);

		$this->assertSame( $first, Workers_Ai_Key::sanitize( $first ) );
		$this->assertSame(
			array(),
			Workers_Ai_Key::sanitize(
				array(
					'account' => self::ACCOUNT_ID,
					'token'   => 'fh1.' . str_repeat( 'ab', 60 ),
				)
			)
		);
	}

	/**
	 * The form shows the account ID and never the token, sealed or not; only an
	 * administrator sees it. Its nonce field has no ID, since the screen's other form has
	 * one.
	 */
	public function test_the_form_never_shows_the_token(): void {
		$this->save(
			array(
				'account'    => self::ACCOUNT_ID,
				'typedToken' => self::API_TOKEN,
			)
		);
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'wp_referer_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->alias(
			static function ( string $text ): void {
				echo '<p class="submit"><input type="submit" value="' . esc_attr( $text ) . '" /></p>';
			}
		);

		ob_start();
		Workers_Ai_Key::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="' . self::ACCOUNT_ID . '"', $html );
		$this->assertStringContainsString( '<input type="password" name="firehouse_workers_ai_key[typedToken]" value=""', $html );
		$this->assertStringContainsString( 'A token is saved.', $html );
		$this->assertStringContainsString( 'Remove the key saved here', $html );
		$this->assertStringContainsString( '<input type="hidden" name="_wpnonce" value="nonce" />', $html );
		$this->assertStringNotContainsString( self::API_TOKEN, $html );
		$this->assertStringNotContainsString( $this->options[ Workers_Ai_Key::OPTION ]['token'], $html );
		$this->assertStringNotContainsString( 'id="_wpnonce"', $html );

		Functions\when( 'current_user_can' )->justReturn( false );
		ob_start();
		Workers_Ai_Key::render();
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * The option is registered on admin_init, for the screen's form, with no REST route.
	 */
	public function test_the_option_is_registered_without_rest(): void {
		Workers_Ai_Key::hook();
		$this->assertNotFalse( has_action( 'admin_init', array( Workers_Ai_Key::class, 'register' ) ) );

		Functions\expect( 'register_setting' )->once()->with( Workers_Ai_Key::OPTION, Workers_Ai_Key::OPTION, \Mockery::on( static fn( array $args ): bool => false === $args['show_in_rest'] && array( Workers_Ai_Key::class, 'sanitize' ) === $args['sanitize_callback'] ) );
		Workers_Ai_Key::register();
	}
}
