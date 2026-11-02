<?php
/**
 * The Firehouse → Import screen.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

use Firehouse\Domain\Content_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads a seed bundle in two steps: a dry run that lists what would change and
 * anything wrong, then the import, once the person confirms.
 *
 * The bundle is read from PHP's temporary upload and the file is never stored.
 * Between the dry run and the confirmation, the checked content waits for at most
 * thirty minutes in a transient that belongs to the person who uploaded it, so the
 * import writes exactly what the dry run showed. It is the site's public content; the
 * bundle holds no private answers (rule 2). Only administrators can open the screen.
 */
final class Import_Screen {

	/**
	 * The screen's slug.
	 */
	public const PAGE = 'firehouse-import';

	/**
	 * How long a dry run waits for its confirmation.
	 */
	private const EXPIRES = 30 * MINUTE_IN_SECONDS;

	/**
	 * What the screen shows after a submission: a refusal, a dry run or a result.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $state = null;

	/**
	 * Hooks the screen.
	 */
	public static function hook(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
	}

	/**
	 * Adds the screen under the Firehouse menu, and handles its forms before anything
	 * is drawn.
	 */
	public static function add_page(): void {
		$hook = add_submenu_page(
			Post_Types::MENU,
			__( 'Import Firehouse content', 'firehouse' ),
			__( 'Import', 'firehouse' ),
			Capabilities::IMPORT,
			self::PAGE,
			array( self::class, 'render' )
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, array( self::class, 'handle' ) );
			add_filter( 'admin_title', array( self::class, 'title' ) );
		}
	}

	/**
	 * Handles a submitted form: the dry run of an upload, or the import it confirms.
	 */
	public static function handle(): void {
		if ( ! isset( $_POST['firehouse_step'] ) ) {
			return;
		}
		if ( ! current_user_can( Capabilities::IMPORT ) ) {
			wp_die( esc_html__( 'Only administrators can import Firehouse content.', 'firehouse' ), '', array( 'response' => 403 ) );
		}
		$step = sanitize_key( wp_unslash( $_POST['firehouse_step'] ) );
		if ( 'preview' === $step ) {
			check_admin_referer( 'firehouse_import_preview' );
			self::$state = self::preview();
		} elseif ( 'import' === $step ) {
			check_admin_referer( 'firehouse_import_run' );
			$token       = isset( $_POST['firehouse_token'] ) ? sanitize_text_field( wp_unslash( $_POST['firehouse_token'] ) ) : '';
			self::$state = self::import( $token );
		}
	}

	/**
	 * Says in the browser's title what happened, so a screen reader's first words
	 * do.
	 *
	 * @param string $title The admin page's title.
	 */
	public static function title( string $title ): string {
		$prefix = match ( self::$state['step'] ?? null ) {
			'refused' => __( 'Not imported:', 'firehouse' ),
			'preview' => self::$state['plan']['ok'] ? __( 'Ready to import:', 'firehouse' ) : __( 'Not ready to import:', 'firehouse' ),
			'done'    => self::problem_count( self::$state['plan'] ) > 0 ? __( 'Imported, with problems:', 'firehouse' ) : __( 'Imported:', 'firehouse' ),
			default   => '',
		};
		return '' === $prefix ? $title : $prefix . ' ' . $title;
	}

	/**
	 * Draws the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::IMPORT ) ) {
			return;
		}
		$state = self::$state ?? array( 'step' => 'upload' );
		echo '<div class="wrap">';
		echo '<h1>' . esc_html( get_admin_page_title() ) . '</h1>';

		switch ( $state['step'] ) {
			case 'refused':
				self::summary( 'error', __( 'Not imported: the bundle was refused', 'firehouse' ), array( $state['message'] ) );
				self::upload_form();
				break;
			case 'preview':
				$count = self::problem_count( $state['plan'] );
				if ( $state['plan']['ok'] ) {
					/* translators: %s: the file's name. */
					self::summary( 'info', __( 'Ready to import. Nothing has been written yet.', 'firehouse' ), array( sprintf( __( 'This is what importing %s would change. Check it, then import.', 'firehouse' ), $state['file'] ) ) );
					self::confirm_form( $state['token'] );
				} else {
					/* translators: %d: how many problems. */
					self::summary( 'error', sprintf( _n( 'Not ready to import: %d problem', 'Not ready to import: %d problems', $count, 'firehouse' ), $count ), array( __( 'Nothing has been written. Fix the problems listed below in the site’s content files, make the bundle again with npm run seed, and upload it.', 'firehouse' ) ) );
				}
				self::report( $state['plan'] );
				if ( ! $state['plan']['ok'] ) {
					self::upload_form();
				}
				break;
			case 'done':
				$count = self::problem_count( $state['plan'] );
				if ( ! $state['plan']['imported'] ) {
					self::summary( 'error', __( 'Not imported: WordPress has changed since the dry run', 'firehouse' ), array( __( 'Nothing has been written. Upload the bundle again to see what an import would change now.', 'firehouse' ) ) );
				} elseif ( $count > 0 ) {
					/* translators: %d: how many problems. */
					self::summary( 'error', sprintf( _n( 'Imported, with %d problem', 'Imported, with %d problems', $count, 'firehouse' ), $count ), array( __( 'The problems are listed below. Importing the same bundle again is safe: it updates the same posts.', 'firehouse' ) ) );
				} else {
					self::summary( 'success', __( 'Imported', 'firehouse' ), array( __( 'Every project and topic below was written and read back as imported.', 'firehouse' ) ) );
				}
				self::report( $state['plan'] );
				break;
			default:
				echo '<p>' . esc_html__( 'Loads the site’s content from a seed bundle: its projects, topics, syntheses and settings. You see what it would change before anything is written, and importing the same bundle again updates the same posts.', 'firehouse' ) . '</p>';
				self::upload_form();
		}
		echo '</div>';
	}

	/**
	 * The dry run of an uploaded bundle.
	 *
	 * @return array<string, mixed>
	 */
	private static function preview(): array {
		$file = $_FILES['firehouse_seed'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- handle() checked the nonce; each field is checked below, and the name is shown escaped.
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== ( $file['error'] ?? null ) || ! is_string( $file['tmp_name'] ?? null ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return self::refused( __( 'No file arrived. Choose the seed bundle, then preview it.', 'firehouse' ) );
		}
		if ( (int) ( $file['size'] ?? 0 ) > Seed_Bundle::MAX_BYTES ) {
			/* translators: %d: the largest size, in KB. */
			return self::refused( sprintf( __( 'The file is larger than a seed bundle can be (%d KB).', 'firehouse' ), Seed_Bundle::MAX_BYTES / 1024 ) );
		}

		$json = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- PHP's temporary upload, read once and never stored.
		try {
			$bundle = Seed_Bundle::read( is_string( $json ) ? $json : '' );
		} catch ( Content_Error $e ) {
			// The message was escaped when it was made, and esc_html() won't escape it twice.
			return self::refused( $e->getMessage() );
		}

		$token = wp_generate_password( 32, false );
		set_transient(
			self::transient(),
			array(
				'token'  => $token,
				'bundle' => $bundle,
			),
			self::EXPIRES
		);
		return array(
			'step'  => 'preview',
			'plan'  => Importer::preview( $bundle['content'], $bundle['notes'] ),
			'token' => $token,
			'file'  => sanitize_file_name( is_string( $file['name'] ?? null ) ? $file['name'] : '' ),
		);
	}

	/**
	 * The import a dry run waits for, if the token is that dry run's.
	 *
	 * @param string $token The token from the confirmation form.
	 * @return array<string, mixed>
	 */
	private static function import( string $token ): array {
		$waiting = get_transient( self::transient() );
		if ( ! is_array( $waiting ) || ! is_string( $waiting['token'] ?? null ) || '' === $token || ! hash_equals( $waiting['token'], $token ) || ! is_array( $waiting['bundle'] ?? null ) ) {
			return self::refused( __( 'That dry run has expired, or was already imported. Upload the bundle again.', 'firehouse' ) );
		}
		delete_transient( self::transient() );
		return array(
			'step' => 'done',
			'plan' => Importer::run( $waiting['bundle']['content'], $waiting['bundle']['notes'] ),
		);
	}

	/**
	 * A refusal, to show.
	 *
	 * @param string $message Why.
	 * @return array{step: string, message: string}
	 */
	private static function refused( string $message ): array {
		return array(
			'step'    => 'refused',
			'message' => $message,
		);
	}

	/**
	 * The transient holding the current user's dry run.
	 */
	private static function transient(): string {
		return 'firehouse_import_' . get_current_user_id();
	}

	/**
	 * Counts the problems in a plan.
	 *
	 * @param array<string, mixed> $plan The plan.
	 */
	private static function problem_count( array $plan ): int {
		$count = count( $plan['settings']['problems'] );
		foreach ( array( 'projects', 'topics' ) as $list ) {
			foreach ( $plan[ $list ] as $entry ) {
				$count += count( $entry['problems'] );
			}
		}
		return $count;
	}

	/**
	 * The notice at the top of the result, which takes the focus when the page loads.
	 *
	 * @param string   $type       success, info or error.
	 * @param string   $heading    What happened.
	 * @param string[] $paragraphs What to do next.
	 */
	private static function summary( string $type, string $heading, array $paragraphs ): void {
		printf( '<div id="firehouse-import-summary" class="notice notice-%s" tabindex="-1" autofocus>', esc_attr( $type ) );
		echo '<h2>' . esc_html( $heading ) . '</h2>';
		foreach ( $paragraphs as $paragraph ) {
			echo '<p>' . esc_html( $paragraph ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * The upload form.
	 */
	private static function upload_form(): void {
		?>
		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'firehouse_import_preview' ); ?>
			<input type="hidden" name="firehouse_step" value="preview" />
			<p>
				<label for="firehouse-seed"><?php esc_html_e( 'Seed bundle', 'firehouse' ); ?></label><br />
				<input type="file" id="firehouse-seed" name="firehouse_seed" accept=".json,application/json" required aria-describedby="firehouse-seed-description" />
			</p>
			<p class="description" id="firehouse-seed-description"><?php esc_html_e( 'The JSON file npm run seed makes from the site’s content files. It is read from this upload and not kept, and nothing is written until you confirm.', 'firehouse' ); ?></p>
			<?php submit_button( __( 'Preview the import', 'firehouse' ), 'secondary' ); ?>
		</form>
		<?php
	}

	/**
	 * The confirmation form, for a dry run with no problems.
	 *
	 * @param string $token The dry run's token.
	 */
	private static function confirm_form( string $token ): void {
		?>
		<form method="post">
			<?php wp_nonce_field( 'firehouse_import_run' ); ?>
			<input type="hidden" name="firehouse_step" value="import" />
			<input type="hidden" name="firehouse_token" value="<?php echo esc_attr( $token ); ?>" />
			<p>
				<?php submit_button( __( 'Import', 'firehouse' ), 'primary', 'submit', false ); ?>
				<a class="button" href="<?php echo esc_url( admin_url( Post_Types::MENU . '&page=' . self::PAGE ) ); ?>"><?php esc_html_e( 'Cancel', 'firehouse' ); ?></a>
			</p>
		</form>
		<?php
	}

	/**
	 * What a plan does: projects, topics, settings, and what it leaves alone.
	 *
	 * @param array<string, mixed> $plan The plan, or a run's result.
	 */
	private static function report( array $plan ): void {
		$actions = array(
			'create'    => __( 'Add', 'firehouse' ),
			'update'    => __( 'Update', 'firehouse' ),
			'unchanged' => __( 'No change', 'firehouse' ),
		);

		echo '<h2>' . esc_html__( 'Projects', 'firehouse' ) . '</h2>';
		echo '<table class="widefat striped"><caption class="screen-reader-text">' . esc_html__( 'Projects in the bundle', 'firehouse' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Project', 'firehouse' ), __( 'Change', 'firehouse' ), __( 'Status', 'firehouse' ), __( 'Fields', 'firehouse' ), __( 'Problems and notes', 'firehouse' ) ) as $column ) {
			echo '<th scope="col">' . esc_html( $column ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $plan['projects'] as $project ) {
			echo '<tr><th scope="row">' . esc_html( $project['title'] ) . '<br /><code>' . esc_html( $project['slug'] ) . '</code></th>';
			echo '<td>' . esc_html( $actions[ $project['action'] ] ) . '</td>';
			echo '<td>' . esc_html( 'publish' === $project['status'] ? __( 'Published', 'firehouse' ) : __( 'Draft', 'firehouse' ) ) . '</td>';
			echo '<td>' . esc_html( 'create' === $project['action'] ? __( 'All', 'firehouse' ) : implode( ', ', $project['changes'] ) ) . '</td>';
			echo '<td>';
			self::messages( $project['problems'], $project['notes'] );
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		if ( array() !== $plan['left_alone'] ) {
			echo '<p>' . esc_html__( 'In WordPress but not in the bundle, and left as they are:', 'firehouse' ) . '</p><ul class="ul-disc">';
			foreach ( $plan['left_alone'] as $project ) {
				echo '<li>' . esc_html( $project['title'] ) . ' (<code>' . esc_html( $project['slug'] ) . '</code>)</li>';
			}
			echo '</ul>';
		}

		echo '<h2>' . esc_html__( 'Topics', 'firehouse' ) . '</h2>';
		echo '<table class="widefat striped"><caption class="screen-reader-text">' . esc_html__( 'Topics and their syntheses', 'firehouse' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Topic', 'firehouse' ), __( 'Change', 'firehouse' ), __( 'Fields', 'firehouse' ), __( 'Problems and notes', 'firehouse' ) ) as $column ) {
			echo '<th scope="col">' . esc_html( $column ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $plan['topics'] as $topic ) {
			echo '<tr><th scope="row">' . esc_html( $topic['label'] ) . '<br /><code>' . esc_html( $topic['key'] ) . '</code></th>';
			echo '<td>' . esc_html( $actions[ $topic['action'] ] ) . '</td>';
			echo '<td>' . esc_html( 'create' === $topic['action'] ? __( 'All', 'firehouse' ) : implode( ', ', $topic['changes'] ) ) . '</td>';
			echo '<td>';
			self::messages( $topic['problems'], $topic['notes'] );
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Settings', 'firehouse' ) . '</h2>';
		if ( 'unchanged' === $plan['settings']['action'] && array() === $plan['settings']['problems'] ) {
			echo '<p>' . esc_html__( 'No change.', 'firehouse' ) . '</p>';
		} else {
			if ( array() !== $plan['settings']['changes'] ) {
				/* translators: %s: the settings that change. */
				echo '<p>' . esc_html( sprintf( __( 'Changes: %s.', 'firehouse' ), implode( ', ', $plan['settings']['changes'] ) ) ) . '</p>';
			}
			self::messages( $plan['settings']['problems'], array() );
		}

		foreach ( $plan['notes'] as $note ) {
			echo '<p>' . esc_html( $note ) . '</p>';
		}
	}

	/**
	 * Problems and notes, each labelled in words rather than by colour alone.
	 *
	 * @param string[] $problems What stops the import.
	 * @param string[] $notes    What doesn't.
	 */
	private static function messages( array $problems, array $notes ): void {
		if ( array() === $problems && array() === $notes ) {
			return;
		}
		echo '<ul>';
		foreach ( $problems as $problem ) {
			echo '<li><strong>' . esc_html__( 'Problem:', 'firehouse' ) . '</strong> ' . esc_html( $problem ) . '</li>';
		}
		foreach ( $notes as $note ) {
			echo '<li>' . esc_html__( 'Note:', 'firehouse' ) . ' ' . esc_html( $note ) . '</li>';
		}
		echo '</ul>';
	}
}
