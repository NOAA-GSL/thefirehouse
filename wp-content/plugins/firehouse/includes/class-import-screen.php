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
 * The bundle is read from PHP's temporary upload and the file is never saved. After a
 * dry run with no problems, the parts of the checked content the import writes
 * (projects, topics, syntheses and settings) wait for at most thirty minutes in a
 * transient keyed to the uploader's user ID, with a fingerprint of the plan they saw.
 * The confirmation plans again against WordPress as it is then: if anything would
 * differ from what was shown, it shows the new dry run instead of importing. Opening
 * the screen afresh, or importing, clears the transient. Only administrators can open
 * the screen.
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
	 * The content blocks the import writes, and so the only ones kept between steps.
	 */
	private const KEPT = array( 'projects', 'topics', 'topicSummaries', 'settings', 'landing', 'about' );

	/**
	 * The settings the import reads, and so the only ones kept between steps.
	 */
	private const KEPT_SETTINGS = array( 'siteName', 'submitLabel', 'submitFormUrl', 'nav' );

	/**
	 * A focus ring for the scrolling table regions: WordPress's admin styles remove
	 * the browser's from every div.
	 */
	private const STYLE = '.firehouse-table-region:focus{outline:2px solid var(--wp-admin-theme-color,#2271b1);outline-offset:2px}';

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
			__( 'Import FireHouse content', 'firehouse' ),
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
	 * Opening the screen without one starts afresh, so a dry run left waiting goes.
	 */
	public static function handle(): void {
		if ( ! current_user_can( Capabilities::IMPORT ) ) {
			wp_die( esc_html__( 'Only administrators can import FireHouse content.', 'firehouse' ), '', array( 'response' => 403 ) );
		}
		wp_add_inline_style( 'common', self::STYLE );
		if ( ! isset( $_POST['firehouse_step'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only checks whether a form was sent; each form's nonce is checked below.
			delete_transient( self::transient() );
			return;
		}
		$step = sanitize_key( wp_unslash( $_POST['firehouse_step'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Each step checks its nonce next.
		if ( 'preview' === $step ) {
			check_admin_referer( 'firehouse_import_preview' );
			self::$state = self::preview();
		} elseif ( 'import' === $step ) {
			check_admin_referer( 'firehouse_import_run' );
			$token       = isset( $_POST['firehouse_token'] ) && is_string( $_POST['firehouse_token'] ) ? sanitize_text_field( wp_unslash( $_POST['firehouse_token'] ) ) : '';
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
		$state  = self::$state;
		$prefix = match ( true ) {
			null === $state                                     => '',
			'refused' === $state['step'] || ! empty( $state['changed'] ) => __( 'Not imported:', 'firehouse' ),
			'claimed' === $state['step']                        => __( 'Already confirmed:', 'firehouse' ),
			'preview' === $state['step'] && $state['plan']['ok'] => __( 'Ready to import:', 'firehouse' ),
			'preview' === $state['step']                        => __( 'Not ready to import:', 'firehouse' ),
			! $state['plan']['imported']                        => __( 'Not imported:', 'firehouse' ),
			self::problem_count( $state['plan'] ) > 0           => __( 'Imported, with problems:', 'firehouse' ),
			default                                             => __( 'Imported:', 'firehouse' ),
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
			case 'claimed':
				self::summary( 'info', __( 'This dry run was already confirmed', 'firehouse' ), array( __( 'Another confirmation of it, such as the first of a double click, is importing it or has imported it. Open this screen again to see what WordPress now holds, or upload the bundle to see what another import would change.', 'firehouse' ) ) );
				self::upload_form();
				break;
			case 'preview':
				self::preview_summary( $state );
				self::report( $state['plan'] );
				if ( $state['plan']['ok'] ) {
					self::confirm_form( $state['token'] );
				} else {
					self::upload_form();
				}
				break;
			case 'done':
				self::done_summary( $state['plan'] );
				self::report( $state['plan'] );
				echo '<p><a class="button button-primary" href="' . esc_url( admin_url( Post_Types::MENU ) ) . '">' . esc_html__( 'View projects', 'firehouse' ) . '</a> ';
				echo '<a class="button" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Import another bundle', 'firehouse' ) . '</a></p>';
				break;
			default:
				echo '<p>' . esc_html__( 'Loads the site’s content from a seed bundle: its projects, topics, syntheses and settings. You see what it would change before anything is written. An import adds what is missing and refreshes each project’s survey answers; topics, settings and each project’s editorial fields that already exist in WordPress are kept.', 'firehouse' ) . '</p>';
				self::upload_form();
		}
		echo '</div>';
	}

	/**
	 * The parts of a bundle's content the import writes, which a dry run keeps for its
	 * confirmation: the projects, topics and syntheses, the settings it sets, and the
	 * landing and About pages' words. Nothing else is kept.
	 *
	 * @param array<string, mixed> $content The bundle's content, normalized.
	 * @return array<string, mixed>
	 */
	public static function kept( array $content ): array {
		$kept = array_intersect_key( $content, array_flip( self::KEPT ) );
		if ( is_array( $kept['settings'] ?? null ) ) {
			$kept['settings'] = array_intersect_key( $kept['settings'], array_flip( self::KEPT_SETTINGS ) );
		}
		return $kept;
	}

	/**
	 * The dry run of an uploaded bundle. Only a dry run with no problems is kept for
	 * its confirmation.
	 *
	 * @return array<string, mixed>
	 */
	private static function preview(): array {
		delete_transient( self::transient() );
		$file = $_FILES['firehouse_seed'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- handle() checked the nonce; each field is checked below, and the name is shown escaped.
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== ( $file['error'] ?? null ) || ! is_string( $file['tmp_name'] ?? null ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return self::refused( __( 'No file arrived. Choose the seed bundle, then preview it.', 'firehouse' ) );
		}
		if ( (int) ( $file['size'] ?? 0 ) > Seed_Bundle::MAX_BYTES ) {
			/* translators: %d: the largest size, in KB. */
			return self::refused( sprintf( __( 'The file is larger than a seed bundle can be (%d KB).', 'firehouse' ), Seed_Bundle::MAX_BYTES / 1024 ) );
		}

		$json = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- PHP's temporary upload, read once and never saved.
		try {
			$bundle = Seed_Bundle::read( is_string( $json ) ? $json : '' );
		} catch ( Content_Error $e ) {
			return self::refused( self::without_prefix( $e->getMessage() ) );
		}
		$bundle['content'] = self::kept( $bundle['content'] );

		$state = array(
			'step' => 'preview',
			'plan' => Importer::preview( $bundle['content'], $bundle['notes'], $bundle['problems'] ),
			'file' => sanitize_file_name( is_string( $file['name'] ?? null ) ? $file['name'] : '' ),
		);
		return self::keep( $state, $bundle );
	}

	/**
	 * The import a dry run waits for, if the token is that dry run's and WordPress
	 * hasn't changed what it would do; otherwise the dry run as things stand now.
	 *
	 * @param string $token The token from the confirmation form.
	 * @return array<string, mixed>
	 */
	private static function import( string $token ): array {
		$waiting = get_transient( self::transient() );
		if ( ! is_array( $waiting ) || ! is_string( $waiting['token'] ?? null ) || '' === $token || ! hash_equals( $waiting['token'], $token ) || ! is_array( $waiting['bundle'] ?? null ) ) {
			delete_transient( self::transient() );
			return self::refused( __( 'That dry run has expired, or was already imported. Upload the bundle again.', 'firehouse' ) );
		}
		$bundle = $waiting['bundle'];
		$plan   = Importer::preview( $bundle['content'], $bundle['notes'], $bundle['problems'] );
		if ( ! hash_equals( (string) ( $waiting['fingerprint'] ?? '' ), Importer::fingerprint( $plan ) ) ) {
			$state = array(
				'step'    => 'preview',
				'plan'    => $plan,
				'file'    => (string) ( $waiting['file'] ?? '' ),
				'changed' => true,
			);
			return self::keep( $state, $bundle );
		}

		// Only the request whose delete removes the dry run imports it: a second
		// confirmation sent at the same time, as a double click sends, finds it gone,
		// and says so without claiming the import failed, since the first one ran.
		if ( ! delete_transient( self::transient() ) ) {
			return array( 'step' => 'claimed' );
		}
		// The run plans again; if that plan isn't the one just checked, as an upload in
		// between could make it, it imports nothing, and the new dry run is shown.
		$plan = Importer::run( $bundle['content'], $bundle['notes'], $bundle['problems'], (string) $waiting['fingerprint'] );
		if ( ! empty( $plan['changed'] ) ) {
			$state = array(
				'step'    => 'preview',
				'plan'    => $plan,
				'file'    => (string) ( $waiting['file'] ?? '' ),
				'changed' => true,
			);
			return self::keep( $state, $bundle );
		}
		return array(
			'step' => 'done',
			'plan' => $plan,
		);
	}

	/**
	 * Keeps a dry run with no problems for its confirmation, with a new token; drops
	 * any other.
	 *
	 * @param array<string, mixed> $state  The dry run to show.
	 * @param array<string, mixed> $bundle The bundle it checked.
	 * @return array<string, mixed> The state, with its token.
	 */
	private static function keep( array $state, array $bundle ): array {
		if ( ! $state['plan']['ok'] ) {
			delete_transient( self::transient() );
			return $state;
		}
		$state['token'] = wp_generate_password( 32, false );
		set_transient(
			self::transient(),
			array(
				'token'       => $state['token'],
				'fingerprint' => Importer::fingerprint( $state['plan'] ),
				'file'        => $state['file'],
				'bundle'      => $bundle,
			),
			self::EXPIRES
		);
		return $state;
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
	 * A refusal's message without the "[content] " the domain puts first, which is
	 * for logs, not people.
	 *
	 * @param string $message The message.
	 */
	private static function without_prefix( string $message ): string {
		return str_starts_with( $message, '[content] ' ) ? substr( $message, strlen( '[content] ' ) ) : $message;
	}

	/**
	 * The transient holding the current user's dry run.
	 */
	private static function transient(): string {
		return 'firehouse_import_' . get_current_user_id();
	}

	/**
	 * The screen's address.
	 */
	private static function url(): string {
		return admin_url( Post_Types::MENU . '&page=' . self::PAGE );
	}

	/**
	 * Every problem in a plan, each with where it is on the screen.
	 *
	 * @param array<string, mixed> $plan The plan.
	 * @return list<array{0: string, 1: string}> The anchor and the problem.
	 */
	private static function problems( array $plan ): array {
		$found = array();
		foreach ( $plan['problems'] as $problem ) {
			$found[] = array( 'firehouse-import-bundle', $problem );
		}
		foreach ( $plan['projects'] as $index => $project ) {
			foreach ( $project['problems'] as $problem ) {
				$found[] = array( 'firehouse-import-project-' . $index, $project['title'] . ': ' . $problem );
			}
		}
		foreach ( $plan['topics'] as $topic ) {
			foreach ( $topic['problems'] as $problem ) {
				$found[] = array( 'firehouse-import-topic-' . $topic['key'], $topic['label'] . ': ' . $problem );
			}
		}
		foreach ( $plan['settings']['problems'] as $problem ) {
			$found[] = array( 'firehouse-import-settings', __( 'Settings', 'firehouse' ) . ': ' . $problem );
		}
		foreach ( $plan['landing']['problems'] as $problem ) {
			$found[] = array( 'firehouse-import-landing', __( 'Landing page', 'firehouse' ) . ': ' . $problem );
		}
		foreach ( $plan['about']['problems'] as $problem ) {
			$found[] = array( 'firehouse-import-about', __( 'About page', 'firehouse' ) . ': ' . $problem );
		}
		return $found;
	}

	/**
	 * Counts the problems in a plan.
	 *
	 * @param array<string, mixed> $plan The plan.
	 */
	private static function problem_count( array $plan ): int {
		return count( self::problems( $plan ) );
	}

	/**
	 * The summary of a dry run.
	 *
	 * @param array<string, mixed> $state The dry run.
	 */
	private static function preview_summary( array $state ): void {
		$plan = $state['plan'];
		if ( ! empty( $state['changed'] ) ) {
			$heading = __( 'Not imported: WordPress changed since your dry run', 'firehouse' );
			$lead    = __( 'Nothing has been written. This is the dry run as things stand now.', 'firehouse' );
			$text    = $plan['ok'] ? __( 'Check it, then import at the bottom of the page. Opening this screen again, here or in another tab, discards this dry run.', 'firehouse' ) : __( 'Fix these in the site’s content files, make the bundle again with npm run seed, and upload it:', 'firehouse' );
			self::summary( 'error', $heading, array( $lead, $text ), $plan['ok'] ? array() : self::problems( $plan ) );
			return;
		}
		if ( $plan['ok'] ) {
			/* translators: %s: the file's name. */
			self::summary( 'info', __( 'Ready to import. Nothing has been written yet.', 'firehouse' ), array( sprintf( __( 'This is what importing %s would change. Check it, then import at the bottom of the page. Opening this screen again, here or in another tab, discards this dry run.', 'firehouse' ), $state['file'] ) ) );
			return;
		}
		$count = self::problem_count( $plan );
		/* translators: %d: how many problems. */
		$heading = sprintf( _n( 'Not ready to import: %d problem', 'Not ready to import: %d problems', $count, 'firehouse' ), $count );
		self::summary( 'error', $heading, array( __( 'Nothing has been written. Fix these in the site’s content files, make the bundle again with npm run seed, and upload it:', 'firehouse' ) ), self::problems( $plan ) );
	}

	/**
	 * The summary of an import.
	 *
	 * @param array<string, mixed> $plan The import's result.
	 */
	private static function done_summary( array $plan ): void {
		$count = self::problem_count( $plan );
		if ( ! $plan['imported'] ) {
			self::summary( 'error', __( 'Not imported: WordPress has changed since the dry run', 'firehouse' ), array( __( 'Nothing has been written. Upload the bundle again to see what an import would change now.', 'firehouse' ) ), self::problems( $plan ) );
		} elseif ( $count > 0 ) {
			/* translators: %d: how many problems. */
			$heading = sprintf( _n( 'Imported, with %d problem', 'Imported, with %d problems', $count, 'firehouse' ), $count );
			self::summary( 'error', $heading, array( __( 'Everything else was written. Importing the same bundle again is safe: it updates the same posts.', 'firehouse' ) ), self::problems( $plan ) );
		} else {
			self::summary( 'success', __( 'Imported', 'firehouse' ), array( __( 'Everything listed below as added or updated was written and read back as imported.', 'firehouse' ) ) );
		}
	}

	/**
	 * The notice at the top of the page, which takes the focus when the page loads.
	 * It is `inline` so WordPress's script doesn't move it, which would drop the focus.
	 *
	 * @param string                            $type       success, info or error.
	 * @param string                            $heading    What happened.
	 * @param string[]                          $paragraphs What to do next.
	 * @param list<array{0: string, 1: string}> $problems   Each problem and where it is.
	 */
	private static function summary( string $type, string $heading, array $paragraphs, array $problems = array() ): void {
		printf( '<div id="firehouse-import-summary" class="notice notice-%s inline" tabindex="-1" autofocus>', esc_attr( $type ) );
		echo '<h2>' . esc_html( $heading ) . '</h2>';
		foreach ( $paragraphs as $paragraph ) {
			echo '<p>' . esc_html( $paragraph ) . '</p>';
		}
		if ( array() !== $problems ) {
			echo '<ul class="ul-disc">';
			foreach ( $problems as $problem ) {
				echo '<li><a href="#' . esc_attr( $problem[0] ) . '">' . esc_html( $problem[1] ) . '</a></li>';
			}
			echo '</ul>';
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
	 * The confirmation form, after the dry run it confirms.
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
				<a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Cancel', 'firehouse' ); ?></a>
			</p>
		</form>
		<?php
	}

	/**
	 * What a plan does: problems with the bundle, projects, topics, settings, what it
	 * leaves alone, and notes.
	 *
	 * @param array<string, mixed> $plan The plan, or a run's result.
	 */
	private static function report( array $plan ): void {
		if ( array() !== $plan['problems'] ) {
			echo '<h2 id="firehouse-import-bundle" tabindex="-1">' . esc_html__( 'Problems with the bundle', 'firehouse' ) . '</h2>';
			self::messages( $plan['problems'], array() );
		}

		$actions = array(
			'create'    => __( 'Add', 'firehouse' ),
			'update'    => __( 'Update', 'firehouse' ),
			'unchanged' => __( 'No change', 'firehouse' ),
		);

		self::table_start( 'projects', __( 'Projects', 'firehouse' ), __( 'Projects in the bundle', 'firehouse' ), array( __( 'Project', 'firehouse' ), __( 'Change', 'firehouse' ), __( 'Status', 'firehouse' ), __( 'Fields', 'firehouse' ), __( 'Problems and notes', 'firehouse' ) ) );
		foreach ( $plan['projects'] as $index => $project ) {
			echo '<tr><th scope="row" tabindex="-1" id="firehouse-import-project-' . esc_attr( (string) $index ) . '">' . esc_html( $project['title'] ) . '<br /><code>' . esc_html( $project['slug'] ) . '</code></th>';
			echo '<td>' . esc_html( $actions[ $project['action'] ] ) . '</td>';
			echo '<td>' . esc_html( self::status( $project['status'] ) ) . '</td>';
			echo '<td>' . esc_html( self::fields_changed( $project ) ) . '</td>';
			echo '<td>';
			self::messages( $project['problems'], $project['notes'], true );
			echo '</td></tr>';
		}
		self::table_end();

		if ( array() !== $plan['left_alone'] ) {
			echo '<p>' . esc_html__( 'In WordPress but not in the bundle, and left as they are:', 'firehouse' ) . '</p><ul class="ul-disc">';
			foreach ( $plan['left_alone'] as $project ) {
				$about = array();
				if ( $project['published'] ) {
					$about[] = __( 'still published', 'firehouse' );
				}
				if ( ! $project['has_id'] ) {
					$about[] = __( 'no response ID, so no import matches it; its slug is taken', 'firehouse' );
				}
				$title = '' === $project['title'] ? $project['slug'] : $project['title'];
				echo '<li>' . esc_html( $title ) . ' (<code>' . esc_html( $project['slug'] ) . '</code>)' . ( array() === $about ? '' : esc_html( ': ' . implode( '; ', $about ) ) ) . '</li>';
			}
			echo '</ul>';
		}

		self::table_start( 'topics', __( 'Topics', 'firehouse' ), __( 'Topics and their syntheses', 'firehouse' ), array( __( 'Topic', 'firehouse' ), __( 'Change', 'firehouse' ), __( 'Fields', 'firehouse' ), __( 'Problems and notes', 'firehouse' ) ) );
		foreach ( $plan['topics'] as $topic ) {
			echo '<tr><th scope="row" tabindex="-1" id="firehouse-import-topic-' . esc_attr( $topic['key'] ) . '">' . esc_html( $topic['label'] ) . '<br /><code>' . esc_html( $topic['key'] ) . '</code></th>';
			echo '<td>' . esc_html( $actions[ $topic['action'] ] ) . '</td>';
			echo '<td>' . esc_html( self::fields_changed( $topic ) ) . '</td>';
			echo '<td>';
			self::messages( $topic['problems'], $topic['notes'], true );
			echo '</td></tr>';
		}
		self::table_end();

		echo '<h2 id="firehouse-import-settings" tabindex="-1">' . esc_html__( 'Settings', 'firehouse' ) . '</h2>';
		$settings = $plan['settings'];
		if ( 'update' === $settings['action'] && array() !== $settings['changes'] ) {
			/* translators: %s: the settings that change. */
			echo '<p>' . esc_html( sprintf( __( 'Set for the first time: %s.', 'firehouse' ), implode( ', ', $settings['changes'] ) ) ) . '</p>';
		} elseif ( array() === $settings['problems'] && array() === $settings['notes'] ) {
			echo '<p>' . esc_html__( 'No change.', 'firehouse' ) . '</p>';
		}
		self::messages( $settings['problems'], $settings['notes'] );

		echo '<h2 id="firehouse-import-landing" tabindex="-1">' . esc_html__( 'Landing page', 'firehouse' ) . '</h2>';
		self::page_report( $plan['landing'], '/' . Query::ROOT_PATH . '/', ! empty( $plan['imported'] ) );

		echo '<h2 id="firehouse-import-about" tabindex="-1">' . esc_html__( 'About page', 'firehouse' ) . '</h2>';
		self::page_report( $plan['about'], '/' . Query::ROOT_PATH . '/' . About_Page::SLUG . '/', ! empty( $plan['imported'] ) );

		echo '<h2>' . esc_html__( 'Notes', 'firehouse' ) . '</h2>';
		self::messages( array(), $plan['notes'] );
	}

	/**
	 * What happened to a page the import creates: not created, created, or to be
	 * created at its address; then its problems and notes. A page that exists already
	 * says so in its notes.
	 *
	 * @param array<string, mixed> $page     The page's plan.
	 * @param string               $address  Its address.
	 * @param bool                 $imported Whether the import ran.
	 */
	private static function page_report( array $page, string $address, bool $imported ): void {
		if ( 'create' === $page['action'] && array() !== $page['problems'] ) {
			echo '<p>' . esc_html__( 'Not created: see the problems below.', 'firehouse' ) . '</p>';
		} elseif ( 'create' === $page['action'] ) {
			/* translators: %s: the page's address. */
			echo '<p>' . esc_html( sprintf( $imported ? __( 'Created at %s, with the bundle’s words.', 'firehouse' ) : __( 'Will be created at %s, with the bundle’s words.', 'firehouse' ), $address ) ) . '</p>';
		}
		self::messages( $page['problems'], $page['notes'] );
	}

	/**
	 * Opens a table in a region that scrolls on its own at narrow widths.
	 *
	 * @param string   $name    The section's name, for its IDs.
	 * @param string   $heading The section's heading.
	 * @param string   $caption The table's caption.
	 * @param string[] $columns The column headings.
	 */
	private static function table_start( string $name, string $heading, string $caption, array $columns ): void {
		$id = 'firehouse-import-' . $name . '-heading';
		echo '<h2 id="' . esc_attr( $id ) . '">' . esc_html( $heading ) . '</h2>';
		echo '<div class="firehouse-table-region" role="region" aria-labelledby="' . esc_attr( $id ) . '" tabindex="0" style="overflow-x:auto">';
		echo '<table class="widefat striped"><caption class="screen-reader-text">' . esc_html( $caption ) . '</caption><thead><tr>';
		foreach ( $columns as $column ) {
			echo '<th scope="col">' . esc_html( $column ) . '</th>';
		}
		echo '</tr></thead><tbody>';
	}

	/**
	 * Closes a table opened by table_start().
	 */
	private static function table_end(): void {
		echo '</tbody></table></div>';
	}

	/**
	 * A status in words.
	 *
	 * @param string $status The post status.
	 */
	private static function status( string $status ): string {
		return match ( $status ) {
			'publish' => __( 'Published', 'firehouse' ),
			'draft'   => __( 'Draft', 'firehouse' ),
			'pending' => __( 'Pending review', 'firehouse' ),
			'private' => __( 'Private', 'firehouse' ),
			'future'  => __( 'Scheduled', 'firehouse' ),
			'trash'   => __( 'In the trash', 'firehouse' ),
			default   => $status,
		};
	}

	/**
	 * What a project or topic changes, in words: every field for a new one, the fields
	 * that change, and the ones removed.
	 *
	 * @param array<string, mixed> $entry The project or topic.
	 */
	private static function fields_changed( array $entry ): string {
		if ( 'create' === $entry['action'] ) {
			return __( 'All', 'firehouse' );
		}
		$parts = $entry['changes'];
		if ( array() !== $entry['delete'] ) {
			/* translators: %s: the fields removed. */
			$parts[] = sprintf( __( 'removed: %s', 'firehouse' ), implode( ', ', $entry['delete'] ) );
		}
		return array() === $parts ? __( 'None', 'firehouse' ) : implode( '; ', $parts );
	}

	/**
	 * Problems and notes, each labelled in words rather than by colour alone. In a
	 * table cell, none at all is "None", so a screen reader doesn't say "blank".
	 *
	 * @param string[] $problems What stops the import.
	 * @param string[] $notes    What doesn't.
	 * @param bool     $cell     Whether this is a table cell.
	 */
	private static function messages( array $problems, array $notes, bool $cell = false ): void {
		if ( array() === $problems && array() === $notes ) {
			if ( $cell ) {
				echo esc_html__( 'None', 'firehouse' );
			}
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
