<?php
/**
 * The survey form's questions.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Survey;

use Firehouse\Domain\Vocabulary;
use Firehouse\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What the survey form asks: the Google Form's questions ("FireHouse V1"), in its
 * order and its wording, which is Stephanie and Emily's. Three of them asked for a
 * numbered or comma-separated list in one box; here each entry has its own box (the
 * plan's "One box per entry"), so their wording drops the numbering. The choices
 * for fire phases and regions are the site's own, from shared/vocabulary.json (rule
 * 8). Each question's wording and help can be changed on the settings screen (Eric,
 * 16 November), and worded() gives them as changed there; their order, their choices
 * and the line saying who sees a private answer are fixed here.
 *
 * Three answers are private (rule 2): the email (Q2), the job title (Q3) and how the
 * results were shared (Q10). They're stored apart from the project and never reach a
 * page, the REST API or a revision. Each has a `privacy` line saying who sees it, which
 * the form shows after its help and the settings screen can't change: it's a promise
 * for the privacy review to check, not wording.
 */
final class Questions {

	/**
	 * The fewest and most takeaways.
	 */
	public const TAKEAWAYS = array( 2, 4 );

	/**
	 * The fewest and most needs and recommendations.
	 */
	public const NEEDS = array( 1, 8 );

	/**
	 * The fewest and most links.
	 */
	public const LINKS = array( 0, 10 );

	/**
	 * The answers that stay private (rule 2).
	 */
	public const PRIVATE = array( 'email', 'jobTitle', 'shared' );

	/**
	 * The parts of a question the settings screen can reword.
	 */
	public const WORDING = array( 'label', 'help' );

	/**
	 * The questions, by name, in the form's order, in the plugin's own wording (the form
	 * asks worded()). Each has a `type` (text, email, year, longtext, choice, choices or
	 * entries), whether it's `required`, a short `name` for what's wrong with an answer,
	 * its `label` and any `help`, a private answer's `privacy` line, the most characters
	 * an answer may have (`max`), and a choice's `options` (value => label, with `other`
	 * for a box of its own).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		$phases = array();
		foreach ( Vocabulary::items( 'firePhases' ) as $key => $phase ) {
			$phases[ $key ] = $phase['short'] . ': ' . $phase['label'] . ( isset( $phase['description'] ) ? ' (' . $phase['description'] . ')' : '' );
		}
		$regions = array();
		foreach ( Vocabulary::items( 'regions' ) as $key => $region ) {
			$regions[ $key ] = $region['label'];
		}
		return array(
			'submitter'      => array(
				'name'         => 'Your name',
				'type'         => 'text',
				'required'     => true,
				'max'          => 200,
				'autocomplete' => 'name',
				'label'        => 'Submitter name (First Last). You must be a co-author on the project.',
			),
			'email'          => array(
				'name'         => 'Your email',
				'type'         => 'email',
				'required'     => false,
				'max'          => 200,
				'autocomplete' => 'email',
				'label'        => 'What is your preferred email?',
				'privacy'      => 'Only the FireHouse team and the site’s administrators see it, to follow up with you. It never appears on the site.',
			),
			'jobTitle'       => array(
				'name'         => 'Your job title',
				'type'         => 'text',
				'required'     => false,
				'max'          => 200,
				'autocomplete' => 'organization-title',
				'label'        => 'What is your job title?',
				'privacy'      => 'Only the FireHouse team and the site’s administrators see it. It never appears on the site.',
			),
			'org'            => array(
				'name'         => 'Your agency or institution',
				'type'         => 'text',
				'required'     => true,
				'max'          => 300,
				'autocomplete' => 'organization',
				'label'        => 'What agency or institution do you work for?',
			),
			'title'          => array(
				'name'     => 'The project’s title',
				'type'     => 'text',
				'required' => true,
				'max'      => 300,
				'label'    => 'What is the title of your project?',
			),
			'coResearchers'  => array(
				'name'     => 'The co-researchers',
				'type'     => 'longtext',
				'required' => false,
				'max'      => 2000,
				'rows'     => 3,
				'label'    => 'Please list the names of all co-researchers who contributed to this project (First Last, names separated by commas). Their names will also be included on the FireHouse website.',
			),
			'completionYear' => array(
				'name'     => 'The year it was completed',
				'type'     => 'year',
				'required' => true,
				'label'    => 'In what year did you complete this project?',
			),
			'irb'            => array(
				'name'     => 'IRB approval',
				'type'     => 'choice',
				'required' => true,
				'label'    => 'Did this project receive IRB approval or exemption?',
				'options'  => array(
					'yes'          => 'Yes',
					'no'           => 'No',
					'not-required' => 'Project did not require IRB review',
				),
			),
			'firePhases'     => array(
				'name'     => 'The fire cycle phases',
				'type'     => 'choices',
				'required' => true,
				'label'    => 'Which wildland fire cycle phase(s) did your research cover? (select all that apply)',
				'options'  => $phases,
			),
			'shared'         => array(
				'name'     => 'Sharing results',
				'type'     => 'choices',
				'required' => false,
				'label'    => 'Beyond academic publications and presentations, have you shared your results outside of your research group? (select all that apply)',
				'privacy'  => 'Only the FireHouse team and the site’s administrators see this answer.',
				'options'  => array(
					'end-users'  => 'Yes - Communicated back with end-users (meteorologists, fire crews, public, etc.)',
					'developers' => 'Yes - Communicated back with tool developers',
					'policy'     => 'Yes - Communicated back with policy makers',
					'operations' => 'Yes - Discussed in operational settings (forecast offices, fire agencies, emergency management, etc.)',
					'none'       => 'No - So far, I have not shared results externally',
				),
			),
			'projectType'    => array(
				'name'     => 'The kind of project',
				'type'     => 'choice',
				'required' => true,
				'max'      => 200,
				'label'    => 'Which of the following best describes your project?',
				'options'  => array(
					'testbed'  => 'Testbed Evaluation',
					'program'  => 'Program Evaluation (tools, community adaptation, etc.)',
					'research' => 'Research Study',
					'other'    => 'Other',
				),
				'other'    => 'other',
			),
			'methods'        => array(
				'name'     => 'The data collection instruments',
				'type'     => 'choices',
				'required' => true,
				'max'      => 200,
				'label'    => 'Select all data collection instruments used for this project. (select all that apply)',
				'options'  => array(
					'survey'     => 'Survey',
					'focus'      => 'Focus groups',
					'interviews' => 'Interviews',
					'workshops'  => 'Workshop/roundtable discussions',
					'other'      => 'Other',
				),
				'other'    => 'other',
			),
			'regions'        => array(
				'name'     => 'The GACCs',
				'type'     => 'choices',
				'required' => true,
				'label'    => 'Select all Geographic Area Coordination Centers (GACC) that are represented by your research project. If your project is National, includes Hawaii/outside the US, or is unknown, select from the additional options at the end of the list.',
				'options'  => $regions,
			),
			'abstract'       => array(
				'name'     => 'The abstract',
				'type'     => 'longtext',
				'required' => true,
				'max'      => 3000,
				'rows'     => 8,
				'label'    => 'Briefly describe the objectives and scope of your evaluation or study (no more than 150 words). Think of this as the project’s abstract.',
			),
			'takeaways'      => array(
				'name'     => 'The takeaways',
				'type'     => 'entries',
				'required' => true,
				'max'      => 1000,
				'rows'     => 3,
				'count'    => self::TAKEAWAYS,
				'label'    => 'List 2 to 4 major takeaways from this work.',
				'entry'    => 'Takeaway',
			),
			'needs'          => array(
				'name'     => 'The needs and recommendations',
				'type'     => 'entries',
				'required' => true,
				'max'      => 1500,
				'rows'     => 4,
				'count'    => self::NEEDS,
				'label'    => 'Please list up to 8 user needs and recommendations identified in your project, one in each box.',
				'help'     => 'Whenever possible, pair each need with its corresponding recommendation in a single entry. However, feel free to submit stand-alone needs or stand-alone recommendations if they do not have a direct pair. Start with “Need for…” to describe the gap, barrier, or operational issue, and conclude the entry with “Recommend…” as the final sentence to outline the suggested solution. Be specific about the end-user group(s) to which the need and recommendation apply (e.g., incident commanders, NWS meteorologists, local emergency managers, wildland firefighters, county planners, public safety officers). Limit each entry to 70 words. For example: “Need for [Topic] among [Specific end-User Group]: [Description of the specific gap, barrier, or operational challenge facing this user group]. Recommend [actionable recommendation or solution].”',
				'entry'    => 'Need and recommendation',
			),
			'links'          => array(
				'name'     => 'The links',
				'type'     => 'entries',
				'required' => false,
				'max'      => 2000,
				'count'    => self::LINKS,
				'label'    => 'Please provide the links to corresponding publications, tech notes, presentations, or reports (DOI/URL), one in each box.',
				'help'     => 'If your report is in a Google Doc, Box, or OneDrive folder, please paste a shareable “Anyone with the link can view” URL.',
				'entry'    => 'Link',
			),
		);
	}

	/**
	 * The questions as the form asks them: each one's wording and help as the settings
	 * screen changed them (Settings::stored()), over the plugin's own; and after a
	 * private answer's help, its privacy line, whatever the help says.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function worded(): array {
		$questions = self::all();
		$changed   = Settings::stored()['questions'];
		foreach ( $questions as $name => $question ) {
			foreach ( self::WORDING as $part ) {
				if ( isset( $changed[ $name ][ $part ] ) ) {
					$questions[ $name ][ $part ] = $changed[ $name ][ $part ];
				}
			}
			if ( isset( $question['privacy'] ) ) {
				$questions[ $name ]['help'] = trim( ( $questions[ $name ]['help'] ?? '' ) . ' ' . $question['privacy'] );
			}
		}
		return $questions;
	}

	/**
	 * What the form says above its questions: the Google Form's description, its
	 * sections and their points, the placeholder for the site's address filled in.
	 * "AI" is "Super Intelligence (SI)" here, as Executive Order 14434 has federal
	 * sites say (Eric, 16 November).
	 *
	 * @return list<array{heading: string, paragraphs: list<string>, items: list<string>}>
	 */
	public static function notice(): array {
		return array(
			array(
				'heading'    => 'Welcome to the FireHouse User Needs Submission Portal!',
				'paragraphs' => array(),
				'items'      => array(),
			),
			array(
				'heading'    => 'Motivation & Background',
				'paragraphs' => array(
					'This initiative grew directly out of our team’s work aggregating social science insights across wildland fire projects. We realized a critical piece was missing from the community: a dynamic, social science-driven needs assessment that stays continuously updated as new research and operational projects are completed.',
					'The FireHouse bridges the gap between research and operations (R2O) by elevating the real-world voices, gaps, and decisions of end-users. By submitting your project’s findings, you help build a centralized, cross-agency repository of end-user needs spanning NOAA, partner federal agencies (USFS, USGS, DOI, NASA, USACE, FEMA, etc.), higher education, non-profits, and community organizations.',
				),
				'items'      => array(),
			),
			array(
				'heading'    => 'Project Inclusion Criteria',
				'paragraphs' => array( 'Before submitting, please ensure your project meets the following criteria:' ),
				'items'      => array(
					'Scope & Topic: Captures end-user insights pertaining to fire weather across any phase (before, during, after, or long-term planning/adaptation), including wildland fire, smoke, and post-fire hydrologic impacts (precipitation, debris flow, and flooding).',
					'Project Status: The project must be completed.',
					'Compliance: Any human subjects research included must be IRB-approved.',
					'Submission Unit: Submissions should represent one specific project, rather than a researcher’s entire body of work.',
				),
			),
			array(
				'heading'    => 'Super Intelligence (SI) Usage & Data Privacy Disclosure',
				'paragraphs' => array(),
				'items'      => array(
					'Categorization: We utilize Google Gemini within a secure NOAA Gemini / NotebookLM workspace to assist in categorizing and organizing submitted user needs.',
					'Data Security: Your data will not be used for SI model training purposes.',
					'Quality Control: All content—both prior to SI processing and in its final form—is thoroughly reviewed and quality-checked by the GSL User Needs Team.',
					'Public Visibility: Please note that information submitted through this form may be featured publicly on the FireHouse website.',
				),
			),
		);
	}

	/**
	 * What the page says after a submission.
	 */
	public static function thanks(): string {
		return 'Thank you. Your submission has reached the FireHouse team, who review each one before it appears on the site.';
	}

	/**
	 * What the page says after a submission without IRB approval or exemption: the
	 * Google Form's own ending for that answer. It's kept as a draft all the same, so
	 * nothing a researcher sends is lost.
	 */
	public static function no_irb(): string {
		return 'Thank you for your interest in submitting to the FireHouse! Unfortunately, we only accept research studies that have been reviewed and approved by your respective IRB. We apologize.';
	}
}
