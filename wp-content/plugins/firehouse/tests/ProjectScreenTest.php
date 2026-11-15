<?php
/**
 * Tests for the project edit screen.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Project_Screen;

/**
 * The screen saves the team's fields exactly as typed, refuses all of them when one is
 * wrong, keeps the survey's answers out of reach, and shows each as stored.
 */
final class ProjectScreenTest extends WordPressTestCase {

	/**
	 * The project's meta, by key.
	 *
	 * @var array<string, mixed>
	 */
	private array $stored = array();

	/**
	 * Stubs what the screen reads and writes, with a project stored.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->stored = array(
			'fh_id'             => 'R_test',
			'fh_title'          => 'Smoke & <b>fire</b> study',
			'fh_summary'        => 'R&amp;D summary',
			'fh_authors'        => array(
				array(
					'name' => 'Ana <Ruiz> & co',
					'org'  => 'CIRA',
				),
			),
			'fh_completionYear' => '2024',
			'fh_firePhases'     => array( 'during', 'made-up' ),
			'fh_methods'        => array( 'Focus groups' ),
			'fh_irbApproved'    => '1',
			'fh_geo'            => array( 'regions' => array( 'GBCC' ) ),
			'fh_takeaways'      => array( "First line\nsecond line" ),
			'fh_needs'          => array(
				array(
					'need'           => 'A need',
					'recommendation' => 'A recommendation',
				),
			),
			'fh_papers'         => array(
				array(
					'title' => 'A paper',
					'year'  => 2024,
					'url'   => 'javascript:alert(1)',
				),
			),
		);
		Functions\when( 'metadata_exists' )->alias( fn( string $type, int $id, string $key ): bool => array_key_exists( $key, $this->stored ) );
		Functions\when( 'get_post_meta' )->alias( fn( int $id, string $key ): mixed => $this->stored[ $key ] ?? '' );
		Functions\when( 'rest_validate_value_from_schema' )->justReturn( true );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $part = -1 ): mixed => parse_url( $url, $part ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Standing in for wp_parse_url().
		// As WordPress's: a protocol it doesn't allow is refused, and an address with none
		// that isn't relative gets http://.
		Functions\when( 'esc_url_raw' )->alias(
			static fn( string $url ): string => match ( true ) {
				1 === preg_match( '#^(javascript|data|vbscript):#i', $url ) => '',
				1 === preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ), 1 === preg_match( '#^[/?\#]#', $url ) => $url,
				default => 'http://' . $url,
			}
		);
		Functions\when( 'selected' )->alias( static fn( string $a, string $b ): string => $a === $b ? ' selected=\'selected\'' : '' );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'get_current_user_id' )->justReturn( 6 );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( mixed $value ): int => abs( (int) $value ) );
		$_POST = array();
		$_GET  = array();
		Project_Screen::load();
	}

	/**
	 * Clears the request.
	 */
	protected function tearDown(): void {
		$_POST = array();
		$_GET  = array();
		parent::tearDown();
	}

	/**
	 * The project post.
	 */
	private static function post(): \WP_Post {
		return new \WP_Post(
			array(
				'ID'          => 41,
				'post_type'   => 'fh_project',
				'post_name'   => 'smoke-study',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * The project's fields, as Store reads them back.
	 *
	 * @return array<string, mixed>
	 */
	private static function fields(): array {
		return array(
			'summary' => 'Old',
			'geo'     => array(
				'regions' => array( 'GBCC' ),
				'note'    => 'Old note',
			),
		);
	}

	/**
	 * Valid input is saved as typed, but for a browser's line breaks and an address's spaces.
	 */
	public function test_valid_input_is_kept_as_typed(): void {
		$checked = Project_Screen::check(
			self::fields(),
			array(
				'summary'           => "50% of R&amp;D <b>done</b>,\r\nsecond line",
				'publicationStatus' => 'in-review',
				'geoNote'           => 'Front Range & foothills',
				'fullRecordUrl'     => '  https://example.org/record?a=1&b=2 ',
			)
		);

		$this->assertSame( array(), $checked['errors'] );
		$this->assertSame( array(), $checked['delete'] );
		$this->assertSame(
			array(
				'summary'           => "50% of R&amp;D <b>done</b>,\nsecond line",
				'publicationStatus' => 'in-review',
				'fullRecordUrl'     => 'https://example.org/record?a=1&b=2',
				'geo'               => array(
					'regions' => array( 'GBCC' ),
					'note'    => 'Front Range & foothills',
				),
			),
			$checked['set']
		);
	}

	/**
	 * An empty status, note or address is removed, and the survey's regions stay.
	 */
	public function test_empty_fields_are_removed_and_the_regions_kept(): void {
		$checked = Project_Screen::check(
			self::fields(),
			array(
				'summary'           => '',
				'publicationStatus' => '',
				'geoNote'           => '',
				'fullRecordUrl'     => '   ',
			)
		);

		$this->assertSame( array(), $checked['errors'] );
		$this->assertSame( array( 'publicationStatus', 'fullRecordUrl' ), $checked['delete'] );
		$this->assertSame(
			array(
				'summary' => '',
				'geo'     => array( 'regions' => array( 'GBCC' ) ),
			),
			$checked['set']
		);
	}

	/**
	 * A note on a project with no geography starts one with no regions; no note adds none.
	 */
	public function test_a_note_without_a_geography_starts_one_without_regions(): void {
		$without = Project_Screen::check( array( 'summary' => 'Old' ), array( 'geoNote' => 'Front Range' ) );
		$this->assertSame(
			array(
				'regions' => array(),
				'note'    => 'Front Range',
			),
			$without['set']['geo']
		);

		$none = Project_Screen::check( array( 'summary' => 'Old' ), array( 'geoNote' => '' ) );
		$this->assertArrayNotHasKey( 'geo', $none['set'] );
	}

	/**
	 * Input that isn't text counts as empty.
	 */
	public function test_input_that_is_not_text_counts_as_empty(): void {
		$checked = Project_Screen::check( self::fields(), array( 'summary' => array( 'x' ) ) );
		$this->assertSame( '', $checked['set']['summary'] );
		$this->assertSame( '', $checked['values']['summary'] );
	}

	/**
	 * Wrong input is refused for its field, and kept to show again.
	 *
	 * @dataProvider bad_input
	 *
	 * @param array<string, string> $input The form's input.
	 * @param string                $field The field refused.
	 */
	public function test_a_wrong_field_is_refused( array $input, string $field ): void {
		$checked = Project_Screen::check( self::fields(), $input + array( 'summary' => 'S' ) );
		$this->assertSame( array( $field ), array_keys( $checked['errors'] ) );
		$this->assertSame( $input[ $field ], $checked['values'][ $field ] );
	}

	/**
	 * Wrong input, and the field it's refused for.
	 *
	 * @return array<string, array{0: array<string, string>, 1: string}>
	 */
	public static function bad_input(): array {
		return array(
			'unknown status' => array( array( 'publicationStatus' => 'printed' ), 'publicationStatus' ),
			'script address' => array( array( 'fullRecordUrl' => 'javascript:alert(1)' ), 'fullRecordUrl' ),
			'ftp address'    => array( array( 'fullRecordUrl' => 'ftp://example.org/x' ), 'fullRecordUrl' ),
			'no scheme'      => array( array( 'fullRecordUrl' => 'www.example.org' ), 'fullRecordUrl' ),
			'scheme alone'   => array( array( 'fullRecordUrl' => 'https://' ), 'fullRecordUrl' ),
			'relative'       => array( array( 'fullRecordUrl' => '/records/1' ), 'fullRecordUrl' ),
			'no host'        => array( array( 'fullRecordUrl' => 'http:/example.org' ), 'fullRecordUrl' ),
		);
	}

	/**
	 * A value its field's schema refuses is refused with the schema's message.
	 */
	public function test_a_field_its_schema_refuses_is_refused(): void {
		Functions\when( 'rest_validate_value_from_schema' )->alias( static fn( mixed $value, array $schema, string $name ): mixed => 'summary' === $name ? new \WP_Error( 'x', 'summary is too long.' ) : true );
		$checked = Project_Screen::check( self::fields(), array( 'summary' => 'S' ) );
		$this->assertSame( array( 'summary' => 'summary is too long.' ), $checked['errors'] );
	}

	/**
	 * A save from anywhere but the screen's form, such as Quick Edit, writes nothing.
	 */
	public function test_a_save_without_the_screens_form_writes_nothing(): void {
		Functions\expect( 'update_post_meta' )->never();
		Functions\expect( 'delete_post_meta' )->never();
		Functions\expect( 'set_transient' )->never();
		$_POST = array( 'firehouse' => array( 'summary' => 'Quick Edit' ) );
		Project_Screen::save( 41, self::post() );
	}

	/**
	 * A save whose nonce doesn't check writes nothing.
	 */
	public function test_a_save_with_a_wrong_nonce_writes_nothing(): void {
		Functions\expect( 'wp_verify_nonce' )->once()->with( 'forged', 'firehouse_project_fields' )->andReturn( false );
		Functions\expect( 'update_post_meta' )->never();
		$_POST = array(
			'firehouse_project_nonce' => 'forged',
			'firehouse'               => array( 'summary' => 'Forged' ),
		);
		Project_Screen::save( 41, self::post() );
	}

	/**
	 * A save by someone who may not edit the project writes nothing.
	 */
	public function test_a_save_by_someone_who_may_not_edit_writes_nothing(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\expect( 'current_user_can' )->once()->with( 'edit_post', 41 )->andReturn( false );
		Functions\expect( 'update_post_meta' )->never();
		$_POST = array(
			'firehouse_project_nonce' => 'good',
			'firehouse'               => array( 'summary' => 'Not theirs' ),
		);
		Project_Screen::save( 41, self::post() );
	}

	/**
	 * A refused save writes no field, and keeps the input and problems for the next screen.
	 */
	public function test_a_refused_save_writes_nothing_and_keeps_the_input(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( 'update_post_meta' )->never();
		Functions\expect( 'delete_post_meta' )->never();
		$kept = null;
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, mixed $value, int $expires ) use ( &$kept ): bool {
				$kept = array( $key, $value, $expires );
				return true;
			}
		);
		$_POST = array(
			'firehouse_project_nonce' => 'good',
			'firehouse'               => array(
				'summary'       => 'A good summary',
				'fullRecordUrl' => 'javascript:alert(1)',
			),
		);
		Project_Screen::save( 41, self::post() );

		$this->assertSame( 'firehouse_project_41_6', $kept[0] );
		$this->assertSame( 'A good summary', $kept[1]['values']['summary'] );
		$this->assertSame( 'javascript:alert(1)', $kept[1]['values']['fullRecordUrl'] );
		$this->assertSame( array( 'fullRecordUrl' ), array_keys( $kept[1]['errors'] ) );
	}

	/**
	 * A good save writes each field slashed, so WordPress stores it as typed, and removes the empty ones.
	 */
	public function test_a_good_save_writes_each_field_slashed(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_slash' )->alias( static fn( mixed $value ): mixed => is_string( $value ) ? addslashes( $value ) : $value );
		$written = array();
		Functions\when( 'update_post_meta' )->alias(
			static function ( int $id, string $key, mixed $value ) use ( &$written ): bool {
				$written[ $key ] = $value;
				return true;
			}
		);
		$deleted = array();
		Functions\when( 'delete_post_meta' )->alias(
			static function ( int $id, string $key ) use ( &$deleted ): bool {
				$deleted[] = $key;
				return true;
			}
		);
		$_POST = array(
			'firehouse_project_nonce' => 'good',
			'firehouse'               => array(
				'summary'           => 'It\'s a \\ summary',
				'publicationStatus' => 'published',
				'geoNote'           => '',
				'fullRecordUrl'     => '',
			),
		);
		Project_Screen::save( 41, self::post() );

		$this->assertSame( array( 'fh_fullRecordUrl' ), $deleted );
		$this->assertSame( 'It\\\'s a \\\\ summary', $written['fh_summary'] );
		$this->assertSame( 'published', $written['fh_publicationStatus'] );
		$this->assertSame( array( 'regions' => array( 'GBCC' ) ), $written['fh_geo'] );
	}

	/**
	 * The form shows each stored value exactly, entities included, each control labelled and described.
	 */
	public function test_the_form_shows_each_value_exactly_as_stored(): void {
		$this->stored['fh_publicationStatus'] = 'in-review';
		$this->stored['fh_fullRecordUrl']     = 'https://example.org/?a=1&amp;b=2';
		ob_start();
		Project_Screen::editorial_box( self::post() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '>R&amp;amp;D summary</textarea>', $html );
		$this->assertStringContainsString( 'value="https://example.org/?a=1&amp;amp;b=2"', $html );
		$this->assertStringContainsString( '<option value="in-review" selected=\'selected\'>', $html );
		$this->assertStringContainsString( '<option value="">', $html );
		foreach ( Project_Screen::FIELDS as $name ) {
			$this->assertStringContainsString( '<label for="fh-field-' . $name . '">', $html );
			$this->assertStringContainsString( 'id="fh-field-' . $name . '" name="firehouse[' . $name . ']"', $html );
			$this->assertStringContainsString( 'aria-describedby="fh-field-' . $name . '-description"', $html );
		}
		$this->assertStringNotContainsString( 'aria-invalid', $html );
	}

	/**
	 * A refused save comes back once, with the typed values, each problem beside its field and at the top.
	 */
	public function test_a_refused_save_comes_back_once_marked(): void {
		$_GET = array( 'post' => '41' );
		Functions\when( 'get_post' )->justReturn( self::post() );
		Functions\when( 'wp_add_inline_style' )->justReturn( true );
		Functions\expect( 'get_transient' )->once()->with( 'firehouse_project_41_6' )->andReturn(
			array(
				'values' => array(
					'summary'           => 'Typed <i>summary</i>',
					'publicationStatus' => '',
					'geoNote'           => '',
					'fullRecordUrl'     => 'javascript:alert(1)',
				),
				'errors' => array( 'fullRecordUrl' => 'The full record link has to be a web address.' ),
			)
		);
		Functions\expect( 'delete_transient' )->once()->with( 'firehouse_project_41_6' );
		Project_Screen::load();

		ob_start();
		Project_Screen::notice();
		Project_Screen::editorial_box( self::post() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<div class="notice notice-error">', $html );
		$this->assertStringContainsString( '<a href="#fh-field-fullRecordUrl">Full record link</a>: The full record link has to be a web address.', $html );
		$this->assertStringContainsString( '>Typed &lt;i&gt;summary&lt;/i&gt;</textarea>', $html );
		$this->assertStringContainsString( 'value="javascript:alert(1)" spellcheck="false" aria-invalid="true" aria-describedby="fh-field-fullRecordUrl-error fh-field-fullRecordUrl-description"', $html );
		$this->assertStringContainsString( '<p class="fh-field__error" id="fh-field-fullRecordUrl-error">The full record link has to be a web address.</p>', $html );
	}

	/**
	 * Without a refusal there's no notice.
	 */
	public function test_no_notice_without_a_refusal(): void {
		$_GET = array( 'post' => '41' );
		Functions\when( 'get_post' )->justReturn( self::post() );
		Functions\when( 'wp_add_inline_style' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'delete_transient' )->justReturn( true );
		Project_Screen::load();

		ob_start();
		Project_Screen::notice();
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * The survey's answers are shown as stored, escaped, with no links.
	 */
	public function test_the_survey_answers_are_shown_as_stored(): void {
		ob_start();
		Project_Screen::survey_box( self::post() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<dt>Title</dt><dd>Smoke &amp; &lt;b&gt;fire&lt;/b&gt; study</dd>', $html );
		$this->assertStringContainsString( '<li>Ana &lt;Ruiz&gt; &amp; co (CIRA)</li>', $html );
		$this->assertStringContainsString( '<dt>Year completed</dt><dd>2024</dd>', $html );
		$this->assertStringContainsString( '<li>made-up</li>', $html );
		$this->assertStringContainsString( '<dt>IRB approval</dt><dd>Approved, exempt, or not required</dd>', $html );
		$this->assertStringContainsString( "<ol><li>First line\nsecond line</li></ol>", $html );
		$this->assertStringContainsString( "<strong>Need:</strong> A need\n<strong>Recommendation:</strong> A recommendation", $html );
		$this->assertStringContainsString( 'Address: javascript:alert(1)', $html );
		$this->assertStringNotContainsString( 'href=', $html );
		$this->assertStringContainsString( '<dt>Organization</dt><dd><span class="fh-survey__none">No answer</span></dd>', $html );
		$this->assertStringContainsString( '<dt>Response ID</dt><dd>R_test</dd>', $html );
	}

	/**
	 * A project without IRB approval says visitors can't see it.
	 */
	public function test_a_project_without_approval_says_so(): void {
		$this->stored['fh_irbApproved'] = '';
		ob_start();
		Project_Screen::survey_box( self::post() );
		$this->assertStringContainsString( '<dt>IRB approval</dt><dd>Not recorded. Visitors can’t see a project without it.</dd>', (string) ob_get_clean() );
	}

	/**
	 * The heading names the project, and isn't added to other screens.
	 */
	public function test_the_heading_names_the_project_and_nothing_else(): void {
		ob_start();
		Project_Screen::heading( self::post() );
		Project_Screen::heading( new \WP_Post( array( 'post_type' => 'page' ) ) );
		$this->assertSame( '<h2 class="fh-project-screen__title">Smoke &amp; &lt;b&gt;fire&lt;/b&gt; study</h2>', ob_get_clean() );
	}
}
