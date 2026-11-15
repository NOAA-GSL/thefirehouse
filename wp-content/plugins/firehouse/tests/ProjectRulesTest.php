<?php
/**
 * Tests for what a save may change on a project.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Project_Rules;

/**
 * No project without IRB approval is published, a published project keeps its
 * address, and the post's title is the researcher's, by WordPress's editing routes.
 */
final class ProjectRulesTest extends WordPressTestCase {

	/**
	 * Each post's meta, by post and key.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $meta = array();

	/**
	 * The posts, by ID.
	 *
	 * @var array<int, \WP_Post>
	 */
	private array $posts = array();

	/**
	 * Refusals kept, by transient.
	 *
	 * @var array<string, mixed>
	 */
	private array $kept = array();

	/**
	 * Stubs what the rules read: an approved published project (41), a draft that has
	 * never been published and has no approval (42), and a page (43).
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->posts = array(
			41 => self::post( 41, 'publish', 'smoke-study', '2024-05-01 10:00:00' ),
			42 => self::post( 42, 'draft', 'draft-study', '0000-00-00 00:00:00' ),
			43 => new \WP_Post(
				array(
					'ID'        => 43,
					'post_type' => 'page',
				)
			),
		);
		$this->meta  = array(
			41 => array(
				'fh_title'             => 'Smoke & <b>fire</b>',
				'fh_irbApproved'       => '1',
				'_firehouse_published' => '1',
			),
			42 => array(
				'fh_title'       => 'A draft',
				'fh_irbApproved' => '',
			),
		);
		$this->kept  = array();
		$_POST       = array();
		$_GET        = array();
		Functions\when( 'get_post' )->alias( fn( mixed $id ): ?\WP_Post => $this->posts[ (int) $id ] ?? null );
		Functions\when( 'get_post_meta' )->alias( fn( int $id, string $key ): mixed => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'update_post_meta' )->alias(
			function ( int $id, string $key, mixed $value ): bool {
				$this->meta[ $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_slash' )->alias( static fn( string $value ): string => addslashes( $value ) );
		Functions\when( 'sanitize_title' )->alias( static fn( string $value ): string => strtolower( trim( $value ) ) );
		Functions\when( 'wp_unique_post_slug' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 6 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'get_transient' )->alias( fn( string $key ): mixed => $this->kept[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $key, mixed $value ): bool {
				$this->kept[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $key ): bool {
				unset( $this->kept[ $key ] );
				return true;
			}
		);
	}

	/**
	 * Clears the request.
	 */
	protected function tearDown(): void {
		$_POST = array();
		$_GET  = array();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * A project post.
	 *
	 * @param int    $id     Its ID.
	 * @param string $status Its status.
	 * @param string $slug   Its slug.
	 * @param string $gmt    Its date in GMT.
	 */
	private static function post( int $id, string $status, string $slug, string $gmt ): \WP_Post {
		return new \WP_Post(
			array(
				'ID'            => $id,
				'post_type'     => 'fh_project',
				'post_status'   => $status,
				'post_name'     => $slug,
				'post_title'    => 'Old label',
				'post_date'     => '2024-05-01 04:00:00',
				'post_date_gmt' => $gmt,
			)
		);
	}

	/**
	 * A save of post $id asking for $changes, as WordPress hands it to the filter.
	 *
	 * @param int                  $id      The post.
	 * @param array<string, mixed> $changes What WordPress will save.
	 * @param array<string, mixed> $postarr What the save asked for, besides the ID.
	 * @return array<string, mixed>
	 */
	private function save( int $id, array $changes, array $postarr = array() ): array {
		$post = $this->posts[ $id ];
		$data = $changes + array(
			'post_title'    => 'Whatever the request said',
			'post_status'   => $post->post_status,
			'post_name'     => $post->post_name,
			'post_date'     => '2026-11-15 09:00:00',
			'post_date_gmt' => '2026-11-15 16:00:00',
		);
		return Project_Rules::enforce( $data, array( 'ID' => $id ) + $postarr );
	}

	/**
	 * The refusals kept for the editor.
	 *
	 * @return list<string>
	 */
	private function kinds(): array {
		return array_values( array_map( static fn( array $note ): string => $note['post'] . ':' . $note['kind'], $this->kept['firehouse_project_notes_6'] ?? array() ) );
	}

	/**
	 * The post's title is the researcher's, escaped, trimmed and slashed as WordPress
	 * saves it, whatever the save asked for.
	 */
	public function test_the_title_is_the_researchers(): void {
		$saved = $this->save( 41, array() );
		$this->assertSame( 'Smoke &amp; &lt;b&gt;fire&lt;/b&gt;', $saved['post_title'] );
		$this->assertSame( array(), $this->kinds() );

		$this->meta[41]['fh_title'] = ' Back\\slash ';
		$this->assertSame( 'Back\\\\slash', $this->save( 41, array() )['post_title'], 'Trimmed, and slashed, as WordPress unslashes what it stores.' );
	}

	/**
	 * On a table that can't hold an emoji, the title's emoji are encoded, as WordPress
	 * encodes them before the filter.
	 */
	public function test_an_emoji_is_encoded_where_the_table_needs_it(): void {
		Functions\when( 'wp_encode_emoji' )->alias( static fn( string $text ): string => str_replace( '🔥', '&#x1f525;', $text ) );
		$this->meta[41]['fh_title'] = 'Fire 🔥';
		$GLOBALS['wpdb']            = new class() { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the database, stood in for; tearDown() removes it.
			/**
			 * The posts table.
			 *
			 * @var string
			 */
			public string $posts = 'wp_posts';

			/**
			 * The column's character set.
			 *
			 * @var string
			 */
			public string $charset = 'utf8mb3';

			/**
			 * A column's character set.
			 *
			 * @param string $table  The table.
			 * @param string $column The column.
			 */
			public function get_col_charset( string $table, string $column ): string {
				return $this->charset;
			}
		};
		$this->assertSame( 'Fire &#x1f525;', $this->save( 41, array() )['post_title'] );

		$GLOBALS['wpdb']->charset = 'utf8mb4';
		$this->assertSame( 'Fire 🔥', $this->save( 41, array() )['post_title'] );
	}

	/**
	 * Publishing, scheduling or making private a draft without IRB approval keeps it
	 * a draft, with its dates and the slug it asked for, and says so.
	 *
	 * @dataProvider published_statuses
	 *
	 * @param string $status publish, future or private.
	 */
	public function test_a_project_without_approval_is_not_published( string $status ): void {
		$saved = $this->save( 42, array( 'post_status' => $status, 'post_name' => 'draft-study-2' ), array( 'post_name' => 'Draft-Study' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- One save's fields.
		$this->assertSame( 'draft', $saved['post_status'] );
		$this->assertSame( '2024-05-01 04:00:00', $saved['post_date'] );
		$this->assertSame( '0000-00-00 00:00:00', $saved['post_date_gmt'] );
		$this->assertSame( 'draft-study', $saved['post_name'], 'The slug it asked for, not the one WordPress made unique for publishing.' );
		$this->assertSame( array( '42:irb' ), $this->kinds() );
	}

	/**
	 * The statuses that publish a project.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function published_statuses(): array {
		return array(
			'publish' => array( 'publish' ),
			'future'  => array( 'future' ),
			'private' => array( 'private' ),
		);
	}

	/**
	 * A pending project stays pending; one asking to be published out of the trash
	 * becomes a draft, keeping the slug WordPress restored.
	 */
	public function test_a_refusal_keeps_pending_and_leaves_the_trash_as_a_draft(): void {
		$this->posts[42]->post_status = 'pending';
		$this->assertSame( 'pending', $this->save( 42, array( 'post_status' => 'publish' ) )['post_status'] );

		$this->posts[42]->post_status           = 'trash';
		$this->posts[42]->post_name             = 'draft-study__trashed';
		$this->meta[42]['_firehouse_published'] = '1';
		$saved                                  = $this->save( 42, array( 'post_status' => 'publish', 'post_name' => 'draft-study' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- One save's fields.
		$this->assertSame( 'draft', $saved['post_status'] );
		$this->assertSame( 'draft-study', $saved['post_name'] );
	}

	/**
	 * A project that was public without approval becomes a draft, and the editor is
	 * told it's now one; one with approval is published as asked.
	 */
	public function test_public_without_approval_becomes_a_draft_and_with_it_stays(): void {
		$this->meta[41]['fh_irbApproved'] = '';
		$saved                            = $this->save( 41, array( 'post_name' => 'smoke-study-2' ), array( 'post_name' => 'something-else' ) );
		$this->assertSame( 'draft', $saved['post_status'] );
		$this->assertSame( 'smoke-study', $saved['post_name'], 'Its fixed address.' );
		$this->assertSame( array( '41:unpublished' ), $this->kinds() );

		$this->meta[41]['fh_irbApproved'] = '1';
		$this->kept                       = array();
		$saved                            = $this->save( 41, array() );
		$this->assertSame( 'publish', $saved['post_status'] );
		$this->assertSame( '2026-11-15 16:00:00', $saved['post_date_gmt'] );
		$this->assertSame( array(), $this->kinds() );
	}

	/**
	 * A draft without approval can still be saved as a draft or pending.
	 */
	public function test_other_statuses_are_left_alone(): void {
		foreach ( array( 'draft', 'pending' ) as $status ) {
			$this->assertSame( $status, $this->save( 42, array( 'post_status' => $status ) )['post_status'] );
		}
		$this->assertSame( array(), $this->kinds() );
	}

	/**
	 * A published project's slug stays, and the editor is told; a draft that has never
	 * been published can change its slug.
	 */
	public function test_a_published_projects_slug_stays(): void {
		$this->assertSame( 'smoke-study', $this->save( 41, array( 'post_name' => 'new-name' ) )['post_name'] );
		$this->assertSame( array( '41:slug' ), $this->kinds() );

		$this->assertSame( 'renamed-draft', $this->save( 42, array( 'post_name' => 'renamed-draft' ) )['post_name'] );
	}

	/**
	 * Once published, a project's slug stays even as a draft again; a draft with only
	 * a date set by an editor is not locked.
	 */
	public function test_the_lock_follows_publication_not_the_date(): void {
		$this->posts[41]->post_status = 'draft';
		$this->assertSame( 'smoke-study', $this->save( 41, array( 'post_name' => 'new-name' ) )['post_name'] );

		$this->posts[42]->post_date_gmt = '2026-11-20 10:00:00';
		$this->assertFalse( Project_Rules::locked( $this->posts[42] ) );
		$this->assertSame( 'renamed-draft', $this->save( 42, array( 'post_name' => 'renamed-draft' ) )['post_name'] );
	}

	/**
	 * If another project has taken a locked project's address, WordPress's new one
	 * stands, rather than two projects sharing it, and the editor is told.
	 */
	public function test_a_taken_address_isnt_shared(): void {
		Functions\when( 'wp_unique_post_slug' )->justReturn( 'smoke-study-2' );
		$this->assertSame( 'smoke-study-2', $this->save( 41, array( 'post_name' => 'smoke-study-2' ) )['post_name'] );
		$this->assertSame( array( '41:taken' ), $this->kinds() );
	}

	/**
	 * Trashing and restoring rename a slug for WordPress's own reasons, and are left
	 * alone.
	 */
	public function test_trashing_and_restoring_are_left_alone(): void {
		$this->assertSame( 'smoke-study__trashed', $this->save( 41, array( 'post_status' => 'trash', 'post_name' => 'smoke-study__trashed' ) )['post_name'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- One save's two fields.

		$this->posts[41]->post_status = 'trash';
		$this->posts[41]->post_name   = 'smoke-study__trashed';
		$this->assertSame( 'smoke-study', $this->save( 41, array( 'post_status' => 'draft', 'post_name' => 'smoke-study' ) )['post_name'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- As above.
		$this->assertSame( array(), $this->kinds() );
	}

	/**
	 * Anything but a project, and a new post, pass through as they are.
	 */
	public function test_other_posts_pass_through(): void {
		$data = array(
			'post_title'  => 'A page',
			'post_status' => 'publish',
			'post_name'   => 'a-page',
		);
		$this->assertSame( $data, Project_Rules::enforce( $data, array( 'ID' => 43 ) ) );
		$this->assertSame( $data, Project_Rules::enforce( $data, array() ) );
	}

	/**
	 * Which projects' addresses are fixed: by status, or by having been published.
	 */
	public function test_which_addresses_are_fixed(): void {
		foreach ( array( 'publish', 'future', 'private' ) as $status ) {
			$this->assertTrue( Project_Rules::locked( self::post( 7, $status, 'a', '0000-00-00 00:00:00' ) ), $status );
		}
		$this->assertFalse( Project_Rules::locked( self::post( 7, 'draft', 'a', '2024-05-01 10:00:00' ) ), 'A date alone' );
		$this->assertFalse( Project_Rules::locked( self::post( 7, 'pending', 'a', '0000-00-00 00:00:00' ) ) );
		$this->meta[7] = array( '_firehouse_published' => '1' );
		$this->assertTrue( Project_Rules::locked( self::post( 7, 'draft', 'a', '0000-00-00 00:00:00' ) ), 'Published once' );
	}

	/**
	 * A project is marked as published the first time it enters or leaves a published
	 * status, by any route; nothing else is marked.
	 */
	public function test_publication_is_recorded(): void {
		Project_Rules::mark_published( 'draft', 'pending', $this->posts[42] );
		$this->assertArrayNotHasKey( '_firehouse_published', $this->meta[42] );
		Project_Rules::mark_published( 'private', 'draft', $this->posts[42] );
		$this->assertSame( '1', $this->meta[42]['_firehouse_published'] );

		unset( $this->meta[41]['_firehouse_published'] );
		Project_Rules::mark_published( 'draft', 'publish', $this->posts[41] );
		$this->assertSame( '1', $this->meta[41]['_firehouse_published'], 'Leaving counts, for one published before this was recorded.' );

		Functions\expect( 'update_post_meta' )->never();
		Project_Rules::mark_published( 'publish', 'draft', $this->posts[43] );
		Project_Rules::mark_published( 'publish', 'draft', $this->posts[41] );
	}

	/**
	 * Quick Edit's publish of a project without approval is refused with the reason,
	 * before WordPress saves anything; anything else goes on to WordPress.
	 */
	public function test_quick_edit_refuses_with_the_reason(): void {
		Functions\when( 'sanitize_key' )->alias( static fn( string $key ): string => strtolower( $key ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( mixed $value ): int => abs( (int) $value ) );
		$died = array();
		Functions\when( 'wp_die' )->alias(
			static function ( string $message ) use ( &$died ): void {
				$died[] = $message;
			}
		);
		foreach ( array(
			array( '42', 'publish', '' ),
			array( '42', 'draft', 'private' ),
			array( '42', 'draft', '' ),
			array( '41', 'publish', '' ),
			array( '43', 'publish', '' ),
		) as list( $id, $status, $private ) ) {
			$_POST = array(
				'post_ID'      => $id,
				'_status'      => $status,
				'keep_private' => $private,
			);
			Project_Rules::quick_edit();
		}
		$this->assertCount( 2, $died );
		$this->assertStringContainsString( 'Approval comes from the survey record, not this screen.', $died[0] );

		Functions\when( 'current_user_can' )->justReturn( false );
		$_POST = array(
			'post_ID' => '42',
			'_status' => 'publish',
		);
		Project_Rules::quick_edit();
		$this->assertCount( 2, $died, 'Not for a project the editor may not edit.' );
	}

	/**
	 * On the projects list each refusal is said once, naming the project; not on other
	 * screens, nor for a project the editor may not edit.
	 */
	public function test_refusals_are_said_once_on_the_list(): void {
		$this->save( 41, array( 'post_name' => 'new-name' ) );
		$this->save( 42, array( 'post_status' => 'publish' ) );

		foreach ( array( new \WP_Screen( 'dashboard', '' ), new \WP_Screen( 'edit-tags', 'fh_project' ), new \WP_Screen( 'post', 'fh_project' ) ) as $screen ) {
			Functions\when( 'get_current_screen' )->justReturn( $screen );
			ob_start();
			Project_Rules::list_notices();
			$this->assertSame( '', ob_get_clean(), $screen->base );
		}
		$this->assertCount( 2, $this->kept['firehouse_project_notes_6'] );

		Functions\when( 'get_current_screen' )->justReturn( new \WP_Screen( 'edit', 'fh_project' ) );
		ob_start();
		Project_Rules::list_notices();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '<div class="notice notice-warning"><p>“Smoke &amp; &lt;b&gt;fire&lt;/b&gt;” keeps its address, /firehouse/projects/smoke-study/. It can’t change now that the project has been published', $html );
		$this->assertStringContainsString( '“A draft” wasn’t published. Its survey record has no IRB approval or exemption', $html );
		$this->assertArrayNotHasKey( 'firehouse_project_notes_6', $this->kept );

		ob_start();
		Project_Rules::list_notices();
		$this->assertSame( '', ob_get_clean(), 'Said once.' );
	}

	/**
	 * Each kind of refusal has its own words.
	 */
	public function test_each_refusal_has_its_words(): void {
		Functions\when( 'get_current_screen' )->justReturn( new \WP_Screen( 'edit', 'fh_project' ) );
		$this->meta[41]['fh_irbApproved'] = '';
		$this->save( 41, array() );
		$this->meta[41]['fh_irbApproved'] = '1';
		Functions\when( 'wp_unique_post_slug' )->justReturn( 'smoke-study-2' );
		$this->save( 41, array( 'post_name' => 'smoke-study-2' ) );
		ob_start();
		Project_Rules::list_notices();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '” is now a draft. Its survey record has no IRB approval', $html );
		$this->assertStringContainsString( '” couldn’t keep its address: another project has it now.', $html );
	}

	/**
	 * A refusal for a project the editor may not edit isn't said.
	 */
	public function test_a_refusal_for_another_editors_project_isnt_said(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_current_screen' )->justReturn( new \WP_Screen( 'edit', 'fh_project' ) );
		$this->save( 42, array( 'post_status' => 'publish' ) );
		ob_start();
		Project_Rules::list_notices();
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * On a project's screen, refusals are said inside the form, after the editorial
	 * fields' summary, and a project without approval says why it can't be published
	 * at the top, where Tab meets it; other screens get nothing.
	 */
	public function test_the_project_screen_says_refusals_and_why(): void {
		$this->save( 41, array( 'post_name' => 'new-name' ) );
		ob_start();
		Project_Rules::form_notices( $this->posts[41] );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '<div class="notice notice-warning inline"><p>“Smoke', $html );
		$this->assertStringNotContainsString( 'can’t be published', $html );

		ob_start();
		Project_Rules::form_notices( $this->posts[42] );
		$this->assertStringContainsString( '<div class="notice notice-info inline"><p>This project can’t be published. Its survey record has no IRB approval or exemption, and visitors never see a project without it. Approval comes from the survey record, not this screen. You can still save it as a draft.</p></div>', (string) ob_get_clean() );

		$this->meta[41]['fh_irbApproved'] = '';
		ob_start();
		Project_Rules::form_notices( $this->posts[41] );
		$this->assertStringContainsString( 'Updating it makes it a draft.', (string) ob_get_clean() );

		ob_start();
		Project_Rules::form_notices( $this->posts[43] );
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * The Publish box says why a project can't be published, and what saving does,
	 * and shows a fixed address; the Slug box is removed to match.
	 */
	public function test_the_publish_box_says_why_and_shows_the_address(): void {
		ob_start();
		Project_Rules::publish_box( $this->posts[42] );
		$draft = (string) ob_get_clean();
		$this->assertStringContainsString( 'This project can’t be published.', $draft );
		$this->assertStringContainsString( 'You can still save it as a draft.', $draft );
		$this->assertStringNotContainsString( 'Address:', $draft );

		ob_start();
		Project_Rules::publish_box( $this->posts[41] );
		$published = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'can’t be published', $published );
		$this->assertStringContainsString( 'Address: <code>/firehouse/projects/smoke-study/</code><br>It can’t change now that the project has been published', $published );

		$this->meta[41]['fh_irbApproved'] = '';
		ob_start();
		Project_Rules::publish_box( $this->posts[41] );
		$this->assertStringContainsString( 'Updating it makes it a draft.', (string) ob_get_clean() );

		ob_start();
		Project_Rules::publish_box( $this->posts[43] );
		$this->assertSame( '', ob_get_clean() );

		Functions\expect( 'remove_meta_box' )->once()->with( 'slugdiv', 'fh_project', 'normal' );
		Project_Rules::boxes( $this->posts[41] );
		Project_Rules::boxes( $this->posts[42] );
	}

	/**
	 * The Publish button and the visibility and date settings are hidden only on the
	 * screen of a draft or pending project that can't be published; a public one keeps
	 * its Update button.
	 */
	public function test_what_would_publish_is_hidden_only_when_it_cant(): void {
		Functions\when( 'absint' )->alias( static fn( mixed $value ): int => abs( (int) $value ) );
		$styles = array();
		Functions\when( 'wp_add_inline_style' )->alias(
			static function ( string $handle, string $css ) use ( &$styles ): bool {
				$styles[] = $css;
				return true;
			}
		);
		$this->meta[41]['fh_irbApproved'] = '';
		foreach ( array( '41', '42', '43' ) as $id ) {
			$_GET = array( 'post' => $id );
			Project_Rules::load();
		}
		$this->assertSame( array( '#publishing-action,#submitdiv .misc-pub-visibility,#submitdiv .misc-pub-curtime{display:none}' ), $styles );
	}

	/**
	 * The projects list marks each project without approval.
	 */
	public function test_the_list_marks_projects_without_approval(): void {
		$this->assertSame( array( 'firehouse-no-irb' => 'No IRB approval' ), Project_Rules::states( array(), $this->posts[42] ) );
		$this->assertSame( array(), Project_Rules::states( array(), $this->posts[41] ) );
		$this->assertSame( array(), Project_Rules::states( array(), $this->posts[43] ) );
	}

	/**
	 * Bulk edit's counts speak of projects, and leave other post types' alone.
	 */
	public function test_bulk_edit_speaks_of_projects(): void {
		$messages = Project_Rules::bulk_messages( array( 'post' => array( 'updated' => 'Post' ) ), array( 'updated' => 2 ) );
		$this->assertSame( '%s projects updated.', $messages['fh_project']['updated'] );
		$this->assertSame( array( 'updated' => 'Post' ), $messages['post'] );
	}
}
