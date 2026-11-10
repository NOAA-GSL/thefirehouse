<?php
/**
 * Tests for the seed import: its plan, and its run against a stand-in WordPress.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Importer;
use Firehouse\Meta;
use Firehouse\Settings;

/**
 * The dry run says what an import of the shared fixture would do to a site and finds
 * what would stop it; the run writes it and reads it back.
 */
final class ImporterTest extends WordPressTestCase {

	/**
	 * The stand-in WordPress's posts, by ID: what wp_insert_post() was given.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $posts = array();

	/**
	 * The stand-in WordPress's options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * A slug the stand-in WordPress refuses to save.
	 *
	 * @var string
	 */
	private string $refuse = '';

	/**
	 * Stubs the functions planning calls, and a WordPress with no Firehouse content.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->posts   = array();
		$this->options = array();
		$this->refuse  = '';
		self::stand_in_serialize_blocks();
		Functions\when( 'rest_validate_value_from_schema' )->justReturn( true );
		Functions\when( 'sanitize_title' )->alias( static fn( string $title ): string => trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $title ) ), '-' ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn( string $text ): string => trim( $text ) );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url ): mixed => parse_url( $url ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Standing in for wp_parse_url().
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_specialchars_decode' )->alias( static fn( string $text ): string => htmlspecialchars_decode( $text, ENT_QUOTES ) );
		$options = &$this->options;
		Functions\when( 'get_option' )->alias(
			static function ( string $name, mixed $fallback = false ) use ( &$options ): mixed {
				return $options[ $name ] ?? $fallback;
			}
		);
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
		return array(
			'post_id' => $post_id,
			'status'  => $project['published'] ? 'publish' : 'draft',
			'fields'  => array(
				'slug'      => $project['slug'],
				'published' => $project['published'],
			) + array_intersect_key( $project, Meta::fields( 'fh_project' ) ),
		);
	}

	/**
	 * Every topic post, as an import of the fixture would leave it.
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
			$fields             = array_intersect_key( $topic + $summary, Meta::fields( 'fh_topic' ) );
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
	 * Every project and topic of the fixture, stored, and the settings saved as the
	 * bundle has them.
	 *
	 * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: array<string, mixed>}
	 */
	private static function imported(): array {
		$content  = self::content();
		$projects = array();
		$post_ids = array();
		foreach ( $content['projects'] as $index => $project ) {
			$projects[]                   = self::stored( $project, 50 + $index );
			$post_ids[ $project['slug'] ] = 50 + $index;
		}
		$settings                  = Settings::defaults();
		$settings['submitFormUrl'] = 'https://forms.example.org/firehouse';
		return array( $projects, self::stored_topics( $content, $post_ids ), $settings );
	}

	/**
	 * Into an empty site, every project and topic is added, published or a draft as
	 * the bundle has it, with no author and an escaped label for a title, and the
	 * settings are set.
	 */
	public function test_into_an_empty_site_everything_is_added(): void {
		$plan = Importer::plan( self::content(), array(), array(), null );

		$this->assertTrue( $plan['ok'] );
		$this->assertSame( array( 'create' ), array_values( array_unique( array_column( $plan['projects'], 'action' ) ) ) );
		$this->assertSame( array( 'smoke-alerts-for-evacuation', 'national-outlook-study', 'pacific-island-recovery', 'draft-fuel-study', 'alpha-fire-forecasting' ), array_column( $plan['projects'], 'slug' ) );
		$this->assertSame( 'draft', $plan['projects'][3]['post']['post_status'] );
		$this->assertSame( 0, $plan['projects'][0]['post']['post_author'] );
		$this->assertArrayHasKey( 'title', $plan['projects'][0]['set'], 'The title is a field.' );
		$this->assertSame( array( 'observe', 'forecast', 'warning', 'governance' ), array_column( $plan['topics'], 'key' ) );
		$this->assertSame( array( 'create' ), array_values( array_unique( array_column( $plan['topics'], 'action' ) ) ) );
		$this->assertSame( 0, $plan['topics'][0]['post']['post_author'] );
		$this->assertSame( 'update', $plan['settings']['action'] );
		$this->assertSame( array( 'submitFormUrl' ), $plan['settings']['changes'] );
	}

	/**
	 * A topic's label goes into its post's title escaped, as a project's does, so a
	 * theme that prints the title raw can't run markup.
	 */
	public function test_a_topic_label_is_escaped_in_the_post_title(): void {
		$content                       = self::content();
		$content['topics'][0]['label'] = 'Observations <img src=x onerror=alert(1)>';

		$plan  = Importer::plan( $content, array(), array(), null );
		$topic = array_values( array_filter( $plan['topics'], static fn( array $t ): bool => $content['topics'][0]['key'] === $t['key'] ) )[0];

		$this->assertSame( 'Observations &lt;img src=x onerror=alert(1)&gt;', $topic['post']['post_title'] );
	}

	/**
	 * A site that already holds the bundle sees no change.
	 */
	public function test_importing_again_changes_nothing(): void {
		list( $projects, $topics, $settings ) = self::imported();

		$plan = Importer::plan( self::content(), $projects, $topics, $settings );

		$this->assertTrue( $plan['ok'] );
		$this->assertSame( array( 'unchanged' ), array_values( array_unique( array_column( array_merge( $plan['projects'], $plan['topics'] ), 'action' ) ) ) );
		$this->assertSame( 'unchanged', $plan['settings']['action'] );
		$this->assertSame( array(), array_merge( ...array_column( $plan['topics'], 'notes' ) ) );
	}

	/**
	 * Rule 4's editorial fields are the ones an existing project keeps.
	 */
	public function test_the_editorial_fields_are_rule_4s(): void {
		$this->assertSame( array( 'slug', 'published', 'summary', 'publicationStatus', 'fullRecordUrl' ), Importer::EDITORIAL );
		$this->assertSame( array( 'note', 'states' ), Importer::GEO_EDITORIAL );
	}

	/**
	 * An existing project's survey fields come from the bundle, and one the bundle no
	 * longer has is removed; every editorial field, the geography note and states
	 * included, stays as WordPress has it, and each difference is noted (rule 4).
	 */
	public function test_an_existing_project_keeps_its_editorial_fields(): void {
		$content                               = self::content();
		$project                               = $content['projects'][2];
		$stored                                = self::stored( $project, 52 );
		$stored['fields']['slug']              = 'renamed-by-an-editor';
		$stored['fields']['summary']           = 'An editor’s summary.';
		$stored['fields']['published']         = false;
		$stored['status']                      = 'draft';
		$stored['fields']['publicationStatus'] = 'in-review';
		$stored['fields']['fullRecordUrl']     = 'https://example.org/record';
		$stored['fields']['geo']['note']       = 'Maui';
		$stored['fields']['geo']['states']     = array( 'HI' );
		$stored['fields']['title']             = 'An older title';
		$stored['fields']['org']               = 'A field the bundle no longer has';
		unset( $content['projects'][2]['org'] );

		$entry = Importer::plan( $content, array( $stored ), array(), null )['projects'][2];

		$this->assertSame( 'update', $entry['action'] );
		$this->assertSame( 52, $entry['post_id'] );
		$this->assertSame( 'draft', $entry['status'] );
		$this->assertSame( array( 'title' ), $entry['changes'] );
		$this->assertSame( array( 'title' ), array_keys( $entry['set'] ) );
		$this->assertSame( array( 'org' ), $entry['delete'] );
		$this->assertSame( array( 'post_title' => esc_html( $project['title'] ) ), $entry['post'], 'The label follows the title; the slug and status stay.' );
		$this->assertCount( 7, $entry['notes'], 'Slug, published, summary, publication status, full-record link, and the geography note and states.' );
	}

	/**
	 * What stops an import is a problem, and nothing is written while there is one.
	 * A project post with no response ID is left alone, and its slug stays taken.
	 */
	public function test_problems_stop_the_import(): void {
		$content                        = self::content();
		$content['projects'][0]['id']   = '';
		$content['projects'][1]['id']   = 'p3';
		$content['projects'][4]['slug'] = 'Alpha_Fire';
		$stored_taken                   = self::stored( $content['projects'][3], 90 );
		$stored_taken['fields']['id']   = 'someone-else';
		$content['projects'][3]['id']   = 'p4-new';
		$trashed                        = self::stored( $content['projects'][2], 91 );
		$trashed['status']              = 'trash';
		$no_id                          = self::stored( $content['projects'][0], 92 );
		$no_id['fields']['slug']        = 'a-hand-made-project';
		unset( $no_id['fields']['id'] );

		$plan = Importer::plan( $content, array( $stored_taken, $trashed, $no_id ), array(), null );

		$this->assertFalse( $plan['ok'] );
		$this->assertStringContainsString( 'no response ID', $plan['projects'][0]['problems'][0] );
		$this->assertStringContainsString( 'same response ID', $plan['projects'][2]['problems'][0] );
		$this->assertStringContainsString( 'trash', implode( ' ', $plan['projects'][2]['problems'] ) );
		$this->assertStringContainsString( 'already taken', $plan['projects'][3]['problems'][0] );
		$this->assertStringContainsString( 'would change its slug', $plan['projects'][4]['problems'][0] );
		$this->assertSame( array( 'a-hand-made-project', 'draft-fuel-study' ), array_column( $plan['left_alone'], 'slug' ) );
		$this->assertSame( array( false, true ), array_column( $plan['left_alone'], 'has_id' ) );
		$this->assertSame( array( true, false ), array_column( $plan['left_alone'], 'published' ) );
	}

	/**
	 * A problem with the bundle itself stops the import too.
	 */
	public function test_a_problem_with_the_bundle_stops_the_import(): void {
		$plan = Importer::plan( self::content(), array(), array(), null, array(), array( 'projects.0 has a field the plugin doesn’t store: email.' ) );

		$this->assertFalse( $plan['ok'] );
		$this->assertSame( array( 'projects.0 has a field the plugin doesn’t store: email.' ), $plan['problems'] );
	}

	/**
	 * A topic the bundle doesn't have, or has twice, stops the import.
	 */
	public function test_each_topic_is_needed_once(): void {
		$content             = self::content();
		$by_key              = array_column( $content['topics'], null, 'key' );
		$content['topics'][] = $by_key['governance'];
		$content['topics']   = array_values( array_filter( $content['topics'], static fn( array $topic ): bool => 'warning' !== $topic['key'] ) );

		$plan     = Importer::plan( $content, array(), array(), null );
		$problems = array_combine( array_column( $plan['topics'], 'key' ), array_column( $plan['topics'], 'problems' ) );

		$this->assertFalse( $plan['ok'] );
		$this->assertStringContainsString( 'has 0 topics', $problems['warning'][0] );
		$this->assertStringContainsString( 'has 2 topics', $problems['governance'][0] );
		$this->assertSame( array(), $problems['observe'] );
	}

	/**
	 * An existing topic is kept as WordPress has it, its review included, and each
	 * field that differs from the bundle's is named.
	 */
	public function test_an_existing_topic_is_kept(): void {
		list( $projects, $topics, $settings ) = self::imported();
		$topics[0]['fields']['reviewedBy']    = 'A reviewer';
		$topics[0]['fields']['covers']        = array( 'Edited in WordPress' );

		$entry = Importer::plan( self::content(), $projects, $topics, $settings )['topics'][0];

		$this->assertSame( 'unchanged', $entry['action'] );
		$this->assertSame( array(), $entry['set'] );
		$this->assertSame( array(), $entry['delete'] );
		$this->assertStringContainsString( 'covers', $entry['notes'][0] );
		$this->assertStringContainsString( 'reviewedBy', $entry['notes'][0] );
	}

	/**
	 * Saved settings are kept, with a note naming what differs; settings never saved
	 * are set from the bundle, and must pass the settings screen's checks.
	 */
	public function test_settings_are_set_only_once(): void {
		list( $projects, $topics, $settings ) = self::imported();
		$settings['submitLabel']              = 'Share your research';

		$kept = Importer::plan( self::content(), $projects, $topics, $settings )['settings'];
		$this->assertSame( 'unchanged', $kept['action'] );
		$this->assertStringContainsString( 'submitLabel', $kept['notes'][0] );

		$content                              = self::content();
		$content['settings']['submitFormUrl'] = 'http://forms.example.org/firehouse';
		$plan                                 = Importer::plan( $content, array(), array(), null );
		$this->assertFalse( $plan['ok'] );
		$this->assertStringContainsString( 'https://', $plan['settings']['problems'][0] );
		$this->assertTrue( Importer::plan( $content, $projects, $topics, $settings )['ok'], 'Settings that aren’t written aren’t checked.' );
	}

	/**
	 * The bundle's own notes come first, then the plan's.
	 */
	public function test_the_bundles_notes_are_shown(): void {
		$plan = Importer::plan( self::content(), array(), array(), null, array( 'A dropped link.' ) );

		$this->assertSame( 'A dropped link.', $plan['notes'][0] );
		$this->assertStringContainsString( 'About page', end( $plan['notes'] ) );
	}

	/**
	 * With no `firehouse` page, the landing page is planned: a published page at the
	 * top of the tree, with no author, titled with the site's name, its blocks from the
	 * bundle's landing content. A link to the bundle's survey address is stored empty,
	 * so it follows the settings.
	 */
	public function test_the_landing_page_is_created_when_there_is_none(): void {
		$landing = Importer::plan( self::content(), array(), array(), null )['landing'];

		$this->assertSame( 'create', $landing['action'] );
		$this->assertSame(
			array(
				'post_type'   => 'page',
				'post_name'   => 'firehouse',
				'post_parent' => 0,
				'post_status' => 'publish',
				'post_author' => 0,
				'post_title'  => 'The Firehouse',
			),
			array_diff_key( $landing['post'], array( 'post_content' => true ) )
		);
		$blocks = $landing['post']['post_content'];
		$this->assertMatchesRegularExpression( '#^<!-- wp:firehouse/hero \{.*\} --><!-- wp:firehouse/stats \{"items":\[.*\]\} /--><!-- /wp:firehouse/hero --><!-- wp:firehouse/top-needs \{.*\} /--><!-- wp:firehouse/submit-band \{.*\} /-->$#s', $blocks );
		$this->assertStringContainsString( '"primaryCta":{"label":"Submit a Finding","href":""}', $blocks );
		$this->assertStringNotContainsString( 'forms.example.org', $blocks );
		$this->assertSame( array(), $landing['problems'] );
	}

	/**
	 * A `firehouse` page that exists, in any status, is left as it is: its words are the
	 * editors'.
	 */
	public function test_an_existing_landing_page_is_left_alone(): void {
		$landing = Importer::plan( self::content(), array(), array(), null, array(), array(), 77 )['landing'];

		$this->assertSame( 'unchanged', $landing['action'] );
		$this->assertSame( 77, $landing['post_id'] );
		$this->assertSame( array(), $landing['post'] );
		$this->assertStringContainsString( 'already exists', $landing['notes'][0] );
	}

	/**
	 * A run creates the landing page and reads it back; the next finds it and leaves
	 * it alone.
	 */
	public function test_a_run_creates_the_landing_page_once(): void {
		$this->stand_in_wordpress();

		$first = Importer::run( self::content() );
		$id    = $first['landing']['post_id'];

		$this->assertSame( array(), $first['landing']['problems'] );
		$this->assertSame( 'firehouse', $this->posts[ $id ]['post_name'] );
		$this->assertStringStartsWith( '<!-- wp:firehouse/hero ', $this->posts[ $id ]['post_content'] );

		$second = Importer::run( self::content() );
		$this->assertSame( 'unchanged', $second['landing']['action'] );
		$this->assertSame( $id, $second['landing']['post_id'] );
	}

	/**
	 * A landing page WordPress saves at another address, as it would beside a page of
	 * the same name in the trash, is a problem.
	 */
	public function test_a_landing_page_at_another_address_is_a_problem(): void {
		$this->stand_in_wordpress();
		$posts = &$this->posts;
		Functions\when( 'wp_insert_post' )->alias(
			static function ( array $postarr ) use ( &$posts ): int {
				$id           = 200 + count( $posts );
				$posts[ $id ] = array( 'post_name' => 'page' === $postarr['post_type'] ? 'firehouse-2' : $postarr['post_name'] ) + $postarr;
				return $id;
			}
		);

		$landing = Importer::run( self::content() )['landing'];

		$this->assertStringContainsString( 'isn’t at /firehouse/', $landing['problems'][0] );
	}

	/**
	 * The fingerprint changes when what the plan would do changes, and only then.
	 */
	public function test_the_fingerprint_follows_what_the_plan_would_do(): void {
		list( $projects, $topics, $settings ) = self::imported();
		$plan                                 = Importer::plan( self::content(), $projects, $topics, $settings );
		$again                                = Importer::plan( self::content(), $projects, $topics, $settings, array( 'A different note.' ) );
		$projects[0]['fields']['title']       = 'Changed in the meantime';
		$changed                              = Importer::plan( self::content(), $projects, $topics, $settings );

		$this->assertSame( Importer::fingerprint( $plan ), Importer::fingerprint( $again ) );
		$this->assertNotSame( Importer::fingerprint( $plan ), Importer::fingerprint( $changed ) );
	}

	/**
	 * Every write the stand-in WordPress was asked for, as "insert", "update",
	 * "delete" or "option".
	 *
	 * @var list<string>
	 */
	private array $writes = array();

	/**
	 * Whether the stand-in WordPress ignores deletions, as a broken save would.
	 *
	 * @var bool
	 */
	private bool $ignore_deletes = false;

	/**
	 * A post status the stand-in WordPress saves instead of the one given, or ''.
	 *
	 * @var string
	 */
	private string $status_instead = '';

	/**
	 * A post as the stand-in WordPress returns it.
	 *
	 * @param int                  $id   The post's ID.
	 * @param array<string, mixed> $post What was saved.
	 */
	private static function as_post( int $id, array $post ): \WP_Post {
		return new \WP_Post(
			array(
				'ID'           => $id,
				'post_type'    => $post['post_type'],
				'post_name'    => $post['post_name'],
				'post_status'  => $post['post_status'],
				'post_title'   => $post['post_title'],
				'post_parent'  => $post['post_parent'] ?? 0,
				'post_content' => $post['post_content'] ?? '',
			)
		);
	}

	/**
	 * Stands in for WordPress's posts and options: wp_insert_post() and
	 * wp_update_post() keep what they are given, and get_posts(), get_post() and the
	 * meta functions read it back.
	 */
	private function stand_in_wordpress(): void {
		$this->writes = array();
		$posts        = &$this->posts;
		$options      = &$this->options;
		$refuse       = &$this->refuse;
		$writes       = &$this->writes;
		$ignore       = &$this->ignore_deletes;
		$instead      = &$this->status_instead;
		Functions\when( 'get_posts' )->alias(
			static function ( array $args ) use ( &$posts ): array {
				$found = array();
				foreach ( $posts as $id => $post ) {
					if ( $args['post_type'] === $post['post_type'] && ( ! isset( $args['name'] ) || ( $args['name'] === $post['post_name'] && ( $post['post_parent'] ?? 0 ) === $args['post_parent'] ) ) ) {
						$found[] = 'ids' === ( $args['fields'] ?? '' ) ? $id : self::as_post( $id, $post );
					}
				}
				return $found;
			}
		);
		Functions\when( 'wp_slash' )->returnArg();
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'kses_remove_filters' )->justReturn();
		Functions\when( 'update_option' )->alias(
			static function ( string $name, mixed $value ) use ( &$options, &$writes ): bool {
				$writes[]         = 'option';
				$options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_insert_post' )->alias(
			static function ( array $postarr ) use ( &$posts, &$refuse, &$writes, &$instead ): int|\WP_Error {
				$writes[] = 'insert';
				if ( $refuse === $postarr['post_name'] ) {
					return new \WP_Error( 'db_insert_error', 'Could not insert post into the database.' );
				}
				if ( '' !== $instead ) {
					$postarr['post_status'] = $instead;
				}
				$id           = 200 + count( $posts );
				$posts[ $id ] = $postarr;
				return $id;
			}
		);
		Functions\when( 'wp_update_post' )->alias(
			static function ( array $postarr ) use ( &$posts, &$writes ): int {
				$writes[]                   = 'update';
				$id                         = $postarr['ID'];
				$meta                       = $postarr['meta_input'] + ( $posts[ $id ]['meta_input'] ?? array() );
				$posts[ $id ]               = array_merge( $posts[ $id ], array_diff_key( $postarr, array_flip( array( 'ID', 'meta_input' ) ) ) );
				$posts[ $id ]['meta_input'] = $meta;
				return $id;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			static function ( int $id, string $key ) use ( &$posts, &$writes, &$ignore ): bool {
				$writes[] = 'delete';
				if ( ! $ignore ) {
					unset( $posts[ $id ]['meta_input'][ $key ] );
				}
				return true;
			}
		);
		Functions\when( 'get_post' )->alias(
			static function ( mixed $id ) use ( &$posts ): ?\WP_Post {
				$id = (int) $id;
				return isset( $posts[ $id ] ) ? self::as_post( $id, $posts[ $id ] ) : null;
			}
		);
		Functions\when( 'metadata_exists' )->alias(
			static function ( string $type, int $id, string $key ) use ( &$posts ): bool {
				return array_key_exists( $key, $posts[ $id ]['meta_input'] ?? array() );
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $id, string $key ) use ( &$posts ): mixed {
				return $posts[ $id ]['meta_input'][ $key ] ?? '';
			}
		);
	}

	/**
	 * Into an empty site, the run writes every project, every topic with each need's
	 * projects as post IDs, and the settings, and reads them all back.
	 */
	public function test_a_run_writes_everything_and_reads_it_back(): void {
		$this->stand_in_wordpress();

		$result = Importer::run( self::content() );

		$this->assertTrue( $result['imported'] );
		$this->assertSame( array(), array_merge( $result['settings']['problems'], ...array_column( $result['projects'], 'problems' ), ...array_column( $result['topics'], 'problems' ) ) );
		$ids = array_column( $result['projects'], 'post_id', 'slug' );
		$this->assertNotContains( 0, $ids );
		$observe = $this->posts[ $result['topics'][0]['post_id'] ];
		$this->assertSame( 'observe', $observe['post_name'] );
		$this->assertSame( array( $ids['smoke-alerts-for-evacuation'] ), $observe['meta_input']['fh_topNeeds'][0]['projects'] );
		$this->assertSame( 'https://forms.example.org/firehouse', $this->options['firehouse_settings']['submitFormUrl'] );
	}

	/**
	 * A project WordPress refuses is a problem, and a topic whose needs name it isn't
	 * created rather than created with a broken link; the rest is written.
	 */
	public function test_a_refused_project_keeps_the_topics_naming_it_back(): void {
		$this->stand_in_wordpress();
		$this->refuse = 'smoke-alerts-for-evacuation';

		$result = Importer::run( self::content() );
		$topics = array_column( $result['topics'], null, 'key' );

		$this->assertTrue( $result['imported'] );
		$this->assertStringContainsString( 'didn’t save it', $result['projects'][0]['problems'][0] );
		$this->assertStringContainsString( 'names a project WordPress didn’t save', $topics['observe']['problems'][0] );
		$this->assertSame( 0, $topics['observe']['post_id'] );
		$this->assertNotSame( 0, $topics['forecast']['post_id'] );
	}

	/**
	 * Something that reads back differently from what was written is a problem.
	 */
	public function test_a_difference_on_reading_back_is_a_problem(): void {
		$this->stand_in_wordpress();
		$posts = &$this->posts;
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $id, string $key ) use ( &$posts ): mixed {
				return 'fh_title' === $key ? 'Changed on the way in' : ( $posts[ $id ]['meta_input'][ $key ] ?? '' );
			}
		);

		$result = Importer::run( self::content() );

		$this->assertStringContainsString( 'reads back differently from what was imported: title', $result['projects'][0]['problems'][0] );
	}

	/**
	 * A second run of the same bundle writes nothing at all: no post, no field, no
	 * setting. Topics and settings that exist are never written again.
	 */
	public function test_a_second_run_writes_nothing(): void {
		$this->stand_in_wordpress();
		Importer::run( self::content() );
		$this->writes = array();

		$result = Importer::run( self::content() );

		$this->assertTrue( $result['imported'] );
		$this->assertSame( array(), $this->writes );
		$this->assertSame( array( 'unchanged' ), array_values( array_unique( array_column( array_merge( $result['projects'], $result['topics'] ), 'action' ) ) ) );
		$this->assertSame( 'unchanged', $result['settings']['action'] );
	}

	/**
	 * A survey field the bundle no longer has is removed; if it reads back as still
	 * there, that is a problem.
	 */
	public function test_a_field_left_behind_is_a_problem(): void {
		$this->stand_in_wordpress();
		Importer::run( self::content() );
		$content = self::content();
		unset( $content['projects'][0]['methods'] );
		$this->ignore_deletes = true;

		$result = Importer::run( $content );

		$this->assertSame( array( 'methods' ), $result['projects'][0]['delete'] );
		$this->assertStringContainsString( 'methods (still there)', $result['projects'][0]['problems'][0] );
	}

	/**
	 * A post that reads back with a status other than the one written is a problem.
	 */
	public function test_a_wrong_status_is_a_problem(): void {
		$this->stand_in_wordpress();
		$this->status_instead = 'pending';

		$result = Importer::run( self::content() );

		$this->assertStringContainsString( 'post_status', $result['projects'][0]['problems'][0] );
	}
}
