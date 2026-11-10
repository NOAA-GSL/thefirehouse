<?php
/**
 * The site's small components, as markup.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

use Firehouse\Domain\Format;
use Firehouse\Domain\Vocabulary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The markup of the design system's small components, for the plugin's pages: what the
 * site's `Button`, `SynthesisNote` and `TopicTag` render, with their classes, so
 * firehouse.css styles them the same. Everything returned is escaped.
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
		$icon = self::BUTTON_ICON[ $size ] ?? 16;
		$href = self::url( $url );
		// Decided from the address the link leads to, after esc_url() has added any
		// scheme it lacked: `www.example.org` is another site's.
		$external = self::is_external( html_entity_decode( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		return sprintf(
			'<a href="%1$s" class="fh-btn fh-btn--%2$s fh-btn--%3$s"%4$s>%5$s%6$s%7$s%8$s</a>',
			$href,
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
	 * Stored text, escaped for HTML so it shows exactly as stored, as the site does
	 * (rule 1). esc_html() leaves alone a character reference that's already in the
	 * text, so a title stored as "R&amp;D" would show as "R&D"; this escapes every `&`.
	 * A broken UTF-8 sequence shows as U+FFFD rather than emptying the text.
	 *
	 * @param string $text The text as stored.
	 */
	public static function text( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * An address for an `href`, escaped, that leads where a browser would take it as
	 * written (as_browser_reads()); esc_url() then checks it, the protocol included.
	 *
	 * @param string $url The address as written.
	 */
	public static function url( string $url ): string {
		return esc_url( self::as_browser_reads( $url ) );
	}

	/**
	 * An address as a browser reads it, with nothing esc_url() would drop.
	 *
	 * A browser reads a `\` before the query as `/` in an http or https address,
	 * and in one with no scheme, which it resolves against the page's; esc_url() drops
	 * it, which can move the host (`https://doi.org\@elsewhere/` is doi.org to a
	 * browser, and elsewhere once the `\` is gone). So it's read as `/` here too. Any
	 * other `\`, and every other character esc_url() would drop, is percent-encoded,
	 * as a browser sends it. A `%` stays as it is, so an address already encoded isn't
	 * encoded again. Space around the address goes, as a browser drops it.
	 *
	 * @param string $url The address as written.
	 */
	private static function as_browser_reads( string $url ): string {
		$url = trim( $url );
		if ( 1 !== preg_match( '/^[a-z][a-z0-9+.-]*:/i', $url ) || 1 === preg_match( '/^https?:/i', $url ) ) {
			$end = strcspn( $url, '?#' );
			$url = str_replace( '\\', '/', substr( $url, 0, $end ) ) . substr( $url, $end );
		}
		return (string) preg_replace_callback(
			'/[^a-z0-9\-~+_.?#=!&;,\/:%@$|*\'()\[\]\x80-\xff]/i',
			static fn( array $character ): string => rawurlencode( $character[0] ),
			$url
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
	 * The site's `SynthesisNote`: the one-line disclosure under synthesized needs. The
	 * synthesis is the one place the site paraphrases researchers, so it says so, and
	 * says when a pass hasn't been reviewed. It says "all" only while the pass covers
	 * every published project; projects published since make it "4 of 8".
	 *
	 * @param string|null $model        The tool that wrote the synthesis, if one did.
	 * @param string|null $reviewed_by  Who reviewed it, if anyone has.
	 * @param string|null $updated_at   When the pass ran, as an ISO date.
	 * @param int         $source_count How many projects the pass read.
	 * @param int         $published    How many projects a visitor may see now.
	 * @param bool        $show_status  Whether to say whether it's reviewed.
	 */
	public static function synthesis_note( ?string $model, ?string $reviewed_by, ?string $updated_at, int $source_count, int $published, bool $show_status = true ): string {
		$projects = $source_count < $published
			/* translators: 1: how many projects the synthesis read, 2: how many the site shows. */
			? sprintf( __( '%1$d of %2$d projects', 'firehouse' ), $source_count, $published )
			/* translators: %d: how many projects the synthesis read, every one the site shows. */
			: sprintf( _n( 'all %d project', 'all %d projects', $source_count, 'firehouse' ), $source_count );
		$date     = null !== $updated_at && '' !== $updated_at
			? sprintf( '<time datetime="%1$s">%2$s</time>', esc_attr( $updated_at ), esc_html( Format::review_date( $updated_at ) ) )
			: '';
		$reviewed = null !== $reviewed_by && '' !== $reviewed_by;

		$status = '';
		if ( $show_status && $reviewed ) {
			$status = ' ' . ( '' !== $date
				/* translators: 1: who reviewed the synthesis, 2: the date. */
				? sprintf( esc_html__( 'Reviewed by %1$s on %2$s.', 'firehouse' ), esc_html( $reviewed_by ), $date )
				/* translators: %s: who reviewed the synthesis. */
				: sprintf( esc_html__( 'Reviewed by %s.', 'firehouse' ), esc_html( $reviewed_by ) ) );
		} elseif ( $show_status ) {
			$draft  = '<strong>' . esc_html__( 'Draft, pending team review', 'firehouse' ) . '</strong>';
			$status = ' ' . ( '' !== $date
				/* translators: 1: "Draft, pending team review", 2: the date the synthesis was updated. */
				? sprintf( esc_html__( '%1$s · updated %2$s.', 'firehouse' ), $draft, $date )
				: $draft . '.' );
		}

		return sprintf(
			'<p class="fh-synthesis-note%1$s"><span class="fh-synthesis-note__dot" aria-hidden="true"></span><span>%2$s%3$s</span></p>',
			$show_status && ! $reviewed ? ' fh-synthesis-note--draft' : '',
			sprintf(
				/* translators: 1: "AI-synthesized" or "Synthesized", 2: which projects, e.g. "all 8 projects". */
				esc_html__( '%1$s across %2$s.', 'firehouse' ),
				'<strong>' . ( null !== $model && '' !== $model ? esc_html__( 'AI-synthesized', 'firehouse' ) : esc_html__( 'Synthesized', 'firehouse' ) ) . '</strong>',
				esc_html( $projects )
			),
			$status
		);
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
