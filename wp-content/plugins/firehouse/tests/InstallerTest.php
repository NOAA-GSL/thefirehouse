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
	 * Stubs the administrator role, which installing equips.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_role' )->justReturn( null );
	}

	/**
	 * A site with an older revision, or none, installs the role, has the rewrite
	 * rules rebuilt, and records this revision.
	 */
	public function test_an_older_site_installs(): void {
		Functions\when( 'get_option' )->justReturn( 1 );
		Functions\expect( 'remove_role' )->once()->with( 'firehouse_editor' );
		Functions\expect( 'add_role' )->once();
		Functions\expect( 'delete_option' )->once()->with( 'rewrite_rules' );
		Functions\expect( 'update_option' )->once()->with( Installer::OPTION, Installer::REVISION );

		Installer::maybe_upgrade();
	}

	/**
	 * A site with this revision does nothing.
	 */
	public function test_a_current_site_does_nothing(): void {
		Functions\when( 'get_option' )->justReturn( (string) Installer::REVISION );
		Functions\expect( 'add_role' )->never();
		Functions\expect( 'delete_option' )->never();
		Functions\expect( 'update_option' )->never();

		Installer::maybe_upgrade();
	}

	/**
	 * Activation installs, whatever revision is recorded.
	 */
	public function test_activation_installs(): void {
		Functions\when( 'remove_role' )->justReturn();
		Functions\expect( 'add_role' )->once();
		Functions\expect( 'delete_option' )->once()->with( 'rewrite_rules' );
		Functions\expect( 'update_option' )->once()->with( Installer::OPTION, Installer::REVISION );

		Installer::activate();
	}

	/**
	 * Deactivation has the rewrite rules rebuilt without the Firehouse addresses, and
	 * forgets the revision, so reactivating installs again.
	 */
	public function test_deactivation_removes_the_addresses(): void {
		$deleted = array();
		Functions\when( 'delete_option' )->alias(
			static function ( string $option ) use ( &$deleted ): bool {
				$deleted[] = $option;
				return true;
			}
		);

		Installer::deactivate();

		$this->assertSame( array( 'rewrite_rules', Installer::OPTION ), $deleted );
	}
}
