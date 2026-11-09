<?php
/**
 * Tests for the design system's small components, as markup.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Markup;

/**
 * Buttons and topic tags render as the site's `Button` and `TopicTag` do.
 */
final class MarkupTest extends WordPressTestCase {

	/**
	 * Stands in for WordPress's addresses, escaping and shared JSON.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_json_file_decode' )->alias(
			static fn( string $filename, array $options = array() ): mixed => json_decode( (string) file_get_contents( $filename ), ! empty( $options['associative'] ) )
		);
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://example.test' . $path );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component = -1 ): mixed => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- the stand-in for wp_parse_url.
	}

	/**
	 * A button on this site: its variant and size, its icons at the size's icon size,
	 * before and after the words, and no new tab.
	 */
	public function test_a_button_on_this_site(): void {
		$html = Markup::button( 'Go <now>', 'https://EXAMPLE.test/firehouse/', 'secondary', 'lg', array( 'plus', 'arrow-right' ) );

		$this->assertMatchesRegularExpression( '#^<a href="https://EXAMPLE.test/firehouse/" class="fh-btn fh-btn--secondary fh-btn--lg"><svg[^>]*width="18"[^>]*>.*</svg>Go &lt;now&gt;<svg[^>]*width="18"[^>]*>.*</svg></a>$#s', $html );
		$this->assertStringNotContainsString( 'target=', $html );
	}

	/**
	 * A button to another site opens in a new tab, says so to screen readers, and
	 * carries `noopener noreferrer` (rule 11).
	 */
	public function test_a_button_to_another_site(): void {
		$html = Markup::button( 'Survey', 'https://forms.example.org/x', 'accent', 'sm' );

		$this->assertSame( '<a href="https://forms.example.org/x" class="fh-btn fh-btn--accent fh-btn--sm" target="_blank" rel="noopener noreferrer">Survey<span class="fh-visually-hidden"> (opens in a new tab)</span></a>', $html );
	}

	/**
	 * A topic tag: the area's colours by its key, its icon at the tag's size with the
	 * thicker stroke, and its short label unless one is given.
	 */
	public function test_a_topic_tag(): void {
		$html = Markup::topic_tag( 'governance', 'sm' );

		$this->assertStringStartsWith( '<span class="fh-topic-tag fh-topic-tag--sm" style="--tag-text:var(--topic-governance-text);--tag-tint:var(--topic-governance-tint);--tag-border:var(--topic-governance-border)"><svg', $html );
		$this->assertMatchesRegularExpression( '#<svg[^>]*width="12"[^>]*stroke-width="2.25"#', $html );
		$this->assertStringEndsWith( '</svg>Governance</span>', $html );

		$this->assertStringEndsWith( '</svg>&lt;b&gt;Mine&lt;/b&gt;</span>', Markup::topic_tag( 'observe', 'md', '<b>Mine</b>' ) );
		$this->assertMatchesRegularExpression( '#fh-topic-tag--md.*<svg[^>]*width="14"#s', Markup::topic_tag( 'observe' ) );
	}

	/**
	 * Every topic key is a plain name, since it goes into an inline style, where HTML
	 * escaping is no protection.
	 */
	public function test_every_topic_key_is_safe_in_a_style(): void {
		$keys = array_column( \Firehouse\Domain\Vocabulary::topic_areas(), 'key' );
		$this->assertCount( 4, $keys );
		foreach ( $keys as $key ) {
			$this->assertMatchesRegularExpression( '/^[a-z][a-z0-9-]*$/', $key );
		}
	}

	/**
	 * A key outside the vocabulary gives the first area's tag, as the component does,
	 * and never reaches the style.
	 */
	public function test_an_unknown_topic_is_the_first_area(): void {
		$html = Markup::topic_tag( 'x);background:url(evil)' );

		$this->assertStringContainsString( '--tag-text:var(--topic-observe-text)', $html );
		$this->assertStringNotContainsString( 'evil', $html );
	}
}
