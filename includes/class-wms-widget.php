<?php
/**
 * WordPress dashboard widget showing a mini stats summary.
 * WordPress dashboard widget showing a mini Milner Stats summary.
 *
 * @package WP_Milner_Stats
 */

defined( 'ABSPATH' ) || exit;

class WMS_Widget {

	public function register() {
		wp_add_dashboard_widget(
			'wms_dashboard_widget',
			__( 'Site Stats', 'wp-milner-stats' ),
			[ $this, 'render' ],
			null,
			null,
			'normal',
			'high'
		);
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			echo '<p>' . esc_html__( 'You do not have permission to view stats.', 'wp-milner-stats' ) . '</p>';
			return;
		}

		$summary  = WMS_Query::get_summary_counts();
		$top_week = WMS_Query::get_top_posts( 'week', 5 );
		?>
		<div class="wms-widget">

			<div class="wms-widget__counts">
				<?php
				$items = [
					__( 'Today',      'wp-milner-stats' ) => $summary['day']   ?? 0,
					__( 'This Week',  'wp-milner-stats' ) => $summary['week']  ?? 0,
					__( 'This Month', 'wp-milner-stats' ) => $summary['month'] ?? 0,
				];
				foreach ( $items as $label => $count ) :
					?>
					<div class="wms-widget__count-item">
						<span class="wms-widget__count-value"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
						<span class="wms-widget__count-label"><?php echo esc_html( $label ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<?php if ( ! empty( $top_week ) ) : ?>
				<h4 style="margin: 12px 0 6px; font-size: 12px; text-transform: uppercase; letter-spacing: .05em; color: #888;">
					<?php esc_html_e( 'Top Posts This Week', 'wp-milner-stats' ); ?>
				</h4>
				<ol class="wms-widget__top-posts">
					<?php foreach ( $top_week as $post ) : ?>
						<li>
							<a href="<?php echo esc_url( $post->permalink ); ?>" target="_blank" rel="noopener">
								<?php echo esc_html( wp_trim_words( $post->post_title, 8 ) ); ?>
							</a>
							<span class="wms-widget__post-views">
								<?php echo esc_html( number_format_i18n( $post->views ) ); ?>
							</span>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php else : ?>
				<p style="color:#888; font-style:italic; font-size:13px; margin-top:12px;">
					<?php esc_html_e( 'No views recorded this week yet.', 'wp-milner-stats' ); ?>
				</p>
			<?php endif; ?>

			<p style="margin-top:12px; border-top: 1px solid #eee; padding-top: 10px;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-milner-stats' ) ); ?>" class="button button-small">
					<?php esc_html_e( 'View Full Stats →', 'wp-milner-stats' ); ?>
				</a>
			</p>
		</div>

		<style>
		.wms-widget__counts {
			display: flex;
			gap: 12px;
			margin-bottom: 4px;
		}
		.wms-widget__count-item {
			flex: 1;
			background: #f9f9f9;
			border: 1px solid #e5e5e5;
			border-radius: 6px;
			padding: 10px 8px;
			text-align: center;
		}
		.wms-widget__count-value {
			display: block;
			font-size: 22px;
			font-weight: 700;
			color: #1e1e1e;
			line-height: 1.1;
		}
		.wms-widget__count-label {
			display: block;
			font-size: 11px;
			color: #777;
			margin-top: 3px;
		}
		.wms-widget__top-posts {
			margin: 0;
			padding: 0 0 0 18px;
		}
		.wms-widget__top-posts li {
			display: flex;
			justify-content: space-between;
			align-items: baseline;
			padding: 4px 0;
			font-size: 13px;
			border-bottom: 1px solid #f0f0f0;
		}
		.wms-widget__top-posts li:last-child { border-bottom: none; }
		.wms-widget__top-posts a {
			text-decoration: none;
			color: #1d2327;
			flex: 1;
			min-width: 0;
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}
		.wms-widget__top-posts a:hover { color: #2271b1; }
		.wms-widget__post-views {
			font-size: 12px;
			color: #888;
			font-weight: 600;
			margin-left: 8px;
			white-space: nowrap;
		}
		</style>
		<?php
	}
}
