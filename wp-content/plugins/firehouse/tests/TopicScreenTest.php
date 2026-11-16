<?php
/**
 * Tests for the topic edit screen.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Edit_Screen;
use Firehouse\Project_Screen;
use Firehouse\Store;
use Firehouse\Topic_Screen;

/**
 * The screen saves the team's words for a topic exactly as typed, all or nothing,
 * never leaves it without a label, and shows its synthesis read-only.
 */
final class TopicScreenTest extends WordPressTestCase {

	/**
	 * Meta, by post ID and key.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $stored = array();

	/**
	 * Posts, by ID.
	 *
	 * @var array<int, \WP_Post>
	 */
	private array $posts = array();

	/**
	 * Stubs what the screen reads and writes, with a topic and two projects stored.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->stored = array(
			40 => array(
				'fh_short'       => 'Obs & <b>more</b>',
				'fh_description' => 'R&amp;D description',
				'fh_covers'      => array( 'Networks', 'Sensors & <i>feeds</i>' ),
				'fh_order'       => '1',
				'fh_topNeeds'    => array(
					array(
						'text'     => 'Longer-lead <outlooks>',
						'mentions' => 3,
						'projects' => array( 11, 12, 99 ),
					),
					array(
						'text'     => 'Counted by its projects',
						'projects' => array( 11 ),
					),
				),
				'fh_sourceCount' => '8',
				'fh_model'       => 'NotebookLM',
			),
			11 => array(
				'fh_title'       => 'Smoke & <b>fire</b> study',
				'fh_irbApproved' => '1',
			),
			12 => array(
				'fh_title'       => 'A draft study',
				'fh_irbApproved' => '1',
			),
		);
		$this->posts  = array(
			40 => self::post(),
			11 => new \WP_Post(
				array(
					'ID'        => 11,
					'post_type' => 'fh_project',
				)
			),
			12 => new \WP_Post(
				array(
					'ID'          => 12,
					'post_type'   => 'fh_project',
					'post_status' => 'draft',
				)
			),
		);
		Functions\when( 'metadata_exists' )->alias( fn( string $type, int $id, string $key ): bool => array_key_exists( $key, $this->stored[ $id ] ?? array() ) );
		Functions\when( 'get_post_meta' )->alias( fn( int $id, string $key ): mixed => $this->stored[ $id ][ $key ] ?? '' );
		Functions\when( 'get_post' )->alias( fn( mixed $id ): ?\WP_Post => $this->posts[ (int) $id ] ?? null );
		Functions\when( 'wp_specialchars_decode' )->alias( static fn( string $text ): string => htmlspecialchars_decode( $text, ENT_QUOTES ) );
		Functions\when( 'rest_validate_value_from_schema' )->justReturn( true );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'get_current_user_id' )->justReturn( 6 );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_slash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( mixed $value ): int => abs( (int) $value ) );
		$_POST = array();
		$_GET  = array();
		Topic_Screen::load();
		Project_Screen::load();
		// No label kept from another test's request.
		( new \ReflectionProperty( Topic_Screen::class, 'kept' ) )->setValue( null, array() );
	}

	/**
	 * Clears the request.
	 */
	protected function tearDown(): void {
		$_POST = array();
		$_GET  = array();
		parent::tearDown();
	}

	/**
	 * The topic post.
	 *
	 * @param int $id Its ID.
	 */
	private static function post( int $id = 40 ): \WP_Post {
		return new \WP_Post(
			array(
				'ID'         => $id,
				'post_type'  => 'fh_topic',
				'post_name'  => 'observe',
				'post_title' => 'Observations &amp; Monitoring',
			)
		);
	}

	/**
	 * The form's input: every field, as the form sends it, with $changes.
	 *
	 * @param array<string, mixed> $changes The fields to change.
	 * @return array<string, mixed>
	 */
	private static function input( array $changes ): array {
		return $changes + array(
			'short'       => 'Observations',
			'description' => '',
			'covers'      => '',
		);
	}

	/**
	 * Shows a refusal on the topic's screen, as the screen after a refused save would.
	 *
	 * @param array<string, string> $errors The problems.
	 * @param array<string, string> $values What was typed.
	 */
	private static function refuse( array $errors, array $values = array() ): void {
		$_GET = array( 'post' => '40' );
		Functions\when( 'wp_add_inline_style' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_transient' )->alias(
			static fn( string $key ): mixed => 'firehouse_topic_40_6' === $key ? array(
				'values' => $values + array_fill_keys( Topic_Screen::FIELDS, '' ),
				'errors' => $errors,
			) : false
		);
		Topic_Screen::load();
	}

	/**
	 * Valid input is kept as typed, but for a browser's line breaks; what the area
	 * covers is one item a line, each trimmed, blank lines dropped.
	 */
	public function test_valid_input_is_kept_as_typed(): void {
		$checked = Topic_Screen::check(
			array(),
			array(
				'short'       => ' Obs & <b>more</b> ',
				'description' => "One line\r\nand the next",
				'covers'      => "  Networks \r\n\r\n\tSensors & <i>feeds</i>\n \n",
			)
		);

		$this->assertSame( array(), $checked['errors'] );
		$this->assertSame( array(), $checked['delete'] );
		$this->assertSame(
			array(
				'short'       => ' Obs & <b>more</b> ',
				'description' => "One line\nand the next",
				'covers'      => array( 'Networks', 'Sensors & <i>feeds</i>' ),
			),
			$checked['set']
		);
		$this->assertSame( "  Networks \n\n\tSensors & <i>feeds</i>\n \n", $checked['values']['covers'], 'Shown back as typed.' );
	}

	/**
	 * An empty description, or nothing the area covers, removes it.
	 */
	public function test_empty_fields_are_removed(): void {
		$checked = Topic_Screen::check(
			array(),
			self::input(
				array(
					'description' => " \n ",
					'covers'      => "\n \n",
				)
			)
		);

		$this->assertSame( array(), $checked['errors'] );
		$this->assertSame( array( 'short' => 'Observations' ), $checked['set'] );
		$this->assertSame( array( 'description', 'covers' ), $checked['delete'] );
	}

	/**
	 * A blank short label is refused: spaces, or characters that show nothing, written
	 * as characters or as entities.
	 *
	 * @dataProvider blanks
	 *
	 * @param string $blank Text that shows nothing.
	 */
	public function test_a_blank_short_label_is_refused( string $blank ): void {
		$checked = Topic_Screen::check( array(), self::input( array( 'short' => $blank ) ) );

		$this->assertSame( array( 'short' => 'A topic needs a short label.' ), $checked['errors'] );
		$this->assertArrayNotHasKey( 'short', $checked['set'] );
	}

	/**
	 * Text that shows nothing.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function blanks(): array {
		return array(
			'spaces and a tab'   => array( " \t " ),
			'a no-break space'   => array( "\u{00A0}" ),
			'a zero-width space' => array( "\u{200B}\u{FEFF}" ),
			'entities'           => array( '&nbsp;&#8203; &#32;' ),
			'a line break'       => array( "\n" ),
			'nothing'            => array( '' ),
			'a grapheme joiner'  => array( "\u{034F}" ),
			'variation selector' => array( "\u{FE0F}\u{E0100}" ),
			'fillers'            => array( "\u{3164}\u{FFA0}\u{115F}" ),
			'a blank Braille'    => array( "\u{2800}" ),
		);
	}

	/**
	 * Lines of what the area covers that show nothing are dropped, and a description
	 * that shows nothing is removed.
	 */
	public function test_invisible_lines_and_descriptions_are_dropped(): void {
		$checked = Topic_Screen::check(
			array(),
			self::input(
				array(
					'description' => "\u{00A0}",
					'covers'      => "One\n\u{200B}\n&nbsp;\nTwo",
				)
			)
		);

		$this->assertSame( array( 'One', 'Two' ), $checked['set']['covers'] );
		$this->assertSame( array( 'description' ), $checked['delete'] );
	}

	/**
	 * Text that isn't valid UTF-8 is refused before anything is saved, and shown back
	 * with each bad byte as �, which the refusal's transient can store; the rest stays
	 * as typed.
	 */
	public function test_text_that_isnt_utf8_is_refused(): void {
		$checked = Topic_Screen::check( array(), self::input( array( 'short' => "Fore\xC3(cast & <b>" ) ) );

		$this->assertSame( array( 'short' => 'This field has characters that can’t be stored. Retype it.' ), $checked['errors'] );
		$this->assertSame( "Fore\u{FFFD}(cast & <b>", $checked['values']['short'] );
	}

	/**
	 * A label's control characters are dropped, as kses would have dropped them, since
	 * a feed's XML can't hold them; a label of nothing else keeps the old one.
	 */
	public function test_a_labels_control_characters_are_dropped(): void {
		$this->assertSame( 'Forecast', Topic_Screen::keep_label( array( 'post_title' => "Fore\x1B\x08\x00cast" ), array( 'ID' => 40 ) )['post_title'] );
		$this->assertSame( 'Observations &amp; Monitoring', Topic_Screen::keep_label( array( 'post_title' => "\x07\x1F" ), array( 'ID' => 40 ) )['post_title'] );
	}

	/**
	 * Characters that show something aren't blank, however new or private: an emoji
	 * newer than the server's tables, or a private-use character.
	 */
	public function test_new_or_private_characters_arent_blank(): void {
		$this->assertSame( array(), Topic_Screen::check( array(), self::input( array( 'short' => "\u{1FAE8}" ) ) )['errors'] );
		$this->assertSame( array(), Topic_Screen::check( array(), self::input( array( 'short' => "\u{E000}" ) ) )['errors'] );
		$this->assertSame( array(), Topic_Screen::check( array(), self::input( array( 'short' => "\u{2764}\u{FE0F}" ) ) )['errors'], 'An emoji with its variation selector.' );
	}

	/**
	 * A label is blank by what the topic page shows: an entity escaped once more, which
	 * the page prints as the entity, shows nothing, and keeps the old label; one escaped
	 * twice more shows as text.
	 */
	public function test_a_label_is_blank_by_what_the_page_shows(): void {
		// As WordPress's: an entity isn't escaped again, where Brain Monkey's would be.
		Functions\when( 'esc_html' )->alias( static fn( string $text ): string => htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false ) );
		$this->assertSame( 'Observations &amp; Monitoring', Topic_Screen::keep_label( array( 'post_title' => '&amp;amp;nbsp;' ), array( 'ID' => 40 ) )['post_title'] );
		$this->assertSame( '&amp;amp;nbsp;', Topic_Screen::keep_label( array( 'post_title' => '&amp;amp;amp;nbsp;' ), array( 'ID' => 40 ) )['post_title'] );
	}

	/**
	 * An unchanged label is kept exactly as stored, sent as the title box shows it or
	 * as Quick Edit does, even one holding literal entity text, which a change can't
	 * keep.
	 */
	public function test_an_unchanged_label_is_kept_as_stored(): void {
		$this->posts[40]->post_title = 'Fire &amp;amp; Smoke';
		$kept                        = static fn( string $sent ): mixed => Topic_Screen::keep_label( array( 'post_title' => $sent ), array( 'ID' => 40 ) )['post_title'];

		$this->assertSame( 'Fire &amp;amp; Smoke', $kept( ' Fire &amp; Smoke ' ), 'As the title box shows it.' );
		$this->assertSame( 'Fire &amp;amp; Smoke', $kept( 'Fire &amp;amp; Smoke' ), 'As Quick Edit sends it.' );
		$this->assertSame( 'Fire &amp; Smoke 2', $kept( 'Fire &amp; Smoke 2' ), 'Changed, it’s read back to text.' );
	}

	/**
	 * A field that's missing or isn't text is refused, not taken as empty.
	 */
	public function test_input_that_is_missing_or_not_text_is_refused(): void {
		$checked = Topic_Screen::check( array(), array( 'covers' => array( 'x' ) ) );

		$this->assertSame( Topic_Screen::FIELDS, array_keys( $checked['errors'] ) );
		$this->assertSame( 'This field didn’t arrive as text. Reload the screen and enter it again.', $checked['errors']['short'] );
	}

	/**
	 * A value the field's schema refuses is refused, with the schema's reason.
	 */
	public function test_a_field_its_schema_refuses_is_refused(): void {
		Functions\when( 'rest_validate_value_from_schema' )->alias( static fn( mixed $value, array $schema, string $name ): mixed => 'covers' === $name ? new \WP_Error( 'x', 'covers[0] is too long.' ) : true );
		$checked = Topic_Screen::check( array(), self::input( array( 'covers' => 'One' ) ) );

		$this->assertSame( array( 'covers' => 'covers[0] is too long.' ), $checked['errors'] );
	}

	/**
	 * A save from anywhere but the screen's form, such as Quick Edit, writes nothing.
	 */
	public function test_a_save_without_the_screens_form_writes_nothing(): void {
		Functions\expect( 'update_post_meta' )->never();
		Functions\expect( 'delete_post_meta' )->never();
		Functions\expect( 'set_transient' )->never();
		$_POST = array( 'firehouse' => self::input( array() ) );
		Topic_Screen::save( 40, self::post() );
		// The project screen's nonce is not the topic screen's.
		$_POST['firehouse_project_nonce'] = 'good';
		Topic_Screen::save( 40, self::post() );
	}

	/**
	 * A save whose nonce isn't the topic's writes nothing.
	 */
	public function test_a_save_with_a_wrong_nonce_writes_nothing(): void {
		Functions\expect( 'wp_verify_nonce' )->once()->with( 'forged', 'firehouse_topic_fields_40' )->andReturn( false );
		Functions\expect( 'update_post_meta' )->never();
		$_POST = array(
			'firehouse_topic_nonce' => 'forged',
			'firehouse'             => self::input( array() ),
		);
		Topic_Screen::save( 40, self::post() );
	}

	/**
	 * The topic screen saves only topics, and the project screen only projects.
	 */
	public function test_each_screen_saves_only_its_own_post_type(): void {
		Functions\expect( 'wp_verify_nonce' )->never();
		Functions\expect( 'update_post_meta' )->never();
		$_POST = array(
			'firehouse_topic_nonce'   => 'good',
			'firehouse_project_nonce' => 'good',
			'firehouse'               => self::input( array() ),
		);
		Topic_Screen::save( 11, $this->posts[11] );
		Project_Screen::save( 40, self::post() );
	}

	/**
	 * A good save stores each field as typed, removes the empty ones, and clears any
	 * refusal still waiting.
	 */
	public function test_a_good_save_writes_each_field(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( 'delete_transient' )->once()->with( 'firehouse_topic_40_6' );
		$written = array();
		Functions\when( 'update_post_meta' )->alias(
			static function ( int $id, string $key, mixed $value ) use ( &$written ): bool {
				$written[ $key ] = $value;
				return true;
			}
		);
		$deleted = array();
		Functions\when( 'delete_post_meta' )->alias(
			static function ( int $id, string $key ) use ( &$deleted ): bool {
				$deleted[] = $key;
				return true;
			}
		);
		$_POST = array(
			'firehouse_topic_nonce' => 'good',
			'firehouse'             => self::input(
				array(
					'short'  => 'Obs "quoted" \\',
					'covers' => "A\nB",
				)
			),
		);
		Topic_Screen::save( 40, self::post() );

		$this->assertSame( array( 'fh_description' ), $deleted );
		$this->assertSame(
			array(
				'fh_short'  => 'Obs "quoted" \\',
				'fh_covers' => array( 'A', 'B' ),
			),
			$written
		);
	}

	/**
	 * A refused save writes no field, and keeps the input and problems for the next
	 * screen, under the topic's own key.
	 */
	public function test_a_refused_save_writes_nothing_and_keeps_the_input(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( 'update_post_meta' )->never();
		Functions\expect( 'delete_post_meta' )->never();
		$kept = null;
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, mixed $value, int $expires ) use ( &$kept ): bool {
				$kept = array( $key, $value, $expires );
				return true;
			}
		);
		$_POST = array(
			'firehouse_topic_nonce' => 'good',
			'firehouse'             => self::input(
				array(
					'short'       => '',
					'description' => 'Typed',
				)
			),
		);
		Topic_Screen::save( 40, self::post() );

		$this->assertNotFalse( has_filter( 'redirect_post_location', array( Topic_Screen::class, 'without_message' ) ) );
		$this->assertSame( 'firehouse_topic_40_6', $kept[0] );
		$this->assertSame( 'Typed', $kept[1]['values']['description'] );
		$this->assertSame( array( 'short' ), array_keys( $kept[1]['errors'] ) );
		$this->assertSame( 300, $kept[2] );
	}

	/**
	 * A topic's label is kept as Store keeps it, escaped and trimmed, from text, by
	 * any route; an empty one keeps the label it had.
	 */
	public function test_the_label_is_kept_escaped_and_never_empty(): void {
		$slash = static fn( string $text ): string => addslashes( $text );
		Functions\when( 'wp_unslash' )->alias( static fn( string $text ): string => stripslashes( $text ) );
		Functions\when( 'wp_slash' )->alias( $slash );
		$label = static fn( string $title, int $id = 40 ): mixed => Topic_Screen::keep_label( array( 'post_title' => $slash( $title ) ), array( 'ID' => $id ) )['post_title'];

		$this->assertSame( $slash( 'Fire &amp; &quot;Smoke&quot; &lt;b&gt;' ), $label( ' Fire &amp; "Smoke" <b> ' ), 'As kses leaves it, or as typed.' );
		$this->assertSame( $slash( 'Back\\slash &amp; more' ), $label( 'Back\\slash & more' ) );
		$this->assertSame( $slash( 'Observations &amp; Monitoring' ), $label( " \n " ), 'An empty one keeps the label it had.' );
		$this->assertSame( $slash( 'Observations &amp; Monitoring' ), $label( "\u{00A0}&nbsp;\u{200B}" ), 'So does one that shows nothing.' );
		$this->assertSame( $slash( 'Observations &amp; Monitoring' ), $label( "Fore\xC3(cast" ), 'And one that isn’t UTF-8, which escaping would empty.' );
		$this->assertSame( $slash( '<b>Project</b>' ), $label( '<b>Project</b>', 11 ), 'Not a topic: left alone.' );
		$this->assertSame( $slash( '' ), $label( '', 0 ), 'No post: left alone.' );
	}

	/**
	 * The label is read as it was sent, before kses, which strips what looks like a tag
	 * from the saves of anyone who may not post unfiltered HTML, such as a FireHouse
	 * Editor; it's escaped instead.
	 */
	public function test_the_label_is_read_before_kses(): void {
		$slash = static fn( string $text ): string => addslashes( $text );
		Functions\when( 'wp_unslash' )->alias( static fn( string $text ): string => stripslashes( $text ) );
		Functions\when( 'wp_slash' )->alias( $slash );

		$kept = Topic_Screen::keep_label( array( 'post_title' => $slash( 'Smoke  exposure' ) ), array( 'ID' => 40 ), array( 'post_title' => $slash( 'Smoke <PM2.5> "exposure"' ) ) );
		$this->assertSame( $slash( 'Smoke &lt;PM2.5&gt; &quot;exposure&quot;' ), $kept['post_title'] );

		$kept = Topic_Screen::keep_label( array( 'post_title' => '' ), array( 'ID' => 40 ), array( 'post_title' => $slash( '<Draft>' ) ) );
		$this->assertSame( $slash( '&lt;Draft&gt;' ), $kept['post_title'], 'Not emptied, and not refused.' );
	}

	/**
	 * The plugin's own writes keep the label they give.
	 */
	public function test_the_plugins_own_writes_keep_their_label(): void {
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'kses_remove_filters' )->justReturn();
		$kept = null;
		Functions\when( 'wp_update_post' )->alias(
			static function ( array $postarr ) use ( &$kept ): int {
				$kept = Topic_Screen::keep_label( array( 'post_title' => '' ), $postarr )['post_title'];
				return 40;
			}
		);

		Store::save( 'fh_topic', 40, array( 'post_title' => '' ), array(), array() );

		$this->assertSame( '', $kept );
	}

	/**
	 * A kept label refuses the screen's fields too, and says why, with the label the
	 * topic still has; WordPress's save with a stale nonce says both.
	 */
	public function test_a_kept_label_refuses_the_fields(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\expect( 'update_post_meta' )->never();
		$kept = null;
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, mixed $value ) use ( &$kept ): bool {
				$kept = $value;
				return true;
			}
		);
		$_POST = array(
			'firehouse_topic_nonce' => 'good',
			'firehouse'             => self::input( array( 'description' => 'Typed' ) ),
		);

		Topic_Screen::keep_label( array( 'post_title' => '' ), array( 'ID' => 40 ) );
		Topic_Screen::save( 40, self::post() );

		$this->assertSame( array( Topic_Screen::LABEL => 'A topic needs a label, so it’s still “Observations & Monitoring”.' ), $kept['errors'] );
		$this->assertSame( 'Typed', $kept['values']['description'] );

		// The label's problem comes first, as the label does on the screen; one that
		// isn't UTF-8 says so.
		$_POST['firehouse']['short'] = '';
		Topic_Screen::keep_label( array( 'post_title' => "Fore\xC3(cast" ), array( 'ID' => 40 ) );
		Topic_Screen::save( 40, self::post() );
		$this->assertSame(
			array(
				Topic_Screen::LABEL => 'The label had characters that can’t be stored, so it’s still “Observations & Monitoring”.',
				'short'             => 'A topic needs a short label.',
			),
			$kept['errors']
		);

		Functions\when( 'wp_verify_nonce' )->justReturn( false );
		$_POST['action'] = 'editpost';
		Topic_Screen::keep_label( array( 'post_title' => '' ), array( 'ID' => 40 ) );
		Topic_Screen::save( 40, self::post() );
		$this->assertSame( array( Topic_Screen::FORM, Topic_Screen::LABEL ), array_keys( $kept['errors'] ) );
	}

	/**
	 * A kept label refuses the save it came from, not a later save of the same topic in
	 * the same process, and a good label clears it.
	 */
	public function test_a_kept_label_is_for_its_own_save_only(): void {
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'delete_post_meta' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\expect( 'set_transient' )->once()->andReturn( true );
		Functions\expect( 'update_post_meta' )->twice()->andReturn( true );
		$_POST = array(
			'firehouse_topic_nonce' => 'good',
			'firehouse'             => self::input( array() ),
		);

		Topic_Screen::keep_label( array( 'post_title' => '' ), array( 'ID' => 40 ) );
		Topic_Screen::save( 40, self::post() );
		Topic_Screen::save( 40, self::post() );

		Topic_Screen::keep_label( array( 'post_title' => '' ), array( 'ID' => 40 ) );
		Topic_Screen::keep_label( array( 'post_title' => 'Good' ), array( 'ID' => 40 ) );
		Topic_Screen::save( 40, self::post() );
	}

	/**
	 * The form shows each stored value exactly, what the area covers one item a line,
	 * each control labelled and described, with the topic's own nonce.
	 */
	public function test_the_form_shows_each_value_exactly_as_stored(): void {
		$nonce = null;
		Functions\when( 'wp_nonce_field' )->alias(
			static function ( mixed ...$args ) use ( &$nonce ): string {
				$nonce = $args;
				return '';
			}
		);
		ob_start();
		Topic_Screen::editorial_box( self::post() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="Obs &amp; &lt;b&gt;more&lt;/b&gt;"', $html );
		$this->assertStringContainsString( ">\nR&amp;amp;D description</textarea>", $html );
		$this->assertStringContainsString( ">\nNetworks\nSensors &amp; &lt;i&gt;feeds&lt;/i&gt;</textarea>", $html );
		foreach ( Topic_Screen::FIELDS as $name ) {
			$this->assertStringContainsString( '<label for="fh-topic-' . $name . '">', $html );
			$this->assertStringContainsString( 'id="fh-topic-' . $name . '" name="firehouse[' . $name . ']"', $html );
			$this->assertStringContainsString( 'aria-describedby="fh-topic-' . $name . '-description"', $html );
		}
		$this->assertStringNotContainsString( 'aria-invalid', $html );
		$this->assertSame( array( 'firehouse_topic_fields_40', 'firehouse_topic_nonce', false ), $nonce );
	}

	/**
	 * A refused save comes back once, with what was typed, each problem beside its
	 * field and at the top, the label's linked to WordPress's title box and shown
	 * under it.
	 */
	public function test_a_refused_save_comes_back_marked(): void {
		self::refuse(
			array(
				Topic_Screen::LABEL => 'A topic needs a label.',
				'short'             => 'A topic needs a short label.',
			),
			array( 'description' => 'Typed <i>text</i>' )
		);

		ob_start();
		Topic_Screen::notice( self::post() );
		Topic_Screen::label_error( self::post() );
		Topic_Screen::editorial_box( self::post() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<div id="firehouse-topic-errors" class="notice notice-error inline fh-edit-errors" role="group" tabindex="-1" autofocus aria-labelledby="firehouse-topic-errors-heading"><h2 id="firehouse-topic-errors-heading">Topic fields not saved: 2 problems</h2><p>Fix what’s marked, then update the topic again.</p>', $html );
		$this->assertStringContainsString( '<li><a href="#title">Label</a>: A topic needs a label.</li>', $html );
		$this->assertStringContainsString( '<li><a href="#fh-topic-short">Short label</a>: A topic needs a short label.</li>', $html );
		$this->assertStringContainsString( '<div class="fh-edit-screen"><p class="fh-edit-field__error" id="fh-topic-label-error"><strong>Error:</strong> A topic needs a label.</p></div>', $html );
		$this->assertStringContainsString( '<label for="fh-topic-short">Short label (required)</label><input type="text" id="fh-topic-short" name="firehouse[short]" value="" aria-required="true" aria-invalid="true" aria-describedby="fh-topic-short-error fh-topic-short-description"', $html );
		$this->assertStringContainsString( ">\nTyped &lt;i&gt;text&lt;/i&gt;</textarea>", $html );
		$this->assertNotFalse( has_filter( 'postbox_classes_fh_topic_firehouse-editorial', array( Topic_Screen::class, 'open_box' ) ) );
		$this->assertNotFalse( has_filter( 'admin_title', array( Topic_Screen::class, 'title' ) ) );
		$this->assertSame( 'Topic fields not saved: Edit topic', Topic_Screen::title( 'Edit topic' ) );
	}

	/**
	 * When the label was saved, the refusal says so.
	 */
	public function test_a_refusal_says_the_label_was_saved(): void {
		self::refuse( array( 'short' => 'A topic needs a short label.' ) );

		ob_start();
		Topic_Screen::notice( self::post() );
		Topic_Screen::label_error( self::post() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<p>Fix what’s marked, then update the topic again. The topic’s label was saved.</p>', $html );
		$this->assertStringNotContainsString( 'fh-topic-label-error', $html );
	}

	/**
	 * When only the label was refused, it's back as it was, so the summary asks only
	 * for the other changes to be saved again, and doesn't say the label was saved.
	 */
	public function test_a_label_only_refusal_asks_to_save_again(): void {
		self::refuse( array( Topic_Screen::LABEL => 'A topic needs a label.' ) );

		ob_start();
		Topic_Screen::notice( self::post() );
		$this->assertStringContainsString( '<p>Your other changes are below as you typed them: update the topic again to save them.</p>', (string) ob_get_clean() );
	}

	/**
	 * The title box is named "Label" on a topic's screen only.
	 */
	public function test_the_title_box_is_named_label(): void {
		$this->assertSame( 'Label', Topic_Screen::title_prompt( 'Add title', self::post() ) );
		$this->assertSame( 'Add title', Topic_Screen::title_prompt( 'Add title', $this->posts[11] ) );
	}

	/**
	 * Each screen shows only its own refusal: a project's never appears on a topic's
	 * screen, or a topic's on a project's.
	 */
	public function test_each_screen_shows_only_its_own_refusal(): void {
		self::refuse( array( 'short' => 'A topic needs a short label.' ) );
		$_GET = array( 'post' => '11' );
		Project_Screen::load();
		Topic_Screen::load();

		ob_start();
		Project_Screen::notice( $this->posts[11] );
		Topic_Screen::notice( $this->posts[11] );
		Topic_Screen::notice( self::post() );
		Topic_Screen::label_error( self::post() );
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * The synthesis is shown as stored, read-only: each need with its count and its
	 * projects by title, linked for an editor, marked when visitors can't see one or
	 * it's gone, and a note where no one has reviewed it.
	 */
	public function test_the_synthesis_is_shown_read_only(): void {
		Functions\when( 'current_user_can' )->alias( static fn( string $cap, int $id ): bool => 11 === $id );
		Functions\when( 'get_edit_post_link' )->alias( static fn( int $id ): string => 'https://example.org/wp-admin/post.php?post=' . $id . '&action=edit' );
		ob_start();
		Topic_Screen::synthesis_box( self::post() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<dt>Reviewed by</dt><dd><span class="fh-edit-note">No one yet. The topic page marks the needs “Draft, pending team review”.</span></dd>', $html );
		$this->assertStringContainsString( '<dt>Last updated</dt><dd><span class="fh-edit-note">(Not recorded)</span></dd>', $html );
		$this->assertStringContainsString( '<dt>Projects it read</dt><dd>8</dd>', $html );
		$this->assertStringContainsString( '<dt>Made with</dt><dd>NotebookLM</dd>', $html );
		$this->assertStringContainsString( '<ol><li><div>Longer-lead &lt;outlooks&gt;</div><div>Project count: 3</div><ul>', $html );
		$this->assertStringContainsString( '<li><a href="https://example.org/wp-admin/post.php?post=11&action=edit">Smoke &amp; &lt;b&gt;fire&lt;/b&gt; study</a></li>', $html );
		$this->assertStringContainsString( '<li>A draft study <span class="fh-edit-note">(not on the site)</span></li>', $html );
		$this->assertStringContainsString( '<li><span class="fh-edit-note">A project that’s gone (ID 99)</span></li>', $html );
		$this->assertStringContainsString( '<div>Counted by its projects</div><div>Project count: 1</div>', $html );
		$this->assertStringNotContainsString( '<input', $html );
		$this->assertStringNotContainsString( '<textarea', $html );
	}

	/**
	 * A reviewed synthesis names its reviewer, and none says so.
	 */
	public function test_a_reviewed_synthesis_names_its_reviewer(): void {
		$this->stored[40] = array(
			'fh_reviewedBy' => 'Stephanie & Emily',
			'fh_updatedAt'  => '2026-11-03',
		);
		ob_start();
		Topic_Screen::synthesis_box( self::post() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<dt>Reviewed by</dt><dd>Stephanie &amp; Emily</dd>', $html );
		$this->assertStringContainsString( '<dt>Last updated</dt><dd>November 3, 2026</dd>', $html );
		$this->assertStringContainsString( '<dt>Top needs</dt><dd><span class="fh-edit-note">(None)</span></dd>', $html );
	}

	/**
	 * WordPress's notices after a save speak of a topic, with a link to it, and leave
	 * other post types' alone.
	 */
	public function test_the_save_notices_speak_of_a_topic(): void {
		Functions\when( 'get_post' )->justReturn( self::post() );
		Functions\when( 'get_permalink' )->justReturn( 'https://example.org/firehouse/topics/observe/' );
		$messages = Topic_Screen::messages( array( 'post' => array( 5 => 'Post restored.' ) ) );

		$this->assertSame( 'Topic updated. <a href="https://example.org/firehouse/topics/observe/">View topic</a>', $messages['fh_topic'][1] );
		$this->assertSame( 'Topic updated.', $messages['fh_topic'][4] );
		$this->assertSame( 'Post restored.', $messages['fh_topic'][5] );
		$this->assertSame( array( 5 => 'Post restored.' ), $messages['post'] );
	}

	/**
	 * WordPress's heartbeat refreshes the topic screen's nonce for a topic only.
	 */
	public function test_the_nonce_is_refreshed_for_a_topic_only(): void {
		Functions\when( 'get_post_type' )->alias( static fn( int $id ): string => 40 === $id ? 'fh_topic' : 'fh_project' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_create_nonce' )->alias( static fn( string $action ): string => 'nonce-for-' . $action );
		$response = array( 'wp-refresh-post-nonces' => array( 'replace' => array( '_wpnonce' => 'core' ) ) );
		$asked    = static fn( mixed $id ): array => array( 'wp-refresh-post-nonces' => array( 'post_id' => $id ) );

		$refreshed = Topic_Screen::refresh_nonce( Project_Screen::refresh_nonce( $response, $asked( '40' ) ), $asked( '40' ) );
		$this->assertSame(
			array(
				'_wpnonce'              => 'core',
				'firehouse_topic_nonce' => 'nonce-for-firehouse_topic_fields_40',
			),
			$refreshed['wp-refresh-post-nonces']['replace']
		);
		$this->assertSame( $response, Topic_Screen::refresh_nonce( $response, $asked( '11' ) ), 'a project' );
	}

	/**
	 * WordPress's title box shows a topic's label as text, once, and anything else's
	 * title as it was.
	 */
	public function test_the_title_box_shows_the_label_as_text(): void {
		Functions\when( 'get_post_type' )->alias( static fn( int $id ): string => 40 === $id ? 'fh_topic' : 'page' );

		$this->assertSame( 'Fire & <b>Smoke</b>', Topic_Screen::edit_label( 'Fire &amp; &lt;b&gt;Smoke&lt;/b&gt;', 40 ) );
		$this->assertSame( 'Fire &amp; Smoke', Topic_Screen::edit_label( 'Fire &amp;amp; Smoke', '40' ), 'Read back once only.' );
		$this->assertSame( 'Fire &amp; Smoke', Topic_Screen::edit_label( 'Fire &amp; Smoke', 12 ), 'Not a topic.' );
		$this->assertNull( Topic_Screen::edit_label( null, 40 ) );
	}

	/**
	 * The screen hooks its own callbacks, the label's rule late.
	 */
	public function test_the_screen_hooks_its_own_callbacks(): void {
		Topic_Screen::hook();

		$this->assertNotFalse( has_action( 'save_post_fh_topic', array( Topic_Screen::class, 'save' ) ) );
		$this->assertNotFalse( has_action( 'add_meta_boxes_fh_topic', array( Topic_Screen::class, 'boxes' ) ) );
		$this->assertNotFalse( has_action( 'load-post.php', array( Topic_Screen::class, 'load' ) ) );
		$this->assertSame( PHP_INT_MAX - 10, has_filter( 'wp_insert_post_data', array( Topic_Screen::class, 'keep_label' ) ) );
		$this->assertNotFalse( has_filter( 'title_edit_pre', array( Topic_Screen::class, 'edit_label' ) ) );
		$this->assertNotFalse( has_filter( 'enter_title_here', array( Topic_Screen::class, 'title_prompt' ) ) );
		$this->assertNotFalse( has_action( 'edit_form_before_permalink', array( Topic_Screen::class, 'label_error' ) ) );
		$this->assertNotFalse( has_filter( 'post_row_actions', array( Topic_Screen::class, 'row_actions' ) ) );
		$this->assertNotFalse( has_filter( 'bulk_actions-edit-fh_topic', array( Topic_Screen::class, 'bulk_actions' ) ) );
		$this->assertNotFalse( has_filter( 'manage_fh_topic_posts_columns', array( Topic_Screen::class, 'columns' ) ) );
		$this->assertFalse( has_action( 'save_post_fh_project', array( Topic_Screen::class, 'save' ) ) );
	}

	/**
	 * The topics list has no Quick Edit, in a row or as the bulk Edit, and so no
	 * checkboxes; every other list keeps them.
	 */
	public function test_the_topics_list_has_no_quick_edit(): void {
		$actions = array(
			'edit'                 => 'Edit',
			'inline hide-if-no-js' => 'Quick&nbsp;Edit',
			'view'                 => 'View',
		);

		$this->assertSame( array( 'edit', 'view' ), array_keys( Topic_Screen::row_actions( $actions, $this->posts[40] ) ) );
		$this->assertSame( $actions, Topic_Screen::row_actions( $actions, $this->posts[11] ), 'A project.' );
		$this->assertSame( 'not a list', Topic_Screen::row_actions( 'not a list', $this->posts[40] ), 'Another plugin\'s filter.' );

		$bulk = array(
			'edit'  => 'Edit',
			'trash' => 'Move to Trash',
		);
		$this->assertSame( array( 'trash' => 'Move to Trash' ), Topic_Screen::bulk_actions( $bulk ) );
		$this->assertNull( Topic_Screen::bulk_actions( null ) );

		// No checkboxes, with nothing left for them to choose rows for.
		$this->assertSame(
			array( 'title' => 'Label' ),
			Topic_Screen::columns(
				array(
					'cb'    => '<input type="checkbox" />',
					'title' => 'Label',
				)
			)
		);
	}

	/**
	 * A screen that hasn't named its post type, kind and nonce can't be hooked, so it
	 * fails when the plugin loads, not quietly on a save.
	 */
	public function test_a_screen_without_its_names_cant_be_hooked(): void {
		$this->expectException( \LogicException::class );
		Edit_Screen::hook();
	}
}
