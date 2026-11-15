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
 * Holds three rules on every save of a project through WordPress's editing routes (the
 * edit screen, Quick Edit, bulk edit, a restore, XML-RPC). The plugin's own writes
 * through Store are left alone: the importer and, later, the survey form set a
 * project's status themselves.
 *
 * - **No project without IRB approval is published, scheduled or made private**
 *   (decided by Eric, 14 November, for publishing; private is WordPress's "privately
 *   published", and an unapproved project has no use for it). Visitors never see one
 *   (Store::is_public()), and the approval is the survey's, which no editor can
 *   change. The save keeps the project a draft or pending, or makes it a draft if it
 *   was public, and keeps its dates and the slug it asked for, so a refused publish
 *   leaves no publish date or renamed slug behind. The edit screen says why at the top
 *   and in the Publish box, Quick Edit refuses with the reason in its own panel, and
 *   the projects list marks each unapproved project.
 * - **A project's address is fixed once it has been published** (rule 5; Eric, 14
 *   November): links to it may have been shared. "Published" is recorded the first
 *   time the project enters or leaves a public or private status (mark_published()),
 *   so a date set on a draft doesn't count. While it's a draft that has never been
 *   published, its slug can change. Trashing and restoring, which rename a slug for
 *   WordPress's own reasons, are left alone. A draft can't take another project's
 *   address (free()), and if another published project holds a project's address, as
 *   when one took it while this one was in the trash, it takes the next free one,
 *   rather than two projects sharing it.
 * - **The post's title is the researcher's**, as Store writes it (Store::label()), so
 *   a restore, Quick Edit or anything else can't make WordPress's lists name a
 *   project wrongly. It's set as WordPress saves the post, never by saving it again.
 *
 * The filter runs late, so another plugin's can't undo the rules. A refusal the screen
 * can't say at once, from bulk edit or a forged request, is said on the next projects
 * list or project screen the editor opens.
 */
final class Project_Rules {

	/**
	 * The statuses that publish a project, to visitors, when it's due, or privately.
	 */
	private const PUBLISHED = array( 'publish', 'future', 'private' );

	/**
	 * The meta that records a project has been published once.
	 */
	public const PUBLISHED_ONCE = '_firehouse_published';

	/**
	 * How long a refusal waits for the screen that says it.
	 */
	private const KEEP = 60 * MINUTE_IN_SECONDS;

	/**
	 * Hooks the rules, the notices, the Publish box, Quick Edit and the list.
	 */
	public static function hook(): void {
		add_filter( 'wp_insert_post_data', array( self::class, 'enforce' ), PHP_INT_MAX - 10, 2 );
		add_action( 'transition_post_status', array( self::class, 'mark_published' ), 10, 3 );
		add_action( 'wp_ajax_inline-save', array( self::class, 'quick_edit' ), 0 );
		add_action( 'admin_notices', array( self::class, 'list_notices' ) );
		add_action( 'edit_form_top', array( self::class, 'form_notices' ), 20 );
		add_action( 'post_submitbox_misc_actions', array( self::class, 'publish_box' ) );
		add_action( 'load-post.php', array( self::class, 'load' ) );
		add_action( 'add_meta_boxes_' . Post_Types::PROJECT, array( self::class, 'boxes' ), 20 );
		add_filter( 'display_post_states', array( self::class, 'states' ), 10, 2 );
		add_filter( 'bulk_post_updated_messages', array( self::class, 'bulk_messages' ), 10, 2 );
	}

	/**
	 * Holds the rules on a project's save.
	 *
	 * WordPress passes $data slashed, so the title is slashed going in, and it has
	 * already trimmed the title and, on an older table, encoded its emoji, so the
	 * researcher's is too.
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
			$data['post_title'] = wp_slash( Store::post_title( $title ) );
		}

		$status = (string) ( $data['post_status'] ?? '' );
		if ( in_array( $status, self::PUBLISHED, true ) && ! self::approved( $id ) ) {
			$was                   = in_array( $stored->post_status, self::PUBLISHED, true );
			$data['post_status']   = in_array( $stored->post_status, array( 'draft', 'pending' ), true ) ? $stored->post_status : 'draft';
			$data['post_date']     = $stored->post_date;
			$data['post_date_gmt'] = $stored->post_date_gmt;
			// WordPress made the slug unique for a published post; a draft's needn't be. Out
			// of the trash, WordPress has already put back the slug it had before.
			if ( 'trash' !== $stored->post_status ) {
				$asked             = sanitize_title( (string) ( $postarr['post_name'] ?? '' ) );
				$data['post_name'] = '' !== $asked && ! self::locked( $stored ) ? self::free( $asked, $stored ) : $stored->post_name;
			}
			self::note( $id, $was ? 'unpublished' : 'irb' );
			return $data;
		}

		if ( 'trash' === $status || 'trash' === $stored->post_status ) {
			return $data;
		}
		$name  = (string) ( $data['post_name'] ?? '' );
		$asked = sanitize_title( (string) ( $postarr['post_name'] ?? '' ) );
		if ( self::locked( $stored ) && $name !== $stored->post_name ) {
			if ( null !== self::holder( $stored->post_name, $id, self::PUBLISHED ) ) {
				// Another published project has the address, as when one took it while
				// this was in the trash: this one takes the next free one, not one the
				// request names. A draft that holds it doesn't count: WordPress makes the
				// draft's slug unique when it's published.
				$data['post_name'] = wp_unique_post_slug( $stored->post_name, $id, 'publish', $stored->post_type, (int) $stored->post_parent );
				self::note( $id, 'taken' );
			} else {
				$data['post_name'] = $stored->post_name;
				if ( '' !== $asked && $asked !== $stored->post_name ) {
					self::note( $id, 'slug' );
				}
			}
		} elseif ( ! self::locked( $stored ) && '' !== $name && $name !== $stored->post_name ) {
			$free = self::free( $name, $stored );
			if ( $free !== $name ) {
				$data['post_name'] = $free;
				self::note( $id, 'taken' );
			}
		}
		return $data;
	}

	/**
	 * A slug a project that has never been published may take: the one it asks for, or,
	 * if another project has it, the next free one, as if publishing. WordPress lets a
	 * draft share a slug, so without this a draft could take a published project's
	 * address, and win it when published.
	 *
	 * @param string   $slug The slug asked for.
	 * @param \WP_Post $post The project.
	 */
	private static function free( string $slug, \WP_Post $post ): string {
		$held = null !== self::holder( $slug, $post->ID, self::PUBLISHED ) || null !== self::holder( $slug, $post->ID, array( 'draft', 'pending' ) );
		return $held ? wp_unique_post_slug( $slug, $post->ID, 'publish', $post->post_type, (int) $post->post_parent ) : $slug;
	}

	/**
	 * Another project in one of $statuses that has a slug, if any. Each group of
	 * statuses is asked for on its own, so a newer draft holding the slug can't come
	 * back in place of the published project that has it.
	 *
	 * @param string   $slug     The slug.
	 * @param int      $id       The project asking, which doesn't count.
	 * @param string[] $statuses The statuses to look in.
	 */
	private static function holder( string $slug, int $id, array $statuses ): ?\WP_Post {
		if ( '' === $slug ) {
			return null;
		}
		// Two at most: the project asking may be one of them.
		$found = get_posts(
			array(
				'post_type'   => Post_Types::PROJECT,
				'name'        => $slug,
				'post_status' => $statuses,
				'numberposts' => 2,
			)
		);
		foreach ( $found as $post ) {
			if ( $post instanceof \WP_Post && $post->ID !== $id ) {
				return $post;
			}
		}
		return null;
	}

	/**
	 * Records that a project has been published, the first time it enters or leaves a
	 * published status, by any route, the importer's and the scheduler's included.
	 * Leaving counts too, so a project published before this was recorded is marked
	 * when it's first unpublished.
	 *
	 * @param string   $new_status The status it's moving to.
	 * @param string   $old_status The status it had.
	 * @param \WP_Post $post       The post.
	 */
	public static function mark_published( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( Post_Types::PROJECT !== $post->post_type || ( ! in_array( $new_status, self::PUBLISHED, true ) && ! in_array( $old_status, self::PUBLISHED, true ) ) ) {
			return;
		}
		if ( '' === get_post_meta( $post->ID, self::PUBLISHED_ONCE, true ) ) {
			update_post_meta( $post->ID, self::PUBLISHED_ONCE, '1' );
		}
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
	 * has been (mark_published()).
	 *
	 * @param \WP_Post $post The project as stored.
	 */
	public static function locked( \WP_Post $post ): bool {
		return in_array( $post->post_status, self::PUBLISHED, true ) || '' !== get_post_meta( $post->ID, self::PUBLISHED_ONCE, true );
	}

	/**
	 * Refuses Quick Edit's publish of a project without IRB approval before WordPress
	 * saves anything, so its panel shows the reason and screen readers hear it, where
	 * WordPress would say "Changes saved." It saves nothing either way; enforce() is the
	 * rule, and this only says it sooner.
	 */
	public static function quick_edit(): void {
		$id   = isset( $_POST['post_ID'] ) && is_scalar( $_POST['post_ID'] ) ? absint( $_POST['post_ID'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only decides whether to refuse with a message; WordPress checks its nonce before it saves anything.
		$post = 0 === $id ? null : get_post( $id );
		if ( ! $post instanceof \WP_Post || Post_Types::PROJECT !== $post->post_type || ! current_user_can( 'edit_post', $id ) || self::approved( $id ) ) {
			return;
		}
		$status  = isset( $_POST['_status'] ) && is_string( $_POST['_status'] ) ? sanitize_key( wp_unslash( $_POST['_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- As above.
		$private = isset( $_POST['keep_private'] ) && 'private' === $_POST['keep_private']; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- As above.
		if ( in_array( $status, self::PUBLISHED, true ) || $private ) {
			wp_die( esc_html( self::reason() . ' ' . __( 'Choose Draft or Pending Review to save its other changes.', 'firehouse' ) ) );
		}
	}

	/**
	 * Why a project without IRB approval can't be published, and what decides it.
	 */
	private static function reason(): string {
		return __( 'This project can’t be published. Its survey record has no IRB approval or exemption, and visitors never see a project without it. Approval comes from the survey record, not this screen.', 'firehouse' );
	}

	/**
	 * Keeps a refusal for the screen that says it.
	 *
	 * @param int    $post_id The project.
	 * @param string $kind    irb, unpublished, slug or taken.
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
	 * On the projects list, says each refusal kept for this editor, once.
	 */
	public static function list_notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen instanceof \WP_Screen && Post_Types::PROJECT === $screen->post_type && 'edit' === $screen->base ) {
			self::say_notes( '' );
		}
	}

	/**
	 * On a project's screen, says each refusal kept for this editor, once, after the
	 * editorial fields' refusal, which takes the focus, so reading on reaches it; and,
	 * for a project without IRB approval, why it can't be published, where Tab and a
	 * screen reader meet it before the fields.
	 *
	 * @param \WP_Post $post The post being edited.
	 */
	public static function form_notices( \WP_Post $post ): void {
		if ( Post_Types::PROJECT !== $post->post_type ) {
			return;
		}
		self::say_notes( ' inline' );
		if ( ! self::approved( $post->ID ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html( self::reason() ) . ' ' . esc_html( in_array( $post->post_status, self::PUBLISHED, true ) ? __( 'Updating it makes it a draft.', 'firehouse' ) : __( 'You can still save it as a draft.', 'firehouse' ) ) . '</p></div>';
		}
	}

	/**
	 * Says each refusal kept for this editor, once: which project, what was kept, and
	 * why. A project the editor may no longer edit is skipped.
	 *
	 * @param string $inline " inline" to keep WordPress's script from moving the notice.
	 */
	private static function say_notes( string $inline ): void {
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
			$title   = get_post_meta( $post->ID, Meta::key( 'title' ), true );
			$name    = is_string( $title ) && '' !== $title ? $title : wp_specialchars_decode( $post->post_title, ENT_QUOTES );
			$message = match ( $note['kind'] ?? '' ) {
				/* translators: %s: the project's title. */
				'irb'         => sprintf( __( '“%s” wasn’t published. Its survey record has no IRB approval or exemption, and visitors never see a project without it. Its other changes were saved.', 'firehouse' ), $name ),
				/* translators: %s: the project's title. */
				'unpublished' => sprintf( __( '“%s” is now a draft. Its survey record has no IRB approval or exemption, and visitors never see a project without it. Its other changes were saved.', 'firehouse' ), $name ),
				/* translators: 1: the project's title, 2: its address. */
				'slug'        => sprintf( __( '“%1$s” keeps its address, %2$s. It can’t change now that the project has been published, since links to it may have been shared. Its other changes were saved.', 'firehouse' ), $name, self::address( $post ) ),
				/* translators: 1: the project's title, 2: its new address. */
				'taken'       => sprintf( __( '“%1$s” can’t use that address: another project has it. Its address is %2$s.', 'firehouse' ), $name, self::address( $post ) ),
				default       => '',
			};
			if ( '' !== $message ) {
				echo '<div class="notice notice-warning' . esc_attr( $inline ) . '"><p>' . esc_html( $message ) . '</p></div>';
			}
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
		if ( ! self::approved( $post->ID ) ) {
			$public = in_array( $post->post_status, self::PUBLISHED, true );
			$head   = $public ? __( 'This project can’t stay published.', 'firehouse' ) : __( 'This project can’t be published.', 'firehouse' );
			$next   = $public ? __( 'Updating it makes it a draft.', 'firehouse' ) : __( 'You can still save it as a draft.', 'firehouse' );
			echo '<div class="misc-pub-section fh-publish-refused"><p><strong>' . esc_html( $head ) . '</strong> ' . esc_html__( 'Its survey record has no IRB approval or exemption. Approval comes from the survey record, not this screen.', 'firehouse' ) . ' ' . esc_html( $next ) . '</p></div>';
		}
		if ( self::locked( $post ) ) {
			echo '<div class="misc-pub-section fh-publish-address">' . esc_html__( 'Address:', 'firehouse' ) . ' <code>' . esc_html( self::address( $post ) ) . '</code><br>' . esc_html__( 'It can’t change now that the project has been published, since links to it may have been shared.', 'firehouse' ) . '</div>';
		}
	}

	/**
	 * On the screen of a project that can't be published, hides what would ask for it:
	 * the Publish button, and the visibility and date settings, which would make it
	 * private or schedule it. Save Draft and Save as Pending stay. The server refuses
	 * them anyway; this keeps the box from offering them, or from leaving no button
	 * once Private is chosen.
	 */
	public static function load(): void {
		$id   = isset( $_GET['post'] ) && is_string( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads which post the screen shows.
		$post = 0 === $id ? null : get_post( $id );
		if ( $post instanceof \WP_Post && Post_Types::PROJECT === $post->post_type && self::unpublishable( $post ) ) {
			wp_add_inline_style( 'common', '#publishing-action,#submitdiv .misc-pub-visibility,#submitdiv .misc-pub-curtime{display:none}' );
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
	 * Marks each project without IRB approval in the projects list, so the list says
	 * it before anyone tries to publish one.
	 *
	 * @param array<string, string> $states The post's states.
	 * @param \WP_Post              $post   The post.
	 * @return array<string, string>
	 */
	public static function states( array $states, \WP_Post $post ): array {
		if ( Post_Types::PROJECT === $post->post_type && ! self::approved( $post->ID ) ) {
			$states['firehouse-no-irb'] = __( 'No IRB approval', 'firehouse' );
		}
		return $states;
	}

	/**
	 * Bulk edit's counts, in a project's words.
	 *
	 * @param array<string, array<string, string>> $messages The messages, by post type.
	 * @param array<string, int>                   $counts   How many were changed, by kind.
	 * @return array<string, array<string, string>>
	 */
	public static function bulk_messages( array $messages, array $counts ): array {
		$n                               = static fn( string $kind ): int => (int) ( $counts[ $kind ] ?? 0 );
		$messages[ Post_Types::PROJECT ] = array(
			/* translators: %s: how many projects. */
			'updated'   => _n( '%s project updated.', '%s projects updated.', $n( 'updated' ), 'firehouse' ),
			/* translators: %s: how many projects. */
			'locked'    => _n( '%s project not updated, somebody is editing it.', '%s projects not updated, somebody is editing them.', $n( 'locked' ), 'firehouse' ),
			/* translators: %s: how many projects. */
			'deleted'   => _n( '%s project permanently deleted.', '%s projects permanently deleted.', $n( 'deleted' ), 'firehouse' ),
			/* translators: %s: how many projects. */
			'trashed'   => _n( '%s project moved to the Trash.', '%s projects moved to the Trash.', $n( 'trashed' ), 'firehouse' ),
			/* translators: %s: how many projects. */
			'untrashed' => _n( '%s project restored from the Trash.', '%s projects restored from the Trash.', $n( 'untrashed' ), 'firehouse' ),
		);
		return $messages;
	}

	/**
	 * Whether the Publish box would offer what the rules refuse: the project is a
	 * draft or pending and has no IRB approval.
	 *
	 * @param \WP_Post $post The project.
	 */
	private static function unpublishable( \WP_Post $post ): bool {
		return ! in_array( $post->post_status, self::PUBLISHED, true ) && ! self::approved( $post->ID );
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
