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
	 * The most words a search counts, as MAX_TERMS in search.ts; any after them are
	 * ignored. No real search comes near it, and it bounds the work one long query
	 * string can ask of the server.
	 */
	public const MAX_TERMS = 20;

	/**
	 * The largest year a query keeps, JavaScript's Number.MAX_SAFE_INTEGER: past it a
	 * number isn't exact in JavaScript, and wouldn't be written back the same.
	 */
	private const MAX_YEAR = 9007199254740991;

	/**
	 * Latin letters with accents, one for one with UNACCENTED: what fold_without_intl()
	 * strips on a server without the intl extension. Each pair is a letter and what
	 * Unicode's decomposition (NFD) leaves of it once the combining marks U+0300 to
	 * U+036F are dropped, for every letter from U+00C0 to U+024F and U+1E00 to U+1EFF
	 * whose fold changes it.
	 */
	private const ACCENTED = 'ÀÁÂÃÄÅÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜÝàáâãäåçèéêëìíîïñòóôõöùúûüýÿĀāĂăĄąĆćĈĉĊċČčĎďĒēĔĕĖėĘęĚěĜĝĞğĠġĢģĤĥĨĩĪīĬĭĮįİĴĵĶķĹĺĻļĽľŃńŅņŇňŌōŎŏŐőŔŕŖŗŘřŚśŜŝŞşŠšŢţŤťŨũŪūŬŭŮůŰűŲųŴŵŶŷŸŹźŻżŽžƠơƯưǍǎǏǐǑǒǓǔǕǖǗǘǙǚǛǜǞǟǠǡǢǣǦǧǨǩǪǫǬǭǮǯǰǴǵǸǹǺǻǼǽǾǿȀȁȂȃȄȅȆȇȈȉȊȋȌȍȎȏȐȑȒȓȔȕȖȗȘșȚțȞȟȦȧȨȩȪȫȬȭȮȯȰȱȲȳḀḁḂḃḄḅḆḇḈḉḊḋḌḍḎḏḐḑḒḓḔḕḖḗḘḙḚḛḜḝḞḟḠḡḢḣḤḥḦḧḨḩḪḫḬḭḮḯḰḱḲḳḴḵḶḷḸḹḺḻḼḽḾḿṀṁṂṃṄṅṆṇṈṉṊṋṌṍṎṏṐṑṒṓṔṕṖṗṘṙṚṛṜṝṞṟṠṡṢṣṤṥṦṧṨṩṪṫṬṭṮṯṰṱṲṳṴṵṶṷṸṹṺṻṼṽṾṿẀẁẂẃẄẅẆẇẈẉẊẋẌẍẎẏẐẑẒẓẔẕẖẗẘẙẛẠạẢảẤấẦầẨẩẪẫẬậẮắẰằẲẳẴẵẶặẸẹẺẻẼẽẾếỀềỂểỄễỆệỈỉỊịỌọỎỏỐốỒồỔổỖỗỘộỚớỜờỞởỠỡỢợỤụỦủỨứỪừỬửỮữỰựỲỳỴỵỶỷỸỹ';

	/**
	 * The letters ACCENTED becomes, in the same order.
	 */
	private const UNACCENTED = 'AAAAAACEEEEIIIINOOOOOUUUUYaaaaaaceeeeiiiinooooouuuuyyAaAaAaCcCcCcCcDdEeEeEeEeEeGgGgGgGgHhIiIiIiIiIJjKkLlLlLlNnNnNnOoOoOoRrRrRrSsSsSsSsTtTtUuUuUuUuUuUuWwYyYZzZzZzOoUuAaIiOoUuUuUuUuUuAaAaÆæGgKkOoOoƷʒjGgNnAaÆæØøAaAaEeEeIiIiOoOoRrRrUuUuSsTtHhAaEeOoOoOoOoYyAaBbBbBbCcDdDdDdDdDdEeEeEeEeEeFfGgHhHhHhHhHhIiIiKkKkKkLlLlLlLlMmMmMmNnNnNnNnOoOoOoOoPpPpRrRrRrRrSsSsSsSsSsTtTtTtTtUuUuUuUuUuVvVvWwWwWwWwWwXxXxYyZzZzZzhtwyſAaAaAaAaAaAaAaAaAaAaAaAaEeEeEeEeEeEeEeEeIiIiOoOoOoOoOoOoOoOoOoOoOoOoUuUuUuUuUuUuUuYyYyYyYy';

	/**
	 * The parameters of a query string as URLSearchParams reads them: a leading "?"
	 * dropped, "+" as a space, percent-escapes decoded, bytes that aren't UTF-8 replaced,
	 * and the first value kept when a name repeats, unlike PHP's $_GET. A name with
	 * brackets, such as "phase[]", is just another name, not an array.
	 *
	 * Under WordPress, pass the query part of wp_unslash( $_SERVER['REQUEST_URI'] ), as
	 * Explorer_Page::for_request() does: WordPress adds slashes to $_SERVER, which would
	 * double a backslash or a quote, and a server's rewrite rules can change
	 * QUERY_STRING. Not $_GET, which keeps the last of a repeated name and makes arrays,
	 * and not through sanitize_text_field(), which deletes percent-escapes: this decodes
	 * the text, and the query keeps only what parse_query() accepts.
	 *
	 * @param string $query The query string, unslashed.
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
	 * @return array{q: string, phase: string|null, region: string|null, year: int|null, type: string|null, sort: string|null}
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
			'year'   => is_finite( $year ) && floor( $year ) === $year && $year > 0 && $year <= self::MAX_YEAR ? (int) $year : null,
			'type'   => '' === $type ? null : $type,
			'sort'   => in_array( $sort, self::SORTS, true ) ? $sort : null,
		);
	}

	/**
	 * A query as a query string, leaving out what's unset so addresses stay short, in a
	 * fixed order, encoded as URLSearchParams encodes it: a space as "+", and every byte
	 * but ASCII letters, digits and "*-._" escaped.
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
	 * fold_without_intl() is used instead. Before PHP 8.3, mb_strtolower() lower-cases a
	 * final Greek sigma as σ where JavaScript writes ς, so a Greek word ending in it
	 * can score differently there.
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
	 * The folding without intl: Latin letters lose their accents as they would with
	 * intl, from the ACCENTED table, and combining marks stored as separate characters
	 * are dropped. Accented letters of other alphabets, such as Greek ά or Cyrillic й,
	 * keep their accents, where JavaScript would drop them.
	 *
	 * @param string $text The text.
	 */
	public static function fold_without_intl( string $text ): string {
		static $table = null;
		$table      ??= array_combine( mb_str_split( self::ACCENTED, 1, 'UTF-8' ), mb_str_split( self::UNACCENTED, 1, 'UTF-8' ) );
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
		return array_slice( self::words( (array) preg_split( '/[' . Js::SPACE . ']+/u', self::fold( $q ) ) ), 0, self::MAX_TERMS );
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
		// An entry that isn't the shape the TypeScript expects (a paper stored as plain
		// text, say) gives nothing, as it does there, rather than an error on every search.
		$join = static fn( mixed $items, callable $text ): string => implode( ' ', array_map( $text, is_array( $items ) ? $items : array() ) );
		$of   = static fn( mixed $item, string $key ): string => is_array( $item ) ? self::text( $item[ $key ] ?? null ) : '';
		return array(
			array( self::text( $project['title'] ?? null ), 6 ),
			array( self::text( $project['projectType'] ?? null ), 4 ),
			array( $join( $project['authors'] ?? null, static fn( mixed $author ): string => $of( $author, 'name' ) ), 4 ),
			array( self::text( $project['org'] ?? null ), 3 ),
			array( self::text( $project['summary'] ?? null ), 3 ),
			array( self::text( $project['abstract'] ?? null ), 2 ),
			array( $join( $project['takeaways'] ?? null, array( self::class, 'text' ) ), 1 ),
			array( $join( $project['methods'] ?? null, array( self::class, 'text' ) ), 2 ),
			array( $join( $project['needs'] ?? null, static fn( mixed $need ): string => $of( $need, 'need' ) . ' ' . $of( $need, 'recommendation' ) ), 1 ),
			array( $join( $project['papers'] ?? null, static fn( mixed $paper ): string => $of( $paper, 'title' ) . ' ' . $of( $paper, 'container' ) ), 1 ),
			array( self::text( is_array( $project['geo'] ?? null ) ? ( $project['geo']['note'] ?? null ) : null ), 1 ),
		);
	}

	/**
	 * A stored value as searchable text: a string as it is, a number or true as its
	 * digits or "1", and anything else, null and lists included, as nothing.
	 *
	 * @param mixed $value The value.
	 */
	public static function text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Whether two words are at most one insertion, deletion or substitution apart,
	 * counted in UTF-16 code units as JavaScript counts a string.
	 *
	 * @param string $a One word.
	 * @param string $b Another.
	 */
	public static function within_one_edit( string $a, string $b ): bool {
		return self::units_within_one_edit( self::units( $a ), self::units( $b ) );
	}

	/**
	 * The same test as within_one_edit(), on words already split into UTF-16 code units.
	 *
	 * @param string[] $a One word's units.
	 * @param string[] $b Another's.
	 */
	private static function units_within_one_edit( array $a, array $b ): bool {
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
				'tokens' => null,
			);
		}

		$score = 0.0;
		$found = array();
		foreach ( $words as $word ) {
			// A word repeated in the search scores as it did the first time.
			$found[ $word ] ??= self::best_hit( $word, $fields );
			if ( 0.0 === $found[ $word ] ) {
				return 0.0;
			}
			$score += $found[ $word ];
		}
		return $score;
	}

	/**
	 * One search word's best hit across a project's folded fields, or 0. A field's tokens,
	 * in UTF-16 code units, are worked out only when a word needs a typo match, and then
	 * once.
	 *
	 * @param string                     $word   The folded word.
	 * @param list<array<string, mixed>> $fields The fields: `text`, `weight` and `tokens`.
	 */
	private static function best_hit( string $word, array &$fields ): float {
		$starts_word = '/(^|[^\p{L}\p{N}])' . preg_quote( $word, '/' ) . '/u';
		$units       = self::units( $word );
		$best        = 0.0;
		foreach ( $fields as &$field ) {
			if ( str_contains( $field['text'], $word ) ) {
				$best = max( $best, $field['weight'] * ( 1 === preg_match( $starts_word, $field['text'] ) ? 2.0 : 1.0 ) );
				continue;
			}
			if ( count( $units ) < 4 || $field['weight'] * 0.5 <= $best ) {
				continue;
			}
			$field['tokens'] ??= array_map( array( self::class, 'units' ), self::words( (array) preg_split( '/[^\p{L}\p{N}]+/u', $field['text'] ) ) );
			foreach ( $field['tokens'] as $token ) {
				if ( abs( count( $token ) - count( $units ) ) <= 1 && self::units_within_one_edit( $units, $token ) ) {
					$best = max( $best, $field['weight'] * 0.5 );
					break;
				}
			}
		}
		unset( $field );
		return $best;
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
			$wanted = $query[ $facet ];
			$found  = array_filter( self::facet_values( $project, $facet ), static fn( mixed $value ): bool => self::same( $value, $wanted ) );
			if ( array() === $found ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether two values are equal as JavaScript's includes() and Map keys compare them:
	 * numbers by value, so 2025 and 2025.0 are one year, and anything else strictly.
	 *
	 * @param mixed $a One value.
	 * @param mixed $b Another.
	 */
	private static function same( mixed $a, mixed $b ): bool {
		if ( ( is_int( $a ) || is_float( $a ) ) && ( is_int( $b ) || is_float( $b ) ) ) {
			return (float) $a === (float) $b;
		}
		return $a === $b;
	}

	/**
	 * A facet value as a key of the counts: a whole number as an integer, as 2025.0 is
	 * the year 2025 in JavaScript, another number as its text, and a string as it is.
	 *
	 * @param mixed $value The value.
	 * @return string|int
	 */
	public static function key( mixed $value ): string|int {
		if ( is_float( $value ) && is_finite( $value ) && floor( $value ) === $value && abs( $value ) <= self::MAX_YEAR ) {
			return (int) $value;
		}
		return is_int( $value ) ? $value : self::text( $value );
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
				$key            = self::key( $value );
				$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
			}
		}
		return $counts;
	}
}
