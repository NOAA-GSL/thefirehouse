<?php
/**
 * A paper's title, journal and year, looked up by its DOI.
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
 * Looks up the citation metadata of each DOI a submission gives, as the import does
 * (`scripts/import-survey.mjs`, resolveDoi()), so the project's page can show a
 * paper's title rather than a bare DOI. Only DOIs: the server never fetches a link a
 * researcher typed (the plan's Security review). It asks doi.org for CSL JSON, and
 * doi.org sends it on to the registration agency's metadata service: Crossref's
 * (api.crossref.org) or DataCite's (data.crosscite.org). A redirect to any other host
 * is refused, and WordPress's safe request refuses a private address on any of them.
 *
 * The researcher waits while it runs, so each request has a few seconds, and all of a
 * submission's together a few more; a DOI it can't look up in time, or at all, keeps
 * no title, as the import keeps none when its lookup fails. What comes back is checked
 * as a researcher's answers are: a title or journal with an email address, a character
 * that doesn't show, or more than a thousand characters is left out.
 */
final class Doi_Lookup {

	/**
	 * The only hosts a lookup reaches: doi.org and the two services it sends a request
	 * for metadata on to.
	 */
	public const HOSTS = array( 'doi.org', 'api.crossref.org', 'data.crosscite.org' );

	/**
	 * What a lookup asks for: citation metadata, as the import asks.
	 */
	private const ACCEPT = 'application/vnd.citationstyles.csl+json';

	/**
	 * The seconds one request may take.
	 */
	private const TIMEOUT = 5;

	/**
	 * The seconds all of a submission's lookups may take together.
	 */
	public const BUDGET = 10;

	/**
	 * The redirects one lookup may follow: doi.org's to the metadata service, and one
	 * more if that moves.
	 */
	private const HOPS = 2;

	/**
	 * The most of a response read: CSL JSON for a paper with a long reference list runs
	 * to a few hundred kilobytes.
	 */
	private const MOST_BYTES = 2097152;

	/**
	 * The longest title or journal kept.
	 */
	private const LONGEST = 1000;

	/**
	 * The papers, each with a DOI given its title, journal and year as far as they can
	 * be looked up within the budget. A paper keeps its own DOI and link; what's looked
	 * up only adds to them.
	 *
	 * @param list<array<string, mixed>> $papers The papers, as Submission::project() gives them.
	 * @param int                        $budget The seconds all the lookups may take.
	 * @return list<array<string, mixed>>
	 */
	public static function papers( array $papers, int $budget = self::BUDGET ): array {
		$deadline = microtime( true ) + $budget;
		foreach ( $papers as $index => $paper ) {
			$left = $deadline - microtime( true );
			if ( ! is_string( $paper['doi'] ?? null ) ) {
				continue;
			}
			if ( $left < 1 ) {
				break;
			}
			$papers[ $index ] = $paper + self::lookup( $paper['doi'], (int) min( self::TIMEOUT, floor( $left ) ) );
		}
		return $papers;
	}

	/**
	 * One DOI's title, journal and year, each only if found and fit to keep; nothing
	 * if the lookup fails.
	 *
	 * @param string $doi     The DOI, bare.
	 * @param int    $timeout The seconds each request may take.
	 * @return array{title?: string, container?: string, year?: int}
	 */
	public static function lookup( string $doi, int $timeout = self::TIMEOUT ): array {
		$url = 'https://doi.org/' . implode( '/', array_map( 'rawurlencode', explode( '/', $doi ) ) );
		for ( $hop = 0; $hop <= self::HOPS; $hop++ ) {
			if ( ! self::allowed( $url ) ) {
				return array();
			}
			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => max( 1, $timeout ),
					'redirection'         => 0,
					'headers'             => array( 'Accept' => self::ACCEPT ),
					'user-agent'          => 'FireHouse WordPress plugin',
					'limit_response_size' => self::MOST_BYTES,
				)
			);
			if ( is_wp_error( $response ) ) {
				return array();
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 300 && $code < 400 ) {
				$location = wp_remote_retrieve_header( $response, 'location' );
				$url      = self::absolute( is_string( $location ) ? $location : '', $url );
				continue;
			}
			return 200 === $code ? self::metadata( (string) wp_remote_retrieve_body( $response ) ) : array();
		}
		return array();
	}

	/**
	 * Whether a lookup may reach an address: https, on one of the HOSTS, on its own
	 * port, with no user name in it.
	 *
	 * @param string $url The address.
	 */
	public static function allowed( string $url ): bool {
		$parts = wp_parse_url( $url );
		return is_array( $parts )
			&& 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) )
			&& in_array( strtolower( (string) ( $parts['host'] ?? '' ) ), self::HOSTS, true )
			&& ! isset( $parts['port'] )
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] );
	}

	/**
	 * The title, journal and year in CSL JSON, as the import reads them: the first title,
	 * the first container title or else the publisher, and the year it was issued. Each
	 * is kept only if it's text a page can show.
	 *
	 * @param string $json The response.
	 * @return array{title?: string, container?: string, year?: int}
	 */
	public static function metadata( string $json ): array {
		$csl = json_decode( $json, true );
		if ( ! is_array( $csl ) ) {
			return array();
		}
		$first = static fn( mixed $value ): mixed => is_array( $value ) ? ( $value[0] ?? null ) : $value;
		$found = array();

		$title = self::text( $first( $csl['title'] ?? null ) );
		if ( '' !== $title ) {
			$found['title'] = $title;
		}
		$container = self::text( $first( $csl['container-title'] ?? null ) );
		if ( '' === $container ) {
			$container = self::text( $csl['publisher'] ?? null );
		}
		if ( '' !== $container ) {
			$found['container'] = $container;
		}
		$year = $csl['issued']['date-parts'][0][0] ?? null;
		if ( is_string( $year ) && 1 === preg_match( '/^\d{4}$/', $year ) ) {
			$year = (int) $year;
		}
		if ( is_int( $year ) && $year >= 1000 && $year <= (int) gmdate( 'Y' ) + 1 ) {
			$found['year'] = $year;
		}
		return $found;
	}

	/**
	 * A value as text to keep, tidied as the import tidies it, or "" when it isn't
	 * text, would show nothing, or holds what a researcher's answer may not: an email
	 * address or a character that doesn't show.
	 *
	 * @param mixed $value The value.
	 */
	private static function text( mixed $value ): string {
		if ( ! is_string( $value ) || 1 !== preg_match( '//u', $value ) ) {
			return '';
		}
		$text = Submission::tidy( $value );
		if ( Synthesis::blank( $text ) || mb_strlen( $text ) > self::LONGEST || 1 === preg_match( Submission::HIDDEN, $text ) || Email_Shape::has_email( $text ) ) {
			return '';
		}
		return $text;
	}

	/**
	 * A redirect's address, made whole from the address it came from if it's given
	 * from the root ("/works/…"); "" if it's neither.
	 *
	 * @param string $location The redirect's Location.
	 * @param string $from     The address that answered with it.
	 */
	private static function absolute( string $location, string $from ): string {
		if ( 1 === preg_match( '#^https?://#i', $location ) ) {
			return $location;
		}
		if ( str_starts_with( $location, '/' ) && ! str_starts_with( $location, '//' ) ) {
			return 'https://' . (string) wp_parse_url( $from, PHP_URL_HOST ) . $location;
		}
		return '';
	}
}
