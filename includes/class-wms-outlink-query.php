<?php
/**
 * Database query helpers for outbound link click tracking.
 *
 * @package Milner_Stats
 */

defined( 'ABSPATH' ) || exit;

class WMS_Outlink_Query {

	const CACHE_GROUP = 'wms_stats';

	// ── Public API ─────────────────────────────────────────────────────────

	/**
	 * Get top clicked outbound links for a time period.
	 *
	 * @param  string $period  'day' | 'week' | 'month' | 'year'
	 * @param  int    $limit
	 * @return array  [ { link_url, link_host, link_text, clicks } ]
	 */
	public static function get_top_outlinks( string $period = 'day', int $limit = 20 ): array {
		$cache_key = "top_outlinks_{$period}_{$limit}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table  = $wpdb->prefix . WMS_OUTLINKS_TABLE;
		$cutoff = self::cutoff( $period );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- result is cached via wp_cache_get/set in this method.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT   link_url,
				          link_host,
				          link_text,
				          COUNT(*) AS clicks
				 FROM     {$table}
				 WHERE    clicked_at >= %s
				 GROUP BY link_url
				 ORDER BY clicks DESC
				 LIMIT    %d",
				$cutoff,
				$limit
			)
		);

		$data = array_map( function( $r ) {
			return (object) [
				'link_url'  => $r->link_url,
				'link_host' => $r->link_host,
				'link_text' => $r->link_text,
				'clicks'    => (int) $r->clicks,
			];
		}, $results ?: [] );

		wp_cache_set( $cache_key, $data, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
		return $data;
	}

	/**
	 * Get top clicked outbound domains (grouped by host).
	 *
	 * @param  string $period
	 * @param  int    $limit
	 * @return array  [ { link_host, clicks, unique_urls } ]
	 */
	public static function get_top_outlink_domains( string $period = 'day', int $limit = 20 ): array {
		$cache_key = "top_outlink_domains_{$period}_{$limit}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table  = $wpdb->prefix . WMS_OUTLINKS_TABLE;
		$cutoff = self::cutoff( $period );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- result is cached via wp_cache_get/set in this method.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT   link_host,
				          COUNT(*)            AS clicks,
				          COUNT(DISTINCT link_url) AS unique_urls
				 FROM     {$table}
				 WHERE    clicked_at >= %s
				   AND    link_host != ''
				 GROUP BY link_host
				 ORDER BY clicks DESC
				 LIMIT    %d",
				$cutoff,
				$limit
			)
		);

		$data = array_map( function( $r ) {
			return (object) [
				'link_host'   => $r->link_host,
				'clicks'      => (int) $r->clicks,
				'unique_urls' => (int) $r->unique_urls,
			];
		}, $results ?: [] );

		wp_cache_set( $cache_key, $data, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
		return $data;
	}

	/**
	 * Get total outlink click count for a period.
	 *
	 * @param  string $period
	 * @return int
	 */
	public static function get_total_clicks( string $period = 'day' ): int {
		global $wpdb;
		$table  = $wpdb->prefix . WMS_OUTLINKS_TABLE;
		$cutoff = self::cutoff( $period );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- result is cached via wp_cache_get/set in this method.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix + plugin constant, not user input; MySQL does not support table names as prepare() placeholders.
				"SELECT COUNT(*) FROM {$table} WHERE clicked_at >= %s",
				$cutoff
			)
		);
	}

	/**
	 * Record an outbound link click.
	 *
	 * @param  int    $post_id
	 * @param  string $link_url   Full destination URL
	 * @param  string $link_text  Anchor text (optional)
	 * @param  string $ip_hash    Already-hashed IP
	 * @return bool
	 */
	public static function record_click(
		int $post_id,
		string $link_url,
		string $link_text,
		string $ip_hash
	): bool {
		global $wpdb;

		// Validate it's a real http/https URL before storing
		$link_url = trim( $link_url );
		if ( ! filter_var( $link_url, FILTER_VALIDATE_URL ) ) {
			return false;
		}
		if ( ! in_array( wp_parse_url( $link_url, PHP_URL_SCHEME ), [ 'http', 'https' ], true ) ) {
			return false;
		}

		$link_url  = substr( $link_url, 0, 2048 );
		$parsed    = wp_parse_url( $link_url );
		$link_host = strtolower( $parsed['host'] ?? '' );

		// Don't record clicks to the same domain
		$site_host = strtolower( wp_parse_url( home_url(), PHP_URL_HOST ) ?? '' );
		if ( $link_host === $site_host || empty( $link_host ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- result is cached via wp_cache_get/set in this method.
		$result = $wpdb->insert(
			$wpdb->prefix . WMS_OUTLINKS_TABLE,
			[
				'post_id'    => $post_id,
				'clicked_at' => current_time( 'mysql', true ),
				'link_url'   => $link_url,
				'link_host'  => substr( $link_host, 0, 255 ),
				'link_text'  => substr( sanitize_text_field( $link_text ), 0, 255 ),
				'ip_hash'    => $ip_hash,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		return (bool) $result;
	}

	// ── Private ────────────────────────────────────────────────────────────

	private static function cutoff( string $period ): string {
		$map = [
			'day'   => '-1 day',
			'week'  => '-7 days',
			'month' => '-30 days',
			'year'  => '-365 days',
		];
		$interval = $map[ $period ] ?? '-1 day';
		$tz       = wp_timezone();
		$local    = new \DateTimeImmutable( $interval, $tz );
		return $local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}
