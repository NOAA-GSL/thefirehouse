<?php
/**
 * Runs when the plugin is deleted from wp-admin.
 *
 * Removes the Firehouse Editor role and every Firehouse capability administrators
 * hold, and the record of the installed revision. The content stays: the projects,
 * topics and settings belong to the site, and reinstalling the plugin shows them
 * again. Deleting them is a decision for the site's administrators, not this file.
 *
 * @package Firehouse
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

remove_role( 'firehouse_editor' );

// The plugin isn't loaded here, so this matches Firehouse's capabilities as
// Capabilities::is_own() does: "firehouse_…", and the post types' "…_fh_projects"
// and "…_fh_topics".
$firehouse_administrator = get_role( 'administrator' );
if ( $firehouse_administrator ) {
	foreach ( array_keys( $firehouse_administrator->capabilities ) as $firehouse_cap ) {
		if ( 1 === preg_match( '/^firehouse_|_fh_(projects|topics)$/', (string) $firehouse_cap ) ) {
			$firehouse_administrator->remove_cap( $firehouse_cap );
		}
	}
}

delete_option( 'firehouse_installed_revision' );
delete_option( 'rewrite_rules' );
