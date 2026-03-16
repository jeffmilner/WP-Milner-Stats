<?php
/**
 * REST API endpoints for WP Milner Stats.
 *
 * Tracking (public, nonce-gated):
 *   POST /wp-milner-stats/v1/track           — Record a page view + referrer
 *   POST /wp-milner-stats/v1/track-outlink   — Record an outbound link click
 *
 * Stats (admin-only):
 *   GET  /wp-milner-stats/v1/stats                  — Summary counts (views + visitors)
 *   GET  /wp-milner-stats/v1/stats/posts            — Top posts by period
 *   GET  /wp-milner-stats/v1/stats/chart            — Time-series chart (views + visitors)
 *   GET  /wp-milner-stats/v1/stats/post/{id}        — Per-post counts + chart
 *   GET  /wp-milner-stats/v1/stats/trending         — Trending posts
 *   GET  /wp-milner-stats/v1/stats/referrers        — Top referrer domains
 *   GET  /wp-milner-stats/v1/stats/search-terms     — Top search terms
 *   GET  /wp-milner-stats/v1/stats/outlinks         — Top outbound link clicks
 *   POST /wp-milner-stats/v1/cache/flush            — Flush stats cache
 *
 * @package WP_Milner_Stats
 */

defined( 'ABSPATH' ) || exit;

class WMS_REST_API {

	const NAMESPACE = 'wp-milner-stats/v1';

	public function register_routes() {
		add_action( 'rest_api_init', [ $this, 'register' ] );
	}

	public function register() {

		// ── Page view tracking ─────────────────────────────────────────────
		register_rest_route( self::NAMESPACE, '/track', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_track' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'post_id' => [
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'validate_callback' => fn( $v ) => $v > 0,
				],
				'referrer' => [
					'required'          => false,
					'type'              => 'string',
					'default'           => '',
					// Use sanitize_text_field not esc_url_raw — esc_url_raw strips
					// query strings and special chars, destroying referrer URLs like
					// https://www.google.com/search?q=something before we can store them.
					// We sanitise more carefully in WMS_Tracker::record_referrer().
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );

		// ── Outbound link click tracking ───────────────────────────────────
		register_rest_route( self::NAMESPACE, '/track-outlink', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_track_outlink' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'post_id' => [
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'validate_callback' => fn( $v ) => $v > 0,
				],
				'link_url' => [
					'required'          => true,
					'type'              => 'string',
					// sanitize_text_field preserves the full URL including query strings.
					// esc_url_raw would strip params and break the stored URL.
					// We validate it is a real external URL inside record_click().
					'sanitize_callback' => 'sanitize_text_field',
				],
				'link_text' => [
					'required'          => false,
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );

		// ── Summary stats ──────────────────────────────────────────────────
		register_rest_route( self::NAMESPACE, '/stats', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_summary' ],
			'permission_callback' => [ $this, 'admin_permissions' ],
		] );

		// ── Top posts ──────────────────────────────────────────────────────
		register_rest_route( self::NAMESPACE, '/stats/posts', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_top_posts' ],
			'permission_callback' => [ $this, 'admin_permissions' ],
			'args'                => [
				'period' => [
					'default'           => 'day',
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => fn( $v ) => in_array( $v, WMS_Query::PERIODS, true ),
				],
				'limit' => [
					'default'           => 10,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'validate_callback' => fn( $v ) => $v >= 1 && $v <= 500,
				],
			],
		] );

		// ── Activity chart ─────────────────────────────────────────────────
		register_rest_route( self::NAMESPACE, '/stats/chart', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_chart' ],
			'permission_callback' => [ $this, 'admin_permissions' ],
			'args'                => [
				'range' => [
					'default'           => '24h',
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => fn( $v ) => in_array( $v, [ '24h', '7', '30', '365', '1825', '3650', 'all' ], true ),
				],
			],
		] );

		// ── Per-post stats ─────────────────────────────────────────────────
		register_rest_route( self::NAMESPACE, '/stats/post/(?P<id>[\d]+)', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_post_stats' ],
			'permission_callback' => [ $this, 'admin_permissions' ],
			'args'                => [
				'id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
			],
		] );

		// ── Trending posts ─────────────────────────────────────────────────
		register_rest_route( self::NAMESPACE, '/stats/trending', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_trending' ],
			'permission_callback' => [ $this, 'admin_permissions' ],
			'args'                => [
				'limit' => [
					'default'           => 10,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'validate_callback' => fn( $v ) => $v >= 1 && $v <= 50,
				],
			],
		] );

		// ── Referrers ──────────────────────────────────────────────────────
		register_rest_route( self::NAMESPACE, '/stats/referrers', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_referrers' ],
			'permission_callback' => [ $this, 'admin_permissions' ],
			'args'                => [
				'period' => [
					'default'           => 'day',
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => fn( $v ) => in_array( $v, WMS_Query::PERIODS, true ),
				],
			],
		] );

		// ── Search terms ───────────────────────────────────────────────────
		register_rest_route( self::NAMESPACE, '/stats/search-terms', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_search_terms' ],
			'permission_callback' => [ $this, 'admin_permissions' ],
			'args'                => [
				'period' => [
					'default'           => 'day',
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => fn( $v ) => in_array( $v, WMS_Query::PERIODS, true ),
				],
			],
		] );

		// ── Outbound links ─────────────────────────────────────────────────
		register_rest_route( self::NAMESPACE, '/stats/outlinks', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'handle_outlinks' ],
			'permission_callback' => [ $this, 'admin_permissions' ],
			'args'                => [
				'period' => [
					'default'           => 'day',
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => fn( $v ) => in_array( $v, WMS_Query::PERIODS, true ),
				],
			],
		] );

		// ── Cache flush ────────────────────────────────────────────────────
		register_rest_route( self::NAMESPACE, '/cache/flush', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_flush_cache' ],
			'permission_callback' => [ $this, 'admin_permissions' ],
		] );
	}

	// ── Callbacks ─────────────────────────────────────────────────────────

	/** POST /track */
	public function handle_track( WP_REST_Request $request ): WP_REST_Response {
		$post_id    = (int) $request->get_param( 'post_id' );
		$ip_address = $this->get_client_ip();
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';

		// Prefer the referrer passed explicitly from JS (document.referrer),
		// fall back to the HTTP Referer header sent by the browser.
		// Use sanitize_text_field (not esc_url_raw) so query strings are preserved —
		// esc_url_raw strips params like ?q=search-term, destroying referrer data.
		$referrer = $request->get_param( 'referrer' ) ?: '';
		if ( empty( $referrer ) && isset( $_SERVER['HTTP_REFERER'] ) ) {
			$referrer = sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
		}

		$result = WMS_Tracker::record( $post_id, $ip_address, $user_agent, $referrer );

		return new WP_REST_Response( [ 'result' => $result ], 200 );
	}

	/** POST /track-outlink */
	public function handle_track_outlink( WP_REST_Request $request ): WP_REST_Response {
		$post_id   = (int) $request->get_param( 'post_id' );
		$link_url  = $request->get_param( 'link_url' );
		$link_text = $request->get_param( 'link_text' );
		$ip_hash   = hash( 'sha256',
			$this->get_client_ip() . ( defined( 'LOGGED_IN_SALT' ) ? LOGGED_IN_SALT : 'wms-salt' )
		);

		$recorded = WMS_Outlink_Query::record_click( $post_id, $link_url, $link_text, $ip_hash );

		return new WP_REST_Response( [ 'recorded' => $recorded ], 200 );
	}

	/** GET /stats — views + visitors summary */
	public function handle_summary( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( WMS_Insights::get_all_period_stats(), 200 );
	}

	/** GET /stats/posts */
	public function handle_top_posts( WP_REST_Request $request ): WP_REST_Response {
		$period = $request->get_param( 'period' );
		$limit  = (int) $request->get_param( 'limit' );
		return new WP_REST_Response( WMS_Query::get_top_posts( $period, $limit ), 200 );
	}

	/**
	 * GET /stats/chart
	 * Returns dual-series data: views + visitors per time bucket.
	 */
	public function handle_chart( WP_REST_Request $request ): WP_REST_Response {
		$range = $request->get_param( 'range' );

		if ( $range === '24h' ) {
			$data = WMS_Insights::get_hourly_views_visitors();
		} elseif ( $range === 'all' ) {
			$data = WMS_Insights::get_monthly_views_visitors( 0 );
		} elseif ( in_array( (int) $range, [ 1825, 3650 ], true ) ) {
			$data = WMS_Insights::get_monthly_views_visitors( (int) $range );
		} else {
			$days = in_array( (int) $range, [ 7, 30, 365 ], true ) ? (int) $range : 30;
			$data = WMS_Insights::get_daily_views_visitors( $days );
		}

		return new WP_REST_Response( $data, 200 );
	}

	/** GET /stats/post/{id} */
	public function handle_post_stats( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request->get_param( 'id' );
		return new WP_REST_Response( [
			'post_id' => $post_id,
			'counts'  => WMS_Query::get_post_counts( $post_id ),
			'chart'   => WMS_Query::get_daily_views_for_post( $post_id, 30 ),
		], 200 );
	}

	/** GET /stats/trending */
	public function handle_trending( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response(
			WMS_Trending::get_trending( (int) $request->get_param( 'limit' ) ),
			200
		);
	}

	/** GET /stats/referrers */
	public function handle_referrers( WP_REST_Request $request ): WP_REST_Response {
		$period = $request->get_param( 'period' );
		return new WP_REST_Response( [
			'summary'  => WMS_Referrer_Query::get_referrer_summary( $period ),
			'referrers' => WMS_Referrer_Query::get_top_referrers( $period, 20 ),
		], 200 );
	}

	/** GET /stats/search-terms */
	public function handle_search_terms( WP_REST_Request $request ): WP_REST_Response {
		$period = $request->get_param( 'period' );
		return new WP_REST_Response(
			WMS_Referrer_Query::get_top_search_terms( $period, 20 ),
			200
		);
	}

	/** GET /stats/outlinks */
	public function handle_outlinks( WP_REST_Request $request ): WP_REST_Response {
		$period = $request->get_param( 'period' );
		return new WP_REST_Response( [
			'total'   => WMS_Outlink_Query::get_total_clicks( $period ),
			'links'   => WMS_Outlink_Query::get_top_outlinks( $period, 20 ),
			'domains' => WMS_Outlink_Query::get_top_outlink_domains( $period, 20 ),
		], 200 );
	}

	/** POST /cache/flush */
	public function handle_flush_cache( WP_REST_Request $request ): WP_REST_Response {
		wp_cache_flush_group( 'wms_stats' );

		$keys = [
			'all_period_stats', 'hourly_views_visitors',
			'daily_views_visitors_7', 'daily_views_visitors_30', 'daily_views_visitors_365',
			'monthly_views_visitors_1825', 'monthly_views_visitors_3650', 'monthly_views_visitors_0',
			'top_referrers_day_20', 'top_referrers_week_20', 'top_referrers_month_20', 'top_referrers_year_20',
			'top_search_terms_day_20', 'top_search_terms_week_20',
			'top_outlinks_day_20', 'top_outlinks_week_20', 'top_outlinks_month_20', 'top_outlinks_year_20',
			'top_outlink_domains_day_20', 'top_posts_day_10', 'top_posts_week_10',
		];
		foreach ( $keys as $key ) {
			wp_cache_delete( $key, 'wms_stats' );
		}

		return new WP_REST_Response( [ 'flushed' => true ], 200 );
	}

	// ── Permissions ────────────────────────────────────────────────────────

	public function admin_permissions(): bool {
		return current_user_can( 'manage_options' );
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	private function get_client_ip(): string {
		$candidates = [
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		];
		foreach ( $candidates as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = trim( explode( ',', wp_unslash( $_SERVER[ $key ] ) )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '0.0.0.0';
	}
}
