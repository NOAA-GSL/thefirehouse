<?php
/**
 * The project edit screen.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

use Firehouse\Domain\Vocabulary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a project's fields to WordPress's own edit screen, in two boxes (rule 4):
 *
 * - **Editorial fields**, which the FireHouse team writes and a re-import never
 *   changes: the summary, the publication status, the geography note and the
 *   full-record link. They're saved with the post, by WordPress's Publish or Update
 *   button, and kept exactly as typed: they're meta, which kses doesn't touch.
 * - **From the survey**, the researcher's answers, shown read-only, as stored (rule
 *   1). They change only when the survey record does, through the Import screen.
 *
 * A save checks every editorial field first, and saves none of them if any is wrong
 * (rule 6): the screen comes back with what was typed, each problem beside its field
 * and listed at the top. WordPress has saved the post's own fields by then, such as
 * its status.
 *
 * The slug and the Publish box are WordPress's; what they may do is Phase 6's next
 * change.
 */
final class Project_Screen {

	/**
	 * The nonce's action.
	 */
	public const NONCE = 'firehouse_project_fields';

	/**
	 * The nonce's field.
	 */
	public const NONCE_FIELD = 'firehouse_project_nonce';

	/**
	 * The form fields' name: each editorial field is firehouse[name].
	 */
	public const INPUT = 'firehouse';

	/**
	 * The key of a problem with the whole form, not one field.
	 */
	public const FORM = '_form';

	/**
	 * The editorial fields the screen edits, in the order it shows them. geoNote is
	 * the note in the project's geography.
	 */
	public const FIELDS = array( 'summary', 'publicationStatus', 'geoNote', 'fullRecordUrl' );

	/**
	 * How long a refused save's input waits for the screen it redirects to.
	 */
	private const KEEP = 5 * MINUTE_IN_SECONDS;

	/**
	 * The screen's styles, added inline on the project screen only.
	 */
	private const STYLE = '#poststuff .fh-project-screen__title{margin:1em 0 0;padding:0;font-size:1.3em;line-height:1.4}'
		. '.fh-project-screen .fh-project-field{margin:0 0 1.5em}'
		. '.fh-project-screen .fh-project-field label{display:block;font-weight:600;margin:0 0 .25em}'
		. '.fh-project-screen .fh-project-field textarea,.fh-project-screen .fh-project-field input[type=text]{width:100%;max-width:48em}'
		. '.fh-project-screen .fh-project-field :is(input,select,textarea){scroll-margin-top:3em}'
		. '.fh-project-screen .fh-project-field .description{margin:.25em 0 0;max-width:48em}'
		. '.fh-project-screen .fh-project-field__error{margin:.25em 0 0;color:#b32d2e;font-weight:600}'
		. '.fh-project-screen [aria-invalid=true]{border-color:#b32d2e;box-shadow:0 0 0 1px #b32d2e}'
		. '.fh-project-screen [aria-invalid=true]:focus{border-color:var(--wp-admin-theme-color,#2271b1);box-shadow:0 0 0 2px var(--wp-admin-theme-color,#2271b1)}'
		. '.fh-survey{margin:0}'
		. '.fh-survey dt{font-weight:600;margin:1em 0 .25em}'
		. '.fh-survey dt:first-child{margin-top:0}'
		. '.fh-survey dd{margin:0;max-width:48em;white-space:pre-wrap;overflow-wrap:anywhere}'
		. '.fh-survey dd ul,.fh-survey dd ol{margin:0 0 0 1.5em;white-space:normal}'
		. '.fh-survey dd li{margin:0 0 .5em;white-space:pre-wrap}'
		. '.fh-survey__note{color:#50575e;font-style:italic}';

	/**
	 * A refused save's input and problems, for the screen that shows it.
	 *
	 * @var array{values: array<string, string>, errors: array<string, string>}|null
	 */
	private static ?array $refused = null;

	/**
	 * Hooks the screen.
	 */
	public static function hook(): void {
		add_action( 'add_meta_boxes_' . Post_Types::PROJECT, array( self::class, 'boxes' ) );
		add_action( 'edit_form_after_title', array( self::class, 'heading' ) );
		add_action( 'save_post_' . Post_Types::PROJECT, array( self::class, 'save' ), 10, 2 );
		add_action( 'load-post.php', array( self::class, 'load' ) );
		add_action( 'edit_form_top', array( self::class, 'notice' ) );
		add_filter( 'post_updated_messages', array( self::class, 'messages' ) );
		add_filter( 'wp_refresh_nonces', array( self::class, 'refresh_nonce' ), 11, 2 );
	}

	/**
	 * On a project's edit screen, before anything is drawn: adds the styles, and takes
	 * back a refused save's input, once. After a refusal, the browser's title says so,
	 * so a screen reader's first words do, and the editorial box is open and shown
	 * even if the editor had collapsed or hidden it, so the notice's links reach its
	 * fields.
	 */
	public static function load(): void {
		self::$refused = null;
		$post          = self::screen_post();
		if ( null === $post ) {
			return;
		}
		wp_add_inline_style( 'common', self::STYLE );
		$key     = self::transient( $post->ID );
		$refused = get_transient( $key );
		delete_transient( $key );
		if ( is_array( $refused ) && is_array( $refused['values'] ?? null ) && is_array( $refused['errors'] ?? null ) && array() !== $refused['errors'] ) {
			self::$refused = array(
				'values' => array_map( 'strval', $refused['values'] ),
				'errors' => array_map( 'strval', $refused['errors'] ),
			);
			add_filter( 'admin_title', array( self::class, 'title' ) );
			add_filter( 'postbox_classes_' . Post_Types::PROJECT . '_firehouse-editorial', array( self::class, 'open_box' ) );
			add_filter( 'hidden_meta_boxes', array( self::class, 'show_box' ) );
		}
	}

	/**
	 * The browser's title after a refused save.
	 *
	 * @param string $title The admin page's title.
	 */
	public static function title( string $title ): string {
		return __( 'Editorial fields not saved:', 'firehouse' ) . ' ' . $title;
	}

	/**
	 * The editorial box's classes, without the one that collapses it.
	 *
	 * @param string[] $classes The box's classes.
	 * @return string[]
	 */
	public static function open_box( array $classes ): array {
		return array_values( array_diff( $classes, array( 'closed' ) ) );
	}

	/**
	 * The boxes the editor has hidden, without the editorial box.
	 *
	 * @param string[] $hidden The hidden boxes' IDs.
	 * @return string[]
	 */
	public static function show_box( array $hidden ): array {
		return array_values( array_diff( $hidden, array( 'firehouse-editorial' ) ) );
	}

	/**
	 * Leaves WordPress's own "updated" notice off the screen a refused save redirects
	 * to, where it would say the opposite of the refusal's.
	 *
	 * @param string $location The address WordPress redirects to.
	 */
	public static function without_message( string $location ): string {
		return remove_query_arg( 'message', $location );
	}

	/**
	 * WordPress's notices after saving, in a project's words. The editorial fields'
	 * refusal has its own notice.
	 *
	 * @param array<string, array<int, string>> $messages The notices, by post type.
	 * @return array<string, array<int, string>>
	 */
	public static function messages( array $messages ): array {
		$post                            = get_post();
		$view                            = $post instanceof \WP_Post ? ' <a href="' . esc_url( (string) get_permalink( $post ) ) . '">' . esc_html__( 'View project', 'firehouse' ) . '</a>' : '';
		$messages[ Post_Types::PROJECT ] = array(
			0  => '',
			1  => esc_html__( 'Project updated.', 'firehouse' ) . $view,
			4  => esc_html__( 'Project updated.', 'firehouse' ),
			6  => esc_html__( 'Project published.', 'firehouse' ) . $view,
			7  => esc_html__( 'Project saved.', 'firehouse' ),
			10 => esc_html__( 'Project draft updated.', 'firehouse' ) . $view,
		) + ( $messages['post'] ?? array() );
		return $messages;
	}

	/**
	 * The project the current screen edits, if it's a project's edit screen.
	 */
	private static function screen_post(): ?\WP_Post {
		$id   = isset( $_GET['post'] ) && is_string( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads which post the screen shows; WordPress checks it may be edited.
		$post = 0 === $id ? null : get_post( $id );
		return $post instanceof \WP_Post && Post_Types::PROJECT === $post->post_type ? $post : null;
	}

	/**
	 * Adds the two boxes.
	 */
	public static function boxes(): void {
		add_meta_box( 'firehouse-editorial', __( 'Editorial fields', 'firehouse' ), array( self::class, 'editorial_box' ), Post_Types::PROJECT, 'normal', 'high' );
		add_meta_box( 'firehouse-survey', __( 'From the survey', 'firehouse' ), array( self::class, 'survey_box' ), Post_Types::PROJECT, 'normal', 'high' );
	}

	/**
	 * The researcher's title under the screen's heading, so the screen says which
	 * project it is.
	 *
	 * @param \WP_Post $post The post being edited.
	 */
	public static function heading( \WP_Post $post ): void {
		if ( Post_Types::PROJECT !== $post->post_type ) {
			return;
		}
		$fields = Store::project( $post )['fields'];
		$title  = is_string( $fields['title'] ?? null ) ? $fields['title'] : '';
		if ( '' !== $title ) {
			echo '<h2 class="fh-project-screen__title">' . Markup::text( $title ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Markup::text() escapes stored text.
		}
	}

	/**
	 * The editorial fields, as a form inside WordPress's.
	 *
	 * @param \WP_Post $post The post being edited.
	 */
	public static function editorial_box( \WP_Post $post ): void {
		$values = self::$refused['values'] ?? self::values( Store::project( $post )['fields'] );
		$errors = self::$refused['errors'] ?? array();
		wp_nonce_field( self::NONCE . '_' . $post->ID, self::NONCE_FIELD, false );

		echo '<div class="fh-project-screen">';
		echo '<p>' . esc_html__( 'The FireHouse team’s own fields. Saving the project saves them; a new import of the survey never changes them.', 'firehouse' ) . '</p>';

		// A browser drops a line break straight after <textarea>, so one is printed for it
		// to drop, and a summary that starts with its own line break keeps it.
		self::field(
			'summary',
			__( 'Summary', 'firehouse' ),
			static fn( string $attributes ): string => '<textarea id="fh-project-summary" name="' . esc_attr( self::INPUT ) . '[summary]" rows="3"' . $attributes . ">\n" . Markup::text( $values['summary'] ) . '</textarea>',
			__( 'One or two lines under the title on the explorer’s tiles. The import starts it with the abstract’s first sentence.', 'firehouse' ),
			$errors
		);

		$options = '<option value="">' . esc_html__( 'None', 'firehouse' ) . '</option>';
		foreach ( Vocabulary::items( 'publicationStatuses' ) as $key => $item ) {
			$options .= '<option value="' . esc_attr( (string) $key ) . '"' . selected( $values['publicationStatus'], (string) $key, false ) . '>' . esc_html( (string) $item['label'] ) . '</option>';
		}
		self::field(
			'publicationStatus',
			__( 'Publication status', 'firehouse' ),
			static fn( string $attributes ): string => '<select id="fh-project-publicationStatus" name="' . esc_attr( self::INPUT ) . '[publicationStatus]"' . $attributes . '>' . $options . '</select>',
			__( 'Whether the research is published, shown at the top of the project’s page and in its quick look. The survey doesn’t ask.', 'firehouse' ),
			$errors
		);

		self::field(
			'geoNote',
			__( 'Geography note', 'firehouse' ),
			static fn( string $attributes ): string => '<input type="text" id="fh-project-geoNote" name="' . esc_attr( self::INPUT ) . '[geoNote]" value="' . Markup::text( $values['geoNote'] ) . '"' . $attributes . '>',
			__( 'A few words shown beside the project’s regions on its page, such as “Front Range”. Not shown when the survey names no region.', 'firehouse' ),
			$errors
		);

		self::field(
			'fullRecordUrl',
			__( 'Full record link', 'firehouse' ),
			static fn( string $attributes ): string => '<input type="text" inputmode="url" id="fh-project-fullRecordUrl" name="' . esc_attr( self::INPUT ) . '[fullRecordUrl]" value="' . Markup::text( $values['fullRecordUrl'] ) . '" spellcheck="false" autocomplete="off"' . $attributes . '>',
			__( 'A web address where the full record is kept, starting with https:// or http://. The project’s page links to it as “View full record”.', 'firehouse' ),
			$errors
		);
		echo '</div>';
	}

	/**
	 * One field: its label, its control, its description, and its problem, if any,
	 * which the control is described by and marked invalid for.
	 *
	 * @param string                $name        The field.
	 * @param string                $label       Its label.
	 * @param callable              $control     Builds the control's markup, escaped, from its extra attributes.
	 * @param string                $description What it's for.
	 * @param array<string, string> $errors      The problems, by field.
	 */
	private static function field( string $name, string $label, callable $control, string $description, array $errors ): void {
		$id          = 'fh-project-' . $name;
		$described   = $id . '-description';
		$error       = $errors[ $name ] ?? null;
		$attributes  = null === $error ? '' : ' aria-invalid="true"';
		$attributes .= ' aria-describedby="' . esc_attr( ( null === $error ? '' : $id . '-error ' ) . $described ) . '"';

		echo '<div class="fh-project-field">';
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		echo $control( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the control and its attributes are escaped where they're built.
		if ( null !== $error ) {
			echo '<p class="fh-project-field__error" id="' . esc_attr( $id . '-error' ) . '"><strong>' . esc_html__( 'Error:', 'firehouse' ) . '</strong> ' . esc_html( $error ) . '</p>';
		}
		echo '<p class="description" id="' . esc_attr( $described ) . '">' . esc_html( $description ) . '</p>';
		echo '</div>';
	}

	/**
	 * The editorial fields' stored values, as the form shows them: '' for none.
	 *
	 * @param array<string, mixed> $fields The project's fields (Store::project()).
	 * @return array<string, string>
	 */
	public static function values( array $fields ): array {
		$geo = is_array( $fields['geo'] ?? null ) ? $fields['geo'] : array();
		return array(
			'summary'           => is_string( $fields['summary'] ?? null ) ? $fields['summary'] : '',
			'publicationStatus' => is_string( $fields['publicationStatus'] ?? null ) ? $fields['publicationStatus'] : '',
			'geoNote'           => is_string( $geo['note'] ?? null ) ? $geo['note'] : '',
			'fullRecordUrl'     => is_string( $fields['fullRecordUrl'] ?? null ) ? $fields['fullRecordUrl'] : '',
		);
	}

	/**
	 * The researcher's answers, read-only, each as stored.
	 *
	 * @param \WP_Post $post The post being edited.
	 */
	public static function survey_box( \WP_Post $post ): void {
		$fields = Store::project( $post )['fields'];
		$text   = static fn( string $name ): string => is_string( $fields[ $name ] ?? null ) ? $fields[ $name ] : '';
		$list   = static fn( string $name ): array => is_array( $fields[ $name ] ?? null ) ? array_values( $fields[ $name ] ) : array();
		$geo    = is_array( $fields['geo'] ?? null ) ? $fields['geo'] : array();

		echo '<p>' . esc_html__( 'The researcher’s answers, exactly as submitted. They change only when the survey record does, through the Import screen.', 'firehouse' ) . '</p>';
		echo '<dl class="fh-survey">';
		self::row( __( 'Title', 'firehouse' ), Markup::text( $text( 'title' ) ) );
		self::row( __( 'Authors', 'firehouse' ), self::items( array_map( array( self::class, 'author' ), $list( 'authors' ) ) ) );
		self::row( __( 'Organization', 'firehouse' ), Markup::text( $text( 'org' ) ) );
		$year = $fields['completionYear'] ?? null;
		self::row( __( 'Year completed', 'firehouse' ), is_int( $year ) || is_float( $year ) ? esc_html( (string) $year ) : '' );
		self::row( __( 'Fire cycle phases', 'firehouse' ), self::labels( $list( 'firePhases' ), Vocabulary::items( 'firePhases' ) ) );
		self::row( __( 'Project type', 'firehouse' ), Markup::text( $text( 'projectType' ) ) );
		self::row( __( 'Methods', 'firehouse' ), self::items( array_map( array( self::class, 'plain' ), $list( 'methods' ) ) ) );
		self::row(
			__( 'IRB approval', 'firehouse' ),
			'<span class="fh-survey__note">' . esc_html(
				true === ( $fields['irbApproved'] ?? null )
					? __( 'Approved, exempt, or not required', 'firehouse' )
					: __( 'Not recorded. Visitors can’t see a project without it.', 'firehouse' )
			) . '</span>'
		);
		self::row( __( 'Geographic areas', 'firehouse' ), self::labels( is_array( $geo['regions'] ?? null ) ? array_values( $geo['regions'] ) : array(), Vocabulary::items( 'regions' ) ) );
		self::row( __( 'Abstract', 'firehouse' ), Markup::text( $text( 'abstract' ) ) );
		self::row( __( 'Major takeaways', 'firehouse' ), self::items( array_map( array( self::class, 'plain' ), $list( 'takeaways' ) ), 'ol' ) );
		self::row( __( 'End-user needs and recommendations', 'firehouse' ), self::items( array_map( array( self::class, 'need' ), $list( 'needs' ) ), 'ol' ) );
		self::row( __( 'Publications', 'firehouse' ), self::items( array_map( array( self::class, 'paper' ), $list( 'papers' ) ) ) );
		self::row( __( 'Response ID', 'firehouse' ), Markup::text( $text( 'id' ) ) );
		echo '</dl>';
	}

	/**
	 * One answer: its question's label, and the answer, or a note that there's none,
	 * set in italics, as the screen's own words are, apart from the researcher's.
	 *
	 * @param string $label The label.
	 * @param string $value The answer's markup, escaped; '' for none.
	 */
	private static function row( string $label, string $value ): void {
		echo '<dt>' . esc_html( $label ) . '</dt>';
		echo '<dd>' . ( '' === $value ? '<span class="fh-survey__note">' . esc_html__( '(No answer)', 'firehouse' ) . '</span>' : $value ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the value is escaped where it's built.
	}

	/**
	 * A list of answers, each escaped already; '' for none.
	 *
	 * @param array<int, string> $items Each item's markup.
	 * @param string             $tag   ul or ol.
	 */
	private static function items( array $items, string $tag = 'ul' ): string {
		$items = array_values( array_filter( $items, static fn( string $item ): bool => '' !== $item ) );
		if ( array() === $items ) {
			return '';
		}
		return '<' . $tag . '><li>' . implode( '</li><li>', $items ) . '</li></' . $tag . '>';
	}

	/**
	 * Vocabulary keys as their labels, a key the vocabulary doesn't know as written.
	 *
	 * @param array<int, mixed>                   $keys  The keys.
	 * @param array<string, array<string, mixed>> $items The vocabulary's items.
	 */
	private static function labels( array $keys, array $items ): string {
		$labels = array();
		foreach ( $keys as $key ) {
			if ( is_string( $key ) ) {
				$labels[] = Markup::text( isset( $items[ $key ]['label'] ) ? (string) $items[ $key ]['label'] : $key );
			}
		}
		return self::items( $labels );
	}

	/**
	 * A text item, escaped; '' for anything else.
	 *
	 * @param mixed $item The item.
	 */
	private static function plain( mixed $item ): string {
		return is_string( $item ) ? Markup::text( $item ) : '';
	}

	/**
	 * An author: the name, and their organization if the survey gave one.
	 *
	 * @param mixed $author The author.
	 */
	private static function author( mixed $author ): string {
		if ( ! is_array( $author ) || ! is_string( $author['name'] ?? null ) ) {
			return '';
		}
		$org = is_string( $author['org'] ?? null ) && '' !== $author['org'] ? ' (' . Markup::text( $author['org'] ) . ')' : '';
		return Markup::text( $author['name'] ) . $org;
	}

	/**
	 * A need and its recommendation, each labelled.
	 *
	 * @param mixed $need The need.
	 */
	private static function need( mixed $need ): string {
		if ( ! is_array( $need ) ) {
			return '';
		}
		$parts = array();
		if ( is_string( $need['need'] ?? null ) && '' !== $need['need'] ) {
			$parts[] = '<strong>' . esc_html__( 'Need:', 'firehouse' ) . '</strong> ' . Markup::text( $need['need'] );
		}
		if ( is_string( $need['recommendation'] ?? null ) && '' !== $need['recommendation'] ) {
			$parts[] = '<strong>' . esc_html__( 'Recommendation:', 'firehouse' ) . '</strong> ' . Markup::text( $need['recommendation'] );
		}
		return implode( "\n", $parts );
	}

	/**
	 * A publication: its title, where and when it appeared, and its DOI and address,
	 * as text. Addresses aren't links here: the screen shows what was submitted.
	 *
	 * @param mixed $paper The publication.
	 */
	private static function paper( mixed $paper ): string {
		if ( ! is_array( $paper ) ) {
			return '';
		}
		$lines = array();
		foreach ( array( 'title', 'container' ) as $part ) {
			if ( is_string( $paper[ $part ] ?? null ) && '' !== $paper[ $part ] ) {
				$lines[] = Markup::text( $paper[ $part ] );
			}
		}
		if ( is_int( $paper['year'] ?? null ) || is_float( $paper['year'] ?? null ) ) {
			$lines[] = esc_html( (string) $paper['year'] );
		}
		$named = array(
			'doi' => __( 'DOI:', 'firehouse' ),
			'url' => __( 'Address:', 'firehouse' ),
		);
		foreach ( $named as $part => $label ) {
			if ( is_string( $paper[ $part ] ?? null ) && '' !== $paper[ $part ] ) {
				$lines[] = esc_html( $label ) . ' ' . Markup::text( $paper[ $part ] );
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Refreshes the screen's nonce when WordPress refreshes its own, through the
	 * heartbeat: as a nonce ages, and after signing in again in the session-expired
	 * dialog, when every nonce the page holds is stale. WordPress's script sets each
	 * value it's sent by the element's ID, which wp_nonce_field() gives the name.
	 * Without it, the screen's form would go stale while WordPress's own nonce stayed
	 * fresh, and the save would drop the editorial fields.
	 *
	 * @param array<string, mixed> $response The heartbeat's response.
	 * @param array<string, mixed> $data     What the heartbeat sent.
	 * @return array<string, mixed>
	 */
	public static function refresh_nonce( array $response, array $data ): array {
		$id = isset( $data['wp-refresh-post-nonces']['post_id'] ) && is_scalar( $data['wp-refresh-post-nonces']['post_id'] ) ? absint( $data['wp-refresh-post-nonces']['post_id'] ) : 0;
		if ( 0 === $id || ! isset( $response['wp-refresh-post-nonces']['replace'] ) || ! is_array( $response['wp-refresh-post-nonces']['replace'] ) ) {
			return $response;
		}
		if ( Post_Types::PROJECT !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return $response;
		}
		$response['wp-refresh-post-nonces']['replace'][ self::NONCE_FIELD ] = wp_create_nonce( self::NONCE . '_' . $id );
		return $response;
	}

	/**
	 * Saves the editorial fields with the post, from the screen's own form only (Save
	 * Draft, Publish, Update, or a draft's Preview, which saves the draft): not from
	 * Quick Edit, an autosave, or the plugin's own writes. Nothing is saved if any
	 * field is wrong; the input and problems wait for the screen WordPress redirects
	 * to. A good save clears any refusal still waiting, such as one from a preview,
	 * whose window never shows the edit screen.
	 *
	 * @param int      $post_id The post.
	 * @param \WP_Post $post    The post as saved.
	 */
	public static function save( int $post_id, \WP_Post $post ): void {
		if ( Store::writing() || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || Post_Types::PROJECT !== $post->post_type ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! is_string( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}
		$fresh = wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE . '_' . $post_id );
		if ( ! $fresh ) {
			self::refuse_stale( $post_id, $post );
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// A request with the screen's nonce but without its fields didn't come from the
		// screen's form, which always sends all of them: it changes nothing.
		if ( ! isset( $_POST[ self::INPUT ] ) || ! is_array( $_POST[ self::INPUT ] ) ) {
			return;
		}
		$input = wp_unslash( $_POST[ self::INPUT ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Kept as typed (rule 1's spirit for the team's words); check() validates each field, and pages escape them.

		$checked = self::check( Store::project( $post )['fields'], is_array( $input ) ? $input : array() );
		if ( array() !== $checked['errors'] ) {
			set_transient(
				self::transient( $post_id ),
				array(
					'values' => $checked['values'],
					'errors' => $checked['errors'],
				),
				self::KEEP
			);
			add_filter( 'redirect_post_location', array( self::class, 'without_message' ) );
			return;
		}
		delete_transient( self::transient( $post_id ) );
		Store::update_fields( $post_id, $checked['set'], $checked['delete'] );
	}

	/**
	 * When the screen's nonce has gone stale but WordPress's own save of the post went
	 * through (post.php's editpost, which checks WordPress's nonce first), the editorial
	 * fields aren't saved, and the screen says so and shows what was typed, rather than
	 * dropping it quietly under WordPress's "Project updated." Anything else with a
	 * stale nonce changes nothing and says nothing.
	 *
	 * @param int      $post_id The post.
	 * @param \WP_Post $post    The post as saved.
	 */
	private static function refuse_stale( int $post_id, \WP_Post $post ): void {
		$action = isset( $_POST['action'] ) && is_string( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress checked its own nonce for editpost before saving the post; this only decides whether to say so.
		if ( 'editpost' !== $action || ! isset( $_POST[ self::INPUT ] ) || ! is_array( $_POST[ self::INPUT ] ) || ! current_user_can( 'edit_post', $post_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- As above.
			return;
		}
		$input = wp_unslash( $_POST[ self::INPUT ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Shown back to the same user, escaped; nothing is saved.
		$typed = self::check( Store::project( $post )['fields'], is_array( $input ) ? $input : array() )['values'];
		set_transient(
			self::transient( $post_id ),
			array(
				'values' => $typed,
				'errors' => array( self::FORM => __( 'The screen had been open too long, so they weren’t saved. They’re below as you typed them: save the project again.', 'firehouse' ) ),
			),
			self::KEEP
		);
		add_filter( 'redirect_post_location', array( self::class, 'without_message' ) );
	}

	/**
	 * Checks the form's input against the project's stored fields: what to save, what
	 * to remove, and what's wrong, by field.
	 *
	 * Text is kept as typed, but for the line breaks a browser sends (CRLF), which
	 * become LF; an address is trimmed. An empty status, note or address removes it.
	 * The geography keeps its regions, which are the survey's. A field that's missing
	 * or isn't text is refused, rather than taken as empty: the form always sends each
	 * as text.
	 *
	 * @param array<string, mixed> $fields The project's stored fields.
	 * @param array<string, mixed> $input  The form's input, unslashed.
	 * @return array{set: array<string, mixed>, delete: list<string>, errors: array<string, string>, values: array<string, string>}
	 */
	public static function check( array $fields, array $input ): array {
		$values = array();
		$errors = array();
		foreach ( self::FIELDS as $name ) {
			$value           = $input[ $name ] ?? null;
			$values[ $name ] = is_string( $value ) ? str_replace( "\r\n", "\n", $value ) : '';
			if ( ! is_string( $value ) ) {
				$errors[ $name ] = __( 'This field didn’t arrive as text. Reload the screen and enter it again.', 'firehouse' );
			}
		}
		$values['fullRecordUrl'] = trim( $values['fullRecordUrl'] );

		$set    = array( 'summary' => $values['summary'] );
		$delete = array();

		$status = $values['publicationStatus'];
		if ( '' === $status ) {
			$delete[] = 'publicationStatus';
		} elseif ( Vocabulary::is_one_of( $status, Vocabulary::publication_statuses() ) ) {
			$set['publicationStatus'] = $status;
		} else {
			$errors['publicationStatus'] ??= __( 'Choose one of the listed publication statuses.', 'firehouse' );
		}

		$url = $values['fullRecordUrl'];
		if ( '' === $url ) {
			$delete[] = 'fullRecordUrl';
		} elseif ( self::is_web_address( $url ) ) {
			$set['fullRecordUrl'] = $url;
		} else {
			$errors['fullRecordUrl'] ??= __( 'The full record link has to be a web address starting with https:// or http://.', 'firehouse' );
		}

		// The geography is written only when its note changes, so a save never stands
		// or falls on the survey's regions, which the editor can't change.
		$geo    = is_array( $fields['geo'] ?? null ) ? $fields['geo'] : null;
		$note   = $values['geoNote'];
		$stored = is_string( $geo['note'] ?? null ) ? $geo['note'] : '';
		if ( $note !== $stored ) {
			$geo = $geo ?? array( 'regions' => array() );
			unset( $geo['note'] );
			if ( '' !== $note ) {
				$geo['note'] = $note;
			}
			$set['geo'] = $geo;
		}

		foreach ( $set as $name => $value ) {
			$valid = Meta::validate( Post_Types::PROJECT, $name, $value );
			if ( true !== $valid ) {
				$errors[ 'geo' === $name ? 'geoNote' : $name ] ??= $valid->get_error_message();
			}
		}

		return array(
			'set'    => $set,
			'delete' => $delete,
			'errors' => $errors,
			'values' => $values,
		);
	}

	/**
	 * Whether an address is one the project's page links to, over the web: http or
	 * https, with a host, and one the page's own check (Markup::url()) keeps.
	 *
	 * @param string $url The address.
	 */
	private static function is_web_address( string $url ): bool {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $scheme ) && in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) && is_string( $host ) && '' !== $host && '' !== Markup::url( $url );
	}

	/**
	 * After a refused save, a summary at the top of the form naming each problem, each
	 * a link to its field. It takes the focus when the page loads, and is `inline` so
	 * WordPress's script doesn't move it, which would drop the focus, as the Import
	 * screen's summary does.
	 *
	 * @param \WP_Post|null $post The post being edited.
	 */
	public static function notice( ?\WP_Post $post = null ): void {
		if ( null === self::$refused || ( null !== $post && Post_Types::PROJECT !== $post->post_type ) ) {
			return;
		}
		$count  = count( self::$refused['errors'] );
		$labels = array(
			'summary'           => __( 'Summary', 'firehouse' ),
			'publicationStatus' => __( 'Publication status', 'firehouse' ),
			'geoNote'           => __( 'Geography note', 'firehouse' ),
			'fullRecordUrl'     => __( 'Full record link', 'firehouse' ),
		);
		echo '<div id="firehouse-project-errors" class="notice notice-error inline" tabindex="-1" autofocus><h2>';
		/* translators: %d: how many problems. */
		echo esc_html( sprintf( _n( 'Editorial fields not saved: %d problem', 'Editorial fields not saved: %d problems', $count, 'firehouse' ), $count ) );
		$marked = array() !== array_diff_key( self::$refused['errors'], array( self::FORM => true ) );
		echo '</h2><p>' . ( $marked ? esc_html__( 'Fix what’s marked, then save the project again.', 'firehouse' ) . ' ' : '' ) . esc_html__( 'The project’s other changes, such as its status, were saved.', 'firehouse' ) . '</p><ul>';
		foreach ( self::$refused['errors'] as $name => $message ) {
			echo self::FORM === $name
				? '<li>' . esc_html( $message ) . '</li>'
				: '<li><a href="#fh-project-' . esc_attr( $name ) . '">' . esc_html( $labels[ $name ] ?? $name ) . '</a>: ' . esc_html( $message ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * The transient that keeps a refused save for the screen after it, one per post
	 * and user.
	 *
	 * @param int $post_id The post.
	 */
	private static function transient( int $post_id ): string {
		return 'firehouse_project_' . $post_id . '_' . get_current_user_id();
	}
}
