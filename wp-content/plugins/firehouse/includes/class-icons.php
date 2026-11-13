<?php
/**
 * The site's icons, inlined.
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
 * Inlines an icon from assets/icons/icons.php as the site's `Icon` component draws it
 * (WORDPRESS-PLAN.md, Phase 3, icons and logos).
 *
 * The icons are one generated array of SVG by name, which opcache keeps compiled, so a
 * request reads no file for them (Phase 4's weight). Only a name in Vocabulary::icons()
 * is looked up, never one built from content, and each icon holds only what
 * shared/icon-svg.json allows (tests/IconsTest.php). Everything added goes in where
 * each icon's `<svg` opens: the size in place of its 24×24, `aria-hidden` and
 * `focusable`, since every icon is decorative, the component's inline style, and any
 * attributes the caller asks for, escaped.
 */
final class Icons {

	/**
	 * How every icon opens, up to the attributes this replaces.
	 */
	private const HEAD = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" ';

	/**
	 * The attributes this sets on every icon, or the icon does, which a caller's can't
	 * repeat: HTML keeps the first of a pair, so a second would be lost or win wrongly.
	 */
	private const OWN = array( 'xmlns', 'width', 'height', 'aria-hidden', 'focusable', 'style', 'viewbox', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin' );

	/**
	 * Every icon, by name, once the first is asked for.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $icons = null;

	/**
	 * The icon `$name` at `$size` pixels, or '' for a name the vocabulary doesn't hold.
	 *
	 * @param string                     $name       The icon's name.
	 * @param int                        $size       Its width and height, in pixels.
	 * @param array<string, string|true> $attributes More attributes, such as a class;
	 *                                               `true` writes the name alone. A name
	 *                                               that isn't a plain attribute name, is
	 *                                               an event handler's, or is one this
	 *                                               sets itself, is left out.
	 * @param float|null                 $stroke     A stroke width in place of the file's 2,
	 *                                               as a topic tag draws its icon (2.25).
	 */
	public static function svg( string $name, int $size, array $attributes = array(), ?float $stroke = null ): string {
		if ( ! in_array( $name, Vocabulary::icons(), true ) ) {
			return '';
		}
		if ( null === self::$icons ) {
			// A missing file costs the icons, not the page, as a missing icon always has.
			$icons       = include dirname( __DIR__ ) . '/assets/icons/icons.php';
			self::$icons = is_array( $icons ) ? $icons : array();
		}
		$svg = self::$icons[ $name ] ?? null;
		if ( ! is_string( $svg ) || ! str_starts_with( $svg, self::HEAD ) ) {
			return '';
		}

		$added = sprintf( ' width="%1$d" height="%1$d" aria-hidden="true" focusable="false"', $size );
		foreach ( $attributes as $attribute => $value ) {
			if ( 1 !== preg_match( '/^[a-z][a-z0-9-]*$/', $attribute ) || str_starts_with( $attribute, 'on' ) || in_array( $attribute, self::OWN, true ) ) {
				continue;
			}
			$added .= true === $value ? ' ' . $attribute : sprintf( ' %s="%s"', $attribute, esc_attr( $value ) );
		}
		$added .= ' style="display:inline-flex;flex-shrink:0"';

		$rest = substr( $svg, strlen( self::HEAD ) );
		if ( null !== $stroke && $stroke > 0 ) {
			$rest = preg_replace( '/ stroke-width="2"/', sprintf( ' stroke-width="%s"', (string) round( $stroke, 2 ) ), $rest, 1 ) ?? $rest;
		}
		return '<svg xmlns="http://www.w3.org/2000/svg"' . $added . ' ' . $rest;
	}
}
