<?php
/**
 * Tests for the seed import's plan.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Importer;
use Firehouse\Settings;

/**
 * The dry run says what an import of the shared fixture would do to a site, and
 * finds what would stop it. The writes themselves are checked in a WordPress.
 */
final class ImporterTest extends WordPressTestCase {

	/**
	 * Stubs the functions planning calls.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'rest_validate_value_from_schema' )->justReturn( true );
		Functions\when( 'sanitize_title' )->alias( static fn( string $title ): string => trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $title ) ), '-' ) );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $text ): string => trim( $text ) );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url ): mixed => parse_url( $url ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Standing in for wp_parse_url().
		Functions\when( 'esc_url_raw' )->returnArg();
	}

	/**
	 * The fixture's content, normalized.
	 *
	 * @return array<string, mixed>
	 */
	private static function content(): array {
		return Cases::site();
	}

	/**
	 * A project post holding exactly what the bundle has for this project.
	 *
	 * @param array<string, mixed> $project The bundle's project.
	 * @param int                  $post_id The post's ID.
	 * @return array<string, mixed>
	 */
	private static function stored( array $project, int $post_id ): array {
		$fields = array_intersect_key( $project, \Firehouse\Meta::fields( 'fh_project' ) );
		return array(
			'post_id' => $post_id,
			'status'  => $project['published'] ? 'publish' : 'draft',
			'fields'  => array(
				'slug'      => $project['slug'],
				'published' => $project['published'],
			) + $fields,
		);
	}

	/**
	 * Every topic post, as an import of the fixture would leave it, given the
	 * projects' post IDs.
	 *
	 * @param array<string, mixed> $content  The content.
	 * @param array<string, int>   $post_ids Post IDs by slug.
	 * @return list<array<string, mixed>>
	 */
	private static function stored_topics( array $content, array $post_ids ): array {
		$topics = array();
		foreach ( $content['topics'] as $index => $topic ) {
			$summary = array();
			foreach ( $content['topicSummaries'] as $candidate ) {
				if ( $candidate['topic'] === $topic['key'] ) {
					$summary = $candidate;
				}
			}
			$fields             = array_intersect_key( $topic + $summary, \Firehouse\Meta::fields( 'fh_topic' ) );
			$fields['topNeeds'] = array_map(
				static function ( array $need ) use ( $post_ids ): array {
					if ( isset( $need['projects'] ) ) {
						$need['projects'] = array_map( static fn( string $slug ): int => $post_ids[ $slug ], $need['projects'] );
					}
					return $need;
				},
				$fields['topNeeds'] ?? array()
			);
			$topics[]           = array(
				'post_id' => 100 + $index,
				'status'  => 'publish',
				'key'     => $topic['key'],
				'fields'  => array( 'label' => $topic['label'] ) + $fields,
			);
		}
		return $topics;
	}

	/**
	 * Into an empty site, every project and topic is added, published or a draft as
	 * the bundle has it, each project post titled with the escaped label.
	 */
	public function test_into_an_empty_site_everything_is_added(): void {
		$plan = Importer::plan( self::content(), array(), array(), Settings::get() );

		$this->assertTrue( $plan['ok'] );
		$this->assertSame( array( 'create' ), array_values( array_unique( array_column( $plan['projects'], 'action' ) ) ) );
		$this->assertSame( array( 'smoke-alerts-for-evacuation', 'national-outlook-study', 'pacific-island-recovery', 'draft-fuel-study', 'alpha-fire-forecasting' ), array_column( $plan['projects'], 'slug' ) );
		$this->assertSame( 'draft', $plan['projects'][3]['post']['post_status'] );
		$this->assertSame( 'publish', $plan['projects'][0]['post']['post_status'] );
		$this->assertArrayHasKey( 'title', $plan['projects'][0]['set'], 'The title is a field.' );
		$this->assertSame( array( 'observe', 'forecast', 'warning', 'governance' ), array_column( $plan['topics'], 'key' ) );
		$this->assertSame( array( 'create' ), array_values( array_unique( array_column( $plan['topics'], 'action' ) ) ) );
		$this->assertTrue( $plan['topics'][0]['needs_new_projects'] );
		$this->assertSame( 'update', $plan['settings']['action'] );
		$this->assertSame( array( 'submitFormUrl' ), $plan['settings']['changes'] );
	}

	/**
	 * A site that already holds the bundle sees no change.
	 */
	public function test_importing_again_changes_nothing(): void {
		$content  = self::content();
		$projects = array();
		$post_ids = array();
		foreach ( $content['projects'] as $index => $project ) {
			$projects[]                   = self::stored( $project, 50 + $index );
			$post_ids[ $project['slug'] ] = 50 + $index;
		}
		$settings                  = Settings::get();
		$settings['submitFormUrl'] = 'https://forms.example.org/firehouse';

		$plan = Importer::plan( $content, $projects, self::stored_topics( $content, $post_ids ), $settings );

		$this->assertTrue( $plan['ok'] );
		$this->assertSame( array( 'unchanged' ), array_values( array_unique( array_column( array_merge( $plan['projects'], $plan['topics'] ), 'action' ) ) ) );
		$this->assertSame( 'unchanged', $plan['settings']['action'] );
	}

	/**
	 * An existing project's survey fields come from the bundle; its editorial fields,
	 * its geography note included, stay as WordPress has them, and each difference is
	 * noted (rule 4).
	 */
	public function test_an_existing_project_keeps_its_editorial_fields(): void {
		$content                         = self::content();
		$project                         = $content['projects'][2];
		$stored                          = self::stored( $project, 52 );
		$stored['fields']['slug']        = 'renamed-by-an-editor';
		$stored['fields']['summary']     = 'An editor’s summary.';
		$stored['fields']['published']   = false;
		$stored['status']                = 'draft';
		$stored['fields']['geo']['note'] = 'Maui';
		$stored['fields']['title']       = 'An older title';
		$stored['fields']['org']         = 'A field the bundle no longer has';
		unset( $content['projects'][2]['org'] );

		$plan  = Importer::plan( $content, array( $stored ), array(), Settings::get() );
		$entry = $plan['projects'][2];

		$this->assertSame( 'update', $entry['action'] );
		$this->assertSame( 52, $entry['post_id'] );
		$this->assertSame( 'draft', $entry['status'] );
		$this->assertSame( array( 'title', 'org' ), $entry['changes'] );
		$this->assertSame( array( 'title' ), array_keys( $entry['set'] ) );
		$this->assertSame( array( 'org' ), $entry['delete'] );
		$this->assertSame( array( 'post_title' => esc_html( $project['title'] ) ), $entry['post'], 'The label follows the title; the slug and status stay.' );
		$this->assertCount( 4, $entry['notes'], 'Slug, published, summary and geography note.' );
	}

	/**
	 * What stops an import is a problem, and nothing is written while there is one.
	 */
	public function test_problems_stop_the_import(): void {
		$content                        = self::content();
		$content['projects'][0]['id']   = '';
		$content['projects'][1]['id']   = 'p3';
		$content['projects'][4]['slug'] = 'Alpha_Fire';
		$stored_taken                   = self::stored( $content['projects'][3], 90 );
		$stored_taken['fields']['id']   = 'someone-else';
		$stored_taken['fields']['slug'] = 'draft-fuel-study';
		$content['projects'][3]['id']   = 'p4-new';
		$trashed                        = self::stored( $content['projects'][2], 91 );
		$trashed['status']              = 'trash';

		$plan = Importer::plan( $content, array( $stored_taken, $trashed ), array(), Settings::get() );

		$this->assertFalse( $plan['ok'] );
		$this->assertStringContainsString( 'no response ID', $plan['projects'][0]['problems'][0] );
		$this->assertStringContainsString( 'same response ID', $plan['projects'][2]['problems'][0] );
		$this->assertStringContainsString( 'trash', implode( ' ', $plan['projects'][2]['problems'] ) );
		$this->assertStringContainsString( 'already taken', $plan['projects'][3]['problems'][0] );
		$this->assertStringContainsString( 'would change its slug', $plan['projects'][4]['problems'][0] );
		$this->assertSame( array( array( 'slug' => 'draft-fuel-study', 'title' => $content['projects'][3]['title'] ) ), $plan['left_alone'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * A topic the bundle doesn't have, or has twice, stops the import.
	 */
	public function test_each_topic_is_needed_once(): void {
		$content             = self::content();
		$by_key              = array_column( $content['topics'], null, 'key' );
		$content['topics'][] = $by_key['governance'];
		$content['topics']   = array_values( array_filter( $content['topics'], static fn( array $topic ): bool => 'warning' !== $topic['key'] ) );

		$plan     = Importer::plan( $content, array(), array(), Settings::get() );
		$problems = array_combine( array_column( $plan['topics'], 'key' ), array_column( $plan['topics'], 'problems' ) );

		$this->assertFalse( $plan['ok'] );
		$this->assertStringContainsString( 'has 0 topics', $problems['warning'][0] );
		$this->assertStringContainsString( 'has 2 topics', $problems['governance'][0] );
		$this->assertSame( array(), $problems['observe'] );
	}

	/**
	 * Settings the settings screen would refuse stop the import.
	 */
	public function test_bad_settings_stop_the_import(): void {
		$content                              = self::content();
		$content['settings']['submitFormUrl'] = 'http://forms.example.org/firehouse';

		$plan = Importer::plan( $content, array(), array(), Settings::get() );

		$this->assertFalse( $plan['ok'] );
		$this->assertStringContainsString( 'https://', $plan['settings']['problems'][0] );
	}

	/**
	 * The bundle's own notes come first, then the plan's.
	 */
	public function test_the_bundles_notes_are_shown(): void {
		$plan = Importer::plan( self::content(), array(), array(), Settings::get(), array( 'A dropped link.' ) );

		$this->assertSame( 'A dropped link.', $plan['notes'][0] );
		$this->assertStringContainsString( 'Phase 4', end( $plan['notes'] ) );
	}
}
