<?php
/**
 * Activation, deactivation and upgrades.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

namespace Firehouse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores what the plugin needs in the database, once: the Firehouse Editor role and
 * the administrators' capabilities, and a rebuild of the rewrite rules so they hold
 * the Firehouse addresses.
 *
 * Activation does it, and so does the first request after an update that raises
 * REVISION, since WordPress doesn't run the activation hook on an update. The rules
 * are rebuilt by deleting the stored ones: WordPress makes them again when it next
 * needs them, after every plugin and theme has registered its own.
 */
final class Installer {

	/**
	 * Raised whenever the role, the capabilities or the addresses change, so each
	 * site applies the change once.
	 */
	public const REVISION = 2;

	/**
	 * The option that records the revision a site has.
	 */
	public const OPTION = 'firehouse_installed_revision';

	/**
	 * Hooks activation, deactivation and the upgrade check.
	 *
	 * @param string $plugin_file The plugin's main file.
	 */
	public static function hook( string $plugin_file ): void {
		register_activation_hook( $plugin_file, array( self::class, 'activate' ) );
		register_deactivation_hook( $plugin_file, array( self::class, 'deactivate' ) );
		add_action( 'init', array( self::class, 'maybe_upgrade' ), 20 );
	}

	/**
	 * Installs on activation.
	 */
	public static function activate(): void {
		self::install();
	}

	/**
	 * Installs when the site has an older revision.
	 */
	public static function maybe_upgrade(): void {
		if ( self::REVISION !== (int) get_option( self::OPTION, 0 ) ) {
			self::install();
		}
	}

	/**
	 * Takes the Firehouse addresses out of the rewrite rules, and forgets the
	 * revision so reactivating installs again. The role stays, and holds nothing of
	 * WordPress's while the plugin is off; uninstall.php removes it.
	 */
	public static function deactivate(): void {
		delete_option( 'rewrite_rules' );
		delete_option( self::OPTION );
	}

	/**
	 * Stores the role and the capabilities, has the rewrite rules rebuilt, and
	 * records the revision.
	 */
	private static function install(): void {
		Capabilities::install();
		delete_option( 'rewrite_rules' );
		update_option( self::OPTION, self::REVISION );
	}
}
