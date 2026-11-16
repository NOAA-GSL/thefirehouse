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
use Firehouse\Meta;
use Firehouse\Post_Types;
use Firehouse\Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Looks up the citation metadata of each DOI a submission gives, as the import does
 * (`scripts/import-survey.mjs`, resolveDoi()), so the project's page can show a
 * paper's title rather than a bare DOI. Only DOIs: the server never fetches a link a
 * researcher typed (the plan's Security review). It asks doi.org for CSL JSON, and
 * doi.org redirects the request to the registration agency's metadata service, which
 * the server then asks itself: Crossref's (api.crossref.org) or DataCite's
 * (data.crosscite.org). A redirect to any other host is refused, and WordPress's safe
 * request refuses a private address on any of them.
 *
 * It runs after the submission is saved, in WordPress's scheduled events (WP-Cron),
 * not while the researcher waits: WordPress runs those one at a time, so a burst of
 * submissions queues its lookups rather than holding a server process each. A
 * submission without IRB approval, which is never published, isn't looked up.
 *
 * All of a submission's lookups have ten seconds, each request five, and a DOI given
 * twice is looked up once; a DOI it can't look up in time, or at all, keeps no title,
 * as the import keeps none when its lookup fails. A pasted link's query and fragment
 * aren't sent: only the DOI before them. What comes back is checked as a researcher's
 * answers are: a title or journal with an email address, a character that doesn't
 * show, or more than a thousand characters is left out.
 */
final class Doi_Lookup {

	/**
	 * The scheduled event that looks up a draft's DOIs.
	 */
	public const EVENT = 'firehouse_doi_lookup';

	/**
	 * The only hosts a lookup reaches: doi.org and the two services it redirects a
	 * request for metadata to.
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
	 * Hooks the scheduled lookup.
	 */
	public static function hook(): void {
		add_action( self::EVENT, array( self::class, 'run' ) );
	}

	/**
	 * Schedules the lookups for a draft the survey form has just saved, if it gives a
	 * DOI.
	 *
	 * @param int                        $post_id The draft.
	 * @param list<array<string, mixed>> $papers  Its papers.
	 */
	public static function schedule( int $post_id, array $papers ): void {
		if ( array() !== array_filter( $papers, static fn( mixed $paper ): bool => is_array( $paper ) && is_string( $paper['doi'] ?? null ) ) ) {
			wp_schedule_single_event( time(), self::EVENT, array( $post_id ) );
		}
	}

	/**
	 * The scheduled event: looks up the DOIs of a draft the survey form saved, and
	 * stores what's found with its papers. Anything else, a project imported from the
	 * survey's download included, is left alone.
	 *
	 * @param mixed $post_id The draft, as the event was given it.
	 */
	public static function run( mixed $post_id ): void {
		$post = is_int( $post_id ) || ( is_string( $post_id ) && ctype_digit( $post_id ) ) ? get_post( (int) $post_id ) : null;
		if ( ! $post instanceof \WP_Post || Post_Types::PROJECT !== $post->post_type ) {
			return;
		}
		$id     = get_post_meta( $post->ID, Meta::key( 'id' ), true );
		$papers = get_post_meta( $post->ID, Meta::key( 'papers' ), true );
		if ( ! is_string( $id ) || ! str_starts_with( $id, Survey::RESPONSE_PREFIX ) || ! is_array( $papers ) || ! array_is_list( $papers ) ) {
			return;
		}
		$found = self::papers( $papers );
		if ( $found !== $papers && true === Meta::validate( Post_Types::PROJECT, 'papers', $found ) ) {
			Store::update_fields( $post->ID, array( 'papers' => $found ), array() );
		}
	}

	/**
	 * The papers, each with a DOI given its title, journal and year as far as they can
	 * be looked up within the budget, each DOI once. A paper keeps its own DOI and link,
	 * and anything it already has; what's looked up only adds to them.
	 *
	 * @param list<array<string, mixed>> $papers The papers.
	 * @param int                        $budget The seconds all the lookups may take.
	 * @return list<array<string, mixed>>
	 */
	public static function papers( array $papers, int $budget = self::BUDGET ): array {
		$deadline = microtime( true ) + $budget;
		$found    = array();
		foreach ( $papers as $index => $paper ) {
			if ( ! is_array( $paper ) || ! is_string( $paper['doi'] ?? null ) ) {
				continue;
			}
			$doi = self::doi( $paper['doi'] );
			if ( '' === $doi ) {
				continue;
			}
			if ( ! isset( $found[ $doi ] ) ) {
				if ( $deadline - microtime( true ) < 1 ) {
					break;
				}
				$found[ $doi ] = self::lookup( $doi, $deadline );
			}
			$papers[ $index ] = $paper + $found[ $doi ];
		}
		return $papers;
	}

	/**
	 * The DOI to look up: the stored one without a pasted link's query or fragment,
	 * which doi.org ignores and which may carry a token; "" for one that doesn't start
	 * "10.", or has a "." or ".." step in its path, which an address would resolve out
	 * of it.
	 *
	 * @param string $doi The DOI as stored.
	 */
	public static function doi( string $doi ): string {
		$doi = (string) preg_replace( '/[?#].*/s', '', $doi );
		return 1 === preg_match( '#^10\.\d{4,9}/#', $doi ) && 1 !== preg_match( '#(^|/)\.\.?(/|$)#', $doi ) ? $doi : '';
	}

	/**
	 * One DOI's title, journal and year, each only if found and fit to keep; nothing
	 * if the lookup fails or the time runs out.
	 *
	 * @param string     $doi      The DOI, as doi() gives it.
	 * @param float|null $deadline When the time is up, as microtime( true ); by default, BUDGET from now.
	 * @return array{title?: string, container?: string, year?: int}
	 */
	public static function lookup( string $doi, ?float $deadline = null ): array {
		$deadline ??= microtime( true ) + self::BUDGET;
		$url        = 'https://doi.org/' . implode( '/', array_map( 'rawurlencode', explode( '/', $doi ) ) );
		for ( $hop = 0; $hop <= self::HOPS; $hop++ ) {
			$left = $deadline - microtime( true );
			if ( $left < 1 || ! self::allowed( $url ) ) {
				return array();
			}
			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => (int) min( self::TIMEOUT, floor( $left ) ),
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
