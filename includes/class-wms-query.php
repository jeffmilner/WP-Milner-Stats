<?php
/**
 * Database query helpers for fetching view statistics.
 *
 * All public methods return structured data ready for the UI.
 * Queries are written defensively with proper caching.
 *
 * @package WP_Milner_Stats
 */

defined( 'ABSPATH' ) || exit;

class WMS_Query {

	/**
	 * Valid time period identifiers.
	 */
	const PERIODS = [ 'day', 'week', 'month', 'year' ];

	/**
	 * Cache group for object cache.
	 */
	const CACHE_GROUP = 'wms_stats';

	// ── Public API ────────────────────────────────────────────────────────

	/**
	 * Get the top N posts for a given time period.
	 *
	 * @param  string $period  'day' | 'week' | 'month' | 'year'
	 * @param  int    $limit   Number of posts to return (default 10)
	 * @return array           Array of objects: { post_id, views, post_title, post_type, permalink }
	 */
	public static function get_top_posts( string $period = 'day', int $limit = 10 ): array {
		if ( ! in_array( $period, self::PERIODS, true ) ) {
			return [];
		}

		$limit     = max( 1, min( 500, $limit ) );
		$cache_key = "top_posts_{$period}_{$limit}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table  = $wpdb->prefix . WMS_TABLE_NAME;
		$cutoff = self::cutoff_datetime( $period );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT   v.post_id,
				          COUNT(*) AS views
				 FROM     {$table} v
				 WHERE    v.viewed_at >= %s
				 GROUP BY v.post_id
				 ORDER BY views DESC
				 LIMIT    %d",
				$cutoff,
				$limit
			)
		);

		if ( empty( $results ) ) {
			wp_cache_set( $cache_key, [], self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
			return [];
		}

		// Enrich results with post meta
		$enriched = [];
		foreach ( $results as $row ) {
			$post = get_post( (int) $row->post_id );
			if ( ! $post ) {
				continue;
			}
			$enriched[] = (object) [
				'post_id'    => (int) $row->post_id,
				'views'      => (int) $row->views,
				'post_title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'post_type'  => $post->post_type,
				'permalink'  => get_permalink( $post ),
				'edit_url'   => get_edit_post_link( $post->ID, 'raw' ),
				'thumbnail'  => get_the_post_thumbnail_url( $post, 'thumbnail' ) ?: '',
			];
		}

		wp_cache_set( $cache_key, $enriched, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
		return $enriched;
	}

	/**
	 * Get summary counts for all periods at once.
	 *
	 * @return array { day: int, week: int, month: int, year: int, total: int }
	 */
	public static function get_summary_counts(): array {
		$cache_key = 'summary_counts';
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table = $wpdb->prefix . WMS_TABLE_NAME;

		$counts = [];
		foreach ( self::PERIODS as $period ) {
			$cutoff         = self::cutoff_datetime( $period );
			$counts[$period] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE viewed_at >= %s",
					$cutoff
				)
			);
		}

		// All-time total
		$counts['total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		wp_cache_set( $cache_key, $counts, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
		return $counts;
	}

	/**
	 * Get hourly view counts for the last 24 hours.
	 * Buckets are in the site's local timezone so "9 AM" means 9 AM local, not UTC.
	 *
	 * @return array Array of { label: string, views: int } — 24 items
	 */
	public static function get_hourly_views(): array {
		$cache_key = 'hourly_views';
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table  = $wpdb->prefix . WMS_TABLE_NAME;
		$tz     = wp_timezone();
		$cutoff = ( new \DateTimeImmutable( '-24 hours', $tz ) )
			->setTimezone( new \DateTimeZone( 'UTC' ) )
			->format( 'Y-m-d H:i:s' );
		$tz_off = self::mysql_tz_offset();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT   DATE_FORMAT( CONVERT_TZ(viewed_at, '+00:00', %s), '%%Y-%%m-%%d %%H:00:00' ) AS hour,
				          COUNT(*) AS views
				 FROM     {$table}
				 WHERE    viewed_at >= %s
				 GROUP BY hour
				 ORDER BY hour ASC",
				$tz_off,
				$cutoff
			)
		);

		// Build slots in local time
		$data = [];
		$now  = new \DateTimeImmutable( 'now', $tz );
		for ( $i = 23; $i >= 0; $i-- ) {
			$key        = $now->modify( "-{$i} hours" )->format( 'Y-m-d H:00:00' );
			$data[$key] = 0;
		}
		foreach ( $rows as $row ) {
			if ( isset( $data[ $row->hour ] ) ) {
				$data[ $row->hour ] = (int) $row->views;
			}
		}

		$result = [];
		foreach ( $data as $hour_local => $views ) {
			$result[] = [
				'label' => self::format_local_label( 'g A', $hour_local ),
				'views' => $views,
			];
		}

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
		return $result;
	}

	/**
	 * Get daily view counts for the past N days.
	 * Days are bucketed in the site's local timezone.
	 *
	 * @param  int    $days
	 * @return array  Array of { label: string, views: int }
	 */
	public static function get_daily_views( int $days = 30 ): array {
		$cache_key = "daily_views_{$days}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table  = $wpdb->prefix . WMS_TABLE_NAME;
		$tz     = wp_timezone();
		$cutoff = ( new \DateTimeImmutable( "-{$days} days", $tz ) )
			->setTimezone( new \DateTimeZone( 'UTC' ) )
			->format( 'Y-m-d H:i:s' );
		$tz_off = self::mysql_tz_offset();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT   DATE( CONVERT_TZ(viewed_at, '+00:00', %s) ) AS day,
				          COUNT(*) AS views
				 FROM     {$table}
				 WHERE    viewed_at >= %s
				 GROUP BY day
				 ORDER BY day ASC",
				$tz_off,
				$cutoff
			)
		);

		// Build complete slot array in local dates
		$data = [];
		$now  = new \DateTimeImmutable( 'today', $tz );
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$key        = $now->modify( "-{$i} days" )->format( 'Y-m-d' );
			$data[$key] = 0;
		}
		foreach ( $rows as $row ) {
			if ( isset( $data[ $row->day ] ) ) {
				$data[ $row->day ] = (int) $row->views;
			}
		}

		$result = [];
		foreach ( $data as $day => $views ) {
			$result[] = [
				'label' => self::format_local_label( 'M j', $day . ' 00:00:00' ),
				'views' => $views,
			];
		}

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
		return $result;
	}

	/**
	 * Get monthly view counts for large date ranges (5yr, 10yr, all-time).
	 * Month boundaries are in the site's local timezone.
	 *
	 * @param  int  $days  Number of days to look back. 0 = all-time (no cutoff).
	 * @return array       Array of { label: string, views: int }
	 */
	public static function get_monthly_views( int $days = 1825 ): array {
		$cache_key = 'monthly_views_' . $days;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table  = $wpdb->prefix . WMS_TABLE_NAME;
		$tz     = wp_timezone();
		$tz_off = self::mysql_tz_offset();

		if ( $days > 0 ) {
			$cutoff = ( new \DateTimeImmutable( "-{$days} days", $tz ) )
				->setTimezone( new \DateTimeZone( 'UTC' ) )
				->format( 'Y-m-d H:i:s' );
			$rows   = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT   DATE_FORMAT( CONVERT_TZ(viewed_at, '+00:00', %s), '%%Y-%%m-01' ) AS month,
					          COUNT(*) AS views
					 FROM     {$table}
					 WHERE    viewed_at >= %s
					 GROUP BY month
					 ORDER BY month ASC",
					$tz_off,
					$cutoff
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT   DATE_FORMAT( CONVERT_TZ(viewed_at, '+00:00', %s), '%%Y-%%m-01' ) AS month,
					          COUNT(*) AS views
					 FROM     {$table}
					 GROUP BY month
					 ORDER BY month ASC",
					$tz_off
				)
			);
		}

		if ( empty( $rows ) ) {
			wp_cache_set( $cache_key, [], self::CACHE_GROUP, 15 * MINUTE_IN_SECONDS );
			return [];
		}

		// Build complete month-slot array filling gaps with 0
		$first_month = $rows[0]->month;
		$last_month  = gmdate( 'Y-m-01' );

		$data   = [];
		$cursor = strtotime( $first_month );
		$end    = strtotime( $last_month );

		while ( $cursor <= $end ) {
			$key          = gmdate( 'Y-m-01', $cursor );
			$data[ $key ] = 0;
			$cursor       = mktime( 0, 0, 0, (int) gmdate( 'n', $cursor ) + 1, 1, (int) gmdate( 'Y', $cursor ) );
		}

		foreach ( $rows as $row ) {
			if ( isset( $data[ $row->month ] ) ) {
				$data[ $row->month ] = (int) $row->views;
			}
		}

		$result = [];
		foreach ( $data as $month => $views ) {
			$result[] = [
				'label' => self::format_local_label( 'M Y', $month . ' 00:00:00' ),
				'views' => $views,
			];
		}

		$ttl = $days === 0 ? 30 * MINUTE_IN_SECONDS : 15 * MINUTE_IN_SECONDS;
		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, $ttl );
		return $result;
	}

	/**
	 * Get daily view counts for a specific post over the last N days.
	 * Used by the editor meta box sparkline. Days in site's local timezone.
	 *
	 * @param  int $post_id
	 * @param  int $days
	 * @return array Array of { label: string, views: int }
	 */
	public static function get_daily_views_for_post( int $post_id, int $days = 14 ): array {
		global $wpdb;
		$table  = $wpdb->prefix . WMS_TABLE_NAME;
		$tz     = wp_timezone();
		$cutoff = ( new \DateTimeImmutable( "-{$days} days", $tz ) )
			->setTimezone( new \DateTimeZone( 'UTC' ) )
			->format( 'Y-m-d H:i:s' );
		$tz_off = self::mysql_tz_offset();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT   DATE( CONVERT_TZ(viewed_at, '+00:00', %s) ) AS day,
				          COUNT(*) AS views
				 FROM     {$table}
				 WHERE    post_id   = %d
				   AND    viewed_at >= %s
				 GROUP BY day
				 ORDER BY day ASC",
				$tz_off,
				$post_id,
				$cutoff
			)
		);

		$data = [];
		$now  = new \DateTimeImmutable( 'today', $tz );
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$key        = $now->modify( "-{$i} days" )->format( 'Y-m-d' );
			$data[$key] = 0;
		}
		foreach ( $rows as $row ) {
			if ( isset( $data[ $row->day ] ) ) {
				$data[ $row->day ] = (int) $row->views;
			}
		}

		$result = [];
		foreach ( $data as $day => $views ) {
			$result[] = [
				'label' => self::format_local_label( 'M j', $day . ' 00:00:00' ),
				'views' => $views,
			];
		}
		return $result;
	}

	/**
	 * Get total views for a specific post across all periods.
	 *
	 * @param  int  $post_id
	 * @return array { day: int, week: int, month: int, year: int, total: int }
	 */
	public static function get_post_counts( int $post_id ): array {
		global $wpdb;
		$table  = $wpdb->prefix . WMS_TABLE_NAME;
		$counts = [];

		foreach ( self::PERIODS as $period ) {
			$cutoff          = self::cutoff_datetime( $period );
			$counts[$period] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table}
					 WHERE post_id = %d AND viewed_at >= %s",
					$post_id,
					$cutoff
				)
			);
		}

		$counts['total'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE post_id = %d",
				$post_id
			)
		);

		return $counts;
	}

	/**
	 * Get the number of unique posts viewed in a period.
	 *
	 * @param  string $period
	 * @return int
	 */
	public static function get_unique_posts_viewed( string $period = 'day' ): int {
		global $wpdb;
		$table  = $wpdb->prefix . WMS_TABLE_NAME;
		$cutoff = self::cutoff_datetime( $period );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$table} WHERE viewed_at >= %s",
				$cutoff
			)
		);
	}

	// ── Private helpers ────────────────────────────────────────────────────

	/**
	 * Convert a period string to a UTC datetime string for WHERE clauses.
	 *
	 * Uses the WordPress site timezone (Settings > General > Timezone) so that
	 * "Last 24 Hours" is calculated relative to local time, not server UTC time.
	 *
	 * @param  string $period  'day' | 'week' | 'month' | 'year'
	 * @return string          UTC datetime, e.g. '2025-03-07 05:00:00'
	 */
	private static function cutoff_datetime( string $period ): string {
		$intervals = [
			'day'   => '-1 day',
			'week'  => '-7 days',
			'month' => '-30 days',
			'year'  => '-365 days',
		];
		$interval = $intervals[ $period ] ?? '-1 day';

		// Compute cutoff in the site's local timezone, then convert to UTC for the query
		$tz    = wp_timezone();
		$local = new \DateTimeImmutable( $interval, $tz );
		$utc   = $local->setTimezone( new \DateTimeZone( 'UTC' ) );
		return $utc->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Build the MySQL CONVERT_TZ offset string from the current WP timezone.
	 * Uses the actual DateTimeImmutable offset to respect DST.
	 */
	private static function mysql_tz_offset(): string {
		$tz             = wp_timezone();
		$now            = new \DateTimeImmutable( 'now', $tz );
		$offset_seconds = $now->getOffset();
		$sign           = $offset_seconds >= 0 ? '+' : '-';
		$abs            = abs( $offset_seconds );
		return sprintf( '%s%02d:%02d', $sign, (int) floor( $abs / 3600 ), (int) ( ( $abs % 3600 ) / 60 ) );
	}

	/**
	 * Format a local-time datetime string as a chart label.
	 *
	 * The string is already in local time — we must NOT use strtotime() on it
	 * because strtotime() assumes UTC on most servers, producing a wrong timestamp.
	 * Instead we parse it with DateTimeImmutable in the site timezone, then use
	 * wp_date() for locale-aware formatting, then html_entity_decode() to prevent
	 * HTML entities (e.g. &#8211;) from appearing literally in Chart.js canvas labels.
	 *
	 * @param  string $format             PHP date format, e.g. 'g A', 'M j', 'M Y'
	 * @param  string $local_datetime_str Datetime string already in site's local timezone
	 * @return string                     Plain-text label safe for chart display
	 */
	private static function format_local_label( string $format, string $local_datetime_str ): string {
		$tz = wp_timezone();
		$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $local_datetime_str, $tz );

		if ( ! $dt ) {
			$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d', $local_datetime_str, $tz );
		}

		if ( ! $dt ) {
			return gmdate( $format, strtotime( $local_datetime_str ) );
		}

		$label = wp_date( $format, $dt->getTimestamp() );
		return html_entity_decode( $label, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
