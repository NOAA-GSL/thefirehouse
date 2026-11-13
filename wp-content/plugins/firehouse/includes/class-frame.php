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
 * site's CSS scoped under `.fh-app` (assets/css/firehouse.min.css, generated from the
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
	 * The font files in assets/fonts/ that are preloaded: the latin subset of Public
	 * Sans, which sets the Firehouse bar's links and each page's text. Other subsets
	 * load as before, if a page uses their characters.
	 *
	 * Measured on the local copy (12 November): a first view, over HTTP/1.1, on an
	 * emulated slow 4G connection. Without a preload, both fonts arrived about a
	 * second after the first paint, and the text moved as Public Sans swapped in: a
	 * layout shift of up to 0.07 on a topic page at desktop width, and 0.08 on a
	 * project page on a phone. Preloading Public Sans took the shift under 0.002, and
	 * put the first paint, which was also the largest, 0 to 0.3 s later, since the
	 * font shares the connection with the stylesheets. Preloading Archivo too, for the
	 * headings and the bar's name, also took away their change of font, which moves
	 * almost nothing, for a first paint 0.15 to 0.55 s later than none. So the
	 * headings still swap in. A second view takes the fonts from the cache. Where
	 * stylesheets can go first, over HTTP/2 or 3, a preload may cost less, so the
	 * plan has Archivo measured again on the test site.
	 */
	public const PRELOADED_FONTS = array( 'public-sans-normal-latin.woff2' );

	/**
	 * Hooks the template, the stylesheets, the font preloads, the body class and the
	 * not-found redirect. The stylesheets enqueue at priority 20, after a theme's at
	 * the default 10, so they print after it.
	 */
	public static function hook(): void {
		add_filter( 'template_include', array( self::class, 'template' ), 99 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 20 );
		add_filter( 'wp_preload_resources', array( self::class, 'preload' ) );
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
	 * The plugin's frame for a Firehouse request; the theme's choice for any other. The
	 * filter runs on every page, and an earlier one may return anything, so it's taken
	 * as given and passed on as it was.
	 *
	 * @param mixed $template The template WordPress, or an earlier filter, chose.
	 * @return mixed
	 */
	public static function template( mixed $template ): mixed {
		return self::is_firehouse_request() ? dirname( __DIR__ ) . '/templates/frame.php' : $template;
	}

	/**
	 * Loads the Firehouse stylesheets on a Firehouse request, in order. frame.css
	 * depends on the site's CSS; nothing depends on the fonts, so a site that wants its
	 * own can dequeue `firehouse-fonts` and lose nothing else. This runs at priority 20
	 * of `wp_enqueue_scripts`, so the dequeue has to come after it, at 21 or later:
	 * WordPress forgets a dequeue of a handle not yet queued.
	 *
	 * The site's CSS is the minified copy, firehouse.min.css, or under SCRIPT_DEBUG the
	 * same rules readable, firehouse.css, as WordPress chooses between its own
	 * (`wp_scripts_get_suffix()`). scripts/plugin-css.mjs writes both.
	 */
	public static function enqueue(): void {
		if ( ! self::is_firehouse_request() ) {
			return;
		}
		self::style( self::FONTS, 'fonts.css', array() );
		self::style( self::STYLE, 'firehouse' . wp_scripts_get_suffix() . '.css', array() );
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
	 * Has the browser fetch the fonts in PRELOADED_FONTS as it reads the page's head.
	 * Otherwise it asks for a font only once the stylesheets have arrived and it has
	 * found text that uses it, and the text shows in a fallback font until then.
	 *
	 * Only while fonts.css is enqueued, so a site that dequeues it for its own fonts
	 * preloads nothing. WordPress prints preloads after the stylesheets are enqueued:
	 * both run at the head's first priority, the stylesheets first. A dequeue on
	 * `wp_enqueue_scripts` after priority 20 is seen; one later in the head, on
	 * `wp_print_styles` say, comes after the preload has printed, and the browser fetches a font nothing uses.
	 *
	 * Each address is the one fonts.css names, with no version: a preload is used only
	 * by a request for the same address, or the browser fetches the font twice. Fonts
	 * are fetched in CORS mode, so the preload is too (`crossorigin`), even from the
	 * same site, or the browser doesn't match the two. The address is built as the
	 * stylesheet's is, from plugins_url(), but not passed through `style_loader_src`:
	 * a plugin that moves stylesheets to another host with that filter, or combines
	 * them, leaves the preload unused. Such a site can remove this filter.
	 *
	 * The enqueue check comes first, since it's the cheaper, and fonts.css is enqueued
	 * only on a Firehouse request.
	 *
	 * @param mixed $resources The preloads other filters have asked for.
	 * @return mixed
	 */
	public static function preload( mixed $resources ): mixed {
		if ( ! is_array( $resources ) || ! wp_style_is( self::FONTS, 'enqueued' ) || ! self::is_firehouse_request() ) {
			return $resources;
		}
		$root = dirname( __DIR__ );
		foreach ( self::PRELOADED_FONTS as $file ) {
			$resources[] = array(
				'href'        => plugins_url( 'assets/fonts/' . $file, $root . '/firehouse.php' ),
				'as'          => 'font',
				'type'        => 'font/woff2',
				'crossorigin' => 'anonymous',
			);
		}
		return $resources;
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
	 * a placeholder on a page with nothing to show yet (the explorer until Phase 5, or an
	 * empty page in the tree).
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
		// The query's post, not the queried object: a preview of a published page swaps
		// its unsaved changes into the one, and leaves the other as published.
		$page = get_post();
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
	 * What the frame holds on a page with nothing to show yet: a heading and a line.
	 */
	public static function placeholder(): void {
		// On the explorer, the heading names the page as its link and its title do; on an
		// empty page in the tree, it names the site.
		printf(
			'<section class="fh-section"><div class="fh-container"><h1>%1$s</h1><p>%2$s</p></div></section>',
			esc_html( is_post_type_archive( Post_Types::PROJECT ) ? (string) Settings::get()['navLabels']['projects'] : __( 'The FireHouse', 'firehouse' ) ),
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
