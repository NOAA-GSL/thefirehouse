<?php
/**
 * Tests for the Firehouse settings.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Settings;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The settings read over their defaults, and a save with any error changes nothing.
 */
final class SettingsTest extends WordPressTestCase {

	/**
	 * The errors recorded, as add_settings_error() was given them.
	 *
	 * @var list<array{setting: string, code: string, message: string, type: string}>
	 */
	private array $errors = array();

	/**
	 * The transients set, by name.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * Stubs the option and the functions sanitizing calls.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->errors     = array();
		$this->transients = array();
		$errors           = &$this->errors;
		$transients       = &$this->transients;
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $text ): string => trim( strip_tags( $text ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Standing in for sanitize_text_field().
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url ): mixed => parse_url( $url ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Standing in for wp_parse_url().
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_specialchars_decode' )->alias( static fn( string $text ): string => htmlspecialchars_decode( $text, ENT_QUOTES ) );
		Functions\when( 'get_settings_errors' )->alias(
			static function () use ( &$errors ): array {
				return $errors;
			}
		);
		Functions\when( 'add_settings_error' )->alias(
			static function ( string $setting, string $code, string $message ) use ( &$errors ): void {
				$errors[] = array(
					'setting' => $setting,
					'code'    => $code,
					'message' => $message,
					'type'    => 'error',
				);
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( string $name, mixed $value ) use ( &$transients ): bool {
				$transients[ $name ] = $value;
				return true;
			}
		);
	}

	/**
	 * A complete, valid submission.
	 *
	 * @return array<string, mixed>
	 */
	private static function valid(): array {
		return array(
			'siteName'      => 'The Firehouse',
			'submitLabel'   => 'Submit a Finding',
			'submitFormUrl' => 'https://forms.example.org/firehouse',
			'navLabels'     => array(
				'home'     => 'Home',
				'projects' => 'Explore Projects',
				'about'    => 'About',
			),
		);
	}

	/**
	 * Before anything is saved, the settings are the React site's wording, with no
	 * survey address.
	 */
	public function test_unsaved_settings_are_the_defaults(): void {
		$settings = Settings::get();

		$this->assertSame( 'The Firehouse', $settings['siteName'] );
		$this->assertSame( '', $settings['submitFormUrl'] );
		$this->assertSame( array( 'home', 'projects', 'about' ), array_keys( $settings['navLabels'] ) );
	}

	/**
	 * What is stored wins over the defaults, and anything stored that isn't a
	 * setting, or isn't text, is left out.
	 */
	public function test_stored_settings_are_read_over_the_defaults(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'siteName'  => 'Firehouse',
				'footer'    => 'Not a setting',
				'navLabels' => array(
					'about' => 'Who we are',
					'other' => 'Not a link',
					'home'  => array( 'not text' ),
				),
			)
		);

		$settings = Settings::get();

		$this->assertSame( 'Firehouse', $settings['siteName'] );
		$this->assertArrayNotHasKey( 'footer', $settings );
		$this->assertSame(
			array(
				'home'     => 'Home',
				'projects' => 'Explore Projects',
				'about'    => 'Who we are',
			),
			$settings['navLabels']
		);
	}

	/**
	 * A stored survey address the screen would refuse, written some other way, is read
	 * as no address; a good one is read as stored.
	 */
	public function test_a_stored_address_the_screen_would_refuse_is_dropped(): void {
		foreach ( array( 'javascript:alert(1)', 'http://forms.example.org/a', 'https://', '/firehouse/submit/', array( 'https://forms.example.org/a' ), 42 ) as $url ) {
			Functions\when( 'get_option' )->justReturn( array( 'submitFormUrl' => $url ) );
			$this->assertSame( '', Settings::get()['submitFormUrl'], var_export( $url, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- the failing value, in the message.
		}

		Functions\when( 'get_option' )->justReturn( array( 'submitFormUrl' => 'https://forms.example.org/a' ) );
		$this->assertSame( 'https://forms.example.org/a', Settings::get()['submitFormUrl'] );
	}

	/**
	 * A valid submission is saved as given, with no errors.
	 */
	public function test_a_valid_submission_is_saved(): void {
		$this->assertSame( self::valid(), Settings::sanitize( self::valid() ) );
		$this->assertSame( array(), $this->errors );
		$this->assertSame( array(), $this->transients );
	}

	/**
	 * A save with any error changes nothing, not even its valid fields. Each error is
	 * recorded against its field, and what was typed is kept to show again.
	 */
	public function test_a_save_with_an_error_changes_nothing(): void {
		$input                          = self::valid();
		$input['siteName']              = 'A new name';
		$input['submitLabel']           = '  ';
		$input['navLabels']['projects'] = '';

		$this->assertSame( Settings::defaults(), Settings::sanitize( $input ) );
		$this->assertSame( array( 'firehouse-submitLabel', 'firehouse-navLabels-projects' ), array_column( $this->errors, 'code' ) );
		$this->assertSame( 'Button label can’t be empty.', $this->errors[0]['message'] );
		$typed = $this->transients['firehouse_settings_rejected_7'];
		$this->assertSame( 'A new name', $typed['firehouse-siteName'] );
		$this->assertSame( '  ', $typed['firehouse-submitLabel'] );
	}

	/**
	 * The checks alone, for the Import screen: the settings with each valid field
	 * changed, and each error by field, without recording or saving anything.
	 */
	public function test_validate_checks_without_recording(): void {
		$input                  = self::valid();
		$input['submitFormUrl'] = 'http://forms.example.org/firehouse';

		list( $settings, $errors ) = Settings::validate( $input );

		$this->assertSame( 'The Firehouse', $settings['siteName'] );
		$this->assertSame( '', $settings['submitFormUrl'] );
		$this->assertSame( array( 'firehouse-submitFormUrl' ), array_keys( $errors ) );
		$this->assertSame( array(), $this->errors );
		$this->assertSame( array(), $this->transients );
	}

	/**
	 * The survey address has to be an https address with a host.
	 *
	 * @param mixed $url A submitted address.
	 */
	#[DataProvider( 'bad_addresses' )]
	public function test_a_bad_address_is_refused( mixed $url ): void {
		$input                  = self::valid();
		$input['submitFormUrl'] = $url;

		list( , $errors ) = Settings::validate( $input );

		$this->assertSame( array( 'firehouse-submitFormUrl' ), array_keys( $errors ) );
	}

	/**
	 * Plain http, no scheme, a script, no host, something that isn't text, and nothing.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function bad_addresses(): array {
		return array(
			'plain http' => array( 'http://forms.example.org/firehouse' ),
			'no scheme'  => array( 'forms.example.org/firehouse' ),
			'a script'   => array( 'javascript:alert(1)' ),
			'no host'    => array( 'https:///firehouse' ),
			'not text'   => array( array( 'https://forms.example.org' ) ),
			'empty'      => array( '' ),
		);
	}

	/**
	 * An address WordPress would have to rewrite is refused, not quietly changed.
	 */
	public function test_an_address_wordpress_would_rewrite_is_refused(): void {
		Functions\when( 'esc_url_raw' )->justReturn( 'https://forms.example.org/a%20form' );
		$input                  = self::valid();
		$input['submitFormUrl'] = 'https://forms.example.org/a form';

		list( , $errors ) = Settings::validate( $input );

		$this->assertArrayHasKey( 'firehouse-submitFormUrl', $errors );
	}

	/**
	 * WordPress sanitizes a new option twice; each error is still recorded once.
	 */
	public function test_each_error_is_recorded_once(): void {
		$input                = self::valid();
		$input['submitLabel'] = '';

		Settings::sanitize( $input );
		Settings::sanitize( $input );

		$this->assertCount( 1, $this->errors );
	}

	/**
	 * Something that isn't a set of settings changes nothing.
	 */
	public function test_a_submission_that_isnt_settings_changes_nothing(): void {
		$this->assertSame( Settings::defaults(), Settings::sanitize( 'not settings' ) );
	}

	/**
	 * The settings are saved with Firehouse's own capability, not manage_options.
	 */
	public function test_saving_takes_the_firehouse_capability(): void {
		$this->assertSame( 'firehouse_manage_settings', Settings::capability() );
	}

	/**
	 * After a refused save, the browser's title says so.
	 */
	public function test_a_refused_save_is_in_the_title(): void {
		$this->assertSame( 'Firehouse settings', Settings::title( 'Firehouse settings' ) );

		Settings::sanitize( array( 'submitLabel' => '' ) + self::valid() );

		$this->assertSame( 'Not saved: Firehouse settings', Settings::title( 'Firehouse settings' ) );
	}

	/**
	 * A field's label points at it, its description is tied to it, and its value is
	 * escaped.
	 */
	public function test_a_field_is_labelled_described_and_escaped(): void {
		Functions\when( 'get_option' )->justReturn( array( 'siteName' => 'The "Firehouse"' ) );

		$html = self::field( array( 'siteName' ) );

		$this->assertStringContainsString( 'id="firehouse-siteName"', $html );
		$this->assertStringContainsString( 'aria-describedby="firehouse-siteName-description"', $html );
		$this->assertStringContainsString( 'id="firehouse-siteName-description"', $html );
		$this->assertStringContainsString( 'value="The &quot;Firehouse&quot;"', $html );
		$this->assertStringContainsString( 'required', $html );
		$this->assertStringNotContainsString( 'aria-invalid', $html );
	}

	/**
	 * A field in error is marked invalid, its error is tied to it and says "Error:" in
	 * words, and it takes the focus.
	 */
	public function test_a_field_in_error_says_so(): void {
		Settings::sanitize( array( 'submitLabel' => '' ) + self::valid() );

		$html = self::field( array( 'submitLabel' ) );

		$this->assertStringContainsString( 'aria-invalid="true"', $html );
		$this->assertStringContainsString( 'aria-describedby="firehouse-submitLabel-error firehouse-submitLabel-description"', $html );
		$this->assertStringContainsString( '<p class="description" id="firehouse-submitLabel-error"><strong>Error:</strong> Button label can’t be empty.</p>', $html );
		$this->assertStringContainsString( 'autofocus', $html );
	}

	/**
	 * Draws one field.
	 *
	 * @param string[] $path The field's path.
	 */
	private static function field( array $path ): string {
		ob_start();
		Settings::text_field(
			array(
				'path'        => $path,
				'description' => 'What it is for.',
				'type'        => 'text',
			)
		);
		return (string) ob_get_clean();
	}
}
