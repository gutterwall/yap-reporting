<?php
/**
 * Uninstall: always removes the API credential, caches, locks and scheduled jobs.
 * Settings, Area selections, roles and per-user grants are removed too unless
 * "Keep settings on uninstall" was ticked. No data in YAP is touched.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'yapr_sync_recent', 'yapr_sync_history', 'yapr_sync_bmlt', 'yapr_cache_prune' ) as $hook ) {
	wp_clear_scheduled_hook( $hook );
}

delete_option( 'yapr_api_token' );
delete_option( 'yapr_capabilities' );
foreach ( array( 'recent', 'history', 'bmlt' ) as $job ) {
	delete_option( 'yapr_lock_job_' . $job );
}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_yapr\\_%' OR option_name LIKE '\\_transient\\_timeout\\_yapr\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}yapr_aggregate_cache" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}yapr_sync_state" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$settings = get_option( 'yapr_settings' );
$keep     = is_array( $settings ) && ! empty( $settings['keep_data_on_uninstall'] );

if ( ! $keep ) {
	delete_option( 'yapr_settings' );
	delete_option( 'yapr_db_version' );
	delete_option( 'yapr_bmlt_synced_at' );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}yapr_areas" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	delete_metadata( 'user', 0, 'yapr_areas', '', true );
	$caps = array( 'yapr_manage_settings', 'yapr_manage_access', 'yapr_view_reports', 'yapr_view_call_details', 'yapr_view_volunteers', 'yapr_export_reports' );
	foreach ( wp_roles()->role_objects as $role ) {
		foreach ( $caps as $cap ) {
			$role->remove_cap( $cap );
		}
	}
	remove_role( 'yapr_phoneline_admin' );
	remove_role( 'yapr_area_reporter' );
} else {
	// Tables dropped above must be recreated on reinstall; force the installer to run.
	delete_option( 'yapr_db_version' );
}
