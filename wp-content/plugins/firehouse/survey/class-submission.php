<?php
/**
 * A survey submission: read, checked and turned into a project's fields.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Survey;

use Firehouse\Domain\Email_Shape;
use Firehouse\Domain\Synthesis;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a researcher sent, without WordPress: read from the form's fields, checked,
 * and turned into a draft project's fields exactly as `scripts/import-survey.mjs`
 * turns a survey row into one (rule 1: the researchers' words as they wrote them,
 * spaces tidied as the import tidies them), with the private answers apart (rule 2).
 */
final class Submission {

	/**
	 * The form's fields, under one name, so none of them can be read as one of
	 * WordPress's query variables (`title`, `year`), which it takes from a POST too.
	 */
	public const FIELD = 'firehouse_survey';

	/**
	 * Answers that are a researcher's public words, checked for an email address
	 * (rule 2): the email has a question of its own, which only the team sees.
	 */
	private const PUBLIC_TEXT = array( 'submitter', 'org', 'title', 'coResearchers', 'abstract', 'takeaways', 'needs', 'projectTypeOther', 'methodsOther' );

	/**
	 * The answers in a POST, by question: text as a string, a choice as its value, a
	 * set of choices or entries as a list of strings, and anything else as nothing.
	 * Entries keep their boxes, empty ones too, so the form shows what was sent.
	 *
	 * @param mixed $sent The form's fields, unslashed: `$_POST[ self::FIELD ]`.
	 * @return array<string, string|list<string>>
	 */
	public static function read( mixed $sent ): array {
		$sent   = is_array( $sent ) ? $sent : array();
		$values = array();
		foreach ( Questions::all() as $name => $question ) {
			$value = $sent[ $name ] ?? null;
			if ( in_array( $question['type'], array( 'choices', 'entries' ), true ) ) {
				$values[ $name ] = is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : array();
			} else {
				$values[ $name ] = is_string( $value ) ? $value : '';
			}
			if ( isset( $question['other'] ) ) {
				$values[ $name . 'Other' ] = is_string( $sent[ $name . 'Other' ] ?? null ) ? $sent[ $name . 'Other' ] : '';
			}
		}
		return $values;
	}

	/**
	 * What's wrong with the answers, by the id of the field it's in (an entry's is its
	 * question's name, a hyphen and its place from 1), in the form's order.
	 *
	 * @param array<string, string|list<string>> $values The answers, as read().
	 * @return array<string, string>
	 */
	public static function check( array $values ): array {
		$problems = array();
		foreach ( Questions::all() as $name => $question ) {
			$value = $values[ $name ] ?? ( in_array( $question['type'], array( 'choices', 'entries' ), true ) ? array() : '' );
			$label = (string) $question['name'];
			switch ( $question['type'] ) {
				case 'choice':
					if ( '' === $value ) {
						if ( $question['required'] ) {
							$problems[ $name ] = sprintf( '%s: choose one answer.', $label );
						}
					} elseif ( ! isset( $question['options'][ $value ] ) ) {
						$problems[ $name ] = sprintf( '%s: choose one of the answers given.', $label );
					}
					break;
				case 'choices':
					$chosen = array_unique( (array) $value );
					if ( array() === $chosen && $question['required'] ) {
						$problems[ $name ] = sprintf( '%s: choose at least one.', $label );
					} elseif ( array() !== array_diff( $chosen, array_keys( $question['options'] ) ) ) {
						$problems[ $name ] = sprintf( '%s: choose from the answers given.', $label );
					}
					break;
				case 'entries':
					$problems += self::entries( $name, $question, (array) $value );
					break;
				default:
					$problem = self::text( (string) $value, $question );
					if ( null !== $problem ) {
						$problems[ $name ] = $label . ': ' . $problem;
					}
			}
			if ( isset( $question['other'] ) ) {
				$chosen = (array) $value;
				$other  = (string) ( $values[ $name . 'Other' ] ?? '' );
				if ( in_array( $question['other'], $chosen, true ) && Synthesis::blank( $other ) ) {
					$problems[ $name . 'Other' ] = sprintf( '%s: say what “Other” is.', $label );
				} elseif ( mb_strlen( $other ) > (int) $question['max'] ) {
					$problems[ $name . 'Other' ] = sprintf( '%1$s: “Other” is longer than %2$d characters.', $label, $question['max'] );
				}
			}
		}

		// Rule 2: no email address among the words the site shows.
		foreach ( self::PUBLIC_TEXT as $name ) {
			foreach ( (array) ( $values[ $name ] ?? array() ) as $index => $text ) {
				$id = is_array( $values[ $name ] ?? null ) ? $name . '-' . ( $index + 1 ) : $name;
				if ( ! isset( $problems[ $id ] ) && is_string( $text ) && Email_Shape::has_email( $text ) ) {
					$problems[ $id ] = sprintf( '%s: holds an email address. Your email goes in its own question, which only the FireHouse team sees.', self::name( $name ) );
				}
			}
		}

		// In the form's order.
		$order = array();
		foreach ( array_keys( Questions::all() ) as $name ) {
			foreach ( $problems as $id => $problem ) {
				if ( $id === $name || $id === $name . 'Other' || str_starts_with( $id, $name . '-' ) ) {
					$order[ $id ] = $problem;
				}
			}
		}
		return $order;
	}

	/**
	 * The fields of the draft project an import of the same answers would write, by
	 * their names in types.ts, its slug and status aside.
	 *
	 * @param array<string, string|list<string>> $values      The answers, as read(), with nothing wrong.
	 * @param string                             $response_id The project's response ID.
	 * @return array<string, mixed>
	 */
	public static function project( array $values, string $response_id ): array {
		$questions = Questions::all();
		$other     = static fn( string $name, string $value ): string => ( $questions[ $name ]['other'] ?? null ) === $value ? self::tidy( (string) $values[ $name . 'Other' ] ) : (string) $questions[ $name ]['options'][ $value ];
		$summary   = self::tidy( (string) $values['abstract'] );
		$fields    = array(
			'id'             => $response_id,
			'title'          => self::tidy( (string) $values['title'] ),
			'summary'        => self::summary( $summary ),
			'abstract'       => $summary,
			'authors'        => self::authors( (string) $values['submitter'], (string) $values['coResearchers'] ),
			'org'            => self::tidy( (string) $values['org'] ),
			'completionYear' => (int) $values['completionYear'],
			'firePhases'     => array_values( array_unique( (array) $values['firePhases'] ) ),
			'projectType'    => $other( 'projectType', (string) $values['projectType'] ),
			'methods'        => array_values( array_map( static fn( string $method ): string => $other( 'methods', $method ), array_unique( (array) $values['methods'] ) ) ),
			'irbApproved'    => 'no' !== $values['irb'],
			'geo'            => array( 'regions' => array_values( array_unique( (array) $values['regions'] ) ) ),
			'takeaways'      => array_values( array_filter( array_map( 'trim', (array) $values['takeaways'] ), static fn( string $text ): bool => ! Synthesis::blank( $text ) ) ),
			'needs'          => array(),
			'papers'         => array(),
		);
		if ( '' === $fields['org'] ) {
			unset( $fields['org'] );
		}
		foreach ( (array) $values['needs'] as $text ) {
			if ( ! Synthesis::blank( $text ) ) {
				$fields['needs'][] = self::need( $text );
			}
		}
		foreach ( (array) $values['links'] as $link ) {
			$paper = self::paper( $link );
			if ( null !== $paper ) {
				$fields['papers'][] = $paper;
			}
		}
		return $fields;
	}

	/**
	 * The private answers (rule 2), stored apart from the project: the email, the job
	 * title, and how the results were shared, as the answers' own words.
	 *
	 * @param array<string, string|list<string>> $values The answers, as read().
	 * @return array{email: string, jobTitle: string, shared: list<string>}
	 */
	public static function private_answers( array $values ): array {
		$options = Questions::all()['shared']['options'];
		return array(
			'email'    => trim( (string) $values['email'] ),
			'jobTitle' => self::tidy( (string) $values['jobTitle'] ),
			'shared'   => array_values( array_map( static fn( string $key ): string => (string) $options[ $key ], array_unique( (array) $values['shared'] ) ) ),
		);
	}

	/**
	 * A page address from a title, as the import makes one: lower-case words, cut at a
	 * word under 60 characters, without a trailing "and" or "of".
	 *
	 * @param string $title The project's title.
	 */
	public static function slug( string $title ): string {
		// The import decomposes accents and drops the marks: "Évacuation" is "evacuation".
		$text  = (string) preg_replace( '/[^\x00-\x7F]/', '', remove_accents( $title ) );
		$words = preg_split( '/[\s_-]+/', (string) preg_replace( '/[^\w\s-]/', ' ', strtolower( $text ) ), -1, PREG_SPLIT_NO_EMPTY );
		$base  = '';
		foreach ( is_array( $words ) ? $words : array() as $word ) {
			if ( '' !== $base && strlen( $base ) + strlen( $word ) + 1 > 60 ) {
				break;
			}
			$base = '' === $base ? $word : $base . '-' . $word;
		}
		$base = (string) preg_replace( '/(?:-(?:a|an|and|for|in|of|on|or|the|to|with|within))+$/', '', $base );
		return '' === $base ? 'project' : $base;
	}

	/**
	 * The abstract's opening sentence, as the import seeds the tile's one-liner.
	 *
	 * @param string $text The abstract.
	 */
	public static function summary( string $text ): string {
		$sentence = 1 === preg_match( '/^[\s\S]*?[a-z0-9)”"][.!?](?=\s+[A-Z])/u', $text, $match ) ? $match[0] : $text;
		$sentence = trim( $sentence );
		if ( mb_strlen( $sentence ) <= 240 ) {
			return $sentence;
		}
		return (string) preg_replace( '/\s+\S*$/u', '', mb_substr( $sentence, 0, 237 ) ) . '…';
	}

	/**
	 * The researchers, the submitter first, each once, as the import lists them: a
	 * two-word name split into given and family names, any other kept whole.
	 *
	 * @param string $submitter      The submitter's name.
	 * @param string $co_researchers The co-researchers, separated by commas or semicolons.
	 * @return list<array<string, string>>
	 */
	public static function authors( string $submitter, string $co_researchers ): array {
		$authors = array();
		$seen    = array();
		foreach ( array_merge( array( $submitter ), (array) preg_split( '/[,;]/', $co_researchers ) ) as $name ) {
			$name = self::tidy( (string) $name );
			$key  = mb_strtolower( $name );
			if ( '' === $name || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$parts        = explode( ' ', $name );
			$authors[]    = 2 === count( $parts ) ? array(
				'name'   => $name,
				'given'  => $parts[0],
				'family' => $parts[1],
			) : array( 'name' => $name );
		}
		return $authors;
	}

	/**
	 * One need box as an entry: the need, and the recommendation from the first
	 * sentence that begins "Recommend", either alone if that's all there is.
	 *
	 * @param string $text What the box holds.
	 * @return array<string, string>
	 */
	public static function need( string $text ): array {
		$text = self::tidy( $text );
		$at   = 1 === preg_match( '/(?:^|(?<=[.!?:;)"”’]\s))Recommend/u', $text, $found, PREG_OFFSET_CAPTURE ) ? $found[0][1] : -1;
		$need = -1 === $at ? $text : trim( substr( $text, 0, $at ) );
		$rec  = -1 === $at ? '' : trim( substr( $text, $at ) );
		return array_filter(
			array(
				'need'           => $need,
				'recommendation' => $rec,
			),
			static fn( string $part ): bool => '' !== $part
		);
	}

	/**
	 * One link box as a paper, as the import reads it before looking anything up: a
	 * DOI, written out or in a doi.org or Zenodo address, or else the link; or null when
	 * it's neither.
	 *
	 * @param string $link What the box holds.
	 * @return array<string, string>|null
	 */
	public static function paper( string $link ): ?array {
		$value = trim( $link );
		$url   = 1 === preg_match( '/\bhttps?:\/\/\S+/', $value, $found ) ? $found[0] : null;
		$doi   = 1 === preg_match( '/\b(10\.\d{4,9}\/[^\s"<>]+)/', $value, $found ) ? rtrim( $found[1], '.,;' ) : null;
		if ( null === $doi && null !== $url && 1 === preg_match( '/^https?:\/\/(?:www\.)?zenodo\.org\/(?:records|record)\/(\d+)/i', $url, $found ) ) {
			return array(
				'doi' => '10.5281/zenodo.' . $found[1],
				'url' => $url,
			);
		}
		if ( null !== $doi ) {
			return array( 'doi' => $doi );
		}
		return null === $url ? null : array( 'url' => $url );
	}

	/**
	 * Text with its runs of spaces and line breaks made one space, and trimmed, as the
	 * import tidies a field.
	 *
	 * @param string $text The text.
	 */
	public static function tidy( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * What's wrong with one text answer, or null.
	 *
	 * @param string               $value    The answer.
	 * @param array<string, mixed> $question Its question.
	 */
	private static function text( string $value, array $question ): ?string {
		if ( 1 !== preg_match( '//u', $value ) ) {
			return 'holds characters that aren’t text.';
		}
		if ( Synthesis::blank( $value ) ) {
			return $question['required'] ? 'this is required.' : null;
		}
		if ( isset( $question['max'] ) && mb_strlen( $value ) > (int) $question['max'] ) {
			return sprintf( 'it’s longer than %d characters.', $question['max'] );
		}
		if ( 'email' === $question['type'] && ! filter_var( trim( $value ), FILTER_VALIDATE_EMAIL ) ) {
			return 'that doesn’t look like an email address.';
		}
		if ( 'year' === $question['type'] ) {
			$year = trim( $value );
			if ( 1 !== preg_match( '/^\d{4}$/', $year ) || (int) $year < 1950 || (int) $year > (int) gmdate( 'Y' ) ) {
				return sprintf( 'give the year as four digits, no later than %s: the project must be completed.', gmdate( 'Y' ) );
			}
		}
		return null;
	}

	/**
	 * What's wrong with a question's entries, by entry, and with how many there are.
	 *
	 * @param string               $name     The question's name.
	 * @param array<string, mixed> $question The question.
	 * @param string[]             $entries  Its entries, empty ones too.
	 * @return array<string, string>
	 */
	private static function entries( string $name, array $question, array $entries ): array {
		$problems = array();
		$filled   = 0;
		foreach ( $entries as $index => $entry ) {
			$id = $name . '-' . ( $index + 1 );
			if ( 1 !== preg_match( '//u', $entry ) ) {
				$problems[ $id ] = sprintf( '%1$s %2$d holds characters that aren’t text.', $question['entry'], $index + 1 );
				continue;
			}
			if ( Synthesis::blank( $entry ) ) {
				continue;
			}
			++$filled;
			if ( mb_strlen( $entry ) > (int) $question['max'] ) {
				$problems[ $id ] = sprintf( '%1$s %2$d is longer than %3$d characters.', $question['entry'], $index + 1, $question['max'] );
			} elseif ( 'links' === $name && null === self::paper( $entry ) ) {
				$problems[ $id ] = sprintf( '%1$s %2$d isn’t a web address or a DOI: start it with https:// or 10.', $question['entry'], $index + 1 );
			}
		}
		[ $fewest, $most ] = $question['count'];
		if ( $filled < $fewest && ( $question['required'] || $filled > 0 ) ) {
			$problems[ $name . '-1' ] = $problems[ $name . '-1' ] ?? sprintf( '%1$s: give at least %2$d.', $question['name'], $fewest );
		} elseif ( count( $entries ) > $most ) {
			$problems[ $name . '-1' ] = $problems[ $name . '-1' ] ?? sprintf( '%1$s: give no more than %2$d.', $question['name'], $most );
		}
		return $problems;
	}

	/**
	 * A question's short name.
	 *
	 * @param string $name The question's name, or an "Other" box's.
	 */
	private static function name( string $name ): string {
		$questions = Questions::all();
		return (string) ( $questions[ $name ]['name'] ?? $questions[ substr( $name, 0, -5 ) ]['name'] ?? $name );
	}
}
