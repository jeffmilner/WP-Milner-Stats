<?php
/**
 * Core view-tracking logic.
 *
 * @package WP_Milner_Stats
 */

defined( 'ABSPATH' ) || exit;

class WMS_Tracker {

	private static function get_dedup_window(): int {
		$minutes = (int) get_option( 'wms_dedup_window', 30 );
		return max( 5, $minutes ) * 60;
	}

	/**
	 * Record a page view. Returns 'recorded', 'duplicate', or 'skipped'.
	 *
	 * Referrers are recorded on EVERY call that passes bot/skip checks,
	 * including duplicate views — because the referrer tells us how someone
	 * arrived, which is valuable even if the view itself is deduplicated.
	 * Referrer recording uses its own lightweight dedup (1 per referrer+post
	 * per day) to avoid inflating referral counts.
	 */
	public static function record(
		int $post_id,
		string $ip_address,
		string $user_agent,
		string $referrer_url = ''
	): string {

		// 1. Validate post exists and is published
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return 'skipped';
		}

		// 2. Check tracked post types
		$tracked = get_option( 'wms_track_post_types', [ 'post', 'page' ] );
		if ( ! in_array( $post->post_type, (array) $tracked, true ) ) {
			return 'skipped';
		}

		// 3. Skip privileged users if configured
		$skip_admin = (bool) get_option( 'wms_skip_admin_views', true );
		if ( apply_filters( 'wms_skip_admin_views', $skip_admin ) && self::is_privileged_user() ) {
			return 'skipped';
		}

		// 4. Skip bots
		if ( self::is_bot( $user_agent ) ) {
			return 'skipped';
		}

		$ip_hash      = self::hash_ip( $ip_address );
		$dedup_window = self::get_dedup_window();
		$is_duplicate = self::is_duplicate( $post_id, $ip_hash, $dedup_window );

		$view_id = 0;
		$result  = 'duplicate';

		if ( ! $is_duplicate ) {
			// 5. Determine visitor status for this post
			$visitor_window = apply_filters( 'wms_visitor_window_seconds', DAY_IN_SECONDS );
			$is_new_visitor = self::is_new_visitor( $post_id, $ip_hash, $visitor_window );

			// 6. Insert view record
			$view_id = self::insert_view( $post_id, $ip_hash, $user_agent, $is_new_visitor );

			if ( ! $view_id ) {
				return 'skipped';
			}

			// 7. Set dedup transient to prevent counting the same view twice
			$transient_key = 'wms_v_' . $post_id . '_' . substr( $ip_hash, 0, 16 );
			set_transient( $transient_key, 1, $dedup_window );

			$result = 'recorded';
		}

		// 8. Record referrer — done regardless of dedup status.
		// We use a separate per-referrer dedup (24h window) so the same visitor
		// arriving from the same referrer doesn't inflate counts, but new referrers
		// from that session are still captured.
		if ( ! empty( $referrer_url ) ) {
			self::maybe_record_referrer( $view_id, $post_id, $ip_hash, $referrer_url );
		}

		return $result;
	}

	// ── Private helpers ────────────────────────────────────────────────────

	private static function hash_ip( string $ip ): string {
		$salt = defined( 'LOGGED_IN_SALT' ) ? LOGGED_IN_SALT : 'wms-fallback-salt';
		return hash( 'sha256', $ip . $salt );
	}

	private static function is_duplicate( int $post_id, string $ip_hash, int $window ): bool {
		$transient_key = 'wms_v_' . $post_id . '_' . substr( $ip_hash, 0, 16 );

		if ( false !== get_transient( $transient_key ) ) {
			return true;
		}

		global $wpdb;
		$table  = $wpdb->prefix . WMS_TABLE_NAME;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $window );

		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$table} WHERE post_id = %d AND ip_hash = %s AND viewed_at > %s LIMIT 1",
			$post_id, $ip_hash, $cutoff
		) );

		if ( $exists ) {
			set_transient( $transient_key, 1, $window );
			return true;
		}

		return false;
	}

	private static function is_new_visitor( int $post_id, string $ip_hash, int $window ): bool {
		$transient_key = 'wms_vis_' . $post_id . '_' . substr( $ip_hash, 0, 16 );

		if ( false !== get_transient( $transient_key ) ) {
			return false;
		}

		global $wpdb;
		$table  = $wpdb->prefix . WMS_TABLE_NAME;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $window );

		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$table} WHERE post_id = %d AND ip_hash = %s AND viewed_at > %s LIMIT 1",
			$post_id, $ip_hash, $cutoff
		) );

		set_transient( $transient_key, 1, $window );
		return ! $exists;
	}

	private static function insert_view( int $post_id, string $ip_hash, string $user_agent, bool $is_new_visitor ): int {
		global $wpdb;

		$result = $wpdb->insert(
			$wpdb->prefix . WMS_TABLE_NAME,
			[
				'post_id'        => $post_id,
				'viewed_at'      => current_time( 'mysql', true ),
				'ip_hash'        => $ip_hash,
				'user_agent'     => substr( $user_agent, 0, 255 ),
				'is_new_visitor' => $is_new_visitor ? 1 : 0,
			],
			[ '%d', '%s', '%s', '%s', '%d' ]
		);

		return $result ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Record a referrer, with its own 24-hour dedup per (ip_hash + post_id + referrer_host).
	 * This means:
	 *   - The same visitor arriving from the same referrer domain on the same day = 1 record
	 *   - The same visitor arriving from a *different* referrer = new record
	 *   - Works even when the page view itself was a duplicate (returning visitor)
	 *
	 * @param int    $view_id      The inserted view ID, or 0 if view was a duplicate
	 * @param int    $post_id
	 * @param string $ip_hash
	 * @param string $referrer_url Raw referrer URL (already sanitized)
	 */
	private static function maybe_record_referrer( int $view_id, int $post_id, string $ip_hash, string $referrer_url ): void {
		$referrer_url = trim( $referrer_url );
		if ( empty( $referrer_url ) ) {
			return;
		}

		// Only store http/https referrers
		$scheme = (string) wp_parse_url( $referrer_url, PHP_URL_SCHEME );
		if ( ! in_array( strtolower( $scheme ), [ 'http', 'https' ], true ) ) {
			return;
		}

		// Parse host
		$parsed        = wp_parse_url( $referrer_url );
		$referrer_host = strtolower( $parsed['host'] ?? '' );

		if ( empty( $referrer_host ) ) {
			return;
		}

		// Skip self-referrals (including www. variants)
		$site_host  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$bare_ref   = preg_replace( '/^www\./', '', $referrer_host );
		$bare_site  = preg_replace( '/^www\./', '', $site_host );
		if ( $bare_ref === $bare_site ) {
			return;
		}

		// Per-referrer dedup: same visitor, same post, same referrer domain, same day
		$ref_transient = 'wms_r_' . $post_id . '_' . substr( $ip_hash, 0, 12 ) . '_' . substr( md5( $referrer_host ), 0, 8 );
		if ( false !== get_transient( $ref_transient ) ) {
			return;
		}
		set_transient( $ref_transient, 1, DAY_IN_SECONDS );

		// Strip fragments safely (avoid strtok's stateful behaviour)
		$hash_pos     = strpos( $referrer_url, '#' );
		if ( $hash_pos !== false ) {
			$referrer_url = substr( $referrer_url, 0, $hash_pos );
		}
		$referrer_url = substr( $referrer_url, 0, 2048 );

		$search_term = self::extract_search_term( $referrer_url, $referrer_host );

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . WMS_REFERRERS_TABLE,
			[
				'view_id'       => $view_id,
				'post_id'       => $post_id,
				'viewed_at'     => current_time( 'mysql', true ),
				'referrer_url'  => $referrer_url,
				'referrer_host' => substr( $referrer_host, 0, 255 ),
				'search_term'   => substr( $search_term, 0, 512 ),
			],
			[ '%d', '%d', '%s', '%s', '%s', '%s' ]
		);

		if ( $wpdb->last_error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions
			error_log( 'WMS referrer insert error: ' . $wpdb->last_error );
		}
	}

	/**
	 * Extract a search term from a referrer URL.
	 * Handles Google, Bing, DuckDuckGo, Yahoo, Ecosia, Yandex, Baidu, Naver, Ask, AOL.
	 */
	public static function extract_search_term( string $url, string $host = '' ): string {
		if ( empty( $host ) ) {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		}

		$search_engines = apply_filters( 'wms_search_engine_params', [
			'google'     => 'q',
			'bing'       => 'q',
			'yahoo'      => 'p',
			'duckduckgo' => 'q',
			'ecosia'     => 'q',
			'yandex'     => 'text',
			'baidu'      => 'wd',
			'naver'      => 'query',
			'ask'        => 'q',
			'aol'        => 'q',
		] );

		foreach ( $search_engines as $engine => $param ) {
			if ( strpos( $host, $engine ) !== false ) {
				$query_string = (string) wp_parse_url( $url, PHP_URL_QUERY );
				wp_parse_str( $query_string, $params );
				return sanitize_text_field( $params[ $param ] ?? '' );
			}
		}

		return '';
	}

	private static function is_privileged_user(): bool {
		return is_user_logged_in() && current_user_can( 'edit_posts' );
	}

	private static function is_bot( string $user_agent ): bool {
		if ( empty( $user_agent ) ) {
			return true;
		}

		$patterns = apply_filters( 'wms_bot_patterns', [
			'bot', 'crawl', 'spider', 'slurp', 'wget', 'curl',
			'facebookexternalhit', 'Twitterbot', 'LinkedInBot',
			'WhatsApp', 'Googlebot', 'bingbot', 'YandexBot',
			'DuckDuckBot', 'Baiduspider', 'Sogou', 'Exabot',
			'AhrefsBot', 'SemrushBot', 'MJ12bot', 'DotBot',
		] );

		$ua_lower = strtolower( $user_agent );
		foreach ( $patterns as $pattern ) {
			if ( strpos( $ua_lower, strtolower( $pattern ) ) !== false ) {
				return true;
			}
		}

		return false;
	}
}
