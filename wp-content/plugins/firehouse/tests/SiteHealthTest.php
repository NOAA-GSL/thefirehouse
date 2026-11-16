<?php
/**
 * Tests for FireHouse's Site Health checks.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Site_Health;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Site Health gets three FireHouse checks: whether the server reaches the outside
 * services, whether search engines are kept out of a site that isn't production, and
 * whether a cache keeps FireHouse's pages. The two that make requests answer Site
 * Health's script, for whoever may see Site Health, and never run in its weekly check.
 */
final class SiteHealthTest extends WordPressTestCase {

	/**
	 * The URLs asked for, in order.
	 *
	 * @var list<string>
	 */
	private array $asked = array();

	/**
	 * Options by name.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array( 'blog_public' => '1' );

	/**
	 * A WordPress whose requests are kept and answered by the test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\stubEscapeFunctions();
		Functions\when( 'get_option' )->alias( fn( string $name ): mixed => $this->options[ $name ] ?? false );
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://gsl.example' . $path );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'wp_remote_retrieve_header' )->alias( static fn( array $response, string $name ): mixed => $response['headers'][ $name ] ?? '' );
		$this->answer( array() );
	}

	/**
	 * Answers every request with these headers, or with an error for the hosts in $fail.
	 *
	 * @param array<string, string> $headers The response's headers.
	 * @param string[]              $fail    Hosts that can't be reached.
	 */
	private function answer( array $headers, array $fail = array() ): void {
		$respond = function ( string $url ) use ( $headers, $fail ): mixed {
			$this->asked[] = $url;
			if ( in_array( (string) parse_url( $url, PHP_URL_HOST ), $fail, true ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- A test's stand-in.
				return new \WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );
			}
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => $headers,
			);
		};
		Functions\when( 'wp_safe_remote_head' )->alias( $respond );
		Functions\when( 'wp_remote_get' )->alias( $respond );
	}

	/**
	 * The checks join Site Health's: one direct, two async that its script asks for by
	 * action and that its weekly check skips.
	 */
	public function test_the_checks_join_site_healths(): void {
		$tests = Site_Health::tests(
			array(
				'direct' => array(),
				'async'  => array(),
			)
		);

		$this->assertSame( array( Site_Health::class, 'search' ), $tests['direct']['firehouse_search']['test'] );
		foreach ( array( 'firehouse_outbound', 'firehouse_caching' ) as $name ) {
			$this->assertSame( $name, $tests['async'][ $name ]['test'] );
			$this->assertFalse( $tests['async'][ $name ]['has_rest'] );
			$this->assertTrue( $tests['async'][ $name ]['skip_cron'] );
		}
		$this->assertSame( 'not tests', Site_Health::tests( 'not tests' ) );

		Site_Health::hook();
		$this->assertNotFalse( has_filter( 'site_status_tests', array( Site_Health::class, 'tests' ) ) );
		$this->assertNotFalse( has_action( 'wp_ajax_health-check-firehouse-outbound', array( Site_Health::class, 'answer_outbound' ) ) );
		$this->assertNotFalse( has_action( 'wp_ajax_health-check-firehouse-caching', array( Site_Health::class, 'answer_caching' ) ) );
	}

	/**
	 * Without a Workers AI key, the server is asked to reach the three DOI hosts, each
	 * with a HEAD request to its front page; all answering is good.
	 */
	public function test_the_doi_hosts_are_reached(): void {
		$result = Site_Health::outbound();

		$this->assertSame( array( 'https://doi.org/', 'https://api.crossref.org/', 'https://data.crosscite.org/' ), $this->asked );
		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'FireHouse reaches the outside services it uses', $result['label'] );
		$this->assertSame( 'FireHouse', $result['badge']['label'] );
		$this->assertStringNotContainsString( 'cloudflare', $result['description'] );
	}

	/**
	 * A host that can't be reached is named, with why and what fails without it.
	 */
	public function test_a_host_that_cant_be_reached_is_named(): void {
		$this->answer( array(), array( 'api.crossref.org' ) );

		$result = Site_Health::outbound();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( '<li>api.crossref.org: cURL error 6: Could not resolve host</li>', $result['description'] );
		$this->assertStringContainsString( 'a submission’s papers keep only their DOI', $result['description'] );
	}

	/**
	 * With a Workers AI key, Cloudflare's API is asked too, without the key.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_with_a_key_cloudflare_is_reached_too(): void {
		define( 'FIREHOUSE_WORKERS_AI_ACCOUNT', '0123456789abcdef0123456789abcdef' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The plugin's own constant, as wp-config.php sets it.
		define( 'FIREHOUSE_WORKERS_AI_TOKEN', 'test-token_0123456789ABCDEFGHIJ' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The plugin's own constant, as wp-config.php sets it.
		$args = array();
		Functions\when( 'wp_safe_remote_head' )->alias(
			function ( string $url, array $request ) use ( &$args ): array {
				$this->asked[] = $url;
				$args[]        = $request;
				return array( 'response' => array( 'code' => 404 ) );
			}
		);

		$result = Site_Health::outbound();

		$this->assertSame( 'https://api.cloudflare.com/', end( $this->asked ) );
		$this->assertSame( 'good', $result['status'], 'Any answer counts.' );
		$this->assertStringContainsString( 'Analyze results', $result['description'] );
		$this->assertStringNotContainsString( 'test-token', (string) json_encode( $args ), 'The key isn’t sent.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- A test's look at what was sent.
	}

	/**
	 * Production lets search engines in; a site that isn't production keeps them out.
	 * Either the other way round is a recommendation.
	 */
	public function test_search_engines_are_kept_out_of_any_site_but_production(): void {
		$this->assertSame( 'good', Site_Health::search()['status'] );

		$this->options['blog_public'] = '0';
		$result                       = Site_Health::search();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'set WP_ENVIRONMENT_TYPE to “staging” or “local”', $result['description'] );

		Functions\when( 'wp_get_environment_type' )->justReturn( 'staging' );
		$result = Site_Health::search();
		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( 'Search engines are kept out of this staging site', $result['label'] );

		$this->options['blog_public'] = '1';
		$result                       = Site_Health::search();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'Search engines may index this staging site', $result['label'] );
	}

	/**
	 * The landing page, asked for at the site's own address: kept by Cloudflare is
	 * critical, since a hidden project would stay on the pages that showed it; passed on
	 * is good.
	 */
	public function test_cloudflares_cache_status_is_read(): void {
		foreach ( array( 'HIT', 'MISS', 'expired' ) as $status ) {
			$this->answer( array( 'cf-cache-status' => $status ) );
			$result = Site_Health::caching();
			$this->assertSame( 'critical', $result['status'], $status );
			$this->assertStringContainsString( 'a project whose IRB approval is cleared stays on the pages that showed it', $result['description'] );
		}
		$this->assertSame( 'https://gsl.example/firehouse/', end( $this->asked ) );

		foreach ( array( 'DYNAMIC', 'BYPASS' ) as $status ) {
			$this->answer( array( 'cf-cache-status' => $status ) );
			$result = Site_Health::caching();
			$this->assertSame( 'good', $result['status'], $status );
			$this->assertSame( 'Cloudflare passes FireHouse’s pages on without keeping them', $result['label'] );
		}
	}

	/**
	 * Another cache that served the page from its store is a recommendation; no cache
	 * header at all is good, with how to check from outside; a page that can't be loaded
	 * says why.
	 */
	public function test_other_caches_and_none(): void {
		$this->answer( array( 'age' => '120' ) );
		$this->assertSame( 'recommended', Site_Health::caching()['status'] );

		$this->answer( array( 'x-cache' => 'HIT from proxy' ) );
		$this->assertStringContainsString( 'X-Cache: HIT from proxy', Site_Health::caching()['description'] );

		$this->answer( array( 'age' => '0' ) );
		$result = Site_Health::caching();
		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( 'curl -sI', $result['description'] );

		$this->answer( array(), array( 'gsl.example' ) );
		$result = Site_Health::caching();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'Could not resolve host', $result['description'] );
	}

	/**
	 * Site Health's script gets an answer only with its nonce, and only for whoever may
	 * see Site Health.
	 */
	public function test_only_site_healths_viewers_get_an_answer(): void {
		Functions\expect( 'check_ajax_referer' )->twice()->with( 'health-check-site-status' );
		Functions\when( 'wp_send_json_error' )->alias(
			static function (): void {
				throw new \RuntimeException( 'refused' );
			}
		);
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( mixed $data ): void {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A test's stand-in; the message goes to the test, never a page.
				throw new \RuntimeException( 'answered ' . $data['test'] );
			}
		);

		Functions\when( 'current_user_can' )->alias( static fn( string $cap ): bool => 'view_site_health_checks' === $cap );
		try {
			Site_Health::answer_caching();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'answered firehouse_caching', $e->getMessage() );
		}

		Functions\when( 'current_user_can' )->justReturn( false );
		$this->expectExceptionMessage( 'refused' );
		Site_Health::answer_outbound();
	}
}
