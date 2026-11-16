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
use Firehouse\Capabilities;

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
	 * The statuses get_post_stati() gives: WordPress's, and `pitch`, an editorial
	 * workflow plugin's.
	 */
	private const STATI = array( 'publish', 'future', 'draft', 'pending', 'private', 'trash', 'auto-draft', 'inherit', 'pitch' );

	/**
	 * Posts, by ID: the FireHouse root (10), its About page (11), a page outside the
	 * tree (20), a revision of About (30), a project (40) and a page outside the tree
	 * holding a core block (50).
	 *
	 * @var array<int, \WP_Post>
	 */
	private array $posts = array();

	/**
	 * Whether the current user may open the pages (`read_post`).
	 *
	 * @var bool
	 */
	private bool $can = true;

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
		// The root's ID; or the pages under a level of them, or these pages, in the
		// statuses asked for, as WordPress would give them. As in WordPress, an empty
		// post__in limits nothing.
		Functions\when( 'get_posts' )->alias(
			function ( array $args ): array {
				if ( ! isset( $args['post_parent__in'] ) && ! isset( $args['post__in'] ) ) {
					return array( 10 );
				}
				$pages = array_values(
					array_filter(
						$this->posts,
						static fn( \WP_Post $post ): bool => 'page' === $post->post_type
							&& in_array( $post->post_status, $args['post_status'], true )
							&& ( isset( $args['post__in'] ) ? ( array() === $args['post__in'] || in_array( $post->ID, $args['post__in'], true ) ) : in_array( $post->post_parent, $args['post_parent__in'], true ) )
					)
				);
				return 'ids' === ( $args['fields'] ?? '' ) ? array_column( $pages, 'ID' ) : $pages;
			}
		);
		// The statuses WordPress registers, and an editorial workflow's.
		Functions\when( 'get_post_stati' )->justReturn( array_combine( self::STATI, self::STATI ) );
		// Someone whose page editing isn't limited to the tree, who may open any page,
		// unless a test says not.
		Functions\when( 'wp_get_current_user' )->justReturn( new \WP_User( 1, array( 'administrator' ), array() ) );
		$this->can = true;
		Functions\when( 'current_user_can' )->alias(
			function ( string $cap, mixed $id = null ): bool {
				$this->assertSame( 'read_post', $cap );
				$this->assertArrayHasKey( $id, $this->posts, 'Asked of a page there is.' );
				return $this->can;
			}
		);
		Functions\when( 'wp_specialchars_decode' )->alias( static fn( string $text ): string => htmlspecialchars_decode( $text, ENT_QUOTES ) );
		Functions\when( 'wp_sprintf' )->alias( static fn( string $pattern, array $items ): string => implode( ', ', $items ) );
		Functions\when( 'get_post_ancestors' )->alias(
			function ( \WP_Post $post ): array {
				$ancestors = array();
				while ( $post->post_parent && isset( $this->posts[ $post->post_parent ] ) ) {
					$ancestors[] = $post->post_parent;
					$post        = $this->posts[ $post->post_parent ];
				}
				return $ancestors;
			}
		);
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
	 * A page moved into the tree brings the pages under it, unsaved, so the move is
	 * refused while any of them, at any depth, holds another block, and says so. Pages
	 * in the tree already aren't walked.
	 */
	public function test_a_page_moved_in_is_refused_while_a_page_under_it_holds_another_block(): void {
		$page = static fn( int $id, int $parent_id, string $content, string $status = 'publish' ): \WP_Post => new \WP_Post(
			array(
				'ID'           => $id,
				'post_type'    => 'page',
				'post_parent'  => $parent_id,
				'post_content' => $content,
				'post_status'  => $status,
				'post_title'   => 'Page ' . $id . ' R&amp;D',
			)
		);
		// Outside the tree: 60, holding FireHouse blocks; under it 61, the same; under that 62, a paragraph.
		$this->posts += array(
			60 => $page( 60, 0, self::FIREHOUSE ),
			61 => $page( 61, 60, self::FIREHOUSE ),
			62 => $page( 62, 61, self::PARAGRAPH ),
		);
		$moving       = static fn(): \stdClass|\WP_Error => Allowed_Blocks::refuse_rest( self::prepared( 60, null, 10 ), new \WP_REST_Request( 'POST', '/wp/v2/pages/60' ) );

		$refused = $moving();
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertStringContainsString( 'This page wasn’t moved', $refused->get_error_message() );
		$this->assertStringContainsString( 'One of them holds another block, or text between its blocks: Page 62 R&D.', $refused->get_error_message(), 'Naming the page, its title as typed.' );
		$this->assertStringContainsString( 'restore it first', $refused->get_error_message() );
		$this->assertTrue(
			Allowed_Blocks::refuse_write(
				false,
				array(
					'ID'          => 60,
					'post_type'   => 'page',
					'post_parent' => 11,
				)
			),
			'By any route, under About too.'
		);
		$this->assertFalse( Allowed_Blocks::refuse_write( false, array( 'ID' => 60 ) ), 'Left where it is.' );

		$this->posts[62] = $page( 62, 61, self::FIREHOUSE );
		$this->assertNotInstanceOf( \WP_Error::class, $moving(), 'Every page under it holds FireHouse blocks.' );

		// Through a page in the trash (61): a published page under it holding a paragraph
		// still opens, at the trashed page's address, and would be in the tree.
		$this->posts[61] = $page( 61, 60, self::FIREHOUSE, 'trash' );
		$this->posts[62] = $page( 62, 61, self::PARAGRAPH );
		$this->assertInstanceOf( \WP_Error::class, $moving(), 'Under a page in the trash.' );
		// A page in the trash holding a paragraph itself, which a restore brings back as it is.
		$this->posts[61] = $page( 61, 60, self::PARAGRAPH, 'trash' );
		$this->posts[62] = $page( 62, 61, self::FIREHOUSE );
		$this->assertStringContainsString( ': Page 61 R&D (in the Trash).', $moving()->get_error_message(), 'A page in the trash, marked so.' );
		// The same in an editorial workflow's status, and through one.
		$this->posts[61] = $page( 61, 60, self::PARAGRAPH, 'pitch' );
		$this->assertInstanceOf( \WP_Error::class, $moving(), 'A page in a status another plugin registers.' );
		$this->posts[61] = $page( 61, 60, self::FIREHOUSE, 'pitch' );
		$this->posts[62] = $page( 62, 61, self::PARAGRAPH );
		$this->assertInstanceOf( \WP_Error::class, $moving(), 'Under a page in such a status.' );
		// A page its user may not open is only counted.
		$this->can = false;
		$this->assertStringContainsString( 'One of them holds another block, or text between its blocks: 1 page you can’t open.', $moving()->get_error_message() );
		$this->can       = true;
		$this->posts[61] = $page( 61, 60, self::FIREHOUSE, 'trash' );
		$this->posts[62] = $page( 62, 61, self::FIREHOUSE );
		$this->assertNotInstanceOf( \WP_Error::class, $moving(), 'Every page under it, in the trash or not, holds FireHouse blocks.' );

		// A page with nothing under it reads no pages: an empty post__in would read every
		// page on the site, About's paragraph among them.
		$this->assertNotInstanceOf( \WP_Error::class, Allowed_Blocks::refuse_rest( self::prepared( 62, null, 10 ), new \WP_REST_Request( 'POST', '/wp/v2/pages/62' ) ) );

		// A page in the tree already: the pages under it are there too, so none is read.
		$this->posts[12] = $page( 12, 11, self::PARAGRAPH );
		$walked          = 0;
		Functions\when( 'get_posts' )->alias(
			static function ( array $args ) use ( &$walked ): array {
				$walked += isset( $args['post_parent__in'] ) ? 1 : 0;
				return array( 10 );
			}
		);
		$this->assertNotInstanceOf( \WP_Error::class, Allowed_Blocks::refuse_rest( self::prepared( 11, self::FIREHOUSE ), new \WP_REST_Request( 'POST', '/wp/v2/pages/11' ) ) );
		$this->assertSame( 0, $walked );
	}

	/**
	 * The move's message names five pages and counts the rest, and a title's angle
	 * brackets read as ‹ and ›: the block editor leaves out a save's message whole when
	 * anything in it looks like a tag.
	 */
	public function test_the_move_message_names_a_few_pages_and_nothing_like_a_tag(): void {
		$page            = static fn( int $id, int $parent_id, string $content ): \WP_Post => new \WP_Post(
			array(
				'ID'           => $id,
				'post_type'    => 'page',
				'post_parent'  => $parent_id,
				'post_content' => $content,
				'post_title'   => 'Page <' . $id . '>',
			)
		);
		$this->posts[60] = $page( 60, 0, self::FIREHOUSE );
		foreach ( range( 61, 67 ) as $id ) {
			$this->posts[ $id ] = $page( $id, 60, self::PARAGRAPH );
		}
		$refused = Allowed_Blocks::refuse_rest( self::prepared( 60, null, 10 ), new \WP_REST_Request( 'POST', '/wp/v2/pages/60' ) );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertStringContainsString( 'Some of them hold another block, or text between their blocks: Page ‹61›, Page ‹62›, Page ‹63›, Page ‹64›, Page ‹65›, 2 other pages.', $refused->get_error_message() );
		$this->assertDoesNotMatchRegularExpression( '/<\/?[^>]*>/', $refused->get_error_message(), 'The block editor\'s test for markup.' );
	}

	/**
	 * A limited editor's page keeps its parent whatever a save asks
	 * (Capabilities::keep_in_place(), which runs after these checks), so a save is
	 * judged where the page will stay: asking to move About out of the tree doesn't let
	 * a paragraph in.
	 */
	public function test_a_limited_editors_page_is_checked_where_it_stays(): void {
		Functions\when( 'wp_get_current_user' )->justReturn( new \WP_User( 7, array( Capabilities::EDITOR_ROLE ), array( Capabilities::EDIT_PAGES => true ) ) );
		$postarr = array(
			'ID'           => 11,
			'post_type'    => 'page',
			'post_parent'  => 0,
			'post_content' => addslashes( self::PARAGRAPH ),
		);

		$this->assertTrue( Allowed_Blocks::refuse_write( false, $postarr ), 'Refused by the backstop.' );
		$this->assertSame( 10, Capabilities::keep_in_place( $postarr, $postarr )['post_parent'], 'Where the save would have left it.' );
		$this->assertInstanceOf( \WP_Error::class, Allowed_Blocks::refuse_rest( self::prepared( 11, self::PARAGRAPH, 0 ), new \WP_REST_Request( 'POST', '/wp/v2/pages/11' ) ), 'Refused through the REST API.' );

		Functions\when( 'wp_get_current_user' )->justReturn( new \WP_User( 1, array( 'administrator' ), array() ) );
		$this->assertFalse( Allowed_Blocks::refuse_write( false, $postarr ), 'Someone who may move it out of the tree.' );
		$this->assertSame( 0, Capabilities::keep_in_place( $postarr, $postarr )['post_parent'] );
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
