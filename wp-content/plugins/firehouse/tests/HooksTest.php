<?php
/**
 * Tests for what each class hooks, and how.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Firehouse\Capabilities;
use Firehouse\Frame;
use Firehouse\Import_Screen;
use Firehouse\Installer;
use Firehouse\Meta;
use Firehouse\Post_Types;
use Firehouse\Query;
use Firehouse\Settings;

/**
 * Each callback is hooked with the priority and the number of arguments it needs. A
 * filter hooked with fewer arguments than its callback takes fails every call of it,
 * site-wide, and the upgrade must run after the post types are registered.
 */
final class HooksTest extends WordPressTestCase {

	/**
	 * The post types are registered on init, before the upgrade check runs there.
	 */
	public function test_post_types_and_the_upgrade_run_in_order_on_init(): void {
		Actions\expectAdded( 'init' )->once()->with( array( Post_Types::class, 'register' ), 10, 1 );
		Post_Types::hook();

		Functions\expect( 'register_activation_hook' )->once()->with( 'firehouse.php', array( Installer::class, 'activate' ) );
		Functions\expect( 'register_deactivation_hook' )->once()->with( 'firehouse.php', array( Installer::class, 'deactivate' ) );
		Actions\expectAdded( 'init' )->once()->with( array( Installer::class, 'maybe_upgrade' ), 20, 1 );
		Installer::hook( 'firehouse.php' );
	}

	/**
	 * Every write of a field is checked, with the four arguments the check reads.
	 */
	public function test_meta_writes_are_checked(): void {
		Filters\expectAdded( 'add_post_metadata' )->once()->with( array( Meta::class, 'refuse_add' ), 10, 4 );
		Filters\expectAdded( 'update_post_metadata' )->once()->with( array( Meta::class, 'refuse_invalid' ), 10, 4 );

		Meta::hook();
	}

	/**
	 * The `year` fix filters the request.
	 */
	public function test_the_year_fix_filters_the_request(): void {
		Filters\expectAdded( 'request' )->once()->with( array( Query::class, 'request' ), 10, 1 );

		Query::hook();
	}

	/**
	 * Each capability filter gets the arguments it reads; XML-RPC is refused after
	 * WordPress has decided who signed in.
	 */
	public function test_capability_filters_get_their_arguments(): void {
		Filters\expectAdded( 'user_has_cap' )->once()->with( array( Capabilities::class, 'lend_page_caps' ), 10, 1 );
		Filters\expectAdded( 'map_meta_cap' )->once()->with( array( Capabilities::class, 'map_meta_cap' ), 10, 4 );
		Filters\expectAdded( 'wp_insert_post_empty_content' )->once()->with( array( Capabilities::class, 'refuse_write' ), 10, 2 );
		Filters\expectAdded( 'wp_insert_post_data' )->once()->with( array( Capabilities::class, 'keep_in_place' ), 10, 2 );
		Filters\expectAdded( 'rest_pre_insert_page' )->once()->with( array( Capabilities::class, 'refuse_rest_page_write' ), 10, 2 );
		Filters\expectAdded( 'authenticate' )->once()->with( array( Capabilities::class, 'refuse_xmlrpc' ), 100, 1 );
		Actions\expectAdded( 'load-post-new.php' )->once()->with( array( Capabilities::class, 'refuse_new_page_screen' ), 10, 1 );
		Actions\expectAdded( 'pre_get_posts' )->once()->with( array( Capabilities::class, 'limit_page_list' ), 10, 1 );

		Capabilities::hook();
	}

	/**
	 * The settings register in the admin, and save with Firehouse's capability.
	 */
	public function test_settings_hooks(): void {
		Actions\expectAdded( 'admin_init' )->once()->with( array( Settings::class, 'register' ), 10, 1 );
		Actions\expectAdded( 'admin_menu' )->once()->with( array( Settings::class, 'add_page' ), 10, 1 );
		Filters\expectAdded( 'option_page_capability_firehouse_settings' )->once()->with( array( Settings::class, 'capability' ), 10, 1 );

		Settings::hook();
	}

	/**
	 * The Import screen adds its page in the admin menu.
	 */
	public function test_import_screen_hooks(): void {
		Actions\expectAdded( 'admin_menu' )->once()->with( array( Import_Screen::class, 'add_page' ), 10, 1 );

		Import_Screen::hook();
	}

	/**
	 * The frame takes the template late, loads its stylesheets after the theme's, and
	 * adds its body class.
	 */
	public function test_frame_hooks(): void {
		Filters\expectAdded( 'template_include' )->once()->with( array( Frame::class, 'template' ), 99, 1 );
		Actions\expectAdded( 'wp_enqueue_scripts' )->once()->with( array( Frame::class, 'enqueue' ), 20, 1 );
		Filters\expectAdded( 'body_class' )->once()->with( array( Frame::class, 'body_class' ), 10, 1 );

		Frame::hook();
	}
}
