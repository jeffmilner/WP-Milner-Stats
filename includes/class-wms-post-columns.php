<?php
/**
 * Adds a "Views" column to the Posts, Pages, and any tracked post type list tables.
 * Shows counts for the current period (default: all-time).
 *
 * @package WP_Milner_Stats
 */

defined( 'ABSPATH' ) || exit;

class WMS_Post_Columns {

	/**
	 * Register hooks for all public post types.
	 */
	public static function init() {
		$tracked = self::get_tracked_post_types();

		foreach ( $tracked as $post_type ) {
			// Add column header
			add_filter( "manage_{$post_type}_posts_columns",       [ __CLASS__, 'add_column' ] );
			// Populate column cell
			add_action( "manage_{$post_type}_posts_custom_column", [ __CLASS__, 'render_column' ], 10, 2 );
			// Make column sortable
			add_filter( "manage_edit-{$post_type}_sortable_columns", [ __CLASS__, 'sortable_column' ] );
		}

		// Handle the custom orderby in queries
		add_action( 'pre_get_posts', [ __CLASS__, 'handle_orderby' ] );

		// Enqueue tiny inline style for the column
		add_action( 'admin_head', [ __CLASS__, 'inline_styles' ] );
	}

	// ── Column registration ────────────────────────────────────────────────

	public static function add_column( array $columns ): array {
		// Insert before the 'date' column for natural placement
		$new = [];
		foreach ( $columns as $key => $label ) {
			if ( $key === 'date' ) {
				$new['wms_views'] = sprintf(
					'<span class="dashicons dashicons-chart-bar" title="%s" aria-label="%s"></span>',
					esc_attr__( 'Post Views', 'wp-milner-stats' ),
					esc_attr__( 'Post Views', 'wp-milner-stats' )
				);
			}
			$new[ $key ] = $label;
		}
		// If 'date' wasn't found, just append
		if ( ! isset( $new['wms_views'] ) ) {
			$new['wms_views'] = esc_html__( 'Views', 'wp-milner-stats' );
		}
		return $new;
	}

	public static function sortable_column( array $columns ): array {
		$columns['wms_views'] = 'wms_views';
		return $columns;
	}

	// ── Column content ─────────────────────────────────────────────────────

	public static function render_column( string $column, int $post_id ) {
		if ( 'wms_views' !== $column ) {
			return;
		}

		$counts = WMS_Query::get_post_counts( $post_id );
		$total  = $counts['total'] ?? 0;
		$day    = $counts['day']   ?? 0;

		// Show total with a tooltip of period breakdown
		$tooltip = sprintf(
			/* translators: 1: day views, 2: week views, 3: month views, 4: year views */
			__( "Today: %1\$d\nThis week: %2\$d\nThis month: %3\$d\nThis year: %4\$d", 'wp-milner-stats' ),
			$counts['day'],
			$counts['week'],
			$counts['month'],
			$counts['year']
		);

		printf(
			'<span class="wms-col-total" title="%s">%s</span>',
			esc_attr( $tooltip ),
			esc_html( number_format_i18n( $total ) )
		);

		// Show a small "today" badge if there are views today
		if ( $day > 0 ) {
			printf(
				'<span class="wms-col-today" title="%s">+%s</span>',
				esc_attr__( 'Views today', 'wp-milner-stats' ),
				esc_html( number_format_i18n( $day ) )
			);
		}
	}

	// ── Orderby handling ───────────────────────────────────────────────────

	/**
	 * Modify the WP_Query when sorting by wms_views.
	 * Joins in a subquery aggregate so we can ORDER BY view count.
	 */
	public static function handle_orderby( WP_Query $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'wms_views' !== $query->get( 'orderby' ) ) {
			return;
		}

		// We use a filter on posts_join + posts_orderby
		add_filter( 'posts_join',    [ __CLASS__, 'orderby_join' ] );
		add_filter( 'posts_orderby', [ __CLASS__, 'orderby_clause' ] );
	}

	public static function orderby_join( string $join ): string {
		global $wpdb;
		$table = $wpdb->prefix . WMS_TABLE_NAME;
		$join .= " LEFT JOIN (
			SELECT post_id, COUNT(*) AS wms_total_views
			FROM {$table}
			GROUP BY post_id
		) AS wms_counts ON ( {$wpdb->posts}.ID = wms_counts.post_id ) ";
		return $join;
	}

	public static function orderby_clause( string $orderby ): string {
		global $wp_query;
		$order = strtoupper( $wp_query->get( 'order' ) ) === 'ASC' ? 'ASC' : 'DESC';
		return "COALESCE(wms_counts.wms_total_views, 0) {$order}";
	}

	// ── Inline styles ──────────────────────────────────────────────────────

	public static function inline_styles() {
		$screen = get_current_screen();
		if ( ! $screen || $screen->base !== 'edit' ) {
			return;
		}
		?>
		<style>
		.column-wms_views { width: 80px; text-align: center !important; }
		.wms-col-total {
			display: block;
			font-weight: 600;
			font-size: 13px;
			color: #1d2327;
			cursor: help;
		}
		.wms-col-today {
			display: inline-block;
			margin-top: 2px;
			background: #d1fae5;
			color: #065f46;
			font-size: 10px;
			font-weight: 700;
			padding: 1px 5px;
			border-radius: 10px;
		}
		</style>
		<?php
	}

	// ── Helpers ────────────────────────────────────────────────────────────

	private static function get_tracked_post_types(): array {
		$saved = get_option( 'wms_track_post_types', [ 'post', 'page' ] );
		return is_array( $saved ) ? $saved : [ 'post', 'page' ];
	}
}
