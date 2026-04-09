<?php
/**
 * Caching Plugin Compatibility
 *
 * Full-page caching plugins (WP Super Cache, W3 Total Cache, WP Rocket,
 * LiteSpeed Cache, etc.) serve pages from a static cache, which means
 * the PHP tracker never fires. This class provides solutions:
 *
 * Strategy A — JS-based tracking (default, already works):
 *   The frontend tracker.js fires after page load via JS fetch/beacon,
 *   bypassing page cache entirely. No config needed.
 *
 * Strategy B — Cache exclusion rules (optional):
 *   We can tell caching plugins not to cache pages when the tracking
 *   nonce cookie is present, but this defeats caching benefits.
 *
 * Strategy C — REST endpoint exclusion:
 *   Ensure the /wp-milner-stats/v1/track REST endpoint is never cached.
 *   This class adds the necessary headers and exclusion hints.
 *
 * @package Milner_Stats
 */

defined( 'ABSPATH' ) || exit;

class WMS_Compatibility {

	public static function init() {
		// Add no-cache headers to the tracking REST endpoint
		add_filter( 'rest_pre_serve_request', [ __CLASS__, 'nocache_tracking_endpoint' ], 10, 4 );

		// Tell WP Rocket not to cache the REST tracking call
		add_filter( 'rocket_cache_reject_uri', [ __CLASS__, 'rocket_exclude_rest' ] );

		// W3 Total Cache: exclude REST endpoint
		add_filter( 'w3tc_pgcache_reject_uri', [ __CLASS__, 'w3tc_exclude_rest' ] );

		// LiteSpeed Cache: set no-cache for tracking endpoint response
		add_action( 'rest_api_init', [ __CLASS__, 'litespeed_nocache' ] );

		// WP Super Cache: mark tracking endpoint as uncacheable
		add_filter( 'wp_super_cache_query_strings', [ __CLASS__, 'supercache_passthrough' ] );

		// Autoptimize: don't touch our tracker script
		add_filter( 'autoptimize_filter_js_exclude', [ __CLASS__, 'autoptimize_exclude_tracker' ] );
	}

	// ── WP Rocket ─────────────────────────────────────────────────────────

	/**
	 * Exclude the REST tracking endpoint from WP Rocket's page cache.
	 */
	public static function rocket_exclude_rest( array $uris ): array {
		$uris[] = '/wp-json/wp-milner-stats/';
		return $uris;
	}

	// ── W3 Total Cache ─────────────────────────────────────────────────────

	public static function w3tc_exclude_rest( array $uris ): array {
		$uris[] = '\/wp-json\/wp-milner-stats\/';
		return $uris;
	}

	// ── LiteSpeed Cache ────────────────────────────────────────────────────

	public static function litespeed_nocache() {
		// Tell LiteSpeed not to cache REST API responses from our namespace
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( strpos( $request_uri, 'wp-milner-stats' ) !== false ) {
			do_action( 'litespeed_control_set_nocache', 'WSC tracking endpoint' );
		}
	}

	// ── WP Super Cache ─────────────────────────────────────────────────────

	/**
	 * Add our REST slug as a query string that bypasses WP Super Cache.
	 */
	public static function supercache_passthrough( array $strings ): array {
		$strings[] = 'wms_track';
		return $strings;
	}

	// ── Autoptimize ────────────────────────────────────────────────────────

	/**
	 * Prevent Autoptimize from deferring/moving our tracker script.
	 * (It uses requestIdleCallback so it's already non-blocking, but
	 * Autoptimize can sometimes break script ordering.)
	 */
	public static function autoptimize_exclude_tracker( string $excluded ): string {
		return $excluded . ', wp-milner-stats/public/js/tracker.js';
	}

	// ── REST endpoint no-cache headers ────────────────────────────────────

	/**
	 * Add Cache-Control: no-store to the tracking POST endpoint response.
	 * This prevents any intermediate proxy from caching a 200 response
	 * to the track endpoint, which could cause missed view counts.
	 */
	public static function nocache_tracking_endpoint( $served, $result, WP_REST_Request $request, WP_REST_Server $server ): bool {
		if ( '/wp-milner-stats/v1/track' === $request->get_route()
			&& 'POST' === $request->get_method() ) {
			header( 'Cache-Control: no-store, no-cache, must-revalidate' );
			header( 'Pragma: no-cache' );
		}
		return $served;
	}
}
