<?php
/**
 * The FireHouse → Synthesis screen.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

use Firehouse\Domain\Notebook_Source;
use Firehouse\Domain\Synthesis;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brings a synthesis made outside WordPress onto the topic pages, in the smallest form
 * the first release needs (decided by Eric, 16 November):
 *
 * 1. **Download NotebookLM source:** every published project, word for word
 *    (Notebook_Source), as the notebook's only source.
 * 2. **Check:** the JSON the team's prompt gives, pasted, is checked (Synthesis): its
 *    shape, that every project it names is one the site shows, each need's count,
 *    and each area's order. Every problem is said, and nothing is written.
 * 3. **Mark reviewed:** once nothing is wrong, the reviewer's name publishes it to the
 *    four topics, with today's date, how many projects the site shows, and the tool
 *    that made it. Each topic's revisions keep the synthesis it had.
 *
 * A check with no problems waits for at most thirty minutes in a transient keyed to
 * the person who checked it, with what they saw. Marking it reviewed checks it again
 * against the projects as they are then, and shows the new check instead of
 * publishing if anything would differ. The comparison against the published
 * synthesis comes after launch. Anyone who may publish a topic may use the screen.
 */
final class Synthesis_Screen {

	/**
	 * The screen's slug.
	 */
	public const PAGE = 'firehouse-synthesis';

	/**
	 * Who may use it: whoever may publish a topic, the FireHouse Editors and
	 * administrators.
	 */
	public const CAP = 'publish_fh_topics';

	/**
	 * The admin-post action that downloads the source.
	 */
	public const DOWNLOAD = 'firehouse_notebook_source';

	/**
	 * How long a check waits to be marked reviewed.
	 */
	private const EXPIRES = 30 * MINUTE_IN_SECONDS;

	/**
	 * The longest paste read, in bytes: far more than five needs in four areas take.
	 */
	private const LONGEST = 200000;

	/**
	 * The longest Reviewed by or Made with, in characters.
	 */
	private const LONGEST_NAME = 200;

	/**
	 * What the screen shows after a submission.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $state = null;

	/**
	 * Hooks the screen and the download.
	 */
	public static function hook(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_post_' . self::DOWNLOAD, array( self::class, 'download' ) );
	}

	/**
	 * Adds the screen under the FireHouse menu, and handles its forms before anything
	 * is drawn.
	 */
	public static function add_page(): void {
		$hook = add_submenu_page(
			Post_Types::MENU,
			__( 'FireHouse synthesis', 'firehouse' ),
			__( 'Synthesis', 'firehouse' ),
			self::CAP,
			self::PAGE,
			array( self::class, 'render' )
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, array( self::class, 'handle' ) );
			add_filter( 'admin_title', array( self::class, 'title' ) );
		}
	}

	/**
	 * Sends the NotebookLM source as a file.
	 */
	public static function download(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Only FireHouse editors can download the NotebookLM source.', 'firehouse' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::DOWNLOAD );
		nocache_headers();
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="firehouse-findings.md"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo self::source(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A Markdown file sent as an attachment, never shown as HTML.
		exit;
	}

	/**
	 * The NotebookLM source: the projects a visitor may see, and the four topics.
	 */
	public static function source(): string {
		$projects = array();
		foreach ( get_posts( Store::public_projects_query( array( 'numberposts' => -1 ) ) ) as $post ) {
			$projects[] = Store::project( $post )['fields'];
		}
		$topics = array();
		foreach ( Store::topics() as $topic ) {
			if ( 'publish' === $topic['status'] ) {
				$topics[] = $topic['fields'];
			}
		}
		return Notebook_Source::build( $projects, $topics );
	}

	/**
	 * Handles a submitted form: a check, or marking it reviewed. Opening the screen
	 * without one starts afresh, so a check left waiting goes.
	 */
	public static function handle(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Only FireHouse editors can publish a synthesis.', 'firehouse' ), '', array( 'response' => 403 ) );
		}
		if ( ! isset( $_POST['firehouse_step'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only checks whether a form was sent; each form's nonce is checked below.
			delete_transient( self::transient() );
			return;
		}
		$step = sanitize_key( wp_unslash( $_POST['firehouse_step'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Each step checks its nonce next.
		if ( 'check' === $step ) {
			check_admin_referer( 'firehouse_synthesis_check' );
			// The JSON as pasted: it's parsed, and shown again only through esc_textarea().
			$pasted      = isset( $_POST['firehouse_synthesis'] ) && is_string( $_POST['firehouse_synthesis'] ) ? wp_unslash( $_POST['firehouse_synthesis'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed as JSON and checked by Synthesis; never printed raw.
			self::$state = self::check( $pasted );
		} elseif ( 'publish' === $step ) {
			check_admin_referer( 'firehouse_synthesis_publish' );
			$field       = static fn( string $name ): string => isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
			self::$state = self::publish( $field( 'firehouse_token' ), $field( 'firehouse_reviewer' ), $field( 'firehouse_model' ) );
		}
	}

	/**
	 * Says in the browser's title what happened, so a screen reader's first words do.
	 *
	 * @param string $title The admin page's title.
	 */
	public static function title( string $title ): string {
		$state  = self::$state ?? array();
		$prefix = match ( true ) {
			! isset( $state['step'] )                                       => '',
			! empty( $state['changed'] ) || ! empty( $state['missing'] )    => __( 'Not published:', 'firehouse' ),
			'gone' === $state['step']                                       => __( 'Not published:', 'firehouse' ),
			'problems' === $state['step']                                   => __( 'Not ready:', 'firehouse' ),
			'checked' === $state['step']                                    => __( 'Ready to mark reviewed:', 'firehouse' ),
			array() === $state['saved']                                     => __( 'Not published:', 'firehouse' ),
			array() !== $state['problems']                                  => __( 'Published, with problems:', 'firehouse' ),
			default                                                         => __( 'Published:', 'firehouse' ),
		};
		return '' === $prefix ? $title : $prefix . ' ' . $title;
	}

	/**
	 * A check of what was pasted, kept for marking reviewed when nothing is wrong.
	 *
	 * @param string $pasted What was pasted.
	 * @return array<string, mixed>
	 */
	private static function check( string $pasted ): array {
		if ( strlen( $pasted ) > self::LONGEST ) {
			return array(
				'step'     => 'problems',
				'pasted'   => '',
				'problems' => array( __( 'The paste is far longer than a synthesis, so it wasn’t read. Paste only the JSON the prompt gives.', 'firehouse' ) ),
			);
		}
		$result = Synthesis::check( $pasted, self::projects() );
		if ( array() !== $result['problems'] ) {
			return array(
				'step'     => 'problems',
				'pasted'   => $pasted,
				'problems' => $result['problems'],
			);
		}
		$token = wp_generate_password( 20, false );
		set_transient(
			self::transient(),
			array(
				'token'  => $token,
				'pasted' => $pasted,
				'needs'  => $result['needs'],
			),
			self::EXPIRES
		);
		return array(
			'step'   => 'checked',
			'pasted' => $pasted,
			'needs'  => $result['needs'],
			'token'  => $token,
		);
	}

	/**
	 * Marks a check reviewed: publishes it to the topics, if it's still the check
	 * waiting and nothing about it has changed.
	 *
	 * @param string $token    The check's token.
	 * @param string $reviewer Who reviewed it.
	 * @param string $model    The tool that made it.
	 * @return array<string, mixed>
	 */
	private static function publish( string $token, string $reviewer, string $model ): array {
		$kept = get_transient( self::transient() );
		if ( ! is_array( $kept ) || ! is_string( $kept['token'] ?? null ) || ! hash_equals( $kept['token'], $token ) ) {
			return array( 'step' => 'gone' );
		}
		$missing = array();
		if ( Synthesis::blank( $reviewer ) ) {
			$missing[] = __( 'Reviewed by is empty: the topic pages name who reviewed the synthesis.', 'firehouse' );
		}
		if ( Synthesis::blank( $model ) ) {
			$missing[] = __( 'Made with is empty: the topic pages say what made the synthesis, since it’s the one place the site paraphrases researchers.', 'firehouse' );
		}
		foreach ( array(
			__( 'Reviewed by', 'firehouse' ) => $reviewer,
			__( 'Made with', 'firehouse' )   => $model,
		) as $name => $value ) {
			$hidden = Synthesis::hidden( $value );
			if ( ! Synthesis::blank( $value ) && null !== $hidden ) {
				/* translators: 1: a field's name, 2: a character's code, as U+200B. */
				$missing[] = sprintf( __( '%1$s holds a character visitors wouldn’t see (%2$s). Take it out.', 'firehouse' ), $name, $hidden );
			}
			if ( mb_strlen( $value ) > self::LONGEST_NAME ) {
				/* translators: 1: a field's name, 2: how many characters it may have. */
				$missing[] = sprintf( __( '%1$s is longer than %2$d characters.', 'firehouse' ), $name, self::LONGEST_NAME );
			}
		}
		if ( array() !== $missing ) {
			return array(
				'step'     => 'checked',
				'pasted'   => $kept['pasted'],
				'needs'    => $kept['needs'],
				'token'    => $kept['token'],
				'reviewer' => $reviewer,
				'model'    => $model,
				'missing'  => $missing,
			);
		}

		// Claimed before anything is written: of two clicks at once, only one request
		// deletes the transient, and the other publishes nothing.
		if ( ! delete_transient( self::transient() ) ) {
			return array( 'step' => 'gone' );
		}
		$again = self::check( (string) $kept['pasted'] );
		if ( 'checked' !== $again['step'] || $again['needs'] !== $kept['needs'] ) {
			return array( 'changed' => true ) + $again;
		}
		delete_transient( self::transient() );

		$set      = array(
			'updatedAt'   => wp_date( 'Y-m-d' ),
			'sourceCount' => Store::public_project_count(),
			'model'       => $model,
			'reviewedBy'  => $reviewer,
		);
		$saved    = array();
		$problems = array();
		foreach ( Store::topics() as $topic ) {
			if ( ! isset( $kept['needs'][ $topic['key'] ] ) ) {
				continue;
			}
			$fields = array( 'topNeeds' => $kept['needs'][ $topic['key'] ] ) + $set;
			$label  = (string) ( $topic['fields']['label'] ?? $topic['key'] );
			foreach ( $fields as $name => $value ) {
				$valid = Meta::validate( Post_Types::TOPIC, $name, $value );
				if ( is_wp_error( $valid ) ) {
					/* translators: 1: a topic area, 2: what's wrong. */
					$problems[] = sprintf( __( '%1$s wasn’t saved: %2$s', 'firehouse' ), $label, $valid->get_error_message() );
					continue 2;
				}
			}
			$result = Store::save( Post_Types::TOPIC, $topic['post_id'], array(), $fields, array() );
			if ( is_wp_error( $result ) ) {
				/* translators: 1: a topic area, 2: WordPress's reason. */
				$problems[] = sprintf( __( '%1$s wasn’t saved: %2$s', 'firehouse' ), $label, $result->get_error_message() );
				continue;
			}
			$saved[] = array(
				'label' => $label,
				'url'   => (string) get_permalink( $topic['post_id'] ),
			);
		}
		return array(
			'step'     => 'done',
			'saved'    => $saved,
			'problems' => $problems,
			'reviewer' => $reviewer,
			'by'       => wp_get_current_user()->display_name,
		);
	}

	/**
	 * Every project, as Synthesis reads them: by post ID, slug, response ID and title,
	 * and whether a visitor may see it.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function projects(): array {
		$public   = array_map(
			'intval',
			get_posts(
				Store::public_projects_query(
					array(
						'fields'      => 'ids',
						'numberposts' => -1,
					)
				)
			)
		);
		$projects = array();
		foreach ( Store::projects() as $project ) {
			$projects[] = array(
				'ref'    => $project['post_id'],
				'slug'   => (string) ( $project['fields']['slug'] ?? '' ),
				'id'     => (string) ( $project['fields']['id'] ?? '' ),
				'title'  => (string) ( $project['fields']['title'] ?? '' ),
				'public' => in_array( $project['post_id'], $public, true ),
			);
		}
		return $projects;
	}

	/**
	 * Draws the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$state = self::$state ?? array( 'step' => 'start' );
		echo '<div class="wrap">';
		echo '<h1>' . esc_html( get_admin_page_title() ) . '</h1>';

		switch ( $state['step'] ) {
			case 'problems':
				self::summary(
					'error',
					! empty( $state['changed'] ) ? __( 'Not published: the projects changed since the check', 'firehouse' ) : __( 'Not ready: the synthesis has problems', 'firehouse' ),
					array( __( 'Nothing was written. Fix each problem in the JSON below, then check it again.', 'firehouse' ) ),
					$state['problems']
				);
				self::paste_form( (string) $state['pasted'] );
				break;
			case 'checked':
				if ( ! empty( $state['changed'] ) ) {
					self::summary( 'warning', __( 'Not published: the projects changed since the check', 'firehouse' ), array( __( 'The synthesis was checked again against the projects as they are now. Review it below, then mark it reviewed.', 'firehouse' ) ) );
				} elseif ( ! empty( $state['missing'] ) ) {
					self::summary( 'error', __( 'Not published: a field is empty', 'firehouse' ), array(), $state['missing'] );
				} else {
					self::summary( 'success', __( 'Ready to mark reviewed', 'firehouse' ), array( __( 'Nothing is wrong with the synthesis. Review its needs below. Nothing reaches the site until you mark it reviewed.', 'firehouse' ) ) );
				}
				self::needs( $state['needs'] );
				self::review_form( (string) $state['token'], (string) ( $state['reviewer'] ?? wp_get_current_user()->display_name ), (string) ( $state['model'] ?? 'NotebookLM' ) );
				self::paste_form( (string) $state['pasted'] );
				break;
			case 'done':
				if ( array() === $state['saved'] ) {
					self::summary( 'error', __( 'Not published', 'firehouse' ), array(), $state['problems'] );
				} else {
					self::summary(
						array() === $state['problems'] ? 'success' : 'warning',
						/* translators: %s: who reviewed the synthesis. */
						sprintf( __( 'Published: the synthesis reviewed by %s', 'firehouse' ), $state['reviewer'] ),
						array(
							/* translators: %s: the name of the person signed in. */
							sprintf( __( 'Marked reviewed by %s, who was signed in; each topic’s new revision records that too. The topic pages and the landing page show it now, and each topic’s revisions keep the synthesis it had before.', 'firehouse' ), $state['by'] ),
						),
						$state['problems']
					);
					echo '<ul class="ul-disc">';
					foreach ( $state['saved'] as $topic ) {
						echo '<li><a href="' . esc_url( $topic['url'] ) . '">' . esc_html( $topic['label'] ) . '</a></li>';
					}
					echo '</ul>';
				}
				self::source_section();
				self::paste_form( '' );
				break;
			case 'gone':
				self::summary( 'info', __( 'Not published: this check isn’t waiting any more', 'firehouse' ), array( __( 'It was marked reviewed already, as by the first of a double click, or it waited more than thirty minutes. Check the synthesis again to mark it reviewed.', 'firehouse' ) ) );
				self::paste_form( '' );
				break;
			default:
				echo '<p>' . esc_html__( 'Publishes a synthesis to the topic pages: up to five needs in each topic area, each with its project count and the projects it draws on. Make it in NotebookLM from the source below, with the team’s prompt, then paste the JSON it gives and check it. Nothing reaches the site until you mark it reviewed.', 'firehouse' ) . '</p>';
				self::source_section();
				self::paste_form( '' );
		}
		echo '</div>';
	}

	/**
	 * The notice at the top of the page, which takes the focus when the page loads.
	 * It is `inline` so WordPress's script doesn't move it, which would drop the focus.
	 *
	 * @param string   $type       success, warning, info or error.
	 * @param string   $heading    What happened.
	 * @param string[] $paragraphs What to do next.
	 * @param string[] $problems   What's wrong.
	 */
	private static function summary( string $type, string $heading, array $paragraphs, array $problems = array() ): void {
		printf( '<div id="firehouse-synthesis-summary" class="notice notice-%s inline" tabindex="-1" autofocus>', esc_attr( $type ) );
		echo '<h2>' . esc_html( $heading ) . '</h2>';
		foreach ( $paragraphs as $paragraph ) {
			echo '<p>' . esc_html( $paragraph ) . '</p>';
		}
		if ( array() !== $problems ) {
			echo '<ul class="ul-disc">';
			foreach ( $problems as $problem ) {
				echo '<li>' . esc_html( $problem ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	/**
	 * Where to download the NotebookLM source.
	 */
	private static function source_section(): void {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DOWNLOAD ), self::DOWNLOAD );
		echo '<h2>' . esc_html__( 'The source', 'firehouse' ) . '</h2>';
		echo '<p><a class="button" href="' . esc_url( $url ) . '" aria-describedby="firehouse-source-description">' . esc_html__( 'Download NotebookLM source', 'firehouse' ) . '</a></p>';
		echo '<p class="description" id="firehouse-source-description">' . esc_html(
			sprintf(
				/* translators: %d: how many projects the site shows. */
				_n( 'firehouse-findings.md: the %d published project, word for word, and the four topic areas. Make it the notebook’s only source, so the synthesis can’t draw on anything the site doesn’t show.', 'firehouse-findings.md: the %d published projects, word for word, and the four topic areas. Make it the notebook’s only source, so the synthesis can’t draw on anything the site doesn’t show.', Store::public_project_count(), 'firehouse' ),
				Store::public_project_count()
			)
		) . '</p>';
	}

	/**
	 * The form a synthesis is pasted into.
	 *
	 * @param string $pasted What was pasted before, to fix.
	 */
	private static function paste_form( string $pasted ): void {
		?>
		<h2><?php esc_html_e( 'The synthesis', 'firehouse' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'firehouse_synthesis_check' ); ?>
			<input type="hidden" name="firehouse_step" value="check" />
			<p><label for="firehouse-synthesis"><?php esc_html_e( 'The JSON the prompt gave', 'firehouse' ); ?></label></p>
			<textarea id="firehouse-synthesis" name="firehouse_synthesis" class="large-text code" rows="16" required aria-describedby="firehouse-synthesis-description"><?php echo esc_textarea( $pasted ); ?></textarea>
			<p class="description" id="firehouse-synthesis-description"><?php esc_html_e( 'The four topic areas, each with up to five needs, by project count, highest first. Each need names its projects by page slug or response ID. Edit the wording here before checking: the published wording is the team’s, not the model’s.', 'firehouse' ); ?></p>
			<?php submit_button( __( 'Check the synthesis', 'firehouse' ), 'secondary' ); ?>
		</form>
		<?php
	}

	/**
	 * A checked synthesis's needs, a table for each topic area.
	 *
	 * @param array<string, list<array{text: string, mentions: int, projects: list<int>}>> $needs The needs by area.
	 */
	private static function needs( array $needs ): void {
		$labels = array();
		foreach ( Store::topics() as $topic ) {
			$labels[ $topic['key'] ] = (string) ( $topic['fields']['label'] ?? $topic['key'] );
		}
		echo '<h2>' . esc_html__( 'Its needs', 'firehouse' ) . '</h2>';
		foreach ( $needs as $key => $list ) {
			$label = $labels[ $key ] ?? $key;
			echo '<table class="widefat striped"><caption class="screen-reader-text">' . esc_html( $label ) . '</caption>';
			echo '<thead><tr><th scope="col">' . esc_html( $label ) . '</th><th scope="col">' . esc_html__( 'Project count', 'firehouse' ) . '</th><th scope="col">' . esc_html__( 'Projects', 'firehouse' ) . '</th></tr></thead><tbody>';
			if ( array() === $list ) {
				echo '<tr><td colspan="3">' . esc_html__( 'No needs in this area.', 'firehouse' ) . '</td></tr>';
			}
			foreach ( $list as $need ) {
				// As the pages show them (Markup::text()), so "R&amp;D" reads here as it will there.
				$titles = array_map( static fn( int $id ): string => (string) get_post_meta( $id, Meta::key( 'title' ), true ), $need['projects'] );
				echo '<tr><td>' . Markup::text( $need['text'] ) . '</td><td>' . esc_html( (string) $need['mentions'] ) . '</td><td>' . Markup::text( implode( '; ', $titles ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup::text() escapes.
			}
			echo '</tbody></table><br />';
		}
	}

	/**
	 * The form that marks a check reviewed.
	 *
	 * @param string $token    The check's token.
	 * @param string $reviewer Who reviewed it, as typed or first offered.
	 * @param string $model    What made it, as typed or first offered.
	 */
	private static function review_form( string $token, string $reviewer, string $model ): void {
		?>
		<h2><?php esc_html_e( 'Mark it reviewed', 'firehouse' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'firehouse_synthesis_publish' ); ?>
			<input type="hidden" name="firehouse_step" value="publish" />
			<input type="hidden" name="firehouse_token" value="<?php echo esc_attr( $token ); ?>" />
			<p>
				<label for="firehouse-reviewer"><?php esc_html_e( 'Reviewed by', 'firehouse' ); ?></label><br />
				<input type="text" id="firehouse-reviewer" name="firehouse_reviewer" class="regular-text" value="<?php echo esc_attr( $reviewer ); ?>" required />
			</p>
			<p>
				<label for="firehouse-model"><?php esc_html_e( 'Made with', 'firehouse' ); ?></label><br />
				<input type="text" id="firehouse-model" name="firehouse_model" class="regular-text" value="<?php echo esc_attr( $model ); ?>" required aria-describedby="firehouse-model-description" />
			</p>
			<p class="description" id="firehouse-model-description"><?php esc_html_e( 'The topic pages show who reviewed it, what made it and today’s date. Marking it reviewed publishes it at once.', 'firehouse' ); ?></p>
			<?php submit_button( __( 'Mark reviewed and publish', 'firehouse' ), 'primary' ); ?>
		</form>
		<?php
	}

	/**
	 * The transient a check waits in, keyed to the person who checked it.
	 */
	private static function transient(): string {
		return 'firehouse_synthesis_' . get_current_user_id();
	}
}
