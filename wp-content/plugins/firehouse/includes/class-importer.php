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
 * would change.
 *
 * - **Projects** are matched by response ID (`id`), so running it again updates the
 *   same posts. A new project arrives whole: published or a draft, as the bundle has
 *   it. An existing one has its survey fields replaced and keeps its editorial fields,
 *   which belong to editors in WordPress (rule 4): its slug, whether it is published,
 *   its summary, publication status, full-record link and geography note.
 * - **Topics** are the four posts keyed by topic key, created if missing. A topic's
 *   fields and synthesis are replaced; each need's projects become post IDs (rule 5).
 * - **Settings**: the site name, submit label and address, and the navigation labels.
 *
 * Nothing is written while anything is wrong, and the dry run lists every problem.
 * Projects in WordPress that the bundle doesn't have are left as they are. The landing
 * and About pages are checked but not written: their pages are built from blocks in
 * Phase 4.
 */
final class Importer {

	/**
	 * A project's editorial fields, besides its geography note: an existing project
	 * keeps WordPress's.
	 */
	public const EDITORIAL = array( 'slug', 'published', 'summary', 'publicationStatus', 'fullRecordUrl' );

	/**
	 * Slugs WordPress won't give a project, because its addresses use them.
	 */
	private const RESERVED_SLUGS = array( 'feed', 'rdf', 'rss', 'rss2', 'atom', 'embed' );

	/**
	 * What importing this content would change, against what WordPress holds now.
	 *
	 * @param array<string, mixed> $content Normalized content, from Seed_Bundle::read().
	 * @param string[]             $notes   Seed_Bundle::read()'s notes about the bundle.
	 * @return array<string, mixed> The plan; see plan().
	 */
	public static function preview( array $content, array $notes = array() ): array {
		return self::plan( $content, Store::projects(), Store::topics(), Settings::get(), $notes );
	}

	/**
	 * Imports the content, if the dry run finds nothing wrong, and checks each post
	 * it wrote by reading it back.
	 *
	 * @param array<string, mixed> $content Normalized content, from Seed_Bundle::read().
	 * @param string[]             $notes   Seed_Bundle::read()'s notes about the bundle.
	 * @return array<string, mixed> The plan, with each post's ID, `imported`, and any
	 *                              write that failed or read back differently as a problem.
	 */
	public static function run( array $content, array $notes = array() ): array {
		$plan = self::preview( $content, $notes );
		if ( ! $plan['ok'] ) {
			return $plan + array( 'imported' => false );
		}

		$ids = array();
		foreach ( $plan['projects'] as $index => $project ) {
			if ( 'unchanged' !== $project['action'] ) {
				$saved = Store::save( Post_Types::PROJECT, $project['post_id'], $project['post'], $project['set'], $project['delete'] );
				if ( is_wp_error( $saved ) ) {
					$plan['projects'][ $index ]['problems'][] = 'WordPress didn’t save it: ' . $saved->get_error_message();
					continue;
				}
				$plan['projects'][ $index ]['post_id'] = $saved;
			}
			$ids[ $project['slug'] ] = $plan['projects'][ $index ]['post_id'];
		}

		foreach ( $plan['topics'] as $index => $topic ) {
			$set = $topic['set'];
			if ( isset( $set['topNeeds'] ) ) {
				$set['topNeeds'] = self::needs_by_id( $set['topNeeds'], $ids );
			}
			if ( 'unchanged' !== $topic['action'] || $topic['needs_new_projects'] ) {
				$saved = Store::save( Post_Types::TOPIC, $topic['post_id'], $topic['post'], $set, $topic['delete'] );
				if ( is_wp_error( $saved ) ) {
					$plan['topics'][ $index ]['problems'][] = 'WordPress didn’t save it: ' . $saved->get_error_message();
					continue;
				}
				$plan['topics'][ $index ]['post_id'] = $saved;
			}
			$plan['topics'][ $index ]['set'] = $set;
		}

		if ( 'unchanged' !== $plan['settings']['action'] ) {
			update_option( Settings::OPTION, $plan['settings']['value'] );
		}

		$plan = self::verify( $plan );
		return array_merge( $plan, array( 'imported' => true ) );
	}

	/**
	 * Plans an import of the content into what WordPress holds.
	 *
	 * Each project and topic has an `action` (create, update or unchanged), the fields
	 * it would change, `problems` that stop the import and `notes` that don't. `ok` is
	 * whether nothing anywhere stops it.
	 *
	 * @param array<string, mixed>       $content  Normalized content.
	 * @param list<array<string, mixed>> $projects The project posts, from Store::projects().
	 * @param list<array<string, mixed>> $topics   The topic posts, from Store::topics().
	 * @param array<string, mixed>       $settings The current settings.
	 * @param string[]                   $notes    Notes about the bundle itself.
	 * @return array{ok: bool, projects: list<array<string, mixed>>, topics: list<array<string, mixed>>, settings: array<string, mixed>, left_alone: list<array<string, string>>, notes: list<string>}
	 */
	public static function plan( array $content, array $projects, array $topics, array $settings, array $notes = array() ): array {
		$by_id   = array();
		$repeats = array();
		$slugs   = array();
		foreach ( $projects as $stored ) {
			$id = $stored['fields']['id'] ?? null;
			if ( is_string( $id ) && '' !== $id ) {
				if ( isset( $by_id[ $id ] ) ) {
					$repeats[ $id ] = true;
				}
				$by_id[ $id ] = $stored;
			}
			$slugs[ $stored['fields']['slug'] ] = true;
		}

		$planned = array();
		$seen    = array();
		foreach ( $content['projects'] as $project ) {
			$planned[] = self::plan_project( $project, $by_id, $repeats, $slugs, $seen );
		}

		$left_alone = array();
		foreach ( $by_id as $id => $stored ) {
			if ( ! isset( $seen[ $id ] ) ) {
				$left_alone[] = array(
					'slug'  => (string) $stored['fields']['slug'],
					'title' => (string) $stored['fields']['title'],
				);
			}
		}

		$post_ids = array();
		foreach ( $planned as $project ) {
			$post_ids[ $project['slug'] ] = $project['post_id'];
		}

		$plan = array(
			'projects'   => $planned,
			'topics'     => self::plan_topics( $content, $topics, $post_ids ),
			'settings'   => self::plan_settings( $content['settings'], $settings ),
			'left_alone' => $left_alone,
			'notes'      => array_merge( array_values( $notes ), array( 'The landing and About pages are checked but not written: their pages are built in Phase 4.' ) ),
		);

		$problems   = array_merge(
			...array_column( $plan['projects'], 'problems' ),
			...array_column( $plan['topics'], 'problems' )
		);
		$plan['ok'] = array() === $problems && array() === $plan['settings']['problems'];
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
			);
			$entry['set']  = $fields;
			return $entry;
		}

		$entry['post_id'] = $stored['post_id'];
		$entry['status']  = $stored['status'];
		if ( 'trash' === $stored['status'] ) {
			$entry['problems'][] = 'It is in the trash in WordPress. Restore it or delete it permanently, then import again.';
		}

		$survey = array_diff_key( $fields, array_flip( self::EDITORIAL ) );
		$note   = $stored['fields']['geo']['note'] ?? null;
		if ( isset( $survey['geo'] ) ) {
			unset( $survey['geo']['note'] );
		}
		if ( is_string( $note ) ) {
			$survey['geo'] = ( $survey['geo'] ?? array( 'regions' => array() ) ) + array( 'note' => $note );
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
				$entry['delete'][]  = $name;
				$entry['changes'][] = $name;
			}
		}
		$entry['action'] = array() === $entry['changes'] ? 'unchanged' : 'update';

		foreach ( self::EDITORIAL as $name ) {
			$ours   = 'slug' === $name || 'published' === $name ? $project[ $name ] : ( $fields[ $name ] ?? null );
			$theirs = $stored['fields'][ $name ] ?? null;
			if ( ! self::same( $ours, $theirs ) ) {
				$entry['notes'][] = sprintf( 'It keeps WordPress’s %s, which differs from the bundle’s.', $name );
			}
		}
		if ( ! self::same( $project['geo']['note'] ?? null, $note ) ) {
			$entry['notes'][] = 'It keeps WordPress’s geography note, which differs from the bundle’s.';
		}
		return $entry;
	}

	/**
	 * Plans the four topics.
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
				'key'                => $key,
				'label'              => is_string( $entries[0]['label'] ?? null ) ? $entries[0]['label'] : '',
				'action'             => 'create',
				'post_id'            => 0,
				'changes'            => array(),
				'problems'           => array(),
				'notes'              => array(),
				'post'               => array(),
				'set'                => array(),
				'delete'             => array(),
				'needs_new_projects' => false,
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
			$known = self::needs_by_id( $fields['topNeeds'], $post_ids );
			foreach ( $fields['topNeeds'] as $need ) {
				foreach ( $need['projects'] ?? array() as $slug ) {
					if ( 0 === ( $post_ids[ $slug ] ?? 0 ) ) {
						$entry['needs_new_projects'] = true;
					}
				}
			}
			foreach ( array_merge( $fields, array( 'topNeeds' => $known ) ) as $name => $value ) {
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
					'post_title'  => $entry['label'],
					'post_name'   => $key,
					'post_status' => 'publish',
				);
				$entry['set']  = $fields;
				$planned[]     = $entry;
				continue;
			}

			$entry['post_id'] = $stored['post_id'];
			if ( 'publish' !== $stored['status'] ) {
				$entry['post']['post_status'] = 'publish';
				$entry['changes'][]           = 'status';
			}
			if ( $entry['label'] !== $stored['fields']['label'] ) {
				$entry['post']['post_title'] = $entry['label'];
				$entry['changes'][]          = 'label';
			}
			foreach ( $fields as $name => $value ) {
				$compare = 'topNeeds' === $name ? $known : $value;
				if ( ! array_key_exists( $name, $stored['fields'] ) || ! self::same( $compare, $stored['fields'][ $name ] ) || ( 'topNeeds' === $name && $entry['needs_new_projects'] ) ) {
					$entry['set'][ $name ] = $value;
					$entry['changes'][]    = $name;
				}
			}
			foreach ( array_keys( Meta::fields( Post_Types::TOPIC ) ) as $name ) {
				if ( ! array_key_exists( $name, $fields ) && array_key_exists( $name, $stored['fields'] ) ) {
					$entry['delete'][]  = $name;
					$entry['changes'][] = $name;
				}
			}
			$entry['action'] = array() === $entry['changes'] ? 'unchanged' : 'update';
			$planned[]       = $entry;
		}
		return $planned;
	}

	/**
	 * Plans the settings: the bundle's, checked as the settings screen checks them.
	 * A navigation link the bundle doesn't have keeps its current label.
	 *
	 * @param array<string, mixed> $bundle  The bundle's settings.
	 * @param array<string, mixed> $current The current settings.
	 * @return array{action: string, changes: list<string>, problems: list<string>, value: array<string, mixed>}
	 */
	private static function plan_settings( array $bundle, array $current ): array {
		$labels = $current['navLabels'];
		foreach ( is_array( $bundle['nav'] ?? null ) ? $bundle['nav'] : array() as $link ) {
			$key = is_array( $link ) ? array_search( $link['to'] ?? null, Settings::NAV, true ) : false;
			if ( false !== $key && is_string( $link['label'] ?? null ) ) {
				$labels[ $key ] = $link['label'];
			}
		}
		$input = array(
			'siteName'      => $bundle['siteName'] ?? null,
			'submitLabel'   => $bundle['submitLabel'] ?? null,
			'submitFormUrl' => $bundle['submitFormUrl'] ?? null,
			'navLabels'     => $labels,
		);

		list( $value, $errors ) = Settings::validate( $input );
		$changes                = array();
		foreach ( $value as $key => $setting ) {
			if ( ! self::same( $setting, $current[ $key ] ?? null ) ) {
				$changes[] = $key;
			}
		}
		return array(
			'action'   => array() === $changes ? 'unchanged' : 'update',
			'changes'  => $changes,
			'problems' => array_values( $errors ),
			'value'    => $value,
		);
	}

	/**
	 * Reads back each project and topic the run wrote, and lists any field that
	 * reads back differently from what was written as a problem.
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
				if ( 'unchanged' === $entry['action'] && empty( $entry['needs_new_projects'] ) ) {
					continue;
				}
				$post = get_post( $entry['post_id'] );
				if ( ! $post instanceof \WP_Post ) {
					continue;
				}
				$stored = Post_Types::PROJECT === $post_type ? Store::project( $post ) : Store::topic( $post );
				foreach ( $entry['set'] as $name => $value ) {
					if ( ! self::same( $value, $stored['fields'][ $name ] ?? null ) ) {
						$plan[ $list ][ $index ]['problems'][] = sprintf( 'Its %s reads back differently from what was imported.', $name );
					}
				}
				$expected = array(
					'post_title' => Post_Types::PROJECT === $post_type ? 'title' : 'label',
					'post_name'  => 'slug',
				);
				foreach ( $expected as $field => $name ) {
					if ( isset( $entry['post'][ $field ] ) && $entry['post'][ $field ] !== $post->$field ) {
						$plan[ $list ][ $index ]['problems'][] = sprintf( 'Its %s reads back differently from what was imported.', $name );
					}
				}
			}
		}
		return $plan;
	}

	/**
	 * The fields of a project or topic that are stored as meta, as the bundle has them.
	 *
	 * @param array<string, mixed> $item      A project, or a topic and its synthesis.
	 * @param string               $post_type The post type.
	 * @return array<string, mixed>
	 */
	private static function fields( array $item, string $post_type ): array {
		return array_intersect_key( $item, Meta::fields( $post_type ) );
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
