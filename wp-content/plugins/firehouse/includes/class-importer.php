<?php
/**
 * The seed import: what it would change, and the change.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

use Firehouse\Domain\Vocabulary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads a seed bundle's content into WordPress, after a dry run that says what it
 * would change. An import creates what is missing and refreshes the survey's words;
 * whatever editors and reviewers own, it writes only the first time:
 *
 * - **Projects** are matched by response ID (`id`), so running it again updates the
 *   same posts. A new project arrives whole, published or a draft as the bundle has
 *   it. An existing one takes the bundle's survey fields, a field the bundle no longer
 *   has is removed, and it keeps its editorial fields (rule 4): its slug, whether it
 *   is published, its summary, publication status, full-record link, and the parts of
 *   its geography the survey doesn't ask for (the note and states).
 * - **Topics** are the four posts keyed by topic key. A missing one is created with
 *   its fields and synthesis, each need's projects as post IDs (rule 5). An existing
 *   one is left as it is: a synthesis changes through review, not the seed.
 * - **Settings** are written only if they have never been saved.
 * - **The landing page**, the `firehouse` page at the top of the Firehouse tree, is
 *   created from the bundle's landing content, as the landing page's blocks
 *   (Landing_Page::blocks()), only if there's no such page yet in any status but the
 *   trash: after that its words are the editors'. One in the trash, or an attachment
 *   or page holding the address, stops the import, since the new page would land at
 *   another address; one created at another address anyway is deleted, not left
 *   published.
 * - **The About page**, the `about` page under it, is created the same way from the
 *   bundle's about content (About_Page::blocks()), once the landing page it goes under
 *   exists and has read back as planned. One in the trash under the landing page, or
 *   an attachment of the landing page holding the address, stops the import.
 *
 * Every difference kept from WordPress is noted. Nothing is written while anything is
 * wrong, and the dry run lists every problem. Projects in WordPress that the bundle
 * doesn't have are left as they are. New posts have no author: the researcher's work
 * isn't credited to whoever imported it.
 */
final class Importer {

	/**
	 * A project's editorial fields: an existing project keeps WordPress's.
	 */
	public const EDITORIAL = array( 'slug', 'published', 'summary', 'publicationStatus', 'fullRecordUrl' );

	/**
	 * The parts of a project's geography the survey doesn't ask for, which an existing
	 * project keeps.
	 */
	public const GEO_EDITORIAL = array( 'note', 'states' );

	/**
	 * Slugs WordPress won't give a project, because its addresses use them.
	 */
	private const RESERVED_SLUGS = array( 'feed', 'rdf', 'rss', 'rss2', 'atom', 'embed' );

	/**
	 * What importing this content would change, against what WordPress holds now.
	 *
	 * @param array<string, mixed> $content  Normalized content, from Seed_Bundle::read().
	 * @param string[]             $notes    Seed_Bundle::read()'s notes about the bundle.
	 * @param string[]             $problems Seed_Bundle::read()'s problems with the bundle.
	 * @return array<string, mixed> The plan; see plan().
	 */
	public static function preview( array $content, array $notes = array(), array $problems = array() ): array {
		$saved = false !== get_option( Settings::OPTION, false ) ? Settings::get() : null;
		return self::plan( $content, Store::projects(), Store::topics(), $saved, $notes, $problems, Capabilities::root_id() );
	}

	/**
	 * Imports the content, if the dry run finds nothing wrong, and checks everything
	 * it wrote by reading it back.
	 *
	 * Writing stops at nothing short of a problem: a save WordPress refuses is listed,
	 * the rest still happens, and importing the same bundle again finishes the job.
	 *
	 * @param array<string, mixed> $content  Normalized content, from Seed_Bundle::read().
	 * @param string[]             $notes    Seed_Bundle::read()'s notes about the bundle.
	 * @param string[]             $problems Seed_Bundle::read()'s problems with the bundle.
	 * @return array<string, mixed> The plan, with each post's ID, `imported`, and any
	 *                              write that failed or read back differently as a problem.
	 */
	public static function run( array $content, array $notes = array(), array $problems = array() ): array {
		$plan = self::preview( $content, $notes, $problems );
		if ( ! $plan['ok'] ) {
			return $plan + array( 'imported' => false );
		}

		$ids    = array();
		$failed = array();
		foreach ( $plan['projects'] as $index => $project ) {
			if ( 'unchanged' !== $project['action'] ) {
				$saved = Store::save( Post_Types::PROJECT, $project['post_id'], $project['post'], $project['set'], $project['delete'] );
				if ( is_wp_error( $saved ) ) {
					$plan['projects'][ $index ]['problems'][] = 'WordPress didn’t save it: ' . $saved->get_error_message();
					$failed[ $project['slug'] ]               = true;
					continue;
				}
				$plan['projects'][ $index ]['post_id'] = $saved;
			}
			$ids[ $project['slug'] ] = $plan['projects'][ $index ]['post_id'];
		}

		foreach ( $plan['topics'] as $index => $topic ) {
			if ( 'create' !== $topic['action'] ) {
				continue;
			}
			$set = $topic['set'];
			if ( array() !== array_intersect_key( $failed, array_flip( self::needed_projects( $set['topNeeds'] ) ) ) ) {
				$plan['topics'][ $index ]['problems'][] = 'A need names a project WordPress didn’t save, so the topic wasn’t created. Import again once the project saves.';
				continue;
			}
			$set['topNeeds'] = self::needs_by_id( $set['topNeeds'], $ids );
			$saved           = Store::save( Post_Types::TOPIC, 0, $topic['post'], $set, array() );
			if ( is_wp_error( $saved ) ) {
				$plan['topics'][ $index ]['problems'][] = 'WordPress didn’t save it: ' . $saved->get_error_message();
				continue;
			}
			$plan['topics'][ $index ]['post_id'] = $saved;
			$plan['topics'][ $index ]['set']     = $set;
		}

		if ( 'update' === $plan['settings']['action'] ) {
			update_option( Settings::OPTION, $plan['settings']['value'] );
		}

		if ( 'create' === $plan['landing']['action'] ) {
			$saved = self::create_page( $plan['landing']['post'] );
			if ( is_wp_error( $saved ) ) {
				$plan['landing']['problems'][] = 'WordPress didn’t save it: ' . $saved->get_error_message();
			} else {
				$plan['landing']['post_id'] = $saved;
			}
		}

		$plan            = self::verify( $plan );
		$plan['landing'] = self::discard( $plan['landing'], 'landing page' );

		// The About page goes under the landing page as it now stands: not under one
		// that was just deleted, whose pages WordPress would move to the top of the site.
		if ( 'create' === $plan['about']['action'] ) {
			$plan['about'] = self::discard( self::create_about( $plan['about'], $plan['landing'] ), 'About page' );
		}
		return array_merge( $plan, array( 'imported' => true ) );
	}

	/**
	 * Creates the About page under the landing page, if the landing page is there, and
	 * reads it back: under the landing page, at its address, with its blocks, published.
	 *
	 * @param array<string, mixed> $about   The About page's plan.
	 * @param array<string, mixed> $landing The landing page's, after the run.
	 * @return array<string, mixed> The About page's plan, with its ID and any problem.
	 */
	private static function create_about( array $about, array $landing ): array {
		$parent = array() === $landing['problems'] ? (int) $landing['post_id'] : 0;
		if ( 0 === $parent ) {
			$about['problems'][] = 'It wasn’t created, since the landing page it goes under wasn’t. Fix the landing page’s problem, then import again.';
			return $about;
		}
		$about['post']['post_parent'] = $parent;
		$saved                        = self::create_page( $about['post'] );
		if ( is_wp_error( $saved ) ) {
			$about['problems'][] = 'WordPress didn’t save it: ' . $saved->get_error_message();
			return $about;
		}
		$about['post_id'] = $saved;
		$page             = get_post( $saved );
		if ( ! $page instanceof \WP_Post ) {
			$about['problems'][] = 'It can’t be read back.';
		} elseif ( $parent !== $page->post_parent || About_Page::SLUG !== $page->post_name ) {
			$about['problems'][] = 'It isn’t at /' . Query::ROOT_PATH . '/' . About_Page::SLUG . '/: WordPress gave it the address /' . get_page_uri( $page ) . '/.';
		} elseif ( $page->post_content !== $about['post']['post_content'] || 'publish' !== $page->post_status ) {
			$about['problems'][] = 'It reads back differently from what was imported.';
		}
		return $about;
	}

	/**
	 * Deletes a page the run created that isn't as planned, so it isn't left published,
	 * and says so; or says WordPress refused, with the page's ID.
	 *
	 * @param array<string, mixed> $page The page's plan, after the run.
	 * @param string               $name What the page is, as the message names it.
	 * @return array<string, mixed>
	 */
	private static function discard( array $page, string $name ): array {
		if ( 'create' !== $page['action'] || 0 === $page['post_id'] || array() === $page['problems'] ) {
			return $page;
		}
		if ( wp_delete_post( $page['post_id'], true ) ) {
			$page['problems'][] = sprintf( 'It was deleted, so the Firehouse has no %s yet. Fix the problem above, then import again.', $name );
			$page['post_id']    = 0;
		} else {
			$page['problems'][] = sprintf( 'WordPress didn’t delete it, so it is still published: delete the page with ID %d from the Pages list, then import again.', $page['post_id'] );
		}
		return $page;
	}

	/**
	 * Creates a page as planned: published, with no author, its blocks as planned. The
	 * kses filters are off while it's written, as for the plugin's other writes, so the
	 * blocks' attributes are saved exactly as serialize_blocks() wrote them, whoever runs
	 * the import.
	 *
	 * @param array<string, mixed> $post The page, as wp_insert_post() takes it.
	 * @return int|\WP_Error The page's ID, or why it wasn't saved.
	 */
	private static function create_page( array $post ): int|\WP_Error {
		$kses = (bool) has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		kses_remove_filters();
		try {
			return wp_insert_post( wp_slash( $post ), true );
		} finally {
			if ( $kses ) {
				kses_init_filters();
			}
		}
	}

	/**
	 * A short fingerprint of what a plan would do, so the Import screen can tell
	 * whether WordPress changed between the dry run and the confirmation.
	 *
	 * @param array<string, mixed> $plan The plan.
	 */
	public static function fingerprint( array $plan ): string {
		$summary = array();
		foreach ( array( 'projects', 'topics' ) as $list ) {
			foreach ( $plan[ $list ] as $entry ) {
				$summary[] = array( $entry['action'], $entry['post_id'], $entry['changes'], $entry['delete'] );
			}
		}
		$summary[] = array( $plan['settings']['action'], $plan['settings']['changes'] );
		$summary[] = array( $plan['landing']['action'], $plan['landing']['post_id'] );
		$summary[] = array( $plan['about']['action'], $plan['about']['post_id'] );
		return hash( 'sha256', serialize( $summary ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Hashed, never stored or unserialized.
	}

	/**
	 * Plans an import of the content into what WordPress holds.
	 *
	 * Each project and topic has an `action` (create, update or unchanged), the fields
	 * it would change and remove, `problems` that stop the import and `notes` that
	 * don't. `ok` is whether nothing anywhere stops it.
	 *
	 * @param array<string, mixed>       $content  Normalized content.
	 * @param list<array<string, mixed>> $projects The project posts, from Store::projects().
	 * @param list<array<string, mixed>> $topics   The topic posts, from Store::topics().
	 * @param array<string, mixed>|null  $settings The saved settings, or null if never saved.
	 * @param string[]                   $notes    Notes about the bundle itself.
	 * @param string[]                   $problems Problems with the bundle itself.
	 * @param int                        $root     The `firehouse` page's ID, from Capabilities::root_id(), or 0 for none.
	 * @return array{ok: bool, problems: list<string>, projects: list<array<string, mixed>>, topics: list<array<string, mixed>>, settings: array<string, mixed>, landing: array<string, mixed>, about: array<string, mixed>, left_alone: list<array<string, mixed>>, notes: list<string>}
	 */
	public static function plan( array $content, array $projects, array $topics, ?array $settings, array $notes = array(), array $problems = array(), int $root = 0 ): array {
		$by_id      = array();
		$repeats    = array();
		$slugs      = array();
		$left_alone = array();
		foreach ( $projects as $stored ) {
			$id = $stored['fields']['id'] ?? null;
			if ( is_string( $id ) && '' !== $id ) {
				if ( isset( $by_id[ $id ] ) ) {
					$repeats[ $id ] = true;
				}
				$by_id[ $id ] = $stored;
			} else {
				$left_alone[] = self::left_alone( $stored, false );
			}
			$slugs[ $stored['fields']['slug'] ] = true;
		}

		$planned = array();
		$seen    = array();
		foreach ( $content['projects'] as $project ) {
			$planned[] = self::plan_project( $project, $by_id, $repeats, $slugs, $seen );
		}
		foreach ( $by_id as $id => $stored ) {
			if ( ! isset( $seen[ $id ] ) ) {
				$left_alone[] = self::left_alone( $stored, true );
			}
		}

		$post_ids = array();
		foreach ( $planned as $project ) {
			$post_ids[ $project['slug'] ] = $project['post_id'];
		}

		$settings_plan = self::plan_settings( $content['settings'], $settings );
		$plan          = array(
			'problems'   => array_values( $problems ),
			'projects'   => $planned,
			'topics'     => self::plan_topics( $content, $topics, $post_ids ),
			'settings'   => $settings_plan,
			'landing'    => self::plan_landing( $content, $root ),
			'about'      => self::plan_about( $content, $root, (string) ( $settings_plan['value']['navLabels']['about'] ?? '' ) ),
			'left_alone' => $left_alone,
			'notes'      => array_values( $notes ),
		);

		$all        = array_merge(
			$plan['problems'],
			$plan['settings']['problems'],
			$plan['landing']['problems'],
			$plan['about']['problems'],
			...array_column( $plan['projects'], 'problems' ),
			...array_column( $plan['topics'], 'problems' )
		);
		$plan['ok'] = array() === $all;
		return $plan;
	}

	/**
	 * Plans one project.
	 *
	 * @param array<string, mixed>                $project One project from the bundle.
	 * @param array<string, array<string, mixed>> $by_id   Project posts by response ID.
	 * @param array<string, true>                 $repeats Response IDs on more than one post.
	 * @param array<string, true>                 $slugs   Slugs project posts have.
	 * @param array<string, true>                 $seen    Response IDs planned so far; this one is added.
	 * @return array<string, mixed>
	 */
	private static function plan_project( array $project, array $by_id, array $repeats, array $slugs, array &$seen ): array {
		$id    = $project['id'] ?? null;
		$title = $project['title'] ?? null;
		$entry = array(
			'id'       => is_string( $id ) ? $id : '',
			'slug'     => $project['slug'],
			'title'    => is_string( $title ) ? $title : '',
			'status'   => $project['published'] ? 'publish' : 'draft',
			'action'   => 'create',
			'post_id'  => 0,
			'changes'  => array(),
			'problems' => array(),
			'notes'    => array(),
			'post'     => array(),
			'set'      => array(),
			'delete'   => array(),
		);

		if ( '' === $entry['id'] ) {
			$entry['problems'][] = 'It has no response ID, which a later import matches it by.';
			return $entry;
		}
		if ( isset( $seen[ $entry['id'] ] ) ) {
			$entry['problems'][] = 'Another project in the bundle has the same response ID.';
		}
		$seen[ $entry['id'] ] = true;
		if ( isset( $repeats[ $entry['id'] ] ) ) {
			$entry['problems'][] = 'More than one project in WordPress has this response ID.';
		}
		if ( '' === trim( $entry['title'] ) ) {
			$entry['problems'][] = 'It has no title.';
		}

		$fields = self::fields( $project, Post_Types::PROJECT );
		foreach ( $fields as $name => $value ) {
			$valid = Meta::validate( Post_Types::PROJECT, $name, $value );
			if ( is_wp_error( $valid ) ) {
				$entry['problems'][] = sprintf( '%s: %s', $name, $valid->get_error_message() );
			}
		}

		$stored = $by_id[ $entry['id'] ] ?? null;
		if ( null === $stored ) {
			if ( sanitize_title( $entry['slug'] ) !== $entry['slug'] ) {
				$entry['problems'][] = sprintf( 'WordPress would change its slug, “%s”, which is its address.', $entry['slug'] );
			} elseif ( isset( $slugs[ $entry['slug'] ] ) || in_array( $entry['slug'], self::RESERVED_SLUGS, true ) ) {
				$entry['problems'][] = sprintf( 'Its slug, “%s”, is already taken in WordPress.', $entry['slug'] );
			}
			$entry['post'] = array(
				'post_title'  => Store::label( $entry['title'] ),
				'post_name'   => $entry['slug'],
				'post_status' => $entry['status'],
				'post_author' => 0,
			);
			$entry['set']  = $fields;
			return $entry;
		}

		$entry['post_id'] = $stored['post_id'];
		$entry['status']  = $stored['status'];
		if ( 'trash' === $stored['status'] ) {
			$entry['problems'][] = 'It is in the trash in WordPress. Restore it or delete it permanently, then import again.';
		}

		$survey     = array_diff_key( $fields, array_flip( self::EDITORIAL ) );
		$stored_geo = is_array( $stored['fields']['geo'] ?? null ) ? $stored['fields']['geo'] : array();
		$kept_geo   = array_intersect_key( $stored_geo, array_flip( self::GEO_EDITORIAL ) );
		if ( isset( $survey['geo'] ) ) {
			$survey['geo'] = array_diff_key( $survey['geo'], array_flip( self::GEO_EDITORIAL ) );
		}
		if ( array() !== $kept_geo ) {
			$survey['geo'] = ( $survey['geo'] ?? array( 'regions' => array() ) ) + $kept_geo;
		}

		foreach ( $survey as $name => $value ) {
			if ( ! array_key_exists( $name, $stored['fields'] ) || ! self::same( $value, $stored['fields'][ $name ] ) ) {
				$entry['set'][ $name ] = $value;
				$entry['changes'][]    = $name;
			}
		}
		if ( isset( $entry['set']['title'] ) ) {
			$entry['post']['post_title'] = Store::label( $entry['title'] );
		}
		foreach ( array_keys( Meta::fields( Post_Types::PROJECT ) ) as $name ) {
			if ( ! in_array( $name, self::EDITORIAL, true ) && ! array_key_exists( $name, $survey ) && array_key_exists( $name, $stored['fields'] ) ) {
				$entry['delete'][] = $name;
			}
		}
		$entry['action'] = array() === $entry['changes'] && array() === $entry['delete'] ? 'unchanged' : 'update';

		foreach ( self::EDITORIAL as $name ) {
			$ours   = in_array( $name, array( 'slug', 'published' ), true ) ? $project[ $name ] : ( $fields[ $name ] ?? null );
			$theirs = $stored['fields'][ $name ] ?? null;
			if ( ! self::same( $ours, $theirs ) ) {
				$entry['notes'][] = sprintf( 'It keeps WordPress’s %s, which differs from the bundle’s.', $name );
			}
		}
		foreach ( self::GEO_EDITORIAL as $name ) {
			if ( ! self::same( $project['geo'][ $name ] ?? null, $stored_geo[ $name ] ?? null ) ) {
				$entry['notes'][] = sprintf( 'It keeps WordPress’s geography %s, which differs from the bundle’s.', $name );
			}
		}
		return $entry;
	}

	/**
	 * Plans the four topics: a missing one is created, and an existing one is kept,
	 * with a note naming each field that differs from the bundle's.
	 *
	 * @param array<string, mixed>       $content  Normalized content.
	 * @param list<array<string, mixed>> $topics   The topic posts.
	 * @param array<string, int>         $post_ids Each bundle project's post ID by slug, 0 for a new one.
	 * @return list<array<string, mixed>>
	 */
	private static function plan_topics( array $content, array $topics, array $post_ids ): array {
		$planned = array();
		foreach ( Vocabulary::topics() as $key ) {
			$entries   = array_values( array_filter( $content['topics'], static fn( array $topic ): bool => $key === $topic['key'] ) );
			$summaries = array_values( array_filter( $content['topicSummaries'], static fn( array $summary ): bool => $key === $summary['topic'] ) );
			$entry     = array(
				'key'      => $key,
				'label'    => is_string( $entries[0]['label'] ?? null ) ? $entries[0]['label'] : '',
				'action'   => 'create',
				'post_id'  => 0,
				'changes'  => array(),
				'problems' => array(),
				'notes'    => array(),
				'post'     => array(),
				'set'      => array(),
				'delete'   => array(),
			);
			if ( 1 !== count( $entries ) ) {
				$entry['problems'][] = sprintf( 'The bundle has %d topics with this key; it needs exactly one.', count( $entries ) );
			}
			if ( count( $summaries ) > 1 ) {
				$entry['problems'][] = sprintf( 'The bundle has %d syntheses for this topic; it can have one.', count( $summaries ) );
			}
			if ( '' === trim( $entry['label'] ) ) {
				$entry['problems'][] = 'It has no label.';
			}

			$fields = self::fields( ( $entries[0] ?? array() ) + ( $summaries[0] ?? array() ), Post_Types::TOPIC );
			if ( ! isset( $fields['topNeeds'] ) ) {
				$fields['topNeeds'] = array();
			}
			$by_id = self::needs_by_id( $fields['topNeeds'], $post_ids );
			foreach ( array_merge( $fields, array( 'topNeeds' => $by_id ) ) as $name => $value ) {
				$valid = Meta::validate( Post_Types::TOPIC, $name, $value );
				if ( is_wp_error( $valid ) ) {
					$entry['problems'][] = sprintf( '%s: %s', $name, $valid->get_error_message() );
				}
			}

			$stored = null;
			foreach ( $topics as $topic ) {
				if ( $key === $topic['key'] ) {
					$stored = $topic;
					break;
				}
			}
			if ( null === $stored ) {
				$entry['post'] = array(
					'post_title'  => Store::label( $entry['label'] ),
					'post_name'   => $key,
					'post_status' => 'publish',
					'post_author' => 0,
				);
				$entry['set']  = $fields;
				$planned[]     = $entry;
				continue;
			}

			$entry['action']  = 'unchanged';
			$entry['post_id'] = $stored['post_id'];
			$differ           = array();
			foreach ( array( 'label' => $entry['label'] ) + $fields + $stored['fields'] as $name => $unused ) {
				$ours = 'label' === $name ? $entry['label'] : ( 'topNeeds' === $name ? $by_id : ( $fields[ $name ] ?? null ) );
				if ( ! self::same( $ours, $stored['fields'][ $name ] ?? null ) ) {
					$differ[] = $name;
				}
			}
			if ( array() !== $differ ) {
				$entry['notes'][] = sprintf( 'It keeps WordPress’s %s. A synthesis changes through review, not the seed.', self::differing( $differ ) );
			}
			$planned[] = $entry;
		}
		return $planned;
	}

	/**
	 * Plans the settings: the bundle's, checked as the settings screen checks them, if
	 * they have never been saved; otherwise WordPress's, with a note naming each that
	 * differs. A navigation link the bundle doesn't have keeps its default label.
	 *
	 * @param array<string, mixed>      $bundle The bundle's settings.
	 * @param array<string, mixed>|null $saved  The saved settings, or null if never saved.
	 * @return array{action: string, changes: list<string>, problems: list<string>, notes: list<string>, value: array<string, mixed>}
	 */
	private static function plan_settings( array $bundle, ?array $saved ): array {
		$current = $saved ?? Settings::defaults();
		$labels  = $current['navLabels'];
		foreach ( is_array( $bundle['nav'] ?? null ) ? $bundle['nav'] : array() as $link ) {
			$key = is_array( $link ) ? array_search( $link['to'] ?? null, Settings::NAV, true ) : false;
			if ( false !== $key && is_string( $link['label'] ?? null ) ) {
				$labels[ $key ] = $link['label'];
			}
		}
		$input                  = array(
			'siteName'      => $bundle['siteName'] ?? null,
			'submitLabel'   => $bundle['submitLabel'] ?? null,
			'submitFormUrl' => $bundle['submitFormUrl'] ?? null,
			'navLabels'     => $labels,
		);
		list( $value, $errors ) = Settings::validate( $input );

		$differ = array();
		foreach ( $value as $key => $setting ) {
			if ( ! self::same( $setting, $current[ $key ] ?? null ) ) {
				$differ[] = $key;
			}
		}

		if ( null !== $saved ) {
			// Not written, so not a problem; but a value the settings screen would refuse
			// is still worth saying, since Settings::validate() keeps WordPress's instead.
			$notes = array() === $differ ? array() : array( sprintf( 'The settings keep WordPress’s %s.', self::differing( $differ ) ) );
			foreach ( $errors as $error ) {
				$notes[] = 'The bundle’s settings would be refused: ' . $error;
			}
			return array(
				'action'   => 'unchanged',
				'changes'  => array(),
				'problems' => array(),
				'notes'    => $notes,
				'value'    => $saved,
			);
		}
		return array(
			'action'   => 'update',
			'changes'  => $differ,
			'problems' => array_values( $errors ),
			'notes'    => array(),
			'value'    => $value,
		);
	}

	/**
	 * Fields that differ from the bundle's, as a phrase: "covers, which differs from
	 * the bundle's", or "covers and reviewedBy, which differ from the bundle's".
	 *
	 * @param string[] $fields The fields.
	 */
	private static function differing( array $fields ): string {
		if ( 1 === count( $fields ) ) {
			return $fields[0] . ', which differs from the bundle’s';
		}
		$last = array_pop( $fields );
		return implode( ', ', $fields ) . ' and ' . $last . ', which differ from the bundle’s';
	}

	/**
	 * A project post the import leaves as it is, for the report.
	 *
	 * @param array<string, mixed> $stored     The post, from Store::projects().
	 * @param bool                 $has_id     Whether it has a response ID.
	 * @return array{slug: string, title: string, published: bool, has_id: bool}
	 */
	private static function left_alone( array $stored, bool $has_id ): array {
		return array(
			'slug'      => (string) $stored['fields']['slug'],
			'title'     => (string) ( $stored['fields']['title'] ?? '' ),
			'published' => 'publish' === $stored['status'],
			'has_id'    => $has_id,
		);
	}

	/**
	 * Plans the landing page: created from the bundle's landing content if there's no
	 * `firehouse` page in any status but the trash; otherwise left as it is, its words
	 * the editors'. A trashed one, or anything else holding the address, is a problem.
	 * Its title is the bundle's site name, which WordPress's lists and the page's
	 * `<title>` show.
	 *
	 * @param array<string, mixed> $content Normalized content.
	 * @param int                  $root    The `firehouse` page's ID, or 0 for none.
	 * @return array{action: string, post_id: int, post: array<string, mixed>, problems: list<string>, notes: list<string>}
	 */
	private static function plan_landing( array $content, int $root ): array {
		$landing = is_array( $content['landing'] ?? null ) ? $content['landing'] : array();
		if ( 0 !== $root ) {
			$page = get_post( $root );
			return array(
				'action'   => 'unchanged',
				'post_id'  => $root,
				'post'     => array(),
				'problems' => array(),
				'notes'    => array(
					$page instanceof \WP_Post && '' === trim( $page->post_content )
						? 'The landing page exists but is empty, so it shows a placeholder: the import doesn’t write into an existing page. To have the import create it, first move any pages under it elsewhere (deleting it would move them out of the Firehouse), then delete it permanently and import again.'
						: 'The landing page already exists, so its words are left as editors have them.',
				),
			);
		}

		$problems = array();
		if ( self::trashed( 0, Query::ROOT_PATH ) ) {
			$problems[] = 'A landing page is in the trash. Restore it, or delete it permanently, then import again: a new one beside it would leave two pages claiming /' . Query::ROOT_PATH . '/.';
		} elseif ( wp_unique_post_slug( Query::ROOT_PATH, 0, 'publish', 'page', 0 ) !== Query::ROOT_PATH ) {
			$problems[] = 'Something else holds the address /' . Query::ROOT_PATH . '/, such as a Media Library file named “' . Query::ROOT_PATH . '”, so the page would land elsewhere. Rename or remove it, then import again.';
		}
		$notes = array();
		if ( is_string( $landing['hero']['imageUrl'] ?? null ) && '' !== $landing['hero']['imageUrl'] ) {
			$notes[] = 'The hero’s photograph isn’t carried over: the landing page shows its drawn background instead.';
		}
		$settings = is_array( $content['settings'] ?? null ) ? $content['settings'] : array();
		$name     = is_string( $settings['siteName'] ?? null ) && '' !== $settings['siteName'] ? $settings['siteName'] : 'The Firehouse';
		return array(
			'action'   => 'create',
			'post_id'  => 0,
			'post'     => array(
				'post_type'    => 'page',
				'post_name'    => Query::ROOT_PATH,
				'post_parent'  => 0,
				'post_status'  => 'publish',
				'post_author'  => 0,
				'post_title'   => Store::label( $name ),
				'post_content' => Landing_Page::blocks( $landing, is_string( $settings['submitFormUrl'] ?? null ) ? $settings['submitFormUrl'] : '' ),
			),
			'problems' => $problems,
			'notes'    => $notes,
		);
	}

	/**
	 * Plans the About page: created under the landing page from the bundle's about
	 * content if there's no `about` page under it in any status but the trash; otherwise
	 * left as it is, its words the editors'. A trashed one, or anything else under the
	 * landing page holding the address, is a problem. With no landing page yet, it's
	 * created under the one the run creates. Its title is the About link's label.
	 *
	 * @param array<string, mixed> $content Normalized content.
	 * @param int                  $root    The `firehouse` page's ID, or 0 for none.
	 * @param string               $title   The About link's label in the settings the run leaves.
	 * @return array{action: string, post_id: int, post: array<string, mixed>, problems: list<string>, notes: list<string>}
	 */
	private static function plan_about( array $content, int $root, string $title ): array {
		$about   = is_array( $content['about'] ?? null ) ? $content['about'] : array();
		$address = '/' . Query::ROOT_PATH . '/' . About_Page::SLUG . '/';
		$page    = 0 !== $root ? self::child_page( $root, About_Page::SLUG ) : null;
		if ( null !== $page ) {
			return array(
				'action'   => 'unchanged',
				'post_id'  => $page->ID,
				'post'     => array(),
				'problems' => array(),
				'notes'    => array(
					'' === trim( $page->post_content )
						? 'The About page exists but is empty, so it shows a placeholder: the import doesn’t write into an existing page. Delete it permanently, then import again to create it.'
						: 'The About page already exists, so its words are left as editors have them.',
				),
			);
		}

		$problems = array();
		if ( 0 !== $root && self::trashed( $root, About_Page::SLUG ) ) {
			$problems[] = 'An About page is in the trash. Restore it, or delete it permanently, then import again: a new one beside it would leave two pages claiming ' . $address . '.';
		} elseif ( 0 !== $root && wp_unique_post_slug( About_Page::SLUG, 0, 'publish', 'page', $root ) !== About_Page::SLUG ) {
			$problems[] = 'Something else holds the address ' . $address . ', such as a Media Library file named “' . About_Page::SLUG . '” uploaded to the landing page, so the page would land elsewhere. Rename or remove it, then import again.';
		}

		$notes   = array();
		$photos  = array_values( array_filter( array_map( static fn( mixed $member ): string => is_array( $member ) && is_string( $member['photo'] ?? null ) ? $member['photo'] : '', is_array( $about['team']['members'] ?? null ) ? $about['team']['members'] : array() ) ) );
		$missing = array_values( array_diff( $photos, array_keys( About_Page::portraits( $photos ) ) ) );
		if ( array() !== $missing ) {
			$notes[] = sprintf( 'The team’s portraits aren’t in the bundle: upload them to the Media Library titled %s (a file’s title starts as its name), and the About page shows them. Until then it shows each person’s initials.', implode( ', ', array_map( static fn( string $name ): string => '“' . $name . '”', $missing ) ) );
		}
		$settings = is_array( $content['settings'] ?? null ) ? $content['settings'] : array();
		return array(
			'action'   => 'create',
			'post_id'  => 0,
			'post'     => array(
				'post_type'    => 'page',
				'post_name'    => About_Page::SLUG,
				'post_parent'  => $root,
				'post_status'  => 'publish',
				'post_author'  => 0,
				'post_title'   => Store::label( '' !== $title ? $title : 'About' ),
				'post_content' => About_Page::blocks( $about, is_string( $settings['submitFormUrl'] ?? null ) ? $settings['submitFormUrl'] : '' ),
			),
			'problems' => $problems,
			'notes'    => $notes,
		);
	}

	/**
	 * The page under a parent with this name, in any status but the trash, or null.
	 *
	 * @param int    $parent_id The parent page's ID.
	 * @param string $name      The page's name.
	 */
	private static function child_page( int $parent_id, string $name ): ?\WP_Post {
		$pages = get_posts(
			array(
				'post_type'   => 'page',
				'name'        => $name,
				'post_parent' => $parent_id,
				'post_status' => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'numberposts' => 1,
			)
		);
		return isset( $pages[0] ) && $pages[0] instanceof \WP_Post ? $pages[0] : null;
	}

	/**
	 * Whether a page under this parent that had this name is in the trash: trashed
	 * with it (WordPress keeps the name it wants back), or older trash still holding
	 * it. WordPress gives its name back when it's restored, beside any new page. One
	 * query for the pages and their fields.
	 *
	 * @param int    $parent_id The parent's ID: 0 for the top of the site.
	 * @param string $name      The page's name.
	 */
	private static function trashed( int $parent_id, string $name ): bool {
		$trashed = get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => 'trash',
				'post_parent' => $parent_id,
				'numberposts' => -1,
			)
		);
		foreach ( $trashed as $page ) {
			if ( in_array( $name, array( $page->post_name, get_post_meta( $page->ID, '_wp_desired_post_slug', true ) ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Reads back what the run wrote, and lists as a problem anything that reads back
	 * differently: a field, a field that should be gone, the title, slug or status, a
	 * setting, or the landing page.
	 *
	 * @param array<string, mixed> $plan The plan, after the writes.
	 * @return array<string, mixed>
	 */
	private static function verify( array $plan ): array {
		foreach ( array(
			'projects' => Post_Types::PROJECT,
			'topics'   => Post_Types::TOPIC,
		) as $list => $post_type ) {
			foreach ( $plan[ $list ] as $index => $entry ) {
				if ( 'unchanged' === $entry['action'] || 0 === $entry['post_id'] || array() !== $entry['problems'] ) {
					continue;
				}
				$post = get_post( $entry['post_id'] );
				if ( ! $post instanceof \WP_Post ) {
					$plan[ $list ][ $index ]['problems'][] = 'It can’t be read back.';
					continue;
				}
				$stored  = Post_Types::PROJECT === $post_type ? Store::project( $post ) : Store::topic( $post );
				$differs = array();
				foreach ( $entry['set'] as $name => $value ) {
					if ( ! self::same( $value, $stored['fields'][ $name ] ?? null ) ) {
						$differs[] = $name;
					}
				}
				foreach ( $entry['delete'] as $name ) {
					if ( array_key_exists( $name, $stored['fields'] ) ) {
						$differs[] = $name . ' (still there)';
					}
				}
				foreach ( array( 'post_title', 'post_name', 'post_status' ) as $field ) {
					if ( isset( $entry['post'][ $field ] ) && $entry['post'][ $field ] !== $post->$field ) {
						$differs[] = $field;
					}
				}
				if ( array() !== $differs ) {
					$plan[ $list ][ $index ]['problems'][] = sprintf( 'It reads back differently from what was imported: %s.', implode( ', ', $differs ) );
				}
			}
		}

		if ( 'update' === $plan['settings']['action'] && ! self::same( $plan['settings']['value'], Settings::get() ) ) {
			$plan['settings']['problems'][] = 'The settings read back differently from what was imported.';
		}

		$landing = $plan['landing'];
		if ( 'create' === $landing['action'] && array() === $landing['problems'] ) {
			$page = get_post( $landing['post_id'] );
			if ( ! $page instanceof \WP_Post ) {
				$plan['landing']['problems'][] = 'It can’t be read back.';
			} elseif ( Capabilities::root_id() !== $page->ID ) {
				$plan['landing']['problems'][] = 'It isn’t at /' . Query::ROOT_PATH . '/: WordPress gave it the address /' . $page->post_name . '/.';
			} elseif ( $page->post_content !== $landing['post']['post_content'] || 'publish' !== $page->post_status ) {
				$plan['landing']['problems'][] = 'It reads back differently from what was imported.';
			}
		}
		return $plan;
	}

	/**
	 * The fields of a project or topic that are stored as meta, as the bundle has them.
	 * Seed_Bundle has already refused a bundle with any other field.
	 *
	 * @param array<string, mixed> $item      A project, or a topic and its synthesis.
	 * @param string               $post_type The post type.
	 * @return array<string, mixed>
	 */
	private static function fields( array $item, string $post_type ): array {
		return array_intersect_key( $item, Meta::fields( $post_type ) );
	}

	/**
	 * The slugs a synthesis's needs name.
	 *
	 * @param list<array<string, mixed>> $needs The needs, naming projects by slug.
	 * @return list<string>
	 */
	private static function needed_projects( array $needs ): array {
		$slugs = array();
		foreach ( $needs as $need ) {
			foreach ( $need['projects'] ?? array() as $slug ) {
				$slugs[] = $slug;
			}
		}
		return $slugs;
	}

	/**
	 * A synthesis's needs with each project named by post ID instead of slug. A
	 * project that isn't known yet (a new one, before the run) is 0.
	 *
	 * @param list<array<string, mixed>> $needs    The needs, naming projects by slug.
	 * @param array<string, int>         $post_ids Post IDs by slug.
	 * @return list<array<string, mixed>>
	 */
	private static function needs_by_id( array $needs, array $post_ids ): array {
		return array_map(
			static function ( array $need ) use ( $post_ids ): array {
				if ( isset( $need['projects'] ) ) {
					$need['projects'] = array_map( static fn( string $slug ): int => $post_ids[ $slug ] ?? 0, $need['projects'] );
				}
				return $need;
			},
			$needs
		);
	}

	/**
	 * Whether two values are the same as JSON would have them: equal values, with an
	 * object's keys in any order.
	 *
	 * @param mixed $a One value.
	 * @param mixed $b The other.
	 */
	private static function same( mixed $a, mixed $b ): bool {
		return self::canonical( $a ) === self::canonical( $b );
	}

	/**
	 * A value with every object's keys sorted.
	 *
	 * @param mixed $value The value.
	 * @return mixed
	 */
	private static function canonical( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$value = array_map( array( self::class, 'canonical' ), $value );
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		return $value;
	}
}
