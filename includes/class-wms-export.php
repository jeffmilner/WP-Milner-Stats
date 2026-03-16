<?php
/**
 * CSV export handler for WP Milner Stats.
 *
 * Adds an "Export" submenu page and handles the download request.
 * Exports: top posts by period, or raw daily view totals.
 *
 * @package WP_Milner_Stats
 */

defined( 'ABSPATH' ) || exit;

class WMS_Export {

	public static function init() {
		add_action( 'admin_menu',    [ __CLASS__, 'register_menu' ] );
		add_action( 'admin_init',    [ __CLASS__, 'handle_export' ] );
	}

	// ── Menu ───────────────────────────────────────────────────────────────

	public static function register_menu() {
		add_submenu_page(
			WMS_Admin::MENU_SLUG,
			__( 'Export Stats', 'wp-milner-stats' ),
			__( 'Export', 'wp-milner-stats' ),
			'manage_options',
			WMS_Admin::MENU_SLUG . '-export',
			[ __CLASS__, 'render_page' ]
		);
	}

	// ── Page ───────────────────────────────────────────────────────────────

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wp-milner-stats' ) );
		}
		?>
		<div class="wrap wms-wrap">
			<h1><?php esc_html_e( 'Export Stats', 'wp-milner-stats' ); ?></h1>
			<p><?php esc_html_e( 'Download your view statistics as a CSV file.', 'wp-milner-stats' ); ?></p>

			<table class="form-table">
				<!-- Export: Top Posts -->
				<tr>
					<th><?php esc_html_e( 'Top Posts', 'wp-milner-stats' ); ?></th>
					<td>
						<p class="description" style="margin-bottom:10px;">
							<?php esc_html_e( 'Export the most-viewed posts for a time period.', 'wp-milner-stats' ); ?>
						</p>
						<?php foreach ( [ 'day' => 'Last 24 Hours', 'week' => 'Last 7 Days', 'month' => 'Last 30 Days', 'year' => 'Last 365 Days' ] as $period => $label ) : ?>
							<a href="<?php echo esc_url( self::export_url( 'top_posts', [ 'period' => $period ] ) ); ?>"
							   class="button" style="margin-right:6px;margin-bottom:6px;">
								⬇ <?php echo esc_html( $label ); ?>
							</a>
						<?php endforeach; ?>
					</td>
				</tr>

				<!-- Export: Daily totals -->
				<tr>
					<th><?php esc_html_e( 'Daily Totals', 'wp-milner-stats' ); ?></th>
					<td>
						<p class="description" style="margin-bottom:10px;">
							<?php esc_html_e( 'Export total views per day across all posts.', 'wp-milner-stats' ); ?>
						</p>
						<?php foreach ( [ 30 => 'Last 30 Days', 90 => 'Last 90 Days', 365 => 'Last Year' ] as $days => $label ) : ?>
							<a href="<?php echo esc_url( self::export_url( 'daily_totals', [ 'days' => $days ] ) ); ?>"
							   class="button" style="margin-right:6px;margin-bottom:6px;">
								⬇ <?php echo esc_html( $label ); ?>
							</a>
						<?php endforeach; ?>
					</td>
				</tr>

				<!-- Export: All raw data -->
				<tr>
					<th><?php esc_html_e( 'Raw Data', 'wp-milner-stats' ); ?></th>
					<td>
						<p class="description" style="margin-bottom:10px;">
							<?php esc_html_e( 'Export all view records (one row per event). Large sites may produce very large files.', 'wp-milner-stats' ); ?>
						</p>
						<a href="<?php echo esc_url( self::export_url( 'raw' ) ); ?>"
						   class="button button-secondary">
							⬇ <?php esc_html_e( 'Export All Raw Records', 'wp-milner-stats' ); ?>
						</a>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	// ── Export handler (runs before headers are sent) ──────────────────────

	public static function handle_export() {
		if ( ! isset( $_GET['wms_export'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wp-milner-stats' ) );
		}
		if ( ! check_admin_referer( 'wms_export' ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'wp-milner-stats' ) );
		}

		$type = sanitize_key( $_GET['wms_export'] );

		switch ( $type ) {
			case 'top_posts':
				$period = sanitize_key( $_GET['period'] ?? 'week' );
				self::export_top_posts( $period );
				break;

			case 'daily_totals':
				$days = absint( $_GET['days'] ?? 30 );
				self::export_daily_totals( $days );
				break;

			case 'raw':
				self::export_raw();
				break;

			default:
				wp_die( esc_html__( 'Unknown export type.', 'wp-milner-stats' ) );
		}
		exit;
	}

	// ── Export generators ──────────────────────────────────────────────────

	private static function export_top_posts( string $period ) {
		$posts    = WMS_Query::get_top_posts( $period, 500 );
		$filename = 'wms-top-posts-' . $period . '-' . gmdate( 'Y-m-d' ) . '.csv';

		self::set_csv_headers( $filename );

		$out = fopen( 'php://output', 'w' );
		// BOM for Excel UTF-8 compatibility
		fputs( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, [ 'Rank', 'Post ID', 'Title', 'Post Type', 'Views', 'URL' ] );

		foreach ( $posts as $i => $post ) {
			fputcsv( $out, [
				$i + 1,
				$post->post_id,
				$post->post_title,
				$post->post_type,
				$post->views,
				$post->permalink,
			] );
		}
		fclose( $out );
	}

	private static function export_daily_totals( int $days ) {
		$data     = WMS_Query::get_daily_views( $days );
		$filename = 'wms-daily-totals-' . $days . 'd-' . gmdate( 'Y-m-d' ) . '.csv';

		self::set_csv_headers( $filename );

		$out = fopen( 'php://output', 'w' );
		fputs( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, [ 'Date', 'Total Views' ] );

		foreach ( $data as $row ) {
			fputcsv( $out, [ $row['label'], $row['views'] ] );
		}
		fclose( $out );
	}

	private static function export_raw() {
		global $wpdb;
		$table    = $wpdb->prefix . WMS_TABLE_NAME;
		$filename = 'wms-raw-export-' . gmdate( 'Y-m-d' ) . '.csv';

		self::set_csv_headers( $filename );

		$out = fopen( 'php://output', 'w' );
		fputs( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, [ 'ID', 'Post ID', 'Post Title', 'Viewed At (UTC)', 'IP Hash' ] );

		// Stream in batches to avoid memory exhaustion
		$offset = 0;
		$batch  = 500;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, post_id, viewed_at, ip_hash FROM {$table}
					 ORDER BY viewed_at DESC
					 LIMIT %d OFFSET %d",
					$batch,
					$offset
				)
			);

			foreach ( $rows as $row ) {
				$raw_title = get_the_title( (int) $row->post_id ) ?: '(deleted)';
				$title     = html_entity_decode( $raw_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				fputcsv( $out, [
					$row->id,
					$row->post_id,
					$title,
					$row->viewed_at,
					$row->ip_hash,
				] );
			}

			$offset += $batch;
		} while ( count( $rows ) === $batch );

		fclose( $out );
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	private static function set_csv_headers( string $filename ) {
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
	}

	private static function export_url( string $type, array $args = [] ): string {
		return wp_nonce_url(
			add_query_arg(
				array_merge( [ 'wms_export' => $type ], $args ),
				admin_url( 'admin.php' )
			),
			'wms_export'
		);
	}
}
