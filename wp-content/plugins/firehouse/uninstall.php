<?php
/**
 * Runs when the plugin is deleted from wp-admin.
 *
 * Removes the Firehouse Editor role, so users who had it are left with no role on the
 * site; every Firehouse capability administrators hold; the record of the installed
 * revision; the stored rewrite rules, so WordPress rebuilds them without the Firehouse
 * addresses; and anything still waiting in a transient (a dry run, a synthesis check,
 * a refused save, a publishing rule's notice). The content stays: the projects,
 * topics and settings belong to the site, and reinstalling the plugin shows them
 * again. That includes the survey form's draft projects and their private answers
 * (submitters' emails, job titles and how they shared their results), in the
 * protected `_firehouse_private` post meta field. Deleting them is a decision for
 * the site's administrators and NOAA's privacy review, not this file.
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

// Analyze results' drafts, and a run waiting to start (Analysis::OPTION, ::EVENT).
delete_option( 'firehouse_analysis' );
wp_unschedule_hook( 'firehouse_analyze' );

// Dry runs, synthesis checks, refused saves and notices are transients named for
// their user. Kept in the
// database, they are found by name; kept in an object cache, they expire on their own.
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Removing the plugin's own transients by name, once.
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_firehouse_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_firehouse_' ) . '%'
	)
);
