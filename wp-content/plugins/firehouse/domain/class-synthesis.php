<?php
/**
 * The checks on a synthesis pasted into the synthesis screen.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the JSON the synthesis prompt produces (`notebooklm/README.md`) and says
 * everything wrong with it, as the plan's synthesis screen does:
 *
 * - **Its shape.** A list of the four topic areas, each once, by key, each with up to
 *   five needs; each need with its text, its project count (`mentions`) and the
 *   projects it draws on. A field the site doesn't show is ignored.
 * - **Its projects.** Each is named by its page slug or its survey response ID, and
 *   must be one the site shows: a project that isn't on the site, or isn't published,
 *   is named with the need it's in, since the synthesis may only draw on what the
 *   site shows. A name that isn't a project's gets the page slug nearest it, or the
 *   one it cuts short, or the one whose title it is, when one clearly is, for the
 *   reviewer to judge.
 * - **Its counts.** A project counts once, so a need's count is the number of
 *   projects it names.
 * - **Its order.** Each area's needs run by project count, highest first, as the
 *   About page says. A tie is the reviewer's to order.
 *
 * Nothing is reworded, merged or dropped: the reviewer edits the paste and checks it
 * again.
 */
final class Synthesis {

	/**
	 * The most needs an area shows.
	 */
	public const MOST = 5;

	/**
	 * The most problems listed; the rest are counted.
	 */
	private const LISTED = 50;

	/**
	 * How much of a need's text a problem quotes.
	 */
	private const QUOTED = 80;

	/**
	 * The longest name the check looks for a close page slug to: WordPress's longest
	 * slug.
	 */
	private const LONGEST_NAME = 200;

	/**
	 * Characters that show nothing on their own, beyond spaces, controls and format
	 * characters, as Edit_Screen::blank() has them.
	 */
	private const INVISIBLE = '\x{034F}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180B}-\x{180F}\x{2800}\x{3164}\x{FE00}-\x{FE0F}\x{FFA0}\x{E0100}-\x{E01EF}';

	/**
	 * Whether text would show nothing: only spaces, control and format characters, and
	 * other characters that show nothing on their own.
	 *
	 * @param string $text The text.
	 */
	public static function blank( string $text ): bool {
		return 1 !== preg_match( '/[^\p{Z}\p{Cc}\p{Cf}' . self::INVISIBLE . ']/u', $text );
	}

	/**
	 * The first character in text that a visitor wouldn't see but that would still be
	 * there, as U+ and its code: a control character, or a format character such as a
	 * zero-width space, a direction override, which turns what follows it around, or a
	 * tag character, which a language model reads though no one sees it. Null when
	 * there's none.
	 *
	 * @param string $text The text.
	 */
	public static function hidden( string $text ): ?string {
		if ( 1 !== preg_match( '/[\p{Cc}\p{Cf}]/u', $text, $found ) ) {
			return null;
		}
		$bytes = array_values( (array) unpack( 'C*', $found[0] ) );
		$code  = match ( count( $bytes ) ) {
			1       => $bytes[0],
			2       => ( ( $bytes[0] & 0x1F ) << 6 ) | ( $bytes[1] & 0x3F ),
			3       => ( ( $bytes[0] & 0x0F ) << 12 ) | ( ( $bytes[1] & 0x3F ) << 6 ) | ( $bytes[2] & 0x3F ),
			default => ( ( $bytes[0] & 0x07 ) << 18 ) | ( ( $bytes[1] & 0x3F ) << 12 ) | ( ( $bytes[2] & 0x3F ) << 6 ) | ( $bytes[3] & 0x3F ),
		};
		return sprintf( 'U+%04X', $code );
	}

	/**
	 * What's wrong with a pasted synthesis, and its needs if nothing is.
	 *
	 * @param string                     $pasted   What was pasted: the JSON, perhaps in a Markdown code fence.
	 * @param list<array<string, mixed>> $projects Every project: `ref` (what a need stores for it, its post ID),
	 *                                             `slug`, `id` (its response ID), `title` and `public` (whether the site shows it).
	 * @return array{problems: list<string>, needs: array<string, list<array{text: string, mentions: int, projects: list<int>}>>}
	 */
	public static function check( string $pasted, array $projects ): array {
		$problems = array();
		$needs    = array();
		$areas    = Vocabulary::items( 'topics' );

		$text = trim( $pasted );
		if ( 1 === preg_match( '/\A```[a-zA-Z]*\s*\n(.*)\n\s*```\z/s', $text, $fenced ) ) {
			$text = trim( $fenced[1] );
		}
		if ( '' === $text ) {
			return self::result( array( 'Nothing was pasted.' ), array() );
		}
		try {
			$data = json_decode( $text, true, 64, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			return self::result( array( 'The paste isn’t JSON the screen can read (' . $e->getMessage() . '). Paste the JSON the prompt gives, from its first [ to its last ].' ), array() );
		}
		if ( ! is_array( $data ) || ! array_is_list( $data ) || array() === $data ) {
			return self::result( array( 'The paste must be a list of the four topic areas, as the prompt gives it: [ { "topic": "observe", "topNeeds": [ … ] }, … ].' ), array() );
		}

		$by_slug = array();
		$by_id   = array();
		$slugs   = array();
		foreach ( $projects as $project ) {
			$by_slug[ (string) $project['slug'] ] = $project;
			if ( '' !== (string) ( $project['id'] ?? '' ) ) {
				$by_id[ (string) $project['id'] ] = $project;
			}
			$plain = self::plain( (string) $project['slug'] );
			if ( '' !== $plain ) {
				$slugs[] = array(
					'plain'   => $plain,
					'title'   => self::plain( str_replace( '/', ' ', (string) ( $project['title'] ?? '' ) ) ),
					'project' => $project,
				);
			}
		}

		foreach ( $data as $index => $area ) {
			$key = is_array( $area ) && is_string( $area['topic'] ?? null ) ? $area['topic'] : null;
			if ( null === $key ) {
				$problems[] = sprintf( 'Item %d in the list isn’t a topic area: each is { "topic": …, "topNeeds": [ … ] }.', $index + 1 );
				continue;
			}
			if ( ! isset( $areas[ $key ] ) ) {
				$problems[] = sprintf( '“%s” isn’t a topic area. The areas are %s.', $key, implode( ', ', array_keys( $areas ) ) );
				continue;
			}
			$label = (string) $areas[ $key ]['label'];
			if ( isset( $needs[ $key ] ) ) {
				$problems[] = sprintf( '%s is in the paste twice.', $label );
				continue;
			}
			$list = $area['topNeeds'] ?? null;
			if ( ! is_array( $list ) || ! array_is_list( $list ) ) {
				$problems[]    = sprintf( '%s: its topNeeds must be a list of needs.', $label );
				$needs[ $key ] = array();
				continue;
			}
			if ( count( $list ) > self::MOST ) {
				$problems[] = sprintf( '%1$s has %2$d needs; an area shows up to %3$d.', $label, count( $list ), self::MOST );
			}
			$needs[ $key ] = array();
			$before        = count( $problems );
			foreach ( $list as $position => $need ) {
				$checked = self::need( $need, $label, $position + 1, $by_slug, $by_id, $slugs, $problems );
				if ( null !== $checked ) {
					$needs[ $key ][] = $checked;
				}
			}
			// The order once the needs themselves are right: a count that isn't one would
			// put the order wrong as well.
			if ( count( $problems ) === $before ) {
				$problems = array_merge( $problems, self::order( $needs[ $key ], $label ) );
			}
		}

		foreach ( $areas as $key => $area ) {
			if ( ! isset( $needs[ $key ] ) ) {
				$problems[] = sprintf( '%s isn’t in the paste. Every topic area needs its list, an empty one if no project supports a need there.', $area['label'] );
			}
		}

		if ( array() !== $problems ) {
			return self::result( $problems, array() );
		}
		$ordered = array();
		foreach ( array_keys( $areas ) as $key ) {
			$ordered[ $key ] = $needs[ $key ];
		}
		return self::result( array(), $ordered );
	}

	/**
	 * One need, checked: its text trimmed, its count, and its projects by `ref`; or null
	 * when it can't be read at all.
	 *
	 * @param mixed                               $need     The need as pasted.
	 * @param string                              $label    Its area's label.
	 * @param int                                 $number   Its place in the area, from 1.
	 * @param array<string, array<string, mixed>> $by_slug  The projects by slug.
	 * @param array<string, array<string, mixed>> $by_id    The projects by response ID.
	 * @param list<array<string, mixed>>          $slugs    Each project with a slug, and its slug and title as closest() compares them.
	 * @param string[]                            $problems What's wrong so far, added to.
	 * @return array{text: string, mentions: int, projects: list<int>}|null
	 */
	private static function need( mixed $need, string $label, int $number, array $by_slug, array $by_id, array $slugs, array &$problems ): ?array {
		if ( ! is_array( $need ) || ( array_is_list( $need ) && array() !== $need ) ) {
			$problems[] = sprintf( '%1$s, need %2$d: isn’t a need: each is { "text": …, "mentions": …, "projects": [ … ] }.', $label, $number );
			return null;
		}
		$text   = is_string( $need['text'] ?? null ) ? trim( $need['text'] ) : '';
		$blank  = self::blank( $text );
		$hidden = self::hidden( $text );
		// Text with a character that doesn't show isn't quoted: a direction override in
		// it would turn the rest of the problem around.
		$where = $blank || null !== $hidden ? sprintf( '%1$s, need %2$d', $label, $number ) : sprintf( '%1$s, need %2$d (“%3$s”)', $label, $number, self::quote( $text ) );
		if ( $blank ) {
			$problems[] = $where . ': has no text.';
		} elseif ( null !== $hidden ) {
			$problems[] = sprintf( '%1$s: holds a character visitors wouldn’t see (%2$s), such as a zero-width space or a direction override. Take it out and check again.', $where, $hidden );
		}

		$refs  = $need['projects'] ?? null;
		$found = array();
		if ( ! is_array( $refs ) || ! array_is_list( $refs ) || array() === $refs ) {
			$problems[] = $where . ': names no projects. Each need lists the projects it draws on, by page slug or response ID.';
		} else {
			foreach ( $refs as $ref ) {
				if ( ! is_string( $ref ) || '' === trim( $ref ) ) {
					$problems[] = $where . ': names a project by something other than its page slug or response ID.';
					continue;
				}
				$ref    = trim( $ref );
				$unseen = self::hidden( $ref );
				if ( null !== $unseen ) {
					$problems[] = sprintf( '%1$s: names a project by a name holding a character visitors wouldn’t see (%2$s), such as a zero-width space or a direction override. Take it out and check again.', $where, $unseen );
					continue;
				}
				$project = $by_slug[ $ref ] ?? $by_id[ $ref ] ?? null;
				if ( null === $project ) {
					$problem = sprintf( '%1$s: names “%2$s”, which isn’t a project on the site.', $where, self::quote( $ref ) );
					// Only while the problem would be listed: the rest are counted, not read.
					$closest = count( $problems ) < self::LISTED ? self::closest( $ref, $slugs ) : null;
					if ( null !== $closest && true === $closest['public'] ) {
						$problem .= sprintf( ' The nearest published page slug is “%1$s”, for “%2$s”. If the need draws on that project, change the name to it and check again.', $closest['slug'], self::title( $closest ) );
					} elseif ( null !== $closest ) {
						$problem .= sprintf( ' The nearest page slug is “%1$s”, for “%2$s”, which the site doesn’t show: the synthesis may only draw on published projects.', $closest['slug'], self::title( $closest ) );
					}
					$problems[] = $problem;
					continue;
				}
				if ( true !== $project['public'] ) {
					$problems[] = sprintf( '%1$s: names “%2$s”, %3$s, which the site doesn’t show: the synthesis may only draw on published projects.', $where, $ref, self::title( $project ) );
					continue;
				}
				if ( in_array( $project['ref'], $found, true ) ) {
					$problems[] = sprintf( '%1$s: names %2$s twice.', $where, self::title( $project ) );
					continue;
				}
				$found[] = $project['ref'];
			}
		}

		$mentions = $need['mentions'] ?? null;
		if ( ! is_int( $mentions ) || $mentions < 0 ) {
			$problems[] = $where . ': its project count, mentions, must be a whole number.';
			$mentions   = count( $found );
		} elseif ( is_array( $refs ) && array_is_list( $refs ) && array() !== $refs && count( $refs ) !== $mentions ) {
			$problems[] = sprintf( '%1$s: counts %2$d projects but names %3$d. A project counts once, so the two must match.', $where, $mentions, count( $refs ) );
		}

		return array(
			'text'     => $text,
			'mentions' => $mentions,
			'projects' => $found,
		);
	}

	/**
	 * The project whose page slug is nearest a name that isn't a project's, for the
	 * problem to give. A model copying a long slug can shorten or reword part of it
	 * ("…-dss" for "…-decision-support"), or give the page's path or the project's
	 * title instead. Both are compared in lower case, with each run of other characters
	 * as one hyphen, and the name without any path before its last slash.
	 *
	 * A slug is given only when no more than a third of its characters would have to
	 * change, and it's clearly the nearest: every other slug, published or not, needs
	 * at least two more changes, or half as many again, whichever is more (one more,
	 * for a slug that matches exactly). Before that, the slug the name cuts short
	 * (start()); after it, the project whose title, in slug form, is clearly nearest
	 * by the same measure, since a model can make its own slug from the title
	 * ("…-products-within-a-fire-partner-decision-making-context" for "…-products").
	 * The project may be one the site doesn't show, which the problem then says. Null
	 * when no slug is given.
	 *
	 * @param string                     $name  The name the need gives.
	 * @param list<array<string, mixed>> $slugs Each project with a slug, and its slug and title as compared.
	 * @return array<string, mixed>|null
	 */
	private static function closest( string $name, array $slugs ): ?array {
		$name = self::plain( $name );
		if ( '' === $name || strlen( $name ) > self::LONGEST_NAME ) {
			return null;
		}
		// Each way is tried only when the one before finds nothing near: one that finds
		// two about as near gives nothing, rather than letting the next pick one of them.
		foreach ( array( 'start', 'plain', 'title' ) as $way ) {
			$found = 'start' === $way ? self::start( $name, $slugs ) : self::nearest( $name, $slugs, $way );
			if ( null !== $found ) {
				return false === $found ? null : $found;
			}
		}
		return null;
	}

	/**
	 * The project whose slug, or title in slug form, is clearly nearest the name, with no
	 * more than a third of it to change (closest()); false when two are about as near;
	 * null when none is near.
	 *
	 * @param string                     $name  The name, as plain() gives it.
	 * @param list<array<string, mixed>> $slugs Each project with a slug, and its slug and title as compared.
	 * @param string                     $key   "plain" for the slugs, "title" for the titles.
	 * @return array<string, mixed>|false|null
	 */
	private static function nearest( string $name, array $slugs, string $key ): array|false|null {
		$nearest = null;
		$least   = PHP_INT_MAX;
		$next    = PHP_INT_MAX;
		foreach ( $slugs as $slug ) {
			// The distance is at least the difference in length, so a slug that can be
			// neither the nearest nor near enough to leave the nearest in doubt isn't
			// measured.
			if ( '' === $slug[ $key ] || abs( strlen( $name ) - strlen( $slug[ $key ] ) ) >= self::clear( $least ) ) {
				continue;
			}
			$distance = levenshtein( $name, $slug[ $key ] );
			if ( $distance < $least ) {
				$next    = $least;
				$least   = $distance;
				$nearest = $slug;
			} elseif ( $distance < $next ) {
				$next = $distance;
			}
		}
		if ( null === $nearest || $least > intdiv( strlen( $nearest[ $key ] ), 3 ) ) {
			return null;
		}
		return $next < self::clear( $least ) ? false : $nearest['project'];
	}

	/**
	 * The project whose slug the name cuts short: the only slug that starts with the
	 * name's words, of which the name has at least two-thirds. A model can stop a long
	 * slug early ("…-fire-weather" for "…-fire-weather-decision-support"), which leaves
	 * the end too far from it to be nearest, letter by letter, when another slug begins
	 * the same way. False when it begins more than one slug; null when it begins none,
	 * or too little of one.
	 *
	 * @param string                     $name  The name, as plain() gives it.
	 * @param list<array<string, mixed>> $slugs Each project with a slug, and its slug as compared.
	 * @return array<string, mixed>|false|null
	 */
	private static function start( string $name, array $slugs ): array|false|null {
		$started = array_values( array_filter( $slugs, static fn( array $slug ): bool => str_starts_with( $slug['plain'], $name . '-' ) ) );
		if ( count( $started ) > 1 ) {
			return false;
		}
		if ( array() === $started || 3 * ( substr_count( $name, '-' ) + 1 ) < 2 * ( substr_count( $started[0]['plain'], '-' ) + 1 ) ) {
			return null;
		}
		return $started[0]['project'];
	}

	/**
	 * How far the next slug must be for the nearest, this far, to be clearly nearest.
	 *
	 * @param int $least The nearest slug's distance.
	 */
	private static function clear( int $least ): int {
		if ( PHP_INT_MAX === $least ) {
			return PHP_INT_MAX;
		}
		return 0 === $least ? 1 : $least + max( 2, intdiv( $least + 1, 2 ) );
	}

	/**
	 * Text as a problem quotes it: cut short when it's long.
	 *
	 * @param string $text The text.
	 */
	private static function quote( string $text ): string {
		return mb_strlen( $text ) > self::QUOTED ? mb_substr( $text, 0, self::QUOTED - 1 ) . '…' : $text;
	}

	/**
	 * A project's title as a problem names it: without control or format characters,
	 * since a direction override among them would turn the rest of the problem around.
	 * Only the problem's copy changes; the title stays as the researcher wrote it.
	 *
	 * @param array<string, mixed> $project The project.
	 */
	private static function title( array $project ): string {
		return (string) preg_replace( '/[\p{Cc}\p{Cf}]/u', '', (string) $project['title'] );
	}

	/**
	 * A name as the closest slug is found: without any path before its last slash, in
	 * lower case, each run of characters other than letters and digits one hyphen.
	 *
	 * @param string $name The name.
	 */
	private static function plain( string $name ): string {
		$name = trim( $name, "/ \t\n\r" );
		$last = strrpos( $name, '/' );
		if ( false !== $last ) {
			$name = substr( $name, $last + 1 );
		}
		return trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $name ) ), '-' );
	}

	/**
	 * Where an area's needs don't run by project count, highest first.
	 *
	 * @param list<array{text: string, mentions: int, projects: list<int>}> $needs The area's needs.
	 * @param string                                                        $label Its label.
	 * @return list<string>
	 */
	private static function order( array $needs, string $label ): array {
		$problems = array();
		for ( $i = 1, $n = count( $needs ); $i < $n; $i++ ) {
			if ( $needs[ $i ]['mentions'] > $needs[ $i - 1 ]['mentions'] ) {
				$problems[] = sprintf( '%1$s: its needs don’t run by project count, highest first: need %2$d counts %3$d, more than need %4$d above it, which counts %5$d.', $label, $i + 1, $needs[ $i ]['mentions'], $i, $needs[ $i - 1 ]['mentions'] );
			}
		}
		return $problems;
	}

	/**
	 * A result.
	 *
	 * @param string[]                                                                     $problems What's wrong.
	 * @param array<string, list<array{text: string, mentions: int, projects: list<int>}>> $needs    The needs by area, when nothing is.
	 * @return array{problems: list<string>, needs: array<string, list<array{text: string, mentions: int, projects: list<int>}>>}
	 */
	private static function result( array $problems, array $needs ): array {
		if ( count( $problems ) > self::LISTED ) {
			$more     = count( $problems ) - self::LISTED;
			$problems = array_slice( $problems, 0, self::LISTED );
			/* The rest wait for the next check. */
			$problems[] = sprintf( '…and %d more. Fix these and check again to see them.', $more );
		}
		return array(
			'problems' => $problems,
			'needs'    => $needs,
		);
	}
}
