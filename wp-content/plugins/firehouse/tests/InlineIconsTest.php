<?php
/**
 * Tests for inlining the site's icons.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Icons;

/**
 * An icon comes from the vocabulary only, at its size, decorative, with what the
 * caller adds escaped.
 */
final class InlineIconsTest extends WordPressTestCase {

	/**
	 * Reads shared JSON and escapes attributes as WordPress would.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_json_file_decode' )->alias(
			static fn( string $filename, array $options = array() ): mixed => json_decode( (string) file_get_contents( $filename ), ! empty( $options['associative'] ) )
		);
	}

	/**
	 * The icon is the file's drawing at the size asked for, hidden from assistive
	 * technology and kept out of the tab order.
	 */
	public function test_an_icon_is_its_file_at_its_size(): void {
		$svg = Icons::svg( 'plus', 14 );

		$this->assertStringStartsWith( '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" aria-hidden="true" focusable="false" style="display:inline-flex;flex-shrink:0" viewBox="0 0 24 24"', $svg );
		$this->assertStringEndsWith( '<path d="M5 12h14"></path><path d="M12 5v14"></path></svg>', $svg );
		$this->assertSame( 1, substr_count( $svg, ' width="' ) );
		$this->assertSame( 1, substr_count( $svg, ' height="' ) );
	}

	/**
	 * A name outside the vocabulary reads nothing, a path included.
	 */
	public function test_a_name_outside_the_vocabulary_gives_nothing(): void {
		foreach ( array( 'nope', '../icons/plus', 'plus.svg', 'LICENSE', '' ) as $name ) {
			$this->assertSame( '', Icons::svg( $name, 16 ), $name );
		}
	}

	/**
	 * What the caller adds is escaped; a bare attribute is written alone; a name that
	 * isn't a plain attribute name, or is an event handler's, is left out.
	 */
	public function test_added_attributes_are_escaped_and_checked(): void {
		$svg = Icons::svg(
			'x',
			18,
			array(
				'class'     => 'a"><script>b',
				'hidden'    => true,
				'onload'    => 'alert(1)',
				'on'        => 'x',
				'data x'    => 'y',
				'Upper'     => 'y',
				'1st'       => 'y',
				'data-wp-a' => '!context.open',
			)
		);

		$this->assertStringContainsString( ' class="a&quot;&gt;&lt;script&gt;b"', $svg );
		$this->assertStringContainsString( ' hidden ', $svg );
		$this->assertStringContainsString( ' data-wp-a="!context.open"', $svg );
		foreach ( array( 'onload', ' on=', 'data x', 'Upper', '1st', '<script>' ) as $absent ) {
			$this->assertStringNotContainsString( $absent, $svg );
		}
	}
}
