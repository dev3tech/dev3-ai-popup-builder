<?php
/**
 * Uninstall: removes data only when "delete data on uninstall" is enabled.
 *
 * @package Dev3\AiPopupBuilder
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dev3pu_settings = get_option( 'dev3pu_settings', array() );
if ( empty( $dev3pu_settings['delete_on_uninstall'] ) ) {
	return;
}
global $wpdb;
foreach ( array( 'dev3pu_usage', 'dev3pu_core_cache', 'dev3pu_core_jobs', 'dev3pu_core_embeddings', 'dev3pu_campaigns', 'dev3pu_stats', 'dev3pu_leads' ) as $dev3pu_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$dev3pu_table}" );
}
foreach ( array( 'dev3pu_settings', 'dev3pu_license', 'dev3pu_db_version', 'dev3pu_secrets' ) as $dev3pu_option ) {
	delete_option( $dev3pu_option );
}
delete_transient( 'dev3pu_models' );
delete_site_transient( 'dev3pu_update_info' );
wp_unschedule_hook( 'dev3pu_run_job' );
wp_unschedule_hook( 'dev3pu_daily' );
