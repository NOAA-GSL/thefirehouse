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
 * address, and the post's title is the researcher's, by any route.
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
				'fh_title'       => 'Smoke & <b>fire</b>',
				'fh_irbApproved' => '1',
			),
			42 => array(
				'fh_title'       => 'A draft',
				'fh_irbApproved' => '',
			),
		);
		$this->kept  = array();
		Functions\when( 'get_post' )->alias( fn( mixed $id ): ?\WP_Post => $this->posts[ (int) $id ] ?? null );
		Functions\when( 'get_post_meta' )->alias( fn( int $id, string $key ): mixed => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'wp_slash' )->alias( static fn( string $value ): string => addslashes( $value ) );
		Functions\when( 'get_current_user_id' )->justReturn( 6 );
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
	 * A save of post $id asking for $changes.
	 *
	 * @param int                  $id      The post.
	 * @param array<string, mixed> $changes What the save asks for.
	 * @return array<string, mixed>
	 */
	private function save( int $id, array $changes ): array {
		$post = $this->posts[ $id ];
		$data = $changes + array(
			'post_title'    => 'Whatever the request said',
			'post_status'   => $post->post_status,
			'post_name'     => $post->post_name,
			'post_date'     => '2026-11-15 09:00:00',
			'post_date_gmt' => '2026-11-15 16:00:00',
		);
		return Project_Rules::enforce( $data, array( 'ID' => $id ) );
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
	 * The post's title is the researcher's, escaped and slashed as WordPress saves it,
	 * whatever the save asked for.
	 */
	public function test_the_title_is_the_researchers(): void {
		$saved = $this->save( 41, array() );
		$this->assertSame( 'Smoke &amp; &lt;b&gt;fire&lt;/b&gt;', $saved['post_title'] );
		$this->assertSame( array(), $this->kinds() );

		$this->meta[41]['fh_title'] = 'Back\\slash';
		$this->assertSame( 'Back\\\\slash', $this->save( 41, array() )['post_title'], 'Slashed, as WordPress unslashes what it stores.' );
	}

	/**
	 * Publishing or scheduling a project without IRB approval keeps the status it had,
	 * and its dates, and says so.
	 *
	 * @dataProvider public_statuses
	 *
	 * @param string $status publish or future.
	 */
	public function test_a_project_without_approval_is_not_made_public( string $status ): void {
		$saved = $this->save( 42, array( 'post_status' => $status ) );
		$this->assertSame( 'draft', $saved['post_status'] );
		$this->assertSame( '2024-05-01 04:00:00', $saved['post_date'] );
		$this->assertSame( '0000-00-00 00:00:00', $saved['post_date_gmt'] );
		$this->assertSame( array( '42:irb' ), $this->kinds() );
	}

	/**
	 * The statuses that make a project public.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function public_statuses(): array {
		return array(
			'publish' => array( 'publish' ),
			'future'  => array( 'future' ),
		);
	}

	/**
	 * A project that was public without approval goes back to a draft when saved as
	 * public again; one with approval is published as asked.
	 */
	public function test_public_without_approval_becomes_a_draft_and_with_it_stays(): void {
		$this->meta[41]['fh_irbApproved'] = '';
		$this->assertSame( 'draft', $this->save( 41, array() )['post_status'] );

		$this->meta[41]['fh_irbApproved'] = '1';
		$this->kept                       = array();
		$saved                            = $this->save( 41, array() );
		$this->assertSame( 'publish', $saved['post_status'] );
		$this->assertSame( '2026-11-15 16:00:00', $saved['post_date_gmt'] );
		$this->assertSame( array(), $this->kinds() );
	}

	/**
	 * A draft without approval can still be saved as a draft, pending or private.
	 */
	public function test_other_statuses_are_left_alone(): void {
		foreach ( array( 'draft', 'pending', 'private' ) as $status ) {
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
	 * Once published, a project's slug stays even as a draft again: WordPress keeps the
	 * date it was published.
	 */
	public function test_an_unpublished_projects_slug_stays(): void {
		$this->posts[41]->post_status = 'draft';
		$this->assertSame( 'smoke-study', $this->save( 41, array( 'post_name' => 'new-name' ) )['post_name'] );
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
	 * Which projects' addresses are fixed.
	 */
	public function test_which_addresses_are_fixed(): void {
		$this->assertTrue( Project_Rules::locked( self::post( 1, 'publish', 'a', '2024-05-01 10:00:00' ) ) );
		$this->assertTrue( Project_Rules::locked( self::post( 1, 'future', 'a', '2027-01-01 10:00:00' ) ) );
		$this->assertTrue( Project_Rules::locked( self::post( 1, 'private', 'a', '2024-05-01 10:00:00' ) ) );
		$this->assertTrue( Project_Rules::locked( self::post( 1, 'draft', 'a', '2024-05-01 10:00:00' ) ), 'published once' );
		$this->assertFalse( Project_Rules::locked( self::post( 1, 'draft', 'a', '0000-00-00 00:00:00' ) ) );
		$this->assertFalse( Project_Rules::locked( self::post( 1, 'pending', 'a', '0000-00-00 00:00:00' ) ) );
		// By status alone, however WordPress kept the date.
		$this->assertTrue( Project_Rules::locked( self::post( 1, 'private', 'a', '0000-00-00 00:00:00' ) ) );
		$this->assertTrue( Project_Rules::locked( self::post( 1, 'future', 'a', '0000-00-00 00:00:00' ) ) );
	}

	/**
	 * Each refusal is said once, on the projects list or a project's screen, naming the
	 * project; not elsewhere, and not for a project the editor may not edit.
	 */
	public function test_refusals_are_said_once_where_projects_are(): void {
		Functions\when( 'current_user_can' )->alias( static fn( string $cap, int $id ): bool => 42 !== $id || true );
		Functions\when( 'wp_specialchars_decode' )->alias( static fn( string $text ): string => html_entity_decode( $text, ENT_QUOTES ) );
		$this->posts[41]->post_title = 'Smoke &amp; fire';
		$this->save( 41, array( 'post_name' => 'new-name' ) );
		$this->save( 42, array( 'post_status' => 'publish' ) );

		foreach ( array( new \WP_Screen( 'dashboard', '' ), new \WP_Screen( 'edit-tags', 'fh_project' ) ) as $screen ) {
			Functions\when( 'get_current_screen' )->justReturn( $screen );
			ob_start();
			Project_Rules::notices();
			$this->assertSame( '', ob_get_clean(), $screen->base );
		}
		$this->assertCount( 2, $this->kept['firehouse_project_notes_6'] );

		Functions\when( 'get_current_screen' )->justReturn( new \WP_Screen( 'edit', 'fh_project' ) );
		ob_start();
		Project_Rules::notices();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '“Smoke &amp; &lt;b&gt;fire&lt;/b&gt;” keeps its address, /firehouse/projects/smoke-study/.', $html );
		$this->assertStringContainsString( '“A draft” wasn’t published. Its survey record has no IRB approval or exemption', $html );
		$this->assertArrayNotHasKey( 'firehouse_project_notes_6', $this->kept );

		ob_start();
		Project_Rules::notices();
		$this->assertSame( '', ob_get_clean(), 'Said once.' );
	}

	/**
	 * A refusal for a project the editor may not edit isn't said.
	 */
	public function test_a_refusal_for_another_editors_project_isnt_said(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_specialchars_decode' )->returnArg();
		Functions\when( 'get_current_screen' )->justReturn( new \WP_Screen( 'post', 'fh_project' ) );
		$this->save( 42, array( 'post_status' => 'publish' ) );
		ob_start();
		Project_Rules::notices();
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * The Publish box says why a project can't be published, and shows a fixed
	 * address; the Publish button is hidden, and the Slug box removed, to match.
	 */
	public function test_the_publish_box_says_why_and_shows_the_address(): void {
		ob_start();
		Project_Rules::publish_box( $this->posts[42] );
		$draft = (string) ob_get_clean();
		$this->assertStringContainsString( 'This project can’t be published.', $draft );
		$this->assertStringNotContainsString( 'Address:', $draft );

		ob_start();
		Project_Rules::publish_box( $this->posts[41] );
		$published = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'can’t be published', $published );
		$this->assertStringContainsString( 'Address: <code>/firehouse/projects/smoke-study/</code>', $published );

		ob_start();
		Project_Rules::publish_box( $this->posts[43] );
		$this->assertSame( '', ob_get_clean() );

		// A public project without approval keeps its Update button: a save makes it a
		// draft, and says why.
		$this->meta[41]['fh_irbApproved'] = '';
		ob_start();
		Project_Rules::publish_box( $this->posts[41] );
		$this->assertStringNotContainsString( 'can’t be published', (string) ob_get_clean() );

		Functions\expect( 'remove_meta_box' )->once()->with( 'slugdiv', 'fh_project', 'normal' );
		Project_Rules::boxes( $this->posts[41] );
		Project_Rules::boxes( $this->posts[42] );
	}

	/**
	 * The Publish button is hidden only on the screen of a project that can't be
	 * published.
	 */
	public function test_the_publish_button_is_hidden_only_when_it_cant_publish(): void {
		Functions\when( 'absint' )->alias( static fn( mixed $value ): int => abs( (int) $value ) );
		$styles = array();
		Functions\when( 'wp_add_inline_style' )->alias(
			static function ( string $handle, string $css ) use ( &$styles ): bool {
				$styles[] = $css;
				return true;
			}
		);
		foreach ( array( '41', '42', '43' ) as $id ) {
			$_GET = array( 'post' => $id );
			Project_Rules::load();
		}
		$_GET = array();
		$this->assertSame( array( '#publishing-action{display:none}' ), $styles );
	}
}
