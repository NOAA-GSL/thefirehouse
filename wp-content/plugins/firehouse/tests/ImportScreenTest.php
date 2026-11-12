<?php
/**
 * Tests for the Import screen's confirmation step.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Import_Screen;
use Firehouse\Importer;

/**
 * A confirmation imports only the dry run that was shown.
 */
final class ImportScreenTest extends WordPressTestCase {

	/**
	 * What was kept between steps, by transient name.
	 *
	 * @var array<string, mixed>
	 */
	private array $kept = array();

	/**
	 * Stubs what a confirmation calls, with a dry run waiting whose fingerprint no
	 * longer matches what WordPress would do.
	 */
	protected function setUp(): void {
		parent::setUp();
		self::stand_in_serialize_blocks();
		$this->kept = array();
		$kept       = &$this->kept;
		$content    = array_intersect_key( Cases::site(), array_flip( array( 'projects', 'topics', 'topicSummaries', 'settings', 'landing', 'about' ) ) );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_add_inline_style' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $text ): string => trim( $text ) );
		Functions\when( 'get_current_user_id' )->justReturn( 6 );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'wp_unique_post_slug' )->returnArg();
		Functions\when( 'rest_validate_value_from_schema' )->justReturn( true );
		Functions\when( 'sanitize_title' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url ): mixed => parse_url( $url ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Standing in for wp_parse_url().
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_generate_password' )->justReturn( 'a-new-token' );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn(
			array(
				'token'       => 'the-token',
				'fingerprint' => 'what WordPress would have done then',
				'file'        => 'firehouse-seed.json',
				'bundle'      => array(
					'content'  => $content,
					'notes'    => array(),
					'problems' => array(),
				),
			)
		);
		Functions\when( 'set_transient' )->alias(
			static function ( string $name, mixed $value ) use ( &$kept ): bool {
				$kept[ $name ] = $value;
				return true;
			}
		);
	}

	/**
	 * Clears the form.
	 */
	protected function tearDown(): void {
		unset( $_POST['firehouse_step'], $_POST['firehouse_token'] );
		parent::tearDown();
	}

	/**
	 * If WordPress has changed since the dry run, the confirmation writes nothing and
	 * shows the dry run as things stand, waiting with a new token.
	 */
	public function test_a_confirmation_after_wordpress_changed_shows_the_new_dry_run(): void {
		Functions\expect( 'wp_insert_post' )->never();
		Functions\expect( 'update_option' )->never();
		$_POST['firehouse_step']  = 'import';
		$_POST['firehouse_token'] = 'the-token';

		Import_Screen::handle();

		$this->assertSame( 'Not imported: Import', Import_Screen::title( 'Import' ) );
		$this->assertSame( 'a-new-token', $this->kept['firehouse_import_6']['token'] );
		$this->assertNotSame( 'what WordPress would have done then', $this->kept['firehouse_import_6']['fingerprint'] );
	}

	/**
	 * A confirmation that finds the dry run already claimed, as the second of a double
	 * click does, imports nothing: only the request whose delete removes it imports. It
	 * says the dry run was already confirmed, not that the import failed.
	 */
	public function test_a_second_confirmation_imports_nothing(): void {
		$waiting                = get_transient( 'firehouse_import_6' );
		$waiting['fingerprint'] = Importer::fingerprint( Importer::preview( $waiting['bundle']['content'] ) );
		Functions\when( 'get_transient' )->justReturn( $waiting );
		Functions\when( 'delete_transient' )->justReturn( false );
		Functions\expect( 'wp_insert_post' )->never();
		Functions\expect( 'update_option' )->never();
		$_POST['firehouse_step']  = 'import';
		$_POST['firehouse_token'] = 'the-token';

		Import_Screen::handle();

		$this->assertSame( 'Already confirmed: Import', Import_Screen::title( 'Import' ) );
	}

	/**
	 * A dry run keeps every part of the bundle the import writes, the landing and About
	 * pages' words included, and nothing else; of the settings, only those it sets.
	 */
	public function test_a_dry_run_keeps_what_the_import_writes(): void {
		$content                       = Cases::site();
		$content['notForWordPress']    = array( 'x' );
		$content['settings']['footer'] = 'not kept';

		$kept = Import_Screen::kept( $content );

		$this->assertEqualsCanonicalizing( array( 'projects', 'topics', 'topicSummaries', 'settings', 'landing', 'about' ), array_keys( $kept ) );
		$this->assertEqualsCanonicalizing( array( 'siteName', 'submitLabel', 'submitFormUrl', 'nav' ), array_keys( $kept['settings'] ) );
		$this->assertSame( $content['about'], $kept['about'] );
	}

	/**
	 * The dry run's report has a section for the About page, and its problems are in
	 * the summary at the top, each a link to that section.
	 */
	public function test_the_report_shows_the_about_page(): void {
		$waiting = get_transient( 'firehouse_import_6' );
		$waiting['bundle']['content']['about']['mission']['heading'] = '';
		Functions\when( 'get_transient' )->justReturn( $waiting );
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'get_admin_page_title' )->justReturn( 'Import Firehouse content' );
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://example.test/wp-admin/' . $path );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( '' );
		$_POST['firehouse_step']  = 'import';
		$_POST['firehouse_token'] = 'the-token';

		Import_Screen::handle();
		ob_start();
		Import_Screen::render();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '#<a href="\#firehouse-import-about">About page: The bundle’s about content has no about\.mission\.heading\.#', $html );
		$this->assertStringContainsString( '<h2 id="firehouse-import-about" tabindex="-1">About page</h2><p>Not created: see the problems below.</p>', $html );
	}

	/**
	 * A token that isn't the waiting dry run's imports nothing.
	 */
	public function test_a_wrong_token_imports_nothing(): void {
		Functions\expect( 'wp_insert_post' )->never();
		$_POST['firehouse_step']  = 'import';
		$_POST['firehouse_token'] = 'another-token';

		Import_Screen::handle();

		$this->assertSame( 'Not imported: Import', Import_Screen::title( 'Import' ) );
		$this->assertSame( array(), $this->kept );
	}
}
