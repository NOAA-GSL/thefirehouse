<?php
/**
 * Values computed from content, ported from src/content/derive.ts.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counts, lookups, ordering, region expansion and synthesis provenance.
 *
 * Everything here works on content in the shape `Normalize::site_content()` returns,
 * which is the JSON shape of src/content/types.ts: arrays with the same camelCase
 * keys. Nobody types a count anywhere; the landing page counts what's published.
 * shared/cases/derive.json holds the cases both versions must pass.
 */
final class Derive {

	/**
	 * The published projects, in their stored order.
	 *
	 * @param array<string, mixed> $content Normalized site content.
	 * @return list<array<string, mixed>>
	 */
	public static function published_projects( array $content ): array {
		return array_values( array_filter( $content['projects'], static fn( array $project ): bool => Js::truthy( $project['published'] ?? null ) ) );
	}

	/**
	 * The value a landing-page stat shows.
	 *
	 * A need counts when its entry has a need; a recommendation submitted on its own
	 * doesn't. A need of "0" counts, as it does in JavaScript, although PHP's empty()
	 * would say it has none.
	 *
	 * @param array<string, mixed> $stat    The stat: its `source`, and a `value` for static ones.
	 * @param array<string, mixed> $content Normalized site content.
	 */
	public static function stat_value( array $stat, array $content ): string {
		switch ( $stat['source'] ?? '' ) {
			case 'publishedProjectCount':
				return (string) count( self::published_projects( $content ) );
			case 'topicCount':
				return (string) count( $content['topics'] );
			case 'needCount':
				$count = 0;
				foreach ( self::published_projects( $content ) as $project ) {
					foreach ( $project['needs'] as $entry ) {
						if ( Js::truthy( $entry['need'] ?? null ) ) {
							++$count;
						}
					}
				}
				return (string) $count;
			case 'static':
				return (string) ( $stat['value'] ?? '' );
			default:
				return '';
		}
	}

	/**
	 * Each topic in display order, with the first needs of its synthesis.
	 *
	 * @param array<string, mixed> $content        Normalized site content.
	 * @param int                  $needs_per_card How many needs each card shows.
	 * @return list<array{topic: array<string, mixed>, needs: list<array<string, mixed>>}>
	 */
	public static function topic_cards( array $content, int $needs_per_card ): array {
		// Keyed by topic, so a later summary for the same topic replaces an earlier one.
		$needs_by_topic = array_column( $content['topicSummaries'], 'topNeeds', 'topic' );

		$cards = array();
		foreach ( $content['topics'] as $topic ) {
			$cards[] = array(
				'topic' => $topic,
				'needs' => array_slice( $needs_by_topic[ $topic['key'] ] ?? array(), 0, $needs_per_card ),
			);
		}
		return $cards;
	}

	/**
	 * A project by slug, drafts included: the page decides whether to show one.
	 *
	 * @param array<string, mixed> $content Normalized site content.
	 * @param string               $slug    The slug.
	 * @return array<string, mixed>|null
	 */
	public static function project_by_slug( array $content, string $slug ): ?array {
		foreach ( $content['projects'] as $project ) {
			if ( $project['slug'] === $slug ) {
				return $project;
			}
		}
		return null;
	}

	/**
	 * The editor-facing record for one topic area.
	 *
	 * @param array<string, mixed> $content Normalized site content.
	 * @param string               $key     The topic key.
	 * @return array<string, mixed>|null
	 */
	public static function topic( array $content, string $key ): ?array {
		foreach ( $content['topics'] as $topic ) {
			if ( $topic['key'] === $key ) {
				return $topic;
			}
		}
		return null;
	}

	/**
	 * One topic's synthesis.
	 *
	 * @param array<string, mixed> $content Normalized site content.
	 * @param string               $key     The topic key.
	 * @return array<string, mixed>|null
	 */
	public static function topic_summary( array $content, string $key ): ?array {
		foreach ( $content['topicSummaries'] as $summary ) {
			if ( $summary['topic'] === $key ) {
				return $summary;
			}
		}
		return null;
	}

	/**
	 * The site-wide project order: newest first, alphabetical within a year.
	 *
	 * Shared so the explorer, the topic pages and the map can't disagree about it.
	 * Use it with usort().
	 *
	 * @param array<string, mixed> $a One project.
	 * @param array<string, mixed> $b Another.
	 */
	public static function by_recency( array $a, array $b ): int {
		$by_year = $b['completionYear'] <=> $a['completionYear'];
		return 0 !== $by_year ? $by_year : self::compare_titles( $a['title'], $b['title'] );
	}

	/**
	 * Compares two titles as JavaScript's localeCompare does in an en-US browser, so a
	 * list PHP renders keeps its order when the explorer's script redraws it.
	 *
	 * That takes PHP's intl extension, whose collator implements the same rules. On a
	 * server without it, compare_titles_without_intl() is used instead.
	 *
	 * @param string $a One title.
	 * @param string $b Another.
	 */
	public static function compare_titles( string $a, string $b ): int {
		static $collator = null;
		if ( null === $collator ) {
			$collator = class_exists( \Collator::class ) ? new \Collator( 'en_US' ) : false;
		}
		return $collator ? (int) $collator->compare( $a, $b ) : self::compare_titles_without_intl( $a, $b );
	}

	/**
	 * The comparison without intl: ignoring case, then lowercase first.
	 *
	 * That agrees with localeCompare on titles made of letters and spaces. Punctuation,
	 * digits and accented letters can sort differently: localeCompare puts "Fire_study"
	 * before "Fire(study)" and "Éclair" before "Zeta", and this does neither.
	 *
	 * @param string $a One title.
	 * @param string $b Another.
	 */
	public static function compare_titles_without_intl( string $a, string $b ): int {
		$ignoring_case = strcmp( strtolower( $a ), strtolower( $b ) );
		return 0 !== $ignoring_case ? $ignoring_case : -strcmp( $a, $b );
	}

	/**
	 * How many times a need was raised: its stored count, or else how many projects
	 * raised it. A stored zero still wins.
	 *
	 * @param array<string, mixed> $need One need from a synthesis.
	 */
	public static function need_mentions( array $need ): int {
		if ( isset( $need['mentions'] ) ) {
			return (int) $need['mentions'];
		}
		return isset( $need['projects'] ) ? count( $need['projects'] ) : 0;
	}

	/**
	 * The provenance the landing page shows above the topic cards.
	 *
	 * A pass runs over the whole collection, so the four summaries normally agree. If
	 * they don't, the latest date and the largest source count win, and there's a
	 * reviewer only if every summary has one. Keys with no value are left out, as
	 * JSON leaves out `undefined`.
	 *
	 * @param array<string, mixed> $content Normalized site content.
	 * @return array{model?: string, reviewedBy?: string, updatedAt?: string, sourceCount: int}
	 */
	public static function synthesis_info( array $content ): array {
		$summaries = $content['topicSummaries'];
		$info      = array();

		foreach ( $summaries as $summary ) {
			if ( Js::truthy( $summary['model'] ?? null ) ) {
				$info['model'] = $summary['model'];
				break;
			}
		}

		$reviewed = array_filter( $summaries, static fn( array $summary ): bool => Js::truthy( $summary['reviewedBy'] ?? null ) );
		if ( array() !== $summaries && count( $reviewed ) === count( $summaries ) ) {
			$info['reviewedBy'] = $summaries[0]['reviewedBy'];
		}

		$dates = array_filter( array_column( $summaries, 'updatedAt' ), array( Js::class, 'truthy' ) );
		if ( array() !== $dates ) {
			sort( $dates, SORT_STRING );
			$info['updatedAt'] = end( $dates );
		}

		$largest             = max( array( 0, ...array_map( static fn( array $summary ): int => (int) ( $summary['sourceCount'] ?? 0 ), $summaries ) ) );
		$info['sourceCount'] = $largest > 0 ? $largest : count( self::published_projects( $content ) );

		return $info;
	}

	/**
	 * The regions a project counts toward.
	 *
	 * A national answer means a nationally representative study, so it counts toward
	 * every GACC as well, and NATIONAL stays so the National row still counts it.
	 * Pacific, international and unknown aren't claims about the whole country, so
	 * they don't expand.
	 *
	 * @param string[] $regions The project's region keys.
	 * @return list<string>
	 */
	public static function effective_regions( array $regions = array() ): array {
		if ( ! in_array( 'NATIONAL', $regions, true ) ) {
			return array_values( $regions );
		}
		return array_values( array_unique( array_merge( $regions, Vocabulary::gaccs() ) ) );
	}

	/**
	 * The published projects that count toward one region, newest first.
	 *
	 * @param array<string, mixed> $content Normalized site content.
	 * @param string               $key     A region key.
	 * @return list<array<string, mixed>>
	 */
	public static function projects_in_region( array $content, string $key ): array {
		$projects = array_values(
			array_filter(
				self::published_projects( $content ),
				static fn( array $project ): bool => in_array( $key, self::effective_regions( $project['geo']['regions'] ?? array() ), true )
			)
		);
		usort( $projects, array( self::class, 'by_recency' ) );
		return $projects;
	}

	/**
	 * Published project counts for every region, for the landing page's map.
	 *
	 * Every key is there, zeroes included, and so are the four answers with no
	 * boundary to draw. Leaving either out would misinform: an empty region would read
	 * as "no data" rather than "nothing filed yet", and the total would come out
	 * smaller than the submissions received.
	 *
	 * @param array<string, mixed> $content Normalized site content.
	 * @return array<string, int>
	 */
	public static function region_counts( array $content ): array {
		$counts = array_fill_keys( Vocabulary::regions(), 0 );
		foreach ( self::published_projects( $content ) as $project ) {
			foreach ( self::effective_regions( $project['geo']['regions'] ?? array() ) as $key ) {
				if ( isset( $counts[ $key ] ) ) {
					++$counts[ $key ];
				}
			}
		}
		return $counts;
	}
}
