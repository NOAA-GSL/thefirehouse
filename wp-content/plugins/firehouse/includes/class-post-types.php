<?php
/**
 * The project and topic post types.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers fh_project and fh_topic at the React site's addresses under /firehouse/,
 * so a link shared from the bridge resolves unchanged:
 *
 * - /firehouse/projects/ is the project archive, and /firehouse/projects/{slug}/ a
 *   project.
 * - /firehouse/topics/{key}/ is one of the four topics. There is no topic archive.
 *
 * Neither is in the REST API: nothing the pages or screens do needs it, so no draft
 * or field can reach it. Each has its own capabilities, so the Firehouse Editor role
 * can edit them without being able to edit the site's posts.
 */
final class Post_Types {

	/**
	 * The project post type.
	 */
	public const PROJECT = 'fh_project';

	/**
	 * The topic post type.
	 */
	public const TOPIC = 'fh_topic';

	/**
	 * The admin menu that projects, topics and the Firehouse screens sit under.
	 */
	public const MENU = 'edit.php?post_type=' . self::PROJECT;

	/**
	 * Hooks the registration.
	 */
	public static function hook(): void {
		add_action( 'init', array( self::class, 'register' ) );
	}

	/**
	 * Registers both post types, then their fields.
	 */
	public static function register(): void {
		register_post_type( self::PROJECT, self::project_args() );
		register_post_type( self::TOPIC, self::topic_args() );
		Meta::register();
	}

	/**
	 * The project post type's arguments.
	 *
	 * A draft is an unpublished project, and the slug is the project's address. Every
	 * field is post meta, the researcher's title included (rule 1); the post's own
	 * title is a label for WordPress's lists, which Store keeps in step. So there is no
	 * title box or block editor: a project's fields get their own screen in Phase 6.
	 * Projects arrive through the Import screen, and later the survey form, so no one
	 * adds one here. The archive shows every project at once, so it has no pages, and
	 * no feed is asked for.
	 *
	 * @return array<string, mixed>
	 */
	public static function project_args(): array {
		return array(
			'labels'           => array(
				'name'               => __( 'Projects', 'firehouse' ),
				'singular_name'      => __( 'Project', 'firehouse' ),
				'menu_name'          => __( 'Firehouse', 'firehouse' ),
				'all_items'          => __( 'Projects', 'firehouse' ),
				'edit_item'          => __( 'Edit project', 'firehouse' ),
				'view_item'          => __( 'View project', 'firehouse' ),
				'view_items'         => __( 'View projects', 'firehouse' ),
				'search_items'       => __( 'Search projects', 'firehouse' ),
				'not_found'          => __( 'No projects found.', 'firehouse' ),
				'not_found_in_trash' => __( 'No projects found in the trash.', 'firehouse' ),
			),
			'description'      => __( 'A research project submitted through the Firehouse survey.', 'firehouse' ),
			'public'           => true,
			'show_in_rest'     => false,
			'has_archive'      => 'firehouse/projects',
			'rewrite'          => array(
				'slug'       => 'firehouse/projects',
				'with_front' => false,
				'feeds'      => false,
				'pages'      => false,
			),
			'supports'         => array( 'revisions' ),
			'capability_type'  => array( 'fh_project', 'fh_projects' ),
			'capabilities'     => array( 'create_posts' => 'do_not_allow' ),
			'map_meta_cap'     => true,
			'menu_icon'        => 'dashicons-clipboard',
			'menu_position'    => 26,
			'delete_with_user' => false,
		);
	}

	/**
	 * The topic post type's arguments.
	 *
	 * There are exactly four topics, with the topic key as the slug, created by the
	 * Import screen. No one can add one here, or delete or trash one, or change its key
	 * or status (Capabilities): a fifth topic is a schema change, not an edit. Its label,
	 * the post's title, can be edited.
	 *
	 * @return array<string, mixed>
	 */
	public static function topic_args(): array {
		return array(
			'labels'           => array(
				'name'          => __( 'Topics', 'firehouse' ),
				'singular_name' => __( 'Topic', 'firehouse' ),
				'all_items'     => __( 'Topics', 'firehouse' ),
				'edit_item'     => __( 'Edit topic', 'firehouse' ),
				'view_item'     => __( 'View topic', 'firehouse' ),
				'view_items'    => __( 'View topics', 'firehouse' ),
				'search_items'  => __( 'Search topics', 'firehouse' ),
				'not_found'     => __( 'No topics found.', 'firehouse' ),
			),
			'description'      => __( 'One of the four topic areas, with its synthesis of needs.', 'firehouse' ),
			'public'           => true,
			'show_in_rest'     => false,
			'show_in_menu'     => self::MENU,
			'has_archive'      => false,
			'rewrite'          => array(
				'slug'       => 'firehouse/topics',
				'with_front' => false,
				'feeds'      => false,
				'pages'      => false,
			),
			'supports'         => array( 'title', 'revisions' ),
			'capability_type'  => array( 'fh_topic', 'fh_topics' ),
			'capabilities'     => array( 'create_posts' => 'do_not_allow' ),
			'map_meta_cap'     => true,
			'delete_with_user' => false,
		);
	}
}
