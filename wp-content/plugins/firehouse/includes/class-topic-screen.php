<?php
/**
 * The topic edit screen.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a topic's fields to WordPress's own edit screen, below its label, which is the
 * post's title, in two boxes:
 *
 * - **Topic fields**, the FireHouse team's words for the area: its short label, its
 *   description and what it covers. They're saved with the post, all or nothing,
 *   as Edit_Screen saves a project's editorial fields.
 * - **Synthesis of needs**, shown read-only: the needs the last synthesis found, who
 *   reviewed it, and when. A new synthesis replaces it; it isn't edited here.
 *
 * The topic's introduction isn't on the screen: no page shows it (types.ts). Nor is
 * its order, which places it among the four, or its key and status, which no one
 * changes (Capabilities).
 *
 * Its label is kept as Store keeps it, escaped and trimmed, whichever route saves it,
 * and is never left empty: an empty one keeps the label the topic had, and the
 * screen says so as one of its problems.
 */
final class Topic_Screen extends Edit_Screen {

	/**
	 * The post type the screen edits.
	 */
	public const POST_TYPE = Post_Types::TOPIC;

	/**
	 * The word in the fields' IDs, the refusal's transient and its notice.
	 */
	public const KIND = 'topic';

	/**
	 * The nonce's action.
	 */
	public const NONCE = 'firehouse_topic_fields';

	/**
	 * The nonce's field.
	 */
	public const NONCE_FIELD = 'firehouse_topic_nonce';

	/**
	 * The fields the screen edits, in the order it shows them.
	 */
	public const FIELDS = array( 'short', 'description', 'covers' );

	/**
	 * The problems' key for the label, the post's title.
	 */
	public const LABEL = 'label';

	/**
	 * The topics whose label this request kept, because the one sent was empty, by ID.
	 *
	 * @var array<int, true>
	 */
	private static array $kept = array();

	/**
	 * Hooks the screen, the label's rule, and the label's problem under its box.
	 */
	public static function hook(): void {
		parent::hook();
		// Late, as Project_Rules's rules are, so another plugin's filter can't undo it.
		add_filter( 'wp_insert_post_data', array( self::class, 'keep_label' ), PHP_INT_MAX - 10, 2 );
		add_filter( 'title_edit_pre', array( self::class, 'edit_label' ), 10, 2 );
		add_action( 'edit_form_after_title', array( self::class, 'label_error' ) );
	}

	/**
	 * A topic's label as WordPress's title box shows it: as text. The label is stored
	 * escaped (keep_label()), and the box escapes what it's given again, so without
	 * this it would show "&amp;" for "&". Saved, the text is escaped once more, so the
	 * label stays as it was.
	 *
	 * @param mixed $title   The stored title.
	 * @param mixed $post_id The post.
	 * @return mixed
	 */
	public static function edit_label( mixed $title, mixed $post_id ): mixed {
		if ( ! is_string( $title ) || ! is_numeric( $post_id ) || Post_Types::TOPIC !== get_post_type( (int) $post_id ) ) {
			return $title;
		}
		return wp_specialchars_decode( $title, ENT_QUOTES );
	}

	/**
	 * Keeps a topic's label, its post's title, as Store writes it (Store::label()),
	 * escaped and trimmed, by any route that saves it: this screen, Quick Edit, a
	 * restore. The title arrives as WordPress has filtered it, entities and all, so
	 * it's read back to text first, as Store::topic() reads it. An empty one keeps
	 * the label the topic had: every topic page's heading is its label. The plugin's
	 * own writes, the Import screen's, are left alone.
	 *
	 * WordPress passes $data slashed, so the title is unslashed to read it and slashed
	 * going back. It has already encoded the title's emoji on an older table, which
	 * reading and escaping leave as they are.
	 *
	 * @param array<string, mixed> $data    The post's fields as they will be saved, slashed.
	 * @param array<string, mixed> $postarr The post as given.
	 * @return array<string, mixed>
	 */
	public static function keep_label( array $data, array $postarr ): array {
		// The ID decides, not $update: get_post( 0 ) would be whatever post is current.
		$id     = (int) ( $postarr['ID'] ?? 0 );
		$stored = 0 === $id ? null : get_post( $id );
		if ( Store::writing() || ! $stored instanceof \WP_Post || Post_Types::TOPIC !== $stored->post_type ) {
			return $data;
		}
		$title = is_string( $data['post_title'] ?? null ) ? $data['post_title'] : '';
		$label = trim( wp_specialchars_decode( wp_unslash( $title ), ENT_QUOTES ) );
		if ( '' === $label ) {
			self::$kept[ $id ]  = true;
			$data['post_title'] = wp_slash( $stored->post_title );
			return $data;
		}
		$data['post_title'] = wp_slash( Store::label( $label ) );
		return $data;
	}

	/**
	 * A kept label is a problem with the save, so the screen's fields aren't saved
	 * either, and the screen says why.
	 *
	 * @param int $post_id The topic.
	 * @return array<string, string>
	 */
	protected static function post_errors( int $post_id ): array {
		if ( ! isset( self::$kept[ $post_id ] ) ) {
			return array();
		}
		$post  = get_post( $post_id );
		$label = $post instanceof \WP_Post ? wp_specialchars_decode( $post->post_title, ENT_QUOTES ) : '';
		/* translators: %s: the topic's label. */
		return array( self::LABEL => sprintf( __( 'A topic needs a label, so it’s still “%s”.', 'firehouse' ), $label ) );
	}

	/**
	 * The label's problem, under the label, which is WordPress's own field.
	 *
	 * @param \WP_Post $post The post being edited.
	 */
	public static function label_error( \WP_Post $post ): void {
		$message = self::refused()['errors'][ self::LABEL ] ?? null;
		if ( Post_Types::TOPIC !== $post->post_type || ! is_string( $message ) ) {
			return;
		}
		echo '<div class="fh-edit-screen">';
		self::error( 'fh-topic-' . self::LABEL, $message );
		echo '</div>';
	}

	/**
	 * Where the refusal's list links a problem to: the label's is WordPress's title box.
	 *
	 * @param string $name The field.
	 */
	protected static function anchor( string $name ): string {
		return self::LABEL === $name ? '#title' : parent::anchor( $name );
	}

	/**
	 * Adds the two boxes.
	 */
	public static function boxes(): void {
		add_meta_box( self::BOX, __( 'Topic fields', 'firehouse' ), array( self::class, 'editorial_box' ), Post_Types::TOPIC, 'normal', 'high' );
		add_meta_box( 'firehouse-synthesis', __( 'Synthesis of needs', 'firehouse' ), array( self::class, 'synthesis_box' ), Post_Types::TOPIC, 'normal', 'high' );
	}

	/**
	 * The topic's fields, as a form inside WordPress's.
	 *
	 * @param \WP_Post $post The post being edited.
	 */
	public static function editorial_box( \WP_Post $post ): void {
		$values = self::refused()['values'] ?? self::values( self::stored( $post ) );
		$errors = self::refused()['errors'] ?? array();
		self::nonce_field( $post->ID );

		echo '<div class="fh-edit-screen">';
		echo '<p>' . esc_html__( 'The FireHouse team’s words for this topic area. Updating the topic saves them, with its label above.', 'firehouse' ) . '</p>';

		self::field(
			'short',
			__( 'Short label', 'firehouse' ),
			static fn( string $attributes ): string => '<input type="text" id="fh-topic-short" name="' . esc_attr( self::INPUT ) . '[short]" value="' . Markup::text( $values['short'] ) . '"' . $attributes . '>',
			__( 'The area’s name on its tags and in the topic page’s breadcrumb, such as “Observations”. A word or two.', 'firehouse' ),
			$errors
		);

		// A browser drops a line break straight after <textarea>, so one is printed for it
		// to drop, and text that starts with its own line break keeps it.
		self::field(
			'description',
			__( 'Description', 'firehouse' ),
			static fn( string $attributes ): string => '<textarea id="fh-topic-description" name="' . esc_attr( self::INPUT ) . '[description]" rows="2"' . $attributes . ">\n" . Markup::text( $values['description'] ) . '</textarea>',
			__( 'One sentence on what the area is about. The site doesn’t show it; the source document a synthesis reads uses it to describe the area.', 'firehouse' ),
			$errors
		);

		self::field(
			'covers',
			__( 'What this area covers', 'firehouse' ),
			static fn( string $attributes ): string => '<textarea id="fh-topic-covers" name="' . esc_attr( self::INPUT ) . '[covers]" rows="5"' . $attributes . ">\n" . Markup::text( $values['covers'] ) . '</textarea>',
			__( 'One kind of question per line. The topic page lists them beside the needs, under “What this area covers”, and leaves that box out when there are none.', 'firehouse' ),
			$errors
		);
		echo '</div>';
	}

	/**
	 * The topic's stored fields, as the form shows them: '' for none, and what it
	 * covers one per line.
	 *
	 * @param array<string, mixed> $fields The topic's fields (Store::topic()).
	 * @return array<string, string>
	 */
	public static function values( array $fields ): array {
		$covers = is_array( $fields['covers'] ?? null ) ? array_filter( $fields['covers'], 'is_string' ) : array();
		return array(
			'short'       => is_string( $fields['short'] ?? null ) ? $fields['short'] : '',
			'description' => is_string( $fields['description'] ?? null ) ? $fields['description'] : '',
			'covers'      => implode( "\n", $covers ),
		);
	}

	/**
	 * The synthesis, read-only, as stored: who reviewed it, when, how many projects it
	 * read and what made it, and its needs, each with its project count and the
	 * projects it names.
	 *
	 * @param \WP_Post $post The post being edited.
	 */
	public static function synthesis_box( \WP_Post $post ): void {
		$fields = self::stored( $post );
		$text   = static fn( string $name ): string => is_string( $fields[ $name ] ?? null ) ? $fields[ $name ] : '';
		$none   = __( '(Not recorded)', 'firehouse' );
		$count  = $fields['sourceCount'] ?? null;
		$needs  = is_array( $fields['topNeeds'] ?? null ) ? array_values( $fields['topNeeds'] ) : array();

		echo '<p>' . esc_html__( 'The needs this area’s projects raise, as the last synthesis found them. They can’t be edited here: a new synthesis replaces them.', 'firehouse' ) . '</p>';
		echo '<dl class="fh-edit-readonly">';
		self::row( __( 'Reviewed by', 'firehouse' ), Markup::text( $text( 'reviewedBy' ) ), __( 'No one yet. The topic page marks the needs “Draft, pending team review”.', 'firehouse' ) );
		self::row( __( 'Last updated', 'firehouse' ), Markup::text( $text( 'updatedAt' ) ), $none );
		self::row( __( 'Projects it read', 'firehouse' ), is_int( $count ) || is_float( $count ) ? esc_html( (string) $count ) : '', $none );
		self::row( __( 'Made with', 'firehouse' ), Markup::text( $text( 'model' ) ), $none );
		self::row( __( 'Top needs', 'firehouse' ), self::items( array_map( array( self::class, 'need' ), $needs ), 'ol' ), __( '(None)', 'firehouse' ) );
		echo '</dl>';
	}

	/**
	 * A need: its text, its project count as stored (or the number of projects it
	 * names), and those projects, each by its title, marked if visitors can't see it.
	 *
	 * @param mixed $need The need, its projects as post IDs.
	 */
	private static function need( mixed $need ): string {
		if ( ! is_array( $need ) || ! is_string( $need['text'] ?? null ) ) {
			return '';
		}
		$ids      = is_array( $need['projects'] ?? null ) ? array_values( array_filter( $need['projects'], 'is_int' ) ) : array();
		$mentions = is_int( $need['mentions'] ?? null ) || is_float( $need['mentions'] ?? null ) ? (int) $need['mentions'] : count( $ids );
		/* translators: %d: how many projects raise the need. */
		$markup = Markup::text( $need['text'] ) . "\n" . esc_html( sprintf( __( 'Project count: %d', 'firehouse' ), $mentions ) );
		return $markup . self::items( array_map( array( self::class, 'source' ), $ids ) );
	}

	/**
	 * A project a need names: its title, linked to its edit screen for someone who may
	 * edit it, and a note if visitors can't see it.
	 *
	 * @param int $id The project's post ID.
	 */
	private static function source( int $id ): string {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || Post_Types::PROJECT !== $post->post_type ) {
			/* translators: %d: a post ID. */
			return '<span class="fh-edit-note">' . esc_html( sprintf( __( 'A project that’s gone (ID %d)', 'firehouse' ), $id ) ) . '</span>';
		}
		$title = get_post_meta( $id, Meta::key( 'title' ), true );
		$title = Markup::text( is_string( $title ) && '' !== $title ? $title : wp_specialchars_decode( $post->post_title, ENT_QUOTES ) );
		$link  = current_user_can( 'edit_post', $id ) ? get_edit_post_link( $id ) : null;
		$name  = is_string( $link ) && '' !== $link ? '<a href="' . esc_url( $link ) . '">' . $title . '</a>' : $title;
		return $name . ( Store::is_public( $post ) ? '' : ' <span class="fh-edit-note">' . esc_html__( '(not on the site)', 'firehouse' ) . '</span>' );
	}

	/**
	 * WordPress's notices after saving, in a topic's words. The fields' refusal has its
	 * own notice.
	 *
	 * @param array<string, array<int, string>> $messages The notices, by post type.
	 * @return array<string, array<int, string>>
	 */
	public static function messages( array $messages ): array {
		$post                          = get_post();
		$view                          = $post instanceof \WP_Post ? ' <a href="' . esc_url( (string) get_permalink( $post ) ) . '">' . esc_html__( 'View topic', 'firehouse' ) . '</a>' : '';
		$messages[ Post_Types::TOPIC ] = array(
			0 => '',
			1 => esc_html__( 'Topic updated.', 'firehouse' ) . $view,
			4 => esc_html__( 'Topic updated.', 'firehouse' ),
		) + ( $messages['post'] ?? array() );
		return $messages;
	}

	/**
	 * Checks the form's input: what to save, what to remove, and what's wrong, by
	 * field. Every field is the team's, so the stored ones don't decide anything.
	 *
	 * Text is kept as typed, but for the line breaks a browser sends (CRLF), which
	 * become LF. The short label can't be blank. An empty description removes it. What
	 * the area covers is one item a line, each trimmed, blank lines dropped; none
	 * removes it. A field that's missing or isn't text is refused, rather than taken as
	 * empty: the form always sends each as text.
	 *
	 * @param array<string, mixed> $fields The topic's stored fields.
	 * @param array<string, mixed> $input  The form's input, unslashed.
	 * @return array{set: array<string, mixed>, delete: list<string>, errors: array<string, string>, values: array<string, string>}
	 */
	public static function check( array $fields, array $input ): array {
		unset( $fields );
		$values = array();
		$errors = array();
		foreach ( self::FIELDS as $name ) {
			$value           = $input[ $name ] ?? null;
			$values[ $name ] = is_string( $value ) ? str_replace( "\r\n", "\n", $value ) : '';
			if ( ! is_string( $value ) ) {
				$errors[ $name ] = __( 'This field didn’t arrive as text. Reload the screen and enter it again.', 'firehouse' );
			}
		}

		$set    = array();
		$delete = array();

		if ( '' === trim( $values['short'] ) ) {
			$errors['short'] ??= __( 'A topic needs a short label.', 'firehouse' );
		} else {
			$set['short'] = $values['short'];
		}

		if ( '' === trim( $values['description'] ) ) {
			$delete[] = 'description';
		} else {
			$set['description'] = $values['description'];
		}

		$covers = array_values( array_filter( array_map( 'trim', explode( "\n", $values['covers'] ) ), static fn( string $line ): bool => '' !== $line ) );
		if ( array() === $covers ) {
			$delete[] = 'covers';
		} else {
			$set['covers'] = $covers;
		}

		foreach ( $set as $name => $value ) {
			$valid = Meta::validate( Post_Types::TOPIC, $name, $value );
			if ( true !== $valid ) {
				$errors[ $name ] ??= $valid->get_error_message();
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
	 * The topic's stored fields, its label among them.
	 *
	 * @param \WP_Post $post The topic.
	 * @return array<string, mixed>
	 */
	protected static function stored( \WP_Post $post ): array {
		return Store::topic( $post )['fields'];
	}

	/**
	 * Each field's label, for the refusal's list of problems.
	 *
	 * @return array<string, string>
	 */
	protected static function field_labels(): array {
		return array(
			self::LABEL   => __( 'Label', 'firehouse' ),
			'short'       => __( 'Short label', 'firehouse' ),
			'description' => __( 'Description', 'firehouse' ),
			'covers'      => __( 'What this area covers', 'firehouse' ),
		);
	}

	/**
	 * The words before the browser's title after a refused save.
	 */
	protected static function refused_title(): string {
		return __( 'Topic fields not saved:', 'firehouse' );
	}

	/**
	 * The refusal's heading.
	 *
	 * @param int $count How many problems.
	 */
	protected static function problems_heading( int $count ): string {
		/* translators: %d: how many problems. */
		return sprintf( _n( 'Topic fields not saved: %d problem', 'Topic fields not saved: %d problems', $count, 'firehouse' ), $count );
	}

	/**
	 * What to do when a field is marked.
	 */
	protected static function fix_hint(): string {
		return __( 'Fix what’s marked, then update the topic again.', 'firehouse' );
	}

	/**
	 * What WordPress saved anyway: the label, unless it was kept.
	 *
	 * @param array<string, string> $errors The problems, by field.
	 */
	protected static function saved_hint( array $errors ): string {
		return isset( $errors[ self::LABEL ] ) ? '' : __( 'The label above was saved.', 'firehouse' );
	}

	/**
	 * The problem when the screen's nonce had gone stale.
	 */
	protected static function stale_message(): string {
		return __( 'The screen had been open too long, so they weren’t saved. They’re below as you typed them: update the topic again.', 'firehouse' );
	}
}
