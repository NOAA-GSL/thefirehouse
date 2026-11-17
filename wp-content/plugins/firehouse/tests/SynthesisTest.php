<?php
/**
 * Tests for the checks on a pasted synthesis.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Firehouse\Domain\Synthesis;

/**
 * A pasted synthesis is read and checked as the synthesis screen shows it: every
 * problem said, the needs given only when there are none.
 */
final class SynthesisTest extends DomainTestCase {

	/**
	 * The projects: two the site shows, one a draft.
	 *
	 * @var list<array<string, mixed>>
	 */
	private const PROJECTS = array(
		array(
			'ref'    => 11,
			'slug'   => 'smoke-study',
			'id'     => 'R_smoke',
			'title'  => 'Smoke study',
			'public' => true,
		),
		array(
			'ref'    => 12,
			'slug'   => 'heat-study',
			'id'     => '2026-10-01 10:00:00',
			'title'  => 'Heat study',
			'public' => true,
		),
		array(
			'ref'    => 13,
			'slug'   => 'draft-study',
			'id'     => 'R_draft',
			'title'  => 'Draft study',
			'public' => false,
		),
	);

	/**
	 * A good synthesis: every area, with two needs in one.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function good(): array {
		return array(
			array(
				'topic'    => 'forecast',
				'topNeeds' => array(
					array(
						'text'     => ' Longer-lead outlooks ',
						'mentions' => 2,
						'projects' => array( 'smoke-study', '2026-10-01 10:00:00' ),
						'cites'    => array( 'Entry 1' ),
					),
					array(
						'text'     => 'Clearer smoke forecasts',
						'mentions' => 1,
						'projects' => array( 'R_smoke' ),
					),
				),
			),
			array(
				'topic'    => 'observe',
				'topNeeds' => array(),
			),
			array(
				'topic'    => 'warning',
				'topNeeds' => array(),
			),
			array(
				'topic'    => 'governance',
				'topNeeds' => array(),
			),
		);
	}

	/**
	 * What's wrong with a synthesis.
	 *
	 * @param mixed $synthesis The synthesis, before it's JSON.
	 * @return list<string>
	 */
	private static function problems( mixed $synthesis ): array {
		return Synthesis::check( (string) json_encode( $synthesis ), self::PROJECTS )['problems'];
	}

	/**
	 * A good synthesis gives its needs, by area in the site's order, each need's text
	 * trimmed and its projects by post ID, whether named by slug or response ID; what
	 * the site doesn't show is left out; a Markdown code fence around it is fine.
	 */
	public function test_a_good_synthesis_gives_its_needs(): void {
		$result = Synthesis::check( "```json\n" . json_encode( self::good() ) . "\n```", self::PROJECTS );

		$this->assertSame( array(), $result['problems'] );
		$this->assertSame( array( 'observe', 'forecast', 'warning', 'governance' ), array_keys( $result['needs'] ) );
		$this->assertSame(
			array(
				array(
					'text'     => 'Longer-lead outlooks',
					'mentions' => 2,
					'projects' => array( 11, 12 ),
				),
				array(
					'text'     => 'Clearer smoke forecasts',
					'mentions' => 1,
					'projects' => array( 11 ),
				),
			),
			$result['needs']['forecast']
		);
		$this->assertSame( array(), $result['needs']['observe'], 'An area with no need.' );
	}

	/**
	 * A need whose text shows nothing has none; one holding a character visitors
	 * wouldn't see, which a direction override or a model's hidden tag characters
	 * would be, says which; a long one is quoted in part; and past fifty problems the
	 * rest are counted.
	 */
	public function test_text_visitors_wouldnt_see_is_a_problem(): void {
		$synthesis = self::good();
		$texts     = array(
			"\u{200B}\u{3164} ",
			"Clearer outlooks\u{202E}",
			"Clearer outlooks\u{E0041}\u{E0042}",
			"Clearer\u{0000} outlooks",
			str_repeat( 'Long text ', 20 ),
		);
		foreach ( $texts as $index => $text ) {
			$synthesis[0]['topNeeds'][ $index ] = array(
				'text'     => $text,
				'mentions' => 1,
				'projects' => array( 'smoke-study' ),
			);
		}
		$problems = self::problems( $synthesis );

		$this->assertSame( 'Forecasts and Modeling, need 1: has no text.', $problems[0] );
		$this->assertStringStartsWith( 'Forecasts and Modeling, need 2: holds', $problems[1] );
		$this->assertStringEndsWith( ': holds a character visitors wouldn’t see (U+202E), such as a zero-width space or a direction override. Take it out and check again.', $problems[1] );
		$this->assertStringContainsString( '(U+E0041)', $problems[2] );
		$this->assertStringContainsString( '(U+0000)', $problems[3] );
		$this->assertSame( 3 + 1, count( $problems ), 'The long need is fine; one problem each.' );

		$synthesis[0]['topNeeds'] = array(
			array(
				'text'     => str_repeat( 'Long text ', 20 ),
				'mentions' => 60,
				'projects' => array_fill( 0, 60, 7 ),
			),
		);
		$problems                 = self::problems( $synthesis );
		$this->assertCount( 51, $problems );
		$this->assertStringStartsWith( 'Forecasts and Modeling, need 1 (“' . mb_substr( str_repeat( 'Long text ', 20 ), 0, 79 ) . '…”): names a project by something other', $problems[0] );
		$this->assertSame( '…and 10 more. Fix these and check again to see them.', $problems[50] );

		$this->assertTrue( Synthesis::blank( "\u{00A0}\u{FE0F}" ) );
		$this->assertFalse( Synthesis::blank( "\u{2615}\u{FE0F}" ), 'An emoji with its variation.' );
		$this->assertNull( Synthesis::hidden( 'R&D for <10 km' ) );
	}

	/**
	 * What can't be read as a synthesis at all says so, and nothing else.
	 */
	public function test_what_isnt_a_synthesis_says_so(): void {
		$this->assertSame( array( 'Nothing was pasted.' ), Synthesis::check( "  \n ", self::PROJECTS )['problems'] );
		$this->assertStringStartsWith( 'The paste isn’t JSON the screen can read', Synthesis::check( 'Here are the needs: [', self::PROJECTS )['problems'][0] );
		$this->assertStringStartsWith( 'The paste must be a list of the four topic areas', self::problems( array( 'observe' => array() ) )[0] );
		$this->assertStringStartsWith( 'The paste must be a list of the four topic areas', self::problems( array() )[0] );
		$this->assertSame( array(), Synthesis::check( 'nope', self::PROJECTS )['needs'] );
	}

	/**
	 * The areas: each once, by a key the site has, every one there.
	 */
	public function test_every_area_once(): void {
		$synthesis    = self::good();
		$synthesis[1] = array(
			'topic'    => 'smoke',
			'topNeeds' => array(),
		);
		$synthesis[]  = array(
			'topic'    => 'warning',
			'topNeeds' => array(),
		);
		$synthesis[]  = 'governance';
		$synthesis[2] = array( 'topic' => 'warning' );
		$this->assertSame(
			array(
				'“smoke” isn’t a topic area. The areas are observe, forecast, warning, governance.',
				'Warnings and Immediate Response: its topNeeds must be a list of needs.',
				'Warnings and Immediate Response is in the paste twice.',
				'Item 6 in the list isn’t a topic area: each is { "topic": …, "topNeeds": [ … ] }.',
				'Observations and Monitoring isn’t in the paste. Every topic area needs its list, an empty one if no project supports a need there.',
			),
			self::problems( $synthesis )
		);
	}

	/**
	 * An area shows up to five needs, by project count, highest first; a tie is the
	 * reviewer's to order.
	 */
	public function test_up_to_five_needs_by_count(): void {
		$need      = static fn( string $text, int $mentions, array $projects ): array => array(
			'text'     => $text,
			'mentions' => $mentions,
			'projects' => $projects,
		);
		$synthesis = self::good();

		$synthesis[0]['topNeeds'] = array(
			$need( 'One', 1, array( 'smoke-study' ) ),
			$need( 'Two', 2, array( 'smoke-study', 'heat-study' ) ),
			$need( 'Tie', 2, array( 'smoke-study', 'heat-study' ) ),
		);
		$this->assertSame( array( 'Forecasts and Modeling: its needs don’t run by project count, highest first: need 2 counts 2, more than need 1 above it, which counts 1.' ), self::problems( $synthesis ) );

		$synthesis[0]['topNeeds'] = array_fill( 0, 6, $need( 'Same', 1, array( 'heat-study' ) ) );
		$this->assertSame( array( 'Forecasts and Modeling has 6 needs; an area shows up to 5.' ), self::problems( $synthesis ) );
	}

	/**
	 * Each need's text, count and projects, every problem with the need it's in.
	 */
	public function test_each_need_is_checked(): void {
		$synthesis                = self::good();
		$synthesis[0]['topNeeds'] = array(
			array(
				'text'     => 'Unknown and unpublished',
				'mentions' => 3,
				'projects' => array( 'no-such-study', 'R_draft', 'smoke-study' ),
			),
			array(
				'text'     => 'Twice',
				'mentions' => 2,
				'projects' => array( 'smoke-study', 'R_smoke' ),
			),
			array(
				'text'     => '  ',
				'mentions' => '1',
				'projects' => array(),
			),
			array(
				'text'     => 'Not by name',
				'mentions' => 1,
				'projects' => array( 11 ),
			),
			array( 'Not', 'a need' ),
		);
		$this->assertSame(
			array(
				'Forecasts and Modeling, need 1 (“Unknown and unpublished”): names “no-such-study”, which isn’t a project on the site.',
				'Forecasts and Modeling, need 1 (“Unknown and unpublished”): names “R_draft”, Draft study, which the site doesn’t show: the synthesis may only draw on published projects.',
				'Forecasts and Modeling, need 2 (“Twice”): names Smoke study twice.',
				'Forecasts and Modeling, need 3: has no text.',
				'Forecasts and Modeling, need 3: names no projects. Each need lists the projects it draws on, by page slug or response ID.',
				'Forecasts and Modeling, need 3: its project count, mentions, must be a whole number.',
				'Forecasts and Modeling, need 4 (“Not by name”): names a project by something other than its page slug or response ID.',
				'Forecasts and Modeling, need 5: isn’t a need: each is { "text": …, "mentions": …, "projects": [ … ] }.',
			),
			self::problems( $synthesis )
		);

		$synthesis[0]['topNeeds'] = array(
			array(
				'text'     => 'Miscounted',
				'mentions' => 3,
				'projects' => array( 'smoke-study', 'heat-study' ),
			),
		);
		$this->assertSame( array( 'Forecasts and Modeling, need 1 (“Miscounted”): counts 3 projects but names 2. A project counts once, so the two must match.' ), self::problems( $synthesis ) );
	}

	/**
	 * A name that isn't a project's gets the page slug nearest it: one a model shortened
	 * or reworded, or gave as the page's path or the project's title. A project the site
	 * doesn't show is named as that. Nothing is given when no slug is near, or two are
	 * about as near; a long name is quoted in part; the name is never changed.
	 */
	public function test_a_name_gets_the_nearest_slug(): void {
		$projects  = array_merge(
			self::PROJECTS,
			array(
				array(
					'ref'    => 14,
					'slug'   => 'igniting-impact-advancing-fire-weather-decision-support',
					'id'     => 'R_impact',
					'title'  => 'Igniting Impact: Advancing Fire Weather Decision Support',
					'public' => true,
				),
				array(
					'ref'    => 15,
					'slug'   => 'smoke-study-2',
					'id'     => 'R_smoke2',
					'title'  => 'Smoke study, part 2',
					'public' => true,
				),
				array(
					'ref'    => 16,
					'slug'   => 'smoke-study-3',
					'id'     => 'R_smoke3',
					'title'  => 'Smoke study, part 3',
					'public' => true,
				),
			)
		);
		$where     = 'Forecasts and Modeling, need 1 (“Named”): names “%s”, which isn’t a project on the site.';
		$published = $where . ' The nearest published page slug is “%s”, for “%s”. If the need draws on that project, change the name to it and check again.';
		$unshown   = $where . ' The nearest page slug is “%s”, for “%s”, which the site doesn’t show: the synthesis may only draw on published projects.';
		$impact    = array( 'igniting-impact-advancing-fire-weather-decision-support', 'Igniting Impact: Advancing Fire Weather Decision Support' );
		$long      = str_repeat( 'heat-study', 21 );
		$cases     = array(
			'igniting-impact-advancing-fire-weather-dss' => sprintf( $published, 'igniting-impact-advancing-fire-weather-dss', ...$impact ),
			'/projects/igniting-impact-advancing-fire-weather-decision-support/' => sprintf( $published, '/projects/igniting-impact-advancing-fire-weather-decision-support/', ...$impact ),
			'Igniting Impact: Advancing Fire Weather Decision Support' => sprintf( $published, 'Igniting Impact: Advancing Fire Weather Decision Support', ...$impact ),
			'heat-studies'                               => sprintf( $published, 'heat-studies', 'heat-study', 'Heat study' ),
			'/projects/Smoke-Study-2/'                   => sprintf( $published, '/projects/Smoke-Study-2/', 'smoke-study-2', 'Smoke study, part 2' ),
			'Draft-Study'                                => sprintf( $unshown, 'Draft-Study', 'draft-study', 'Draft study' ),
			'smoke-study-4'                              => sprintf( $where, 'smoke-study-4' ),
			'no-such-study'                              => sprintf( $where, 'no-such-study' ),
			'R_impcat'                                   => sprintf( $where, 'R_impcat' ),
			'/'                                          => sprintf( $where, '/' ),
			$long                                        => sprintf( $where, mb_substr( $long, 0, 79 ) . '…' ),
		);
		foreach ( $cases as $name => $problem ) {
			$this->assertSame( array( $problem ), self::named( (string) $name, $projects ), (string) $name );
		}
	}

	/**
	 * The nearest slug is given only when every other is clearly further, whichever
	 * comes first in the list, and only when no more than a third of it differs.
	 */
	public function test_only_a_clearly_nearest_slug_is_given(): void {
		$project = static fn( int $ref, string $slug ): array => array(
			'ref'    => $ref,
			'slug'   => $slug,
			'id'     => '',
			'title'  => ucfirst( str_replace( '-', ' ', $slug ) ),
			'public' => true,
		);
		$given   = static fn( string $name, array $projects ): string => 1 === preg_match( '/The nearest published page slug is “([^”]+)”/u', self::named( $name, $projects )[0], $found ) ? $found[1] : 'none';

		$plumes = array( $project( 1, 'smoke-plume-study-2024' ), $project( 2, 'smoke-plume-study-2025' ), $project( 3, 'smoke-plume-study-2026' ) );
		$this->assertSame( 'smoke-plume-study-2026', $given( 'Smoke Plume Study 2026', $plumes ), 'Two as near, then a nearer one.' );
		$this->assertSame( 'smoke-plume-study-2026', $given( 'Smoke Plume Study 2026', array_reverse( $plumes ) ) );
		$this->assertSame( 'none', $given( 'smoke-plume-study-2027', $plumes ), 'Three as near.' );

		$this->assertSame( 'none', $given( 'fire-weather-study-abcde', array( $project( 1, 'fire-weather-study-vwxyz' ), $project( 2, 'fire-weather-study-vwxyzqr' ) ) ), 'Five changes against seven: half again rounds up.' );
		$this->assertSame( 'fire-weather-study-vwxyz', $given( 'fire-weather-study-abcde', array( $project( 1, 'fire-weather-study-vwxyz' ), $project( 2, 'fire-weather-study-vwxyzqrs' ) ) ), 'Five against eight.' );

		$health = array( $project( 1, 'wildfire-smoke-health-messaging-study' ), $project( 2, 'wildfire-smoke-health-risk-communication' ) );
		$this->assertSame( 'none', $given( 'wildfire-smoke-health-communication-study', $health ), 'Ten changes against eleven.' );
		$this->assertSame( 'wildfire-smoke-health-messaging-study', $given( 'wildfire-smoke-health-messaging-studies', $health ) );

		$this->assertSame( 'none', $given( 'heat-sxudy-ab', array( $project( 1, 'heat-study' ) ) ), 'Four changes in ten.' );
		$this->assertSame( 'heat-study', $given( 'heat-sxudy', array( $project( 1, 'heat-study' ) ) ) );
	}

	/**
	 * When no slug is clearly nearest, letter by letter, a name gets the slug it cuts
	 * short, if it begins no other and has two-thirds of its words; then the project
	 * whose title, in slug form, is clearly nearest, as a model makes its own slug from
	 * a title. The names are the ones models gave on 16 November.
	 */
	public function test_a_name_cut_short_or_made_from_a_title_gets_its_slug(): void {
		$project  = static fn( int $ref, string $slug, string $title ): array => array(
			'ref'    => $ref,
			'slug'   => $slug,
			'id'     => '',
			'title'  => $title,
			'public' => true,
		);
		$projects = array(
			$project( 1, 'igniting-impact-advancing-fire-weather-decision-support', 'Igniting Impact: Advancing Fire Weather Decision Support Services' ),
			$project( 2, 'igniting-insight-evaluating-nws-fire-weather-products', 'Igniting Insight: Evaluating NWS Fire Weather Products within a Fire Partner Decision-Making Context' ),
			$project( 3, 'an-evaluation-of-the-next-generation-fire-system-ngfs', 'An Evaluation of the Next Generation Fire System (NGFS) for Predictive Services' ),
			$project( 4, 'fire-weather-testbed-evaluation-004-national-weather-service', 'Fire Weather Testbed Evaluation #004 - National Weather Service Fire Warnings' ),
			$project( 5, 'fire-weather-testbed-evaluations-002-fire-detection', 'Fire Weather Testbed Evaluations #002: Fire Detection and Dissemination' ),
		);
		$given    = static fn( string $name ): string => 1 === preg_match( '/The nearest published page slug is “([^”]+)”/u', self::named( $name, $projects )[0], $found ) ? $found[1] : 'none';

		$this->assertSame( 'igniting-impact-advancing-fire-weather-decision-support', $given( 'igniting-impact-advancing-fire-weather' ), 'Cut short: 17 changes against 24 is no clear nearest, letter by letter.' );
		$this->assertSame( 'an-evaluation-of-the-next-generation-fire-system-ngfs', $given( 'an-evaluation-of-the-next-generation-fire-system-for-predictive-services' ), 'Made from the title, five letters off.' );
		$this->assertSame( 'igniting-insight-evaluating-nws-fire-weather-products', $given( 'igniting-insight-evaluating-nws-fire-weather-products-within-a-fire-partner-decision-making-context' ), 'Made from the title exactly.' );
		$this->assertSame( 'fire-weather-testbed-evaluations-002-fire-detection', $given( 'fire-weather-testbed-evaluation-002-fire-detection' ), 'Letter by letter first: one letter off, not the slug with four of its first words.' );
		$this->assertSame( 'none', $given( 'fire-weather-testbed-evaluation' ), 'Four of eight words.' );
		$this->assertSame( 'none', $given( 'igniting' ), 'Begins two slugs.' );
	}

	/**
	 * A name holding a character visitors wouldn't see is a problem of its own, and
	 * neither it nor the need's text is quoted, since a direction override would turn
	 * the rest of the problem around. A project's title is named without such
	 * characters, in every problem that names it.
	 */
	public function test_a_name_with_a_hidden_character_isnt_quoted(): void {
		$this->assertSame(
			array( 'Forecasts and Modeling, need 1 (“Named”): names a project by a name holding a character visitors wouldn’t see (U+200B), such as a zero-width space or a direction override. Take it out and check again.' ),
			self::named( "heat-study\u{200B}", self::PROJECTS )
		);
		$this->assertStringContainsString( '(U+202E)', self::named( "x\u{202E}heat-study", self::PROJECTS )[0] );
		$this->assertStringNotContainsString( "\u{202E}", self::named( "x\u{202E}heat-study", self::PROJECTS )[0] );

		$projects                 = self::PROJECTS;
		$projects[1]['title']     = "Heat\u{202E} study\u{200B}";
		$projects[2]['title']     = "Draft\u{202E} study";
		$projects[2]['public']    = false;
		$synthesis                = self::good();
		$synthesis[0]['topNeeds'] = array(
			array(
				'text'     => 'Named',
				'mentions' => 5,
				'projects' => array( 'heat-studies', 'draft-studies', 'R_draft', 'heat-study', 'heat-study' ),
			),
		);
		$problems                 = Synthesis::check( (string) json_encode( $synthesis ), $projects )['problems'];
		$this->assertStringEndsWith( 'for “Heat study”. If the need draws on that project, change the name to it and check again.', $problems[0], 'A title is named without the characters that don’t show.' );
		$this->assertStringContainsString( 'for “Draft study”, which the site doesn’t show', $problems[1] );
		$this->assertStringContainsString( '“R_draft”, Draft study, which', $problems[2] );
		$this->assertStringEndsWith( 'names Heat study twice.', $problems[3] );
		$this->assertCount( 4, $problems );
	}

	/**
	 * What's wrong with a synthesis whose one need names one project by this name.
	 *
	 * @param string                     $name     The name.
	 * @param list<array<string, mixed>> $projects The projects.
	 * @return list<string>
	 */
	private static function named( string $name, array $projects ): array {
		$synthesis                = self::good();
		$synthesis[0]['topNeeds'] = array(
			array(
				'text'     => 'Named',
				'mentions' => 1,
				'projects' => array( $name ),
			),
		);
		return Synthesis::check( (string) json_encode( $synthesis ), $projects )['problems'];
	}
}
