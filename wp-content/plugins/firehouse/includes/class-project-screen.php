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
 * its status. Edit_Screen holds what this screen shares with the topic screen: the
 * nonce, the save, the refusal and its notice.
 *
 * The slug, the Publish box and the post's own title follow Project_Rules: no project
 * without IRB approval is published, and a published one keeps its address.
 */
final class Project_Screen extends Edit_Screen {

	/**
	 * The post type the screen edits.
	 */
	public const POST_TYPE = Post_Types::PROJECT;

	/**
	 * The word in the fields' IDs, the refusal's transient and its notice.
	 */
	public const KIND = 'project';

	/**
	 * The nonce's action.
	 */
	public const NONCE = 'firehouse_project_fields';

	/**
	 * The nonce's field.
	 */
	public const NONCE_FIELD = 'firehouse_project_nonce';

	/**
	 * The editorial fields the screen edits, in the order it shows them. geoNote is
	 * the note in the project's geography.
	 */
	public const FIELDS = array( 'summary', 'publicationStatus', 'geoNote', 'fullRecordUrl' );

	/**
	 * The screen's own styles: the researcher's title under the heading.
	 */
	private const STYLE = '#poststuff .fh-project-screen__title{margin:1em 0 0;padding:0;font-size:1.3em;line-height:1.4}';

	/**
	 * Hooks the screen, and the researcher's title under its heading.
	 */
	public static function hook(): void {
		parent::hook();
		add_action( 'edit_form_after_title', array( self::class, 'heading' ) );
	}

	/**
	 * The screen's own styles.
	 */
	protected static function own_style(): string {
		return self::STYLE;
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
	 * The project's stored fields.
	 *
	 * @param \WP_Post $post The project.
	 * @return array<string, mixed>
	 */
	protected static function stored( \WP_Post $post ): array {
		return Store::project( $post )['fields'];
	}

	/**
	 * Each editorial field's label, for the refusal's list of problems.
	 *
	 * @return array<string, string>
	 */
	protected static function field_labels(): array {
		return array(
			'summary'           => __( 'Summary', 'firehouse' ),
			'publicationStatus' => __( 'Publication status', 'firehouse' ),
			'geoNote'           => __( 'Geography note', 'firehouse' ),
			'fullRecordUrl'     => __( 'Full record link', 'firehouse' ),
		);
	}

	/**
	 * The words before the browser's title after a refused save.
	 */
	protected static function refused_title(): string {
		return __( 'Editorial fields not saved:', 'firehouse' );
	}

	/**
	 * The refusal's heading.
	 *
	 * @param int $count How many problems.
	 */
	protected static function problems_heading( int $count ): string {
		/* translators: %d: how many problems. */
		return sprintf( _n( 'Editorial fields not saved: %d problem', 'Editorial fields not saved: %d problems', $count, 'firehouse' ), $count );
	}

	/**
	 * What to do when a field is marked.
	 *
	 * @param array<string, string> $errors The problems, by field.
	 */
	protected static function fix_hint( array $errors ): string {
		unset( $errors );
		return __( 'Fix what’s marked, then save the project again.', 'firehouse' );
	}

	/**
	 * What WordPress saved anyway.
	 *
	 * @param array<string, string> $errors The problems, by field.
	 */
	protected static function saved_hint( array $errors ): string {
		unset( $errors );
		return __( 'The project’s other changes, such as its status, were saved.', 'firehouse' );
	}

	/**
	 * The problem when the screen's nonce had gone stale.
	 */
	protected static function stale_message(): string {
		return __( 'The screen had been open too long, so they weren’t saved. They’re below as you typed them: save the project again.', 'firehouse' );
	}

	/**
	 * Adds the two boxes.
	 */
	public static function boxes(): void {
		add_meta_box( self::BOX, __( 'Editorial fields', 'firehouse' ), array( self::class, 'editorial_box' ), Post_Types::PROJECT, 'normal', 'high' );
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
		$values = self::refused()['values'] ?? self::values( Store::project( $post )['fields'] );
		$errors = self::refused()['errors'] ?? array();
		self::nonce_field( $post->ID );

		echo '<div class="fh-edit-screen">';
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
		echo '<dl class="fh-edit-readonly">';
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
			'<span class="fh-edit-note">' . esc_html(
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
	 * Checks the form's input against the project's stored fields: what to save, what
	 * to remove, and what's wrong, by field.
	 *
	 * Text is kept as typed, but for the line breaks a browser sends (CRLF), which
	 * become LF; an address is trimmed. An empty status, note or address removes it.
	 * The geography keeps its regions, which are the survey's. A field that's missing,
	 * isn't text or isn't valid UTF-8 is refused (texts()).
	 *
	 * @param array<string, mixed> $fields The project's stored fields.
	 * @param array<string, mixed> $input  The form's input, unslashed.
	 * @return array{set: array<string, mixed>, delete: list<string>, errors: array<string, string>, values: array<string, string>}
	 */
	public static function check( array $fields, array $input ): array {
		$texts                   = self::texts( $input );
		$values                  = $texts['values'];
		$errors                  = $texts['errors'];
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
}
