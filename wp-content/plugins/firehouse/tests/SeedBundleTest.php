<?php
/**
 * Tests for reading the seed bundle.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Firehouse\Domain\Content_Error;
use Firehouse\Seed_Bundle;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Only a Firehouse seed bundle is read, its content is checked as the React site
 * checks it, and every link that check drops is noted.
 */
final class SeedBundleTest extends DomainTestCase {

	/**
	 * The shared fixture as a bundle, changed by a callback first.
	 *
	 * @param callable|null $change Changes the decoded fixture in place.
	 */
	private static function bundle( ?callable $change = null ): string {
		$site = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/shared/cases/site.json' ), true );
		if ( null !== $change ) {
			$change( $site );
		}
		return (string) json_encode( array( 'firehouseSeed' => 1 ) + $site ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Building a test fixture.
	}

	/**
	 * A bundle reads back as normalized content, with nothing wrong.
	 */
	public function test_a_bundle_reads_as_normalized_content(): void {
		$bundle = Seed_Bundle::read( self::bundle() );

		$this->assertSame( array( 'settings', 'topics', 'topicSummaries', 'projects', 'landing', 'about' ), array_keys( $bundle['content'] ) );
		$this->assertNotContains( 'no-irb-project', array_column( $bundle['content']['projects'], 'slug' ), 'A project without IRB approval is dropped.' );
		$this->assertSame( array(), $bundle['problems'] );
	}

	/**
	 * A field the plugin doesn't store is a problem, named by where it is and what
	 * it's called, never by what it holds; a name that doesn't look like a field's
	 * isn't shown either, since a name can carry anything.
	 */
	public function test_a_field_the_plugin_doesnt_store_is_a_problem(): void {
		$problems = Seed_Bundle::read(
			self::bundle(
				static function ( array &$site ): void {
					$site['projects'][0]['jobTitle']        = 'Private answer';
					$site['projects'][1]['q10@example.org'] = 'x';
					$site['topicSummaries'][0]['internal']  = 'x';
					$site['topics'][0]['topNeeds']          = array();
				}
			)
		)['problems'];

		$this->assertCount( 4, $problems );
		$this->assertStringContainsString( 'projects.0 (“smoke-alerts-for-evacuation”) has a field the plugin doesn’t store: jobTitle', $problems[0] );
		$this->assertStringContainsString( 'has a field the plugin doesn’t store: a field with an unusual name', $problems[1] );
		$this->assertStringContainsString( 'topNeeds', $problems[2], 'A synthesis field in a topic’s own entry.' );
		$this->assertStringContainsString( 'topicSummaries.0 (“observe”) has a field the plugin doesn’t store: internal', $problems[3] );
		$this->assertStringNotContainsString( 'Private answer', implode( ' ', $problems ) );
		$this->assertStringNotContainsString( 'example.org', implode( ' ', $problems ) );
	}

	/**
	 * An email address anywhere but a team member's own email field is a problem,
	 * named by where it is; a team member's work address isn't.
	 */
	public function test_an_email_where_none_belongs_is_a_problem(): void {
		$problems = Seed_Bundle::read(
			self::bundle(
				static function ( array &$site ): void {
					$site['projects'][0]['abstract']              = 'Write to jane.doe@gmail.com for the data.';
					$site['about']['team']['members'][0]['email'] = 'hal.member@noaa.gov';
				}
			)
		)['problems'];

		$this->assertCount( 1, $problems );
		$this->assertStringContainsString( 'projects.0.abstract', $problems[0] );
		$this->assertStringNotContainsString( 'jane.doe', $problems[0] );

		$outside = Seed_Bundle::read(
			self::bundle(
				static function ( array &$site ): void {
					$site['about']['team']['members'][0]['email'] = 'hal@gmail.com';
				}
			)
		)['problems'];
		$this->assertStringContainsString( 'about.team.members.0.email', $outside[0] );
	}

	/**
	 * A link to a project the site doesn't show is noted, with the need quoted.
	 */
	public function test_every_dropped_link_is_noted(): void {
		$notes = Seed_Bundle::read( self::bundle() )['notes'];

		$this->assertCount( 2, $notes );
		$this->assertStringContainsString( '“draft-fuel-study”', $notes[0] );
		$this->assertStringContainsString( '“no-such-project”', $notes[1] );
		$this->assertStringContainsString( 'the observe synthesis', $notes[0] );
	}

	/**
	 * Anything that isn't a bundle is refused before its content is read.
	 *
	 * @param string $json The file.
	 */
	#[DataProvider( 'not_bundles' )]
	public function test_anything_else_is_refused( string $json ): void {
		$this->expectException( Content_Error::class );
		Seed_Bundle::read( $json );
	}

	/**
	 * Not JSON, JSON with no marker or another format, a list, and a file too large.
	 *
	 * @return array<string, array{string}>
	 */
	public static function not_bundles(): array {
		return array(
			'not JSON'        => array( '{"firehouseSeed": 1,' ),
			'no marker'       => array( '{"settings": {}}' ),
			'another format'  => array( '{"firehouseSeed": 2}' ),
			'a list'          => array( '[1, 2]' ),
			'an empty object' => array( '{}' ),
			'too large'       => array( '{"firehouseSeed": 1, "x": "' . str_repeat( 'a', Seed_Bundle::MAX_BYTES ) . '"}' ),
		);
	}
}
