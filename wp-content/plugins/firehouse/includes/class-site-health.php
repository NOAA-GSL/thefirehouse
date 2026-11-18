<?php
/**
 * FireHouse's checks on Tools → Site Health.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Four checks on Tools → Site Health, for whoever may see it
 * (`view_site_health_checks`, an administrator's), on each site the plugin runs on:
 *
 * - **Outside services:** whether the server reaches doi.org, api.crossref.org and
 *   data.crosscite.org, which the survey form's DOI lookups use, and api.cloudflare.com
 *   while the site has a Workers AI key. One HEAD request to each host's front page,
 *   with nothing about the site or anyone in it; any answer counts.
 * - **Search engines:** a site that isn't production (`wp_get_environment_type()`)
 *   discourages search engines, so WordPress marks its pages noindex, and production
 *   doesn't.
 * - **Page caching:** whether a cache in front of the site, Cloudflare's above all,
 *   keeps FireHouse's pages. The plugin counts on none doing so (WORDPRESS-PLAN.md,
 *   Caching): a hidden project would stay on the pages that show it.
 * - **Workers AI key:** where the key comes from, FireHouse → Settings or wp-config.php,
 *   and whether one saved in Settings still opens: changed salts leave it unreadable.
 *
 * The two that make requests run only when someone opens Site Health, never in
 * WordPress's weekly check, so the plugin makes no request of its own accord.
 */
final class Site_Health {

	/**
	 * How long each request may take, in seconds.
	 */
	private const TIMEOUT = 10;

	/**
	 * Cloudflare's cache statuses for a page it keeps: MISS is one it will keep.
	 */
	private const KEPT = array( 'HIT', 'MISS', 'EXPIRED', 'STALE', 'UPDATING', 'REVALIDATED' );

	/**
	 * Hooks the checks.
	 */
	public static function hook(): void {
		add_filter( 'site_status_tests', array( self::class, 'tests' ) );
		// Site Health's script asks for an async check "x_y" as the action "health-check-x-y".
		add_action( 'wp_ajax_health-check-firehouse-outbound', array( self::class, 'answer_outbound' ) );
		add_action( 'wp_ajax_health-check-firehouse-caching', array( self::class, 'answer_caching' ) );
	}

	/**
	 * Adds the checks to Site Health's.
	 *
	 * @param mixed $tests Site Health's checks: direct and async.
	 * @return mixed
	 */
	public static function tests( mixed $tests ): mixed {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}
		$tests['direct']['firehouse_search']  = array(
			'label' => __( 'FireHouse: search engines', 'firehouse' ),
			'test'  => array( self::class, 'search' ),
		);
		$tests['direct']['firehouse_ai_key']  = array(
			'label' => __( 'FireHouse: Workers AI key', 'firehouse' ),
			'test'  => array( self::class, 'ai_key' ),
		);
		$tests['async']['firehouse_outbound'] = array(
			'label'     => __( 'FireHouse: outside services', 'firehouse' ),
			'test'      => 'firehouse_outbound',
			'has_rest'  => false,
			'skip_cron' => true,
		);
		$tests['async']['firehouse_caching']  = array(
			'label'     => __( 'FireHouse: page caching', 'firehouse' ),
			'test'      => 'firehouse_caching',
			'has_rest'  => false,
			'skip_cron' => true,
		);
		return $tests;
	}

	/**
	 * Answers Site Health's request for the outside services check.
	 */
	public static function answer_outbound(): void {
		self::answer( array( self::class, 'outbound' ) );
	}

	/**
	 * Answers Site Health's request for the page caching check.
	 */
	public static function answer_caching(): void {
		self::answer( array( self::class, 'caching' ) );
	}

	/**
	 * Whether the server reaches the outside services the plugin uses.
	 *
	 * @return array<string, mixed> The result, as Site Health takes it.
	 */
	public static function outbound(): array {
		$hosts = Survey\Doi_Lookup::HOSTS;
		if ( Workers_Ai::configured() ) {
			$hosts[] = Workers_Ai::HOST;
		}
		$failed = array();
		foreach ( $hosts as $host ) {
			$response = wp_safe_remote_head(
				'https://' . $host . '/',
				array(
					'timeout'     => self::TIMEOUT,
					'redirection' => 0,
					'user-agent'  => 'FireHouse WordPress plugin',
				)
			);
			if ( is_wp_error( $response ) ) {
				$failed[ $host ] = $response->get_error_message();
			}
		}

		$uses = '<p>' . esc_html__( 'The survey form looks up the title of each paper a submission gives by its DOI, through doi.org, api.crossref.org and data.crosscite.org.', 'firehouse' );
		if ( Workers_Ai::configured() ) {
			$uses .= ' ' . esc_html__( 'Analyze results, on FireHouse → Synthesis, asks a model on api.cloudflare.com.', 'firehouse' );
		}
		$uses .= ' ' . esc_html__( 'This check asked each for its front page, sending nothing else.', 'firehouse' ) . '</p>';

		if ( array() === $failed ) {
			return self::result( 'firehouse_outbound', 'good', __( 'FireHouse reaches the outside services it uses', 'firehouse' ), $uses );
		}
		$list = '<ul>';
		foreach ( $failed as $host => $why ) {
			/* translators: 1: a host, as doi.org; 2: why the request failed. */
			$item  = sprintf( __( '%1$s: %2$s', 'firehouse' ), $host, $why );
			$list .= '<li>' . esc_html( $item ) . '</li>';
		}
		$list .= '</ul>';
		$fails = '<p>' . esc_html__( 'Without doi.org and the services it sends lookups on to, a submission’s papers keep only their DOI. Without api.cloudflare.com, Analyze results can’t run. Ask whoever runs the server to let it reach them over HTTPS.', 'firehouse' ) . '</p>';
		return self::result( 'firehouse_outbound', 'recommended', __( 'FireHouse can’t reach some of the outside services it uses', 'firehouse' ), $uses . '<p>' . esc_html__( 'The server couldn’t reach:', 'firehouse' ) . '</p>' . $list . $fails );
	}

	/**
	 * Where the Workers AI key comes from, and whether one saved on FireHouse → Settings
	 * can still be read.
	 *
	 * @return array<string, mixed> The result, as Site Health takes it.
	 */
	public static function ai_key(): array {
		$about = '<p>' . esc_html( Workers_Ai_Key::status() ) . '</p><p>' . esc_html__( 'An administrator saves the key under FireHouse → Settings, in its Workers AI key section. A key in wp-config.php, as FIREHOUSE_WORKERS_AI_ACCOUNT and FIREHOUSE_WORKERS_AI_TOKEN, is used only while none is saved there.', 'firehouse' ) . '</p>';
		if ( Workers_Ai_Key::unreadable() ) {
			return self::result( 'firehouse_ai_key', 'recommended', __( 'FireHouse’s saved Workers AI key can’t be read', 'firehouse' ), $about );
		}
		$labels = array(
			Workers_Ai::FROM_SETTINGS => __( 'FireHouse’s Workers AI key is saved in its settings', 'firehouse' ),
			Workers_Ai::FROM_CONFIG   => __( 'FireHouse’s Workers AI key is in wp-config.php', 'firehouse' ),
		);
		return self::result( 'firehouse_ai_key', 'good', $labels[ (string) Workers_Ai::source() ] ?? __( 'FireHouse has no Workers AI key', 'firehouse' ), $about );
	}

	/**
	 * Whether search engines are kept out of a site that isn't production, and let into
	 * production.
	 *
	 * @return array<string, mixed> The result, as Site Health takes it.
	 */
	public static function search(): array {
		$type       = wp_get_environment_type();
		$production = 'production' === $type;
		$kept_out   = '0' === (string) get_option( 'blog_public' );
		$setting    = esc_html__( 'The setting is Settings → Reading → Search engine visibility. Whether a site counts as production comes from WP_ENVIRONMENT_TYPE in its wp-config.php.', 'firehouse' );
		if ( $production && ! $kept_out ) {
			return self::result( 'firehouse_search', 'good', __( 'Search engines may index FireHouse’s pages on this production site', 'firehouse' ), '<p>' . $setting . '</p>' );
		}
		if ( $production ) {
			return self::result( 'firehouse_search', 'recommended', __( 'Search engines are kept out of this production site, FireHouse’s pages with it', 'firehouse' ), '<p>' . esc_html__( 'If this is the public site, clear “Discourage search engines from indexing this site”. If it’s a test or local copy, set WP_ENVIRONMENT_TYPE to “staging” or “local”, so this check knows.', 'firehouse' ) . ' ' . $setting . '</p>' );
		}
		if ( $kept_out ) {
			/* translators: %s: the site's environment type, as "staging". */
			$label = sprintf( __( 'Search engines are kept out of this %s site', 'firehouse' ), $type );
			return self::result( 'firehouse_search', 'good', $label, '<p>' . esc_html__( 'WordPress marks every page noindex, so links copied from FireHouse’s pages here don’t compete with the public site’s.', 'firehouse' ) . ' ' . $setting . '</p>' );
		}
		/* translators: %s: the site's environment type, as "staging". */
		$label = sprintf( __( 'Search engines may index this %s site', 'firehouse' ), $type );
		return self::result( 'firehouse_search', 'recommended', $label, '<p>' . esc_html__( 'FireHouse’s pages here could be found in a search and taken for the public site’s. Tick “Discourage search engines from indexing this site”, so WordPress marks every page noindex.', 'firehouse' ) . ' ' . $setting . '</p>' );
	}

	/**
	 * Whether a cache in front of the site keeps FireHouse's pages: the landing page, asked
	 * for by the server at the site's own address, as a visitor would be served it.
	 *
	 * @return array<string, mixed> The result, as Site Health takes it.
	 */
	public static function caching(): array {
		$url      = home_url( '/' . Query::ROOT_PATH . '/' );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'user-agent'  => 'FireHouse WordPress plugin',
			)
		);
		$outside  = '<p>' . esc_html(
			/* translators: %s: the landing page's address. */
			sprintf( __( 'To check from outside the server, ask for %s with a tool such as curl -sI and read its cf-cache-status header: DYNAMIC means Cloudflare didn’t keep it.', 'firehouse' ), $url )
		) . '</p>';
		if ( is_wp_error( $response ) ) {
			/* translators: %s: why the request failed. */
			$why = sprintf( __( 'The server couldn’t load the FireHouse landing page from its own address: %s', 'firehouse' ), $response->get_error_message() );
			return self::result( 'firehouse_caching', 'recommended', __( 'FireHouse couldn’t check whether its pages are cached', 'firehouse' ), '<p>' . esc_html( $why ) . '</p>' . $outside );
		}
		// A redirect, an access gate's or a challenge's page, or a missing landing page
		// says nothing about how the page a visitor gets is cached.
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			/* translators: %d: the HTTP status code. */
			$why = sprintf( __( 'The server asked for the FireHouse landing page at its own address and got HTTP %d, not the page, so its headers say nothing about how a visitor’s page is cached.', 'firehouse' ), $code );
			return self::result( 'firehouse_caching', 'recommended', __( 'FireHouse couldn’t check whether its pages are cached', 'firehouse' ), '<p>' . esc_html( $why ) . '</p>' . $outside );
		}

		$status = strtoupper( trim( self::header( $response, 'cf-cache-status' ) ) );
		$age    = self::header( $response, 'age' );
		$other  = self::header( $response, 'x-cache' );
		$stale  = '<p>' . esc_html__( 'Then publishing, unpublishing or hiding a project doesn’t show until the cache expires, and a project whose IRB approval is cleared stays on the pages that showed it. The plugin doesn’t clear the cache when content changes. Turn off caching for FireHouse’s pages, under /firehouse/, or tell the developer, who would have the plugin clear it (WORDPRESS-PLAN.md, Caching).', 'firehouse' ) . '</p>';

		if ( in_array( $status, self::KEPT, true ) ) {
			/* translators: %s: Cloudflare's cache status, as HIT. */
			$found = sprintf( __( 'The landing page came back with cf-cache-status: %s.', 'firehouse' ), $status );
			return self::result( 'firehouse_caching', 'critical', __( 'Cloudflare keeps FireHouse’s pages in its cache', 'firehouse' ), '<p>' . esc_html( $found ) . '</p>' . $stale );
		}
		if ( ( is_numeric( $age ) && (int) $age > 0 ) || false !== stripos( $other, 'HIT' ) ) {
			/* translators: 1: the Age header, 2: the X-Cache header. */
			$found = sprintf( __( 'It came back with Age: %1$s and X-Cache: %2$s.', 'firehouse' ), '' === $age ? '–' : $age, '' === $other ? '–' : $other );
			return self::result( 'firehouse_caching', 'recommended', __( 'A cache in front of the site served FireHouse’s landing page from its store', 'firehouse' ), '<p>' . esc_html( $found ) . '</p>' . $stale );
		}
		if ( '' === $status ) {
			return self::result( 'firehouse_caching', 'good', __( 'No cache said it kept FireHouse’s landing page', 'firehouse' ), '<p>' . esc_html__( 'The page the server loaded from its own address carried no cache header. It may not have passed through Cloudflare on the way, as a visitor’s does.', 'firehouse' ) . '</p>' . $outside );
		}
		/* translators: %s: Cloudflare's cache status, as DYNAMIC. */
		$found = sprintf( __( 'The landing page came back with cf-cache-status: %s, so publishing and hiding a project show at once.', 'firehouse' ), $status );
		return self::result( 'firehouse_caching', 'good', __( 'Cloudflare passes FireHouse’s pages on without keeping them', 'firehouse' ), '<p>' . esc_html( $found ) . '</p>' );
	}

	/**
	 * Sends a check's result to Site Health's script, for whoever may see Site Health.
	 *
	 * @param callable $check The check.
	 */
	private static function answer( callable $check ): void {
		check_ajax_referer( 'health-check-site-status' );
		if ( ! current_user_can( 'view_site_health_checks' ) ) {
			wp_send_json_error();
		}
		wp_send_json_success( $check() );
	}

	/**
	 * A response's header as one line, "" when it has none.
	 *
	 * @param array<string, mixed> $response The response.
	 * @param string               $name     The header.
	 */
	private static function header( array $response, string $name ): string {
		$value = wp_remote_retrieve_header( $response, $name );
		return is_array( $value ) ? implode( ', ', $value ) : (string) $value;
	}

	/**
	 * A result as Site Health takes it, with FireHouse's badge.
	 *
	 * @param string $test        The check's name.
	 * @param string $status      good, recommended or critical.
	 * @param string $label       What it found.
	 * @param string $description More, as HTML already escaped.
	 * @return array<string, mixed>
	 */
	private static function result( string $test, string $status, string $label, string $description ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'FireHouse', 'firehouse' ),
				'color' => 'blue',
			),
			'description' => $description,
			'actions'     => '',
			'test'        => $test,
		);
	}
}
