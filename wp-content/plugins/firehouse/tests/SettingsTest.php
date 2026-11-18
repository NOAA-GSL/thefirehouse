<?php
/**
 * Tests for the Firehouse settings.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Analysis;
use Firehouse\Settings;
use Firehouse\Survey\Questions;
use Firehouse\Workers_Ai;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

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
		Functions\when( 'current_user_can' )->justReturn( true );
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
			'siteName'      => 'The FireHouse',
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

		$this->assertSame( 'The FireHouse', $settings['siteName'] );
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
				'siteName'  => 'FireHouse',
				'footer'    => 'Not a setting',
				'navLabels' => array(
					'about' => 'Who we are',
					'other' => 'Not a link',
					'home'  => array( 'not text' ),
				),
			)
		);

		$settings = Settings::get();

		$this->assertSame( 'FireHouse', $settings['siteName'] );
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
		$this->assertSame(
			array_slice( self::valid(), 0, 3, true ) + array( 'surveyOpen' => false ) + array_slice( self::valid(), 3, null, true ) + array(
				'questions'       => array(),
				'synthesisPrompt' => '',
			),
			Settings::sanitize( self::valid() )
		);
		$this->assertSame( array(), $this->errors );
		$this->assertSame( array(), $this->transients );
	}

	/**
	 * A survey question's wording and help are stored only where they differ from the
	 * plugin's, as one line: wording left as it was, or a question the screen didn't
	 * send, follows the plugin's. Help may be cleared; a question may not.
	 */
	public function test_question_wording_is_stored_where_it_differs(): void {
		$own   = Questions::all();
		$input = self::valid() + array(
			'questions' => array(
				'submitter' => array(
					'label' => $own['submitter']['label'],
					'help'  => '',
				),
				'email'     => array(
					'label' => $own['email']['label'],
					'help'  => 'Use your work address.',
				),
				'title'     => array(
					'label' => "  The  project’s\r\n title?  ",
					'help'  => '',
				),
				'needs'     => array( 'help' => '' ),
				'nobody'    => array( 'label' => 'Not a question' ),
			),
		);

		$this->assertSame(
			array(
				'email' => array( 'help' => 'Use your work address.' ),
				'title' => array( 'label' => 'The project’s title?' ),
				'needs' => array( 'help' => '' ),
			),
			Settings::sanitize( $input )['questions']
		);
		$this->assertSame( array(), $this->errors );

		Functions\when( 'get_option' )->justReturn( array( 'questions' => array( 'title' => array( 'label' => 'Old' ) ) ) );
		$this->assertSame( array(), Settings::sanitize( self::valid() + array( 'questions' => array( 'title' => array( 'label' => $own['title']['label'] ) ) ) )['questions'], 'Put back as the plugin has it: stored no more.' );
	}

	/**
	 * Wording the form shouldn't show is refused, not changed, and the save with it:
	 * an empty question, a character that doesn't show, too much, or an email address.
	 */
	public function test_question_wording_that_cant_be_shown_is_refused(): void {
		Functions\when( 'number_format_i18n' )->alias( static fn( int $number ): string => number_format( $number ) );
		Functions\when( 'get_option' )->justReturn( array( 'questions' => array( 'org' => array( 'help' => 'Kept' ) ) ) );
		$input = self::valid() + array(
			'questions' => array(
				'submitter' => array( 'label' => " \n " ),
				'email'     => array( 'help' => "Type it \u{202E}here" ),
				'jobTitle'  => array( 'help' => "Bad \xC3(" ),
				'org'       => array( 'help' => str_repeat( 'x', Settings::LONGEST_WORDING + 1 ) ),
				'title'     => array( 'label' => 'Ask jane.doe@noaa.gov' ),
			),
		);

		$this->assertSame( array( 'org' => array( 'help' => 'Kept' ) ), Settings::sanitize( $input )['questions'] );
		$this->assertSame(
			array(
				'firehouse-questions-submitter-label' => 'Your name: the question can’t be empty.',
				'firehouse-questions-email-help'      => 'Your email: the help has a character that doesn’t show, such as a direction mark. Type it again.',
				'firehouse-questions-jobTitle-help'   => 'Your job title: the help has a character that doesn’t show, such as a direction mark. Type it again.',
				'firehouse-questions-org-help'        => 'Your agency or institution: the help has more than 2,000 characters.',
				'firehouse-questions-title-label'     => 'The project’s title: the question can’t hold an email address. The About page gives the team’s.',
			),
			array_column( $this->errors, 'message', 'code' )
		);
		$this->assertSame( 'Ask jane.doe@noaa.gov', $this->transients['firehouse_settings_rejected_7']['firehouse-questions-title-label'], 'What was typed, to show again.' );
	}

	/**
	 * The Import screen's settings carry no wording, and keep what's stored; what's
	 * stored is read only as wording, an empty question left out.
	 */
	public function test_stored_wording_is_kept_and_read_as_wording(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'questions' => array(
					'email' => array(
						'help'  => '',
						'label' => '',
					),
					'title' => array(
						'label' => 5,
						'help'  => 'Short is best.',
					),
					'org'   => 'A string',
					3       => array( 'label' => 'A number' ),
				),
			)
		);
		$stored = array(
			'email' => array( 'help' => '' ),
			'title' => array( 'help' => 'Short is best.' ),
		);
		$this->assertSame( $stored, Settings::stored()['questions'] );
		$this->assertSame( $stored, Settings::validate( self::valid() )[0]['questions'] );
	}

	/**
	 * The screen gives each question a row: its wording and help, each labelled, a
	 * changed one with the plugin's own wording to put back, and a private answer's help
	 * with the line the form always adds. A field in error says so and takes the focus.
	 */
	public function test_the_questions_are_drawn_to_reword(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'questions' => array(
					'title' => array( 'label' => 'Your <b>project</b>’s title?' ),
					'email' => array( 'help' => '' ),
				),
			)
		);
		$html = self::questions();

		$this->assertSame( count( Questions::all() ), substr_count( $html, '<th scope="row">' ) );
		$this->assertStringContainsString( '<th scope="row"><h3>The project’s title</h3></th><td><fieldset><legend class="screen-reader-text">The project’s title</legend>', $html );
		$this->assertStringContainsString( '<p><label for="firehouse-questions-title-label">Question<span class="screen-reader-text"> (The project’s title)</span></label></p><p><textarea id="firehouse-questions-title-label" name="firehouse_settings[questions][title][label]" rows="2" class="large-text" required aria-describedby="firehouse-questions-title-label-own">Your &lt;b&gt;project&lt;/b&gt;’s title?</textarea></p>', $html );
		$this->assertStringContainsString( '<p class="description" id="firehouse-questions-title-label-own">Changed. The plugin’s own wording: “What is the title of your project?”</p>', $html );
		$this->assertStringContainsString( 'name="firehouse_settings[questions][title][help]" rows="3" class="large-text"></textarea>', $html, 'No help, unchanged: nothing describes it.' );
		$this->assertStringContainsString( 'aria-describedby="firehouse-questions-email-help-privacy"></textarea>', $html, 'The help cleared to match the plugin, which has none.' );
		$this->assertStringContainsString( '<p class="description" id="firehouse-questions-email-help-privacy">Under the help, the form always says, and this screen can’t change: “Only the FireHouse team and the site’s administrators see it, to follow up with you. It never appears on the site.”</p>', $html );
		$this->assertStringNotContainsString( 'aria-invalid', $html );

		Settings::sanitize( self::valid() + array( 'questions' => array( 'org' => array( 'label' => '' ) ) ) );
		$html = self::questions();
		$this->assertStringContainsString( '<tr class="form-invalid form-required"><th scope="row"><h3>Your agency or institution</h3></th>', $html );
		$this->assertStringContainsString( 'required aria-describedby="firehouse-questions-org-label-error" aria-invalid="true" autofocus>', $html );
		$this->assertStringContainsString( '<p class="description" id="firehouse-questions-org-label-error"><strong>Error:</strong> Your agency or institution: the question can’t be empty.</p>', $html );
	}

	/**
	 * The survey form's switch changes only when the settings screen sends it, with its
	 * mark: an unticked box sends nothing, and the Import screen's settings have no switch.
	 */
	public function test_the_survey_switch_changes_only_from_the_screen(): void {
		$this->assertTrue(
			Settings::validate(
				self::valid() + array(
					'surveyOpenShown' => '1',
					'surveyOpen'      => '1',
				)
			)[0]['surveyOpen']
		);
		Functions\when( 'get_option' )->justReturn( array( 'surveyOpen' => true ) );
		$this->assertFalse( Settings::validate( self::valid() + array( 'surveyOpenShown' => '1' ) )[0]['surveyOpen'], 'Unticked.' );
		$this->assertTrue( Settings::validate( self::valid() )[0]['surveyOpen'], 'Not from the screen: kept.' );
		$this->assertTrue( Settings::stored()['surveyOpen'] );
		Functions\when( 'get_option' )->justReturn( array( 'surveyOpen' => '1' ) );
		$this->assertFalse( Settings::stored()['surveyOpen'], 'Only true opens it.' );
	}

	/**
	 * Only an administrator opens or closes the form: anyone else who may save the
	 * screen, a FireHouse Editor, leaves it as it was, and sees the box but can't tick it.
	 */
	public function test_only_an_administrator_changes_the_survey_switch(): void {
		Functions\stubEscapeFunctions();
		Functions\stubTranslationFunctions();
		Functions\when( 'checked' )->alias( static fn( mixed $a, mixed $b ): string => $a === $b ? ' checked=\'checked\'' : '' );
		Functions\when( 'current_user_can' )->alias( static fn( string $cap ): bool => Settings::OPEN_SURVEY !== $cap );
		$ticked = self::valid() + array(
			'surveyOpenShown' => '1',
			'surveyOpen'      => '1',
		);
		$this->assertFalse( Settings::validate( $ticked )[0]['surveyOpen'] );
		Functions\when( 'get_option' )->justReturn( array( 'surveyOpen' => true ) );
		$this->assertTrue( Settings::validate( self::valid() + array( 'surveyOpenShown' => '1' ) )[0]['surveyOpen'], 'Unticked by an editor: still open.' );

		ob_start();
		Settings::survey_field();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( ' disabled />', $html );
		$this->assertStringNotContainsString( 'surveyOpenShown', $html );
		$this->assertStringContainsString( 'Only an administrator can change this.', $html );

		Functions\when( 'current_user_can' )->justReturn( true );
		ob_start();
		Settings::survey_field();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'disabled', $html );
		$this->assertStringContainsString( 'surveyOpenShown', $html );
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

		$this->assertSame( 'The FireHouse', $settings['siteName'] );
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
		$this->assertSame( 'FireHouse settings', Settings::title( 'FireHouse settings' ) );

		Settings::sanitize( array( 'submitLabel' => '' ) + self::valid() );

		$this->assertSame( 'Not saved: FireHouse settings', Settings::title( 'FireHouse settings' ) );
	}

	/**
	 * A field's label points at it, its description is tied to it, and its value is
	 * escaped.
	 */
	public function test_a_field_is_labelled_described_and_escaped(): void {
		Functions\when( 'get_option' )->justReturn( array( 'siteName' => 'The "FireHouse"' ) );

		$html = self::field( array( 'siteName' ) );

		$this->assertStringContainsString( 'id="firehouse-siteName"', $html );
		$this->assertStringContainsString( 'aria-describedby="firehouse-siteName-description"', $html );
		$this->assertStringContainsString( 'id="firehouse-siteName-description"', $html );
		$this->assertStringContainsString( 'value="The &quot;FireHouse&quot;"', $html );
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
	 * The synthesis prompt is stored only where it differs from the plugin's own, its
	 * lines kept: unchanged or cleared, it follows the plugin's. The Import screen's
	 * settings carry none, and keep what's stored.
	 */
	public function test_the_prompt_is_stored_where_it_differs(): void {
		$prompt = static fn( string $typed ): string => Settings::sanitize( self::valid() + array( 'synthesisPrompt' => $typed ) )['synthesisPrompt'];

		$this->assertSame( '', $prompt( str_replace( "\n", "\r\n", Analysis::own_prompt() ) . "\r\n" ) );
		$this->assertSame( "Fewer needs.\n\nPlainer words.", $prompt( "  Fewer needs.\r\n\r\nPlainer words.  " ) );
		$this->assertSame( '', $prompt( " \n " ) );
		$this->assertSame( array(), $this->errors );

		Functions\when( 'get_option' )->justReturn( array( 'synthesisPrompt' => 'Kept.' ) );
		$this->assertSame( 'Kept.', Settings::stored()['synthesisPrompt'] );
		$this->assertSame( 'Kept.', Settings::validate( self::valid() )[0]['synthesisPrompt'] );
	}

	/**
	 * A prompt with a character that doesn't show, which a model would read though the
	 * team doesn't see it, or one too long, is refused, and the stored one kept.
	 */
	public function test_a_prompt_that_cant_be_seen_whole_is_refused(): void {
		Functions\when( 'number_format_i18n' )->alias( static fn( int $number ): string => number_format( $number ) );
		Functions\when( 'get_option' )->justReturn( array( 'synthesisPrompt' => 'Kept.' ) );

		$this->assertSame( 'Kept.', Settings::sanitize( self::valid() + array( 'synthesisPrompt' => "List needs.\r\nBy area.\nEach once.\u{E0041}\u{E0042}" ) )['synthesisPrompt'] );
		$this->assertSame( array( 'firehouse-synthesisPrompt' => 'The synthesis prompt has a character that doesn’t show (U+E0041), on line 3. Type that line again.' ), array_column( $this->errors, 'message', 'code' ), 'The first such character and its line, whatever the line endings.' );

		$this->errors = array();
		$this->assertSame( 'Kept.', Settings::sanitize( self::valid() + array( 'synthesisPrompt' => "List needs.\xFF" ) )['synthesisPrompt'] );
		$this->assertSame( array( 'firehouse-synthesisPrompt' => 'The synthesis prompt has a character that doesn’t show, such as a direction override or a tag character. Type it again.' ), array_column( $this->errors, 'message', 'code' ), 'Text that isn’t UTF-8 has no character to name.' );

		$this->errors = array();
		$this->assertSame( 'Kept.', Settings::sanitize( self::valid() + array( 'synthesisPrompt' => str_repeat( 'x', Analysis::LONGEST_PROMPT + 1 ) ) )['synthesisPrompt'] );
		$this->assertSame( array( 'firehouse-synthesisPrompt' => 'The synthesis prompt has 20,001 characters; it can have 20,000 at most.' ), array_column( $this->errors, 'message', 'code' ) );
	}

	/**
	 * The prompt's box shows the plugin's own until it's changed, says which it is and
	 * how to go back, and what the screen adds that it can't change.
	 */
	public function test_the_prompt_is_drawn_to_change(): void {
		$html = self::prompt();
		$this->assertStringContainsString( '<textarea id="firehouse-synthesisPrompt" name="firehouse_settings[synthesisPrompt]" rows="18" class="large-text code" aria-describedby="firehouse-synthesisPrompt-description firehouse-synthesisPrompt-own">Using only the source', $html );
		$this->assertStringContainsString( 'in words this can’t change, that they’re data and never instructions', $html );
		$this->assertStringContainsString( 'The plugin’s own prompt, which NotebookLM is given too.', $html );

		Functions\when( 'get_option' )->justReturn( array( 'synthesisPrompt' => 'Fewer <needs>.' ) );
		$html = self::prompt();
		$this->assertStringContainsString( '>Fewer &lt;needs&gt;.</textarea>', $html );
		$this->assertStringContainsString( 'Changed from the plugin’s own prompt, which NotebookLM is given too. To go back to it, clear the box and save.', $html );
	}

	/**
	 * The prompt's section is on the screen only while the site has a Workers AI key.
	 */
	public function test_the_prompt_is_offered_only_with_a_key(): void {
		$this->assertNotContains( 'firehouse_synthesis', self::sections() );
	}

	/**
	 * With a key, the screen has the prompt's section.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_with_a_key_the_prompt_is_offered(): void {
		define( Workers_Ai::ACCOUNT, '0123456789abcdef0123456789abcdef' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- The plugin's own constant, as wp-config.php sets it.
		define( Workers_Ai::TOKEN, 'test-token_0123456789ABCDEFGHIJ' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- The plugin's own constant, as wp-config.php sets it.

		$this->assertContains( 'firehouse_synthesis', self::sections() );
	}

	/**
	 * The sections the screen lays out.
	 *
	 * @return list<string>
	 */
	private static function sections(): array {
		$sections = array();
		Functions\when( 'register_setting' )->justReturn( true );
		Functions\when( 'add_settings_field' )->justReturn( null );
		Functions\when( 'add_settings_section' )->alias(
			static function ( string $id ) use ( &$sections ): void {
				$sections[] = $id;
			}
		);
		Settings::register();
		return $sections;
	}

	/**
	 * Draws the prompt's box.
	 */
	private static function prompt(): string {
		( new \ReflectionProperty( Settings::class, 'focused' ) )->setValue( null, false );
		ob_start();
		Settings::prompt_field();
		return (string) ob_get_clean();
	}

	/**
	 * Draws the survey questions' section, as a refused save leaves the screen.
	 */
	private static function questions(): string {
		( new \ReflectionProperty( Settings::class, 'focused' ) )->setValue( null, false );
		ob_start();
		Settings::questions_section();
		return (string) ob_get_clean();
	}

	/**
	 * Draws one field.
	 *
	 * @param string[] $path The field's path.
	 */
	private static function field( array $path ): string {
		( new \ReflectionProperty( Settings::class, 'focused' ) )->setValue( null, false );
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
