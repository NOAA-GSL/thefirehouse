<?php
/**
 * Tests for the survey form's markup.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Survey\Form;
use Firehouse\Survey\Questions;
use Firehouse\Survey\Submission;
use Firehouse\Survey\Survey;

/**
 * The form is drawn accessibly: a label for every field, each group a fieldset with
 * its legend, help and problems tied to their fields, and a summary of what's wrong
 * that links to each; what a researcher typed is printed escaped, as typed.
 */
final class SurveyFormTest extends WordPressTestCase {

	/**
	 * Stubs what the markup reads.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\stubEscapeFunctions();
		Functions\when( 'get_option' )->justReturn( array( 'submitFormUrl' => 'https://forms.example.org/firehouse' ) );
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://example.test' . $path );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $part = -1 ): mixed => parse_url( $url, $part ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Standing in for wp_parse_url().
		Functions\when( 'checked' )->alias( static fn( mixed $a, mixed $b ): string => $a === $b ? ' checked=\'checked\'' : '' );
	}

	/**
	 * The questions are asked as the settings screen words them, escaped; a private
	 * answer's help ends with who sees it, even when the screen gave it help of its own
	 * or none.
	 */
	public function test_the_questions_are_asked_as_worded_on_the_settings_screen(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'questions' => array(
					'title'    => array( 'label' => 'Your <b>project</b>’s title?' ),
					'email'    => array( 'help' => 'Use your work address.' ),
					'jobTitle' => array( 'help' => '' ),
					'links'    => array( 'help' => '' ),
				),
			)
		);
		$html = self::drawn( array() );

		$this->assertStringContainsString( '<label class="fh-field__label" for="fh-survey-title">Your &lt;b&gt;project&lt;/b&gt;’s title?<span class="fh-field__required" aria-hidden="true"> *</span></label>', $html );
		$this->assertStringContainsString( '<span id="fh-survey-email-help" class="fh-field__help">Use your work address.</span><span id="fh-survey-email-privacy" class="fh-field__help">Only the FireHouse team and the site’s administrators see it, to follow up with you. It never appears on the site.</span>', $html );
		$this->assertStringContainsString( '<span id="fh-survey-jobTitle-privacy" class="fh-field__help">Only the FireHouse team and the site’s administrators see it. It never appears on the site.</span>', $html );
		$this->assertStringContainsString( 'aria-describedby="fh-survey-email-help fh-survey-email-privacy"', $html, 'Help and privacy line, each its own line, both tied to the field.' );
		$this->assertStringContainsString( '<fieldset class="fh-survey__group fh-survey__question" id="fh-survey-shared" tabindex="-1" aria-describedby="fh-survey-shared-privacy">', $html );
		$this->assertStringNotContainsString( 'fh-survey-links-help', $html, 'Help cleared: none.' );

		// A character reference shows as typed, never as the address or direction mark
		// the settings screen refuses. esc_html() as WordPress has it, which leaves a
		// reference alone (Brain Monkey's stand-in escapes it), so this fails if the
		// wording goes through esc_html().
		Functions\when( 'esc_html' )->alias( static fn( string $text ): string => htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false ) );
		Functions\when( 'get_option' )->justReturn(
			array(
				'questions' => array(
					'org'    => array( 'label' => 'Ask person&#64;example.org' ),
					'shared' => array( 'label' => 'Shared &#x202E;results' ),
					'needs'  => array( 'help' => 'Write &amp; &#x202E;here' ),
				),
			)
		);
		$html = self::drawn( array() );
		$this->assertStringContainsString( '>Ask person&amp;#64;example.org<span', $html );
		$this->assertStringContainsString( '<legend class="fh-field__label">Shared &amp;#x202E;results</legend>', $html );
		$this->assertStringContainsString( '<span id="fh-survey-needs-help" class="fh-field__help">Write &amp;amp; &amp;#x202E;here</span>', $html );
		$this->assertStringContainsString( 'Please list up to 8 user needs and recommendations identified in your project, one in each box.', $html, 'Not changed: the plugin’s wording.' );
	}

	/**
	 * The form as drawn for these answers and problems.
	 *
	 * @param array<string, string|list<string>> $values   The answers.
	 * @param array<string, string>              $problems What's wrong.
	 * @param string                             $focus    The field with the focus.
	 */
	private static function drawn( array $values, array $problems = array(), string $focus = '' ): string {
		return Form::render(
			array(
				'step'     => 'form',
				'values'   => $values + Submission::read( array() ),
				'problems' => $problems,
				'focus'    => $focus,
				'nonce'    => 'a-nonce',
				'started'  => '123.abc',
				'action'   => 'https://example.test/firehouse/submit/',
			)
		);
	}

	/**
	 * Every question has a label tied to its field, every group a fieldset and legend,
	 * the required ones marked, and the form carries its nonce, start time and the
	 * field people don't see, out of the accessibility tree.
	 */
	public function test_every_field_is_labelled(): void {
		$html = self::drawn( array( 'takeaways' => array( '', '' ) ) );

		$this->assertStringContainsString( '<label class="fh-field__label" for="fh-survey-submitter">Submitter name (First Last). You must be a co-author on the project.<span class="fh-field__required" aria-hidden="true"> *</span></label><input id="fh-survey-submitter" name="firehouse_survey[submitter]" type="text" value="" class="fh-field__control" autocomplete="name" aria-required="true" />', $html );
		$this->assertStringContainsString( '<fieldset class="fh-survey__group fh-survey__question" id="fh-survey-irb" tabindex="-1"><legend class="fh-field__label">Did this project receive IRB approval or exemption?<span class="fh-field__required" aria-hidden="true"> *</span><span class="fh-visually-hidden"> (required)</span></legend>', $html, 'A required group says so in words.' );
		$this->assertStringContainsString( '<legend class="fh-field__label">Beyond academic publications and presentations, have you shared your results outside of your research group? (select all that apply)</legend>', $html, 'An optional one doesn’t.' );
		$this->assertStringContainsString( '<label class="fh-field__label" for="fh-survey-methodsOther">If other, what?<span class="fh-visually-hidden"> (The data collection instruments)</span></label>', $html, 'The two Other boxes named apart.' );
		$this->assertStringContainsString( '<form class="fh-survey__form" method="post" action="https://example.test/firehouse/submit/" novalidate>', $html, 'Sent to the address with no fragment.' );
		$this->assertStringContainsString( '<input type="checkbox" id="fh-survey-regions-NWCC" name="firehouse_survey[regions][]" value="NWCC" /><label for="fh-survey-regions-NWCC">Northwest — OR, WA (NWCC)</label>', $html );
		$this->assertStringContainsString( '<label class="fh-field__label" for="fh-survey-takeaways-2">Takeaway 2</label>', $html );
		$this->assertStringNotContainsString( 'Remove Takeaway', $html, 'No fewer than 2.' );
		$this->assertStringContainsString( 'value="add:takeaways"', $html );
		$this->assertStringContainsString( 'name="_wpnonce" value="a-nonce"', $html );
		$this->assertStringContainsString( 'name="' . Survey::STARTED . '" value="123.abc"', $html );
		$this->assertStringContainsString( '<div class="fh-survey__trap" aria-hidden="true">', $html );
		$this->assertStringContainsString( 'aria-describedby="fh-survey-email-privacy"', $html, 'A private field says so.' );
		$this->assertSame( count( Questions::all() ), substr_count( $html, 'fh-survey__question' ) );
	}

	/**
	 * What's wrong is summed up at the top, which takes the focus, a link to each
	 * field, and said under each field, tied to it.
	 */
	public function test_problems_are_summed_up_and_tied_to_their_fields(): void {
		$html = self::drawn(
			array( 'takeaways' => array( '', '' ) ),
			array(
				'title'     => 'The project’s title: this is required.',
				'takeaways' => 'The takeaways: give at least 2.',
				'form'      => 'The form had been open for too long to send.',
			)
		);

		$this->assertStringContainsString( '<div class="fh-survey__problems" tabindex="-1" autofocus><div role="alert"><h2 id="fh-survey-problems-heading">There are 3 problems to fix before sending</h2>', $html, 'The alert inside what takes the focus.' );
		$this->assertStringContainsString( '<li><a href="#fh-survey-title">The project’s title: this is required.</a></li>', $html );
		$this->assertStringContainsString( '<li><a href="#fh-survey-takeaways">The takeaways: give at least 2.</a></li>', $html, 'How many: the group.' );
		$this->assertStringContainsString( '<fieldset class="fh-survey__group fh-survey__question" id="fh-survey-takeaways" tabindex="-1" aria-describedby="fh-survey-takeaways-problem">', $html );
		$this->assertStringContainsString( 'id="fh-survey-takeaways-1" name="firehouse_survey[takeaways][]" rows="3" class="fh-field__control fh-field__control--area">', $html, 'Its first box isn’t marked wrong.' );
		$this->assertStringContainsString( '<li>The form had been open for too long to send.</li>', $html );
		$this->assertStringContainsString( 'id="fh-survey-title" name="firehouse_survey[title]" type="text" value="" class="fh-field__control fh-field__control--error" aria-describedby="fh-survey-title-problem" aria-invalid="true" aria-required="true"', $html );
		$this->assertStringContainsString( '<span id="fh-survey-title-problem" class="fh-field__help fh-field__help--error"><span class="fh-visually-hidden">Error: </span>The project’s title: this is required.</span>', $html );
	}

	/**
	 * What a researcher typed comes back escaped, as typed: "R&amp;D" stays itself.
	 */
	public function test_typed_answers_come_back_as_typed(): void {
		$html = self::drawn(
			array(
				'title'     => 'R&amp;D <b>"quoted"</b>',
				'abstract'  => 'A <script>',
				'takeaways' => array( 'One & two', '' ),
			),
			array(),
			'takeaways-2'
		);

		$this->assertStringContainsString( 'value="R&amp;amp;D &lt;b&gt;&quot;quoted&quot;&lt;/b&gt;"', $html );
		$this->assertStringContainsString( '>A &lt;script&gt;</textarea>', $html );
		$this->assertStringContainsString( 'One &amp; two</textarea>', $html );
		$this->assertStringContainsString( 'id="fh-survey-takeaways-2" name="firehouse_survey[takeaways][]" rows="3" class="fh-field__control fh-field__control--area" autofocus>', $html, 'The box just added takes the focus.' );
	}

	/**
	 * Closed, the page says so and points at the survey address; after a submission, the
	 * thanks takes the focus.
	 */
	public function test_closed_and_thanks(): void {
		$closed = Form::render( array( 'step' => 'closed' ) );
		$this->assertStringContainsString( 'The FireHouse form isn’t taking submissions here yet.', $closed );
		$this->assertStringContainsString( 'href="https://forms.example.org/firehouse"', $closed );
		$this->assertStringNotContainsString( '<form', $closed );

		$thanks = Form::render( array( 'step' => 'yes' ) );
		$this->assertStringContainsString( '<div class="fh-survey__done" role="group" tabindex="-1" autofocus aria-labelledby="fh-survey-done"><p id="fh-survey-done">' . Questions::thanks() . '</p></div>', $thanks, 'Named by its message, which is read as it takes the focus.' );
		$this->assertStringContainsString( 'we only accept research studies that have been reviewed and approved', Form::render( array( 'step' => 'no-irb' ) ) );
	}
}
