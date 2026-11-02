<?php
/**
 * Display formatting, ported from src/content/format.ts.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dates, bylines, need leads and link labels, formatted as the React site does.
 *
 * The cases both versions must pass are in shared/cases/format.json.
 */
final class Format {

	/**
	 * An ISO date, or a date and time with or without a zone, captured in parts so the
	 * calendar can be checked. Nothing looser counts, so "tomorrow" can't become a
	 * "Last reviewed" date. A zone offset has to be a real one (up to ±23:59): PHP
	 * would read "+24:00" and move the day.
	 */
	private const ISO_DATE = '/^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)?)?$/D';

	/**
	 * A link: a scheme, then the host (after any user, up to the last "@", and before
	 * any port; an IPv6 host in brackets), then the path. The label is built from them
	 * as written, so a SICI DOI keeps its angle brackets and an international host
	 * keeps its letters.
	 */
	private const LINK = '/^[a-z][a-z0-9+.-]*:\/\/(?:[^\/?#]*@)?(\[[^\]\/?#]*\]|[^:\/?#]*)(?::[^\/?#]*)?([^?#]*)/i';

	/**
	 * Formats an ISO date for the "Last reviewed" lines: "September 17, 2026".
	 *
	 * Read in UTC on purpose, including a time written without a zone. The synthesis
	 * stores date-only strings, which mean that day everywhere; read in a local zone
	 * west of Greenwich, they would show the day before, including in every NOAA office.
	 *
	 * A date that isn't on the calendar (February 30, month 00, second 60) comes back
	 * unchanged too, rather than rolled over into a date nobody wrote.
	 *
	 * @param string $iso A date ("2026-09-17") or a date and time.
	 * @return string The date in words, or the input unchanged if it isn't an ISO date.
	 */
	public static function review_date( string $iso ): string {
		if ( ! preg_match( self::ISO_DATE, $iso, $parts ) ) {
			return $iso;
		}
		$on_calendar = checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );
		$on_clock    = (int) ( $parts[4] ?? 0 ) < 24 && (int) ( $parts[5] ?? 0 ) < 60 && (int) ( $parts[6] ?? 0 ) < 60;
		if ( ! $on_calendar || ! $on_clock ) {
			return $iso;
		}
		try {
			$utc  = new \DateTimeZone( 'UTC' );
			$date = new \DateTimeImmutable( $iso, $utc );
		} catch ( \Exception $e ) {
			return $iso;
		}
		// The year unpadded, as JavaScript prints it: 99, not 0099.
		$date = $date->setTimezone( $utc );
		return $date->format( 'F j, ' ) . (int) $date->format( 'Y' );
	}

	/**
	 * Joins an author list into a byline.
	 *
	 * Past `$max` names the rest become "et al.": a grid tile has one line for this.
	 * The project page passes 0 to show everyone.
	 *
	 * @param list<array{name: string}> $authors The authors, in order.
	 * @param int                       $max     How many names before "et al."; 0 for all.
	 */
	public static function byline( array $authors, int $max = 3 ): string {
		if ( array() === $authors ) {
			return 'Unattributed';
		}

		$names = array_column( $authors, 'name' );
		if ( $max > 0 && count( $names ) > $max ) {
			return implode( ', ', array_slice( $names, 0, $max ) ) . ' et al.';
		}
		if ( 1 === count( $names ) ) {
			return $names[0];
		}
		return implode( ', ', array_slice( $names, 0, -1 ) ) . ' and ' . end( $names );
	}

	/**
	 * Splits a need into its lead ("Need for X among Y:") and the rest.
	 *
	 * The survey asks every entry to open with that phrase, so bolding it lets a reader
	 * scan a long list by what is needed and by whom. Nothing is rewritten: the lead, a
	 * space and the rest are the original text. There is no lead when the entry
	 * doesn't follow the pattern closely enough, such as a missing colon that would
	 * make the lead swallow half the paragraph.
	 *
	 * @param string $need One need, as the researcher wrote it.
	 * @return array{lead?: string, rest: string}
	 */
	public static function need_lead( string $need ): array {
		// The TypeScript's pattern, with JavaScript's meanings spelled out: its `\s`, its
		// `.` (anything but a line break) and its ASCII-only word boundary.
		$space   = '[' . Js::SPACE . ']';
		$text    = '[^' . Js::SPACE . ']';
		$line    = '[^\n\r\x{2028}\x{2029}]';
		$pattern = '/^(Need (?:for|to)(?![A-Za-z0-9_])' . $line . '*?[:.])' . $space . '+(' . $text . '[\s\S]*)$/Du';
		if ( ! preg_match( $pattern, $need, $match ) ) {
			return array( 'rest' => $need );
		}
		if ( count( preg_split( '/' . $space . '+/u', $match[1] ) ) > 18 ) {
			return array( 'rest' => $need );
		}
		return array(
			'lead' => $match[1],
			'rest' => $match[2],
		);
	}

	/**
	 * A readable label for a link with no published title: "doi.org/10.1175/…".
	 *
	 * @param string $url The link.
	 * @return string The host, lowercased and without "www.", and the path without a
	 *                trailing slash, both as written; or the input unchanged if it has
	 *                no scheme and host.
	 */
	public static function link_label( string $url ): string {
		if ( ! preg_match( self::LINK, $url, $match ) || '' === $match[1] ) {
			return $url;
		}
		return preg_replace( '/^www\./', '', strtolower( $match[1] ) ) . rtrim( $match[2], '/' );
	}
}
