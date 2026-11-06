<?php
/**
 * The Firehouse frame: GSL's header and footer around the Firehouse wrapper.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Puts every Firehouse request in templates/frame.php and loads the Firehouse
 * stylesheets for it, on those requests only (WORDPRESS-PLAN.md, Look and feel).
 *
 * A Firehouse request is the project archive, a project, a topic, a page in the
 * Firehouse tree, or a missing address under /firehouse/. The template calls the
 * theme's own get_header() and get_footer(), and puts the Firehouse wrapper,
 * `div.fh-app`, between them, outside the theme's content wrappers, whose rules
 * would beat the inward reset (rule 9). The wrapper holds the skip link, the
 * Firehouse bar and the page's main landmark.
 *
 * The stylesheets load after the theme's, so ties go to Firehouse: the fonts, the
 * site's CSS scoped under `.fh-app` (assets/css/firehouse.css, generated from the
 * site), and frame.css, the little Firehouse needs only inside a theme's page.
 */
final class Frame {

	/**
	 * Style handles, in load order.
	 */
	public const FONTS = 'firehouse-fonts';
	public const STYLE = 'firehouse';
	public const FRAME = 'firehouse-frame';

	/**
	 * The class every Firehouse page's body carries, which frame.css keys on.
	 */
	public const BODY_CLASS = 'fh-page';

	/**
	 * Hooks the template, the stylesheets, the body class and the not-found redirect.
	 * The stylesheets enqueue at priority 20, after a theme's at the default 10, so
	 * they print after it.
	 */
	public static function hook(): void {
		add_filter( 'template_include', array( self::class, 'template' ), 99 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 20 );
		add_filter( 'body_class', array( self::class, 'body_class' ) );
		add_filter( 'do_redirect_guess_404_permalink', array( self::class, 'guess_404_permalink' ) );
	}

	/**
	 * Keeps WordPress's guess at a missing address under /firehouse/ inside the
	 * Firehouse. WordPress redirects to a published post whose slug starts with the
	 * address's last part, of any type unless the address names one. So
	 * /firehouse/about/ would land on the site's own About page, and gets the frame's
	 * not-found page instead. A project's or topic's address keeps the guess, which
	 * looks only among its own type: a shared link cut short still finds its project
	 * (rule 5). Anywhere else, WordPress guesses as usual.
	 *
	 * Runs on every missing address on the site, after any other plugin's filter, so
	 * it takes whatever value that left.
	 *
	 * @param mixed $guess Whether WordPress would guess.
	 */
	public static function guess_404_permalink( mixed $guess ): bool {
		global $wp;
		if ( ! $guess || ! ( $wp instanceof \WP && Query::under_root( $wp->request ) ) ) {
			return (bool) $guess;
		}
		return in_array( get_query_var( 'post_type' ), array( Post_Types::PROJECT, Post_Types::TOPIC ), true );
	}

	/**
	 * Whether the main request is a Firehouse request, decided from the query
	 * WordPress ran, so the hooks here must run after it.
	 *
	 * The request's own query variables can't decide it. A preview of a draft page
	 * arrives as `?page_id=`, which names no path, and core's rules send a missing
	 * address under /firehouse/ elsewhere when no page is there. An embed keeps
	 * WordPress's own embed template.
	 */
	public static function is_firehouse_request(): bool {
		if ( is_embed() ) {
			return false;
		}
		if ( is_singular( array( Post_Types::PROJECT, Post_Types::TOPIC ) ) || is_post_type_archive( Post_Types::PROJECT ) ) {
			return true;
		}
		if ( is_page() ) {
			$page = get_queried_object();
			return $page instanceof \WP_Post && Capabilities::in_tree( $page );
		}
		global $wp;
		return is_404() && $wp instanceof \WP && Query::under_root( $wp->request );
	}

	/**
	 * The plugin's frame for a Firehouse request; the theme's choice for any other.
	 *
	 * @param string $template The template WordPress chose.
	 */
	public static function template( string $template ): string {
		return self::is_firehouse_request() ? dirname( __DIR__ ) . '/templates/frame.php' : $template;
	}

	/**
	 * Loads the Firehouse stylesheets on a Firehouse request, in order. frame.css
	 * depends on firehouse.css; nothing depends on the fonts, so a site that wants its
	 * own can dequeue `firehouse-fonts` and lose nothing else.
	 */
	public static function enqueue(): void {
		if ( ! self::is_firehouse_request() ) {
			return;
		}
		self::style( self::FONTS, 'fonts.css', array() );
		self::style( self::STYLE, 'firehouse.css', array() );
		self::style( self::FRAME, 'frame.css', array( self::STYLE ) );
	}

	/**
	 * Enqueues a stylesheet from assets/css/. Its URL carries the plugin's version
	 * and the file's modified time, so a changed file is never served from a stale
	 * cache, even when the version hasn't moved.
	 *
	 * @param string             $handle The style handle.
	 * @param string             $file   The file's name in assets/css/.
	 * @param array<int, string> $deps   The handles it loads after.
	 */
	private static function style( string $handle, string $file, array $deps ): void {
		$root = dirname( __DIR__ );
		$path = $root . '/assets/css/' . $file;
		wp_enqueue_style( $handle, plugins_url( 'assets/css/' . $file, $root . '/firehouse.php' ), $deps, VERSION . '-' . (int) filemtime( $path ) );
	}

	/**
	 * Adds the Firehouse body class on a Firehouse request.
	 *
	 * @param array<int, string> $classes The body's classes.
	 * @return array<int, string>
	 */
	public static function body_class( array $classes ): array {
		if ( self::is_firehouse_request() ) {
			$classes[] = self::BODY_CLASS;
		}
		return $classes;
	}

	/**
	 * What the frame holds until Phase 4 builds the pages: a heading and a line, or
	 * the Firehouse not-found message at a missing address.
	 */
	public static function placeholder(): void {
		if ( is_404() ) {
			$heading = __( 'Page not found', 'firehouse' );
			$text    = __( 'There’s no Firehouse page at this address.', 'firehouse' );
		} else {
			$heading = __( 'The Firehouse', 'firehouse' );
			$text    = __( 'This page is being built.', 'firehouse' );
		}
		printf(
			'<section class="fh-section"><div class="fh-container"><h1>%1$s</h1><p>%2$s</p></div></section>',
			esc_html( $heading ),
			esc_html( $text )
		);
	}
}
