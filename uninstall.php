<?php
/**
 * What deleting the plugin removes, and what it deliberately does not.
 *
 * WordPress runs this only on a real delete, never on deactivation, and only
 * from its own uninstaller - the ABSPATH guard below is what enforces that.
 *
 * The split is between the plugin's own machinery and the owner's content.
 * Settings, the domain lock and the recovery guard are ours: they exist only
 * because this plugin was installed, they mean nothing without it, and leaving
 * a mu-plugin behind that loads on every request is litter.
 *
 * Skills, checkpoints, the site brief and the design notes are not ours.
 * Somebody wrote those instructions; the checkpoints are the only record of
 * what a write replaced. Deleting a plugin is not consent to destroy them, and
 * a reinstall would find them waiting. They go only if the owner asked, on the
 * Configuration screen, which defaults to off.
 *
 * @package NiranzWP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$niranzwp_settings = get_option( 'niranzwp_settings', [] );
$niranzwp_purge    = is_array( $niranzwp_settings ) && ! empty( $niranzwp_settings['purge_on_delete'] );

// The guard, first. It is a mu-plugin and nothing else will ever remove it.
$niranzwp_guard = ( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' )
	. '/niranzwp-guard.php';
if ( file_exists( $niranzwp_guard ) ) {
	unlink( $niranzwp_guard );
}

// Anything the guard left mid-flight, and the plugin's own options.
foreach ( [ 'niranzwp-pending.json', 'niranzwp-recovered.json' ] as $niranzwp_leftover ) {
	$niranzwp_path = WP_CONTENT_DIR . '/' . $niranzwp_leftover;
	if ( file_exists( $niranzwp_path ) ) {
		unlink( $niranzwp_path );
	}
}

// The switches and the domain lock. These describe the plugin's own state and
// mean nothing without it.
foreach ( [ 'niranzwp_settings', 'niranzwp_settings_domain', 'niranzwp_guard_installed' ] as $niranzwp_option ) {
	delete_option( $niranzwp_option );
}

// The update manifest is stored as a SITE transient, so delete_transient would
// walk past it and leave the row for its full twelve hours.
delete_site_transient( 'niranzwp_update_manifest' );
delete_transient( 'niranzwp_just_activated' );

if ( ! $niranzwp_purge ) {
	return;
}

/*
 * Only past this line if the owner ticked the box. Both are custom post types,
 * so they are deleted as posts rather than by dropping rows - meta, revisions
 * and everything else attached goes with them, which a manual DELETE would
 * leave orphaned.
 */
// Written by the owner, not by the plugin: the standing brief every client
// reads, and the design notes.
foreach ( [ 'niranzwp_context', 'niranzwp_context_enabled', 'niranzwp_design' ] as $niranzwp_written ) {
	delete_option( $niranzwp_written );
}

foreach ( [ 'niranzwp_skill', 'niranzwp_ckpt' ] as $niranzwp_type ) {
	$niranzwp_ids = get_posts(
		[
			'post_type'      => $niranzwp_type,
			'post_status'    => 'any',
			'numberposts'    => -1,
			'fields'         => 'ids',
			'suppress_filters' => true,
		]
	);
	foreach ( $niranzwp_ids as $niranzwp_id ) {
		wp_delete_post( (int) $niranzwp_id, true );
	}
}
