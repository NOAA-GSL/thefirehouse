<?php
/**
 * The AI service being tried for the synthesis: Cloudflare Workers AI.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asks a Workers AI model for a reply (Eric, 16 November: try Workers AI first; NOAA's
 * AI policy still decides whether it's used on the sites, open question 7).
 *
 * - **The key stays out of the database.** The Cloudflare account ID and API token are
 *   constants in wp-config.php, FIREHOUSE_WORKERS_AI_ACCOUNT and
 *   FIREHOUSE_WORKERS_AI_TOKEN, so no database copy, export or screen holds them. They
 *   are read only here, sent only to Cloudflare's API, and never stored, shown or
 *   written into a message.
 * - **Off without them.** With either missing or not shaped like Cloudflare's,
 *   configured() is false and nothing offers Analyze results; the paste stays.
 * - **One host,** api.cloudflare.com, over HTTPS, with no redirects followed.
 * - **A fixed list of models,** all from companies in the United States (Eric, 16
 *   November), so the address is never built from typed text.
 */
final class Workers_Ai {

	/**
	 * The constant holding the Cloudflare account ID.
	 */
	public const ACCOUNT = 'FIREHOUSE_WORKERS_AI_ACCOUNT';

	/**
	 * The constant holding the API token, one limited to Workers AI.
	 */
	public const TOKEN = 'FIREHOUSE_WORKERS_AI_TOKEN';

	/**
	 * The one host asked.
	 */
	public const HOST = 'api.cloudflare.com';

	/**
	 * The models offered, by Workers AI's name for each: what the screen calls it, and
	 * the most tokens its reply may take, which leaves a reasoning model room to think
	 * as well as answer. Each reads at least 128,000 tokens, many times the source.
	 * Llama 3.3 70B is left out: its 24,000 would run out as projects arrive.
	 */
	public const MODELS = array(
		'@cf/meta/llama-4-scout-17b-16e-instruct' => array(
			'label'      => 'Llama 4 Scout (Meta)',
			'max_tokens' => 8192,
		),
		'@cf/openai/gpt-oss-120b'                 => array(
			'label'      => 'gpt-oss-120b (OpenAI)',
			'max_tokens' => 16384,
		),
		'@cf/google/gemma-4-26b-a4b-it'           => array(
			'label'      => 'Gemma 4 26B (Google)',
			'max_tokens' => 8192,
		),
		'@cf/nvidia/nemotron-3-120b-a12b'         => array(
			'label'      => 'Nemotron 3 120B (NVIDIA)',
			'max_tokens' => 16384,
		),
	);

	/**
	 * The model offered first.
	 */
	public const DEFAULT_MODEL = '@cf/meta/llama-4-scout-17b-16e-instruct';

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
	 * How much of Cloudflare's error message is kept.
	 */
	private const LONGEST_ERROR = 300;

	/**
	 * Whether the site has a Workers AI key.
	 */
	public static function configured(): bool {
		return null !== self::credentials();
	}

	/**
	 * Whether a name is one of the models offered.
	 *
	 * @param mixed $model The name.
	 */
	public static function offered( mixed $model ): bool {
		return is_string( $model ) && isset( self::MODELS[ $model ] );
	}

	/**
	 * What the screens call a model, as "Llama 4 Scout (Meta)".
	 *
	 * @param string $model Its Workers AI name.
	 */
	public static function label( string $model ): string {
		return self::MODELS[ $model ]['label'] ?? $model;
	}

	/**
	 * Asks a model: its reply's text, or what went wrong, said without the key.
	 *
	 * @param string                                     $model    One of MODELS.
	 * @param list<array{role: string, content: string}> $messages The conversation.
	 * @return array{text: string, cut: bool}|array{problem: string}
	 */
	public static function ask( string $model, array $messages ): array {
		$credentials = self::credentials();
		if ( null === $credentials ) {
			return array( 'problem' => __( 'The site has no Workers AI key.', 'firehouse' ) );
		}
		if ( ! self::offered( $model ) ) {
			return array( 'problem' => __( 'That model isn’t one the screen offers.', 'firehouse' ) );
		}
		$response = wp_safe_remote_post(
			'https://' . self::HOST . '/client/v4/accounts/' . $credentials['account'] . '/ai/run/' . $model,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'headers'             => array(
					'Authorization' => 'Bearer ' . $credentials['token'],
					'Content-Type'  => 'application/json',
				),
				'body'                => (string) wp_json_encode(
					array(
						'messages'   => $messages,
						'max_tokens' => self::MODELS[ $model ]['max_tokens'],
					)
				),
				'user-agent'          => 'FireHouse WordPress plugin',
				'limit_response_size' => self::MOST_BYTES,
			)
		);
		if ( is_wp_error( $response ) ) {
			/* translators: %s: why the request failed, as WordPress says it. */
			return array( 'problem' => sprintf( __( 'Workers AI couldn’t be reached: %s', 'firehouse' ), self::said( $response->get_error_message(), $credentials ) ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$body = is_array( $body ) ? $body : array();
		if ( 200 !== $code || false === ( $body['success'] ?? true ) ) {
			$error = $body['errors'][0]['message'] ?? $body['error']['message'] ?? $body['error'] ?? '';
			return array(
				'problem' => is_string( $error ) && '' !== $error
					/* translators: 1: the HTTP status code, 2: Cloudflare's error message. */
					? sprintf( __( 'Workers AI refused the request (%1$d): %2$s', 'firehouse' ), $code, self::said( $error, $credentials ) )
					/* translators: %d: the HTTP status code. */
					: sprintf( __( 'Workers AI refused the request (%d).', 'firehouse' ), $code ),
			);
		}
		$text = self::text( $body['result'] ?? null );
		if ( null === $text || '' === trim( $text ) ) {
			return array( 'problem' => __( 'Workers AI answered, but with no reply the screen can read.', 'firehouse' ) );
		}
		return array(
			'text' => $text,
			'cut'  => 'length' === ( $body['result']['choices'][0]['finish_reason'] ?? null ),
		);
	}

	/**
	 * A reply's text, in any of the shapes Workers AI's models give it: `response` (as
	 * Llama's), a chat completion's first choice (as Gemma's and Nemotron's), or a
	 * Responses API output's text (as gpt-oss can). Null when there's none.
	 *
	 * @param mixed $result The result.
	 */
	public static function text( mixed $result ): ?string {
		if ( ! is_array( $result ) ) {
			return null;
		}
		if ( is_string( $result['response'] ?? null ) ) {
			return $result['response'];
		}
		if ( is_string( $result['choices'][0]['message']['content'] ?? null ) ) {
			return $result['choices'][0]['message']['content'];
		}
		$parts = array();
		foreach ( is_array( $result['output'] ?? null ) ? $result['output'] : array() as $item ) {
			if ( is_array( $item ) && 'message' === ( $item['type'] ?? null ) && is_array( $item['content'] ?? null ) ) {
				foreach ( $item['content'] as $content ) {
					if ( is_array( $content ) && 'output_text' === ( $content['type'] ?? null ) && is_string( $content['text'] ?? null ) ) {
						$parts[] = $content['text'];
					}
				}
			}
		}
		return array() === $parts ? null : implode( "\n", $parts );
	}

	/**
	 * The account ID and token, when both are set and shaped like Cloudflare's: the ID
	 * 32 hexadecimal digits, the token letters, digits, hyphens and underscores, so
	 * neither can add to the address or the header.
	 *
	 * @return array{account: string, token: string}|null
	 */
	private static function credentials(): ?array {
		$account = defined( self::ACCOUNT ) ? constant( self::ACCOUNT ) : null;
		$token   = defined( self::TOKEN ) ? constant( self::TOKEN ) : null;
		if ( ! is_string( $account ) || 1 !== preg_match( '/\A[0-9a-f]{32}\z/', $account ) ) {
			return null;
		}
		if ( ! is_string( $token ) || 1 !== preg_match( '/\A[A-Za-z0-9_-]{20,200}\z/', $token ) ) {
			return null;
		}
		return array(
			'account' => $account,
			'token'   => $token,
		);
	}

	/**
	 * A message from WordPress or Cloudflare as the screen may show it: one line, short,
	 * and without the account ID or the token, should either be in it.
	 *
	 * @param string                                $message     The message.
	 * @param array{account: string, token: string} $credentials The key.
	 */
	private static function said( string $message, array $credentials ): string {
		$message = str_replace( array( $credentials['token'], $credentials['account'] ), '…', $message );
		$message = trim( (string) preg_replace( '/\s+/u', ' ', $message ) );
		return mb_strlen( $message ) > self::LONGEST_ERROR ? mb_substr( $message, 0, self::LONGEST_ERROR - 1 ) . '…' : $message;
	}
}
