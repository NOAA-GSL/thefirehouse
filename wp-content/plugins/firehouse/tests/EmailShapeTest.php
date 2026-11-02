<?php
/**
 * Tests for finding text shaped like an email address.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Firehouse\Domain\Email_Shape;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * PHP passes the same cases as scripts/email-shape.mjs, and finds the same paths.
 */
final class EmailShapeTest extends DomainTestCase {

	/**
	 * Each shared case.
	 *
	 * @param array<string, mixed> $shared_case The case.
	 */
	#[DataProvider( 'cases' )]
	public function test_shared_case( array $shared_case ): void {
		$this->assertSame( $shared_case['email'], Email_Shape::has_email( $shared_case['text'] ), 'email' );
		$this->assertSame( $shared_case['team'], Email_Shape::is_team_email( $shared_case['text'] ), 'team' );
	}

	/**
	 * The cases in shared/cases/email-shape.json.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function cases(): array {
		return Cases::provider( 'email-shape.json' );
	}

	/**
	 * An address anywhere is found by its path, and the team's and a member's own
	 * address are passed, as scripts/email-shape.test.mjs has it.
	 */
	public function test_find_reports_paths_and_passes_a_team_address(): void {
		$blocks = array(
			'projects' => array( array( 'abstract' => 'Write to jane@gmail.com.' ) ),
			'about'    => array(
				'team'                 => array(
					'email'   => 'the.team@noaa.gov',
					'members' => array(
						array(
							'name'  => 'A',
							'email' => 'a.person@noaa.gov',
						),
						array(
							'name'  => 'B',
							'email' => 'b@noaa.gov; c@gmail.com',
						),
						array(
							'name' => 'C',
							'bio'  => array( 'Reach me at c@noaa.gov.' ),
						),
					),
				),
				'team.members.0.email' => 'd@gmail.com',
			),
		);

		$this->assertSame(
			array( 'projects.0.abstract', 'about.team.members.1.email', 'about.team.members.2.bio.0', 'about.team.members.0.email' ),
			Email_Shape::find( $blocks )
		);
	}

	/**
	 * An outside address in the team's own field is found.
	 */
	public function test_find_reports_an_outside_address_in_the_teams_field(): void {
		$this->assertSame(
			array( 'about.team.email' ),
			Email_Shape::find( array( 'about' => array( 'team' => array( 'email' => 'the.team@gmail.com' ) ) ) )
		);
	}
}
