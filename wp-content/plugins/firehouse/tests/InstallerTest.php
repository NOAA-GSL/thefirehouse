<?php
/**
 * Tests for activation, deactivation and upgrades.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse\Tests;

use Brain\Monkey\Functions;
use Firehouse\Installer;

/**
 * The role, capabilities and rewrite rules are stored once per revision.
 */
final class InstallerTest extends WordPressTestCase {

	/**
	 * Stubs what installing calls.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'remove_role' )->justReturn();
		Functions\when( 'add_role' )->justReturn();
		Functions\when( 'get_role' )->justReturn( null );
	}

	/**
	 * A site with an older revision, or none, installs once and records this one.
	 */
	public function test_an_older_site_installs(): void {
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\expect( 'flush_rewrite_rules' )->once()->with( false );
		Functions\expect( 'update_option' )->once()->with( Installer::OPTION, Installer::REVISION );

		Installer::maybe_upgrade();
	}

	/**
	 * A site with this revision does nothing.
	 */
	public function test_a_current_site_does_nothing(): void {
		Functions\when( 'get_option' )->justReturn( (string) Installer::REVISION );
		Functions\expect( 'flush_rewrite_rules' )->never();
		Functions\expect( 'update_option' )->never();

		Installer::maybe_upgrade();
	}

	/**
	 * Activation registers the post types before the rewrite rules are rebuilt.
	 */
	public function test_activation_registers_the_post_types_first(): void {
		$order = array();
		Functions\when( 'register_post_type' )->alias(
			static function ( string $type ) use ( &$order ): void {
				$order[] = $type;
			}
		);
		Functions\when( 'register_post_meta' )->justReturn( true );
		Functions\when( 'flush_rewrite_rules' )->alias(
			static function () use ( &$order ): void {
				$order[] = 'flush';
			}
		);
		Functions\when( 'update_option' )->justReturn( true );

		Installer::activate();

		$this->assertSame( array( 'fh_project', 'fh_topic', 'flush' ), $order );
	}

	/**
	 * Deactivation takes the addresses out of the rewrite rules and forgets the
	 * revision, so reactivating installs again.
	 */
	public function test_deactivation_removes_the_addresses(): void {
		$order = array();
		Functions\when( 'unregister_post_type' )->alias(
			static function ( string $type ) use ( &$order ): void {
				$order[] = $type;
			}
		);
		Functions\when( 'flush_rewrite_rules' )->alias(
			static function () use ( &$order ): void {
				$order[] = 'flush';
			}
		);
		Functions\expect( 'delete_option' )->once()->with( Installer::OPTION );

		Installer::deactivate();

		$this->assertSame( array( 'fh_project', 'fh_topic', 'flush' ), $order );
	}
}
