<?php
/**
 * What a save may change on a project.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds three rules on every save of a project, by any route (the edit screen, Quick
 * Edit, bulk edit, a restore, XML-RPC), except the plugin's own writes through Store:
 *
 * - **No project without IRB approval is published or scheduled** (decided by Eric,
 *   14 November). Visitors never see one (Store::is_public()), and the approval is the
 *   survey's, which no editor can change. The save keeps the status the project had,
 *   or makes it a draft if it had been public, and keeps its dates, so a refused
 *   publish leaves no sign it was asked for. The edit screen says why in place of the
 *   Publish button.
 * - **A project's address is fixed once it has been published** (rule 5; Eric, 14
 *   November): links to it may have been shared. While it's a draft that has never
 *   been published, its slug can change. Trashing and restoring, which rename a slug
 *   for WordPress's own reasons, are left alone.
 * - **The post's title is the researcher's**, as Store writes it (Store::label()), so
 *   a restore, Quick Edit or anything else can't make WordPress's lists name a
 *   project wrongly. It's set as WordPress saves the post, never by saving it again.
 *
 * A refusal is said on the next project screen or projects list the editor opens,
 * since Quick Edit and bulk edit have nowhere to say it at once.
 */
final class Project_Rules {

	/**
	 * The statuses that put a project in front of visitors, now or when it's due.
	 */
	private const PUBLIC_STATUSES = array( 'publish', 'future' );

	/**
	 * How long a refusal waits for the screen that says it.
	 */
	private const KEEP = 5 * MINUTE_IN_SECONDS;

	/**
	 * Hooks the rules, the notices, and the Publish box.
	 */
	public static function hook(): void {
		add_filter( 'wp_insert_post_data', array( self::class, 'enforce' ), 20, 2 );
		add_action( 'admin_notices', array( self::class, 'notices' ) );
		add_action( 'post_submitbox_misc_actions', array( self::class, 'publish_box' ) );
		add_action( 'load-post.php', array( self::class, 'load' ) );
		add_action( 'add_meta_boxes_' . Post_Types::PROJECT, array( self::class, 'boxes' ), 20 );
	}

	/**
	 * Holds the rules on a project's save.
	 *
	 * WordPress passes $data slashed, so the title is slashed going in.
	 *
	 * @param array<string, mixed> $data    The post's fields as they will be saved, slashed.
	 * @param array<string, mixed> $postarr The post as given.
	 * @return array<string, mixed>
	 */
	public static function enforce( array $data, array $postarr ): array {
		$id     = (int) ( $postarr['ID'] ?? 0 );
		$stored = 0 === $id ? null : get_post( $id );
		if ( Store::writing() || ! $stored instanceof \WP_Post || Post_Types::PROJECT !== $stored->post_type ) {
			return $data;
		}

		$title = get_post_meta( $id, Meta::key( 'title' ), true );
		if ( is_string( $title ) && '' !== $title ) {
			$data['post_title'] = wp_slash( Store::label( $title ) );
		}

		$status = (string) ( $data['post_status'] ?? '' );
		if ( in_array( $status, self::PUBLIC_STATUSES, true ) && ! self::approved( $id ) ) {
			$data['post_status']   = in_array( $stored->post_status, self::PUBLIC_STATUSES, true ) ? 'draft' : $stored->post_status;
			$data['post_date']     = $stored->post_date;
			$data['post_date_gmt'] = $stored->post_date_gmt;
			self::note( $id, 'irb' );
		}

		$trashing = 'trash' === $data['post_status'] || 'trash' === $stored->post_status;
		if ( ! $trashing && self::locked( $stored ) && ( $data['post_name'] ?? '' ) !== $stored->post_name ) {
			$data['post_name'] = $stored->post_name;
			self::note( $id, 'slug' );
		}
		return $data;
	}

	/**
	 * Whether a project's survey record has IRB approval or exemption, as visitors'
	 * pages read it (Store::is_public()).
	 *
	 * @param int $post_id The project.
	 */
	public static function approved( int $post_id ): bool {
		return true === Meta::typed( get_post_meta( $post_id, Meta::key( 'irbApproved' ), true ), 'boolean' );
	}

	/**
	 * Whether a project's address is fixed: it's published, scheduled or private, or
	 * has been, which WordPress records as a date in GMT. A draft that has never been
	 * published has none, since its date floats until it is.
	 *
	 * @param \WP_Post $post The project as stored.
	 */
	public static function locked( \WP_Post $post ): bool {
		return in_array( $post->post_status, array( 'publish', 'future', 'private' ), true )
			|| ( '' !== $post->post_date_gmt && '0000-00-00 00:00:00' !== $post->post_date_gmt );
	}

	/**
	 * Keeps a refusal for the screen that says it.
	 *
	 * @param int    $post_id The project.
	 * @param string $kind    irb or slug.
	 */
	private static function note( int $post_id, string $kind ): void {
		$key                             = self::transient();
		$notes                           = get_transient( $key );
		$notes                           = is_array( $notes ) ? $notes : array();
		$notes[ $post_id . ':' . $kind ] = array(
			'post' => $post_id,
			'kind' => $kind,
		);
		set_transient( $key, $notes, self::KEEP );
	}

	/**
	 * On the projects list or a project's screen, says each refusal kept for this
	 * editor, once: which project, what was kept, and why.
	 */
	public static function notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof \WP_Screen || Post_Types::PROJECT !== $screen->post_type || ! in_array( $screen->base, array( 'edit', 'post' ), true ) ) {
			return;
		}
		$key   = self::transient();
		$notes = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $notes ) ) {
			return;
		}
		foreach ( $notes as $note ) {
			$post = is_array( $note ) ? get_post( (int) ( $note['post'] ?? 0 ) ) : null;
			if ( ! $post instanceof \WP_Post || Post_Types::PROJECT !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$title = get_post_meta( $post->ID, Meta::key( 'title' ), true );
			$name  = is_string( $title ) && '' !== $title ? $title : wp_specialchars_decode( $post->post_title, ENT_QUOTES );
			if ( 'irb' === $note['kind'] ) {
				/* translators: %s: the project's title. */
				$message = sprintf( __( '“%s” wasn’t published. Its survey record has no IRB approval or exemption, and visitors never see a project without it. Its other changes were saved.', 'firehouse' ), $name );
			} else {
				/* translators: 1: the project's title, 2: its address. */
				$message = sprintf( __( '“%1$s” keeps its address, %2$s. A project’s address can’t change once it has been published, since links to it may have been shared. Its other changes were saved.', 'firehouse' ), $name, self::address( $post ) );
			}
			echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	/**
	 * In the Publish box: why a project without IRB approval can't be published, in
	 * place of the Publish button, which load() hides; and a fixed address, read-only,
	 * in place of the Slug box, which boxes() removes.
	 *
	 * @param \WP_Post $post The post being edited.
	 */
	public static function publish_box( \WP_Post $post ): void {
		if ( Post_Types::PROJECT !== $post->post_type ) {
			return;
		}
		if ( self::unpublishable( $post ) ) {
			echo '<div class="misc-pub-section fh-publish-refused"><p><strong>' . esc_html__( 'This project can’t be published.', 'firehouse' ) . '</strong> ' . esc_html__( 'Its survey record has no IRB approval or exemption, and visitors never see a project without it. You can still save it as a draft.', 'firehouse' ) . '</p></div>';
		}
		if ( self::locked( $post ) ) {
			echo '<div class="misc-pub-section fh-publish-address">' . esc_html__( 'Address:', 'firehouse' ) . ' <code>' . esc_html( self::address( $post ) ) . '</code><br>' . esc_html__( 'Fixed once published, since links to it may have been shared.', 'firehouse' ) . '</div>';
		}
	}

	/**
	 * On a project's edit screen, hides the Publish button when the project can't be
	 * published, so it isn't offered; the server refuses it anyway.
	 */
	public static function load(): void {
		$id   = isset( $_GET['post'] ) && is_string( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads which post the screen shows.
		$post = 0 === $id ? null : get_post( $id );
		if ( $post instanceof \WP_Post && Post_Types::PROJECT === $post->post_type && self::unpublishable( $post ) ) {
			wp_add_inline_style( 'common', '#publishing-action{display:none}' );
		}
	}

	/**
	 * Removes the Slug box from a project whose address is fixed; the Publish box
	 * shows the address instead.
	 *
	 * @param \WP_Post $post The post being edited.
	 */
	public static function boxes( \WP_Post $post ): void {
		if ( self::locked( $post ) ) {
			remove_meta_box( 'slugdiv', Post_Types::PROJECT, 'normal' );
		}
	}

	/**
	 * Whether the Publish button would ask for something the rules refuse: the project
	 * isn't public yet and has no IRB approval.
	 *
	 * @param \WP_Post $post The project.
	 */
	private static function unpublishable( \WP_Post $post ): bool {
		return ! in_array( $post->post_status, self::PUBLIC_STATUSES, true ) && ! self::approved( $post->ID );
	}

	/**
	 * A project's address on the site, as a path.
	 *
	 * @param \WP_Post $post The project.
	 */
	private static function address( \WP_Post $post ): string {
		return '/' . Query::ROOT_PATH . '/projects/' . $post->post_name . '/';
	}

	/**
	 * The transient that keeps refusals for the current editor.
	 */
	private static function transient(): string {
		return 'firehouse_project_notes_' . get_current_user_id();
	}
}
