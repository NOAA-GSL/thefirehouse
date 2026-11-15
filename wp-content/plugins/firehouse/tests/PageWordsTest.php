<?php
/**
 * Tests for keeping the FireHouse pages' words as typed.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Page_Words;

/**
 * A FireHouse page made only of FireHouse blocks keeps its content as sent, past kses;
 * anything else is stored as WordPress would store it.
 */
final class PageWordsTest extends WordPressTestCase {

	/**
	 * The content as the editor sends it, before WordPress slashes it.
	 */
	private const CONTENT = '<!-- wp:firehouse/about-intro {"heading":"R\\u0026D \\u003c5 km\\u003e"} /-->';

	/**
	 * Posts, by ID: the FireHouse root (10), its About page (11), a page outside the
	 * tree (20) and a project (30).
	 *
	 * @var array<int, \WP_Post>
	 */
	private array $posts = array();

	/**
	 * Parsed content, by the content: what parse_blocks() gives.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	private array $parsed = array();

	/**
	 * Stubs the tree, kses being on, and the block parser.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->posts  = array(
			10 => new \WP_Post( array( 'ID' => 10, 'post_type' => 'page', 'post_name' => 'firehouse' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- a short fixture.
			11 => new \WP_Post( array( 'ID' => 11, 'post_type' => 'page', 'post_parent' => 10, 'post_name' => 'about' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- a short fixture.
			20 => new \WP_Post( array( 'ID' => 20, 'post_type' => 'page', 'post_name' => 'elsewhere' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- a short fixture.
			30 => new \WP_Post( array( 'ID' => 30, 'post_type' => 'fh_project', 'post_parent' => 10 ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- a short fixture.
		);
		$this->parsed = array( self::CONTENT => array( self::block( 'firehouse/about-intro' ) ) );
		Functions\when( 'has_filter' )->alias( static fn( string $hook, mixed $callback = false ): mixed => ( 'content_save_pre' === $hook && 'wp_filter_post_kses' === $callback ) ? 10 : false );
		Functions\when( 'get_post' )->alias( fn( mixed $id ): ?\WP_Post => $this->posts[ (int) $id ] ?? null );
		Functions\when( 'get_posts' )->justReturn( array( 10 ) );
		Functions\when( 'get_post_ancestors' )->alias( static fn( \WP_Post $post ): array => 0 === $post->post_parent ? array() : array( $post->post_parent ) );
		Functions\when( 'wp_unslash' )->alias( static fn( string $text ): string => stripslashes( $text ) );
		Functions\when( 'parse_blocks' )->alias( fn( string $content ): array => $this->parsed[ $content ] ?? array( self::freeform( $content ) ) );
	}

	/**
	 * A parsed block.
	 *
	 * @param string            $name         Its name.
	 * @param array<int, mixed> $inner        Its inner blocks.
	 * @param array<int, mixed> $inner_content Its inner content: strings, and null for each inner block.
	 * @return array<string, mixed>
	 */
	private static function block( string $name, array $inner = array(), array $inner_content = array() ): array {
		return array(
			'blockName'    => $name,
			'attrs'        => array(),
			'innerBlocks'  => $inner,
			'innerHTML'    => implode( '', array_filter( $inner_content, 'is_string' ) ),
			'innerContent' => $inner_content,
		);
	}

	/**
	 * What's between blocks, as parse_blocks() gives it.
	 *
	 * @param string $html The markup.
	 * @return array<string, mixed>
	 */
	private static function freeform( string $html ): array {
		return array(
			'blockName'    => null,
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
		);
	}

	/**
	 * Saves content to a post, as wp_insert_post would filter it.
	 *
	 * @param int                  $id   The post.
	 * @param array<string, mixed> $data Fields of $data beside the kses'd content.
	 */
	private static function save( int $id, array $data = array() ): mixed {
		return Page_Words::keep_as_typed(
			$data + array(
				'post_type'    => 'page',
				'post_content' => 'kses’d',
			),
			array( 'ID' => $id ),
			array( 'post_content' => addslashes( self::CONTENT ) )
		)['post_content'];
	}

	/**
	 * A page in the tree, made only of FireHouse blocks, keeps its content as sent;
	 * so does a revision or autosave of one.
	 */
	public function test_a_firehouse_page_keeps_its_words_as_sent(): void {
		$this->assertSame( addslashes( self::CONTENT ), self::save( 11 ) );
		$this->assertSame( addslashes( self::CONTENT ), self::save( 10 ), 'The landing page.' );
		$this->assertSame(
			addslashes( self::CONTENT ),
			self::save(
				99,
				array(
					'post_type'   => 'revision',
					'post_parent' => 11,
				)
			),
			'A revision of the About page.'
		);
	}

	/**
	 * Anything outside the tree, or not a page, or new, is stored as WordPress would.
	 */
	public function test_other_posts_are_left_to_kses(): void {
		$this->assertSame( 'kses’d', self::save( 20 ), 'A page outside the tree.' );
		$this->assertSame( 'kses’d', self::save( 30, array( 'post_type' => 'fh_project' ) ), 'Not a page.' );
		$this->assertSame( 'kses’d', self::save( 0 ), 'A new page.' );
		$this->assertSame(
			'kses’d',
			self::save(
				99,
				array(
					'post_type'   => 'revision',
					'post_parent' => 20,
				)
			),
			'A revision of a page outside the tree.'
		);
	}

	/**
	 * Without kses running, the content is left as WordPress has it, which is as sent.
	 */
	public function test_without_kses_nothing_changes(): void {
		Functions\when( 'has_filter' )->justReturn( false );
		$this->assertSame( 'kses’d', self::save( 11 ) );
	}

	/**
	 * Content isn't only FireHouse blocks when it holds markup or text between or inside
	 * blocks, another block, or no block at all.
	 *
	 * @dataProvider not_only_firehouse
	 *
	 * @param array<int, mixed> $blocks The parsed content.
	 */
	public function test_other_content_is_left_to_kses( array $blocks ): void {
		$this->parsed[ self::CONTENT ] = $blocks;
		$this->assertSame( 'kses’d', self::save( 11 ) );
		$this->assertFalse( Page_Words::only_firehouse( $blocks ) );
	}

	/**
	 * Parsed content that isn't only FireHouse blocks.
	 *
	 * @return array<string, array{0: array<int, mixed>}>
	 */
	public static function not_only_firehouse(): array {
		$intro = self::block( 'firehouse/about-intro' );
		return array(
			'markup between blocks'      => array( array( $intro, self::freeform( "\n<script>alert(1)</script>\n" ) ) ),
			'text between blocks'        => array( array( self::freeform( 'Words' ), $intro ) ),
			'markup inside a block'      => array( array( self::block( 'firehouse/hero', array(), array( '<img src=x onerror=alert(1)>' ) ) ) ),
			'markup beside inner blocks' => array( array( self::block( 'firehouse/hero', array( self::block( 'firehouse/stats' ) ), array( '<b>', null, '</b>' ) ) ) ),
			'a core block inside'        => array( array( self::block( 'firehouse/about-sections', array( self::block( 'core/html' ) ), array( null ) ) ) ),
			'a core block'               => array( array( self::block( 'core/paragraph' ) ) ),
			'a made-up FireHouse block'  => array( array( self::block( 'firehouse/made-up' ) ) ),
			'a name like FireHouse’s'    => array( array( self::block( 'firehouse/hero/x' ) ) ),
			'no block at all'            => array( array( self::freeform( "\n" ) ) ),
			'nothing'                    => array( array() ),
			'not a block'                => array( array( 'firehouse/hero' ) ),
		);
	}

	/**
	 * FireHouse blocks holding FireHouse blocks, with spaces and line breaks between,
	 * are only FireHouse blocks.
	 */
	public function test_nested_firehouse_blocks_with_spaces_are_kept(): void {
		$blocks = array(
			self::block( 'firehouse/hero', array( self::block( 'firehouse/stats' ) ), array( "\n", null, "\n" ) ),
			self::freeform( "\n\n" ),
			self::block( 'firehouse/about-sections', array( self::block( 'firehouse/about-mission' ), self::block( 'firehouse/about-team' ) ), array( "\n\t", null, "\n", null, "\n" ) ),
		);
		$this->assertTrue( Page_Words::only_firehouse( $blocks ) );
	}
}
