<?php
/**
 * What the pages the Import screen creates share.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The landing page and the About page are blocks whose attributes hold their words,
 * written by the Import screen from the seed bundle. This builds those blocks from the
 * bundle's text, and works out where their links go when they render.
 *
 * A link to the survey address is stored empty and follows the Submit a Finding
 * address in the settings, so the address lives in one field.
 */
final class Page_Blocks {

	/**
	 * The named fields of a part of the bundle that are text, in that order; anything
	 * else in it, and any of them that isn't text, left out.
	 *
	 * @param mixed    $part  The part: a link, a stat, an item, or a section's words.
	 * @param string[] $names The fields to keep.
	 * @return array<string, string>
	 */
	public static function text_fields( mixed $part, array $names ): array {
		$kept = array();
		foreach ( $names as $name ) {
			if ( is_array( $part ) && is_string( $part[ $name ] ?? null ) ) {
				$kept[ $name ] = $part[ $name ];
			}
		}
		return $kept;
	}

	/**
	 * A link as a page stores it: its label and where it goes, text only. A link to the
	 * bundle's survey address is stored empty, so it follows the settings.
	 *
	 * @param mixed  $link       The bundle's link.
	 * @param string $submit_url The bundle's survey address.
	 * @return array<string, string>
	 */
	public static function link_fields( mixed $link, string $submit_url ): array {
		$link = self::text_fields( $link, array( 'label', 'to', 'href' ) );
		if ( isset( $link['href'] ) && $link['href'] === $submit_url ) {
			$link['href'] = '';
		}
		return $link;
	}

	/**
	 * A parsed block, as serialize_blocks() takes it.
	 *
	 * @param string                     $name   The block's name.
	 * @param array<string, mixed>       $attrs  Its attributes.
	 * @param list<array<string, mixed>> $inner  Its inner blocks.
	 * @return array<string, mixed>
	 */
	public static function block( string $name, array $attrs, array $inner = array() ): array {
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner,
			'innerHTML'    => '',
			'innerContent' => array_fill( 0, count( $inner ), null ),
		);
	}

	/**
	 * Where a link goes: a Firehouse route under /firehouse/, its query and fragment
	 * kept; an address as given; or, for one with no route and an empty or missing
	 * address, the settings' survey address. '' when there's nowhere.
	 *
	 * @param mixed $link The stored link: its `label`, and a `to` or an `href`.
	 */
	public static function link( mixed $link ): string {
		if ( ! is_array( $link ) ) {
			return '';
		}
		if ( is_string( $link['to'] ?? null ) && '' !== $link['to'] ) {
			$end  = strcspn( $link['to'], '?#' );
			$path = '/' . trim( substr( $link['to'], 0, $end ), '/' );
			return home_url( '/' . Query::ROOT_PATH . rtrim( $path, '/' ) . '/' ) . substr( $link['to'], $end );
		}
		$href = is_string( $link['href'] ?? null ) ? $link['href'] : '';
		return '' !== $href ? $href : (string) Settings::get()['submitFormUrl'];
	}

	/**
	 * A button to where a stored link goes, or nothing when it has no label or nowhere
	 * WordPress will print: a button that leads nowhere is left out.
	 *
	 * @param mixed                $link    The stored link.
	 * @param string               $variant The button's variant.
	 * @param string               $size    Its size.
	 * @param array<int, string>   $icons   Its icons: [0] before the label, [1] after.
	 * @param string[]             $modifiers Its modifiers.
	 * @param array<string, mixed> $options Markup::button()'s `extra_class` and `icon_size`.
	 */
	public static function button( mixed $link, string $variant, string $size, array $icons = array(), array $modifiers = array(), array $options = array() ): string {
		$url   = self::link( $link );
		$label = is_array( $link ) && is_string( $link['label'] ?? null ) ? $link['label'] : '';
		if ( '' === Markup::url( $url ) || '' === $label ) {
			return '';
		}
		return Markup::button( $label, $url, $variant, $size, $icons, $modifiers, (string) ( $options['extra_class'] ?? '' ), isset( $options['icon_size'] ) ? (int) $options['icon_size'] : null );
	}
}
