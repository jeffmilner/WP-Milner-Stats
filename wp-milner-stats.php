<?php
/**
 * Plugin Name:       Milner Stats
 * Plugin URI:        https://github.com/jeffmilner/WP-Milner-Stats
 * Description:       Lightweight post view tracking with day, week, month, year, and multi-year breakdowns. No bloat, no external services.
 * Version:           1.1.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Jeff Milner
 * Author URI:        https://github.com/jeffmilner
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-milner-stats
 */

defined( 'ABSPATH' ) || exit;

// ── Constants ────────────────────────────────────────────────────────────────
define( 'WMS_VERSION',         '1.1.1' );
define( 'WMS_PLUGIN_FILE',     __FILE__ );
define( 'WMS_PLUGIN_DIR',      plugin_dir_path( __FILE__ ) );
define( 'WMS_PLUGIN_URL',      plugin_dir_url( __FILE__ ) );
define( 'WMS_TABLE_NAME',      'wms_post_views' );   // page view events
define( 'WMS_REFERRERS_TABLE', 'wms_referrers' );    // referrer + search term events
define( 'WMS_OUTLINKS_TABLE',  'wms_outlinks' );     // outbound link click events

// ── Autoloader ───────────────────────────────────────────────────────────────
spl_autoload_register( function ( $class ) {
	$prefix = 'WMS_';
	if ( strpos( $class, $prefix ) !== 0 ) {
		return;
	}
	$file_map = [
		'WMS_Activator'       => 'class-wms-activator.php',
		'WMS_Tracker'         => 'class-wms-tracker.php',
		'WMS_Query'           => 'class-wms-query.php',
		'WMS_Admin'           => 'class-wms-admin.php',
		'WMS_Widget'          => 'class-wms-widget.php',
		'WMS_REST_API'        => 'class-wms-rest-api.php',
		'WMS_Post_Columns'    => 'class-wms-post-columns.php',
		'WMS_Meta_Box'        => 'class-wms-meta-box.php',
		'WMS_Public'          => 'class-wms-public.php',
		'WMS_Export'          => 'class-wms-export.php',
		'WMS_Sidebar_Widget'  => 'class-wms-sidebar-widget.php',
		'WMS_Trending'        => 'class-wms-trending.php',
		'WMS_Admin_Bar'       => 'class-wms-admin-bar.php',
		'WMS_Compatibility'   => 'class-wms-compatibility.php',
		'WMS_Referrer_Query'  => 'class-wms-referrer-query.php',
		'WMS_Outlink_Query'   => 'class-wms-outlink-query.php',
		'WMS_Insights'        => 'class-wms-insights.php',
	];
	if ( isset( $file_map[ $class ] ) ) {
		require_once WMS_PLUGIN_DIR . 'includes/' . $file_map[ $class ];
	}
} );

// ── Activation / Deactivation ─────────────────────────────────────────────
register_activation_hook( __FILE__, [ 'WMS_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'WMS_Activator', 'deactivate' ] );

// ── Bootstrap ─────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'wms_init' );

function wms_init() {
	// Version check — re-run activation if DB needs upgrading
	if ( get_option( 'wms_db_version' ) !== WMS_VERSION ) {
		WMS_Activator::activate();
	}

	// Always-on: caching compatibility layer (must be early)
	WMS_Compatibility::init();

	// Always-on: REST API (stats/reporting endpoints only)
	$rest = new WMS_REST_API();
	$rest->register_routes();

	// Always-on: AJAX tracking handlers (more reliable than REST for anonymous POSTs —
	// works on all WordPress setups regardless of REST API configuration or security plugins)
	add_action( 'wp_ajax_nopriv_wms_track',         'wms_ajax_track' );
	add_action( 'wp_ajax_wms_track',                'wms_ajax_track' );
	add_action( 'wp_ajax_nopriv_wms_track_outlink', 'wms_ajax_track_outlink' );
	add_action( 'wp_ajax_wms_track_outlink',        'wms_ajax_track_outlink' );

	// Always-on: shortcodes + template tags
	WMS_Public::init();

	// Always-on: cron callback must be registered on every load
	add_action( 'wms_daily_cleanup', [ 'WMS_Activator', 'cleanup_old_records' ] );

	// Always-on: sidebar widget
	add_action( 'widgets_init', [ 'WMS_Sidebar_Widget', 'register' ] );

	// Always-on: admin bar (frontend + admin, gated by capability inside the class)
	WMS_Admin_Bar::init();

	// Admin-only features
	if ( is_admin() ) {
		$admin = new WMS_Admin();
		$admin->init();

		WMS_Post_Columns::init();
		WMS_Meta_Box::init();
		WMS_Export::init();
	}

	// Dashboard widget (admin area)
	add_action( 'wp_dashboard_setup', function () {
		$widget = new WMS_Widget();
		$widget->register();
	} );

	// Enqueue the frontend tracker script on singular posts/pages
	add_action( 'wp_enqueue_scripts', 'wms_enqueue_tracker' );
}

/**
 * Enqueue the lightweight frontend tracker.
 *
 * Tracks:
 *  - Singular posts/pages of configured post types (existing behaviour).
 *  - The site homepage when it is set to a static page (Settings > Reading >
 *    "A static page"). The static page is a real published post, so it gets
 *    its own post ID and appears in the stats dashboard like any other page.
 *  - The blog-index homepage ("Your latest posts") is NOT tracked because
 *    WordPress does not assign it a post ID — there is no single record to
 *    attach the view to. Use a static front page if you want homepage tracking.
 *
 * Respects the 'wms_track_post_types' setting.
 */
function wms_enqueue_tracker() {
	$post_id = 0;

	if ( is_singular() ) {
		// Standard single post / page / CPT view
		$tracked = get_option( 'wms_track_post_types', [ 'post', 'page' ] );
		if ( ! in_array( get_post_type(), (array) $tracked, true ) ) {
			return;
		}
		$post_id = (int) get_the_ID();

	} elseif ( is_front_page() && 'page' === get_option( 'show_on_front' ) ) {
		// Static front page — it IS a real page post; track it if pages are tracked.
		$front_page_id = (int) get_option( 'page_on_front' );
		if ( ! $front_page_id ) {
			return;
		}
		$tracked = get_option( 'wms_track_post_types', [ 'post', 'page' ] );
		if ( ! in_array( 'page', (array) $tracked, true ) ) {
			return;
		}
		$post_id = $front_page_id;

	} else {
		return;
	}

	if ( ! $post_id ) {
		return;
	}

	wp_enqueue_script(
		'wms-tracker',
		WMS_PLUGIN_URL . 'public/js/tracker.js',
		[],
		WMS_VERSION,
		true
	);

	wp_localize_script( 'wms-tracker', 'wmsData', [
		'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
		'postId'   => $post_id,
		'siteHost' => wp_parse_url( home_url(), PHP_URL_HOST ),
	] );
}

/**
 * AJAX handler: record a page view.
 * Registered for both wp_ajax_ and wp_ajax_nopriv_ so it fires for all visitors.
 */
function wms_ajax_track() {
	// Verify nonce if provided, but don't block anonymous requests even if stale —
	// page view tracking is low-risk and a cached page may have an expired nonce.
	// The tracker itself handles deduplication and bot detection.
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Public tracking endpoint; anonymous visitors cannot provide nonces, and cached pages may have expired nonces. Bot/dedup checks are in WMS_Tracker::record().
	$post_id    = absint( $_POST['post_id'] ?? 0 );
	$referrer   = sanitize_text_field( wp_unslash( $_POST['referrer'] ?? '' ) );
	// phpcs:enable WordPress.Security.NonceVerification.Missing
	$ip_address = wms_get_client_ip();
	$user_agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );

	if ( $post_id < 1 ) {
		wp_send_json( [ 'result' => 'skipped' ] );
	}

	// Fall back to HTTP Referer header if JS didn't send one
	if ( empty( $referrer ) && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
		$referrer = sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
	}

	$result = WMS_Tracker::record( $post_id, $ip_address, $user_agent, $referrer );
	wp_send_json( [ 'result' => $result ] );
}

/**
 * AJAX handler: record an outbound link click.
 */
function wms_ajax_track_outlink() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- Public tracking endpoint; anonymous visitors cannot provide nonces.
	$post_id   = absint( $_POST['post_id']   ?? 0 );
	$link_url  = sanitize_text_field( wp_unslash( $_POST['link_url']  ?? '' ) );
	$link_text = sanitize_text_field( wp_unslash( $_POST['link_text'] ?? '' ) );
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	if ( $post_id < 1 || empty( $link_url ) ) {
		wp_send_json( [ 'recorded' => false ] );
	}

	$salt    = defined( 'LOGGED_IN_SALT' ) ? LOGGED_IN_SALT : 'wms-salt';
	$ip_hash = hash( 'sha256', wms_get_client_ip() . $salt );

	$recorded = WMS_Outlink_Query::record_click( $post_id, $link_url, $link_text, $ip_hash );
	wp_send_json( [ 'recorded' => $recorded ] );
}

/**
 * Get the real client IP address, respecting common proxy headers.
 */
function wms_get_client_ip(): string {
	$candidates = [
		'HTTP_CF_CONNECTING_IP',
		'HTTP_X_FORWARDED_FOR',
		'HTTP_X_REAL_IP',
		'REMOTE_ADDR',
	];
	foreach ( $candidates as $key ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- value is validated by filter_var( FILTER_VALIDATE_IP ), which is stricter than sanitize_text_field for IP addresses.
		if ( ! empty( $_SERVER[ $key ] ) ) {
			$ip = trim( explode( ',', wp_unslash( $_SERVER[ $key ] ) )[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
	}
	return '0.0.0.0';
}
