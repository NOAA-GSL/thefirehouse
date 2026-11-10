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
		add_filter( 'pre_redirect_guess_404_permalink', array( self::class, 'guess_404' ) );
	}

	/**
	 * Makes the Firehouse's own guess at a missing address under /firehouse/, in place
	 * of WordPress's. WordPress redirects a missing address to a published post whose
	 * slug starts with the address's last part, of any type in site search unless the
	 * address names one. Under /firehouse/ that could leave the Firehouse:
	 * /firehouse/about/ would land on the site's own About page. And since projects
	 * and topics are kept out of site search, WordPress no longer guesses among them
	 * at all, even at their own addresses.
	 *
	 * So under /firehouse/, a project's or topic's address is guessed among its own
	 * type, the way WordPress would: a shared link cut short still finds its project
	 * (rule 5). The guess is among the projects a visitor may see
	 * (Store::public_projects_query()), and among published topics without a
	 * password. Any other missing address there gets no guess, and the frame's
	 * not-found page. Outside /firehouse/, WordPress guesses as usual.
	 *
	 * Runs on every missing address on the site, unless a `do_redirect_guess_404_permalink`
	 * filter has turned guessing off. At the default priority, it keeps an answer an
	 * earlier filter gave, and a later one can still change it.
	 *
	 * @param mixed $pre Another filter's answer, or null to let WordPress guess.
	 * @return mixed The address to redirect to, false for none, or $pre.
	 */
	public static function guess_404( mixed $pre ): mixed {
		global $wp;
		if ( null !== $pre || ! ( $wp instanceof \WP && Query::under_root( $wp->request ) ) ) {
			return $pre;
		}
		$type = get_query_var( 'post_type' );
		$name = get_query_var( 'name' );
		if ( ! in_array( $type, array( Post_Types::PROJECT, Post_Types::TOPIC ), true ) || ! is_string( $name ) || '' === $name ) {
			return false;
		}

		// WordPress's setting for a strict guess takes only a whole slug, and the query
		// has already looked for this one, published, and found none.
		if ( apply_filters( 'strict_redirect_guess_404_permalink', false ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's hook.
			return false;
		}

		// Every post of the type a visitor may see, in slug order: the match is a
		// prefix, which get_posts() can't ask for. No meta or terms are loaded, so this
		// stays small.
		$name  = strtolower( $name );
		$args  = array(
			'numberposts'            => -1,
			'orderby'                => 'name',
			'order'                  => 'ASC',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);
		$posts = get_posts(
			Post_Types::PROJECT === $type ? Store::public_projects_query( $args ) : array(
				'post_type'    => $type,
				'post_status'  => 'publish',
				'has_password' => false,
			) + $args
		);
		foreach ( $posts as $post ) {
			// A project is checked as the site checks it, as well as by the query: a
			// value written around Meta mustn't pass one and fail the other.
			if ( str_starts_with( $post->post_name, $name ) && ( Post_Types::PROJECT !== $type || Store::is_public( $post ) ) ) {
				return get_permalink( $post );
			}
		}
		return false;
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
	 * Prints the page's content: the not-found page at a missing address, a project's or
	 * a topic area's page at its address, a page in the Firehouse tree's own blocks, and
	 * a placeholder on each page Phase 4 hasn't built yet.
	 */
	public static function content(): void {
		if ( is_404() ) {
			echo do_blocks( '<!-- wp:firehouse/not-found /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the plugin's own block, which escapes what it prints.
			return;
		}
		if ( is_singular( Post_Types::PROJECT ) ) {
			echo do_blocks( '<!-- wp:firehouse/project /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above.
			return;
		}
		if ( is_singular( Post_Types::TOPIC ) ) {
			echo do_blocks( '<!-- wp:firehouse/topic /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- as above.
			return;
		}
		$page = get_queried_object();
		if ( is_page() && $page instanceof \WP_Post && '' !== trim( $page->post_content ) ) {
			self::page( $page );
			return;
		}
		self::placeholder();
	}

	/**
	 * A page in the Firehouse tree: its blocks, rendered as they are, or WordPress's
	 * password form if it has a password the visitor hasn't given. Not through
	 * `the_content`, whose filters (paragraphs added, other plugins' additions) were
	 * written for a theme's posts, not for a page of the plugin's own blocks.
	 *
	 * @param \WP_Post $page The page.
	 */
	public static function page( \WP_Post $page ): void {
		if ( post_password_required( $page ) ) {
			echo get_the_password_form( $page ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress's own form.
			return;
		}
		echo do_blocks( $page->post_content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the page's blocks, each of which escapes what it prints.
	}

	/**
	 * What the frame holds on a page Phase 4 hasn't built yet: a heading and a line.
	 */
	public static function placeholder(): void {
		printf(
			'<section class="fh-section"><div class="fh-container"><h1>%1$s</h1><p>%2$s</p></div></section>',
			esc_html__( 'The Firehouse', 'firehouse' ),
			esc_html__( 'This page is being built.', 'firehouse' )
		);
	}

	/**
	 * Prints the disclaimer that ends every Firehouse page, inside the main landmark,
	 * after the content and above the theme's footer, as the site's `Disclaimer` does.
	 *
	 * Stephanie and Emily approved the wording on 3 November, for the landing, About and
	 * project pages, and asked for it, quietly, at the end of every page; NOAA's
	 * clearance of it is still open (README, Before this goes public). The plugin keeps
	 * it here, not as a setting, and tests/FrameTest.php holds it to `disclaimer` in the
	 * site's settings.json. Whether an editor may reword it is decided with the editing
	 * screens in Phase 6.
	 */
	public static function disclaimer(): void {
		printf(
			'<div class="fh-disclaimer fh-container"><p class="fh-disclaimer__text">%s</p></div>',
			esc_html__( 'Researchers inside and outside NOAA submit the projects on this site. Their findings and opinions are their own and do not necessarily reflect the views of NOAA or the U.S. Department of Commerce.', 'firehouse' )
		);
	}
}
