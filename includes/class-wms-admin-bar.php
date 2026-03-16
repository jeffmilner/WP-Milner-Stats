<?php
/**
 * Admin Bar Integration
 *
 * Adds a "Stats" node to the WordPress admin toolbar showing:
 *  - View count for the current singular post/page (when viewing one)
 *  - A quick link to the full stats dashboard
 *
 * Only visible to users with manage_options capability.
 *
 * @package WP_Milner_Stats
 */

defined( 'ABSPATH' ) || exit;

class WMS_Admin_Bar {

	public static function init() {
		add_action( 'admin_bar_menu', [ __CLASS__, 'add_node' ], 100 );
		add_action( 'wp_head',        [ __CLASS__, 'inline_styles' ] );
		add_action( 'admin_head',     [ __CLASS__, 'inline_styles' ] );
	}

	// ── Admin bar node ─────────────────────────────────────────────────────

	public static function add_node( WP_Admin_Bar $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$dashboard_url = admin_url( 'admin.php?page=wp-milner-stats' );
		$post_id       = self::get_current_post_id();

		// ── Root node ──────────────────────────────────────────────────────
		$wp_admin_bar->add_node( [
			'id'    => 'wms-stats',
			'title' => '<span class="ab-icon dashicons dashicons-chart-bar" style="top:2px"></span>'
			         . '<span class="ab-label">' . esc_html__( 'Stats', 'wp-milner-stats' ) . '</span>',
			'href'  => $dashboard_url,
			'meta'  => [ 'title' => __( 'Milner Stats Dashboard', 'wp-milner-stats' ) ],
		] );

		// ── Sub-node: full dashboard link ──────────────────────────────────
		$wp_admin_bar->add_node( [
			'parent' => 'wms-stats',
			'id'     => 'wms-stats-dashboard',
			'title'  => esc_html__( 'View Full Dashboard', 'wp-milner-stats' ),
			'href'   => $dashboard_url,
		] );

		// ── Sub-node: current post stats (singular views only) ─────────────
		if ( $post_id ) {
			$counts = WMS_Query::get_post_counts( $post_id );

			$wp_admin_bar->add_node( [
				'parent' => 'wms-stats',
				'id'     => 'wms-stats-divider',
				'title'  => '<hr style="margin:4px 0;border-color:#555;opacity:.4">',
				'href'   => false,
			] );

			$wp_admin_bar->add_node( [
				'parent' => 'wms-stats',
				'id'     => 'wms-stats-heading',
				'title'  => '<span style="opacity:.6;font-size:10px;text-transform:uppercase;letter-spacing:.05em;">'
				          . esc_html__( 'This Post', 'wp-milner-stats' )
				          . '</span>',
				'href'   => false,
			] );

			$items = [
				'wms-stats-today' => [
					'label' => __( 'Today',      'wp-milner-stats' ),
					'value' => $counts['day'],
				],
				'wms-stats-week'  => [
					'label' => __( 'This Week',  'wp-milner-stats' ),
					'value' => $counts['week'],
				],
				'wms-stats-month' => [
					'label' => __( 'This Month', 'wp-milner-stats' ),
					'value' => $counts['month'],
				],
				'wms-stats-total' => [
					'label' => __( 'All Time',   'wp-milner-stats' ),
					'value' => $counts['total'],
				],
			];

			foreach ( $items as $node_id => $item ) {
				$wp_admin_bar->add_node( [
					'parent' => 'wms-stats',
					'id'     => $node_id,
					'title'  => sprintf(
						'<span class="wms-ab-stat">'
						. '<span class="wms-ab-stat__label">%s</span>'
						. '<span class="wms-ab-stat__value">%s</span>'
						. '</span>',
						esc_html( $item['label'] ),
						esc_html( number_format_i18n( $item['value'] ) )
					),
					'href'   => false,
				] );
			}

			// Trending indicator
			$trend = WMS_Trending::get_post_trend( $post_id );
			if ( $trend['is_trending'] ) {
				$wp_admin_bar->add_node( [
					'parent' => 'wms-stats',
					'id'     => 'wms-stats-trending',
					'title'  => '🔥 ' . sprintf(
						/* translators: %s: trend score multiplier */
						esc_html__( 'Trending! (%s× normal)', 'wp-milner-stats' ),
						esc_html( number_format_i18n( $trend['trend_score'], 1 ) )
					),
					'href'   => false,
				] );
			}
		}
	}

	// ── Styles ─────────────────────────────────────────────────────────────

	public static function inline_styles() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		?>
		<style>
		#wp-admin-bar-wms-stats .ab-icon:before { content: ''; }
		#wp-admin-bar-wms-stats-heading > .ab-item,
		#wp-admin-bar-wms-stats-divider > .ab-item {
			pointer-events: none;
			cursor: default;
		}
		.wms-ab-stat {
			display: flex;
			justify-content: space-between;
			align-items: center;
			gap: 16px;
			min-width: 160px;
		}
		.wms-ab-stat__label { opacity: .8; }
		.wms-ab-stat__value {
			font-weight: 700;
			font-variant-numeric: tabular-nums;
		}
		</style>
		<?php
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Get the current post ID in both frontend and admin contexts.
	 */
	private static function get_current_post_id(): int {
		// Frontend singular view
		if ( ! is_admin() && is_singular() ) {
			return (int) get_the_ID();
		}

		// Admin edit screen
		if ( is_admin() ) {
			$screen = get_current_screen();
			if ( $screen && in_array( $screen->base, [ 'post', 'page' ], true ) ) {
				return (int) ( $_GET['post'] ?? $_POST['post_ID'] ?? 0 ); // phpcs:ignore
			}
		}

		return 0;
	}
}
