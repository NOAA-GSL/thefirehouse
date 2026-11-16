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
	 * What a page outside the tree holds: a FireHouse block and a core one.
	 */
	private const OUTSIDE = 'outside, with a paragraph';

	/**
	 * Posts, by ID: the FireHouse root (10), its About page (11), a page outside the
	 * tree (20), a revision of About (30), a project (40) and a page outside the tree
	 * holding a core block (50).
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
			50 => $post( 50, 'page', 0, self::OUTSIDE ),
		);
		$intro       = self::block( 'firehouse/about-intro', array( 'heading' => 'About' ) );
		$core        = self::block( 'core/paragraph', array(), array(), array( '<p>Added</p>' ) );
		$parsed      = array(
			self::FIREHOUSE => array( $intro, self::freeform( "\n\n" ), self::block( 'firehouse/about-cta' ) ),
			self::PARAGRAPH => array( $intro, $core ),
			self::INSIDE    => array( self::block( 'firehouse/about-sections', array(), array( $core ), array( null ) ) ),
			self::STORED    => array( $intro, $core ),
			self::OUTSIDE   => array( $intro, $core ),
		);
		Functions\when( 'get_post' )->alias( fn( mixed $id ): ?\WP_Post => $this->posts[ (int) $id ] ?? null );
		Functions\when( 'get_posts' )->justReturn( array( 10 ) );
		Functions\when( 'get_post_ancestors' )->alias( static fn( \WP_Post $post ): array => 0 === $post->post_parent ? array() : array( $post->post_parent ) );
		Functions\when( 'parse_blocks' )->alias( static fn( string $content ): array => $parsed[ $content ] ?? array( self::freeform( $content ) ) );
		Functions\when( 'wp_unslash' )->alias( static fn( string $text ): string => stripslashes( $text ) );
		Functions\when( 'wp_slash' )->alias( static fn( string $text ): string => addslashes( $text ) );
		// WordPress's filters on saved content leave it as it is, unless a test says not.
		Functions\when( 'sanitize_post_field' )->alias( static fn( string $field, string $value ): string => $value );
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
	 * @param int|null    $parent_id Its parent, or null when the request sends none.
	 */
	private static function prepared( int $id, ?string $content, ?int $parent_id = null ): \stdClass {
		$prepared = new \stdClass();
		if ( 0 !== $id ) {
			$prepared->ID = $id;
		}
		if ( null !== $content ) {
			$prepared->post_content = $content;
		}
		if ( null !== $parent_id ) {
			$prepared->post_parent = $parent_id;
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
			$refused = Allowed_Blocks::refuse_rest( self::prepared( 11, $content ), new \WP_REST_Request( 'POST', '/wp/v2/pages/11' ) );
			$this->assertInstanceOf( \WP_Error::class, $refused, $content );
			$this->assertSame( 'firehouse_only_firehouse_blocks', $refused->get_error_code() );
			$this->assertSame( array( 'status' => 400 ), $refused->data );
			$this->assertStringContainsString( 'a FireHouse page holds only its FireHouse blocks', $refused->get_error_message() );
			$this->assertStringContainsString( 'Take out any other block or text, then save again.', $refused->get_error_message() );
		}
		$this->assertInstanceOf( \WP_Error::class, Allowed_Blocks::refuse_rest( self::prepared( 10, self::PARAGRAPH ), new \WP_REST_Request( 'POST', '/wp/v2/pages/10' ) ), 'The landing page.' );
	}

	/**
	 * A REST save of FireHouse blocks, or of nothing, goes through as it is.
	 */
	public function test_a_rest_save_of_firehouse_blocks_or_nothing_goes_through(): void {
		foreach ( array( self::FIREHOUSE, '', " \n\t" ) as $content ) {
			$prepared = self::prepared( 11, $content );
			$this->assertSame( $prepared, Allowed_Blocks::refuse_rest( $prepared, new \WP_REST_Request( 'POST', '/wp/v2/pages/11' ) ), var_export( $content, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- names the case.
		}
	}

	/**
	 * Left alone: content the page holds already, so a page with another block from
	 * before can be published, unpublished or trashed and the block taken out; an
	 * autosave; a request without content; a new page; a page outside the tree; and an
	 * earlier refusal.
	 */
	public function test_what_a_rest_save_leaves_alone(): void {
		$request = new \WP_REST_Request( 'POST', '/wp/v2/pages/11' );
		$cases   = array(
			'the content it holds' => array( self::prepared( 11, self::STORED ), $request ),
			'an autosave'          => array( self::prepared( 11, self::PARAGRAPH ), new \WP_REST_Request( 'POST', '/wp/v2/pages/11/autosaves' ) ),
			'no content sent'      => array( self::prepared( 11, null ), $request ),
			'a new page'           => array( self::prepared( 0, self::PARAGRAPH ), new \WP_REST_Request( 'POST', '/wp/v2/pages' ) ),
			'a page outside'       => array( self::prepared( 20, self::PARAGRAPH ), new \WP_REST_Request( 'POST', '/wp/v2/pages/20' ) ),
			'a post that\'s gone'  => array( self::prepared( 99, self::PARAGRAPH ), new \WP_REST_Request( 'POST', '/wp/v2/pages/99' ) ),
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

	/**
	 * A page created under the tree, or moved into it, is checked like one there: its
	 * content as sent, or the content it holds when the request sends none. A page
	 * moved out of the tree, or created elsewhere, isn't.
	 */
	public function test_a_page_created_under_the_tree_or_moved_into_it_is_checked(): void {
		$rest = static fn( \stdClass $prepared ): bool => Allowed_Blocks::refuse_rest( $prepared, new \WP_REST_Request( 'POST', '/wp/v2/pages' ) ) instanceof \WP_Error;

		$this->assertTrue( $rest( self::prepared( 0, self::PARAGRAPH, 10 ) ), 'A new page under the landing page.' );
		$this->assertTrue( $rest( self::prepared( 0, self::PARAGRAPH, 11 ) ), 'A new page under About.' );
		$this->assertTrue( $rest( self::prepared( 50, null, 10 ) ), 'A page holding a paragraph, moved in without its content sent.' );
		$this->assertTrue( $rest( self::prepared( 20, self::PARAGRAPH, 11 ) ), 'A page moved in, with a paragraph sent.' );

		$this->assertFalse( $rest( self::prepared( 0, self::FIREHOUSE, 10 ) ), 'A new page of FireHouse blocks, as the Import screen makes About.' );
		$this->assertFalse( $rest( self::prepared( 0, self::PARAGRAPH, 20 ) ), 'A new page elsewhere.' );
		$this->assertFalse( $rest( self::prepared( 11, self::PARAGRAPH, 0 ) ), 'About, moved out of the tree.' );
		$this->assertFalse( $rest( self::prepared( 50, null ) ), 'The page outside, left there.' );

		$saving = static fn( array $post ): bool => Allowed_Blocks::refuse_write( false, $post );
		$this->assertTrue(
			$saving(
				array(
					'post_type'    => 'page',
					'post_parent'  => 10,
					'post_content' => addslashes( self::PARAGRAPH ),
				)
			),
			'A new page under the landing page, by any route.'
		);
		$this->assertTrue(
			$saving(
				array(
					'ID'           => 50,
					'post_type'    => 'page',
					'post_parent'  => 10,
					'post_content' => addslashes( self::OUTSIDE ),
				)
			),
			'Moved in with what it holds, as Quick Edit\'s Parent does.'
		);
		$this->assertTrue(
			$saving(
				array(
					'ID'           => 40,
					'post_type'    => 'page',
					'post_parent'  => 11,
					'post_content' => addslashes( self::PARAGRAPH ),
				)
			),
			'Another post made a page under About.'
		);
		$this->assertFalse(
			$saving(
				array(
					'post_type'    => 'post',
					'post_parent'  => 10,
					'post_content' => addslashes( self::PARAGRAPH ),
				)
			),
			'A post, which no page tree holds.'
		);
	}

	/**
	 * A save that leaves a page in the tree with what it holds goes through even when
	 * WordPress's filters (kses) have changed it on the way, as they do for someone who
	 * may not post unfiltered HTML. A page moved into the tree has no such pass.
	 */
	public function test_the_content_as_stored_is_recognized_after_wordpresss_filters(): void {
		Functions\when( 'sanitize_post_field' )->alias( static fn( string $field, string $value ): string => 'post_content' === $field ? 'filtered ' . $value : $value );
		$filtered = 'filtered ' . addslashes( self::STORED );

		$this->assertFalse( Allowed_Blocks::refuse_write( false, array( 'ID' => 11, 'post_content' => $filtered ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertTrue( Allowed_Blocks::refuse_write( false, array( 'ID' => 11, 'post_content' => 'filtered ' . addslashes( self::PARAGRAPH ) ) ), 'Something else.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * Content sent as a number or boolean is checked as the text WordPress would store,
	 * and anything else as no content sent.
	 */
	public function test_content_that_isnt_a_string_is_checked_as_text(): void {
		$this->assertTrue( Allowed_Blocks::refuse_write( false, array( 'ID' => 11, 'post_content' => 777 ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$prepared               = self::prepared( 11, null );
		$prepared->post_content = 12345;
		$this->assertInstanceOf( \WP_Error::class, Allowed_Blocks::refuse_rest( $prepared, new \WP_REST_Request( 'POST', '/wp/v2/pages/11' ) ) );
		$this->assertFalse( Allowed_Blocks::refuse_write( false, array( 'ID' => 11, 'post_content' => array( 'not text' ) ) ), 'No content: what it holds.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * The revisions screen offers no Restore for a revision of a FireHouse page holding
	 * another block, which would be refused while WordPress said it was restored.
	 */
	public function test_a_revision_that_would_be_refused_cant_be_restored(): void {
		$revision = static fn( string $content ): \WP_Post => new \WP_Post(
			array(
				'ID'           => 31,
				'post_type'    => 'revision',
				'post_parent'  => 11,
				'post_content' => $content,
			)
		);
		$data     = array(
			'id'         => 31,
			'restoreUrl' => 'https://example.test/wp-admin/revision.php?revision=31&action=restore',
		);

		$this->assertFalse( Allowed_Blocks::keep_from_restoring( $data, $revision( self::PARAGRAPH ), $this->posts[11] )['restoreUrl'] );
		$this->assertSame( $data, Allowed_Blocks::keep_from_restoring( $data, $revision( self::FIREHOUSE ), $this->posts[11] ) );
		$this->assertSame( $data, Allowed_Blocks::keep_from_restoring( $data, $revision( self::STORED ), $this->posts[11] ), 'What the page holds.' );
		$this->assertSame( $data, Allowed_Blocks::keep_from_restoring( $data, $revision( self::PARAGRAPH ), $this->posts[50] ), 'A page outside the tree.' );
		$this->assertSame( array( 'restoreUrl' => false ), Allowed_Blocks::keep_from_restoring( array( 'restoreUrl' => false ), $revision( self::FIREHOUSE ), $this->posts[11] ), 'Not restorable already.' );
	}
}
