<?php
/**
 * The site's small components, as markup.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

use Firehouse\Domain\Vocabulary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The markup of the design system's small components, for the plugin's pages: what the
 * site's `Button` and `TopicTag` render, with their classes, so firehouse.css styles
 * them the same. Everything returned is escaped.
 */
final class Markup {

	/**
	 * Each button size's icon size, as `Button` sets them.
	 */
	private const BUTTON_ICON = array(
		'sm' => 14,
		'md' => 16,
		'lg' => 18,
	);

	/**
	 * A link styled as the site's `Button`. A link to another site opens in a new tab
	 * and tells screen readers so (rule 11).
	 *
	 * @param string                        $label   The words on it.
	 * @param string                        $url     Where it goes.
	 * @param string                        $variant `primary`, `secondary`, `accent`, `ghost` or `link`.
	 * @param 'sm'|'md'|'lg'                $size    Its size.
	 * @param array{0?: string, 1?: string} $icons The icons before and after the words, by name.
	 */
	public static function button( string $label, string $url, string $variant = 'primary', string $size = 'md', array $icons = array() ): string {
		$icon     = self::BUTTON_ICON[ $size ] ?? 16;
		$external = self::is_external( $url );
		return sprintf(
			'<a href="%1$s" class="fh-btn fh-btn--%2$s fh-btn--%3$s"%4$s>%5$s%6$s%7$s%8$s</a>',
			esc_url( $url ),
			esc_attr( $variant ),
			esc_attr( $size ),
			$external ? ' target="_blank" rel="noopener noreferrer"' : '',
			isset( $icons[0] ) ? Icons::svg( $icons[0], $icon ) : '',
			esc_html( $label ),
			isset( $icons[1] ) ? Icons::svg( $icons[1], $icon ) : '',
			$external ? '<span class="fh-visually-hidden"> ' . esc_html__( '(opens in a new tab)', 'firehouse' ) . '</span>' : ''
		);
	}

	/**
	 * Whether an address is on another site than this one.
	 *
	 * @param string $url The address.
	 */
	public static function is_external( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $host ) && strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) !== strtolower( $host );
	}

	/**
	 * The site's `TopicTag`: a topic area's pill, in its colours, with its icon. A key
	 * outside the vocabulary gives the first area's, as the component does.
	 *
	 * @param string      $key   The topic area's key.
	 * @param 'sm'|'md'   $size  Its size.
	 * @param string|null $label Its words, or null for the area's short label.
	 */
	public static function topic_tag( string $key, string $size = 'md', ?string $label = null ): string {
		$areas = array_column( Vocabulary::topic_areas(), null, 'key' );
		$area  = $areas[ $key ] ?? reset( $areas );
		return sprintf(
			'<span class="fh-topic-tag fh-topic-tag--%1$s" style="--tag-text:var(--topic-%2$s-text);--tag-tint:var(--topic-%2$s-tint);--tag-border:var(--topic-%2$s-border)">%3$s%4$s</span>',
			'sm' === $size ? 'sm' : 'md',
			esc_attr( $area['key'] ),
			Icons::svg( $area['icon'], 'sm' === $size ? 12 : 14, array(), 2.25 ),
			esc_html( $label ?? $area['short'] )
		);
	}
}
