<?php
/**
 * Uninstall script for Milner Stats.
 *
 * Runs when the plugin is DELETED from WordPress admin.
 * Removes all plugin data: tables, options, transients, cron events.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ── Drop all plugin tables ──────────────────────────────────────────────
$tables = [
	$wpdb->prefix . 'wms_post_views',
	$wpdb->prefix . 'wms_referrers',
	$wpdb->prefix . 'wms_outlinks',
];
foreach ( $tables as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is $wpdb->prefix + plugin constant; cannot be parameterised via prepare().
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}

// ── Delete all plugin options ──────────────────────────────────────────
$options = [
	'wms_db_version',
	'wms_skip_admin_views',
	'wms_track_post_types',
	'wms_dedup_window',
];
foreach ( $options as $option ) {
	delete_option( $option );
}

// ── Unschedule cron events ─────────────────────────────────────────────
$timestamp = wp_next_scheduled( 'wms_daily_cleanup' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'wms_daily_cleanup' );
}

// ── Remove transients ──────────────────────────────────────────────────
if ( ! wp_using_ext_object_cache() ) {
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '_transient_wms_%'
		    OR option_name LIKE '_transient_timeout_wms_%'"
	);
}
