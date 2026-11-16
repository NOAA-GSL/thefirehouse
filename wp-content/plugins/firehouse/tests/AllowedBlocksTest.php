<?php
/**
 * Tests for the FireHouse pages holding only FireHouse blocks.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Allowed_Blocks;
use Firehouse\Blocks;

/**
 * A page in the FireHouse tree holds only FireHouse blocks: the block editor offers no
 * others there, and a save that would add one is refused, through the REST API with a
 * reason and by any other route by the backstop.
 */
final class AllowedBlocksTest extends WordPressTestCase {

	/**
	 * Content of FireHouse blocks only.
	 */
	private const FIREHOUSE = 'firehouse blocks';

	/**
	 * A FireHouse block, then a core paragraph.
	 */
	private const PARAGRAPH = 'and a paragraph';

	/**
	 * A core block inside a FireHouse block.
	 */
	private const INSIDE = 'a paragraph inside';

	/**
	 * What the About page holds already: a FireHouse block and a core one, from before.
	 */
	private const STORED = 'stored "with" a paragraph';

	/**
	 * Posts, by ID: the FireHouse root (10), its About page (11), a page outside the
	 * tree (20), a revision of About (30) and a project (40).
	 *
	 * @var array<int, \WP_Post>
	 */
	private array $posts = array();

	/**
	 * Stubs the tree and the block parser.
	 */
	protected function setUp(): void {
		parent::setUp();
		$post        = static fn( int $id, string $type, int $parent_id, string $content = '' ): \WP_Post => new \WP_Post(
			array(
				'ID'           => $id,
				'post_type'    => $type,
				'post_parent'  => $parent_id,
				'post_content' => $content,
			)
		);
		$this->posts = array(
			10 => $post( 10, 'page', 0 ),
			11 => $post( 11, 'page', 10, self::STORED ),
			20 => $post( 20, 'page', 0 ),
			30 => $post( 30, 'revision', 11 ),
			40 => $post( 40, 'fh_project', 0 ),
		);
		$intro       = self::block( 'firehouse/about-intro', array( 'heading' => 'About' ) );
		$core        = self::block( 'core/paragraph', array(), array(), array( '<p>Added</p>' ) );
		$parsed      = array(
			self::FIREHOUSE => array( $intro, self::freeform( "\n\n" ), self::block( 'firehouse/about-cta' ) ),
			self::PARAGRAPH => array( $intro, $core ),
			self::INSIDE    => array( self::block( 'firehouse/about-sections', array(), array( $core ), array( null ) ) ),
			self::STORED    => array( $intro, $core ),
		);
		Functions\when( 'get_post' )->alias( fn( mixed $id ): ?\WP_Post => $this->posts[ (int) $id ] ?? null );
		Functions\when( 'get_posts' )->justReturn( array( 10 ) );
		Functions\when( 'get_post_ancestors' )->alias( static fn( \WP_Post $post ): array => 0 === $post->post_parent ? array() : array( $post->post_parent ) );
		Functions\when( 'parse_blocks' )->alias( static fn( string $content ): array => $parsed[ $content ] ?? array( self::freeform( $content ) ) );
		Functions\when( 'wp_unslash' )->alias( static fn( string $text ): string => stripslashes( $text ) );
		Functions\stubTranslationFunctions();
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
	 * The page as a REST save would give it.
	 *
	 * @param int         $id      The page's ID, or 0 for a new one.
	 * @param string|null $content Its content, or null when the request sends none.
	 */
	private static function prepared( int $id, ?string $content ): \stdClass {
		$prepared = new \stdClass();
		if ( 0 !== $id ) {
			$prepared->ID = $id;
		}
		if ( null !== $content ) {
			$prepared->post_content = $content;
		}
		return $prepared;
	}

	/**
	 * The block editor's context for a post.
	 *
	 * @param mixed $post The post.
	 */
	private static function editing( mixed $post ): object {
		return (object) array( 'post' => $post );
	}

	/**
	 * A FireHouse page's editor offers the FireHouse blocks and no others, whatever was
	 * allowed before; any other editor is left as it was.
	 */
	public function test_the_editor_offers_only_firehouse_blocks_on_a_firehouse_page(): void {
		$offered = Allowed_Blocks::offer( true, self::editing( $this->posts[11] ) );
		$this->assertSame( array_keys( Blocks::attributes() ), $offered );
		$this->assertContains( 'firehouse/about-team', $offered );
		$this->assertSame( array(), array_filter( $offered, static fn( string $name ): bool => ! str_starts_with( $name, 'firehouse/' ) ) );
		$this->assertSame( array_keys( Blocks::attributes() ), Allowed_Blocks::offer( array( 'core/paragraph' ), self::editing( $this->posts[10] ) ), 'Another plugin\'s list.' );

		$this->assertTrue( Allowed_Blocks::offer( true, self::editing( $this->posts[20] ) ), 'A page outside the tree.' );
		$this->assertSame( array( 'core/paragraph' ), Allowed_Blocks::offer( array( 'core/paragraph' ), self::editing( $this->posts[40] ) ), 'A project.' );
		$this->assertTrue( Allowed_Blocks::offer( true, self::editing( null ) ), 'The site editor or the widgets.' );
		$this->assertTrue( Allowed_Blocks::offer( true, null ) );
	}

	/**
	 * A REST save that would give a FireHouse page another block, beside or inside its
	 * own, or markup between them, is refused, with why and what to do.
	 */
	public function test_a_rest_save_adding_anything_else_is_refused(): void {
		foreach ( array( self::PARAGRAPH, self::INSIDE, '<p>Loose text</p>' ) as $content ) {
			$refused = Allowed_Blocks::refuse_rest( self::prepared( 11, $content ), new \WP_REST_Request( '/wp/v2/pages/11' ) );
			$this->assertInstanceOf( \WP_Error::class, $refused, $content );
			$this->assertSame( 'firehouse_only_firehouse_blocks', $refused->get_error_code() );
			$this->assertSame( array( 'status' => 400 ), $refused->data );
			$this->assertStringContainsString( 'a FireHouse page holds only its FireHouse blocks', $refused->get_error_message() );
			$this->assertStringContainsString( 'Take out what was added, then save again.', $refused->get_error_message() );
		}
		$this->assertInstanceOf( \WP_Error::class, Allowed_Blocks::refuse_rest( self::prepared( 10, self::PARAGRAPH ), new \WP_REST_Request( '/wp/v2/pages/10' ) ), 'The landing page.' );
	}

	/**
	 * A REST save of FireHouse blocks, or of nothing, goes through as it is.
	 */
	public function test_a_rest_save_of_firehouse_blocks_or_nothing_goes_through(): void {
		foreach ( array( self::FIREHOUSE, '', " \n\t" ) as $content ) {
			$prepared = self::prepared( 11, $content );
			$this->assertSame( $prepared, Allowed_Blocks::refuse_rest( $prepared, new \WP_REST_Request( '/wp/v2/pages/11' ) ), var_export( $content, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- names the case.
		}
	}

	/**
	 * Left alone: content the page holds already, so a page with another block from
	 * before can be published, unpublished or trashed and the block taken out; an
	 * autosave; a request without content; a new page; a page outside the tree; and an
	 * earlier refusal.
	 */
	public function test_what_a_rest_save_leaves_alone(): void {
		$request = new \WP_REST_Request( '/wp/v2/pages/11' );
		$cases   = array(
			'the content it holds' => array( self::prepared( 11, self::STORED ), $request ),
			'an autosave'          => array( self::prepared( 11, self::PARAGRAPH ), new \WP_REST_Request( '/wp/v2/pages/11/autosaves' ) ),
			'no content sent'      => array( self::prepared( 11, null ), $request ),
			'a new page'           => array( self::prepared( 0, self::PARAGRAPH ), new \WP_REST_Request( '/wp/v2/pages' ) ),
			'a page outside'       => array( self::prepared( 20, self::PARAGRAPH ), new \WP_REST_Request( '/wp/v2/pages/20' ) ),
			'a post that\'s gone'  => array( self::prepared( 99, self::PARAGRAPH ), new \WP_REST_Request( '/wp/v2/pages/99' ) ),
			'a project, by its ID' => array( self::prepared( 40, self::PARAGRAPH ), $request ),
		);
		foreach ( $cases as $case => list( $prepared, $sent ) ) {
			$this->assertSame( $prepared, Allowed_Blocks::refuse_rest( $prepared, $sent ), $case );
		}
		$error = new \WP_Error( 'earlier', 'An earlier refusal.' );
		$this->assertSame( $error, Allowed_Blocks::refuse_rest( $error, $request ) );
	}

	/**
	 * Any other route meets the backstop, with the content slashed as WordPress has it:
	 * another block refused, FireHouse blocks, nothing, and what the page holds already
	 * let through; a revision, another page, a new post and an earlier refusal left as
	 * they are.
	 */
	public function test_the_backstop_refuses_anything_else_by_any_route(): void {
		$saving = static fn( int $id, string $content ): array => array(
			'ID'           => $id,
			'post_content' => addslashes( $content ),
		);

		$this->assertTrue( Allowed_Blocks::refuse_write( false, $saving( 11, self::PARAGRAPH ) ) );
		$this->assertTrue( Allowed_Blocks::refuse_write( false, $saving( 10, '<p>Loose text</p>' ) ) );

		$this->assertFalse( Allowed_Blocks::refuse_write( false, $saving( 11, self::FIREHOUSE ) ) );
		$this->assertFalse( Allowed_Blocks::refuse_write( false, $saving( 11, '' ) ) );
		$this->assertFalse( Allowed_Blocks::refuse_write( false, $saving( 11, self::STORED ) ), 'What it holds, its quotes slashed.' );
		$this->assertFalse( Allowed_Blocks::refuse_write( false, $saving( 30, self::PARAGRAPH ) ), 'A revision.' );
		$this->assertFalse( Allowed_Blocks::refuse_write( false, $saving( 20, self::PARAGRAPH ) ), 'A page outside the tree.' );
		$this->assertFalse( Allowed_Blocks::refuse_write( false, array( 'post_content' => self::PARAGRAPH ) ), 'A new post.' );
		$this->assertFalse( Allowed_Blocks::refuse_write( false, array( 'ID' => 11 ) ), 'No content.' );
		$this->assertTrue( Allowed_Blocks::refuse_write( true, $saving( 20, self::FIREHOUSE ) ), 'An earlier refusal.' );
	}
}
