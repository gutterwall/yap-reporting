<?php
/**
 * Uninstall: always removes the API credential, caches, locks and scheduled jobs.
 * Settings, Area selections, roles and per-user grants are removed too unless
 * "Keep settings on uninstall" was ticked. No data in YAP is touched.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'yapr_sync_recent', 'yapr_sync_history', 'yapr_sync_bmlt', 'yapr_cache_prune' ) as $yapr_hook ) {
	wp_clear_scheduled_hook( $yapr_hook );
}

delete_option( 'yapr_api_token' );
delete_option( 'yapr_capabilities' );
foreach ( array( 'recent', 'history', 'bmlt' ) as $yapr_job ) {
	delete_option( 'yapr_lock_job_' . $yapr_job );
}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_yapr\\_%' OR option_name LIKE '\\_transient\\_timeout\\_yapr\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}yapr_aggregate_cache" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}yapr_sync_state" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$yapr_settings = get_option( 'yapr_settings' );
$yapr_keep     = is_array( $yapr_settings ) && ! empty( $yapr_settings['keep_data_on_uninstall'] );

if ( ! $yapr_keep ) {
	delete_option( 'yapr_settings' );
	delete_option( 'yapr_db_version' );
	delete_option( 'yapr_bmlt_synced_at' );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}yapr_areas" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	delete_metadata( 'user', 0, 'yapr_areas', '', true );
	$yapr_caps = array( 'yapr_manage_settings', 'yapr_manage_access', 'yapr_view_reports', 'yapr_view_call_details', 'yapr_view_volunteers', 'yapr_export_reports' );
	foreach ( wp_roles()->role_objects as $yapr_role ) {
		foreach ( $yapr_caps as $yapr_cap ) {
			$yapr_role->remove_cap( $yapr_cap );
		}
	}
	remove_role( 'yapr_phoneline_admin' );
	remove_role( 'yapr_area_reporter' );
} else {
	// Tables dropped above must be recreated on reinstall; force the installer to run.
	delete_option( 'yapr_db_version' );
}
