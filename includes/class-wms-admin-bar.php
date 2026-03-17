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
			         . '<span class="ab-label wms-ab-label-wrap">'
			         . esc_html__( 'Stats', 'wp-milner-stats' )
			         . self::sparkline_svg()
			         . '</span>',
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

		/* ── Admin bar sparkline ──────────────────────────────────── */
		.wms-ab-label-wrap {
			display: inline-flex;
			align-items: center;
			gap: 5px;
			line-height: 1;
		}
		.wms-ab-sparkline {
			display: inline-block;
			vertical-align: middle;
			overflow: visible;
			flex-shrink: 0;
			margin-top: -1px;
		}
		.wms-spark-line {
			fill: none;
			stroke: #72aee6;
			stroke-width: 1.5;
			stroke-linecap: round;
			stroke-linejoin: round;
		}
		.wms-spark-area {
			fill: rgba(114,174,230,0.22);
			stroke: none;
		}
		.wms-spark-dot {
			fill: #72aee6;
			stroke: none;
		}
		/* Brighten on hover of root node */
		#wp-admin-bar-wms-stats:hover .wms-spark-line { stroke: #fff; }
		#wp-admin-bar-wms-stats:hover .wms-spark-area { fill: rgba(255,255,255,0.18); }
		#wp-admin-bar-wms-stats:hover .wms-spark-dot  { fill: #fff; }
		</style>
		<?php
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Build an inline SVG sparkline showing the last 7 days of sitewide views.
	 *
	 * The sparkline is rendered entirely server-side — no JS, no extra HTTP
	 * requests. Values are fetched from the same cached query used by the
	 * dashboard, so there is no meaningful performance cost.
	 *
	 * @return string  Safe HTML string containing the <svg> element.
	 */
	private static function sparkline_svg(): string {
		// Fetch last 7 days of sitewide daily views (cached, 5 min TTL).
		$daily = WMS_Query::get_daily_views( 7 );

		if ( empty( $daily ) ) {
			return '';
		}

		$values = array_column( $daily, 'views' );
		$max    = max( $values );

		// Dimensions (px) — sized to sit neatly beside the "Stats" label at 28px bar height.
		$w      = 38;
		$h      = 16;
		$pad_x  = 1;   // horizontal breathing room
		$pad_y  = 2;   // vertical breathing room (keeps line off the edges)

		$n      = count( $values );
		$x_step = ( $w - $pad_x * 2 ) / max( 1, $n - 1 );
		$y_range = $h - $pad_y * 2;

		// Build the polyline points string.
		$points = [];
		foreach ( $values as $i => $v ) {
			$x = $pad_x + $i * $x_step;
			// Invert Y: SVG 0 is top, so high values produce small Y.
			$y = $max > 0
				? $pad_y + $y_range - ( $v / $max ) * $y_range
				: $pad_y + $y_range; // flat line when all zeros
			$points[] = round( $x, 2 ) . ',' . round( $y, 2 );
		}
		$points_str = implode( ' ', $points );

		// Build a filled area path: line down right edge, along bottom, back to start.
		$last_x  = round( $pad_x + ( $n - 1 ) * $x_step, 2 );
		$base_y  = $h - $pad_y + 1;
		$first_x = round( (float) $pad_x, 2 );

		$area_d = 'M ' . $points[0]
		        . ' L ' . implode( ' L ', array_slice( $points, 1 ) )
		        . " L {$last_x},{$base_y} L {$first_x},{$base_y} Z";

		// Highlight the last data point with a small dot.
		$last_parts = explode( ',', end( $points ) );
		$dot_cx     = $last_parts[0];
		$dot_cy     = $last_parts[1];

		$svg = sprintf(
			'<svg class="wms-ab-sparkline" xmlns="http://www.w3.org/2000/svg"'
			. ' width="%d" height="%d" viewBox="0 0 %d %d"'
			. ' aria-hidden="true" focusable="false">'
			. '<path class="wms-spark-area" d="%s" />'
			. '<polyline class="wms-spark-line" points="%s" />'
			. '<circle class="wms-spark-dot" cx="%s" cy="%s" r="1.5" />'
			. '</svg>',
			$w, $h, $w, $h,
			esc_attr( $area_d ),
			esc_attr( $points_str ),
			esc_attr( $dot_cx ),
			esc_attr( $dot_cy )
		);

		return $svg;
	}

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
