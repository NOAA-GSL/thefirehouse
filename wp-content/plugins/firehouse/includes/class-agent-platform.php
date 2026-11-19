<?php
/**
 * The AI service the synthesis is drafted with: Google's Gemini Enterprise Agent
 * Platform, formerly Vertex AI.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asks a Gemini model on Google's Agent Platform for a reply. GSL's IT services chose
 * Agent Platform over Cloudflare Workers AI, which 0.3.1 used, and issue the key from
 * a GSL Google Cloud project; NOAA's AI policy still decides whether it's used on the
 * sites, open question 7.
 *
 * - **The key comes from FireHouse → Settings first.** An administrator saves the
 *   project ID and API key there, the API key sealed (Agent_Platform_Key); a site
 *   without one there uses the constants FIREHOUSE_GOOGLE_PROJECT and
 *   FIREHOUSE_GOOGLE_API_KEY in wp-config.php. Settings wins (Eric, 18 November): on
 *   a site whose administrators can't edit wp-config.php, the screen is what they can
 *   reach. Either way the API key is opened only here, sent only to Google, in a
 *   header, never in an address, and never written into a message; it's never shown.
 * - **Off without it.** With neither, or neither shaped like Google's, configured()
 *   is false and nothing offers Analyze results; the paste stays.
 * - **One host,** Agent Platform's for the US multi-region, over HTTPS, with no
 *   redirects followed. Google keeps the model's work on a request to it inside the
 *   United States; its global host may serve one from any region. No single US region
 *   (us-central1 and the rest) offers a Gemini model that hasn't been retired: since
 *   20 October 2026, Gemini 3 and 4 are only on the global host and the multi-regions.
 * - **A fixed list of Google's own models,** so the address is never built from
 *   typed text.
 */
final class Agent_Platform {

	/**
	 * The constant holding the Google Cloud project ID.
	 */
	public const PROJECT = 'FIREHOUSE_GOOGLE_PROJECT';

	/**
	 * The constant holding the API key: one bound to a service account that may use
	 * Agent Platform, and restricted to its API.
	 */
	public const API_KEY = 'FIREHOUSE_GOOGLE_API_KEY';

	/**
	 * Where the key in use came from: FireHouse → Settings.
	 */
	public const FROM_SETTINGS = 'settings';

	/**
	 * Where the key in use came from: wp-config.php's constants.
	 */
	public const FROM_CONFIG = 'wp-config';

	/**
	 * Where the request is processed: Google's US multi-region.
	 */
	public const LOCATION = 'us';

	/**
	 * The one host asked: Agent Platform's for that multi-region.
	 */
	public const HOST = 'aiplatform.us.rep.googleapis.com';

	/**
	 * The models offered, by Google's ID for each: what the screen calls it, the most
	 * tokens its reply may take, and how much it thinks first. All three are GA on the US
	 * multi-region, read a million tokens, many times the source, and aren't due to retire
	 * before July 2027 (Google's model versions page, 19 November 2026). Each reply's
	 * limit is 32,768 tokens, its thinking included: Gemini 4's most without streaming,
	 * and room to spare for the Flash models. The thinking level is each one's default,
	 * written down so a change of Google's doesn't change the drafts, except Flash-Lite's,
	 * raised from MINIMAL, which leaves counting projects across a long source too little.
	 * Gemini 4 refuses thinkingBudget, the 2.5 models' setting, so none is sent. Gemini 4
	 * Hydrogen, newer, has no retirement date yet, and 4 Carbon logs prompts and replies
	 * for 30 days, as Google's Frontier Models do.
	 *
	 * @var array<string, array{label: string, max_tokens: int, thinking: array{thinkingLevel: string}}>
	 */
	public const MODELS = array(
		'gemini-3.8-flash'      => array(
			'label'      => 'Gemini 3.8 Flash',
			'max_tokens' => 32768,
			'thinking'   => array( 'thinkingLevel' => 'MEDIUM' ),
		),
		'gemini-4-argon'        => array(
			'label'      => 'Gemini 4 Argon',
			'max_tokens' => 32768,
			'thinking'   => array( 'thinkingLevel' => 'MEDIUM' ),
		),
		'gemini-3.5-flash-lite' => array(
			'label'      => 'Gemini 3.5 Flash-Lite',
			'max_tokens' => 32768,
			'thinking'   => array( 'thinkingLevel' => 'LOW' ),
		),
	);

	/**
	 * The model the synthesis screen picks to start with: Gemini 3.8 Flash, the newest
	 * of Google's fast models, for well under 4 Argon's price per token. To be compared
	 * with the others on the team's prompt once the site has a key.
	 */
	public const DEFAULT_MODEL = 'gemini-3.8-flash';

	/**
	 * How long a request may take, in seconds: a long reply from a large model takes
	 * minutes.
	 */
	public const TIMEOUT = 300;

	/**
	 * The most of a reply read, in bytes.
	 */
	private const MOST_BYTES = 1048576;

	/**
	 * How much of Google's error message is kept.
	 */
	private const LONGEST_ERROR = 300;

	/**
	 * Whether the site has a Google API key.
	 */
	public static function configured(): bool {
		return null !== self::credentials();
	}

	/**
	 * Where the key in use comes from, FROM_SETTINGS or FROM_CONFIG; null when there's
	 * none.
	 */
	public static function source(): ?string {
		return self::credentials()['from'] ?? null;
	}

	/**
	 * Whether a value is shaped like a Google Cloud project ID: 6 to 30 lowercase letters,
	 * digits and hyphens, starting with a letter and not ending with a hyphen, so it can't
	 * add to the address.
	 *
	 * @param mixed $project The value.
	 */
	public static function project_shaped( mixed $project ): bool {
		return is_string( $project ) && 1 === preg_match( '/\A[a-z][a-z0-9-]{4,28}[a-z0-9]\z/', $project );
	}

	/**
	 * Whether a value is shaped like a Google API key: letters, digits, hyphens and
	 * underscores, so it can't add to the header. Google's are 39 characters; any from 30
	 * to 100 is taken, in case that changes.
	 *
	 * @param mixed $key The value.
	 */
	public static function key_shaped( mixed $key ): bool {
		return is_string( $key ) && 1 === preg_match( '/\A[A-Za-z0-9_-]{30,100}\z/', $key );
	}

	/**
	 * Whether a model is one of those offered.
	 *
	 * @param mixed $model Its ID.
	 */
	public static function offered( mixed $model ): bool {
		return is_string( $model ) && isset( self::MODELS[ $model ] );
	}

	/**
	 * What the screens call a model, as "Gemini 3.8 Flash".
	 *
	 * @param string $model Its ID.
	 */
	public static function label( string $model ): string {
		return self::MODELS[ $model ]['label'] ?? $model;
	}

	/**
	 * Asks a model: its reply's text, or what went wrong, said without the key.
	 *
	 * @param string                                     $model    One of MODELS.
	 * @param list<array{role: string, content: string}> $messages The conversation: system messages, then the user's.
	 * @return array{text: string, cut: bool}|array{problem: string}
	 */
	public static function ask( string $model, array $messages ): array {
		$credentials = self::credentials();
		if ( null === $credentials ) {
			return array( 'problem' => __( 'The site has no Google API key.', 'firehouse' ) );
		}
		if ( ! self::offered( $model ) ) {
			return array( 'problem' => __( 'That model isn’t one the screen offers.', 'firehouse' ) );
		}
		$response = wp_safe_remote_post(
			'https://' . self::HOST . '/v1/projects/' . $credentials['project'] . '/locations/' . self::LOCATION . '/publishers/google/models/' . $model . ':generateContent',
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'headers'             => array(
					'x-goog-api-key' => $credentials['key'],
					'Content-Type'   => 'application/json',
				),
				'body'                => (string) wp_json_encode( self::request( $model, $messages ) ),
				'user-agent'          => 'FireHouse WordPress plugin',
				'limit_response_size' => self::MOST_BYTES,
			)
		);
		if ( is_wp_error( $response ) ) {
			/* translators: %s: why the request failed, as WordPress says it. */
			return array( 'problem' => sprintf( __( 'Google’s Agent Platform couldn’t be reached: %s', 'firehouse' ), self::said( $response->get_error_message(), $credentials ) ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$body = is_array( $body ) ? $body : array();
		if ( 200 !== $code ) {
			return array( 'problem' => self::refusal( $code, $body, $credentials ) );
		}

		$candidate = is_array( $body['candidates'][0] ?? null ) ? $body['candidates'][0] : array();
		$finish    = is_string( $candidate['finishReason'] ?? null ) ? $candidate['finishReason'] : '';
		$text      = self::text( $body );
		// Gemini 4 says CONTINUATION where, streaming, it would have gone on.
		$cut     = in_array( $finish, array( 'MAX_TOKENS', 'CONTINUATION' ), true );
		$stopped = '' !== $finish && 'STOP' !== $finish && ! $cut;
		if ( null !== $text && '' !== trim( $text ) && $stopped ) {
			// Stopped by Google's filters, or for another reason, partway: what it wrote
			// isn't a whole reply, even where its JSON looks complete.
			/* translators: %s: Google's reason, as SAFETY. */
			return array( 'problem' => sprintf( __( 'The model stopped partway (%s), so its reply isn’t kept. Try again, or try another model.', 'firehouse' ), self::said( $finish, $credentials ) ) );
		}
		if ( null === $text || '' === trim( $text ) ) {
			$blocked = $body['promptFeedback']['blockReason'] ?? null;
			if ( is_string( $blocked ) ) {
				/* translators: %s: Google's reason, as PROHIBITED_CONTENT. */
				return array( 'problem' => sprintf( __( 'Google’s filters stopped the request before the model read it (%s). Tell the developer: a project’s text may have set them off.', 'firehouse' ), self::said( $blocked, $credentials ) ) );
			}
			if ( $cut ) {
				return array( 'problem' => __( 'The model used its whole length limit before it gave a reply, as a thinking model can. Try again, or try another model.', 'firehouse' ) );
			}
			if ( $stopped ) {
				/* translators: %s: Google's reason, as SAFETY. */
				return array( 'problem' => sprintf( __( 'The model stopped without a reply (%s). Try again, or try another model.', 'firehouse' ), self::said( $finish, $credentials ) ) );
			}
			return array( 'problem' => __( 'Google’s Agent Platform answered, but with no reply the screen can read.', 'firehouse' ) );
		}
		return array(
			'text' => $text,
			'cut'  => $cut,
		);
	}

	/**
	 * The request's body: the system messages as its instruction, the others as the
	 * conversation, and the model's length limit and thinking. Every system message goes
	 * into the instruction, wherever it stands in the conversation, so one placed after
	 * the source would be read before it. Nothing else is sent: no tools, search or URL
	 * fetching, so nothing in the source can have the model act or fetch.
	 *
	 * @param string                                     $model    One of MODELS.
	 * @param list<array{role: string, content: string}> $messages The conversation.
	 * @return array<string, mixed>
	 */
	public static function request( string $model, array $messages ): array {
		$system   = array();
		$contents = array();
		foreach ( $messages as $message ) {
			if ( 'system' === $message['role'] ) {
				$system[] = array( 'text' => $message['content'] );
				continue;
			}
			$contents[] = array(
				'role'  => 'assistant' === $message['role'] ? 'model' : 'user',
				'parts' => array( array( 'text' => $message['content'] ) ),
			);
		}
		$config = array( 'maxOutputTokens' => self::MODELS[ $model ]['max_tokens'] );
		if ( isset( self::MODELS[ $model ]['thinking'] ) ) {
			$config['thinkingConfig'] = self::MODELS[ $model ]['thinking'];
		}
		return ( array() === $system ? array() : array( 'systemInstruction' => array( 'parts' => $system ) ) ) + array(
			'contents'         => $contents,
			'generationConfig' => $config,
		);
	}

	/**
	 * A reply's text: the parts of its first candidate that aren't the model's thoughts,
	 * joined. Null when there's none.
	 *
	 * @param mixed $body The response's body.
	 */
	public static function text( mixed $body ): ?string {
		$parts = is_array( $body ) ? ( $body['candidates'][0]['content']['parts'] ?? null ) : null;
		if ( ! is_array( $parts ) ) {
			return null;
		}
		$text = array();
		foreach ( $parts as $part ) {
			if ( is_array( $part ) && is_string( $part['text'] ?? null ) && true !== ( $part['thought'] ?? false ) ) {
				$text[] = $part['text'];
			}
		}
		return array() === $text ? null : implode( '', $text );
	}

	/**
	 * What a refusal says: in plain words for the ones an editor can act on, then
	 * Google's own, without the key.
	 *
	 * @param int                                               $code        The HTTP status code.
	 * @param array<mixed>                                      $body        The response's body.
	 * @param array{project: string, key: string, from: string} $credentials The key.
	 */
	private static function refusal( int $code, array $body, array $credentials ): string {
		$error  = $body['error']['message'] ?? '';
		$error  = is_string( $error ) ? self::said( $error, $credentials ) : '';
		$reason = '' !== $error ? $error : __( 'no reason given', 'firehouse' );
		if ( 429 === $code ) {
			/* translators: %s: Google's error message. */
			return sprintf( __( 'Google turned the request away for now: the project’s quota for this model is used up, or the model is busy. Try again in a while, or try another model. (HTTP 429: %s)', 'firehouse' ), $reason );
		}
		if ( 500 <= $code ) {
			/* translators: 1: the HTTP status code, 2: Google's error message. */
			return sprintf( __( 'Google’s Agent Platform had a problem of its own. Try again in a while, or try another model. (HTTP %1$d: %2$s)', 'firehouse' ), $code, $reason );
		}
		if ( 401 === $code || 403 === $code ) {
			/* translators: 1: the HTTP status code, 2: Google's error message. */
			return sprintf( __( 'Google refused the key. Tell an administrator: the API key may be wrong, expired, not bound to a service account or restricted to other APIs, or its service account may not be allowed to use Agent Platform in this project. Google says “API keys are not supported” of any key it can’t tie to a service account. (HTTP %1$d: %2$s)', 'firehouse' ), $code, $reason );
		}
		return '' !== $error
			/* translators: 1: the HTTP status code, 2: Google's error message. */
			? sprintf( __( 'Google’s Agent Platform refused the request (%1$d): %2$s', 'firehouse' ), $code, $error )
			/* translators: %d: the HTTP status code. */
			: sprintf( __( 'Google’s Agent Platform refused the request (%d).', 'firehouse' ), $code );
	}

	/**
	 * The project ID and API key, and where they came from: the ones saved on FireHouse →
	 * Settings if their API key opens, otherwise wp-config.php's constants; either only
	 * when shaped like Google's.
	 *
	 * @return array{project: string, key: string, from: string}|null
	 */
	private static function credentials(): ?array {
		$saved = Agent_Platform_Key::credentials();
		if ( null !== $saved && self::project_shaped( $saved['project'] ) && self::key_shaped( $saved['key'] ) ) {
			return $saved + array( 'from' => self::FROM_SETTINGS );
		}
		$project = defined( self::PROJECT ) ? constant( self::PROJECT ) : null;
		$key     = defined( self::API_KEY ) ? constant( self::API_KEY ) : null;
		if ( ! self::project_shaped( $project ) || ! self::key_shaped( $key ) ) {
			return null;
		}
		return array(
			'project' => $project,
			'key'     => $key,
			'from'    => self::FROM_CONFIG,
		);
	}

	/**
	 * A message from WordPress or Google as the screen may show it: one line, short, and
	 * without the API key, should it be in it.
	 *
	 * @param string                                            $message     The message.
	 * @param array{project: string, key: string, from: string} $credentials The key.
	 */
	private static function said( string $message, array $credentials ): string {
		$message = str_replace( $credentials['key'], '…', $message );
		$message = trim( (string) preg_replace( '/\s+/u', ' ', $message ) );
		return mb_strlen( $message ) > self::LONGEST_ERROR ? mb_substr( $message, 0, self::LONGEST_ERROR - 1 ) . '…' : $message;
	}
}
