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
	 * The synthesis note says what wrote the synthesis, how many projects it read out
	 * of those published, and whether it's been reviewed, as the site's does.
	 */
	public function test_a_synthesis_note(): void {
		$dot  = '<span class="fh-synthesis-note__dot" aria-hidden="true"></span>';
		$date = '<time datetime="2026-10-27">October 27, 2026</time>';

		$this->assertSame(
			'<p class="fh-synthesis-note">' . $dot . '<span><strong>AI-synthesized</strong> across all 8 projects. Reviewed by Pat Reviewer on ' . $date . '.</span></p>',
			Markup::synthesis_note( 'NotebookLM', 'Pat Reviewer', '2026-10-27', 8, 8 )
		);
		$this->assertSame(
			'<p class="fh-synthesis-note fh-synthesis-note--draft">' . $dot . '<span><strong>AI-synthesized</strong> across 4 of 8 projects. <strong>Draft, pending team review</strong> · updated ' . $date . '.</span></p>',
			Markup::synthesis_note( 'NotebookLM', null, '2026-10-27', 4, 8 )
		);
		$this->assertSame(
			'<p class="fh-synthesis-note fh-synthesis-note--draft">' . $dot . '<span><strong>Synthesized</strong> across all 1 project. <strong>Draft, pending team review</strong>.</span></p>',
			Markup::synthesis_note( '', '', '', 1, 1 )
		);
		$this->assertSame(
			'<p class="fh-synthesis-note">' . $dot . '<span><strong>Synthesized</strong> across all 9 projects. Reviewed by Sam &lt;Editor&gt;.</span></p>',
			Markup::synthesis_note( null, 'Sam <Editor>', null, 9, 8 )
		);
		$this->assertSame(
			'<p class="fh-synthesis-note">' . $dot . '<span><strong>AI-synthesized</strong> across all 8 projects.</span></p>',
			Markup::synthesis_note( 'NotebookLM', null, '2026-10-27', 8, 8, false )
		);
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
	 * Stored text shows as stored: every `&` is escaped, a character reference's
	 * included, as are angle brackets and quotes; a broken UTF-8 sequence shows as
	 * U+FFFD rather than emptying the text.
	 */
	public function test_text_shows_as_stored(): void {
		$this->assertSame( 'R&amp;amp;D &lt;b&gt; &quot;q&quot; &#039;a&#039;', Markup::text( 'R&amp;D <b> "q" ' . "'a'" ) );
		$this->assertSame( "a\u{FFFD}b", Markup::text( "a\xC3b" ) );
	}

	/**
	 * An address leads where a browser would take it. In an http or https address a
	 * `\` before the query is a `/`, as a browser reads it, and one after it is
	 * percent-encoded, as is one in any other kind of address; so is every character
	 * esc_url() would drop. A `%` stays as it is, so an address already encoded isn't
	 * encoded again. Space around it goes, as a browser drops it.
	 */
	public function test_an_address_reads_as_a_browser_reads_it(): void {
		$this->assertSame( 'https://doi.org/@evil.example/x', Markup::url( 'https://doi.org\\@evil.example/x' ) );
		$this->assertSame( 'HTTPS://a.example/b/c?d%5Ce#f%5Cg', Markup::url( ' HTTPS:\\\\a.example\\b/c?d\\e#f\\g ' ) );
		$this->assertSame( 'https://example.org/a%20b%22%3Cc%3E%d', Markup::url( 'https://example.org/a b"<c>%d' ) );
		$this->assertSame( 'mailto:a%5Cb@example.org', Markup::url( 'mailto:a\\b@example.org' ) );
	}

	/**
	 * A button's address reads as a browser reads it too.
	 */
	public function test_a_buttons_address_reads_as_a_browser_reads_it(): void {
		$this->assertStringStartsWith( '<a href="https://records.example.org/a/b%20c" ', Markup::button( 'Record', 'https://records.example.org\\a/b c' ) );
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
