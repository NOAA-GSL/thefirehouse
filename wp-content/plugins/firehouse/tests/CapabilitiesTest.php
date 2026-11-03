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
	 * What a Firehouse Editor's account holds: the role's capabilities.
	 */
	private const EDITOR = array(
		'read'                      => true,
		'firehouse_edit_pages'      => true,
		'firehouse_manage_settings' => true,
	);

	/**
	 * The posts the tests use: the root page (10), About under it (11), a draft under
	 * About with no slug yet (12), a page outside the tree (20), a trashed page (21),
	 * a revision of About (30) and of the outside page (31), a topic (40) and a
	 * project (50).
	 *
	 * @var array<int, \WP_Post>
	 */
	private array $posts = array();

	/**
	 * Whether current_user_can() says yes, by "capability:post".
	 *
	 * @var array<string, bool>
	 */
	private array $can = array();

	/**
	 * Sets up the site, with a limited Firehouse Editor signed in.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->posts = array(
			10 => self::post( 10, 'page', 0, 'firehouse' ),
			11 => self::post( 11, 'page', 10, 'about' ),
			12 => self::post( 12, 'page', 11, '' ),
			20 => self::post( 20, 'page', 0, 'research' ),
			21 => self::post( 21, 'page', 0, 'gone__trashed', 'trash' ),
			30 => self::post( 30, 'revision', 11, '' ),
			31 => self::post( 31, 'revision', 20, '' ),
			40 => self::post( 40, 'fh_topic', 0, 'observe' ),
			50 => self::post( 50, 'fh_project', 0, 'a-project' ),
		);
		$posts       = $this->posts;
		Functions\when( 'get_post' )->alias( static fn( mixed $id ): ?\WP_Post => $id instanceof \WP_Post ? $id : ( $posts[ (int) $id ] ?? null ) );
		Functions\when( 'get_posts' )->justReturn( array( 10 ) );
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
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		$can = &$this->can;
		Functions\when( 'current_user_can' )->alias(
			static function ( string $cap, mixed $id = null ) use ( &$can ): bool {
				return $can[ $cap . ':' . (int) $id ] ?? false;
			}
		);
		$this->as_user( array( Capabilities::EDITOR_ROLE ), self::EDITOR );
	}

	/**
	 * A post.
	 *
	 * @param int    $id        Its ID.
	 * @param string $type      Its type.
	 * @param int    $parent_id Its parent.
	 * @param string $name      Its slug.
	 * @param string $status    Its status.
	 */
	private static function post( int $id, string $type, int $parent_id, string $name, string $status = 'publish' ): \WP_Post {
		return new \WP_Post(
			array(
				'ID'          => $id,
				'post_type'   => $type,
				'post_parent' => $parent_id,
				'post_name'   => $name,
				'post_status' => $status,
			)
		);
	}

	/**
	 * Makes a user with these roles and capabilities the current one.
	 *
	 * @param string[]            $roles   The user's roles.
	 * @param array<string, bool> $allcaps What their roles and account hold.
	 */
	private function as_user( array $roles, array $allcaps ): void {
		$user = new \WP_User( 7, $roles, $allcaps );
		Functions\when( 'get_userdata' )->justReturn( $user );
		Functions\when( 'wp_get_current_user' )->justReturn( $user );
	}

	/**
	 * What map_meta_cap() gives for one check, where WordPress asked for $caps.
	 *
	 * @param string   $cap  The capability.
	 * @param mixed    $post The post, or its ID.
	 * @param string[] $caps What WordPress requires.
	 * @return string[]
	 */
	private function caps_for( string $cap, mixed $post, array $caps = array( 'edit_others_pages' ) ): array {
		return Capabilities::map_meta_cap( $caps, $cap, 7, array( $post ) );
	}

	/**
	 * While the plugin runs, a Firehouse Editor is lent WordPress's page capabilities;
	 * anyone else is lent nothing.
	 */
	public function test_page_capabilities_are_lent_only_for_firehouse_pages(): void {
		$lent = Capabilities::lend_page_caps( self::EDITOR );
		foreach ( array( 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'publish_pages' ) as $cap ) {
			$this->assertTrue( $lent[ $cap ] );
		}
		$this->assertArrayNotHasKey( 'delete_pages', $lent );
		$this->assertSame( array( 'read' => true ), Capabilities::lend_page_caps( array( 'read' => true ) ) );
	}

	/**
	 * In the tree, WordPress decides, including for a revision of a tree page.
	 *
	 * @param string $cap The capability.
	 * @param int    $id  The post.
	 */
	#[DataProvider( 'in_the_tree' )]
	public function test_a_page_in_the_tree_is_left_to_wordpress( string $cap, int $id ): void {
		$this->assertSame( array( 'edit_others_pages' ), $this->caps_for( $cap, $id ) );
	}

	/**
	 * The root, a child, a grandchild draft, a revision, and a project.
	 *
	 * @return array<string, array{string, int}>
	 */
	public static function in_the_tree(): array {
		return array(
			'edit the root'         => array( 'edit_post', 10 ),
			'edit_page About'       => array( 'edit_page', 11 ),
			'publish a draft under' => array( 'publish_post', 12 ),
			'a revision of About'   => array( 'edit_post', 30 ),
			'edit a project'        => array( 'edit_post', 50 ),
			'delete a project'      => array( 'delete_post', 50 ),
		);
	}

	/**
	 * Outside the tree, a Firehouse Editor is refused whichever check is asked.
	 *
	 * @param string $cap The capability.
	 * @param mixed  $id  The post, or its ID.
	 */
	#[DataProvider( 'outside_the_tree' )]
	public function test_a_page_outside_the_tree_is_refused( string $cap, mixed $id ): void {
		$this->assertSame( array( 'do_not_allow' ), $this->caps_for( $cap, $id ) );
	}

	/**
	 * Each check WordPress makes, a revision, and a post passed as an object.
	 *
	 * @return array<string, array{string, mixed}>
	 */
	public static function outside_the_tree(): array {
		return array(
			'edit_post'          => array( 'edit_post', 20 ),
			'edit_page'          => array( 'edit_page', 20 ),
			'publish_post'       => array( 'publish_post', 20 ),
			'delete_post'        => array( 'delete_post', 20 ),
			'delete_page'        => array( 'delete_page', 20 ),
			'a revision'         => array( 'edit_post', 31 ),
			'the post as object' => array( 'edit_post', self::post( 20, 'page', 0, 'research' ) ),
		);
	}

	/**
	 * With no Firehouse root page, a Firehouse Editor can edit no page at all.
	 */
	public function test_with_no_root_no_page_is_in_the_tree(): void {
		Functions\when( 'get_posts' )->justReturn( array() );

		$this->assertSame( array( 'do_not_allow' ), $this->caps_for( 'edit_post', 11 ) );
	}

	/**
	 * Outside the tree, a user keeps what their own roles grant, and no more: a role
	 * that edits unpublished pages doesn't let the lent capabilities edit published ones.
	 */
	public function test_a_second_role_keeps_its_own_reach_and_no_more(): void {
		$this->as_user( array( Capabilities::EDITOR_ROLE, 'reviewer' ), self::EDITOR + array( 'edit_pages' => true, 'edit_others_pages' => true ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertSame( array( 'edit_others_pages' ), $this->caps_for( 'edit_post', 20 ) );
		$this->assertSame( array( 'do_not_allow' ), $this->caps_for( 'edit_post', 20, array( 'edit_others_pages', 'edit_published_pages' ) ) );
		$this->assertTrue( Capabilities::is_limited( wp_get_current_user() ) );
	}

	/**
	 * Someone whose own roles hold every page capability isn't limited; nor is anyone
	 * without the Firehouse page capability.
	 *
	 * @param string[]            $roles   The user's roles.
	 * @param array<string, bool> $allcaps What they hold.
	 */
	#[DataProvider( 'unlimited_users' )]
	public function test_other_users_are_not_limited( array $roles, array $allcaps ): void {
		$this->as_user( $roles, $allcaps );

		$this->assertSame( array( 'edit_others_pages' ), $this->caps_for( 'edit_post', 20 ) );
		$this->assertFalse( Capabilities::is_limited( wp_get_current_user() ) );
	}

	/**
	 * An administrator, and a Firehouse Editor who is also an editor.
	 *
	 * @return array<string, array{string[], array<string, bool>}>
	 */
	public static function unlimited_users(): array {
		$pages = array_fill_keys( array( 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'publish_pages' ), true );
		return array(
			'an administrator'              => array( array( 'administrator' ), $pages ),
			'a Firehouse Editor and editor' => array( array( Capabilities::EDITOR_ROLE, 'editor' ), self::EDITOR + $pages ),
		);
	}

	/**
	 * No one, administrators included, can delete a topic.
	 */
	public function test_no_one_deletes_a_topic(): void {
		$this->as_user( array( 'administrator' ), array() );

		$this->assertSame( array( 'do_not_allow' ), $this->caps_for( 'delete_post', 40 ) );
		$this->assertSame( array( 'do_not_allow' ), $this->caps_for( 'delete_page', 40 ) );
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
	 * page they can't edit. A page they can edit, and a new project, are not.
	 */
	public function test_the_backstop_refuses_new_pages_and_pages_outside(): void {
		$this->can['edit_post:11'] = true;

		$this->assertTrue( Capabilities::refuse_write( false, array( 'post_type' => 'page' ) ) );
		$this->assertTrue( Capabilities::refuse_write( false, array( 'ID' => 20 ) ) );
		$this->assertFalse( Capabilities::refuse_write( false, array( 'ID' => 11 ) ) );
		$this->assertFalse( Capabilities::refuse_write( false, array( 'post_type' => 'fh_project' ) ) );
		$this->assertFalse( Capabilities::refuse_write( false, array( 'ID' => 50 ) ) );
	}

	/**
	 * Trashing or restoring through an edit takes the right to delete, as the Trash
	 * and Restore links do, for everyone signed in: a topic can't be trashed even by an
	 * administrator, and a limited editor can't take a page out of the trash.
	 */
	public function test_trashing_or_restoring_through_an_edit_takes_the_right_to_delete(): void {
		$this->as_user( array( 'administrator' ), array() );
		$this->can['delete_post:50'] = true;

		$this->assertTrue( Capabilities::refuse_write( false, array( 'ID' => 40, 'post_status' => 'trash' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertTrue( Capabilities::refuse_write( false, array( 'ID' => 11, 'post_status' => 'trash' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertTrue( Capabilities::refuse_write( false, array( 'ID' => 21, 'post_status' => 'publish' ) ), 'Restoring.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertFalse( Capabilities::refuse_write( false, array( 'ID' => 50, 'post_status' => 'trash' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertFalse( Capabilities::refuse_write( false, array( 'ID' => 21, 'post_status' => 'trash' ) ), 'Already in the trash.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertFalse( Capabilities::refuse_write( false, array( 'ID' => 21 ) ), 'An edit that leaves the status alone.' );

		$this->can['delete_post:21'] = true;
		$this->assertFalse( Capabilities::refuse_write( false, array( 'ID' => 21, 'post_status' => 'publish' ) ), 'Restoring, by someone who may delete it.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$this->assertFalse( Capabilities::refuse_write( false, array( 'ID' => 40, 'post_status' => 'trash' ) ), 'WP-CLI and cron run as no one.' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	/**
	 * The backstop leaves everyone else, and WordPress's own refusals, alone.
	 */
	public function test_the_backstop_leaves_others_alone(): void {
		$this->assertTrue( Capabilities::refuse_write( true, array( 'ID' => 11 ) ) );
		$this->as_user( array( 'administrator' ), array() );
		$this->assertFalse( Capabilities::refuse_write( false, array( 'post_type' => 'page' ) ) );
	}

	/**
	 * A limited editor's page keeps its parent and slug, whatever its status; a draft
	 * with no slug yet gets one; a page in the trash keeps its parent but gets its slug
	 * back as WordPress gives it.
	 */
	public function test_a_limited_editors_page_stays_where_it_is(): void {
		$moved = array(
			'post_type'   => 'page',
			'post_parent' => 46,
			'post_name'   => 'moved-out',
		);

		$kept = Capabilities::keep_in_place( $moved, array( 'ID' => 11 ) );
		$this->assertSame( 10, $kept['post_parent'] );
		$this->assertSame( 'about', $kept['post_name'] );

		$draft = Capabilities::keep_in_place( $moved, array( 'ID' => 12 ) );
		$this->assertSame( 11, $draft['post_parent'] );
		$this->assertSame( 'moved-out', $draft['post_name'] );

		$trashed = Capabilities::keep_in_place( $moved, array( 'ID' => 21 ) );
		$this->assertSame( 0, $trashed['post_parent'], 'A restore can’t move it either.' );
		$this->assertSame( 'moved-out', $trashed['post_name'] );
	}

	/**
	 * A topic keeps its key and status, whoever saves it, unless the plugin is writing.
	 */
	public function test_a_topic_keeps_its_key_and_status(): void {
		$this->as_user( array( 'administrator' ), array() );
		$renamed = array(
			'post_type'   => 'fh_topic',
			'post_name'   => 'renamed',
			'post_status' => 'draft',
			'post_title'  => 'A new label',
		);

		$kept = Capabilities::keep_in_place( $renamed, array( 'ID' => 40 ) );

		$this->assertSame( 'observe', $kept['post_name'] );
		$this->assertSame( 'publish', $kept['post_status'] );
		$this->assertSame( 'A new label', $kept['post_title'] );
	}

	/**
	 * New posts, projects and others' pages are saved as given.
	 */
	public function test_others_saves_are_left_alone(): void {
		$moved = array(
			'post_type'   => 'page',
			'post_parent' => 0,
			'post_name'   => 'moved-out',
		);
		$this->assertSame( $moved, Capabilities::keep_in_place( $moved, array() ), 'A new post has no ID.' );
		$project = array( 'post_type' => 'fh_project' ) + $moved;
		$this->assertSame( $project, Capabilities::keep_in_place( $project, array( 'ID' => 50 ) ) );

		$this->as_user( array( 'administrator' ), array() );
		$this->assertSame( $moved, Capabilities::keep_in_place( $moved, array( 'ID' => 11 ) ) );
	}

	/**
	 * Whatever another plugin's sign-in filter returns is passed on.
	 */
	public function test_signing_in_takes_whatever_other_plugins_return(): void {
		$this->assertFalse( Capabilities::refuse_xmlrpc( false ) );
		$this->assertNull( Capabilities::refuse_xmlrpc( null ) );
	}

	/**
	 * Through the REST API, a limited editor's new page, move or rename is refused
	 * with a reason; an ordinary save goes through.
	 */
	public function test_the_block_editor_says_why(): void {
		$page = new \WP_REST_Request( '/wp/v2/pages/11' );

		$new = Capabilities::refuse_rest_page_write( (object) array( 'post_title' => 'New' ), new \WP_REST_Request( '/wp/v2/pages' ) );
		$this->assertSame( 'firehouse_cannot_add_page', $new->get_error_code() );

		$moved = Capabilities::refuse_rest_page_write( (object) array( 'ID' => 11, 'post_parent' => 0 ), $page ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 'firehouse_cannot_move_page', $moved->get_error_code() );

		$renamed = Capabilities::refuse_rest_page_write( (object) array( 'ID' => 11, 'post_name' => 'about-us' ), $page ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 'firehouse_cannot_move_page', $renamed->get_error_code() );

		$saved = (object) array(
			'ID'           => 11,
			'post_parent'  => 10,
			'post_name'    => 'about',
			'post_content' => 'New words',
		);
		$this->assertSame( $saved, Capabilities::refuse_rest_page_write( $saved, $page ) );
	}

	/**
	 * An autosave can't carry a refusal, so it just loses the move.
	 */
	public function test_an_autosave_loses_the_move_rather_than_failing(): void {
		$autosave = (object) array(
			'ID'           => 11,
			'post_parent'  => 0,
			'post_name'    => 'x',
			'post_content' => 'Draft words',
		);

		$saved = Capabilities::refuse_rest_page_write( $autosave, new \WP_REST_Request( '/wp/v2/pages/11/autosaves' ) );

		$this->assertSame( array( 'ID', 'post_content' ), array_keys( get_object_vars( $saved ) ) );
	}

	/**
	 * Outside XML-RPC, signing in is left alone; the role is recognized by name.
	 */
	public function test_signing_in_outside_xml_rpc_is_left_alone(): void {
		$editor = new \WP_User( 7, array( Capabilities::EDITOR_ROLE ) );

		$this->assertSame( $editor, Capabilities::refuse_xmlrpc( $editor ) );
		$this->assertTrue( Capabilities::is_editor( $editor ) );
		$this->assertFalse( Capabilities::is_editor( new \WP_User( 8, array( 'administrator' ) ) ) );
		$this->assertFalse( Capabilities::is_editor( null ) );
	}

	/**
	 * The role holds Firehouse's capabilities and nothing of WordPress's beyond read,
	 * so a plugin that is off or removed leaves a role that can do nothing.
	 */
	public function test_the_role_holds_nothing_of_wordpress_beyond_read(): void {
		$caps = Capabilities::editor_caps();

		foreach ( array( 'read', 'firehouse_manage_settings', 'firehouse_edit_pages', 'edit_fh_projects', 'delete_fh_projects', 'edit_fh_topics' ) as $cap ) {
			$this->assertArrayHasKey( $cap, $caps );
		}
		foreach ( array_keys( $caps ) as $cap ) {
			$this->assertTrue( 'read' === $cap || Capabilities::is_own( $cap ), $cap );
		}
		foreach ( array( 'firehouse_import', 'delete_fh_topics', 'delete_published_fh_topics' ) as $cap ) {
			$this->assertArrayNotHasKey( $cap, $caps );
		}
	}

	/**
	 * Installing replaces the role, gives administrators every Firehouse capability,
	 * import included, and takes back any Firehouse capability no longer granted.
	 */
	public function test_install_replaces_the_role_and_equips_administrators(): void {
		Functions\expect( 'remove_role' )->once()->with( 'firehouse_editor' );
		Functions\expect( 'add_role' )->once()->with( 'firehouse_editor', 'Firehouse Editor', Capabilities::editor_caps() );
		$administrator = new class() {
			/**
			 * What the role holds.
			 *
			 * @var array<string, bool>
			 */
			public array $capabilities = array(
				'manage_options'      => true,
				'firehouse_old_thing' => true,
				'edit_fh_projects'    => true,
			);

			/**
			 * Adds a capability.
			 *
			 * @param string $cap The capability.
			 */
			public function add_cap( string $cap ): void {
				$this->capabilities[ $cap ] = true;
			}

			/**
			 * Removes a capability.
			 *
			 * @param string $cap The capability.
			 */
			public function remove_cap( string $cap ): void {
				unset( $this->capabilities[ $cap ] );
			}
		};
		Functions\when( 'get_role' )->justReturn( $administrator );

		Capabilities::install();

		$this->assertArrayNotHasKey( 'firehouse_old_thing', $administrator->capabilities );
		$this->assertArrayHasKey( 'manage_options', $administrator->capabilities );
		foreach ( Capabilities::administrator_caps() as $cap ) {
			$this->assertArrayHasKey( $cap, $administrator->capabilities );
		}
	}
}
