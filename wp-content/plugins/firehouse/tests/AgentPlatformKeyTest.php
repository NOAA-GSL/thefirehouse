<?php
/**
 * Tests for the Google API key saved on FireHouse → Settings.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Site_Health;
use Firehouse\Agent_Platform;
use Firehouse\Agent_Platform_Key;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Only an administrator saves the key; its API key is sealed under the site's salts, never
 * shown again, and opens only with them; a save with anything wrong changes nothing; and
 * Agent_Platform uses it before wp-config.php's constants. Each test runs in its own process,
 * since the salts and the constants are constants. Sealing needs PHP's sodium extension
 * here, where WordPress's own copy of libsodium isn't loaded; CI checks it's there.
 */
#[RequiresFunction( 'sodium_crypto_secretbox' )]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class AgentPlatformKeyTest extends WordPressTestCase {

	/**
	 * A project ID shaped like Google's.
	 */
	private const PROJECT_ID = 'firehouse-test-123';

	/**
	 * Another.
	 */
	private const OTHER_PROJECT = 'other-project-456';

	/**
	 * An API key shaped like Google's.
	 */
	private const API_KEY = 'AIzaSy-saved_key_0123456789ABCDEFGHIJKLM';

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
		$saved                                       = Agent_Platform_Key::sanitize( $form );
		$this->options[ Agent_Platform_Key::OPTION ] = $saved;
		return $saved;
	}

	/**
	 * A key typed in is saved with its API key sealed, and opens again for Agent_Platform, which
	 * then has a key from Settings.
	 */
	public function test_a_saved_key_is_sealed_and_opens_again(): void {
		$saved = $this->save(
			array(
				'project'  => ' ' . self::PROJECT_ID . ' ',
				'typedKey' => self::API_KEY . "\n",
			)
		);

		$this->assertSame( array(), $this->errors );
		$this->assertSame( self::PROJECT_ID, $saved['project'] );
		$this->assertStringStartsWith( 'fh1.', $saved['key'] );
		$this->assertStringNotContainsString( self::API_KEY, (string) json_encode( $saved ), 'Never in the clear.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- A test's look at what's stored.
		$this->assertNotSame( $saved['key'], Agent_Platform_Key::sanitize( array( 'typedKey' => self::API_KEY ) + $saved )['key'], 'A new nonce each time.' );
		$this->assertSame(
			array(
				'project' => self::PROJECT_ID,
				'key'     => self::API_KEY,
			),
			Agent_Platform_Key::credentials()
		);
		$this->assertTrue( Agent_Platform_Key::saved() );
		$this->assertFalse( Agent_Platform_Key::unreadable() );
		$this->assertTrue( Agent_Platform::configured() );
		$this->assertSame( Agent_Platform::FROM_SETTINGS, Agent_Platform::source() );
		$this->assertSame( 'Analyze results uses the key saved here.', Agent_Platform_Key::status() );
	}

	/**
	 * Under other salts, as on another site or after they're changed, the API key doesn't
	 * open: Analyze results is off, and the screen and Site Health say to type it again.
	 */
	public function test_other_salts_dont_open_it(): void {
		$this->save(
			array(
				'project'  => self::PROJECT_ID,
				'typedKey' => self::API_KEY,
			)
		);
		$this->salt = 'another-site';

		$this->assertNull( Agent_Platform_Key::credentials() );
		$this->assertTrue( Agent_Platform_Key::unreadable() );
		$this->assertFalse( Agent_Platform::configured() );
		$this->assertStringContainsString( 'Analyze results is turned off', Agent_Platform_Key::status() );
		$health = Site_Health::ai_key();
		$this->assertSame( 'recommended', $health['status'] );
		$this->assertSame( 'FireHouse’s saved Google API key can’t be read', $health['label'] );

		// Tampered with, it doesn't open either: its last hex digit changed, always to
		// another.
		$this->salt = 'wp-config-key|wp-config-salt';
		$this->assertNotNull( Agent_Platform_Key::credentials(), 'It opens again under its own salts.' );
		$sealed = $this->options[ Agent_Platform_Key::OPTION ]['key'];
		$this->options[ Agent_Platform_Key::OPTION ]['key'] = substr( $sealed, 0, -1 ) . ( '0' === substr( $sealed, -1 ) ? '1' : '0' );
		$this->assertNotSame( $sealed, $this->options[ Agent_Platform_Key::OPTION ]['key'] );
		$this->assertNull( Agent_Platform_Key::credentials() );
		$this->options[ Agent_Platform_Key::OPTION ]['key'] = 'fh1.zz';
		$this->assertNull( Agent_Platform_Key::credentials() );
	}

	/**
	 * The key saved here comes before wp-config.php's: Google is asked with it. When
	 * it doesn't open, wp-config.php's is used.
	 */
	public function test_the_saved_key_comes_before_wp_configs(): void {
		define( Agent_Platform::PROJECT, self::OTHER_PROJECT ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- The plugin's own constant, as wp-config.php sets it.
		define( Agent_Platform::API_KEY, 'AIzaSy-wp-config_key_0123456789ABCDEFGH' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- The plugin's own constant, as wp-config.php sets it.
		$this->assertSame( Agent_Platform::FROM_CONFIG, Agent_Platform::source() );
		$this->assertSame( 'Analyze results uses the key in wp-config.php. A key saved here would be used in its place.', Agent_Platform_Key::status() );

		$this->save(
			array(
				'project'  => self::PROJECT_ID,
				'typedKey' => self::API_KEY,
			)
		);
		$asked = array();
		Functions\when( 'wp_json_encode' )->alias( static fn( mixed $data ): string => (string) json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standing in for wp_json_encode().
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"candidates":[{"content":{"parts":[{"text":"A reply"}]},"finishReason":"STOP"}]}' );
		Functions\when( 'wp_safe_remote_post' )->alias(
			static function ( string $url, array $args ) use ( &$asked ): array {
				$asked[] = array( $url, $args['headers']['x-goog-api-key'] );
				return array();
			}
		);

		$this->assertSame( Agent_Platform::FROM_SETTINGS, Agent_Platform::source() );
		$this->assertSame( 'A reply', Agent_Platform::ask( Agent_Platform::DEFAULT_MODEL, array() )['text'] ?? null );
		$this->assertStringContainsString( '/projects/' . self::PROJECT_ID . '/', $asked[0][0] );
		$this->assertSame( self::API_KEY, $asked[0][1] );

		$this->salt = 'changed';
		$this->assertSame( Agent_Platform::FROM_CONFIG, Agent_Platform::source() );
		$this->assertStringContainsString( 'Analyze results uses the key in wp-config.php', Agent_Platform_Key::status() );
	}

	/**
	 * An empty API key keeps the one saved, so the project can change alone; once the salts
	 * have changed, the API key has to be typed again.
	 */
	public function test_an_empty_key_keeps_the_saved_one(): void {
		$first  = $this->save(
			array(
				'project'  => self::PROJECT_ID,
				'typedKey' => self::API_KEY,
			)
		);
		$second = $this->save(
			array(
				'project'  => self::OTHER_PROJECT,
				'typedKey' => '',
			)
		);

		$this->assertSame( self::OTHER_PROJECT, $second['project'] );
		$this->assertSame( $first['key'], $second['key'] );

		$this->salt = 'changed';
		$this->assertSame(
			$second,
			$this->save(
				array(
					'project'  => self::PROJECT_ID,
					'typedKey' => '',
				)
			)
		);
		$this->assertSame( array( 'firehouse-google-ai-key' => 'The saved API key can’t be read any more, because the site’s salts have changed. Type it again.' ), Agent_Platform_Key::errors() );
	}

	/**
	 * A save with anything wrong changes nothing and says what: a project ID or an API key
	 * not shaped like Google's, no API key at all, or salts WordPress keeps in the
	 * database rather than taking from wp-config.php.
	 */
	public function test_a_save_with_a_problem_changes_nothing(): void {
		$this->options[ Agent_Platform_Key::OPTION ] = array( 'kept' => true );
		$cases                                       = array(
			'firehouse-google-ai-project' => array(
				'project'  => 'ABC',
				'typedKey' => self::API_KEY,
			),
			'firehouse-google-ai-key'     => array(
				'project'  => self::PROJECT_ID,
				'typedKey' => "a-token\r\nX-Other: 1 and more",
			),
		);
		foreach ( $cases as $field => $form ) {
			$this->errors = array();
			$this->assertSame( array( 'kept' => true ), Agent_Platform_Key::sanitize( $form ), $field );
			$this->assertSame( array( $field ), array_keys( Agent_Platform_Key::errors() ), $field );
		}

		$this->errors = array();
		$this->assertSame(
			array( 'kept' => true ),
			Agent_Platform_Key::sanitize(
				array(
					'project'  => self::PROJECT_ID,
					'typedKey' => ' ',
				)
			)
		);
		$this->assertSame( array( 'firehouse-google-ai-key' => 'Type the API key.' ), Agent_Platform_Key::errors() );

		// WordPress's salts when wp-config.php's are missing, empty or repeated.
		$this->errors = array();
		$this->salt   = 'kept-in-the-database';
		$this->assertFalse( Agent_Platform_Key::salts_in_config() );
		$this->assertSame(
			array( 'kept' => true ),
			Agent_Platform_Key::sanitize(
				array(
					'project'  => self::PROJECT_ID,
					'typedKey' => self::API_KEY,
				)
			)
		);
		$this->assertStringContainsString( 'doesn’t set its own AUTH_KEY and AUTH_SALT', Agent_Platform_Key::errors()['firehouse-google-ai-key'] ?? '' );
	}

	/**
	 * Only an administrator changes the key: anyone else's save keeps it as it is.
	 */
	public function test_only_an_administrator_changes_it(): void {
		Functions\when( 'current_user_can' )->alias( static fn( string $capability ): bool => 'manage_options' !== $capability );

		foreach ( array( array( 'remove' => '1' ), array( 'project' => self::PROJECT_ID, 'typedKey' => self::API_KEY ) ) as $form ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Two short forms.
			$this->assertSame( array(), Agent_Platform_Key::sanitize( $form ) );
		}
		$this->assertSame( array(), $this->errors );
	}

	/**
	 * "Remove the key" takes it away.
	 */
	public function test_remove_takes_the_key_away(): void {
		$this->save(
			array(
				'project'  => self::PROJECT_ID,
				'typedKey' => self::API_KEY,
			)
		);

		$this->assertSame(
			array(),
			$this->save(
				array(
					'project'  => self::PROJECT_ID,
					'typedKey' => '',
					'remove'   => '1',
				)
			)
		);
		$this->assertFalse( Agent_Platform_Key::saved() );
		$this->assertNull( Agent_Platform::source() );
		$this->assertSame( 'The site has no Google API key, so Analyze results is turned off.', Agent_Platform_Key::status() );
	}

	/**
	 * WordPress sanitizes a new option twice: the second time, what the first returned
	 * is kept as it is. A sealed API key that doesn't open isn't.
	 */
	public function test_the_second_pass_keeps_what_the_first_returned(): void {
		$first = Agent_Platform_Key::sanitize(
			array(
				'project'  => self::PROJECT_ID,
				'typedKey' => self::API_KEY,
			)
		);

		$this->assertSame( $first, Agent_Platform_Key::sanitize( $first ) );
		$this->assertSame(
			array(),
			Agent_Platform_Key::sanitize(
				array(
					'project' => self::PROJECT_ID,
					'key'     => 'fh1.' . str_repeat( 'ab', 60 ),
				)
			)
		);
	}

	/**
	 * The form shows the project ID and never the API key, sealed or not; only an
	 * administrator sees it. Its nonce field has no ID, since the screen's other form has
	 * one.
	 */
	public function test_the_form_never_shows_the_key(): void {
		$this->save(
			array(
				'project'  => self::PROJECT_ID,
				'typedKey' => self::API_KEY,
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
		Agent_Platform_Key::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="' . self::PROJECT_ID . '"', $html );
		$this->assertStringContainsString( '<input type="password" name="firehouse_google_ai_key[typedKey]" value=""', $html );
		$this->assertStringContainsString( 'An API key is saved.', $html );
		$this->assertStringContainsString( 'Remove the key saved here', $html );
		$this->assertStringContainsString( '<input type="hidden" name="_wpnonce" value="nonce" />', $html );
		$this->assertStringNotContainsString( self::API_KEY, $html );
		$this->assertStringNotContainsString( $this->options[ Agent_Platform_Key::OPTION ]['key'], $html );
		$this->assertStringNotContainsString( 'id="_wpnonce"', $html );

		// Each ID once, so each label names its own box and a refused save's link lands on it.
		preg_match_all( '/\sid="([^"]+)"/', $html, $ids );
		$this->assertSame( array_unique( $ids[1] ), $ids[1], 'No ID twice.' );
		foreach ( array( 'firehouse-google-ai-project', 'firehouse-google-ai-key' ) as $id ) {
			$this->assertMatchesRegularExpression( '/<label for="' . $id . '">[^<]+<\/label><\/th><td><input [^>]*id="' . $id . '"/', $html, $id );
		}
		$this->assertStringContainsString( '<div id="firehouse-google-ai">', $html );

		Functions\when( 'current_user_can' )->justReturn( false );
		ob_start();
		Agent_Platform_Key::render();
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * The option is registered on admin_init, for the screen's form, with no REST route.
	 */
	public function test_the_option_is_registered_without_rest(): void {
		Agent_Platform_Key::hook();
		$this->assertNotFalse( has_action( 'admin_init', array( Agent_Platform_Key::class, 'register' ) ) );

		Functions\expect( 'register_setting' )->once()->with( Agent_Platform_Key::OPTION, Agent_Platform_Key::OPTION, \Mockery::on( static fn( array $args ): bool => false === $args['show_in_rest'] && array( Agent_Platform_Key::class, 'sanitize' ) === $args['sanitize_callback'] ) );
		Agent_Platform_Key::register();
	}
}
