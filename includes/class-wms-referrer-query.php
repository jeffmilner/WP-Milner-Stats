<?php
/**
 * Database query helpers for referrers and search terms.
 *
 * @package WP_Milner_Stats
 */

defined( 'ABSPATH' ) || exit;

class WMS_Referrer_Query {

	const CACHE_GROUP = 'wms_stats';

	// ── Public API ─────────────────────────────────────────────────────────

	/**
	 * Get top referrer domains for a time period.
	 *
	 * @param  string $period  'day' | 'week' | 'month' | 'year'
	 * @param  int    $limit
	 * @return array  [ { referrer_host, visits, search_term_count } ]
	 */
	public static function get_top_referrers( string $period = 'day', int $limit = 20 ): array {
		$cache_key = "top_referrers_{$period}_{$limit}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table  = $wpdb->prefix . WMS_REFERRERS_TABLE;
		$cutoff = self::cutoff( $period );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT   referrer_host,
				          COUNT(*)                                          AS visits,
				          SUM( CASE WHEN search_term != '' THEN 1 ELSE 0 END ) AS search_visits
				 FROM     {$table}
				 WHERE    viewed_at >= %s
				   AND    referrer_host != ''
				 GROUP BY referrer_host
				 ORDER BY visits DESC
				 LIMIT    %d",
				$cutoff,
				$limit
			)
		);

		$data = array_map( function( $r ) {
			return (object) [
				'referrer_host'   => $r->referrer_host,
				'visits'          => (int) $r->visits,
				'search_visits'   => (int) $r->search_visits,
			];
		}, $results ?: [] );

		wp_cache_set( $cache_key, $data, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
		return $data;
	}

	/**
	 * Get top search terms for a time period.
	 *
	 * @param  string $period
	 * @param  int    $limit
	 * @return array  [ { search_term, count } ]
	 */
	public static function get_top_search_terms( string $period = 'day', int $limit = 20 ): array {
		$cache_key = "top_search_terms_{$period}_{$limit}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table  = $wpdb->prefix . WMS_REFERRERS_TABLE;
		$cutoff = self::cutoff( $period );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT   search_term,
				          COUNT(*) AS count
				 FROM     {$table}
				 WHERE    viewed_at  >= %s
				   AND    search_term != ''
				 GROUP BY search_term
				 ORDER BY count DESC
				 LIMIT    %d",
				$cutoff,
				$limit
			)
		);

		$data = array_map( function( $r ) {
			return (object) [
				'search_term' => $r->search_term,
				'count'       => (int) $r->count,
			];
		}, $results ?: [] );

		wp_cache_set( $cache_key, $data, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
		return $data;
	}

	/**
	 * Get summary referrer counts: total referrals, search referrals, direct/unknown.
	 *
	 * @param  string $period
	 * @return array  { total_referrals, search_referrals, direct_views }
	 */
	public static function get_referrer_summary( string $period = 'day' ): array {
		$cache_key = "referrer_summary_{$period}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$ref_table   = $wpdb->prefix . WMS_REFERRERS_TABLE;
		$views_table = $wpdb->prefix . WMS_TABLE_NAME;
		$cutoff      = self::cutoff( $period );

		$total_referrals = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$ref_table} WHERE viewed_at >= %s AND referrer_host != ''",
				$cutoff
			)
		);

		$search_referrals = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$ref_table} WHERE viewed_at >= %s AND search_term != ''",
				$cutoff
			)
		);

		$total_views = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$views_table} WHERE viewed_at >= %s",
				$cutoff
			)
		);

		$data = [
			'total_referrals'   => $total_referrals,
			'search_referrals'  => $search_referrals,
			'direct_views'      => max( 0, $total_views - $total_referrals ),
		];

		wp_cache_set( $cache_key, $data, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
		return $data;
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
