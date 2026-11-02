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
	 * The shared fixture as a bundle.
	 */
	private static function bundle(): string {
		$site = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/shared/cases/site.json' ), true );
		return (string) json_encode( array( 'firehouseSeed' => 1 ) + $site ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Building a test fixture.
	}

	/**
	 * A bundle reads back as normalized content.
	 */
	public function test_a_bundle_reads_as_normalized_content(): void {
		$bundle = Seed_Bundle::read( self::bundle() );

		$this->assertSame( array( 'settings', 'topics', 'topicSummaries', 'projects', 'landing', 'about' ), array_keys( $bundle['content'] ) );
		$this->assertNotContains( 'no-irb-project', array_column( $bundle['content']['projects'], 'slug' ), 'A project without IRB approval is dropped.' );
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
