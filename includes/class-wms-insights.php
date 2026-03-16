<?php
/**
 * Insights: views vs visitors separation (Jetpack-style).
 *
 * "Views"    = total page view events (every request that passes dedup)
 * "Visitors" = unique visitors per day (is_new_visitor = 1 rows)
 *
 * Timezone: viewed_at is stored in UTC. Chart labels and period cutoffs
 * are converted to the WordPress site timezone (Settings > General > Timezone)
 * so counts and labels match what the site owner expects.
 *
 * @package WP_Milner_Stats
 */

defined( 'ABSPATH' ) || exit;

class WMS_Insights {

	const CACHE_GROUP = 'wms_stats';

	// ── Timezone helpers ───────────────────────────────────────────────────

	/**
	 * Get the site's UTC offset string suitable for MySQL CONVERT_TZ().
	 * e.g. '+05:30', '-07:00', '+00:00'
	 *
	 * NOTE: Uses gmt_offset (a float, e.g. -6, 5.5) NOT timezone_string,
	 * because CONVERT_TZ requires a numeric offset, not a named zone.
	 * For sites using DST-aware named zones, wp_timezone() handles DST correctly
	 * when we compute the offset from the actual current instant.
	 */
	private static function mysql_tz_offset(): string {
		// Derive offset from the WP timezone object at the current moment,
		// so DST is respected for sites using named timezones (America/New_York etc.)
		$tz             = wp_timezone();
		$now            = new \DateTimeImmutable( 'now', $tz );
		$offset_seconds = $now->getOffset(); // seconds east of UTC, signed
		$sign           = $offset_seconds >= 0 ? '+' : '-';
		$abs            = abs( $offset_seconds );
		return sprintf( '%s%02d:%02d', $sign, (int) floor( $abs / 3600 ), (int) ( ( $abs % 3600 ) / 60 ) );
	}

	/**
	 * Build a UTC cutoff datetime from a local interval string.
	 * e.g. utc_cutoff('-1 day') returns the UTC equivalent of "1 day ago in local time".
	 */
	private static function utc_cutoff( string $interval ): string {
		$tz    = wp_timezone();
		$local = new \DateTimeImmutable( $interval, $tz );
		return $local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Format a LOCAL-time datetime string into a chart label.
	 *
	 * CRITICAL: $local_datetime_str is already in local time (e.g. "2026-03-15 10:00:00").
	 * We must NOT pass it through strtotime() without attaching the timezone, because
	 * strtotime() assumes UTC on most WordPress servers, shifting the time by the UTC offset.
	 *
	 * We use DateTimeImmutable::createFromFormat() with the site timezone to get the
	 * correct Unix timestamp, then wp_date() to format it — which also handles locale
	 * month/day name translation correctly.
	 *
	 * We then run the result through html_entity_decode() because date_i18n() (called
	 * internally by wp_date()) can produce HTML entities like &#8211; in some locales.
	 * These entities would render as literal text in Chart.js canvas labels.
	 *
	 * @param  string $format              PHP date format string, e.g. 'g A', 'M j', 'M Y'
	 * @param  string $local_datetime_str  A datetime string already in the site's local timezone
	 * @return string                      Plain-text label, entities decoded, e.g. "10 AM", "Mar 15"
	 */
	private static function format_local_label( string $format, string $local_datetime_str ): string {
		$tz  = wp_timezone();
		$dt  = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $local_datetime_str, $tz );

		if ( ! $dt ) {
			// Fallback for date-only strings like "2026-03-15"
			$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d', $local_datetime_str, $tz );
		}

		if ( ! $dt ) {
			return gmdate( $format, strtotime( $local_datetime_str ) );
		}

		$timestamp = $dt->getTimestamp(); // correct Unix timestamp
		$label     = wp_date( $format, $timestamp );

		// wp_date() / date_i18n() may produce HTML entities in some locales.
		// Decode them so Chart.js canvas renders "Mar 15" not "Mar&#8201;15".
		return html_entity_decode( $label, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	// ── Views vs Visitors summary ──────────────────────────────────────────

	/**
	 * Get views + visitors for all standard periods at once (for summary cards).
	 *
	 * @return array { day: {views, visitors}, week: ..., month: ..., year: ..., total: {views, visitors} }
	 */
	public static function get_all_period_stats(): array {
		$cache_key = 'all_period_stats';
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table = $wpdb->prefix . WMS_TABLE_NAME;

		// Use local midnight boundaries so "Today" means today in the site timezone
		$periods = [
			'day'   => '-1 day',
			'week'  => '-7 days',
			'month' => '-30 days',
			'year'  => '-365 days',
		];

		$data = [];
		foreach ( $periods as $key => $interval ) {
			$cutoff = self::utc_cutoff( $interval );
			$row    = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(*) AS views, SUM(is_new_visitor) AS visitors
					 FROM   {$table} WHERE viewed_at >= %s",
					$cutoff
				)
			);
			$data[ $key ] = [
				'views'    => (int) ( $row->views    ?? 0 ),
				'visitors' => (int) ( $row->visitors ?? 0 ),
			];
		}

		// All-time totals (no timezone conversion needed — no cutoff)
		$row_all       = $wpdb->get_row(
			"SELECT COUNT(*) AS views, SUM(is_new_visitor) AS visitors FROM {$table}"
		);
		$data['total'] = [
			'views'    => (int) ( $row_all->views    ?? 0 ),
			'visitors' => (int) ( $row_all->visitors ?? 0 ),
		];

		wp_cache_set( $cache_key, $data, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
		return $data;
	}

	/**
	 * Get total views AND unique visitors for a given period.
	 *
	 * @param  string $period 'day' | 'week' | 'month' | 'year'
	 * @return array { views: int, visitors: int }
	 */
	public static function get_views_and_visitors( string $period = 'day' ): array {
		$cache_key = "views_visitors_{$period}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		$all   = self::get_all_period_stats();
		$data  = $all[ $period ] ?? [ 'views' => 0, 'visitors' => 0 ];

		wp_cache_set( $cache_key, $data, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
		return $data;
	}

	// ── Chart data methods ─────────────────────────────────────────────────

	/**
	 * Get hourly views AND visitors for the 24h chart.
	 * Buckets are grouped and labelled in the site's local timezone.
	 *
	 * @return array [ { label: string, views: int, visitors: int } ]
	 */
	public static function get_hourly_views_visitors(): array {
		$cache_key = 'hourly_views_visitors';
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table  = $wpdb->prefix . WMS_TABLE_NAME;
		$cutoff = self::utc_cutoff( '-24 hours' );
		$tz_off = self::mysql_tz_offset();

		// Convert the UTC viewed_at to local time before DATE_FORMAT so buckets
		// align with local hours rather than UTC hours.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT   DATE_FORMAT( CONVERT_TZ(viewed_at, '+00:00', %s), '%%Y-%%m-%%d %%H:00:00' ) AS hour,
				          COUNT(*)             AS views,
				          SUM(is_new_visitor)  AS visitors
				 FROM     {$table}
				 WHERE    viewed_at >= %s
				 GROUP BY hour
				 ORDER BY hour ASC",
				$tz_off,
				$cutoff
			)
		);

		// Build a complete 24-slot array in local time, filling gaps with 0
		$data = [];
		$tz   = wp_timezone();
		$now  = new \DateTimeImmutable( 'now', $tz );
		for ( $i = 23; $i >= 0; $i-- ) {
			$slot_local = $now->modify( "-{$i} hours" );
			$key        = $slot_local->format( 'Y-m-d H:00:00' );
			$data[$key] = [ 'views' => 0, 'visitors' => 0 ];
		}
		foreach ( $rows as $row ) {
			if ( isset( $data[ $row->hour ] ) ) {
				$data[ $row->hour ] = [
					'views'    => (int) $row->views,
					'visitors' => (int) $row->visitors,
				];
			}
		}

		$result = [];
		foreach ( $data as $hour_local => $counts ) {
			$result[] = [
				// $hour_local is already in local time — use format_local_label()
				// which correctly converts it via DateTimeImmutable (not strtotime)
				'label'    => self::format_local_label( 'g A', $hour_local ),
				'views'    => $counts['views'],
				'visitors' => $counts['visitors'],
			];
		}

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
		return $result;
	}

	/**
	 * Get daily views AND visitors for a chart (dual-line, like Jetpack).
	 * Days are in the site's local timezone.
	 *
	 * @param  int $days
	 * @return array [ { label: string, views: int, visitors: int } ]
	 */
	public static function get_daily_views_visitors( int $days = 30 ): array {
		$cache_key = "daily_views_visitors_{$days}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table  = $wpdb->prefix . WMS_TABLE_NAME;
		$cutoff = self::utc_cutoff( "-{$days} days" );
		$tz_off = self::mysql_tz_offset();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT   DATE( CONVERT_TZ(viewed_at, '+00:00', %s) ) AS day,
				          COUNT(*)              AS views,
				          SUM(is_new_visitor)   AS visitors
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
		$tz   = wp_timezone();
		$now  = new \DateTimeImmutable( 'today', $tz );
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$key        = $now->modify( "-{$i} days" )->format( 'Y-m-d' );
			$data[$key] = [ 'views' => 0, 'visitors' => 0 ];
		}
		foreach ( $rows as $row ) {
			if ( isset( $data[ $row->day ] ) ) {
				$data[ $row->day ] = [
					'views'    => (int) $row->views,
					'visitors' => (int) $row->visitors,
				];
			}
		}

		$result = [];
		foreach ( $data as $day => $counts ) {
			$result[] = [
				'label'    => self::format_local_label( 'M j', $day . ' 00:00:00' ),
				'views'    => $counts['views'],
				'visitors' => $counts['visitors'],
			];
		}

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
		return $result;
	}

	/**
	 * Get monthly views AND visitors for multi-year ranges.
	 * Month labels use the site's local timezone for the month boundary.
	 *
	 * @param  int $days  0 = all-time
	 * @return array [ { label, views, visitors } ]
	 */
	public static function get_monthly_views_visitors( int $days = 1825 ): array {
		$cache_key = "monthly_views_visitors_{$days}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$table  = $wpdb->prefix . WMS_TABLE_NAME;
		$tz_off = self::mysql_tz_offset();

		if ( $days > 0 ) {
			$cutoff = self::utc_cutoff( "-{$days} days" );
			$rows   = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT   DATE_FORMAT( CONVERT_TZ(viewed_at, '+00:00', %s), '%%Y-%%m-01' ) AS month,
					          COUNT(*)             AS views,
					          SUM(is_new_visitor)  AS visitors
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
					          COUNT(*) AS views, SUM(is_new_visitor) AS visitors
					 FROM     {$table}
					 GROUP    BY month ORDER BY month ASC",
					$tz_off
				)
			);
		}

		if ( empty( $rows ) ) {
			wp_cache_set( $cache_key, [], self::CACHE_GROUP, 15 * MINUTE_IN_SECONDS );
			return [];
		}

		// Fill month slots from first data month to current month
		$data   = [];
		$cursor = strtotime( $rows[0]->month );
		$end    = strtotime( gmdate( 'Y-m-01' ) );
		while ( $cursor <= $end ) {
			$key        = gmdate( 'Y-m-01', $cursor );
			$data[$key] = [ 'views' => 0, 'visitors' => 0 ];
			$cursor     = mktime( 0, 0, 0, (int) gmdate( 'n', $cursor ) + 1, 1, (int) gmdate( 'Y', $cursor ) );
		}
		foreach ( $rows as $row ) {
			if ( isset( $data[ $row->month ] ) ) {
				$data[ $row->month ] = [
					'views'    => (int) $row->views,
					'visitors' => (int) $row->visitors,
				];
			}
		}

		$result = [];
		foreach ( $data as $month => $counts ) {
			$result[] = [
				'label'    => self::format_local_label( 'M Y', $month . ' 00:00:00' ),
				'views'    => $counts['views'],
				'visitors' => $counts['visitors'],
			];
		}

		$ttl = $days === 0 ? 30 * MINUTE_IN_SECONDS : 15 * MINUTE_IN_SECONDS;
		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, $ttl );
		return $result;
	}
}
