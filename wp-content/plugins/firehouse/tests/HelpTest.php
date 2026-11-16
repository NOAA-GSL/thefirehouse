<?php
/**
 * The guide in the dashboard: which screen shows which page, who reads which pages,
 * and the addresses filled into a page's HTML.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Help;

/**
 * Tests Help against the help.json the plugin ships.
 */
final class HelpTest extends WordPressTestCase {

	/**
	 * Reads help.json afresh for each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Help::reset();
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://example.org/wp-admin/' . $path );
		Functions\when( 'plugins_url' )->alias( static fn( string $path = '' ): string => 'https://example.org/wp-content/plugins/firehouse/' . $path );
		Functions\when( 'wp_kses_post' )->returnArg();
	}

	/**
	 * Each FireHouse screen, by WordPress's ID, base and post type.
	 */
	public function test_screen_key_names_each_firehouse_screen(): void {
		$this->assertSame( 'project-list', Help::screen_key( 'edit-fh_project', 'edit', 'fh_project' ) );
		$this->assertSame( 'project-edit', Help::screen_key( 'fh_project', 'post', 'fh_project' ) );
		$this->assertSame( 'topic-list', Help::screen_key( 'edit-fh_topic', 'edit', 'fh_topic' ) );
		$this->assertSame( 'topic-edit', Help::screen_key( 'fh_topic', 'post', 'fh_topic' ) );
		// The FireHouse menu's own screens open with the projects' post type in the address.
		$this->assertSame( 'synthesis', Help::screen_key( 'fh_project_page_firehouse-synthesis', 'fh_project_page_firehouse-synthesis', 'fh_project' ) );
		$this->assertSame( 'settings', Help::screen_key( 'fh_project_page_firehouse-settings', 'fh_project_page_firehouse-settings', 'fh_project' ) );
		$this->assertSame( 'import', Help::screen_key( 'fh_project_page_firehouse-import', 'fh_project_page_firehouse-import', 'fh_project' ) );
	}

	/**
	 * No other screen, including the Help page itself and a project's revisions.
	 */
	public function test_screen_key_is_empty_elsewhere(): void {
		$this->assertSame( '', Help::screen_key( 'edit-post', 'edit', 'post' ) );
		$this->assertSame( '', Help::screen_key( 'page', 'post', 'page' ) );
		$this->assertSame( '', Help::screen_key( 'dashboard', 'dashboard', '' ) );
		$this->assertSame( '', Help::screen_key( 'fh_project_page_firehouse-help', 'fh_project_page_firehouse-help', 'fh_project' ) );
		$this->assertSame( '', Help::screen_key( 'revision', 'revision', 'fh_project' ) );
		$this->assertSame( '', Help::screen_key( 'settings_page_firehouse-settings-other', 'settings_page_firehouse-settings-other', '' ) );
	}

	/**
	 * Every screen a page may name has at least one page, so each FireHouse screen has
	 * a Help tab, and every page names only those screens.
	 */
	public function test_every_screen_has_a_page(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$named = array();
		foreach ( Help::pages() as $page ) {
			foreach ( $page['screens'] as $screen ) {
				$this->assertContains( $screen, Help::SCREENS, $page['slug'] );
				$named[ $screen ] = true;
			}
		}
		foreach ( Help::SCREENS as $screen ) {
			$this->assertArrayHasKey( $screen, $named, "No page of the guide names $screen." );
		}
	}

	/**
	 * Administrators read every page; FireHouse Editors don't see the administrators'.
	 */
	public function test_pages_by_audience(): void {
		Functions\when( 'current_user_can' )->alias( static fn( string $cap ): bool => 'firehouse_import' === $cap );
		$all = Help::pages();
		$this->assertContains( 'administrators', array_column( $all, 'audience' ) );
		$this->assertContains( 'editors', array_column( $all, 'audience' ) );

		Functions\when( 'current_user_can' )->justReturn( false );
		$editors = Help::pages();
		$this->assertNotEmpty( $editors );
		$this->assertSame( array( 'editors' ), array_values( array_unique( array_column( $editors, 'audience' ) ) ) );
		$this->assertLessThan( count( $all ), count( $editors ) );
	}

	/**
	 * A page's images and links to other pages get their addresses; nothing is left of
	 * the placeholders.
	 */
	public function test_content_fills_in_addresses(): void {
		$html = Help::content( '<img src="{{images}}/a.webp" alt=""><a href="{{guide}}#fh-help-projects">Projects</a>' );
		$this->assertSame(
			'<img src="https://example.org/wp-content/plugins/firehouse/help/images/a.webp" alt=""><a href="https://example.org/wp-admin/edit.php?post_type=fh_project&page=firehouse-help#fh-help-projects">Projects</a>',
			$html
		);

		Functions\when( 'current_user_can' )->justReturn( true );
		foreach ( Help::pages() as $page ) {
			$this->assertStringNotContainsString( '{{', Help::content( $page['html'] ), $page['slug'] );
			$this->assertStringNotContainsString( '{{', Help::content( $page['tab'] ), $page['slug'] );
		}
	}

	/**
	 * Every image a page shows is in help/images/, at the size help.json gives.
	 */
	public function test_images_exist(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$dir = dirname( __DIR__ ) . '/help/images/';
		foreach ( Help::pages() as $page ) {
			preg_match_all( '/src="\{\{images\}\}\/([a-z0-9-]+\.webp)" alt="[^"]+" width="(\d+)" height="(\d+)"/', $page['html'], $images, PREG_SET_ORDER );
			foreach ( $images as [ , $name, $width, $height ] ) {
				$this->assertFileExists( $dir . $name );
			}
			$this->assertStringNotContainsString( '<img', $page['tab'], 'A Help tab shows no screenshots: ' . $page['slug'] );
		}
	}

	/**
	 * A damaged help.json shows no pages rather than failing the screen.
	 */
	public function test_damaged_file_gives_no_pages(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_json_file_decode' )->justReturn( array( 'pages' => array( array( 'slug' => 'x' ), 'not a page' ) ) );
		$this->assertSame( array(), Help::pages() );

		Help::reset();
		Functions\when( 'wp_json_file_decode' )->justReturn( null );
		$this->assertSame( array(), Help::pages() );
	}
}
