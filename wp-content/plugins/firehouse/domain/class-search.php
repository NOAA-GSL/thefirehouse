<?php
/**
 * The project explorer's search and filters, ported from src/content/search.ts.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search and filtering for the explorer, as src/content/search.ts has them, so the page
 * PHP renders for a query string (the first view, and every view without JavaScript)
 * lists what the explorer's script would (WORDPRESS-PLAN.md, Interactivity; open
 * question 11). Both are held to shared/cases/search.json.
 *
 * Everything here is pure and keyed off the query string, so a filtered, searched,
 * sorted view is an address someone can share. A query is an array of `q`, `phase`,
 * `region`, `year`, `type` and `sort`, as ProjectQuery is; a project is one as
 * Normalize leaves it. There is no topic facet: topic areas are a synthesis across the
 * collection, not a tag on any one project.
 */
final class Search {

	/**
	 * The sorts, in the order the explorer offers them.
	 */
	public const SORTS = array( 'relevance', 'newest', 'oldest', 'title' );

	/**
	 * The filters a reader can clear one by one: everything but the text and the sort.
	 */
	public const FACETS = array( 'phase', 'region', 'year', 'type' );

	/**
	 * Latin letters with accents, one for one with UNACCENTED: what fold_without_intl()
	 * strips on a server without the intl extension. Each pair is a letter and what
	 * Unicode's decomposition (NFD) leaves of it once the combining marks U+0300 to
	 * U+036F are dropped, for every letter from U+00C0 to U+024F and U+1E00 to U+1EFF
	 * that has any.
	 */
	private const ACCENTED = 'ÀÁÂÃÄÅÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜÝàáâãäåçèéêëìíîïñòóôõöùúûüýÿĀāĂăĄąĆćĈĉĊċČčĎďĒēĔĕĖėĘęĚěĜĝĞğĠġĢģĤĥĨĩĪīĬĭĮįİĴĵĶķĹĺĻļĽľŃńŅņŇňŌōŎŏŐőŔŕŖŗŘřŚśŜŝŞşŠšŢţŤťŨũŪūŬŭŮůŰűŲųŴŵŶŷŸŹźŻżŽžƠơƯưǍǎǏǐǑǒǓǔǕǖǗǘǙǚǛǜǞǟǠǡǢǣǦǧǨǩǪǫǬǭǮǯǰǴǵǸǹǺǻǼǽǾǿȀȁȂȃȄȅȆȇȈȉȊȋȌȍȎȏȐȑȒȓȔȕȖȗȘșȚțȞȟȦȧȨȩȪȫȬȭȮȯȰȱȲȳḀḁḂḃḄḅḆḇḈḉḊḋḌḍḎḏḐḑḒḓḔḕḖḗḘḙḚḛḜḝḞḟḠḡḢḣḤḥḦḧḨḩḪḫḬḭḮḯḰḱḲḳḴḵḶḷḸḹḺḻḼḽḾḿṀṁṂṃṄṅṆṇṈṉṊṋṌṍṎṏṐṑṒṓṔṕṖṗṘṙṚṛṜṝṞṟṠṡṢṣṤṥṦṧṨṩṪṫṬṭṮṯṰṱṲṳṴṵṶṷṸṹṺṻṼṽṾṿẀẁẂẃẄẅẆẇẈẉẊẋẌẍẎẏẐẑẒẓẔẕẖẗẘẙẛẠạẢảẤấẦầẨẩẪẫẬậẮắẰằẲẳẴẵẶặẸẹẺẻẼẽẾếỀềỂểỄễỆệỈỉỊịỌọỎỏỐốỒồỔổỖỗỘộỚớỜờỞởỠỡỢợỤụỦủỨứỪừỬửỮữỰựỲỳỴỵỶỷỸỹ';

	/**
	 * The letters ACCENTED becomes, in the same order.
	 */
	private const UNACCENTED = 'AAAAAACEEEEIIIINOOOOOUUUUYaaaaaaceeeeiiiinooooouuuuyyAaAaAaCcCcCcCcDdEeEeEeEeEeGgGgGgGgHhIiIiIiIiIJjKkLlLlLlNnNnNnOoOoOoRrRrRrSsSsSsSsTtTtUuUuUuUuUuUuWwYyYZzZzZzOoUuAaIiOoUuUuUuUuUuAaAaÆæGgKkOoOoƷʒjGgNnAaÆæØøAaAaEeEeIiIiOoOoRrRrUuUuSsTtHhAaEeOoOoOoOoYyAaBbBbBbCcDdDdDdDdDdEeEeEeEeEeFfGgHhHhHhHhHhIiIiKkKkKkLlLlLlLlMmMmMmNnNnNnNnOoOoOoOoPpPpRrRrRrRrSsSsSsSsSsTtTtTtTtUuUuUuUuUuVvVvWwWwWwWwWwXxXxYyZzZzZzhtwyſAaAaAaAaAaAaAaAaAaAaAaAaEeEeEeEeEeEeEeEeIiIiOoOoOoOoOoOoOoOoOoOoOoOoUuUuUuUuUuUuUuYyYyYyYy';

	/**
	 * The parameters of a query string as URLSearchParams reads them: a leading "?"
	 * dropped, "+" as a space, percent-escapes decoded, bytes that aren't UTF-8 replaced,
	 * and the first value kept when a name repeats. A name with brackets, such as
	 * "phase[]", is a name of its own, so PHP's arrays never reach a query.
	 *
	 * @param string $query The query string, as $_SERVER['QUERY_STRING'] has it.
	 * @return array<string, string>
	 */
	public static function params( string $query ): array {
		if ( str_starts_with( $query, '?' ) ) {
			$query = substr( $query, 1 );
		}
		$params = array();
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$equals = strpos( $pair, '=' );
			$name   = self::decode( false === $equals ? $pair : substr( $pair, 0, $equals ) );
			$value  = false === $equals ? '' : self::decode( substr( $pair, $equals + 1 ) );
			if ( ! array_key_exists( $name, $params ) ) {
				$params[ $name ] = $value;
			}
		}
		return $params;
	}

	/**
	 * One name or value of a query string, decoded as URLSearchParams decodes it.
	 *
	 * @param string $text The encoded text.
	 */
	private static function decode( string $text ): string {
		$decoded = rawurldecode( str_replace( '+', ' ', $text ) );
		if ( mb_check_encoding( $decoded, 'UTF-8' ) ) {
			return $decoded;
		}
		$previous = mb_substitute_character();
		mb_substitute_character( 0xFFFD );
		$decoded = (string) mb_scrub( $decoded, 'UTF-8' );
		mb_substitute_character( $previous );
		return $decoded;
	}

	/**
	 * A query read from a query string, anything malformed dropped rather than an error,
	 * as parseProjectQuery() reads it.
	 *
	 * @param string $query The query string.
	 * @return array{q: string, phase: string|null, region: string|null, year: int|float|null, type: string|null, sort: string|null}
	 */
	public static function parse_query( string $query ): array {
		$params = self::params( $query );
		$year   = Js::number( $params['year'] ?? null );
		$type   = Js::trim( $params['type'] ?? '' );
		$sort   = $params['sort'] ?? null;

		return array(
			'q'      => $params['q'] ?? '',
			'phase'  => Vocabulary::is_one_of( $params['phase'] ?? null, Vocabulary::fire_phases() ) ? $params['phase'] : null,
			'region' => Vocabulary::is_one_of( $params['region'] ?? null, Vocabulary::regions() ) ? $params['region'] : null,
			'year'   => is_finite( $year ) && floor( $year ) === $year && $year > 0 ? ( $year <= PHP_INT_MAX ? (int) $year : $year ) : null,
			'type'   => '' === $type ? null : $type,
			'sort'   => in_array( $sort, self::SORTS, true ) ? $sort : null,
		);
	}

	/**
	 * A query as a query string, leaving out what's unset so addresses stay short, in a
	 * fixed order, encoded as URLSearchParams encodes it: a space as "+", and every byte
	 * but letters, digits and "*-._" escaped.
	 *
	 * @param array<string, mixed> $query The query.
	 */
	public static function serialize_query( array $query ): string {
		$pairs = array();
		foreach ( array( 'q', 'phase', 'region', 'year', 'type', 'sort' ) as $name ) {
			if ( Js::truthy( $query[ $name ] ?? null ) ) {
				$pairs[] = $name . '=' . strtr(
					rawurlencode( (string) $query[ $name ] ),
					array(
						'%20' => '+',
						'%2A' => '*',
						'~'   => '%7E',
					)
				);
			}
		}
		return implode( '&', $pairs );
	}

	/**
	 * How many filters are set: the facets, not the text or the sort.
	 *
	 * @param array<string, mixed> $query The query.
	 */
	public static function active_facet_count( array $query ): int {
		return count( array_filter( self::FACETS, static fn( string $facet ): bool => null !== ( $query[ $facet ] ?? null ) ) );
	}

	/**
	 * Text lower-cased and with its accents dropped, as fold() in search.ts has it, so
	 * "Évacuation" matches "evacuation". That takes the intl extension's Normalizer to
	 * decompose every letter as JavaScript does; on a server without it,
	 * fold_without_intl() is used instead.
	 *
	 * @param string $text The text.
	 */
	public static function fold( string $text ): string {
		if ( ! class_exists( \Normalizer::class ) ) {
			return self::fold_without_intl( $text );
		}
		$decomposed = \Normalizer::normalize( $text, \Normalizer::FORM_D );
		return mb_strtolower( self::strip_marks( false === $decomposed ? $text : $decomposed ), 'UTF-8' );
	}

	/**
	 * The folding without intl: Latin letters lose their accents as with it, from the
	 * ACCENTED table, and marks already written apart are dropped. Accents on other
	 * scripts' letters, such as Greek's, stay, where JavaScript would drop them.
	 *
	 * @param string $text The text.
	 */
	public static function fold_without_intl( string $text ): string {
		static $table = null;
		$table      ??= array_combine( mb_str_split( self::ACCENTED ), mb_str_split( self::UNACCENTED ) );
		return mb_strtolower( self::strip_marks( strtr( $text, $table ) ), 'UTF-8' );
	}

	/**
	 * Text without the combining marks U+0300 to U+036F.
	 *
	 * @param string $text The text.
	 */
	private static function strip_marks( string $text ): string {
		return preg_replace( '/[\x{0300}-\x{036F}]/u', '', $text ) ?? $text;
	}

	/**
	 * The search text's words, folded, split on JavaScript's whitespace.
	 *
	 * @param string $q The search text.
	 * @return list<string>
	 */
	private static function terms( string $q ): array {
		return self::words( (array) preg_split( '/[' . Js::SPACE . ']+/u', self::fold( $q ) ) );
	}

	/**
	 * The non-empty strings of a split, in order.
	 *
	 * @param array<mixed> $parts What preg_split() gave.
	 * @return list<string>
	 */
	private static function words( array $parts ): array {
		return array_values( array_filter( $parts, static fn( mixed $part ): bool => is_string( $part ) && '' !== $part ) );
	}

	/**
	 * A project's searchable fields, each with its weight: a hit in the title says far
	 * more about what a project is than one in its fourth recommendation.
	 *
	 * @param array<string, mixed> $project The project.
	 * @return list<array{string, int}>
	 */
	private static function fields( array $project ): array {
		$join = static fn( array $items, callable $text ): string => implode( ' ', array_map( $text, $items ) );
		return array(
			array( (string) ( $project['title'] ?? '' ), 6 ),
			array( (string) ( $project['projectType'] ?? '' ), 4 ),
			array( $join( $project['authors'] ?? array(), static fn( array $author ): string => (string) ( $author['name'] ?? '' ) ), 4 ),
			array( (string) ( $project['org'] ?? '' ), 3 ),
			array( (string) ( $project['summary'] ?? '' ), 3 ),
			array( (string) ( $project['abstract'] ?? '' ), 2 ),
			array( implode( ' ', $project['takeaways'] ?? array() ), 1 ),
			array( implode( ' ', $project['methods'] ?? array() ), 2 ),
			array( $join( $project['needs'] ?? array(), static fn( array $need ): string => ( $need['need'] ?? '' ) . ' ' . ( $need['recommendation'] ?? '' ) ), 1 ),
			array( $join( $project['papers'] ?? array(), static fn( array $paper ): string => ( $paper['title'] ?? '' ) . ' ' . ( $paper['container'] ?? '' ) ), 1 ),
			array( (string) ( $project['geo']['note'] ?? '' ), 1 ),
		);
	}

	/**
	 * Whether two words are at most one insertion, deletion or substitution apart,
	 * counted in UTF-16 code units as JavaScript counts a string.
	 *
	 * @param string $a One word.
	 * @param string $b Another.
	 */
	public static function within_one_edit( string $a, string $b ): bool {
		$a      = self::units( $a );
		$b      = self::units( $b );
		$length = array( count( $a ), count( $b ) );
		if ( abs( $length[0] - $length[1] ) > 1 ) {
			return false;
		}
		$i     = 0;
		$j     = 0;
		$edits = 0;
		while ( $i < $length[0] && $j < $length[1] ) {
			if ( $a[ $i ] === $b[ $j ] ) {
				++$i;
				++$j;
				continue;
			}
			if ( ++$edits > 1 ) {
				return false;
			}
			if ( $length[0] > $length[1] ) {
				++$i;
			} elseif ( $length[1] > $length[0] ) {
				++$j;
			} else {
				++$i;
				++$j;
			}
		}
		return $edits + ( $length[0] - $i ) + ( $length[1] - $j ) <= 1;
	}

	/**
	 * Text as UTF-16 code units, which is how JavaScript measures and indexes a string.
	 *
	 * @param string $text The text.
	 * @return list<string>
	 */
	private static function units( string $text ): array {
		$utf16 = (string) mb_convert_encoding( $text, 'UTF-16BE', 'UTF-8' );
		return '' === $utf16 ? array() : str_split( $utf16, 2 );
	}

	/**
	 * A project's relevance to the search text, or 0 if it doesn't match.
	 *
	 * Every word has to appear somewhere: adding a word narrows the results. For each
	 * word the best hit counts: one at the start of a word ("evac" in "evacuation") at
	 * double the field's weight; one inside a word at its weight; and, for a word of
	 * four letters or more, a word one typo away ("forcast") at half its weight.
	 *
	 * @param array<string, mixed> $project The project.
	 * @param string               $q       The search text.
	 */
	public static function score_project( array $project, string $q ): float {
		$words = self::terms( $q );
		if ( array() === $words ) {
			return 1.0;
		}
		$fields = array();
		foreach ( self::fields( $project ) as list( $text, $weight ) ) {
			$folded   = self::fold( $text );
			$fields[] = array(
				'text'   => $folded,
				'weight' => $weight,
				'tokens' => self::words( (array) preg_split( '/[^\p{L}\p{N}]+/u', $folded ) ),
			);
		}

		$score = 0.0;
		foreach ( $words as $word ) {
			$starts_word = '/(^|[^\p{L}\p{N}])' . preg_quote( $word, '/' ) . '/u';
			$long        = count( self::units( $word ) ) >= 4;
			$best        = 0.0;
			foreach ( $fields as $field ) {
				if ( str_contains( $field['text'], $word ) ) {
					$best = max( $best, $field['weight'] * ( 1 === preg_match( $starts_word, $field['text'] ) ? 2.0 : 1.0 ) );
					continue;
				}
				if ( ! $long ) {
					continue;
				}
				foreach ( $field['tokens'] as $token ) {
					if ( self::within_one_edit( $word, $token ) ) {
						$best = max( $best, $field['weight'] * 0.5 );
						break;
					}
				}
			}
			if ( 0.0 === $best ) {
				return 0.0;
			}
			$score += $best;
		}
		return $score;
	}

	/**
	 * Whether a project passes the query's facets, leaving out $skip if it's given.
	 *
	 * @param array<string, mixed> $project The project.
	 * @param array<string, mixed> $query   The query.
	 * @param string|null          $skip    A facet to leave out.
	 */
	private static function matches_facets( array $project, array $query, ?string $skip = null ): bool {
		foreach ( self::FACETS as $facet ) {
			if ( $facet === $skip || ! Js::truthy( $query[ $facet ] ?? null ) ) {
				continue;
			}
			if ( ! in_array( $query[ $facet ], self::facet_values( $project, $facet ), true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A project's values for one facet, as the explorer counts and filters them: its
	 * fire phases, its effective regions (a national study counts in every GACC), its
	 * completion year, or its project type.
	 *
	 * @param array<string, mixed> $project The project.
	 * @param string               $facet   phase, region, year or type.
	 * @return list<mixed>
	 */
	public static function facet_values( array $project, string $facet ): array {
		return match ( $facet ) {
			'phase'  => array_values( $project['firePhases'] ?? array() ),
			'region' => Derive::effective_regions( $project['geo']['regions'] ?? array() ),
			'year'   => array( $project['completionYear'] ?? null ),
			'type'   => Js::truthy( $project['projectType'] ?? null ) ? array( $project['projectType'] ) : array(),
			default  => array(),
		};
	}

	/**
	 * The sort a query lists by: its own, unless that's relevance with no text; or else
	 * relevance when there's text, and newest when there isn't.
	 *
	 * @param array<string, mixed> $query The query.
	 */
	public static function effective_sort( array $query ): string {
		$has_text = '' !== Js::trim( (string) ( $query['q'] ?? '' ) );
		$sort     = $query['sort'] ?? null;
		if ( Js::truthy( $sort ) && ( 'relevance' !== $sort || $has_text ) ) {
			return (string) $sort;
		}
		return $has_text ? 'relevance' : 'newest';
	}

	/**
	 * The projects that match the query, in its sort.
	 *
	 * @param list<array<string, mixed>> $projects The projects.
	 * @param array<string, mixed>       $query    The query.
	 * @return list<array<string, mixed>>
	 */
	public static function filter_projects( array $projects, array $query ): array {
		$scored = array();
		foreach ( $projects as $project ) {
			if ( ! self::matches_facets( $project, $query ) ) {
				continue;
			}
			$score = self::score_project( $project, (string) ( $query['q'] ?? '' ) );
			if ( $score > 0 ) {
				$scored[] = array( $project, $score );
			}
		}

		$sort = self::effective_sort( $query );
		usort(
			$scored,
			static function ( array $a, array $b ) use ( $sort ): int {
				$first = match ( $sort ) {
					'relevance' => $b[1] <=> $a[1],
					'oldest'    => $a[0]['completionYear'] <=> $b[0]['completionYear'],
					default     => 0,
				};
				if ( 0 !== $first ) {
					return $first;
				}
				return in_array( $sort, array( 'oldest', 'title' ), true ) ? Derive::compare_titles( $a[0]['title'], $b[0]['title'] ) : Derive::by_recency( $a[0], $b[0] );
			}
		);
		return array_map( static fn( array $entry ): array => $entry[0], $scored );
	}

	/**
	 * How many results each value of one facet would give, holding every other filter
	 * and the search text, so a reader sees a dead end before choosing it.
	 *
	 * @param list<array<string, mixed>> $projects  The projects.
	 * @param array<string, mixed>       $query     The query.
	 * @param string                     $facet     The facet.
	 * @param callable                   $values_of A project's values for it.
	 * @return array<string|int, int>
	 */
	public static function facet_counts( array $projects, array $query, string $facet, callable $values_of ): array {
		$counts = array();
		foreach ( $projects as $project ) {
			if ( ! self::matches_facets( $project, $query, $facet ) || 0.0 === self::score_project( $project, (string) ( $query['q'] ?? '' ) ) ) {
				continue;
			}
			foreach ( $values_of( $project ) as $value ) {
				$counts[ $value ] = ( $counts[ $value ] ?? 0 ) + 1;
			}
		}
		return $counts;
	}
}
