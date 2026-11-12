<?php
/**
 * The Firehouse pages' titles, descriptions and link previews.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a Firehouse page says about itself in its head, as the site's pages do: its
 * title, a description, and the Open Graph and Twitter tags a shared link's preview is
 * built from (decided by Eric, 12 November).
 *
 * - **The title** names both: the site's title for the page, "About — The Firehouse",
 *   then the site's own suffix, " - Global Systems Laboratory".
 * - **The description** is the site's, from `index.html`, except on a project's page,
 *   where it's the project's abstract, or its summary if it has none, as the
 *   researcher wrote it.
 * - **The preview** uses the page's title, the shorter description the site gives
 *   previews (or the project's own), and the site's preview image.
 *
 * Each runs on every public page and changes nothing outside the Firehouse. If an SEO
 * plugin is active, or the `firehouse_head_tags` filter says no, the Firehouse leaves
 * titles and tags to it.
 */
final class Head {

	/**
	 * What the landing page's title calls the hub, after the site's name.
	 */
	public const HUB = 'Fire Weather Social Science Hub';

	/**
	 * The site's description, for search results.
	 */
	public const DESCRIPTION = 'The Firehouse centralizes end-user needs and recommendations from completed NOAA/GSL fire weather social science research, and accepts new findings from outside researchers.';

	/**
	 * The shorter description the site gives link previews.
	 */
	public const PREVIEW = 'A shared home for the needs and recommendations learned across completed NOAA/GSL fire weather social science research.';

	/**
	 * The preview image's alternative text.
	 */
	public const IMAGE_ALT = 'The Firehouse — fire weather social science needs and findings, NOAA Global Systems Laboratory.';

	/**
	 * The preview image, in the plugin's assets, and its size.
	 */
	private const IMAGE = 'assets/images/og-image.png';

	/**
	 * Constants an SEO plugin defines, one of which means it prints its own titles and
	 * tags.
	 */
	private const SEO_PLUGINS = array( 'WPSEO_VERSION', 'RANK_MATH_VERSION', 'AIOSEO_VERSION', 'SEOPRESS_VERSION', 'THE_SEO_FRAMEWORK_VERSION' );

	/**
	 * Hooks the title and the tags. The tags print early in the head, beside the
	 * title.
	 */
	public static function hook(): void {
		add_filter( 'pre_get_document_title', array( self::class, 'document_title' ) );
		add_action( 'wp_head', array( self::class, 'tags' ), 5 );
	}

	/**
	 * A Firehouse page's whole title, escaped: its own, then the site's suffix as
	 * WordPress would add it. Its own is escaped as stored text is (Markup::text()), so
	 * a project titled "R&amp;D" says so; WordPress's title escaping would show "R&D".
	 * Anything else keeps what an earlier filter gave, or WordPress's.
	 *
	 * @param string $title An earlier filter's title, or "" for WordPress's.
	 */
	public static function document_title( string $title ): string {
		if ( '' !== $title || ! self::active() ) {
			return $title;
		}
		$own = self::title();
		if ( '' === $own ) {
			return $title;
		}
		// WordPress's own separator filter, applied as wp_get_document_title() applies it,
		// so the suffix reads as the rest of the site's titles do.
		$separator = (string) apply_filters( 'document_title_separator', '-' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter.
		return Markup::text( $own ) . ' ' . esc_html( $separator ) . ' ' . get_bloginfo( 'name', 'display' );
	}

	/**
	 * Prints a Firehouse page's description and preview tags.
	 */
	public static function tags(): void {
		if ( ! self::active() ) {
			return;
		}
		$title = self::title();
		if ( '' === $title ) {
			return;
		}
		$own         = self::project_description();
		$description = '' !== $own ? $own : self::DESCRIPTION;
		$preview     = '' !== $own ? $own : self::PREVIEW;
		$image       = plugins_url( self::IMAGE, dirname( __DIR__ ) . '/firehouse.php' );
		$url         = is_singular() ? wp_get_canonical_url() : false;
		$site        = (string) Settings::get()['siteName'];

		$tags = array(
			array( 'name', 'description', $description ),
			array( 'property', 'og:type', 'website' ),
			array( 'property', 'og:site_name', $site ),
			array( 'property', 'og:locale', get_locale() ),
			array( 'property', 'og:title', $title ),
			array( 'property', 'og:description', $preview ),
		);
		if ( is_string( $url ) && '' !== $url ) {
			$tags[] = array( 'property', 'og:url', $url );
		}
		array_push(
			$tags,
			array( 'property', 'og:image', $image ),
			array( 'property', 'og:image:width', '1200' ),
			array( 'property', 'og:image:height', '630' ),
			array( 'property', 'og:image:alt', self::IMAGE_ALT ),
			array( 'name', 'twitter:card', 'summary_large_image' ),
			array( 'name', 'twitter:title', $title ),
			array( 'name', 'twitter:description', $preview ),
			array( 'name', 'twitter:image', $image )
		);
		foreach ( $tags as list( $attribute, $name, $content ) ) {
			$value = in_array( $name, array( 'og:url', 'og:image', 'twitter:image' ), true ) ? Markup::url( $content ) : Markup::text( $content );
			printf( '<meta %1$s="%2$s" content="%3$s" />' . "\n", esc_attr( $attribute ), esc_attr( $name ), $value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup escapes the value.
		}
	}

	/**
	 * The page's own title, as the site gives it: "Page not found — The Firehouse", a
	 * project's or topic area's "{title} — The Firehouse", "Explore projects — The
	 * Firehouse", the landing page's "The Firehouse — Fire Weather Social Science Hub",
	 * and the About page's "About — The Firehouse", named as the About link is. The
	 * site's name is the settings'. "" for any other page, which keeps WordPress's.
	 */
	public static function title(): string {
		$settings = Settings::get();
		$site     = (string) $settings['siteName'];
		$with     = static fn( string $name ): string => '' !== $name ? $name . ' — ' . $site : '';
		if ( is_404() ) {
			return $with( __( 'Page not found', 'firehouse' ) );
		}
		$post = get_queried_object();
		if ( is_singular( Post_Types::PROJECT ) && $post instanceof \WP_Post ) {
			$title = Store::project( $post )['fields']['title'] ?? null;
			return $with( is_string( $title ) ? $title : '' );
		}
		if ( is_singular( Post_Types::TOPIC ) && $post instanceof \WP_Post ) {
			return $with( (string) Store::topic( $post )['fields']['label'] );
		}
		if ( is_post_type_archive( Post_Types::PROJECT ) ) {
			return $with( __( 'Explore projects', 'firehouse' ) );
		}
		if ( is_page() && $post instanceof \WP_Post ) {
			$root = Capabilities::root_id();
			if ( $root === $post->ID ) {
				return $site . ' — ' . self::HUB;
			}
			if ( $root === (int) $post->post_parent && About_Page::SLUG === $post->post_name ) {
				return $with( (string) $settings['navLabels']['about'] );
			}
		}
		return '';
	}

	/**
	 * On a project's page, its abstract, or its summary if it has none, as the
	 * researcher wrote it; "" on any other page.
	 */
	private static function project_description(): string {
		$post = get_queried_object();
		if ( ! is_singular( Post_Types::PROJECT ) || ! $post instanceof \WP_Post ) {
			return '';
		}
		$fields = Store::project( $post )['fields'];
		foreach ( array( 'abstract', 'summary' ) as $name ) {
			if ( is_string( $fields[ $name ] ?? null ) && '' !== trim( $fields[ $name ] ) ) {
				return $fields[ $name ];
			}
		}
		return '';
	}

	/**
	 * Whether the Firehouse gives this request its title and tags: a Firehouse page,
	 * with no SEO plugin printing its own and the filter not saying no.
	 */
	private static function active(): bool {
		foreach ( self::SEO_PLUGINS as $constant ) {
			if ( defined( $constant ) ) {
				return false;
			}
		}
		/**
		 * Whether the Firehouse prints its own titles, descriptions and link previews
		 * on its pages. Return false to leave them to the theme or another plugin.
		 *
		 * @param bool $print True unless an SEO plugin is active.
		 */
		return (bool) apply_filters( 'firehouse_head_tags', true ) && Frame::is_firehouse_request();
	}
}
