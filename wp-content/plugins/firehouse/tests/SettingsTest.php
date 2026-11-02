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

/**
 * The settings read over their defaults, and a bad field keeps what it had.
 */
final class SettingsTest extends WordPressTestCase {

	/**
	 * The errors recorded, as add_settings_error() was given them.
	 *
	 * @var list<array{string, string, string}>
	 */
	private array $errors = array();

	/**
	 * Stubs the option and the functions sanitizing calls.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->errors = array();
		$errors       = &$this->errors;
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $text ): string => trim( strip_tags( $text ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Standing in for sanitize_text_field().
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url ): mixed => parse_url( $url ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Standing in for wp_parse_url().
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'get_settings_errors' )->alias(
			static function () use ( &$errors ): array {
				return array_map( static fn( array $error ): array => array( 'code' => $error[1] ), $errors );
			}
		);
		Functions\when( 'add_settings_error' )->alias(
			static function ( string $setting, string $code, string $message ) use ( &$errors ): void {
				$errors[] = array( $setting, $code, $message );
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
	 * A valid submission is saved as given, with no errors.
	 */
	public function test_a_valid_submission_is_saved(): void {
		$this->assertSame( self::valid(), Settings::sanitize( self::valid() ) );
		$this->assertSame( array(), $this->errors );
	}

	/**
	 * An empty field keeps what it had, and the error names the field and links to it.
	 */
	public function test_an_empty_field_keeps_what_it_had(): void {
		$input                          = self::valid();
		$input['submitLabel']           = '  ';
		$input['navLabels']['projects'] = '';

		$settings = Settings::sanitize( $input );

		$this->assertSame( 'Submit a Finding', $settings['submitLabel'] );
		$this->assertSame( 'Explore Projects', $settings['navLabels']['projects'] );
		$this->assertSame( array( 'firehouse-submitLabel', 'firehouse-navLabels-projects' ), array_column( $this->errors, 1 ) );
		$this->assertStringContainsString( '<a href="#firehouse-submitLabel">Button label</a>', $this->errors[0][2] );
	}

	/**
	 * The survey address has to be an https address with a host.
	 *
	 * @param mixed $url A submitted address.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'bad_addresses' )]
	public function test_a_bad_address_is_refused( mixed $url ): void {
		$input                  = self::valid();
		$input['submitFormUrl'] = $url;

		$this->assertSame( '', Settings::sanitize( $input )['submitFormUrl'] );
		$this->assertSame( array( 'firehouse-submitFormUrl' ), array_column( $this->errors, 1 ) );
	}

	/**
	 * Plain http, no scheme, a script, no host, and something that isn't text.
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

		$this->assertSame( '', Settings::sanitize( $input )['submitFormUrl'] );
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
	 * A field's label points at it, its description is tied to it, and its value is
	 * escaped.
	 */
	public function test_a_field_is_labelled_described_and_escaped(): void {
		Functions\when( 'get_option' )->justReturn( array( 'siteName' => 'The "Firehouse"' ) );

		ob_start();
		Settings::text_field(
			array(
				'path'        => array( 'siteName' ),
				'description' => 'The name.',
				'type'        => 'text',
			)
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="firehouse-siteName"', $html );
		$this->assertStringContainsString( 'aria-describedby="firehouse-siteName-description"', $html );
		$this->assertStringContainsString( 'id="firehouse-siteName-description"', $html );
		$this->assertStringContainsString( 'value="The &quot;Firehouse&quot;"', $html );
		$this->assertStringContainsString( 'required', $html );
	}
}
