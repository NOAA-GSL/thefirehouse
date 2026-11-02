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
 * Stores what the plugin needs in the database, once: the Firehouse Editor role, the
 * administrators' capabilities, and the rewrite rules for the Firehouse addresses.
 *
 * Activation does it, and so does the first request after an update that changes
 * REVISION, since WordPress doesn't run the activation hook on an update.
 */
final class Installer {

	/**
	 * Raised whenever the role, the capabilities or the rewrite rules change, so each
	 * site applies the change once.
	 */
	public const REVISION = 1;

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
	 * Installs on activation. The post types are registered first, because activation
	 * runs after `init` and the rewrite rules are built from them.
	 */
	public static function activate(): void {
		Post_Types::register();
		self::install();
	}

	/**
	 * Installs when the site has an older revision. Runs after the post types are
	 * registered.
	 */
	public static function maybe_upgrade(): void {
		if ( self::REVISION !== (int) get_option( self::OPTION, 0 ) ) {
			self::install();
		}
	}

	/**
	 * Removes the Firehouse addresses from the rewrite rules, and forgets the
	 * revision so reactivating installs again. The role and the content stay.
	 */
	public static function deactivate(): void {
		unregister_post_type( Post_Types::PROJECT );
		unregister_post_type( Post_Types::TOPIC );
		flush_rewrite_rules( false );
		delete_option( self::OPTION );
	}

	/**
	 * Stores the role, the capabilities and the rewrite rules, and records the
	 * revision.
	 */
	private static function install(): void {
		Capabilities::install();
		flush_rewrite_rules( false );
		update_option( self::OPTION, self::REVISION );
	}
}
