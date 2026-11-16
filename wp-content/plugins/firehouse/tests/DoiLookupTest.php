<?php
/**
 * Tests for looking up a paper's title by its DOI.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Survey\Doi_Lookup;

/**
 * A DOI's title, journal and year come from doi.org, through the metadata service it
 * sends the request on to and no other host; what comes back is kept only if a page may
 * show it, and a lookup that fails keeps nothing.
 */
final class DoiLookupTest extends WordPressTestCase {

	/**
	 * Each request made: its address and its arguments.
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $requests = array();

	/**
	 * The answers, by address: a status, and a body or a Location.
	 *
	 * @var array<string, array{0: int, 1: string}>
	 */
	private array $answers = array();

	/**
	 * Stands in for WordPress's HTTP API, answering from $answers.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $part = -1 ): mixed => parse_url( $url, $part ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Standing in for wp_parse_url().
		Functions\when( 'wp_safe_remote_get' )->alias(
			function ( string $url, array $args ): array|\WP_Error {
				$this->requests[] = array( $url, $args );
				if ( ! isset( $this->answers[ $url ] ) ) {
					return new \WP_Error( 'http_request_failed', 'No answer.' );
				}
				return array(
					'code' => $this->answers[ $url ][0],
					'body' => $this->answers[ $url ][1],
				);
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( array $response ): int => $response['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( array $response ): string => $response['code'] < 300 ? $response['body'] : '' );
		Functions\when( 'wp_json_encode' )->alias( static fn( mixed $value ): string => (string) json_encode( $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standing in for wp_json_encode().
		Functions\when( 'wp_remote_retrieve_header' )->alias( static fn( array $response ): string => $response['code'] >= 300 ? $response['body'] : '' );
	}

	/**
	 * CSL JSON as Crossref gives it.
	 *
	 * @param array<string, mixed> $more Fields to add or change.
	 */
	private static function csl( array $more = array() ): string {
		return (string) wp_json_encode(
			$more + array(
				'title'           => array( 'Smoke  and warnings' ),
				'container-title' => array( 'Weather, Climate, and Society' ),
				'publisher'       => 'American Meteorological Society',
				'issued'          => array( 'date-parts' => array( array( 2022, 4 ) ) ),
			)
		);
	}

	/**
	 * A DOI is asked of doi.org for CSL JSON, without following redirects itself, and
	 * its answer is followed only to the metadata service; the title, journal and year
	 * come back, tidied as the import tidies them.
	 */
	public function test_a_doi_is_looked_up_through_doi_org(): void {
		$this->answers['https://doi.org/10.1175/WCAS-D-21-0042.1']                             = array( 302, 'https://api.crossref.org/v1/works/10.1175/WCAS-D-21-0042.1/transform' );
		$this->answers['https://api.crossref.org/v1/works/10.1175/WCAS-D-21-0042.1/transform'] = array( 200, self::csl() );

		$this->assertSame(
			array(
				'title'     => 'Smoke and warnings',
				'container' => 'Weather, Climate, and Society',
				'year'      => 2022,
			),
			Doi_Lookup::lookup( '10.1175/WCAS-D-21-0042.1' )
		);
		$this->assertCount( 2, $this->requests );
		$args = $this->requests[0][1];
		$this->assertSame( 0, $args['redirection'], 'Redirects are followed here, host by host.' );
		$this->assertSame( 'application/vnd.citationstyles.csl+json', $args['headers']['Accept'] );
		$this->assertSame( 'FireHouse WordPress plugin', $args['user-agent'] );
		$this->assertLessThanOrEqual( 5, $args['timeout'] );
		$this->assertArrayHasKey( 'limit_response_size', $args );
	}

	/**
	 * A redirect to any host but the metadata services, such as a publisher's landing
	 * page for a DOI with no metadata to give, is never followed; nor is one over plain
	 * http, on another port, or with a user name, and a chain of them stops.
	 */
	public function test_no_other_host_is_reached(): void {
		$this->answers['https://doi.org/10.1000/landing'] = array( 302, 'https://journals.example.org/article/1' );
		$this->assertSame( array(), Doi_Lookup::lookup( '10.1000/landing' ) );
		$this->assertCount( 1, $this->requests );

		foreach ( array( 'http://api.crossref.org/works', 'https://api.crossref.org:8443/works', 'https://user@api.crossref.org/works', 'https://api.crossref.org.example.org/works', '//api.crossref.org/works', '' ) as $where ) {
			$this->requests                                 = array();
			$this->answers['https://doi.org/10.1000/moved'] = array( 302, $where );
			$this->assertSame( array(), Doi_Lookup::lookup( '10.1000/moved' ), $where );
			$this->assertCount( 1, $this->requests, $where );
		}

		$this->requests                                = array();
		$this->answers['https://doi.org/10.1000/loop'] = array( 302, '/10.1000/loop' );
		$this->assertSame( array(), Doi_Lookup::lookup( '10.1000/loop' ) );
		$this->assertCount( 3, $this->requests, 'Two redirects followed, then it stops.' );
	}

	/**
	 * A DOI's own characters are encoded in the address, so none of them can become a
	 * query, a fragment, a port or a user name.
	 */
	public function test_a_doi_is_encoded_in_the_address(): void {
		Doi_Lookup::lookup( '10.1002/(SICI)1097-4571(199806)49:8<693::AID-ASI4>3.0.CO;2-0@x%2e' );
		$this->assertSame( 'https://doi.org/10.1002/%28SICI%291097-4571%28199806%2949%3A8%3C693%3A%3AAID-ASI4%3E3.0.CO%3B2-0%40x%252e', $this->requests[0][0] );
	}

	/**
	 * A DOI copied from a link has its percent escapes decoded once before it's encoded
	 * for the address, as doi.org decodes the address the import asks for, so "%2E" is
	 * asked for as ".", not "%252E"; the stored DOI keeps what was typed. What it decodes
	 * to is checked as any DOI is.
	 */
	public function test_a_pasted_links_escapes_are_decoded_once(): void {
		$this->assertSame( '10.1175/BAMS-D-25-0036.1', Doi_Lookup::doi( '10.1175/BAMS-D-25-0036%2E1' ) );
		$this->assertSame( '10.1002/(SICI)1097-4571', Doi_Lookup::doi( '10.1002/%28SICI%291097-4571' ) );
		$this->assertSame( '10.1234/50%', Doi_Lookup::doi( '10.1234/50%25' ), 'Decoded once, not twice.' );
		foreach ( array( '10.1234/%2E%2E/api', '10.1234/a%2F..%2Fb', '10.1234/a%20b', '10.1234/a%0Ab', '10.1234/a%00b', '10.1234/a%E2%80%AEb', '10.1234/a%FFb' ) as $doi ) {
			$this->assertSame( '', Doi_Lookup::doi( $doi ), $doi );
		}

		$papers = array( array( 'doi' => '10.1175/BAMS-D-25-0036%2E1' ) );
		Doi_Lookup::papers( $papers );
		$this->assertSame( array( 'https://doi.org/10.1175/BAMS-D-25-0036.1' ), array_column( $this->requests, 0 ) );
	}

	/**
	 * What's looked up is the DOI without a pasted link's query or fragment, which may
	 * carry a token; a DOI with a "." or ".." step, which an address would resolve out
	 * of the path, or one that doesn't start "10.", isn't looked up at all.
	 */
	public function test_only_the_doi_itself_is_sent(): void {
		$this->assertSame( '10.1002/wea.123', Doi_Lookup::doi( '10.1002/wea.123?casa_token=abc#section' ) );
		$this->assertSame( '10.1175/WCAS-D-21-0042.1', Doi_Lookup::doi( '10.1175/WCAS-D-21-0042.1' ) );
		foreach ( array( '10.1234/../../api/handles/10.1234', '10.1234/./x', '10.1234/a/..', '10.1234/..', 'not/a/doi', '' ) as $doi ) {
			$this->assertSame( '', Doi_Lookup::doi( $doi ), $doi );
		}

		Doi_Lookup::papers( array( array( 'doi' => '10.1002/wea.123?casa_token=abc' ), array( 'doi' => '10.1234/../../api/handles/10.1234' ) ) );
		$this->assertSame( array( 'https://doi.org/10.1002/wea.123' ), array_column( $this->requests, 0 ) );
	}

	/**
	 * The time is the whole lookup's: a request starts only with a second left, and is
	 * given no more than what's left.
	 */
	public function test_the_time_limit_holds_across_redirects(): void {
		$this->assertSame( array(), Doi_Lookup::lookup( '10.1000/late', microtime( true ) + 0.5 ) );
		$this->assertSame( array(), $this->requests, 'Under a second left: nothing asked.' );

		Doi_Lookup::lookup( '10.1000/soon', microtime( true ) + 2.5 );
		$this->assertSame( 2, $this->requests[0][1]['timeout'] );
	}

	/**
	 * A lookup that fails, answers with anything but 200, or gives no JSON keeps
	 * nothing.
	 */
	public function test_a_failed_lookup_keeps_nothing(): void {
		$this->assertSame( array(), Doi_Lookup::lookup( '10.1000/unanswered' ) );
		$this->answers['https://doi.org/10.1000/missing'] = array( 404, 'Not found' );
		$this->assertSame( array(), Doi_Lookup::lookup( '10.1000/missing' ) );
		$this->answers['https://doi.org/10.1000/html'] = array( 200, '<html>' );
		$this->assertSame( array(), Doi_Lookup::lookup( '10.1000/html' ) );
	}

	/**
	 * What comes back is kept only if a page may show it: text, not blank, no longer
	 * than a thousand characters, without an email address or a character that doesn't
	 * show, and a plausible year. The publisher stands in for a missing journal.
	 */
	public function test_only_what_a_page_may_show_is_kept(): void {
		$this->assertSame(
			array(
				'title'     => 'A title',
				'container' => 'American Meteorological Society',
				'year'      => 2021,
			),
			Doi_Lookup::metadata(
				self::csl(
					array(
						'title'           => 'A title',
						'container-title' => array(),
						'issued'          => array( 'date-parts' => array( array( '2021' ) ) ),
					)
				)
			)
		);
		$this->assertSame(
			array(),
			Doi_Lookup::metadata(
				self::csl(
					array(
						'title'           => 'Write to jane.doe@noaa.gov',
						'container-title' => array( "A \u{202E}journal" ),
						'publisher'       => str_repeat( 'x', 1001 ),
						'issued'          => array( 'date-parts' => array( array( 99999 ) ) ),
					)
				)
			)
		);
		$this->assertSame( array(), Doi_Lookup::metadata( (string) wp_json_encode( array( 'title' => array( 'title' => 'nested' ) ) ) ) );
		$this->assertSame( array(), Doi_Lookup::metadata( 'null' ) );
	}

	/**
	 * Only a paper with a DOI is looked up, never a link a researcher typed; what's
	 * found adds to the paper's own DOI and link, and nothing is looked up once the
	 * time is spent.
	 */
	public function test_only_dois_are_looked_up(): void {
		$this->answers['https://doi.org/10.5281/zenodo.123'] = array( 200, self::csl( array( 'title' => 'A dataset' ) ) );
		$papers = array(
			array( 'url' => 'https://example.org/report' ),
			array(
				'doi' => '10.5281/zenodo.123',
				'url' => 'https://zenodo.org/records/123',
			),
		);

		$this->assertSame(
			array(
				array( 'url' => 'https://example.org/report' ),
				array(
					'doi'       => '10.5281/zenodo.123',
					'url'       => 'https://zenodo.org/records/123',
					'title'     => 'A dataset',
					'container' => 'Weather, Climate, and Society',
					'year'      => 2022,
				),
			),
			Doi_Lookup::papers( $papers )
		);
		$this->assertSame( array( 'https://doi.org/10.5281/zenodo.123' ), array_column( $this->requests, 0 ) );

		$this->requests = array();
		$this->assertSame( $papers, Doi_Lookup::papers( $papers, 0 ) );
		$this->assertSame( array(), $this->requests, 'No time left: nothing looked up.' );

		$twice = Doi_Lookup::papers( array( array( 'doi' => '10.5281/zenodo.123' ), array( 'doi' => '10.5281/zenodo.123?x=1' ), array( 'doi' => '10.5281/ZENODO.123' ) ) );
		$this->assertCount( 1, $this->requests, 'The same DOI three times, once in capitals: looked up once.' );
		$this->assertSame( 'A dataset', $twice[1]['title'] );
		$this->assertSame( 'A dataset', $twice[2]['title'] );
		$this->assertSame( '10.5281/ZENODO.123', $twice[2]['doi'], 'Each paper keeps its DOI as typed.' );
	}

	/**
	 * Only a draft with a DOI is scheduled for its lookups.
	 */
	public function test_a_draft_with_a_doi_is_scheduled(): void {
		$scheduled = array();
		Functions\when( 'wp_schedule_single_event' )->alias(
			static function ( int $time, string $hook, array $args ) use ( &$scheduled ): bool {
				$scheduled[] = array( $hook, $args );
				return true;
			}
		);
		Doi_Lookup::schedule( 30, array( array( 'url' => 'https://example.org/report' ) ) );
		$this->assertSame( array(), $scheduled );
		Doi_Lookup::schedule( 30, array( array( 'url' => 'https://example.org/report' ), array( 'doi' => '10.1000/x' ) ) );
		$this->assertSame( array( array( Doi_Lookup::EVENT, array( 30 ) ) ), $scheduled );
	}

	/**
	 * The scheduled lookup adds what it finds to the papers of a draft the survey form
	 * saved, checked as every write is, and leaves anything else alone: a project from
	 * the import, another post type, a missing post, papers with nothing new, and a
	 * submission published or trashed before the event, or while its lookups ran, or
	 * whose papers changed meanwhile.
	 */
	public function test_the_scheduled_lookup_stores_what_it_finds(): void {
		$this->answers['https://doi.org/10.5281/zenodo.123'] = array( 200, self::csl( array( 'title' => 'A dataset' ) ) );
		// Each post: its type, response ID, status, and its status after the lookups.
		$posts   = array(
			30 => array( 'fh_project', 'W_abcdefghijklmnop', 'draft', 'draft' ),
			31 => array( 'fh_project', 'R_fromQualtrics', 'draft', 'draft' ),
			32 => array( 'page', 'W_abcdefghijklmnop', 'draft', 'draft' ),
			33 => array( 'fh_project', 'W_nothingToFind', 'draft', 'draft' ),
			34 => array( 'fh_project', 'W_publishedFirst', 'publish', 'publish' ),
			35 => array( 'fh_project', 'W_trashedFirst', 'trash', 'trash' ),
			36 => array( 'fh_project', 'W_publishedDuring', 'draft', 'publish' ),
			37 => array( 'fh_project', 'W_papersChanged', 'draft', 'draft' ),
		);
		$cleaned = array();
		$written = array();
		Functions\when( 'clean_post_cache' )->alias(
			static function ( int $id ) use ( &$cleaned ): void {
				$cleaned[ $id ] = true;
			}
		);
		Functions\when( 'get_post' )->alias(
			static function ( int $id ) use ( $posts, &$cleaned ): ?\WP_Post {
				return isset( $posts[ $id ] ) ? new \WP_Post(
					array(
						'ID'          => $id,
						'post_type'   => $posts[ $id ][0],
						'post_status' => $posts[ $id ][ isset( $cleaned[ $id ] ) ? 3 : 2 ],
					)
				) : null;
			}
		);
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $id, string $key ) use ( $posts, &$cleaned ): mixed {
				if ( 'fh_id' === $key ) {
					return $posts[ $id ][1];
				}
				if ( 37 === $id && isset( $cleaned[ $id ] ) ) {
					return array( array( 'doi' => '10.5281/zenodo.123' ), array( 'url' => 'https://example.org/added' ) );
				}
				return 33 === $id ? array( array( 'url' => 'https://example.org/report' ) ) : array( array( 'doi' => '10.5281/zenodo.123' ) );
			}
		);
		Functions\when( 'rest_validate_value_from_schema' )->justReturn( true );
		Functions\when( 'wp_slash' )->returnArg();
		Functions\when( 'update_post_meta' )->alias(
			static function ( int $id, string $key, mixed $value ) use ( &$written ): bool {
				$written[] = array( $id, $key, $value );
				return true;
			}
		);

		foreach ( array( 30, '31', 32, 33, 34, 35, 36, 37, 99, 'x', null ) as $id ) {
			Doi_Lookup::run( $id );
		}
		$this->assertSame( array( 30, 36, 37 ), array_keys( $cleaned ), 'Read again after the lookups: only drafts the lookups found something for.' );
		$this->assertCount( 1, $written );
		$this->assertSame( array( 30, 'fh_papers' ), array_slice( $written[0], 0, 2 ) );
		$this->assertSame( 'A dataset', $written[0][2][0]['title'] );
	}
}
