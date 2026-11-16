<?php
/**
 * Tests for a survey submission: read, checked and turned into a project's fields.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Survey\Submission;

/**
 * What a researcher sends is read from the form's fields, checked, and turned into the
 * draft project an import of the same answers would write, the private answers apart.
 */
final class SubmissionTest extends WordPressTestCase {

	/**
	 * Stubs WordPress's accent folding.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'remove_accents' )->alias(
			static fn( string $text ): string => strtr(
				$text,
				array(
					'É' => 'E',
					'é' => 'e',
				)
			)
		);
	}

	/**
	 * A good submission's answers, as read().
	 *
	 * @return array<string, string|list<string>>
	 */
	private static function good(): array {
		return array(
			'submitter'        => ' Jo  Smith ',
			'email'            => 'jo@example.org',
			'jobTitle'         => 'Social scientist',
			'org'              => 'A university',
			'title'            => 'Smoke   and evacuations: a study',
			'coResearchers'    => 'Lee Wong, Laura Sample McMeeking; jo smith',
			'completionYear'   => '2025',
			'irb'              => 'yes',
			'firePhases'       => array( 'before', 'during', 'before' ),
			'shared'           => array( 'end-users', 'none' ),
			'projectType'      => 'other',
			'projectTypeOther' => 'Pilot study',
			'methods'          => array( 'survey', 'other' ),
			'methodsOther'     => 'Notes during evaluation',
			'regions'          => array( 'NWCC', 'AICC' ),
			'abstract'         => "Evacuation orders reach people late. We asked why.\nThe rest follows.",
			'takeaways'        => array( 'First takeaway', '  ', 'Second takeaway' ),
			'needs'            => array( 'Need for clearer orders among residents: they arrive late. Recommend earlier notice.', 'Recommend only this.' ),
			'links'            => array( 'https://doi.org/10.1000/xyz.', 'https://zenodo.org/records/123', 'https://example.org/report' ),
		);
	}

	/**
	 * The form's fields are read by question, as text, a choice or a list of strings;
	 * anything else is nothing.
	 */
	public function test_the_answers_are_read_by_question(): void {
		$values = Submission::read(
			array(
				'title'      => 'A title',
				'firePhases' => array( 'before', array( 'nested' ), 42 ),
				'takeaways'  => 'not a list',
				'irb'        => array( 'yes' ),
				'unasked'    => 'dropped',
			)
		);

		$this->assertSame( 'A title', $values['title'] );
		$this->assertSame( array( 'before' ), $values['firePhases'] );
		$this->assertSame( array(), $values['takeaways'] );
		$this->assertSame( '', $values['irb'] );
		$this->assertSame( '', $values['projectTypeOther'] );
		$this->assertArrayNotHasKey( 'unasked', $values );
		$this->assertSame( Submission::read( array() ), Submission::read( 'not an array' ) );
	}

	/**
	 * A good submission has nothing wrong with it.
	 */
	public function test_a_good_submission_has_no_problems(): void {
		$this->assertSame( array(), Submission::check( self::good() ) );
	}

	/**
	 * Every required answer is asked for, by the field it's in, in the form's order.
	 */
	public function test_a_missing_answer_is_named_where_it_is(): void {
		$problems = Submission::check( Submission::read( array() ) );

		$this->assertSame( array( 'submitter', 'org', 'title', 'completionYear', 'irb', 'firePhases', 'projectType', 'methods', 'regions', 'abstract', 'takeaways-1', 'needs-1' ), array_keys( $problems ) );
		$this->assertSame( 'Your name: this is required.', $problems['submitter'] );
		$this->assertSame( 'IRB approval: choose one answer.', $problems['irb'] );
		$this->assertSame( 'The fire cycle phases: choose at least one.', $problems['firePhases'] );
		$this->assertSame( 'The takeaways: give at least 2.', $problems['takeaways-1'] );
	}

	/**
	 * Each answer is checked for what it should be.
	 */
	public function test_each_answer_is_checked(): void {
		$values   = array(
			'email'            => 'not an email',
			'completionYear'   => '2999',
			'irb'              => 'maybe',
			'regions'          => array( 'NWCC', 'MARS' ),
			'projectType'      => 'other',
			'projectTypeOther' => ' ',
			'title'            => str_repeat( 'x', 301 ),
			'takeaways'        => array( 'One' ),
			'links'            => array( 'not a link', 'https://example.org' ),
			'needs'            => array_fill( 0, 9, 'Need for more.' ),
			'abstract'         => 'Write to jane.doe@noaa.gov for the data.',
		) + self::good();
		$problems = Submission::check( $values );

		$this->assertSame( 'Your email: that doesn’t look like an email address.', $problems['email'] );
		$this->assertStringStartsWith( 'The year it was completed: give the year as four digits, no later than ', $problems['completionYear'] );
		$this->assertSame( 'IRB approval: choose one of the answers given.', $problems['irb'] );
		$this->assertSame( 'The GACCs: choose from the answers given.', $problems['regions'] );
		$this->assertSame( 'The kind of project: say what “Other” is.', $problems['projectTypeOther'] );
		$this->assertSame( 'The project’s title: it’s longer than 300 characters.', $problems['title'] );
		$this->assertSame( 'The takeaways: give at least 2.', $problems['takeaways-1'] );
		$this->assertSame( 'Link 1 isn’t a web address or a DOI: start it with https:// or 10.', $problems['links-1'] );
		$this->assertSame( 'The needs and recommendations: give no more than 8.', $problems['needs-1'] );
		$this->assertSame( 'The abstract: holds an email address. Your email goes in its own question, which only the FireHouse team sees.', $problems['abstract'] );
		$this->assertArrayNotHasKey( 'links-2', $problems );
	}

	/**
	 * The draft's fields are what an import of the same answers writes: spaces tidied,
	 * the researchers each once, "Other" as typed, needs split at "Recommend", links as
	 * DOIs where they carry one.
	 */
	public function test_the_project_is_what_an_import_writes(): void {
		$fields = Submission::project( self::good(), 'W_abc' );

		$this->assertSame( 'W_abc', $fields['id'] );
		$this->assertSame( 'Smoke and evacuations: a study', $fields['title'] );
		$this->assertSame( 'Evacuation orders reach people late.', $fields['summary'] );
		$this->assertSame( 'Evacuation orders reach people late. We asked why. The rest follows.', $fields['abstract'] );
		$this->assertSame(
			array(
				array(
					'name'   => 'Jo Smith',
					'given'  => 'Jo',
					'family' => 'Smith',
				),
				array(
					'name'   => 'Lee Wong',
					'given'  => 'Lee',
					'family' => 'Wong',
				),
				array( 'name' => 'Laura Sample McMeeking' ),
			),
			$fields['authors']
		);
		$this->assertSame( 2025, $fields['completionYear'] );
		$this->assertSame( array( 'before', 'during' ), $fields['firePhases'] );
		$this->assertSame( 'Pilot study', $fields['projectType'] );
		$this->assertSame( array( 'Survey', 'Notes during evaluation' ), $fields['methods'] );
		$this->assertTrue( $fields['irbApproved'] );
		$this->assertSame( array( 'regions' => array( 'NWCC', 'AICC' ) ), $fields['geo'] );
		$this->assertSame( array( 'First takeaway', 'Second takeaway' ), $fields['takeaways'] );
		$this->assertSame(
			array(
				array(
					'need'           => 'Need for clearer orders among residents: they arrive late.',
					'recommendation' => 'Recommend earlier notice.',
				),
				array( 'recommendation' => 'Recommend only this.' ),
			),
			$fields['needs']
		);
		$this->assertSame(
			array(
				array( 'doi' => '10.1000/xyz' ),
				array(
					'doi' => '10.5281/zenodo.123',
					'url' => 'https://zenodo.org/records/123',
				),
				array( 'url' => 'https://example.org/report' ),
			),
			$fields['papers']
		);
		$this->assertArrayNotHasKey( 'email', $fields, 'Nothing private (rule 2).' );
		$this->assertArrayNotHasKey( 'jobTitle', $fields );

		$this->assertFalse( Submission::project( array( 'irb' => 'no' ) + self::good(), 'W_abc' )['irbApproved'], 'Kept as a draft that won’t be published.' );
	}

	/**
	 * The private answers are apart, as the answers' own words.
	 */
	public function test_the_private_answers_are_apart(): void {
		$this->assertSame(
			array(
				'email'    => 'jo@example.org',
				'jobTitle' => 'Social scientist',
				'shared'   => array( 'Yes - Communicated back with end-users (meteorologists, fire crews, public, etc.)', 'No - So far, I have not shared results externally' ),
			),
			Submission::private_answers( self::good() )
		);
	}

	/**
	 * A slug is made as the import makes one: accents dropped, cut at a word under 60
	 * characters, no trailing "and" or "of".
	 */
	public function test_a_slug_is_made_as_the_import_makes_one(): void {
		$this->assertSame( 'evacuation-orders-smoke-a-study', Submission::slug( 'Évacuation orders & smoke: a study' ), 'Accents dropped, & gone, as the import does.' );
		$this->assertSame( 'fire-weather-testbed-evaluations-002-fire-detection', Submission::slug( 'Fire Weather Testbed Evaluations #002: Fire Detection and Dissemination - NESDIS Next Generation Fire System' ) );
		$this->assertSame( 'project', Submission::slug( '!!!' ) );
	}

	/**
	 * A long first sentence is cut at a word, with an ellipsis.
	 */
	public function test_a_long_summary_is_cut_at_a_word(): void {
		$summary = Submission::summary( str_repeat( 'word ', 80 ) . 'end.' );
		$this->assertStringEndsWith( '…', $summary );
		$this->assertLessThanOrEqual( 238, mb_strlen( $summary ) );
	}
}
