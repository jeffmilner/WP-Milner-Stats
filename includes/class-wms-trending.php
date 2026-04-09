<?php
/**
 * Trending Posts Detection
 *
 * Identifies posts that are gaining views faster than their historical average.
 * "Trending" = views in the last 24h are significantly above the post's daily average
 * over the past 30 days.
 *
 * This is similar to Jetpack's "trending" feature — it's not just "most viewed today"
 * but rather "most accelerating today relative to its own baseline."
 *
 * Algorithm:
 *   trend_score = views_last_24h / max(1, avg_daily_views_last_30d)
 *   Posts with a score > threshold (default 2.0) are "trending"
 *
 * @package Milner_Stats
 */

defined( 'ABSPATH' ) || exit;

class WMS_Trending {

	/** Minimum views in the last 24h to even consider a post trending. */
	const MIN_VIEWS_TODAY = 3;

	/** Score multiplier above which a post is "trending". */
	const TREND_THRESHOLD = 2.0;

	/** Cache duration in seconds. */
	const CACHE_TTL = 10 * MINUTE_IN_SECONDS;

	// ── Public API ─────────────────────────────────────────────────────────

	/**
	 * Get trending posts.
	 *
	 * @param  int   $limit      Max posts to return (default 10)
	 * @param  float $threshold  Trend score threshold (default 2.0)
	 * @return array             Array of objects with extra 'trend_score' and 'avg_daily' properties
	 */
	public static function get_trending( int $limit = 10, float $threshold = self::TREND_THRESHOLD ): array {
		$cache_key = 'wms_trending_' . $limit;
		$cached    = wp_cache_get( $cache_key, 'wms_stats' );
		if ( false !== $cached ) {
			return $cached;
		}

		$candidates = self::get_candidates();
		if ( empty( $candidates ) ) {
			wp_cache_set( $cache_key, [], 'wms_stats', self::CACHE_TTL );
			return [];
		}

		// Enrich with post meta and calculate trend scores
		$trending = [];
		foreach ( $candidates as $row ) {
			$avg_daily   = (float) $row->avg_daily;
			$views_today = (int)   $row->views_today;

			$score = $views_today / max( 1.0, $avg_daily );

			if ( $score < $threshold || $views_today < self::MIN_VIEWS_TODAY ) {
				continue;
			}

			$post = get_post( (int) $row->post_id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}

			$trending[] = (object) [
				'post_id'     => (int) $row->post_id,
				'views_today' => $views_today,
				'avg_daily'   => round( $avg_daily, 1 ),
				'trend_score' => round( $score, 2 ),
				'post_title'  => html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'post_type'   => $post->post_type,
				'permalink'   => get_permalink( $post ),
				'edit_url'    => get_edit_post_link( $post->ID, 'raw' ),
				'thumbnail'   => get_the_post_thumbnail_url( $post, 'thumbnail' ) ?: '',
			];
		}

		// Sort by trend score descending
		usort( $trending, fn( $a, $b ) => $b->trend_score <=> $a->trend_score );
		$trending = array_slice( $trending, 0, $limit );

		wp_cache_set( $cache_key, $trending, 'wms_stats', self::CACHE_TTL );
		return $trending;
	}

	/**
	 * Get trend data for a single post.
	 *
	 * @param  int $post_id
	 * @return array { views_today, avg_daily, trend_score, is_trending }
	 */
	public static function get_post_trend( int $post_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . WMS_TABLE_NAME;

		$tz         = wp_timezone();
		$cutoff_24h = ( new \DateTimeImmutable( '-1 day',   $tz ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$cutoff_30d = ( new \DateTimeImmutable( '-30 days', $tz ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- result is cached via wp_cache_get/set in this method.
		$views_today = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix + plugin constant, not user input; MySQL does not support table names as prepare() placeholders.
				"SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND viewed_at >= %s",
				$post_id, $cutoff_24h
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- result is cached via wp_cache_get/set in this method.
		$views_30d = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix + plugin constant, not user input; MySQL does not support table names as prepare() placeholders.
				"SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND viewed_at >= %s",
				$post_id, $cutoff_30d
			)
		);

		$avg_daily   = $views_30d / 30.0;
		$trend_score = $views_today / max( 1.0, $avg_daily );

		return [
			'views_today' => $views_today,
			'avg_daily'   => round( $avg_daily, 1 ),
			'trend_score' => round( $trend_score, 2 ),
			'is_trending' => $trend_score >= self::TREND_THRESHOLD && $views_today >= self::MIN_VIEWS_TODAY,
		];
	}

	// ── Private ────────────────────────────────────────────────────────────

	/**
	 * Query posts that have views today AND a 30-day history to compare against.
	 * Returns raw DB rows with post_id, views_today, avg_daily.
	 */
	private static function get_candidates(): array {
		global $wpdb;
		$table = $wpdb->prefix . WMS_TABLE_NAME;

		$tz         = wp_timezone();
		$cutoff_24h = ( new \DateTimeImmutable( '-1 day',   $tz ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$cutoff_30d = ( new \DateTimeImmutable( '-30 days', $tz ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

		/*
		 * Sub-query: count views in the last 24h per post
		 * Outer join: count views in the last 30d to derive the daily avg
		 * This runs as a single query — efficient with the (post_id, viewed_at) index
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- result is cached via wp_cache_get/set in this method.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
				    today.post_id,
				    today.views_today,
				    COALESCE( hist.views_30d, 0 ) / 30.0 AS avg_daily
				 FROM (
				     SELECT post_id, COUNT(*) AS views_today
				     FROM   {$table}
				     WHERE  viewed_at >= %s
				     GROUP  BY post_id
				 ) AS today
				 LEFT JOIN (
				     SELECT post_id, COUNT(*) AS views_30d
				     FROM   {$table}
				     WHERE  viewed_at >= %s
				     GROUP  BY post_id
				 ) AS hist USING (post_id)
				 HAVING views_today >= %d
				 ORDER BY (today.views_today / GREATEST(1, COALESCE(hist.views_30d, 0) / 30.0)) DESC
				 LIMIT 50",
				$cutoff_24h,
				$cutoff_30d,
				self::MIN_VIEWS_TODAY
			)
		);
	}
}
