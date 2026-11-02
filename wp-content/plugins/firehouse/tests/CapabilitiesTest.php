<?php
/**
 * Tests for the Firehouse capabilities, the editor role and the page-tree limit.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Capabilities;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A Firehouse Editor edits Firehouse content and the Firehouse pages, and nothing else.
 */
final class CapabilitiesTest extends WordPressTestCase {

	/**
	 * The posts the tests use: the root page (10), About under it (11), a draft under
	 * About with no slug yet (12), a page outside the tree (20), a revision of About
	 * (30) and of the outside page (31), a topic (40) and a project (50).
	 *
	 * @var array<int, \WP_Post>
	 */
	private array $posts = array();

	/**
	 * Sets up the site.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->posts = array(
			10 => new \WP_Post( array( 'ID' => 10, 'post_type' => 'page', 'post_name' => 'firehouse' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- One line per post keeps the site readable.
			11 => new \WP_Post( array( 'ID' => 11, 'post_type' => 'page', 'post_parent' => 10, 'post_name' => 'about' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			12 => new \WP_Post( array( 'ID' => 12, 'post_type' => 'page', 'post_parent' => 11 ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			20 => new \WP_Post( array( 'ID' => 20, 'post_type' => 'page', 'post_name' => 'research' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			30 => new \WP_Post( array( 'ID' => 30, 'post_type' => 'revision', 'post_parent' => 11 ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			31 => new \WP_Post( array( 'ID' => 31, 'post_type' => 'revision', 'post_parent' => 20 ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			40 => new \WP_Post( array( 'ID' => 40, 'post_type' => 'fh_topic', 'post_name' => 'observe' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			50 => new \WP_Post( array( 'ID' => 50, 'post_type' => 'fh_project', 'post_name' => 'a-project' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		);
		$posts       = $this->posts;
		Functions\when( 'get_post' )->alias( static fn( mixed $id ): ?\WP_Post => $posts[ (int) $id ] ?? null );
		Functions\when( 'get_page_by_path' )->justReturn( $posts[10] );
		Functions\when( 'get_post_ancestors' )->alias(
			static function ( \WP_Post $post ) use ( $posts ): array {
				$ancestors = array();
				while ( $post->post_parent ) {
					$ancestors[] = $post->post_parent;
					$post        = $posts[ $post->post_parent ];
				}
				return $ancestors;
			}
		);
		$this->as_user( array( Capabilities::EDITOR_ROLE ) );
	}

	/**
	 * Makes a user with these roles the current one.
	 *
	 * @param string[] $roles The user's roles.
	 */
	private function as_user( array $roles ): void {
		$user = new \WP_User( 7, $roles );
		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\when( 'wp_get_current_user' )->justReturn( $user );
		Functions\when( 'get_role' )->alias(
			static fn( string $name ): object => new class( 'editor' === $name || 'administrator' === $name ) {
				/**
				 * Sets whether the role edits others' pages.
				 *
				 * @param bool $edits_others_pages Whether it does.
				 */
				public function __construct( private bool $edits_others_pages ) {}

				/**
				 * Whether the role has a capability.
				 *
				 * @param string $cap The capability.
				 */
				public function has_cap( string $cap ): bool {
					return 'edit_others_pages' === $cap && $this->edits_others_pages;
				}
			}
		);
	}

	/**
	 * What map_meta_cap() gives for one check.
	 *
	 * @param string $cap The capability.
	 * @param int    $id  The post.
	 * @return string[]
	 */
	private function caps_for( string $cap, int $id ): array {
		return Capabilities::map_meta_cap( array( 'edit_others_pages' ), $cap, 7, array( $id ) );
	}

	/**
	 * A limited editor may edit, publish and delete-check pages in the tree as
	 * WordPress decides, including a revision of one.
	 *
	 * @param string $cap The capability.
	 * @param int    $id  The post.
	 */
	#[DataProvider( 'in_the_tree' )]
	public function test_a_page_in_the_tree_is_left_to_wordpress( string $cap, int $id ): void {
		$this->assertSame( array( 'edit_others_pages' ), $this->caps_for( $cap, $id ) );
	}

	/**
	 * The root, a child, a grandchild draft and a revision of a child.
	 *
	 * @return array<string, array{string, int}>
	 */
	public static function in_the_tree(): array {
		return array(
			'edit the root'         => array( 'edit_post', 10 ),
			'edit About'            => array( 'edit_post', 11 ),
			'publish a draft under' => array( 'publish_post', 12 ),
			'a revision of About'   => array( 'edit_post', 30 ),
			'edit a project'        => array( 'edit_post', 50 ),
			'delete a project'      => array( 'delete_post', 50 ),
		);
	}

	/**
	 * A limited editor can't edit, publish or delete a page outside the tree, or a
	 * revision of one.
	 *
	 * @param string $cap The capability.
	 * @param int    $id  The post.
	 */
	#[DataProvider( 'outside_the_tree' )]
	public function test_a_page_outside_the_tree_is_refused( string $cap, int $id ): void {
		$this->assertSame( array( 'do_not_allow' ), $this->caps_for( $cap, $id ) );
	}

	/**
	 * Each of the three, and a revision.
	 *
	 * @return array<string, array{string, int}>
	 */
	public static function outside_the_tree(): array {
		return array(
			'edit'       => array( 'edit_post', 20 ),
			'publish'    => array( 'publish_post', 20 ),
			'delete'     => array( 'delete_post', 20 ),
			'a revision' => array( 'edit_post', 31 ),
		);
	}

	/**
	 * With no Firehouse root page, a limited editor can edit no page at all.
	 */
	public function test_with_no_root_no_page_is_in_the_tree(): void {
		Functions\when( 'get_page_by_path' )->justReturn( null );

		$this->assertSame( array( 'do_not_allow' ), $this->caps_for( 'edit_post', 11 ) );
	}

	/**
	 * The limit applies only where the Firehouse role is what grants page editing.
	 *
	 * @param string[] $roles The user's roles.
	 */
	#[DataProvider( 'unlimited_users' )]
	public function test_other_users_are_not_limited( array $roles ): void {
		$this->as_user( $roles );

		$this->assertSame( array( 'edit_others_pages' ), $this->caps_for( 'edit_post', 20 ) );
	}

	/**
	 * An administrator, and a Firehouse Editor who is also an editor.
	 *
	 * @return array<string, array{string[]}>
	 */
	public static function unlimited_users(): array {
		return array(
			'an administrator'              => array( array( 'administrator' ) ),
			'a Firehouse Editor and editor' => array( array( Capabilities::EDITOR_ROLE, 'editor' ) ),
		);
	}

	/**
	 * No one, administrators included, can delete a topic.
	 */
	public function test_no_one_deletes_a_topic(): void {
		$this->as_user( array( 'administrator' ) );

		$this->assertSame( array( 'do_not_allow' ), $this->caps_for( 'delete_post', 40 ) );
		$this->assertSame( array( 'edit_others_pages' ), $this->caps_for( 'edit_post', 40 ) );
	}

	/**
	 * Other capabilities, and checks with no post, are left alone.
	 */
	public function test_other_checks_are_left_alone(): void {
		$this->assertSame( array( 'edit_others_pages' ), $this->caps_for( 'read_post', 20 ) );
		$this->assertSame( array( 'edit_pages' ), Capabilities::map_meta_cap( array( 'edit_pages' ), 'edit_post', 7, array() ) );
	}

	/**
	 * By any route, a limited editor's new page is refused, and so is a change to a
	 * page outside the tree. A page in the tree and a new project are not.
	 */
	public function test_the_backstop_refuses_new_pages_and_pages_outside(): void {
		$this->assertTrue( Capabilities::refuse_page_write( false, array( 'post_type' => 'page' ) ) );
		$this->assertTrue( Capabilities::refuse_page_write( false, array( 'ID' => 20 ) ) );
		$this->assertFalse( Capabilities::refuse_page_write( false, array( 'ID' => 11 ) ) );
		$this->assertFalse( Capabilities::refuse_page_write( false, array( 'post_type' => 'fh_project' ) ) );
		$this->assertFalse( Capabilities::refuse_page_write( false, array( 'ID' => 50 ) ) );
	}

	/**
	 * The backstop leaves everyone else, and WordPress's own refusals, alone.
	 */
	public function test_the_backstop_leaves_others_alone(): void {
		$this->assertTrue( Capabilities::refuse_page_write( true, array( 'ID' => 11 ) ) );
		$this->as_user( array( 'administrator' ) );
		$this->assertFalse( Capabilities::refuse_page_write( false, array( 'post_type' => 'page' ) ) );
	}

	/**
	 * A limited editor's page keeps its parent and slug; a draft with no slug yet gets
	 * one.
	 */
	public function test_a_limited_editors_page_stays_where_it_is(): void {
		$moved = array(
			'post_type'   => 'page',
			'post_parent' => 0,
			'post_name'   => 'moved-out',
		);

		$kept = Capabilities::keep_page_in_place( $moved, array( 'ID' => 11 ), array(), true );
		$this->assertSame( 10, $kept['post_parent'] );
		$this->assertSame( 'about', $kept['post_name'] );

		$draft = Capabilities::keep_page_in_place( $moved, array( 'ID' => 12 ), array(), true );
		$this->assertSame( 11, $draft['post_parent'] );
		$this->assertSame( 'moved-out', $draft['post_name'] );
	}

	/**
	 * Others' pages, new posts and other post types are saved as given.
	 */
	public function test_others_saves_are_left_alone(): void {
		$moved = array(
			'post_type'   => 'page',
			'post_parent' => 0,
			'post_name'   => 'moved-out',
		);
		$this->assertSame( $moved, Capabilities::keep_page_in_place( $moved, array( 'ID' => 11 ), array(), false ) );
		$project = array( 'post_type' => 'fh_project' ) + $moved;
		$this->assertSame( $project, Capabilities::keep_page_in_place( $project, array( 'ID' => 50 ), array(), true ) );

		$this->as_user( array( 'administrator' ) );
		$this->assertSame( $moved, Capabilities::keep_page_in_place( $moved, array( 'ID' => 11 ), array(), true ) );
	}

	/**
	 * Through the REST API, a limited editor's new page, move or rename is refused
	 * with a reason; an ordinary save goes through.
	 */
	public function test_the_block_editor_says_why(): void {
		$new = Capabilities::refuse_rest_page_write( (object) array( 'post_title' => 'New' ) );
		$this->assertSame( 'firehouse_cannot_add_page', $new->get_error_code() );

		$moved = Capabilities::refuse_rest_page_write( (object) array( 'ID' => 11, 'post_parent' => 0 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 'firehouse_cannot_move_page', $moved->get_error_code() );

		$renamed = Capabilities::refuse_rest_page_write( (object) array( 'ID' => 11, 'post_name' => 'about-us' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 'firehouse_cannot_move_page', $renamed->get_error_code() );

		$saved = (object) array(
			'ID'           => 11,
			'post_parent'  => 10,
			'post_name'    => 'about',
			'post_content' => 'New words',
		);
		$this->assertSame( $saved, Capabilities::refuse_rest_page_write( $saved ) );

		$named = (object) array(
			'ID'        => 12,
			'post_name' => 'first-slug',
		);
		$this->assertSame( $named, Capabilities::refuse_rest_page_write( $named ) );
	}

	/**
	 * The role can edit Firehouse content and pages, and nothing that reaches beyond:
	 * no posts, menus, settings, users, uploads, unfiltered HTML, deleting pages or
	 * importing.
	 */
	public function test_the_editor_role_reaches_only_firehouse(): void {
		$caps = Capabilities::editor_caps();

		foreach ( array( 'read', 'firehouse_manage_settings', 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'publish_pages', 'edit_fh_projects', 'delete_fh_projects', 'edit_fh_topics' ) as $cap ) {
			$this->assertArrayHasKey( $cap, $caps );
		}
		foreach ( array( 'unfiltered_html', 'edit_posts', 'edit_theme_options', 'manage_options', 'list_users', 'upload_files', 'delete_pages', 'delete_others_pages', 'read_private_pages', 'firehouse_import', 'delete_fh_topics', 'delete_published_fh_topics' ) as $cap ) {
			$this->assertArrayNotHasKey( $cap, $caps );
		}
		$this->assertSame( array_fill( 0, count( $caps ), true ), array_values( $caps ) );
	}

	/**
	 * Installing replaces the role with this definition and gives administrators
	 * every Firehouse capability, import included.
	 */
	public function test_install_replaces_the_role_and_equips_administrators(): void {
		$calls = array();
		Functions\when( 'remove_role' )->alias(
			static function ( string $role ) use ( &$calls ): void {
				$calls[] = array( 'remove', $role );
			}
		);
		Functions\when( 'add_role' )->alias(
			static function ( string $role, string $name, array $caps ) use ( &$calls ): void {
				$calls[] = array( 'add', $role, $caps );
			}
		);
		$administrator = new class() {
			/**
			 * The capabilities added.
			 *
			 * @var string[]
			 */
			public array $added = array();

			/**
			 * Adds a capability.
			 *
			 * @param string $cap The capability.
			 */
			public function add_cap( string $cap ): void {
				$this->added[] = $cap;
			}
		};
		Functions\when( 'get_role' )->justReturn( $administrator );

		Capabilities::install();

		$this->assertSame( array( 'remove', 'firehouse_editor' ), $calls[0] );
		$this->assertSame( array( 'add', 'firehouse_editor', Capabilities::editor_caps() ), $calls[1] );
		$this->assertSame( Capabilities::administrator_caps(), $administrator->added );
		$this->assertContains( 'firehouse_import', $administrator->added );
	}
}
