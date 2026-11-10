<?php
/**
 * Which projects a visitor may see, wherever WordPress reaches them.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps a project a visitor may not see off the public side of the site. A visitor may
 * see a project that is published, has no password, and has IRB approval or exemption
 * stored as true (Store::is_public()). The site's normalize step drops a project
 * without approval before any page sees it. WordPress has no such step: to it, a
 * published post is public.
 *
 * What this covers:
 *
 * - What a query finds (`posts_results`): the project's address, `?p=` and
 *   `?fh_project=`, the archive, feeds, the embed template, the sitemap, and oEmbed's
 *   lookup of a pretty address.
 * - The redirects WordPress builds from a post ID without a query: any redirect on a
 *   request whose `?p=`, `?page_id=` or `?attachment_id=` names a hidden project or
 *   one of its revisions, which covers a missing address and a feed; and a changed
 *   slug's.
 * - oEmbed's lookup of a `?p=` address, which finds the post without a query.
 * - A menu item that links to the project.
 * - The previous and next links in a project's head.
 *
 * What it doesn't: a query for IDs only (`fields` of `ids` or `id=>parent`), which
 * WordPress answers before `posts_results`; a get_posts() call, which leaves these
 * filters out; and an embed another post cached while the project was public, until
 * that post is saved again. So the plugin's own lookups ask for public projects
 * themselves (Store::public_projects_query()), as Frame::guess_404 and the topic pages
 * do.
 *
 * Someone who may edit the project still sees it at its own address, as WordPress
 * shows them a draft in preview, and nowhere else: not in a list, a feed, an embed or
 * oEmbed, any of which a cache could hand on to a visitor.
 */
final class Visibility {

	/**
	 * Hooks the query's results, the redirects, oEmbed, menus and the head's links.
	 */
	public static function hook(): void {
		add_filter( 'posts_results', array( self::class, 'posts' ), 10, 2 );
		add_filter( 'redirect_canonical', array( self::class, 'redirect' ) );
		add_filter( 'oembed_request_post_id', array( self::class, 'post_id' ) );
		add_filter( 'old_slug_redirect_post_id', array( self::class, 'post_id' ) );
		add_filter( 'wp_setup_nav_menu_item', array( self::class, 'menu_item' ) );
		add_action( 'wp', array( self::class, 'head_links' ) );
	}

	/**
	 * Whether a post is a project a visitor may not see.
	 *
	 * @param \WP_Post $post The post.
	 */
	public static function hidden( \WP_Post $post ): bool {
		return Post_Types::PROJECT === $post->post_type && ! Store::is_public( $post );
	}

	/**
	 * A query's posts, without the projects a visitor may not see. Runs on every query
	 * that doesn't suppress filters and returns posts, the page's main query included:
	 * one with no project in it passes through untouched. A hidden project's address
	 * then gets the 404, and the frame's not-found page under /firehouse/.
	 *
	 * The admin's own screens are left alone, and so is WP-CLI, unless it's running
	 * cron, which runs the same code as a visitor's request to wp-cron.php. A tool that
	 * renders public pages through WP-CLI would show hidden projects.
	 *
	 * WordPress limits a list before this runs, so a page of the archive or the feed
	 * can come back short, and the query's count of what it found (`found_posts`) still
	 * counts a hidden project. A list of projects should ask for public ones itself.
	 *
	 * @param mixed $posts The posts the query found.
	 * @param mixed $query The query.
	 * @return mixed
	 */
	public static function posts( mixed $posts, mixed $query = null ): mixed {
		if ( ! is_array( $posts ) || ( is_admin() && ! wp_doing_ajax() ) || ( defined( 'WP_CLI' ) && WP_CLI && ! wp_doing_cron() ) ) {
			return $posts;
		}
		$projects = false;
		$ids      = array();
		foreach ( $posts as $post ) {
			if ( $post instanceof \WP_Post && Post_Types::PROJECT === $post->post_type ) {
				$projects = true;
				// Only these need their approval read; the rest fail without it.
				if ( 'publish' === $post->post_status && '' === $post->post_password ) {
					$ids[] = $post->ID;
				}
			}
		}
		if ( ! $projects ) {
			return $posts;
		}
		// Most queries have loaded their posts' fields by now, and this finds them
		// loaded. One that loads them after this filter skips loading them again. One
		// that never loads them, as WordPress's sitemap doesn't, makes this one query
		// rather than one for each project.
		if ( array() !== $ids ) {
			update_postmeta_cache( $ids );
		}
		$preview = self::previews( $query );
		return array_values(
			array_filter(
				$posts,
				static fn( mixed $post ): bool => ! ( $post instanceof \WP_Post && self::hidden( $post ) && ! ( $preview && current_user_can( 'edit_post', $post->ID ) ) )
			)
		);
	}

	/**
	 * Whether a query is a page of one post, which someone who may edit a hidden
	 * project may still see: the main query, for one post, not as an embed or a feed.
	 *
	 * @param mixed $query The query.
	 */
	private static function previews( mixed $query ): bool {
		return $query instanceof \WP_Query && $query->is_main_query() && $query->is_singular() && ! $query->is_embed() && ! $query->is_feed();
	}

	/**
	 * No redirect built from `?p=`, `?page_id=` or `?attachment_id=` when it names a
	 * project a visitor may not see. WordPress builds those redirects from the ID
	 * alone, whatever the query found: on a missing address, to the post's address,
	 * and on a feed, which is never a missing address, to its comments feed. Either
	 * would give the project's slug away.
	 *
	 * A revision of the project counts as the project. Projects keep revisions, and
	 * when `?p=` finds nothing, WordPress looks the ID up again and sends a revision's
	 * ID to the address of the post it's a revision of. The query copies `?page_id=`
	 * and `?attachment_id=` into `p`, so the same happens to them.
	 *
	 * @param mixed $redirect Where WordPress would redirect, or false for nowhere.
	 * @return mixed
	 */
	public static function redirect( mixed $redirect ): mixed {
		if ( ! is_string( $redirect ) ) {
			return $redirect;
		}
		foreach ( array( 'p', 'page_id', 'attachment_id' ) as $name ) {
			$id   = (int) get_query_var( $name );
			$post = $id > 0 ? get_post( $id ) : null;
			// A parent of 0 isn't looked up: get_post( 0 ) returns the current post.
			if ( $post instanceof \WP_Post && 'revision' === $post->post_type && $post->post_parent > 0 ) {
				$post = get_post( $post->post_parent );
			}
			if ( $post instanceof \WP_Post && self::hidden( $post ) ) {
				return false;
			}
		}
		return $redirect;
	}

	/**
	 * No post for oEmbed, or for a changed slug's redirect, when it's a project a
	 * visitor may not see, whoever asks: WordPress caches an embed's markup in the post
	 * that embeds it, and shows every visitor that copy.
	 *
	 * @param mixed $post_id The post found, or 0 for none.
	 * @return mixed
	 */
	public static function post_id( mixed $post_id ): mixed {
		$post = is_numeric( $post_id ) && (int) $post_id > 0 ? get_post( (int) $post_id ) : null;
		return $post instanceof \WP_Post && self::hidden( $post ) ? 0 : $post_id;
	}

	/**
	 * A menu item that links to a project a visitor may not see is marked invalid, so
	 * menus leave it out on public pages. WordPress builds a menu item from the post
	 * directly, without a query. The admin's Menus screen shows it as invalid and asks
	 * for invalid items to be checked or deleted; deleting it loses it for good, even
	 * if the project is approved again.
	 *
	 * @param mixed $item The menu item.
	 * @return mixed
	 */
	public static function menu_item( mixed $item ): mixed {
		if ( ! is_object( $item ) || 'post_type' !== ( $item->type ?? '' ) || Post_Types::PROJECT !== ( $item->object ?? '' ) ) {
			return $item;
		}
		$post = get_post( (int) ( $item->object_id ?? 0 ) );
		if ( $post instanceof \WP_Post && self::hidden( $post ) ) {
			$item->_invalid = true;
		}
		return $item;
	}

	/**
	 * No previous and next links in a project's head. WordPress's function for them
	 * picks the neighbouring published projects by date, whether a visitor may see them
	 * or not, and projects have no order to step through. Current WordPress no longer
	 * hooks it by default, but a theme or another plugin may; this takes it off again
	 * where it was hooked at the default priority.
	 */
	public static function head_links(): void {
		if ( is_singular( Post_Types::PROJECT ) ) {
			remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head' );
		}
	}
}
