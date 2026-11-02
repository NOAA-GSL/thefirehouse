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
 * site: projects, topics, Firehouse settings, and the pages in the Firehouse tree (the
 * `firehouse` page and its descendants). It has no `unfiltered_html` (rule 12), can't
 * edit the site's posts or menus, can't add, delete or trash a page or take one out of
 * the trash, can't change a page's parent or slug, which are its address, and can't
 * use XML-RPC. Administrators get the Firehouse capabilities too, and they alone can
 * import. No one can delete or trash a topic, or change its key or status, except the
 * plugin's own import (Store::writing()).
 *
 * The role stores `read` and Firehouse's own capabilities. WordPress's page
 * capabilities are lent to it while the plugin runs (lend_page_caps()) and narrowed to
 * the tree (map_meta_cap()), so with the plugin off its users can only sign in.
 *
 * The other filters refuse, by any route that writes, a new page, and a trashing or
 * restoring by anyone who may not delete the post. A move or slug change is refused
 * through the REST API and quietly undone elsewhere (keep_in_place()). A user whose
 * other role edits all pages, such as Editor, keeps that reach; for anyone else the
 * lent capabilities add only the Firehouse pages. Anyone with the role loses XML-RPC,
 * whatever else they hold.
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
	 * Editing the pages in the Firehouse tree. The role stores this instead of
	 * WordPress's page capabilities, which are lent for it while the plugin runs.
	 */
	public const EDIT_PAGES = 'firehouse_edit_pages';

	/**
	 * The page capabilities lent for EDIT_PAGES.
	 */
	private const PAGE_CAPS = array( 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'publish_pages' );

	/**
	 * The meta capabilities that act on one page or post.
	 */
	private const POST_CHECKS = array( 'edit_post', 'edit_page', 'delete_post', 'delete_page', 'publish_post' );

	/**
	 * Hooks the filters.
	 */
	public static function hook(): void {
		add_filter( 'user_has_cap', array( self::class, 'lend_page_caps' ) );
		add_filter( 'map_meta_cap', array( self::class, 'map_meta_cap' ), 10, 4 );
		add_filter( 'wp_insert_post_empty_content', array( self::class, 'refuse_write' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( self::class, 'keep_in_place' ), 10, 2 );
		add_filter( 'rest_pre_insert_page', array( self::class, 'refuse_rest_page_write' ), 10, 2 );
		add_filter( 'authenticate', array( self::class, 'refuse_xmlrpc' ), 100 );
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
	 * The Firehouse Editor role's capabilities: Firehouse content and settings, and
	 * the tree's pages. Nothing of WordPress's own beyond `read`.
	 *
	 * @return array<string, true>
	 */
	public static function editor_caps(): array {
		$caps = array_merge( array( 'read', self::SETTINGS, self::EDIT_PAGES ), self::project_caps(), self::topic_caps() );
		return array_fill_keys( $caps, true );
	}

	/**
	 * The capabilities administrators are given: all of Firehouse's, including import.
	 * They edit pages with their own.
	 *
	 * @return list<string>
	 */
	public static function administrator_caps(): array {
		return array_merge( self::project_caps(), self::topic_caps(), array( self::SETTINGS, self::IMPORT ) );
	}

	/**
	 * Whether a capability is one of Firehouse's, whether or not this version still
	 * grants it. uninstall.php recognizes them the same way.
	 *
	 * @param string $cap The capability.
	 */
	public static function is_own( string $cap ): bool {
		return 1 === preg_match( '/^firehouse_|_fh_(projects|topics)$/', $cap );
	}

	/**
	 * Creates the Firehouse Editor role as defined here, replacing an older version
	 * of it, and gives administrators the Firehouse capabilities, taking back any an
	 * older version gave. Users keep the role while it is replaced, because their
	 * accounts store only its name.
	 */
	public static function install(): void {
		remove_role( self::EDITOR_ROLE );
		add_role( self::EDITOR_ROLE, 'Firehouse Editor', self::editor_caps() );

		$administrator = get_role( 'administrator' );
		if ( ! $administrator ) {
			return;
		}
		foreach ( array_keys( $administrator->capabilities ) as $cap ) {
			if ( self::is_own( (string) $cap ) && ! in_array( $cap, self::administrator_caps(), true ) ) {
				$administrator->remove_cap( $cap );
			}
		}
		foreach ( self::administrator_caps() as $cap ) {
			$administrator->add_cap( $cap );
		}
	}

	/**
	 * Lends WordPress's page capabilities to anyone who may edit Firehouse pages, for
	 * this request only; map_meta_cap() keeps them inside the tree.
	 *
	 * @param array<string, bool> $allcaps What the user has.
	 * @return array<string, bool>
	 */
	public static function lend_page_caps( array $allcaps ): array {
		if ( ! empty( $allcaps[ self::EDIT_PAGES ] ) ) {
			foreach ( self::PAGE_CAPS as $cap ) {
				$allcaps[ $cap ] = true;
			}
		}
		return $allcaps;
	}

	/**
	 * Refuses deleting a topic to everyone, and refuses a page outside the Firehouse
	 * tree to anyone whose page capabilities are lent, unless what WordPress asks for
	 * is theirs anyway.
	 *
	 * @param string[] $caps    The primitive capabilities WordPress requires.
	 * @param string   $cap     The capability asked about.
	 * @param int      $user_id The user.
	 * @param mixed[]  $args    The capability's arguments; the first is the post.
	 * @return string[]
	 */
	public static function map_meta_cap( array $caps, string $cap, int $user_id, array $args ): array {
		if ( ! in_array( $cap, self::POST_CHECKS, true ) || ! isset( $args[0] ) ) {
			return $caps;
		}
		$post = get_post( $args[0] );
		if ( $post instanceof \WP_Post && 'revision' === $post->post_type ) {
			$post = get_post( $post->post_parent );
		}
		if ( ! $post instanceof \WP_Post ) {
			return $caps;
		}

		if ( in_array( $cap, array( 'delete_post', 'delete_page' ), true ) && Post_Types::TOPIC === $post->post_type ) {
			return array( 'do_not_allow' );
		}
		if ( 'page' !== $post->post_type ) {
			return $caps;
		}
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User || empty( $user->allcaps[ self::EDIT_PAGES ] ) || self::covers( $user, $caps ) || self::in_tree( $post ) ) {
			return $caps;
		}
		return array( 'do_not_allow' );
	}

	/**
	 * Refuses, by any route that writes:
	 *
	 * - Moving a page, project or topic into or out of the trash through an edit, for
	 *   anyone who may not delete it. WordPress asks for that only on its Trash and
	 *   Restore links, and renames a trashed page before anything later could stop it.
	 * - A limited editor's new page, or a change to a page they may not edit.
	 *
	 * WordPress reports a refusal here as empty content, so the routes an editor uses
	 * refuse earlier with a clearer message; this is the backstop.
	 *
	 * @param bool                 $refuse  Whether WordPress would refuse it already.
	 * @param array<string, mixed> $postarr The post being saved.
	 */
	public static function refuse_write( bool $refuse, array $postarr ): bool {
		if ( $refuse ) {
			return true;
		}
		$id     = (int) ( $postarr['ID'] ?? 0 );
		$stored = 0 === $id ? null : get_post( $id );
		$type   = $stored instanceof \WP_Post ? $stored->post_type : ( $postarr['post_type'] ?? 'post' );

		$status     = $postarr['post_status'] ?? '';
		$trashing   = $stored instanceof \WP_Post && 'trash' === $status && 'trash' !== $stored->post_status;
		$untrashing = $stored instanceof \WP_Post && 'trash' === $stored->post_status && '' !== $status && 'trash' !== $status;
		if ( ( $trashing || $untrashing ) && in_array( $type, array( 'page', Post_Types::PROJECT, Post_Types::TOPIC ), true ) && is_user_logged_in() && ! current_user_can( 'delete_post', $id ) ) {
			return true;
		}

		if ( 'page' !== $type || ! self::is_limited( wp_get_current_user() ) ) {
			return false;
		}
		return ! $stored instanceof \WP_Post || ! current_user_can( 'edit_post', $id );
	}

	/**
	 * Keeps what is structural where it is, whatever a save sends:
	 *
	 * - A topic's key and status, unless the plugin itself is writing: there are four
	 *   topics, each always published, at its key.
	 * - A limited editor's page's parent and slug, which are its address, whatever the
	 *   page's status. A draft that has no slug yet gets one as usual, and a page in the
	 *   trash, whose slug WordPress has changed, gets its old one back as WordPress
	 *   gives it.
	 *
	 * It reads only the first two of the filter's arguments, which every caller passes.
	 *
	 * @param array<string, mixed> $data    The post's fields as they will be saved.
	 * @param array<string, mixed> $postarr The post as given.
	 * @return array<string, mixed>
	 */
	public static function keep_in_place( array $data, array $postarr ): array {
		// The ID decides, not $update: get_post( 0 ) would be whatever post is current.
		$id     = (int) ( $postarr['ID'] ?? 0 );
		$stored = 0 === $id ? null : get_post( $id );
		if ( ! $stored instanceof \WP_Post ) {
			return $data;
		}

		if ( Post_Types::TOPIC === $stored->post_type && ! Store::writing() ) {
			$data['post_name']   = $stored->post_name;
			$data['post_status'] = $stored->post_status;
		}
		if ( 'page' === $stored->post_type && self::is_limited( wp_get_current_user() ) ) {
			$data['post_parent'] = $stored->post_parent;
			if ( '' !== $stored->post_name && 'trash' !== $stored->post_status ) {
				$data['post_name'] = $stored->post_name;
			}
		}
		return $data;
	}

	/**
	 * Refuses a limited editor's new page, or a move or rename of a page, through the
	 * REST API, which the block editor saves pages with, and says why. An autosave
	 * can't carry a refusal (WordPress would save the error as the autosave), so it
	 * just loses the move.
	 *
	 * @param \stdClass|\WP_Error $prepared The page as the request would save it.
	 * @param \WP_REST_Request    $request  The request.
	 * @return \stdClass|\WP_Error
	 */
	public static function refuse_rest_page_write( \stdClass|\WP_Error $prepared, \WP_REST_Request $request ): \stdClass|\WP_Error {
		if ( is_wp_error( $prepared ) || ! self::is_limited( wp_get_current_user() ) ) {
			return $prepared;
		}
		if ( str_ends_with( $request->get_route(), '/autosaves' ) ) {
			unset( $prepared->post_parent, $prepared->post_name );
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
	 * Refuses XML-RPC to Firehouse Editors, who have no use for it. Its page methods
	 * check capabilities of their own, and its custom fields bypass the screens. Any
	 * type is taken, since other plugins' filters on every sign-in can return anything.
	 *
	 * @param mixed $user Who signed in, as far as WordPress and other plugins have decided.
	 * @return mixed
	 */
	public static function refuse_xmlrpc( mixed $user ): mixed {
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST && self::is_editor( $user ) ) {
			return new \WP_Error( 'firehouse_no_xmlrpc', __( 'Firehouse editors can’t use XML-RPC.', 'firehouse' ) );
		}
		return $user;
	}

	/**
	 * Refuses the Add New Page screen to a limited editor, and says where to go.
	 *
	 * The menu's Add New Page link stays: without it, the Pages menu would have one
	 * item, the same as its parent, and WordPress would drop the submenu and then
	 * refuse the Pages list to anyone who can't edit posts.
	 */
	public static function refuse_new_page_screen(): void {
		global $typenow;
		if ( 'page' !== $typenow || ! self::is_limited( wp_get_current_user() ) ) {
			return;
		}
		wp_die(
			'<h1>' . esc_html__( 'Firehouse editors can’t add pages', 'firehouse' ) . '</h1><p>' . esc_html__( 'The Firehouse pages already exist, and you can edit them from the Pages list. To add a page, ask a site administrator.', 'firehouse' ) . '</p>',
			esc_html__( 'Can’t add pages', 'firehouse' ),
			array(
				'response'  => 403,
				'link_url'  => esc_url( admin_url( 'edit.php?post_type=page' ) ),
				'link_text' => esc_html__( 'Back to Pages', 'firehouse' ),
			)
		);
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
	 * Whether a user holds the Firehouse Editor role.
	 *
	 * @param mixed $user The user, or anything else.
	 */
	public static function is_editor( mixed $user ): bool {
		return $user instanceof \WP_User && in_array( self::EDITOR_ROLE, $user->roles, true );
	}

	/**
	 * Whether a user's page editing is limited to the Firehouse tree: their page
	 * capabilities are lent, because their own roles don't hold all of them.
	 *
	 * @param \WP_User|false $user The user.
	 */
	public static function is_limited( \WP_User|false $user ): bool {
		return $user instanceof \WP_User && ! empty( $user->allcaps[ self::EDIT_PAGES ] ) && ! self::covers( $user, self::PAGE_CAPS );
	}

	/**
	 * Whether a user's own roles and capabilities hold every one of these, without
	 * what is lent.
	 *
	 * @param \WP_User $user The user.
	 * @param string[] $caps The capabilities.
	 */
	private static function covers( \WP_User $user, array $caps ): bool {
		foreach ( $caps as $cap ) {
			if ( empty( $user->allcaps[ $cap ] ) ) {
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
	 * The root page's ID: the oldest top-level page with the slug `firehouse` that
	 * isn't in the trash, or 0 if there is none.
	 */
	public static function root_id(): int {
		$ids = get_posts(
			array(
				'post_type'   => 'page',
				'name'        => Query::ROOT_PATH,
				'post_parent' => 0,
				'post_status' => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		);
		return isset( $ids[0] ) ? (int) $ids[0] : 0;
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
