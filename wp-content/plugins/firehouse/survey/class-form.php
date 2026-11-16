<?php
/**
 * The survey form's markup.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Survey;

use Firehouse\Markup;
use Firehouse\Query;
use Firehouse\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Draws the Submit page's block from Survey::state(): the form, empty or as sent with
 * what's wrong with it, the thanks, or that the form is closed. Accessible without
 * JavaScript (the plan's survey form): a label for every field, a fieldset and legend
 * for every group of choices and entries, each field's help and problem tied to it by
 * `aria-describedby`, and, when something's wrong, a summary at the top that takes the
 * focus and links to each problem. Everything a researcher typed is printed escaped,
 * as typed (Markup::text()).
 */
final class Form {

	/**
	 * The block's markup.
	 *
	 * @param array<string, mixed> $state What to show, from Survey::state().
	 */
	public static function render( array $state ): string {
		$heading = '<h1 id="fh-survey-heading" class="fh-survey__heading">' . Markup::text( Settings::get()['submitLabel'] ) . '</h1>';
		$landing = home_url( '/' . Query::ROOT_PATH . '/' );
		switch ( $state['step'] ) {
			case 'closed':
				$url  = Settings::get()['submitFormUrl'];
				$body = '<p>' . esc_html__( 'The FireHouse form isn’t taking submissions here yet.', 'firehouse' ) . '</p>'
					. ( '' !== $url ? '<p>' . Markup::button( __( 'Submit through the current form', 'firehouse' ), $url, 'accent' ) . '</p>' : '' );
				break;
			case 'yes':
			case 'no-irb':
				// Takes the focus and is named by its message, which a screen reader reads
				// on reaching it; a live region already on a page as it loads says nothing.
				$body = '<div class="fh-survey__done" role="group" tabindex="-1" autofocus aria-labelledby="fh-survey-done"><p id="fh-survey-done">' . esc_html( 'yes' === $state['step'] ? Questions::thanks() : Questions::no_irb() ) . '</p></div>'
					. '<p>' . Markup::button( __( 'Back to the FireHouse', 'firehouse' ), $landing, 'secondary' ) . '</p>';
				break;
			default:
				$body = self::form( $state );
		}
		return '<section class="fh-survey" aria-labelledby="fh-survey-heading"><div class="fh-survey__inner">' . $heading . $body . '</div></section>';
	}

	/**
	 * The form itself.
	 *
	 * @param array<string, mixed> $state The form's state: values, problems, focus, nonce and start time.
	 */
	private static function form( array $state ): string {
		$problems = (array) $state['problems'];
		$html     = ( array() !== $problems ? self::summary( $problems ) : '' ) . self::notice();
		// Sent to the page's own address, with no fragment: without an action a form is
		// sent to the address as it is, a skip link's or a problem link's fragment
		// included, and a browser skips `autofocus` when the address has one.
		$action = (string) ( $state['action'] ?? '' );
		$html  .= '<form class="fh-survey__form" method="post"' . ( '' !== $action ? ' action="' . esc_url( $action ) . '"' : '' ) . ' novalidate>';
		// The first submit button is what Enter in a text field presses: Send, not an
		// entry's Remove.
		$html .= '<button type="submit" class="fh-visually-hidden" tabindex="-1" aria-hidden="true">' . esc_html__( 'Send', 'firehouse' ) . '</button>';
		$html .= '<input type="hidden" name="_wpnonce" value="' . esc_attr( (string) $state['nonce'] ) . '" />';
		$html .= '<input type="hidden" name="' . esc_attr( Survey::STARTED ) . '" value="' . esc_attr( (string) $state['started'] ) . '" />';
		$html .= '<div class="fh-survey__trap" aria-hidden="true"><label for="fh-survey-website">' . esc_html__( 'Leave this empty', 'firehouse' ) . '</label><input type="text" id="fh-survey-website" name="' . esc_attr( Survey::TRAP ) . '" value="" tabindex="-1" autocomplete="off" /></div>';
		foreach ( Questions::all() as $name => $question ) {
			$html .= self::question( $name, $question, $state );
		}
		$html .= '<p class="fh-survey__send"><button type="submit" class="fh-btn fh-btn--accent fh-btn--lg">' . esc_html__( 'Send', 'firehouse' ) . '</button></p>';
		return $html . '</form>';
	}

	/**
	 * One question's field or group.
	 *
	 * @param string               $name     The question's name.
	 * @param array<string, mixed> $question The question.
	 * @param array<string, mixed> $state    The form's state.
	 */
	private static function question( string $name, array $question, array $state ): string {
		$value    = $state['values'][ $name ] ?? '';
		$problems = (array) $state['problems'];
		$focus    = (string) $state['focus'];
		$required = $question['required'] ? '<span class="fh-field__required" aria-hidden="true"> *</span>' : '';
		$help     = isset( $question['help'] ) ? (string) $question['help'] : '';
		switch ( $question['type'] ) {
			case 'choice':
			case 'choices':
				return self::choices( $name, $question, $value, $state, $required );
			case 'entries':
				return self::entries( $name, $question, (array) $value, $problems, $focus, $required );
			default:
				$id      = 'fh-survey-' . $name;
				$problem = $problems[ $name ] ?? '';
				$control = 'longtext' === $question['type']
					? '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( Submission::FIELD . '[' . $name . ']' ) . '" rows="' . (int) ( $question['rows'] ?? 4 ) . '" class="fh-field__control fh-field__control--area' . ( '' !== $problem ? ' fh-field__control--error' : '' ) . '"' . self::attributes( $id, $question, $help, $problem, $focus === $name ) . '>' . Markup::text( (string) $value ) . '</textarea>'
					: '<input id="' . esc_attr( $id ) . '" name="' . esc_attr( Submission::FIELD . '[' . $name . ']' ) . '" type="' . ( 'email' === $question['type'] ? 'email' : 'text' ) . '"' . ( 'year' === $question['type'] ? ' inputmode="numeric" maxlength="4"' : '' ) . ' value="' . Markup::text( (string) $value ) . '" class="fh-field__control' . ( '' !== $problem ? ' fh-field__control--error' : '' ) . '"' . ( isset( $question['autocomplete'] ) ? ' autocomplete="' . esc_attr( $question['autocomplete'] ) . '"' : '' ) . self::attributes( $id, $question, $help, $problem, $focus === $name ) . ' />';
				return '<div class="fh-field fh-survey__question"><label class="fh-field__label" for="' . esc_attr( $id ) . '">' . esc_html( $question['label'] ) . $required . '</label>' . $control . self::help( $id, $help, $problem ) . '</div>';
		}
	}

	/**
	 * A group of radio buttons or checkboxes, with an "Other" box when it has one.
	 *
	 * @param string               $name     The question's name.
	 * @param array<string, mixed> $question The question.
	 * @param mixed                $value    The answer: a value, or a list of them.
	 * @param array<string, mixed> $state    The form's state.
	 * @param string               $required The required mark.
	 */
	private static function choices( string $name, array $question, mixed $value, array $state, string $required ): string {
		$id      = 'fh-survey-' . $name;
		$problem = (string) ( $state['problems'][ $name ] ?? '' );
		$help    = isset( $question['help'] ) ? (string) $question['help'] : '';
		$many    = 'choices' === $question['type'];
		$chosen  = array_map( 'strval', (array) $value );
		$html    = '<fieldset class="fh-survey__group fh-survey__question" id="' . esc_attr( $id ) . '" tabindex="-1"' . self::described( $id, $help, $problem ) . '><legend class="fh-field__label">' . esc_html( $question['label'] ) . $required . self::required_words( $question ) . '</legend>' . self::help( $id, $help, $problem );
		foreach ( $question['options'] as $key => $label ) {
			$option = $id . '-' . $key;
			$html  .= '<div class="fh-survey__option"><input type="' . ( $many ? 'checkbox' : 'radio' ) . '" id="' . esc_attr( $option ) . '" name="' . esc_attr( Submission::FIELD . '[' . $name . ']' . ( $many ? '[]' : '' ) ) . '" value="' . esc_attr( (string) $key ) . '"' . checked( in_array( (string) $key, $chosen, true ), true, false ) . ' /><label for="' . esc_attr( $option ) . '">' . esc_html( $label ) . '</label></div>';
		}
		if ( isset( $question['other'] ) ) {
			$other_id      = 'fh-survey-' . $name . 'Other';
			$other_problem = (string) ( $state['problems'][ $name . 'Other' ] ?? '' );
			// Named for its question too, so a screen reader's list of fields tells the
			// two "Other" boxes apart.
			$html .= '<div class="fh-field fh-survey__other"><label class="fh-field__label" for="' . esc_attr( $other_id ) . '">' . esc_html__( 'If other, what?', 'firehouse' ) . '<span class="fh-visually-hidden"> (' . esc_html( (string) $question['name'] ) . ')</span></label><input id="' . esc_attr( $other_id ) . '" name="' . esc_attr( Submission::FIELD . '[' . $name . 'Other]' ) . '" type="text" value="' . Markup::text( (string) ( $state['values'][ $name . 'Other' ] ?? '' ) ) . '" class="fh-field__control' . ( '' !== $other_problem ? ' fh-field__control--error' : '' ) . '"' . self::described( $other_id, '', $other_problem ) . ( '' !== $other_problem ? ' aria-invalid="true"' : '' ) . ' />' . self::help( $other_id, '', $other_problem ) . '</div>';
		}
		return $html . '</fieldset>';
	}

	/**
	 * A question's entries, a box each, with Remove and Add buttons that send the form
	 * back with one less or one more.
	 *
	 * @param string                $name     The question's name.
	 * @param array<string, mixed>  $question The question.
	 * @param string[]              $entries  The entries.
	 * @param array<string, string> $problems What's wrong, by field id.
	 * @param string                $focus    The field the focus goes to.
	 * @param string                $required The required mark.
	 */
	private static function entries( string $name, array $question, array $entries, array $problems, string $focus, string $required ): string {
		$id                = 'fh-survey-' . $name;
		$help              = isset( $question['help'] ) ? (string) $question['help'] : '';
		$group_problem     = $problems[ $name ] ?? '';
		$html              = '<fieldset class="fh-survey__group fh-survey__question" id="' . esc_attr( $id ) . '" tabindex="-1"' . self::described( $id, $help, $group_problem ) . '><legend class="fh-field__label">' . esc_html( $question['label'] ) . $required . self::required_words( $question ) . '</legend>' . self::help( $id, $help, $group_problem );
		[ $fewest, $most ] = $question['count'];
		foreach ( $entries as $index => $entry ) {
			$number  = $index + 1;
			$box     = $name . '-' . $number;
			$box_id  = 'fh-survey-' . $box;
			$problem = $problems[ $box ] ?? '';
			/* translators: 1: what an entry is, such as "Takeaway", 2: its place from 1. */
			$label     = sprintf( __( '%1$s %2$d', 'firehouse' ), $question['entry'], $number );
			$autofocus = $focus === $box;
			$control   = isset( $question['rows'] )
				? '<textarea id="' . esc_attr( $box_id ) . '" name="' . esc_attr( Submission::FIELD . '[' . $name . '][]' ) . '" rows="' . (int) $question['rows'] . '" class="fh-field__control fh-field__control--area' . ( '' !== $problem ? ' fh-field__control--error' : '' ) . '"' . self::attributes( $box_id, array( 'required' => false ), '', $problem, $autofocus ) . '>' . Markup::text( $entry ) . '</textarea>'
				: '<input id="' . esc_attr( $box_id ) . '" name="' . esc_attr( Submission::FIELD . '[' . $name . '][]' ) . '" type="text" inputmode="url" value="' . Markup::text( $entry ) . '" class="fh-field__control' . ( '' !== $problem ? ' fh-field__control--error' : '' ) . '"' . self::attributes( $box_id, array( 'required' => false ), '', $problem, $autofocus ) . ' />';
			$remove    = count( $entries ) > $fewest || ( 0 === $fewest && count( $entries ) > 0 )
				? '<button type="submit" name="' . esc_attr( Survey::CHANGE ) . '" value="' . esc_attr( 'remove:' . $name . ':' . $number ) . '" class="fh-btn fh-btn--ghost fh-btn--sm fh-survey__remove">' . esc_html(
					/* translators: %s: the entry, such as "Takeaway 2". */
					sprintf( __( 'Remove %s', 'firehouse' ), $label )
				) . '</button>'
				: '';
			$html .= '<div class="fh-field fh-survey__entry"><label class="fh-field__label" for="' . esc_attr( $box_id ) . '">' . esc_html( $label ) . '</label>' . $control . self::help( $box_id, '', $problem ) . $remove . '</div>';
		}
		if ( count( $entries ) < $most ) {
			$html .= '<p><button type="submit" id="' . esc_attr( 'fh-survey-' . $name . '-add' ) . '" name="' . esc_attr( Survey::CHANGE ) . '" value="' . esc_attr( 'add:' . $name ) . '" class="fh-btn fh-btn--secondary fh-btn--sm"' . ( $focus === $name . '-add' ? ' autofocus' : '' ) . '>' . esc_html(
				/* translators: %s: what an entry is, such as "Takeaway". */
				sprintf( __( 'Add a box: %s', 'firehouse' ), mb_strtolower( (string) $question['entry'] ) )
			) . '</button></p>';
		}
		return $html . '</fieldset>';
	}

	/**
	 * A control's shared attributes: what describes it, whether it's wrong, required,
	 * and whether it takes the focus.
	 *
	 * @param string               $id        The control's id.
	 * @param array<string, mixed> $question  Its question.
	 * @param string               $help      Its help.
	 * @param string               $problem   What's wrong with it.
	 * @param bool                 $autofocus Whether it takes the focus.
	 */
	private static function attributes( string $id, array $question, string $help, string $problem, bool $autofocus ): string {
		return self::described( $id, $help, $problem )
			. ( '' !== $problem ? ' aria-invalid="true"' : '' )
			. ( ! empty( $question['required'] ) ? ' aria-required="true"' : '' )
			. ( $autofocus ? ' autofocus' : '' );
	}

	/**
	 * The `aria-describedby` that ties help and a problem to their control.
	 *
	 * @param string $id      The control's id.
	 * @param string $help    Its help.
	 * @param string $problem What's wrong with it.
	 */
	private static function described( string $id, string $help, string $problem ): string {
		$ids = array_merge( '' !== $problem ? array( $id . '-problem' ) : array(), '' !== $help ? array( $id . '-help' ) : array() );
		return array() === $ids ? '' : ' aria-describedby="' . esc_attr( implode( ' ', $ids ) ) . '"';
	}

	/**
	 * A control's problem and help, under it.
	 *
	 * @param string $id      The control's id.
	 * @param string $help    Its help.
	 * @param string $problem What's wrong with it.
	 */
	private static function help( string $id, string $help, string $problem ): string {
		// "Error:" said before a problem, wherever a screen reader meets it.
		return ( '' !== $problem ? '<span id="' . esc_attr( $id . '-problem' ) . '" class="fh-field__help fh-field__help--error"><span class="fh-visually-hidden">' . esc_html__( 'Error:', 'firehouse' ) . ' </span>' . esc_html( $problem ) . '</span>' : '' )
			. ( '' !== $help ? '<span id="' . esc_attr( $id . '-help' ) . '" class="fh-field__help">' . esc_html( $help ) . '</span>' : '' );
	}

	/**
	 * A required group's legend says so in words, which its `*` (hidden from screen
	 * readers) doesn't: a group of radio buttons or checkboxes has no `aria-required`
	 * of its own, and `required` on a checkbox would mean that box must be ticked.
	 *
	 * @param array<string, mixed> $question The question.
	 */
	private static function required_words( array $question ): string {
		return $question['required'] ? '<span class="fh-visually-hidden"> ' . esc_html__( '(required)', 'firehouse' ) . '</span>' : '';
	}

	/**
	 * The summary of what's wrong, at the top, which takes the focus, a link to each.
	 * The alert is inside the part that takes the focus, as GOV.UK's error summary has
	 * it, so a screen reader doesn't read it twice.
	 *
	 * @param array<string, string> $problems What's wrong, by field id.
	 */
	private static function summary( array $problems ): string {
		$html = '<div class="fh-survey__problems" tabindex="-1" autofocus><div role="alert"><h2 id="fh-survey-problems-heading">' . esc_html(
			sprintf(
				/* translators: %d: how many problems the form has. */
				_n( 'There’s %d problem to fix before sending', 'There are %d problems to fix before sending', count( $problems ), 'firehouse' ),
				count( $problems )
			)
		) . '</h2><ul>';
		foreach ( $problems as $id => $problem ) {
			$html .= '<li>' . ( 'form' === $id ? esc_html( $problem ) : '<a href="#' . esc_attr( 'fh-survey-' . $id ) . '">' . esc_html( $problem ) . '</a>' ) . '</li>';
		}
		return $html . '</ul></div></div>';
	}

	/**
	 * What the form says above its questions (Questions::notice()), with a link to the
	 * site where the Google Form had a placeholder.
	 */
	private static function notice(): string {
		$html = '<div class="fh-survey__notice">';
		foreach ( Questions::notice() as $index => $section ) {
			$html .= ( 0 === $index ? '<p class="fh-survey__welcome">' : '<h2>' ) . esc_html( $section['heading'] ) . ( 0 === $index ? '</p>' : '</h2>' );
			foreach ( $section['paragraphs'] as $paragraph ) {
				$html .= '<p>' . esc_html( $paragraph ) . '</p>';
			}
			if ( 'Motivation & Background' === $section['heading'] ) {
				$html .= '<p>' . esc_html__( 'Explore the Platform: Visit the', 'firehouse' ) . ' <a href="' . esc_url( home_url( '/' . Query::ROOT_PATH . '/' ) ) . '">' . esc_html__( 'FireHouse website', 'firehouse' ) . '</a> ' . esc_html__( 'to view existing entries and learn more about the initiative.', 'firehouse' ) . '</p>';
			}
			if ( array() !== $section['items'] ) {
				$html .= '<ul>' . implode( '', array_map( static fn( string $item ): string => '<li>' . esc_html( $item ) . '</li>', $section['items'] ) ) . '</ul>';
			}
		}
		return $html . '<p>' . esc_html__( 'Questions marked * are required.', 'firehouse' ) . '</p></div>';
	}
}
