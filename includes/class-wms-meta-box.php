<?php
/**
 * Adds a "Post Stats" meta box to the post editor.
 * Displays view counts broken down by period with a mini sparkline.
 *
 * Works with both the Classic Editor and Block Editor (Gutenberg).
 *
 * @package WP_Milner_Stats
 */

defined( 'ABSPATH' ) || exit;

class WMS_Meta_Box {

	public static function init() {
		add_action( 'add_meta_boxes', [ __CLASS__, 'register' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
	}

	// ── Registration ───────────────────────────────────────────────────────

	public static function register() {
		$tracked = get_option( 'wms_track_post_types', [ 'post', 'page' ] );

		foreach ( (array) $tracked as $post_type ) {
			add_meta_box(
				'wms_post_stats',
				__( 'Post Stats', 'wp-milner-stats' ),
				[ __CLASS__, 'render' ],
				$post_type,
				'side',      // Sidebar
				'default'
			);
		}
	}

	// ── Assets ─────────────────────────────────────────────────────────────

	public static function enqueue_assets( string $hook ) {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		// Lightweight Chart.js for the sparkline
		wp_enqueue_script(
			'chartjs',
			'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js',
			[],
			'4.4.3',
			true
		);

		wp_add_inline_script( 'chartjs', self::sparkline_js(), 'after' );
	}

	// ── Render ─────────────────────────────────────────────────────────────

	public static function render( WP_Post $post ) {
		// New posts have no views yet
		if ( 'auto-draft' === $post->post_status || 0 === $post->ID ) {
			echo '<p style="color:#888;font-size:13px;margin:0;">'
				. esc_html__( 'Stats will appear once this post is published.', 'wp-milner-stats' )
				. '</p>';
			return;
		}

		$counts     = WMS_Query::get_post_counts( $post->ID );
		$chart_data = WMS_Query::get_daily_views_for_post( $post->ID, 14 );

		$periods = [
			'day'   => __( 'Today',      'wp-milner-stats' ),
			'week'  => __( 'This Week',  'wp-milner-stats' ),
			'month' => __( 'This Month', 'wp-milner-stats' ),
			'year'  => __( 'This Year',  'wp-milner-stats' ),
		];
		?>
		<div class="wms-meta-box">

			<!-- Period breakdown -->
			<div class="wms-meta-grid">
				<?php foreach ( $periods as $key => $label ) : ?>
					<div class="wms-meta-item">
						<span class="wms-meta-value"><?php echo esc_html( number_format_i18n( $counts[ $key ] ?? 0 ) ); ?></span>
						<span class="wms-meta-label"><?php echo esc_html( $label ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<!-- All-time total -->
			<div class="wms-meta-total">
				<strong><?php echo esc_html( number_format_i18n( $counts['total'] ?? 0 ) ); ?></strong>
				<?php esc_html_e( 'total views', 'wp-milner-stats' ); ?>
			</div>

			<!-- 14-day sparkline -->
			<?php if ( array_sum( array_column( $chart_data, 'views' ) ) > 0 ) : ?>
				<div class="wms-meta-chart">
					<canvas id="wms-sparkline-<?php echo esc_attr( $post->ID ); ?>"
						height="50"
						data-views="<?php echo esc_attr( wp_json_encode( array_column( $chart_data, 'views' ) ) ); ?>"
						data-labels="<?php echo esc_attr( wp_json_encode( array_column( $chart_data, 'label' ) ) ); ?>">
					</canvas>
				</div>
			<?php else : ?>
				<p class="wms-meta-empty"><?php esc_html_e( 'No views in the last 14 days.', 'wp-milner-stats' ); ?></p>
			<?php endif; ?>

			<p class="wms-meta-link">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-milner-stats' ) ); ?>">
					<?php esc_html_e( 'View full stats →', 'wp-milner-stats' ); ?>
				</a>
			</p>

		</div>

		<style>
		.wms-meta-box { font-size: 13px; }
		.wms-meta-grid {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 8px;
			margin-bottom: 10px;
		}
		.wms-meta-item {
			background: #f6f7f7;
			border-radius: 5px;
			padding: 8px 10px;
			text-align: center;
		}
		.wms-meta-value {
			display: block;
			font-size: 18px;
			font-weight: 700;
			color: #1d2327;
			line-height: 1.1;
		}
		.wms-meta-label {
			display: block;
			font-size: 10px;
			color: #666;
			margin-top: 2px;
			text-transform: uppercase;
			letter-spacing: .04em;
		}
		.wms-meta-total {
			text-align: center;
			padding: 6px 0 10px;
			color: #444;
			border-bottom: 1px solid #eee;
			margin-bottom: 10px;
		}
		.wms-meta-total strong {
			font-size: 15px;
		}
		.wms-meta-chart {
			margin: 0 0 8px;
		}
		.wms-meta-empty {
			color: #888;
			font-style: italic;
			font-size: 12px;
			margin: 6px 0;
		}
		.wms-meta-link {
			margin: 8px 0 0;
			font-size: 12px;
		}
		.wms-meta-link a { text-decoration: none; color: #2271b1; }
		.wms-meta-link a:hover { text-decoration: underline; }
		</style>
		<?php
	}

	// ── Sparkline JS ───────────────────────────────────────────────────────

	private static function sparkline_js(): string {
		return "
(function() {
	document.addEventListener('DOMContentLoaded', function() {
		document.querySelectorAll('canvas[id^=\"wms-sparkline-\"]').forEach(function(canvas) {
			var views  = JSON.parse(canvas.dataset.views  || '[]');
			var labels = JSON.parse(canvas.dataset.labels || '[]');
			if (!views.length) return;

			var ctx = canvas.getContext('2d');
			new Chart(ctx, {
				type: 'bar',
				data: {
					labels: labels,
					datasets: [{
						data: views,
						backgroundColor: 'rgba(34,113,177,0.18)',
						borderColor:     'rgba(34,113,177,0.7)',
						borderWidth: 1,
						borderRadius: 2,
					}]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					animation: false,
					plugins: { legend: { display: false }, tooltip: {
						callbacks: {
							label: function(ctx) { return ctx.parsed.y + ' views'; }
						}
					}},
					scales: {
						x: { display: false },
						y: { display: false, beginAtZero: true }
					}
				}
			});
		});
	});
})();
";
	}
}
