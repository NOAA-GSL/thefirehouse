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
 * A Firehouse request is what Query::is_firehouse() says: the project archive, a
 * project, a topic, a page in the Firehouse tree, or a missing address under any of
 * them. The template calls the theme's own get_header() and get_footer(), and puts
 * the Firehouse wrapper, `main.fh-app`, between them, outside the theme's content
 * wrappers, whose rules would beat the inward reset (rule 9).
 *
 * The stylesheets load after the theme's, so ties go to Firehouse: the fonts, the
 * site's CSS scoped under `.fh-app` (assets/css/firehouse.css, generated from the
 * site), and frame.css, the little the wrapper needs only inside a theme's page.
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
	 * Hooks the template, the stylesheets and the body class. The stylesheets enqueue
	 * at priority 20, after a theme's at the default 10, so they print after it.
	 */
	public static function hook(): void {
		add_filter( 'template_include', array( self::class, 'template' ), 99 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 20 );
		add_filter( 'body_class', array( self::class, 'body_class' ) );
	}

	/**
	 * Whether the main request is a Firehouse request.
	 */
	public static function is_firehouse_request(): bool {
		global $wp;
		return $wp instanceof \WP && Query::is_firehouse( (array) $wp->query_vars );
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
	 * Loads the Firehouse stylesheets on a Firehouse request, each depending on the
	 * one before it. The plugin's version is in each URL, so a release isn't served
	 * from a stale cache.
	 */
	public static function enqueue(): void {
		if ( ! self::is_firehouse_request() ) {
			return;
		}
		$main = dirname( __DIR__ ) . '/firehouse.php';
		wp_enqueue_style( self::FONTS, plugins_url( 'assets/css/fonts.css', $main ), array(), VERSION );
		wp_enqueue_style( self::STYLE, plugins_url( 'assets/css/firehouse.css', $main ), array( self::FONTS ), VERSION );
		wp_enqueue_style( self::FRAME, plugins_url( 'assets/css/frame.css', $main ), array( self::STYLE ), VERSION );
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
