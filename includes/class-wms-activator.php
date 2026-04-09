<?php
/**
 * Handles plugin activation, DB table creation, and scheduled tasks.
 *
 * Tables created:
 *   {prefix}wms_post_views  — one row per page view (views + unique visitor tracking)
 *   {prefix}wms_referrers   — referrer URLs and search-term extraction, per view
 *   {prefix}wms_outlinks    — outbound link click events tracked via JS beacon
 *
 * @package Milner_Stats
 */

defined( 'ABSPATH' ) || exit;

class WMS_Activator {

	/**
	 * Run on plugin activation.
	 */
	public static function activate() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// ── Table 1: Post views ────────────────────────────────────────────
		// is_new_visitor: 1 if this ip_hash had no prior view in the dedup window
		// on the same post — lets us separate "views" from "visitors" the Jetpack way.
		$views_table = $wpdb->prefix . WMS_TABLE_NAME; // wms_post_views
		$sql_views   = "CREATE TABLE {$views_table} (
			id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id         BIGINT(20) UNSIGNED NOT NULL,
			viewed_at       DATETIME            NOT NULL,
			ip_hash         VARCHAR(64)         NOT NULL DEFAULT '',
			user_agent      VARCHAR(255)        NOT NULL DEFAULT '',
			is_new_visitor  TINYINT(1)          NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			KEY idx_post_viewed  (post_id, viewed_at),
			KEY idx_viewed_at    (viewed_at),
			KEY idx_visitor      (viewed_at, is_new_visitor)
		) {$charset_collate};";
		dbDelta( $sql_views );

		// ── Table 2: Referrers & search terms ─────────────────────────────
		$referrers_table = $wpdb->prefix . WMS_REFERRERS_TABLE;
		$sql_referrers   = "CREATE TABLE {$referrers_table} (
			id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			view_id       BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			post_id       BIGINT(20) UNSIGNED NOT NULL,
			viewed_at     DATETIME            NOT NULL,
			referrer_url  VARCHAR(2048)       NOT NULL DEFAULT '',
			referrer_host VARCHAR(255)        NOT NULL DEFAULT '',
			search_term   VARCHAR(512)        NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY idx_ref_post  (post_id, viewed_at),
			KEY idx_ref_host  (referrer_host, viewed_at),
			KEY idx_ref_term  (search_term(64), viewed_at)
		) {$charset_collate};";
		dbDelta( $sql_referrers );

		// ── Table 3: Outbound link clicks ──────────────────────────────────
		$outlinks_table = $wpdb->prefix . WMS_OUTLINKS_TABLE;
		$sql_outlinks   = "CREATE TABLE {$outlinks_table} (
			id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id     BIGINT(20) UNSIGNED NOT NULL,
			clicked_at  DATETIME            NOT NULL,
			link_url    VARCHAR(2048)       NOT NULL DEFAULT '',
			link_host   VARCHAR(255)        NOT NULL DEFAULT '',
			link_text   VARCHAR(255)        NOT NULL DEFAULT '',
			ip_hash     VARCHAR(64)         NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY idx_out_post   (post_id, clicked_at),
			KEY idx_out_host   (link_host, clicked_at),
			KEY idx_clicked_at (clicked_at)
		) {$charset_collate};";
		dbDelta( $sql_outlinks );

		update_option( 'wms_db_version', WMS_VERSION );

		// Cancel any previously-scheduled cleanup cron (stats are kept indefinitely).
		$timestamp = wp_next_scheduled( 'wms_daily_cleanup' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wms_daily_cleanup' );
		}
	}

	/**
	 * Run on plugin deactivation (data preserved).
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'wms_daily_cleanup' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wms_daily_cleanup' );
		}
	}

	/**
	 * No-op — stats are kept indefinitely.
	 */
	public static function cleanup_old_records() {}
}
