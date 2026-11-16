<?php
/**
 * The survey form in WordPress: its page, its switch, and what a submission does.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Survey;

use Firehouse\Capabilities;
use Firehouse\Domain\Email_Shape;
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
 * - **Closed until an administrator opens it** (Settings, "Accept submissions", which
 *   only an administrator can change): the form mustn't take submissions on
 *   gsl.noaa.gov before the privacy review and the written PRA answer (open questions
 *   12 and 14). Closed, the page says so and points at the survey address in the
 *   settings; open, every Submit a Finding button leads to it (Settings::get()).
 *   Opening it creates the page under the landing page if there's none.
 * - **A submission becomes a draft project**, its fields as an import of the same
 *   answers would write them (Submission), with a response ID of its own. The
 *   private answers (rule 2) are kept apart, in one protected post meta field that's
 *   in no schema, so it's in no revision and no REST response, and left out of
 *   WordPress's export (Tools → Export). A submission without IRB approval is kept
 *   too, as a draft the publishing rules won't publish (Project_Rules). Each paper
 *   given by DOI gets its title from doi.org once the draft is saved, in a scheduled
 *   event (Doi_Lookup); the team mailbox is told of the draft, in fixed text
 *   (notify()).
 * - **Protections** (decided by Eric, 16 November): the form's nonce; a field people
 *   don't see, which a bot fills (a submission that fills it is thanked and
 *   dropped); a signed start time, refusing one sent within a few seconds; the
 *   checks on every answer and length (Submission::check()); a form sent only to the
 *   page's own address, from the site's own pages; and the Submit page never cached.
 *   Repeat submissions are limited by a Cloudflare rule on POSTs to the page's
 *   address, which GSL's web team sets; the plugin keeps nothing about visitors.
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
	 * The team mailbox each submission is reported to: the one the About page gives.
	 */
	public const NOTIFY = 'gsl.firehouse@noaa.gov';

	/**
	 * How a response ID the form makes begins: never a Qualtrics `R_`, so an import
	 * leaves the form's drafts alone.
	 */
	public const RESPONSE_PREFIX = 'W_';

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
		add_filter( 'wxr_export_skip_postmeta', array( self::class, 'skip_in_export' ), 10, 2 );
		add_filter( 'pre_get_document_title', array( self::class, 'document_title' ), 20 );
		Doi_Lookup::hook();
	}

	/**
	 * Leaves the private answers out of WordPress's export file (Tools → Export), which
	 * otherwise writes every post meta field, protected ones too (rule 2).
	 *
	 * @param mixed $skip Whether the field is already left out.
	 * @param mixed $key  The field's key.
	 */
	public static function skip_in_export( mixed $skip, mixed $key ): bool {
		return (bool) $skip || self::PRIVATE_KEY === $key;
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
	 * When the settings are saved with the form open, creates the Submit page if there's
	 * none: published under the landing page, holding the form's block, titled as the
	 * Submit a Finding buttons are. A Submit page that's there but unpublished, as a
	 * draft or private, is left for an administrator to publish, rather than a second
	 * page made beside it at submit-2.
	 */
	public static function opened(): void {
		if ( ! self::is_open() || null !== self::page() ) {
			return;
		}
		$root = Capabilities::root_id();
		if ( 0 === $root ) {
			return;
		}
		$there = get_posts(
			array(
				'post_type'   => 'page',
				'post_parent' => $root,
				'name'        => self::SLUG,
				'post_status' => Capabilities::TREE_STATUSES,
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		);
		if ( array() !== $there ) {
			return;
		}
		wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'page',
					'post_parent'  => $root,
					'post_name'    => self::SLUG,
					'post_status'  => 'publish',
					'post_title'   => Settings::stored()['submitLabel'],
					'post_content' => '<!-- wp:firehouse/survey-form /-->',
				)
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
		// is_page() first: it asks the main query, where page() asks the database.
		if ( ! is_page() ) {
			return;
		}
		$page = self::page();
		if ( null === $page || ! is_page( $page->ID ) ) {
			return;
		}
		nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The constant page caches read.
		}
		$sent = isset( $_GET['firehouse_sent'] ) && is_string( $_GET['firehouse_sent'] ) ? sanitize_key( wp_unslash( $_GET['firehouse_sent'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks which thanks to show.
		if ( ! self::is_open() ) {
			self::$state = array( 'step' => 'closed' );
			return;
		}
		if ( ! isset( $_POST[ Submission::FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only checks whether a form was sent; its nonce is checked below.
			self::$state = in_array( $sent, array( 'yes', 'no-irb' ), true ) ? array( 'step' => $sent ) : null;
			return;
		}
		// Only a POST to the page's own address, from the site's own pages: WordPress
		// would find the page from a POST anywhere on the site that names it, which a
		// rate limit on the page's address wouldn't see, and a page elsewhere could have
		// its visitors' browsers send the form.
		if ( ! self::sent_here( $page ) ) {
			status_header( 400 );
			self::$state = self::again( Submission::read( array() ), array( 'form' => 'The form has to be sent from this page. Fill it in below.' ) );
			return;
		}
		$values = Submission::read( wp_unslash( $_POST[ Submission::FIELD ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The nonce is checked below, before anything is written; the answers are a researcher's words, kept as typed (rule 1) and checked by Submission::check().
		$change = isset( $_POST[ self::CHANGE ] ) && is_string( $_POST[ self::CHANGE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::CHANGE ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Adding or removing a box writes nothing.
		$posted = isset( $_POST[ self::STARTED ] ) && is_string( $_POST[ self::STARTED ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::STARTED ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked against its signature; the nonce is checked below.
		// The time the form was first drawn, kept through adding a box or a problem, so
		// the wait counts from then: or now, if what was sent isn't one.
		$started = self::is_stamp( $posted ) ? $posted : self::stamp( time() );
		if ( '' !== $change ) {
			self::$state = self::changed( $values, $change, $started );
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
		if ( ! self::took_long_enough( $posted ) ) {
			self::$state = self::again( $values, array( 'form' => 'That was sent faster than a person could fill it in. Check your answers below, then send them again.' ), '', $started );
			return;
		}
		$problems = Submission::check( $values );
		if ( array() !== $problems ) {
			self::$state = self::again( $values, $problems, '', $started );
			return;
		}
		$saved = self::save( $values );
		if ( 0 === $saved ) {
			self::$state = self::again( $values, array( 'form' => 'Your answers couldn’t be saved just now. Try sending them again in a few minutes.' ), '', $started );
			return;
		}
		self::notify( $saved, 'no' !== $values['irb'] );
		self::thank( 'no' === $values['irb'] ? 'no-irb' : 'yes' );
	}

	/**
	 * Whether this POST was sent to the Submit page's own address, as its permalink
	 * spells it, and not from another site: a browser sending it from a page elsewhere
	 * says so in `Sec-Fetch-Site`, or names that site in `Origin`. A request that says
	 * neither, as a script's or an older browser's may, is let through to the rest of
	 * the checks; so is an `Origin` of "null", which a browser sends from the site's own
	 * page when the page asks for no referrer.
	 *
	 * @param \WP_Post $page The Submit page.
	 */
	public static function sent_here( \WP_Post $page ): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		$uri    = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path   = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$own    = (string) wp_parse_url( (string) get_permalink( $page ), PHP_URL_PATH );
		if ( 'POST' !== $method || '' === $own || $path !== $own ) {
			return false;
		}
		$fetch = isset( $_SERVER['HTTP_SEC_FETCH_SITE'] ) && is_string( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ? sanitize_key( wp_unslash( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ) : '';
		if ( 'cross-site' === $fetch ) {
			return false;
		}
		if ( 'same-origin' === $fetch ) {
			return true;
		}
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) && is_string( $_SERVER['HTTP_ORIGIN'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) ) : '';
		if ( '' === $origin || 'null' === $origin ) {
			return true;
		}
		// The host alone: a site behind a proxy may not know its own scheme.
		$from = wp_parse_url( $origin, PHP_URL_HOST );
		$host = wp_parse_url( (string) home_url( '/' ), PHP_URL_HOST );
		return is_string( $from ) && is_string( $host ) && strtolower( $from ) === strtolower( $host );
	}

	/**
	 * The page's title with what happened in front of it, so a screen reader says it as
	 * the page loads: "Error:" when the form came back with problems, "Thank you:" after
	 * a send. Runs after Head::document_title(), on the Submit page only.
	 *
	 * @param mixed $title The title so far, escaped.
	 * @return mixed The title.
	 */
	public static function document_title( mixed $title ): mixed {
		if ( null === self::$state || ! is_string( $title ) || '' === $title ) {
			return $title;
		}
		if ( 'form' === self::$state['step'] && array() !== self::$state['problems'] ) {
			return esc_html__( 'Error:', 'firehouse' ) . ' ' . $title;
		}
		if ( in_array( self::$state['step'], array( 'yes', 'no-irb' ), true ) ) {
			return esc_html__( 'Thank you:', 'firehouse' ) . ' ' . $title;
		}
		return $title;
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
		// Named, so the signature can't stand in for one of WordPress's, which use the same key.
		return $time . '.' . hash_hmac( 'sha256', self::STARTED . '|' . $time, wp_salt( 'nonce' ) );
	}

	/**
	 * Whether a signed start time is one this site signed, of any age.
	 *
	 * @param string $stamp The start time sent.
	 */
	public static function is_stamp( string $stamp ): bool {
		$parts = explode( '.', $stamp, 2 );
		return 2 === count( $parts ) && 1 === preg_match( '/^\d{1,12}$/', $parts[0] ) && hash_equals( self::stamp( (int) $parts[0] ), $stamp );
	}

	/**
	 * Whether a signed start time is real and at least FEWEST_SECONDS old, but not
	 * more than a day.
	 *
	 * @param string $stamp The start time sent.
	 */
	public static function took_long_enough( string $stamp ): bool {
		if ( ! self::is_stamp( $stamp ) ) {
			return false;
		}
		$elapsed = time() - (int) explode( '.', $stamp, 2 )[0];
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
	 * @param string                             $started  The signed time the form was first drawn, or "" for now.
	 * @return array<string, mixed>
	 */
	private static function again( array $values, array $problems, string $focus = '', string $started = '' ): array {
		$page = self::page();
		return array(
			'step'     => 'form',
			'values'   => $values,
			'problems' => $problems,
			'focus'    => $focus,
			'nonce'    => wp_create_nonce( self::NONCE ),
			'started'  => '' !== $started ? $started : self::stamp( time() ),
			// Where the form is sent: the page's address, with no fragment, which a skip
			// link or a problem's link would otherwise leave on it; a browser skips
			// `autofocus` when the address has one.
			'action'   => null !== $page ? (string) get_permalink( $page ) : '',
		);
	}

	/**
	 * The form with an entry's box added or removed, as an Add or Remove button asked,
	 * and the focus on the box added, or where the removed one was.
	 *
	 * @param array<string, string|list<string>> $values The answers.
	 * @param string                             $change  `add:<question>` or `remove:<question>:<place from 1>`.
	 * @param string                             $started The signed time the form was first drawn.
	 * @return array<string, mixed>
	 */
	private static function changed( array $values, string $change, string $started ): array {
		$parts     = explode( ':', $change );
		$questions = Questions::all();
		$name      = $parts[1] ?? '';
		if ( ! isset( $questions[ $name ] ) || 'entries' !== $questions[ $name ]['type'] ) {
			return self::again( $values, array(), '', $started );
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
		return self::again( $values, array(), $focus, $started );
	}

	/**
	 * Saves a submission: a draft project with its fields and its private answers apart,
	 * and its DOIs' lookups scheduled (Doi_Lookup) unless it has no IRB approval, since
	 * it would never be published. The draft's ID, or 0 if WordPress or a field's check
	 * refused it, with nothing kept.
	 *
	 * @param array<string, string|list<string>> $values The answers, with nothing wrong.
	 */
	private static function save( array $values ): int {
		$saved = self::draft( $values );
		if ( 0 !== $saved && 'no' !== $values['irb'] ) {
			Doi_Lookup::schedule( $saved, Submission::project( $values, '' )['papers'] );
		}
		return $saved;
	}

	/**
	 * Writes a submission's draft and its private answers: the draft's ID, or 0 with
	 * nothing kept.
	 *
	 * @param array<string, string|list<string>> $values The answers, with nothing wrong.
	 */
	private static function draft( array $values ): int {
		$fields = Submission::project( $values, self::RESPONSE_PREFIX . wp_generate_password( 16, false ) );
		foreach ( $fields as $name => $value ) {
			if ( true !== Meta::validate( Post_Types::PROJECT, $name, $value ) ) {
				return 0;
			}
		}
		// Rule 2's backstop, as the import has: nothing shaped like an email address in
		// what the site would show. Submission::check() has already said where one is.
		if ( array() !== Email_Shape::find( array( 'project' => $fields ) ) ) {
			return 0;
		}
		$saved = Store::save(
			Post_Types::PROJECT,
			0,
			array(
				'post_title'  => Store::post_title( $fields['title'] ),
				// A slug no project holds, in any status, nor a feed's: as WordPress makes
				// one for a published post.
				'post_name'   => wp_unique_post_slug( Submission::slug( $fields['title'] ), 0, 'publish', Post_Types::PROJECT, 0 ),
				'post_status' => 'draft',
				'post_author' => 0,
			),
			$fields,
			array()
		);
		if ( is_wp_error( $saved ) || 0 === $saved ) {
			return 0;
		}
		$private = Submission::private_answers( $values );
		if ( '' === $private['email'] && '' === $private['jobTitle'] && array() === $private['shared'] ) {
			return $saved;
		}
		// Slashed, as WordPress unslashes what it's given: a job title's backslash is kept.
		// A draft whose answers weren't kept is deleted, so the researcher sends it again.
		if ( false === add_post_meta( $saved, self::PRIVATE_KEY, wp_slash( $private ), true ) ) {
			wp_delete_post( $saved, true );
			return 0;
		}
		return $saved;
	}

	/**
	 * Tells the team mailbox a submission has arrived, with a link to its draft (decided
	 * by Eric, 16 November). The message is fixed text: nothing a researcher typed is
	 * in it, private or not, so the form can't be used to put a stranger's words in an
	 * email from the site. The `firehouse_survey_notify` filter changes the address, or
	 * with "" sends nothing, as a test site may want. A message that can't be sent is
	 * let go: the draft is saved, and the projects list shows it.
	 *
	 * @param int  $post_id The draft.
	 * @param bool $irb     Whether the submission says the project has IRB approval or exemption.
	 */
	public static function notify( int $post_id, bool $irb ): void {
		$to = apply_filters( 'firehouse_survey_notify', self::NOTIFY );
		if ( ! is_string( $to ) || '' === $to || ! is_email( $to ) ) {
			return;
		}
		$lines = array(
			'Someone has sent the FireHouse survey form. Their submission is a draft project, waiting for review:',
			'',
			admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
			'',
		);
		if ( ! $irb ) {
			$lines[] = 'They said the project has no IRB approval or exemption, so it can’t be published.';
			$lines[] = '';
		}
		$lines[] = 'Every draft: ' . admin_url( 'edit.php?post_type=' . Post_Types::PROJECT . '&post_status=draft' );
		$lines[] = '';
		$lines[] = 'This message holds nothing the submitter typed. Their answers, and the private ones, are on the project’s edit screen.';
		wp_mail( $to, $irb ? 'New FireHouse submission' : 'New FireHouse submission, without IRB approval', implode( "\n", $lines ) );
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
