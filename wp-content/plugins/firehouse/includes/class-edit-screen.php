<?php
/**
 * What the project and topic edit screens share.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a box of the FireHouse team's fields to WordPress's own edit screen for one
 * post type, saved with the post by WordPress's Publish or Update button and kept
 * exactly as typed: they're meta, which kses doesn't touch.
 *
 * Each screen (Project_Screen, Topic_Screen) names its post type, its fields and its
 * nonce, draws its boxes, checks the form's input (check()) and says things in its
 * own words. This class does the rest, the same way for each:
 *
 * - **A save comes from the screen's own form only**, with its nonce, which is per
 *   post and refreshed with WordPress's own through the heartbeat. Quick Edit, an
 *   autosave and the plugin's own writes change none of the fields.
 * - **All or nothing** (rule 6): a save checks every field first, and saves none of
 *   them if any is wrong. The screen comes back with what was typed, each problem
 *   beside its field and listed at the top, which takes the focus. WordPress has
 *   saved the post's own fields by then.
 * - **A stale nonce on WordPress's own save** is said out loud, with what was typed,
 *   rather than dropped quietly under WordPress's "updated" notice.
 */
abstract class Edit_Screen {

	/**
	 * The post type the screen edits; each screen sets its own.
	 */
	public const POST_TYPE = '';

	/**
	 * The word in the fields' IDs (fh-{kind}-{field}) and the refusal's transient and
	 * notice; each screen sets its own.
	 */
	public const KIND = '';

	/**
	 * The nonce's action, to which the post's ID is added; each screen sets its own.
	 */
	public const NONCE = '';

	/**
	 * The nonce's field; each screen sets its own.
	 */
	public const NONCE_FIELD = '';

	/**
	 * The fields the screen edits, in the order it shows them; each screen sets its own.
	 */
	public const FIELDS = array();

	/**
	 * The form fields' name: each field is firehouse[name].
	 */
	public const INPUT = 'firehouse';

	/**
	 * The key of a problem with the whole form, not one field.
	 */
	public const FORM = '_form';

	/**
	 * The box of fields the screen saves.
	 */
	public const BOX = 'firehouse-editorial';

	/**
	 * How long a refused save's input waits for the screen it redirects to.
	 */
	private const KEEP = 5 * MINUTE_IN_SECONDS;

	/**
	 * The styles every screen shares, for its fields and its read-only boxes.
	 */
	private const STYLE = '.fh-edit-screen .fh-edit-field{margin:0 0 1.5em}'
		. '.fh-edit-screen .fh-edit-field label{display:block;font-weight:600;margin:0 0 .25em}'
		. '.fh-edit-screen .fh-edit-field textarea,.fh-edit-screen .fh-edit-field input[type=text]{width:100%;max-width:48em}'
		. '.fh-edit-screen .fh-edit-field :is(input,select,textarea){scroll-margin-top:3em}'
		. '.fh-edit-screen .fh-edit-field .description{margin:.25em 0 0;max-width:48em}'
		. '.fh-edit-screen .fh-edit-field__error{margin:.25em 0 0;color:#b32d2e;font-weight:600}'
		. '.fh-edit-screen [aria-invalid=true]{border-color:#b32d2e;box-shadow:0 0 0 1px #b32d2e}'
		. '.fh-edit-screen [aria-invalid=true]:focus{border-color:var(--wp-admin-theme-color,#2271b1);box-shadow:0 0 0 2px var(--wp-admin-theme-color,#2271b1)}'
		. '.fh-edit-readonly{margin:0}'
		. '.fh-edit-readonly dt{font-weight:600;margin:1em 0 .25em}'
		. '.fh-edit-readonly dt:first-child{margin-top:0}'
		. '.fh-edit-readonly dd{margin:0;max-width:48em;white-space:pre-wrap;overflow-wrap:anywhere}'
		. '.fh-edit-readonly dd ul,.fh-edit-readonly dd ol{margin:0 0 0 1.5em;white-space:normal}'
		. '.fh-edit-readonly dd li{margin:0 0 .5em;white-space:pre-wrap}'
		. '.fh-edit-note{color:#50575e;font-style:italic}'
		. '.fh-edit-errors:focus-visible{outline:2px solid var(--wp-admin-theme-color,#2271b1);outline-offset:2px}';

	/**
	 * A refused save's input and problems, for the screen that shows it, by screen.
	 *
	 * @var array<string, array{values: array<string, string>, errors: array<string, string>}>
	 */
	private static array $refused = array();

	/**
	 * Hooks the screen.
	 *
	 * @throws \LogicException When a screen hasn't named its post type, kind or nonce,
	 *                         which would hook nothing it means to, or this class.
	 */
	public static function hook(): void {
		if ( '' === static::POST_TYPE || '' === static::KIND || '' === static::NONCE || '' === static::NONCE_FIELD ) {
			throw new \LogicException( 'An edit screen names its post type, kind and nonce.' );
		}
		add_action( 'add_meta_boxes_' . static::POST_TYPE, array( static::class, 'boxes' ) );
		add_action( 'save_post_' . static::POST_TYPE, array( static::class, 'save' ), 10, 2 );
		add_action( 'load-post.php', array( static::class, 'load' ) );
		add_action( 'edit_form_top', array( static::class, 'notice' ) );
		add_filter( 'post_updated_messages', array( static::class, 'messages' ) );
		add_filter( 'wp_refresh_nonces', array( static::class, 'refresh_nonce' ), 11, 2 );
	}

	/**
	 * Adds the screen's boxes.
	 */
	abstract public static function boxes(): void;

	/**
	 * The box of fields, as a form inside WordPress's.
	 *
	 * @param \WP_Post $post The post being edited.
	 */
	abstract public static function editorial_box( \WP_Post $post ): void;

	/**
	 * WordPress's notices after saving, in the post type's words.
	 *
	 * @param array<string, array<int, string>> $messages The notices, by post type.
	 * @return array<string, array<int, string>>
	 */
	abstract public static function messages( array $messages ): array;

	/**
	 * Checks the form's input against the post's stored fields: what to save, what to
	 * remove, what's wrong, by field, and the input as the form shows it back.
	 *
	 * @param array<string, mixed> $fields The post's stored fields (stored()).
	 * @param array<string, mixed> $input  The form's input, unslashed.
	 * @return array{set: array<string, mixed>, delete: list<string>, errors: array<string, string>, values: array<string, string>}
	 */
	abstract public static function check( array $fields, array $input ): array;

	/**
	 * The post's stored fields, as Store reads them.
	 *
	 * @param \WP_Post $post The post.
	 * @return array<string, mixed>
	 */
	abstract protected static function stored( \WP_Post $post ): array;

	/**
	 * Each field's label, by name, for the refusal's list of problems.
	 *
	 * @return array<string, string>
	 */
	abstract protected static function field_labels(): array;

	/**
	 * The words before the browser's title after a refused save, such as "Editorial
	 * fields not saved:".
	 */
	abstract protected static function refused_title(): string;

	/**
	 * The refusal's heading, with how many problems there are.
	 *
	 * @param int $count How many problems.
	 */
	abstract protected static function problems_heading( int $count ): string;

	/**
	 * What to do when a field is marked.
	 *
	 * @param array<string, string> $errors The problems, by field.
	 */
	abstract protected static function fix_hint( array $errors ): string;

	/**
	 * What WordPress saved anyway, or "".
	 *
	 * @param array<string, string> $errors The problems, by field.
	 */
	abstract protected static function saved_hint( array $errors ): string;

	/**
	 * The problem when the screen's nonce had gone stale.
	 */
	abstract protected static function stale_message(): string;

	/**
	 * The screen's own styles, beside the shared ones.
	 */
	protected static function own_style(): string {
		return '';
	}

	/**
	 * Problems with the post's own fields that WordPress was asked to save in this
	 * request, by field: none, unless a screen says so.
	 *
	 * @param int $post_id The post.
	 * @return array<string, string>
	 */
	protected static function post_errors( int $post_id ): array {
		unset( $post_id );
		return array();
	}

	/**
	 * Where the refusal's list links a field's problem to: its control.
	 *
	 * @param string $name The field.
	 */
	protected static function anchor( string $name ): string {
		return '#fh-' . static::KIND . '-' . $name;
	}

	/**
	 * Each field of the form's input, as the form shows it back, and the problems: a
	 * field that's missing or isn't text is refused, rather than taken as empty, since
	 * the form always sends each as text, and so is one that isn't valid UTF-8, which
	 * the database would refuse after the others were saved. A browser's line breaks
	 * (CRLF) become LF.
	 *
	 * @param array<string, mixed> $input The form's input, unslashed.
	 * @return array{values: array<string, string>, errors: array<string, string>}
	 */
	protected static function texts( array $input ): array {
		$values = array();
		$errors = array();
		foreach ( static::FIELDS as $name ) {
			$value           = $input[ $name ] ?? null;
			$values[ $name ] = is_string( $value ) ? str_replace( "\r\n", "\n", $value ) : '';
			if ( ! is_string( $value ) ) {
				$errors[ $name ] = __( 'This field didn’t arrive as text. Reload the screen and enter it again.', 'firehouse' );
			} elseif ( ! self::is_utf8( $value ) ) {
				$errors[ $name ] = __( 'This field has characters that can’t be stored. Retype it.', 'firehouse' );
				// Shown back with each bad byte as �, so the refusal can be kept for the next
				// screen at all: the database would refuse to store it too.
				$values[ $name ] = htmlspecialchars_decode( htmlspecialchars( $values[ $name ], ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' ), ENT_NOQUOTES );
			}
		}
		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * Whether text is valid UTF-8.
	 *
	 * @param string $text The text.
	 */
	protected static function is_utf8( string $text ): bool {
		return 1 === preg_match( '//u', $text );
	}

	/**
	 * Whether text would show nothing: only spaces, line breaks, control characters
	 * and invisible ones such as a no-break or zero-width space, written as characters
	 * or as entities, since pages print text escaped without escaping an entity again. Text
	 * that isn't valid UTF-8 shows nothing either.
	 *
	 * @param string $text The text.
	 */
	protected static function blank( string $text ): bool {
		return 1 !== preg_match( '/[^\p{Z}\p{Cc}\p{Cf}]/u', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * A refused save's input and problems, if this screen is showing one.
	 *
	 * @return array{values: array<string, string>, errors: array<string, string>}|null
	 */
	protected static function refused(): ?array {
		return self::$refused[ static::class ] ?? null;
	}

	/**
	 * On the screen's edit screen, before anything is drawn: adds the styles, and takes
	 * back a refused save's input, once. After a refusal, the browser's title says so,
	 * so a screen reader's first words do, and the box of fields is open and shown even
	 * if the editor had collapsed or hidden it, so the notice's links reach its fields.
	 */
	public static function load(): void {
		unset( self::$refused[ static::class ] );
		$post = static::screen_post();
		if ( null === $post ) {
			return;
		}
		wp_add_inline_style( 'common', self::STYLE . static::own_style() );
		$key     = static::transient( $post->ID );
		$refused = get_transient( $key );
		delete_transient( $key );
		if ( is_array( $refused ) && is_array( $refused['values'] ?? null ) && is_array( $refused['errors'] ?? null ) && array() !== $refused['errors'] ) {
			self::$refused[ static::class ] = array(
				'values' => array_map( 'strval', $refused['values'] ),
				'errors' => array_map( 'strval', $refused['errors'] ),
			);
			add_filter( 'admin_title', array( static::class, 'title' ) );
			add_filter( 'postbox_classes_' . static::POST_TYPE . '_' . self::BOX, array( static::class, 'open_box' ) );
			add_filter( 'hidden_meta_boxes', array( static::class, 'show_box' ) );
		}
	}

	/**
	 * The browser's title after a refused save.
	 *
	 * @param string $title The admin page's title.
	 */
	public static function title( string $title ): string {
		return static::refused_title() . ' ' . $title;
	}

	/**
	 * The box's classes, without the one that collapses it.
	 *
	 * @param string[] $classes The box's classes.
	 * @return string[]
	 */
	public static function open_box( array $classes ): array {
		return array_values( array_diff( $classes, array( 'closed' ) ) );
	}

	/**
	 * The boxes the editor has hidden, without the box of fields.
	 *
	 * @param string[] $hidden The hidden boxes' IDs.
	 * @return string[]
	 */
	public static function show_box( array $hidden ): array {
		return array_values( array_diff( $hidden, array( self::BOX ) ) );
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
	 * The post the current screen edits, if it's this screen's post type's edit screen.
	 */
	protected static function screen_post(): ?\WP_Post {
		$id   = isset( $_GET['post'] ) && is_string( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads which post the screen shows; WordPress checks it may be edited.
		$post = 0 === $id ? null : get_post( $id );
		return $post instanceof \WP_Post && static::POST_TYPE === $post->post_type ? $post : null;
	}

	/**
	 * One field: its label, its control, its description, and its problem, if any,
	 * which the control is described by and marked invalid for. The control's ID is
	 * fh-{kind}-{name}.
	 *
	 * @param string                $name        The field.
	 * @param string                $label       Its label.
	 * @param callable              $control     Builds the control's markup, escaped, from its extra attributes.
	 * @param string                $description What it's for.
	 * @param array<string, string> $errors      The problems, by field.
	 */
	protected static function field( string $name, string $label, callable $control, string $description, array $errors ): void {
		$id          = 'fh-' . static::KIND . '-' . $name;
		$described   = $id . '-description';
		$error       = $errors[ $name ] ?? null;
		$attributes  = null === $error ? '' : ' aria-invalid="true"';
		$attributes .= ' aria-describedby="' . esc_attr( ( null === $error ? '' : $id . '-error ' ) . $described ) . '"';

		echo '<div class="fh-edit-field">';
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		echo $control( $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the control and its attributes are escaped where they're built.
		if ( null !== $error ) {
			static::error( $id, $error );
		}
		echo '<p class="description" id="' . esc_attr( $described ) . '">' . esc_html( $description ) . '</p>';
		echo '</div>';
	}

	/**
	 * A field's problem, under its control.
	 *
	 * @param string $id      The control's ID.
	 * @param string $message The problem.
	 */
	protected static function error( string $id, string $message ): void {
		echo '<p class="fh-edit-field__error" id="' . esc_attr( $id . '-error' ) . '"><strong>' . esc_html__( 'Error:', 'firehouse' ) . '</strong> ' . esc_html( $message ) . '</p>';
	}

	/**
	 * One row of a read-only box: its label, and its value, or a note that there's
	 * none, set in italics, as the screen's own words are, apart from what's stored.
	 *
	 * @param string $label The label.
	 * @param string $value The value's markup, escaped; '' for none.
	 * @param string $none  The note for none; "(No answer)" if not given.
	 */
	protected static function row( string $label, string $value, string $none = '' ): void {
		$none = '' === $none ? __( '(No answer)', 'firehouse' ) : $none;
		echo '<dt>' . esc_html( $label ) . '</dt>';
		echo '<dd>' . ( '' === $value ? '<span class="fh-edit-note">' . esc_html( $none ) . '</span>' : $value ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the value is escaped where it's built.
	}

	/**
	 * A list, each item escaped already; '' for none.
	 *
	 * @param array<int, string> $items Each item's markup.
	 * @param string             $tag   ul or ol.
	 */
	protected static function items( array $items, string $tag = 'ul' ): string {
		$items = array_values( array_filter( $items, static fn( string $item ): bool => '' !== $item ) );
		if ( array() === $items ) {
			return '';
		}
		return '<' . $tag . '><li>' . implode( '</li><li>', $items ) . '</li></' . $tag . '>';
	}

	/**
	 * Refreshes the screen's nonce when WordPress refreshes its own, through the
	 * heartbeat: as a nonce ages, and after signing in again in the session-expired
	 * dialog, when every nonce the page holds is stale. WordPress's script sets each
	 * value it's sent by the element's ID, which wp_nonce_field() gives the name.
	 * Without it, the screen's form would go stale while WordPress's own nonce stayed
	 * fresh, and the save would drop the fields.
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
		if ( static::POST_TYPE !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return $response;
		}
		$response['wp-refresh-post-nonces']['replace'][ static::NONCE_FIELD ] = wp_create_nonce( static::NONCE . '_' . $id );
		return $response;
	}

	/**
	 * The nonce field for the screen's form.
	 *
	 * @param int $post_id The post being edited.
	 */
	protected static function nonce_field( int $post_id ): void {
		wp_nonce_field( static::NONCE . '_' . $post_id, static::NONCE_FIELD, false );
	}

	/**
	 * Saves the fields with the post, from the screen's own form only (Save Draft,
	 * Publish, Update, or a draft's Preview, which saves the draft): not from Quick
	 * Edit, an autosave, or the plugin's own writes. Nothing is saved if any field is
	 * wrong, or if the post's own fields had a problem (post_errors()); the input and
	 * problems wait for the screen WordPress redirects to. A good save clears any
	 * refusal still waiting, such as one from a preview, whose window never shows the
	 * edit screen.
	 *
	 * @param int      $post_id The post.
	 * @param \WP_Post $post    The post as saved.
	 */
	public static function save( int $post_id, \WP_Post $post ): void {
		if ( Store::writing() || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || static::POST_TYPE !== $post->post_type ) {
			return;
		}
		if ( ! isset( $_POST[ static::NONCE_FIELD ] ) || ! is_string( $_POST[ static::NONCE_FIELD ] ) ) {
			return;
		}
		$fresh = wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ static::NONCE_FIELD ] ) ), static::NONCE . '_' . $post_id );
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

		$checked = static::check( static::stored( $post ), is_array( $input ) ? $input : array() );
		// The post's own fields' problems first, as the post's own fields come first on
		// the screen.
		$errors = static::post_errors( $post_id ) + $checked['errors'];
		if ( array() !== $errors ) {
			self::keep( $post_id, $checked['values'], $errors );
			return;
		}
		delete_transient( static::transient( $post_id ) );
		Store::update_fields( $post_id, $checked['set'], $checked['delete'] );
	}

	/**
	 * When the screen's nonce has gone stale but WordPress's own save of the post went
	 * through (post.php's editpost, which checks WordPress's nonce first), the fields
	 * aren't saved, and the screen says so and shows what was typed, rather than
	 * dropping it quietly under WordPress's "updated" notice. Anything else with a stale
	 * nonce changes nothing and says nothing.
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
		$typed = static::check( static::stored( $post ), is_array( $input ) ? $input : array() )['values'];
		self::keep( $post_id, $typed, array( self::FORM => static::stale_message() ) + static::post_errors( $post_id ) );
	}

	/**
	 * Keeps a refused save's input and problems for the screen WordPress redirects to,
	 * and leaves WordPress's "updated" notice off it.
	 *
	 * @param int                   $post_id The post.
	 * @param array<string, string> $values  The input, as the form shows it back.
	 * @param array<string, string> $errors  The problems, by field.
	 */
	private static function keep( int $post_id, array $values, array $errors ): void {
		set_transient(
			static::transient( $post_id ),
			array(
				'values' => $values,
				'errors' => $errors,
			),
			self::KEEP
		);
		add_filter( 'redirect_post_location', array( static::class, 'without_message' ) );
	}

	/**
	 * After a refused save, a summary at the top of the form naming each problem, each
	 * a link to its field. It takes the focus when the page loads, named by its heading
	 * and outlined, and is `inline` so WordPress's script doesn't move it, which would
	 * drop the focus, as the Import screen's summary does.
	 *
	 * @param \WP_Post|null $post The post being edited.
	 */
	public static function notice( ?\WP_Post $post = null ): void {
		$refused = static::refused();
		if ( null === $refused || ( null !== $post && static::POST_TYPE !== $post->post_type ) ) {
			return;
		}
		$count  = count( $refused['errors'] );
		$labels = static::field_labels();
		$id     = 'firehouse-' . static::KIND . '-errors';
		echo '<div id="' . esc_attr( $id ) . '" class="notice notice-error inline fh-edit-errors" role="group" tabindex="-1" autofocus aria-labelledby="' . esc_attr( $id . '-heading' ) . '"><h2 id="' . esc_attr( $id . '-heading' ) . '">';
		echo esc_html( static::problems_heading( $count ) );
		$marked = array() !== array_diff_key( $refused['errors'], array( self::FORM => true ) );
		$saved  = static::saved_hint( $refused['errors'] );
		echo '</h2><p>' . ( $marked ? esc_html( static::fix_hint( $refused['errors'] ) ) . ( '' !== $saved ? ' ' : '' ) : '' ) . esc_html( $saved ) . '</p><ul>';
		foreach ( $refused['errors'] as $name => $message ) {
			echo self::FORM === $name
				? '<li>' . esc_html( $message ) . '</li>'
				: '<li><a href="' . esc_attr( static::anchor( $name ) ) . '">' . esc_html( $labels[ $name ] ?? $name ) . '</a>: ' . esc_html( $message ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * The transient that keeps a refused save for the screen after it, one per post
	 * and user.
	 *
	 * @param int $post_id The post.
	 */
	protected static function transient( int $post_id ): string {
		return 'firehouse_' . static::KIND . '_' . $post_id . '_' . get_current_user_id();
	}
}
