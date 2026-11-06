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
 * Inlines an icon from assets/icons/ as the site's `Icon` component draws it
 * (WORDPRESS-PLAN.md, Phase 3, icons and logos).
 *
 * Only a name in Vocabulary::icons() is read, never a path built from content, and the
 * files hold only what shared/icon-svg.json allows (tests/IconsTest.php). Everything
 * added goes in where each file's `<svg` opens: the size in place of the file's 24×24,
 * `aria-hidden` and `focusable`, since every icon is decorative, the component's
 * inline style, and any attributes the caller asks for, escaped.
 */
final class Icons {

	/**
	 * How every file opens, up to the attributes this replaces.
	 */
	private const HEAD = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" ';

	/**
	 * The files read so far, by name.
	 *
	 * @var array<string, string>
	 */
	private static array $files = array();

	/**
	 * The icon `$name` at `$size` pixels, or '' for a name the vocabulary doesn't hold.
	 *
	 * @param string                     $name       The icon's name.
	 * @param int                        $size       Its width and height, in pixels.
	 * @param array<string, string|true> $attributes More attributes, such as a class;
	 *                                               `true` writes the name alone. A name
	 *                                               that isn't a plain attribute name, or
	 *                                               is an event handler's, is left out.
	 */
	public static function svg( string $name, int $size, array $attributes = array() ): string {
		if ( ! in_array( $name, Vocabulary::icons(), true ) ) {
			return '';
		}
		if ( ! isset( self::$files[ $name ] ) ) {
			self::$files[ $name ] = trim( (string) file_get_contents( dirname( __DIR__ ) . '/assets/icons/' . $name . '.svg' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- one of the plugin's own files, named from the vocabulary.
		}
		$svg = self::$files[ $name ];
		if ( ! str_starts_with( $svg, self::HEAD ) ) {
			return '';
		}

		$added = sprintf( ' width="%1$d" height="%1$d" aria-hidden="true" focusable="false"', $size );
		foreach ( $attributes as $attribute => $value ) {
			if ( 1 !== preg_match( '/^[a-z][a-z0-9-]*$/', $attribute ) || str_starts_with( $attribute, 'on' ) ) {
				continue;
			}
			$added .= true === $value ? ' ' . $attribute : sprintf( ' %s="%s"', $attribute, esc_attr( $value ) );
		}
		$added .= ' style="display:inline-flex;flex-shrink:0"';

		return '<svg xmlns="http://www.w3.org/2000/svg"' . $added . ' ' . substr( $svg, strlen( self::HEAD ) );
	}
}
