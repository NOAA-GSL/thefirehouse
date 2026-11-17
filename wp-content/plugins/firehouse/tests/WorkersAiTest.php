<?php
/**
 * Tests for the Workers AI client.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Workers_Ai;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * The client asks only api.cloudflare.com, with the key from wp-config.php's constants,
 * one of a fixed list of models; it reads a reply in any of the shapes Workers AI's
 * models give, and never says the key back. A test that sets the constants runs in its
 * own process, since a constant can't be taken away again.
 */
final class WorkersAiTest extends WordPressTestCase {

	/**
	 * An account ID shaped like Cloudflare's.
	 */
	private const ACCOUNT_ID = '0123456789abcdef0123456789abcdef';

	/**
	 * A token shaped like Cloudflare's.
	 */
	private const API_TOKEN = 'test-token_0123456789ABCDEFGHIJ';

	/**
	 * What wp_safe_remote_post() was given.
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $requests = array();

	/**
	 * A WordPress whose requests are kept, and answered by the test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_json_encode' )->alias( static fn( mixed $data ): string => (string) json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standing in for wp_json_encode().
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( array $response ): int => $response['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( array $response ): string => $response['body'] );
	}

	/**
	 * Answers every request with a response.
	 *
	 * @param int                  $code The status code.
	 * @param array<string, mixed> $body The JSON body.
	 */
	private function answer( int $code, array $body ): void {
		Functions\when( 'wp_safe_remote_post' )->alias(
			function ( string $url, array $args ) use ( $code, $body ): array {
				$this->requests[] = array( $url, $args );
				return array(
					'response' => array( 'code' => $code ),
					'body'     => (string) json_encode( $body ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- A test's response.
				);
			}
		);
	}

	/**
	 * Sets the key, as wp-config.php would.
	 *
	 * @param string $account The account ID.
	 * @param string $token   The token.
	 */
	private static function key( string $account = self::ACCOUNT_ID, string $token = self::API_TOKEN ): void {
		define( Workers_Ai::ACCOUNT, $account ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The plugin's own constant, as wp-config.php sets it.
		define( Workers_Ai::TOKEN, $token ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The plugin's own constant, as wp-config.php sets it.
	}

	/**
	 * Without the key, nothing is offered and nothing is asked.
	 */
	public function test_without_the_key_nothing_is_asked(): void {
		Functions\expect( 'wp_safe_remote_post' )->never();

		$this->assertFalse( Workers_Ai::configured() );
		$this->assertSame( array( 'problem' => 'The site has no Workers AI key.' ), Workers_Ai::ask( Workers_Ai::DEFAULT_MODEL, array() ) );
	}

	/**
	 * A key not shaped like Cloudflare's, which could add to the address or the header,
	 * counts as none.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_key_of_the_wrong_shape_counts_as_none(): void {
		self::key( self::ACCOUNT_ID, "a-token\r\nX-Other: 1 and more" );

		$this->assertFalse( Workers_Ai::configured() );
	}

	/**
	 * Only the listed models are offered, each from a company in the United States, and
	 * each called by its name on the screens.
	 */
	public function test_only_the_listed_models_are_offered(): void {
		$this->assertTrue( Workers_Ai::offered( '@cf/meta/llama-4-scout-17b-16e-instruct' ) );
		$this->assertTrue( Workers_Ai::offered( Workers_Ai::DEFAULT_MODEL ) );
		foreach ( array( '@cf/qwen/qwq-32b', '@cf/nvidia/nemotron-3-120b-a12b', '@cf/meta/../../x', '', null, 42 ) as $model ) {
			$this->assertFalse( Workers_Ai::offered( $model ), var_export( $model, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- The failing value, in the message.
		}
		$this->assertSame( 'Llama 4 Scout (Meta)', Workers_Ai::label( '@cf/meta/llama-4-scout-17b-16e-instruct' ) );
		$this->assertSame(
			array( 'Llama 4 Scout (Meta)', 'gpt-oss-120b (OpenAI)', 'Gemma 4 26B (Google)', 'Inkling 2 Small (Thinking Machines)' ),
			array_column( Workers_Ai::MODELS, 'label' )
		);
	}

	/**
	 * A request goes to Cloudflare's API for the account and the model, with the token in
	 * its header, the conversation and the model's reply length in its body, and no
	 * redirect followed; the reply's text comes back.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_request_goes_to_cloudflare_with_the_key(): void {
		self::key();
		$this->answer(
			200,
			array(
				'success' => true,
				'result'  => array( 'response' => '[ { "topic": "observe" } ]' ),
			)
		);
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		$reply = Workers_Ai::ask( '@cf/openai/gpt-oss-120b', $messages );

		$this->assertSame(
			array(
				'text' => '[ { "topic": "observe" } ]',
				'cut'  => false,
			),
			$reply
		);
		list( $url, $args ) = $this->requests[0];
		$this->assertSame( 'https://api.cloudflare.com/client/v4/accounts/' . self::ACCOUNT_ID . '/ai/run/@cf/openai/gpt-oss-120b', $url );
		$this->assertSame( 'Bearer ' . self::API_TOKEN, $args['headers']['Authorization'] );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertSame(
			array(
				'messages'   => $messages,
				'max_tokens' => 16384,
			),
			json_decode( $args['body'], true )
		);
	}

	/**
	 * Gemma 4 26B is asked not to think, which left it no room to answer, and Inkling 2
	 * Small to reason at "low"; the other models get only the messages and their length
	 * limit.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_some_models_are_asked_to_think_less(): void {
		self::key();
		$this->answer(
			200,
			array(
				'success' => true,
				'result'  => array( 'choices' => array( array( 'message' => array( 'content' => '[]' ) ) ) ),
			)
		);

		Workers_Ai::ask( '@cf/google/gemma-4-26b-a4b-it', array() );
		Workers_Ai::ask( Workers_Ai::DEFAULT_MODEL, array() );
		Workers_Ai::ask( '@cf/thinkingmachines/inkling-2-small', array() );

		$this->assertSame(
			array(
				'messages'             => array(),
				'max_tokens'           => 8192,
				'chat_template_kwargs' => array( 'enable_thinking' => false ),
			),
			json_decode( $this->requests[0][1]['body'], true )
		);
		$this->assertSame( array( 'messages', 'max_tokens' ), array_keys( json_decode( $this->requests[1][1]['body'], true ) ) );
		$this->assertSame( 'https://api.cloudflare.com/client/v4/accounts/' . self::ACCOUNT_ID . '/ai/run/@cf/thinkingmachines/inkling-2-small', $this->requests[2][0] );
		$this->assertSame(
			array(
				'messages'              => array(),
				'max_tokens'            => 16384,
				'reasoning_effort'      => 'low',
				'max_completion_tokens' => 16384,
			),
			json_decode( $this->requests[2][1]['body'], true )
		);
	}

	/**
	 * A model not on the list is never asked for, even with the key.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_model_not_on_the_list_is_never_asked(): void {
		self::key();
		Functions\expect( 'wp_safe_remote_post' )->never();

		$this->assertSame( array( 'problem' => 'That model isn’t one the screen offers.' ), Workers_Ai::ask( '@cf/qwen/qwq-32b', array() ) );
	}

	/**
	 * A refusal says Cloudflare's reason, without the key even if the reason holds it;
	 * one that can't be sent says WordPress's.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_refusal_never_says_the_key(): void {
		self::key();
		$this->answer(
			401,
			array(
				'success' => false,
				'errors'  => array( array( 'message' => 'Bad token ' . self::API_TOKEN . " for\naccount " . self::ACCOUNT_ID ) ),
			)
		);

		$this->assertSame( array( 'problem' => 'Workers AI refused the request (401): Bad token … for account …' ), Workers_Ai::ask( Workers_Ai::DEFAULT_MODEL, array() ) );

		Functions\when( 'wp_safe_remote_post' )->justReturn( new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );
		$this->assertSame( array( 'problem' => 'Workers AI couldn’t be reached: cURL error 28: Operation timed out' ), Workers_Ai::ask( Workers_Ai::DEFAULT_MODEL, array() ) );
	}

	/**
	 * A timeout (HTTP 408) says in plain words that the model may be busy, then
	 * Cloudflare's reason and request ID, without the key.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_timeout_says_the_model_may_be_busy(): void {
		self::key();
		$busy = 'Cloudflare stopped waiting for the model’s reply, as it can when the model is busy. Try again in a while, or try another model. (HTTP 408: %s)';
		$this->answer(
			408,
			array(
				'success' => false,
				'errors'  => array( array( 'message' => 'AiError: AiError: Request timeout (cd9eeec7-2f29-4b34-8187-91641b4b161d) for ' . self::ACCOUNT_ID ) ),
			)
		);
		$this->assertSame( array( 'problem' => sprintf( $busy, 'AiError: AiError: Request timeout (cd9eeec7-2f29-4b34-8187-91641b4b161d) for …' ) ), Workers_Ai::ask( Workers_Ai::DEFAULT_MODEL, array() ) );

		$this->answer( 408, array() );
		$this->assertSame( array( 'problem' => sprintf( $busy, 'no reason given' ) ), Workers_Ai::ask( Workers_Ai::DEFAULT_MODEL, array() ) );
	}

	/**
	 * A reply cut off at its length limit says so; one with no text is a problem.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_reply_cut_off_says_so(): void {
		self::key();
		$this->answer(
			200,
			array(
				'success' => true,
				'result'  => array(
					'choices' => array(
						array(
							'message'       => array( 'content' => 'Partly [' ),
							'finish_reason' => 'length',
						),
					),
				),
			)
		);
		$this->assertSame(
			array(
				'text' => 'Partly [',
				'cut'  => true,
			),
			Workers_Ai::ask( Workers_Ai::DEFAULT_MODEL, array() )
		);

		$this->answer(
			200,
			array(
				'success' => true,
				'result'  => array( 'response' => '  ' ),
			)
		);
		$this->assertSame( array( 'problem' => 'Workers AI answered, but with no reply the screen can read.' ), Workers_Ai::ask( Workers_Ai::DEFAULT_MODEL, array() ) );

		// Llama's shape says nothing of why it stopped, but its count of tokens does.
		$this->answer(
			200,
			array(
				'success' => true,
				'result'  => array(
					'response' => 'Partly [',
					'usage'    => array( 'completion_tokens' => 8192 ),
				),
			)
		);
		$this->assertTrue( Workers_Ai::ask( Workers_Ai::DEFAULT_MODEL, array() )['cut'] );

		// A reasoning model that spent its whole limit thinking gives no reply at all.
		$this->answer(
			200,
			array(
				'success' => true,
				'result'  => array(
					'status' => 'incomplete',
					'output' => array( array( 'type' => 'reasoning' ) ),
				),
			)
		);
		$this->assertSame( array( 'problem' => 'The model used its whole length limit before it gave a reply, as a reasoning model can. Try again, or try another model.' ), Workers_Ai::ask( '@cf/openai/gpt-oss-120b', array() ) );
	}

	/**
	 * A reply's text is read from each shape Workers AI's models give: a response, a chat
	 * completion, or a Responses API output.
	 */
	public function test_a_reply_is_read_in_each_shape(): void {
		$this->assertSame( 'one', Workers_Ai::text( array( 'response' => 'one' ) ) );
		$this->assertSame( 'two', Workers_Ai::text( array( 'choices' => array( array( 'message' => array( 'content' => 'two' ) ) ) ) ) );
		$this->assertSame(
			"three\nfour",
			Workers_Ai::text(
				array(
					'output' => array(
						array(
							'type'    => 'reasoning',
							'content' => array(
								array(
									'type' => 'reasoning_text',
									'text' => 'thinking',
								),
							),
						),
						array(
							'type'    => 'message',
							'content' => array(
								array(
									'type' => 'output_text',
									'text' => 'three',
								),
								array(
									'type' => 'output_text',
									'text' => 'four',
								),
							),
						),
					),
				)
			)
		);
		$this->assertNull( Workers_Ai::text( array( 'choices' => array() ) ) );
		$this->assertNull( Workers_Ai::text( 'text' ) );
	}
}
