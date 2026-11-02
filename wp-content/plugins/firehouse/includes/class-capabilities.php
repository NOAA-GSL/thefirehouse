<?php
/**
 * Firehouse's capabilities and the Firehouse Editor role.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Who may do what in Firehouse.
 *
 * The Firehouse Editor role can change Firehouse content and nothing else on the
 * site: projects, topics, Firehouse settings, and the pages in the Firehouse tree
 * (the `firehouse` page and its descendants). It has no `unfiltered_html` (rule 12),
 * can't edit the site's posts or menus, and can't add, delete, move or rename a page.
 * Administrators get the Firehouse capabilities too, and they alone can import.
 *
 * WordPress grants page capabilities for every page at once, so the tree limit is
 * applied on top of them: map_meta_cap() refuses any page outside the tree, and the
 * other filters refuse a new page or a move out of the tree by any route, whether
 * the block editor, Quick Edit or XML-RPC. It applies only where the Firehouse role is
 * what grants page editing: someone who also holds a role that edits others' pages
 * keeps that role's reach.
 */
final class Capabilities {

	/**
	 * The Firehouse Editor role.
	 */
	public const EDITOR_ROLE = 'firehouse_editor';

	/**
	 * Running the Import screen.
	 */
	public const IMPORT = 'firehouse_import';

	/**
	 * Changing the Firehouse settings.
	 */
	public const SETTINGS = 'firehouse_manage_settings';

	/**
	 * Hooks the filters.
	 */
	public static function hook(): void {
		add_filter( 'map_meta_cap', array( self::class, 'map_meta_cap' ), 10, 4 );
		add_filter( 'wp_insert_post_empty_content', array( self::class, 'refuse_page_write' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( self::class, 'keep_page_in_place' ), 10, 4 );
		add_filter( 'rest_pre_insert_page', array( self::class, 'refuse_rest_page_write' ) );
		add_action( 'load-post-new.php', array( self::class, 'refuse_new_page_screen' ) );
		add_action( 'pre_get_posts', array( self::class, 'limit_page_list' ) );
	}

	/**
	 * Every primitive capability of the project post type.
	 *
	 * @return list<string>
	 */
	public static function project_caps(): array {
		return array(
			'edit_fh_projects',
			'edit_others_fh_projects',
			'edit_private_fh_projects',
			'edit_published_fh_projects',
			'publish_fh_projects',
			'read_private_fh_projects',
			'delete_fh_projects',
			'delete_others_fh_projects',
			'delete_private_fh_projects',
			'delete_published_fh_projects',
		);
	}

	/**
	 * The topic capabilities anyone is given. None deletes: the four topics are fixed.
	 *
	 * @return list<string>
	 */
	public static function topic_caps(): array {
		return array(
			'edit_fh_topics',
			'edit_others_fh_topics',
			'edit_private_fh_topics',
			'edit_published_fh_topics',
			'publish_fh_topics',
			'read_private_fh_topics',
		);
	}

	/**
	 * The Firehouse Editor role's capabilities: Firehouse content, and the page
	 * capabilities the tree limit narrows to the Firehouse tree. No deleting pages,
	 * reading others' private pages, uploading, or anything outside Firehouse.
	 *
	 * @return array<string, true>
	 */
	public static function editor_caps(): array {
		$caps = array_merge(
			array( 'read', self::SETTINGS, 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'publish_pages' ),
			self::project_caps(),
			self::topic_caps()
		);
		return array_fill_keys( $caps, true );
	}

	/**
	 * The capabilities administrators are given: all of Firehouse's, including import.
	 *
	 * @return list<string>
	 */
	public static function administrator_caps(): array {
		return array_merge( self::project_caps(), self::topic_caps(), array( self::SETTINGS, self::IMPORT ) );
	}

	/**
	 * Creates the Firehouse Editor role as defined here, replacing an older version
	 * of it, and gives administrators the Firehouse capabilities. Users keep the role
	 * while it is replaced, because their accounts store only its name.
	 */
	public static function install(): void {
		remove_role( self::EDITOR_ROLE );
		add_role( self::EDITOR_ROLE, 'Firehouse Editor', self::editor_caps() );

		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			foreach ( self::administrator_caps() as $cap ) {
				$administrator->add_cap( $cap );
			}
		}
	}

	/**
	 * Refuses deleting a topic to everyone, and editing, publishing or deleting a page
	 * outside the Firehouse tree to a limited editor.
	 *
	 * @param string[] $caps    The primitive capabilities WordPress requires.
	 * @param string   $cap     The capability asked about.
	 * @param int      $user_id The user.
	 * @param mixed[]  $args    The capability's arguments; the first is the post ID.
	 * @return string[]
	 */
	public static function map_meta_cap( array $caps, string $cap, int $user_id, array $args ): array {
		if ( ! in_array( $cap, array( 'edit_post', 'delete_post', 'publish_post' ), true ) || ! isset( $args[0] ) ) {
			return $caps;
		}
		$post = get_post( (int) $args[0] );
		if ( $post instanceof \WP_Post && 'revision' === $post->post_type ) {
			$post = get_post( $post->post_parent );
		}
		if ( ! $post instanceof \WP_Post ) {
			return $caps;
		}

		if ( 'delete_post' === $cap && Post_Types::TOPIC === $post->post_type ) {
			return array( 'do_not_allow' );
		}
		if ( 'page' === $post->post_type && ! self::in_tree( $post ) && self::is_limited( get_userdata( $user_id ) ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	}

	/**
	 * Refuses, by any route, a limited editor's new page, or a change to a page
	 * outside the tree. WordPress reports this as empty content, so the routes the
	 * editor uses refuse it earlier with a clearer message; this is the backstop.
	 *
	 * @param bool                 $refuse  Whether WordPress would refuse it already.
	 * @param array<string, mixed> $postarr The post being saved.
	 */
	public static function refuse_page_write( bool $refuse, array $postarr ): bool {
		if ( $refuse || ! self::is_limited( wp_get_current_user() ) ) {
			return $refuse;
		}
		$id = (int) ( $postarr['ID'] ?? 0 );
		if ( 0 === $id ) {
			return 'page' === ( $postarr['post_type'] ?? 'post' );
		}
		$stored = get_post( $id );
		return $stored instanceof \WP_Post && 'page' === $stored->post_type && ! self::in_tree( $stored );
	}

	/**
	 * Keeps a limited editor's page where it is: its parent and slug are its address,
	 * so they can't be changed to move it out of the tree or break its links. A draft
	 * that has no slug yet gets one as usual.
	 *
	 * @param array<string, mixed> $data        The post's fields as they will be saved.
	 * @param array<string, mixed> $postarr     The post as given.
	 * @param array<string, mixed> $unsanitized The post as given, before sanitizing.
	 * @param bool                 $update      Whether an existing post is being changed.
	 * @return array<string, mixed>
	 */
	public static function keep_page_in_place( array $data, array $postarr, array $unsanitized, bool $update ): array {
		if ( ! $update || 'page' !== ( $data['post_type'] ?? null ) || ! self::is_limited( wp_get_current_user() ) ) {
			return $data;
		}
		$stored = get_post( (int) ( $postarr['ID'] ?? 0 ) );
		if ( ! $stored instanceof \WP_Post ) {
			return $data;
		}
		$data['post_parent'] = $stored->post_parent;
		if ( '' !== $stored->post_name ) {
			$data['post_name'] = $stored->post_name;
		}
		return $data;
	}

	/**
	 * Refuses a limited editor's new page, or a move or rename of a page, through the
	 * REST API, which the block editor saves pages with, and says why.
	 *
	 * @param \stdClass|\WP_Error $prepared The page as the request would save it.
	 * @return \stdClass|\WP_Error
	 */
	public static function refuse_rest_page_write( \stdClass|\WP_Error $prepared ): \stdClass|\WP_Error {
		if ( is_wp_error( $prepared ) || ! self::is_limited( wp_get_current_user() ) ) {
			return $prepared;
		}
		if ( empty( $prepared->ID ) ) {
			return new \WP_Error( 'firehouse_cannot_add_page', __( 'Firehouse editors can’t add pages.', 'firehouse' ), array( 'status' => 403 ) );
		}
		$stored = get_post( (int) $prepared->ID );
		if ( ! $stored instanceof \WP_Post ) {
			return $prepared;
		}
		$moved   = isset( $prepared->post_parent ) && (int) $prepared->post_parent !== $stored->post_parent;
		$renamed = isset( $prepared->post_name ) && '' !== $stored->post_name && $prepared->post_name !== $stored->post_name;
		if ( $moved || $renamed ) {
			return new \WP_Error( 'firehouse_cannot_move_page', __( 'A Firehouse page’s parent and slug are its address, so Firehouse editors can’t change them.', 'firehouse' ), array( 'status' => 403 ) );
		}
		return $prepared;
	}

	/**
	 * Refuses the Add New Page screen to a limited editor.
	 *
	 * The menu's Add New Page link stays: without it, the Pages menu would have one
	 * item, the same as its parent, and WordPress would drop the submenu and then
	 * refuse the Pages list to anyone who can't edit posts.
	 */
	public static function refuse_new_page_screen(): void {
		global $typenow;
		if ( 'page' === $typenow && self::is_limited( wp_get_current_user() ) ) {
			wp_die( esc_html__( 'Firehouse editors can’t add pages.', 'firehouse' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Shows a limited editor only the Firehouse pages in the Pages list.
	 *
	 * @param \WP_Query $query The query.
	 */
	public static function limit_page_list( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || 'page' !== $query->get( 'post_type' ) || ! self::is_limited( wp_get_current_user() ) ) {
			return;
		}
		// An empty post__in means no limit, so a site with no tree lists nothing.
		$ids = self::tree_ids();
		$query->set( 'post__in', array() === $ids ? array( 0 ) : $ids );
	}

	/**
	 * Whether a user's page editing is limited to the Firehouse tree: they hold the
	 * Firehouse Editor role, and no other role of theirs edits others' pages.
	 *
	 * @param \WP_User|false $user The user.
	 */
	public static function is_limited( \WP_User|false $user ): bool {
		if ( ! $user instanceof \WP_User || ! in_array( self::EDITOR_ROLE, $user->roles, true ) ) {
			return false;
		}
		foreach ( $user->roles as $name ) {
			$role = self::EDITOR_ROLE === $name ? null : get_role( $name );
			if ( $role && $role->has_cap( 'edit_others_pages' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a page is the Firehouse root or one of its descendants.
	 *
	 * @param \WP_Post $post The page.
	 */
	public static function in_tree( \WP_Post $post ): bool {
		$root = self::root_id();
		return 0 !== $root && ( $post->ID === $root || in_array( $root, get_post_ancestors( $post ), true ) );
	}

	/**
	 * The root page's ID: the top-level page at /firehouse/, or 0 if there is none.
	 */
	public static function root_id(): int {
		$root = get_page_by_path( Query::ROOT_PATH );
		return $root instanceof \WP_Post ? $root->ID : 0;
	}

	/**
	 * The IDs of the root page and its descendants.
	 *
	 * @return list<int>
	 */
	public static function tree_ids(): array {
		$root = self::root_id();
		if ( 0 === $root ) {
			return array();
		}
		$descendants = get_pages(
			array(
				'child_of'    => $root,
				'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			)
		);
		if ( ! is_array( $descendants ) ) {
			$descendants = array();
		}
		return array_merge( array( $root ), array_map( static fn( \WP_Post $page ): int => $page->ID, $descendants ) );
	}
}
