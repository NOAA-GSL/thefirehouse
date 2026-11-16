<?php
/**
 * The survey form in WordPress: its page, its switch, and what a submission does.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Survey;

use Firehouse\Capabilities;
use Firehouse\Meta;
use Firehouse\Post_Types;
use Firehouse\Query;
use Firehouse\Settings;
use Firehouse\Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Submit page at /firehouse/submit/, its `firehouse/survey-form` block, and what
 * happens when a researcher sends it (Phase 7a):
 *
 * - **Closed until an administrator opens it** (Settings, "Accept submissions"): the
 *   form mustn't take submissions on gsl.noaa.gov before the privacy review and the
 *   written PRA answer (open questions 12 and 14). Closed, the page says so and
 *   points at the survey address in the settings; open, every Submit a Finding
 *   button leads to it (Settings::submit_url()). Opening it creates the page under
 *   the landing page if it's missing.
 * - **A submission becomes a draft project**, its fields as an import of the same
 *   answers would write them (Submission), with a response ID of its own. The
 *   private answers (rule 2) are kept apart, in one protected post meta field that's
 *   in no schema, so it's in no revision, no REST response and no export. A
 *   submission without IRB approval is kept too, as a draft the publishing rules
 *   won't publish (Project_Rules).
 * - **Protections** (decided by Eric, 16 November): the form's nonce; a field people
 *   don't see, which a bot fills (a submission that fills it is thanked and
 *   dropped); a signed start time, refusing one sent within a few seconds; the
 *   checks on every answer and length (Submission::check()); and the Submit page
 *   never cached. Repeat submissions are limited by a Cloudflare rule on POSTs to the
 *   page, which GSL's web team sets; the plugin keeps nothing about visitors.
 * - **No JavaScript needed**: adding or removing an entry's box sends the form back
 *   with one more or one less, writing nothing.
 */
final class Survey {

	/**
	 * The Submit page's slug, under the landing page.
	 */
	public const SLUG = 'submit';

	/**
	 * The protected post meta field holding a project's private answers.
	 */
	public const PRIVATE_KEY = '_firehouse_private';

	/**
	 * The form's nonce action.
	 */
	public const NONCE = 'firehouse_survey';

	/**
	 * The fewest seconds between the form being drawn and sent.
	 */
	public const FEWEST_SECONDS = 5;

	/**
	 * The field people don't see.
	 */
	public const TRAP = 'firehouse_website';

	/**
	 * The signed start time's field.
	 */
	public const STARTED = 'firehouse_started';

	/**
	 * The field an entry's Add or Remove button sends.
	 */
	public const CHANGE = 'firehouse_change';

	/**
	 * What the block shows on this request.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $state = null;

	/**
	 * Hooks the handler and the page's creation when the form opens.
	 */
	public static function hook(): void {
		add_action( 'template_redirect', array( self::class, 'handle' ), 1 );
		add_action( 'add_option_' . Settings::OPTION, array( self::class, 'opened' ) );
		add_action( 'update_option_' . Settings::OPTION, array( self::class, 'opened' ) );
	}

	/**
	 * Whether the form takes submissions.
	 */
	public static function is_open(): bool {
		return true === Settings::stored()['surveyOpen'];
	}

	/**
	 * The Submit page: the published page under the landing page with this slug, or
	 * null.
	 */
	public static function page(): ?\WP_Post {
		$root = Capabilities::root_id();
		if ( 0 === $root ) {
			return null;
		}
		$ids  = get_posts(
			array(
				'post_type'   => 'page',
				'post_parent' => $root,
				'name'        => self::SLUG,
				'post_status' => 'publish',
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		);
		$page = isset( $ids[0] ) ? get_post( (int) $ids[0] ) : null;
		return $page instanceof \WP_Post ? $page : null;
	}

	/**
	 * When the settings are saved with the form open, creates the Submit page if it's
	 * missing: published under the landing page, holding the form's block, titled as
	 * the Submit a Finding buttons are.
	 */
	public static function opened(): void {
		if ( ! self::is_open() || null !== self::page() ) {
			return;
		}
		$root = Capabilities::root_id();
		if ( 0 === $root ) {
			return;
		}
		wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_parent'  => $root,
				'post_name'    => self::SLUG,
				'post_status'  => 'publish',
				'post_title'   => Settings::get()['submitLabel'],
				'post_content' => '<!-- wp:firehouse/survey-form /-->',
			)
		);
	}

	/**
	 * On the Submit page, before anything is drawn: keeps the page out of every cache,
	 * since the form carries a nonce; and handles a sent form. A form with a problem,
	 * or an entry added or removed, is drawn again with what was sent; a good one is
	 * saved, and the page sends the browser on to its thanks.
	 */
	public static function handle(): void {
		$page = self::page();
		if ( null === $page || ! is_page( $page->ID ) ) {
			return;
		}
		nocache_headers();
		$sent = isset( $_GET['firehouse_sent'] ) && is_string( $_GET['firehouse_sent'] ) ? sanitize_key( wp_unslash( $_GET['firehouse_sent'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks which thanks to show.
		if ( ! self::is_open() ) {
			self::$state = array( 'step' => 'closed' );
			return;
		}
		if ( ! isset( $_POST[ Submission::FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only checks whether a form was sent; its nonce is checked below.
			self::$state = in_array( $sent, array( 'yes', 'no-irb' ), true ) ? array( 'step' => $sent ) : null;
			return;
		}
		$values = Submission::read( wp_unslash( $_POST[ Submission::FIELD ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The nonce is checked below, before anything is written; the answers are a researcher's words, kept as typed (rule 1) and checked by Submission::check().
		$change = isset( $_POST[ self::CHANGE ] ) && is_string( $_POST[ self::CHANGE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::CHANGE ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Adding or removing a box writes nothing.
		if ( '' !== $change ) {
			self::$state = self::changed( $values, $change );
			return;
		}

		$nonce = isset( $_POST['_wpnonce'] ) && is_string( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			self::$state = self::again( $values, array( 'form' => 'The form had been open for too long to send. Your answers are below: send them again.' ) );
			return;
		}
		$trap = isset( $_POST[ self::TRAP ] ) && is_string( $_POST[ self::TRAP ] ) ? trim( wp_unslash( $_POST[ self::TRAP ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only checked for being empty.
		if ( '' !== $trap ) {
			self::thank( 'yes' );
			return;
		}
		$started = isset( $_POST[ self::STARTED ] ) && is_string( $_POST[ self::STARTED ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::STARTED ] ) ) : '';
		if ( ! self::took_long_enough( $started ) ) {
			self::$state = self::again( $values, array( 'form' => 'That was sent faster than a person could fill it in. Check your answers below, then send them again.' ) );
			return;
		}
		$problems = Submission::check( $values );
		if ( array() !== $problems ) {
			self::$state = self::again( $values, $problems );
			return;
		}
		if ( ! self::save( $values ) ) {
			self::$state = self::again( $values, array( 'form' => 'Your answers couldn’t be saved just now. Try sending them again in a few minutes.' ) );
			return;
		}
		self::thank( 'no' === $values['irb'] ? 'no-irb' : 'yes' );
	}

	/**
	 * What the form's block shows: the form, empty or as sent, the thanks, or that
	 * the form is closed.
	 *
	 * @return array<string, mixed>
	 */
	public static function state(): array {
		if ( null !== self::$state ) {
			return self::$state;
		}
		if ( ! self::is_open() ) {
			return array( 'step' => 'closed' );
		}
		$values = Submission::read( array() );
		foreach ( Questions::all() as $name => $question ) {
			if ( 'entries' === $question['type'] ) {
				$values[ $name ] = array_fill( 0, max( 1, $question['count'][0] ), '' );
			}
		}
		return self::again( $values, array() );
	}

	/**
	 * A start time, signed so a bot can't make one up.
	 *
	 * @param int $time When, as a Unix timestamp.
	 */
	public static function stamp( int $time ): string {
		return $time . '.' . hash_hmac( 'sha256', (string) $time, wp_salt( 'nonce' ) );
	}

	/**
	 * Whether a signed start time is real and at least FEWEST_SECONDS old, but not
	 * more than a day.
	 *
	 * @param string $stamp The start time sent.
	 */
	public static function took_long_enough( string $stamp ): bool {
		$parts = explode( '.', $stamp, 2 );
		if ( 2 !== count( $parts ) || 1 !== preg_match( '/^\d{1,12}$/', $parts[0] ) || ! hash_equals( self::stamp( (int) $parts[0] ), $stamp ) ) {
			return false;
		}
		$elapsed = time() - (int) $parts[0];
		return $elapsed >= self::FEWEST_SECONDS && $elapsed <= DAY_IN_SECONDS;
	}

	/**
	 * A project's private answers, for those who may edit it.
	 *
	 * @param int $post_id The project.
	 * @return array{email?: string, jobTitle?: string, shared?: list<string>}
	 */
	public static function private_answers( int $post_id ): array {
		$answers = get_post_meta( $post_id, self::PRIVATE_KEY, true );
		return is_array( $answers ) ? $answers : array();
	}

	/**
	 * The form drawn again with what was sent and what's wrong with it.
	 *
	 * @param array<string, string|list<string>> $values   The answers.
	 * @param array<string, string>              $problems What's wrong, by field id.
	 * @param string                             $focus    The field to put the focus on, if not the problems.
	 * @return array<string, mixed>
	 */
	private static function again( array $values, array $problems, string $focus = '' ): array {
		return array(
			'step'     => 'form',
			'values'   => $values,
			'problems' => $problems,
			'focus'    => $focus,
			'nonce'    => wp_create_nonce( self::NONCE ),
			'started'  => self::stamp( time() ),
		);
	}

	/**
	 * The form with an entry's box added or removed, as an Add or Remove button asked,
	 * and the focus on the box added, or where the removed one was.
	 *
	 * @param array<string, string|list<string>> $values The answers.
	 * @param string                             $change `add:<question>` or `remove:<question>:<place from 1>`.
	 * @return array<string, mixed>
	 */
	private static function changed( array $values, string $change ): array {
		$parts     = explode( ':', $change );
		$questions = Questions::all();
		$name      = $parts[1] ?? '';
		if ( ! isset( $questions[ $name ] ) || 'entries' !== $questions[ $name ]['type'] ) {
			return self::again( $values, array() );
		}
		$entries = (array) $values[ $name ];
		$most    = $questions[ $name ]['count'][1];
		$focus   = '';
		if ( 'add' === $parts[0] && count( $entries ) < $most ) {
			$entries[] = '';
			$focus     = $name . '-' . count( $entries );
		} elseif ( 'remove' === $parts[0] && isset( $parts[2] ) && ctype_digit( $parts[2] ) && isset( $entries[ (int) $parts[2] - 1 ] ) ) {
			array_splice( $entries, (int) $parts[2] - 1, 1 );
			$focus = array() === $entries ? $name . '-add' : $name . '-' . max( 1, (int) $parts[2] - 1 );
		}
		$values[ $name ] = array_values( $entries );
		return self::again( $values, array(), $focus );
	}

	/**
	 * Saves a submission: a draft project with its fields, and its private answers
	 * apart. False if WordPress or a field's check refused it, with nothing kept.
	 *
	 * @param array<string, string|list<string>> $values The answers, with nothing wrong.
	 */
	private static function save( array $values ): bool {
		$fields = Submission::project( $values, 'W_' . wp_generate_password( 16, false ) );
		foreach ( $fields as $name => $value ) {
			if ( true !== Meta::validate( Post_Types::PROJECT, $name, $value ) ) {
				return false;
			}
		}
		$saved = Store::save(
			Post_Types::PROJECT,
			0,
			array(
				'post_title'  => Store::post_title( $fields['title'] ),
				'post_name'   => self::free_slug( Submission::slug( $fields['title'] ) ),
				'post_status' => 'draft',
				'post_author' => 0,
			),
			$fields,
			array()
		);
		if ( is_wp_error( $saved ) || 0 === $saved ) {
			return false;
		}
		add_post_meta( $saved, self::PRIVATE_KEY, Submission::private_answers( $values ), true );
		return true;
	}

	/**
	 * A slug no project holds, in any status: the slug, or it with -2, -3 and so on.
	 *
	 * @param string $slug The slug wanted.
	 */
	private static function free_slug( string $slug ): string {
		$base = $slug;
		for ( $n = 2; $n < 100; $n++ ) {
			$taken = get_posts(
				array(
					'post_type'   => Post_Types::PROJECT,
					'name'        => $slug,
					'post_status' => 'any',
					'numberposts' => 1,
					'fields'      => 'ids',
				)
			);
			if ( array() === $taken ) {
				return $slug;
			}
			$slug = $base . '-' . $n;
		}
		return $base . '-' . wp_generate_password( 6, false, false );
	}

	/**
	 * Sends the browser on to the page's thanks, so reloading it sends nothing again.
	 *
	 * @param string $which `yes`, or `no-irb` for a project without IRB approval.
	 */
	private static function thank( string $which ): void {
		$page = self::page();
		wp_safe_redirect( add_query_arg( 'firehouse_sent', $which, null !== $page ? get_permalink( $page ) : home_url( '/' . Query::ROOT_PATH . '/' ) ), 303 );
		exit;
	}
}
