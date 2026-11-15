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
 * A FireHouse page made only of FireHouse blocks, each with only its declared
 * attributes, keeps its content as sent, past WordPress's filters, in WordPress's own
 * serialization; anything else is stored as WordPress would store it.
 */
final class PageWordsTest extends WordPressTestCase {

	/**
	 * The content as the editor sends it, before WordPress slashes it.
	 */
	private const CONTENT = 'the content as sent';

	/**
	 * The heading typed into it.
	 */
	private const HEADING = 'R&D <5 km> "quoted" \\ --> 😀';

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
	 * Stubs the tree, kses being on, the block parser and serializer.
	 */
	protected function setUp(): void {
		parent::setUp();
		$page        = static fn( int $id, int $parent_id ): \WP_Post => new \WP_Post(
			array(
				'ID'          => $id,
				'post_type'   => 'page',
				'post_parent' => $parent_id,
			)
		);
		$this->posts = array(
			10 => $page( 10, 0 ),
			11 => $page( 11, 10 ),
			20 => $page( 20, 0 ),
			30 => new \WP_Post(
				array(
					'ID'          => 30,
					'post_type'   => 'fh_project',
					'post_parent' => 10,
				)
			),
		);
		$this->parsed = array( self::CONTENT => array( self::block( 'firehouse/about-intro', array( 'heading' => self::HEADING ) ) ) );
		Functions\when( 'has_filter' )->alias( static fn( string $hook, mixed $callback = false ): mixed => ( 'content_save_pre' === $hook && 'wp_filter_post_kses' === $callback ) ? 10 : false );
		Functions\when( 'get_post' )->alias( fn( mixed $id ): ?\WP_Post => $this->posts[ (int) $id ] ?? null );
		Functions\when( 'get_posts' )->justReturn( array( 10 ) );
		Functions\when( 'get_post_ancestors' )->alias( static fn( \WP_Post $post ): array => 0 === $post->post_parent ? array() : array( $post->post_parent ) );
		Functions\when( 'wp_unslash' )->alias( static fn( string $text ): string => stripslashes( $text ) );
		Functions\when( 'wp_slash' )->alias( static fn( string $text ): string => addslashes( $text ) );
		Functions\when( 'wp_json_encode' )->alias( static fn( mixed $value ): string|false => json_encode( $value ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- standing in for wp_json_encode().
		Functions\when( 'parse_blocks' )->alias( fn( string $content ): array => $this->parsed[ $content ] ?? array( self::freeform( $content ) ) );
		self::stand_in_serialize_blocks();
	}

	/**
	 * Forgets the database stand-in.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * A parsed block.
	 *
	 * @param string               $name          Its name.
	 * @param array<string, mixed> $attrs         Its attributes.
	 * @param array<int, mixed>    $inner         Its inner blocks.
	 * @param array<int, mixed>    $inner_content Its inner content: strings, and null for each inner block.
	 * @return array<string, mixed>
	 */
	private static function block( string $name, array $attrs = array(), array $inner = array(), array $inner_content = array() ): array {
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
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
	 * Saves the content to a post, as wp_insert_post would filter it: by default the
	 * About page, staying under the landing page.
	 *
	 * @param int                  $id          The post.
	 * @param array<string, mixed> $data        Fields of $data beside the kses'd content.
	 * @param array<string, mixed> $unsanitized The post as sent.
	 */
	private static function save( int $id, array $data = array(), ?array $unsanitized = null ): mixed {
		return Page_Words::keep_as_typed(
			$data + array(
				'post_type'    => 'page',
				'post_parent'  => 11 === $id ? 10 : 0,
				'post_content' => 'kses’d',
			),
			array( 'ID' => $id ),
			$unsanitized ?? array( 'post_content' => addslashes( self::CONTENT ) )
		)['post_content'];
	}

	/**
	 * What's stored for the content as sent: WordPress's serialization of it, slashed.
	 */
	private function kept(): string {
		return addslashes( serialize_blocks( $this->parsed[ self::CONTENT ] ) );
	}

	/**
	 * A page in the tree, made only of FireHouse blocks, keeps its words as sent, in
	 * WordPress's own serialization, which writes what could end the block's comment
	 * as escapes; so does a revision or autosave of one.
	 */
	public function test_a_firehouse_page_keeps_its_words_as_sent(): void {
		$this->assertSame( $this->kept(), self::save( 11 ) );
		$this->assertStringContainsString( 'R\\\\u0026D \\\\u003c5 km\\\\u003e', self::save( 11 ), 'Escaped as the serializer does.' );
		$this->assertStringNotContainsString( '-->"', self::save( 11 ) );
		$this->assertSame( $this->kept(), self::save( 10 ), 'The landing page.' );
		$this->assertSame(
			$this->kept(),
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
	 * Anything outside the tree, or not a page, or new, or moving, or without its
	 * content, is stored as WordPress would.
	 */
	public function test_other_posts_are_left_to_kses(): void {
		$this->assertSame( 'kses’d', self::save( 20 ), 'A page outside the tree.' );
		$this->assertSame( 'kses’d', self::save( 30, array( 'post_type' => 'fh_project' ) ), 'Not a page.' );
		$this->assertSame( 'kses’d', self::save( 0 ), 'A new page.' );
		$this->assertSame( 'kses’d', self::save( 11, array( 'post_parent' => 0 ) ), 'A page moving out of the tree.' );
		$this->assertSame( 'kses’d', self::save( 11, array(), array() ), 'No content sent.' );
		$this->assertSame( 'kses’d', self::save( 11, array(), array( 'post_content' => array( 'x' ) ) ), 'Content that isn’t text.' );
		$this->assertSame( 'kses’d', self::save( 99, array( 'post_type' => 'revision' ) ), 'A revision of no post.' );
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
	 * On a posts table that can't hold an emoji, each one is stored as JSON escapes,
	 * which read back as the emoji, not as an entity the page would show as written.
	 */
	public function test_an_emoji_is_stored_as_json_escapes_on_an_older_table(): void {
		$GLOBALS['wpdb'] = new class() {
			/**
			 * The posts table's name.
			 *
			 * @var string
			 */
			public string $posts = 'wp_posts';

			/**
			 * The character set of a column: an older one for the content.
			 *
			 * @param string $table  The table.
			 * @param string $column The column.
			 */
			public function get_col_charset( string $table, string $column ): string {
				return 'post_content' === $column ? 'utf8mb3' : 'utf8mb4';
			}
		};

		$stored = stripslashes( self::save( 11 ) );

		$this->assertStringNotContainsString( '😀', $stored );
		$this->assertStringContainsString( '\\ud83d\\ude00', $stored );
		$this->assertSame( self::HEADING, json_decode( (string) preg_replace( '/^<!-- wp:firehouse\/about-intro (.*) \/-->$/', '$1', $stored ), true )['heading'] );
	}

	/**
	 * Content isn't only FireHouse blocks when it holds markup or text between or inside
	 * blocks, another block, an attribute the block doesn't declare, or no block at all.
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
			'markup inside a block'      => array( array( self::block( 'firehouse/hero', array(), array(), array( '<img src=x onerror=alert(1)>' ) ) ) ),
			'markup beside inner blocks' => array( array( self::block( 'firehouse/hero', array(), array( self::block( 'firehouse/stats' ) ), array( '<b>', null, '</b>' ) ) ) ),
			'a core block inside'        => array( array( self::block( 'firehouse/about-sections', array(), array( self::block( 'core/html' ) ), array( null ) ) ) ),
			'a core block'               => array( array( self::block( 'core/paragraph' ) ) ),
			'a made-up FireHouse block'  => array( array( self::block( 'firehouse/made-up' ) ) ),
			'a name like FireHouse’s'    => array( array( self::block( 'firehouse/hero/x' ) ) ),
			'custom CSS'                 => array( array( self::block( 'firehouse/about-cta', array( 'style' => array( 'css' => 'background:url(https://example.invalid/x.png)' ) ) ) ) ),
			'a class name'               => array( array( self::block( 'firehouse/about-intro', array( 'className' => 'x' ) ) ) ),
			'a lock'                     => array( array( self::block( 'firehouse/about-intro', array( 'lock' => array( 'remove' => true ) ) ) ) ),
			'metadata'                   => array( array( self::block( 'firehouse/about-intro', array( 'metadata' => array( 'name' => 'x' ) ) ) ) ),
			'another block’s attribute'  => array( array( self::block( 'firehouse/about-intro', array( 'members' => array() ) ) ) ),
			'a nested undeclared one'    => array( array( self::block( 'firehouse/hero', array(), array( self::block( 'firehouse/stats', array( 'style' => array() ) ) ), array( null ) ) ) ),
			'attributes that aren’t'     => array( array( array( 'attrs' => 'x' ) + self::block( 'firehouse/about-intro' ) ) ),
			'inner content that isn’t'   => array( array( self::block( 'firehouse/hero', array(), array(), array( 5 ) ) ) ),
			'freeform that isn’t text'   => array( array( array( 'innerHTML' => array() ) + self::freeform( '' ), $intro ) ),
			'no block at all'            => array( array( self::freeform( "\n" ) ) ),
			'nothing'                    => array( array() ),
			'not a block'                => array( array( 'firehouse/hero' ) ),
		);
	}

	/**
	 * FireHouse blocks holding FireHouse blocks, each with its declared attributes,
	 * with spaces and line breaks between, are only FireHouse blocks.
	 */
	public function test_nested_firehouse_blocks_with_spaces_are_kept(): void {
		$blocks = array(
			self::block( 'firehouse/hero', array( 'heading' => 'H' ), array( self::block( 'firehouse/stats', array( 'items' => array() ) ) ), array( "\n", null, "\n" ) ),
			self::freeform( "\n\n" ),
			self::block( 'firehouse/about-sections', array(), array( self::block( 'firehouse/about-mission' ), self::block( 'firehouse/about-team', array( 'members' => array() ) ) ), array( "\n\t", null, "\n", null, "\n" ) ),
		);
		$this->assertTrue( Page_Words::only_firehouse( $blocks ) );
	}
}
