<?php
/**
 * Public-facing API: shortcodes and template tag functions.
 *
 * Shortcodes:
 *   [wms_views]                — View count for the current post
 *   [wms_views id="42"]        — View count for a specific post
 *   [wms_views period="week"]  — Views for a specific period (day/week/month/year/total)
 *   [wms_views label="Views: "] — Optional prefix label
 *
 *   [wms_top_posts]                    — Ordered list of top posts (this week)
 *   [wms_top_posts period="month" limit="5" show_count="true"]
 *
 * Template tags (for theme developers):
 *   wms_get_views( $post_id, $period )  — Returns integer
 *   wms_the_views( $post_id, $period )  — Echoes formatted string
 *
 * @package Milner_Stats
 */

defined( 'ABSPATH' ) || exit;

class WMS_Public {

	public static function init() {
		add_shortcode( 'wms_views',     [ __CLASS__, 'shortcode_views' ] );
		add_shortcode( 'wms_top_posts', [ __CLASS__, 'shortcode_top_posts' ] );
	}

	// ── Shortcodes ─────────────────────────────────────────────────────────

	/**
	 * [wms_views] shortcode.
	 *
	 * Attributes:
	 *   id       (int)    Post ID. Defaults to current post.
	 *   period   (string) day | week | month | year | total. Default: total.
	 *   label    (string) Text to prepend. Default: empty.
	 *   before   (string) HTML before the count. Default: empty.
	 *   after    (string) HTML after the count. Default: empty.
	 */
	public static function shortcode_views( array $atts ): string {
		$atts = shortcode_atts( [
			'id'     => 0,
			'period' => 'total',
			'label'  => '',
			'before' => '',
			'after'  => '',
		], $atts, 'wms_views' );

		$post_id = $atts['id'] ? absint( $atts['id'] ) : get_the_ID();
		if ( ! $post_id ) {
			return '';
		}

		$count  = wms_get_views( $post_id, sanitize_key( $atts['period'] ) );
		$output = esc_html( $atts['label'] ) . number_format_i18n( $count );

		return $atts['before'] . $output . $atts['after'];
	}

	/**
	 * [wms_top_posts] shortcode.
	 *
	 * Attributes:
	 *   period      (string) day | week | month | year. Default: week.
	 *   limit       (int)    Number of posts. Default: 5.
	 *   show_count  (bool)   Show view count alongside title. Default: true.
	 *   post_type   (string) Comma-separated post types. Default: empty (all tracked).
	 */
	public static function shortcode_top_posts( array $atts ): string {
		$atts = shortcode_atts( [
			'period'     => 'week',
			'limit'      => 5,
			'show_count' => 'true',
			'post_type'  => '',
		], $atts, 'wms_top_posts' );

		$period     = sanitize_key( $atts['period'] );
		$limit      = absint( $atts['limit'] );
		$show_count = filter_var( $atts['show_count'], FILTER_VALIDATE_BOOLEAN );

		if ( ! in_array( $period, WMS_Query::PERIODS, true ) ) {
			$period = 'week';
		}
		$limit = min( max( $limit, 1 ), 50 );

		$posts = WMS_Query::get_top_posts( $period, $limit );

		if ( empty( $posts ) ) {
			return '<p class="wms-no-stats">' . esc_html__( 'No stats available yet.', 'wp-milner-stats' ) . '</p>';
		}

		// Optional post type filter
		if ( ! empty( $atts['post_type'] ) ) {
			$allowed_types = array_map( 'sanitize_key', explode( ',', $atts['post_type'] ) );
			$posts = array_filter( $posts, function( $p ) use ( $allowed_types ) {
				return in_array( $p->post_type, $allowed_types, true );
			} );
		}

		ob_start();
		?>
		<ol class="wms-top-posts-list">
			<?php foreach ( $posts as $post ) : ?>
				<li class="wms-top-posts-list__item">
					<a href="<?php echo esc_url( $post->permalink ); ?>">
						<?php echo esc_html( $post->post_title ); ?>
					</a>
					<?php if ( $show_count ) : ?>
						<span class="wms-top-posts-list__count">
							<?php echo esc_html( number_format_i18n( $post->views ) ); ?>
						</span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
		return ob_get_clean();
	}
}

// ── Global template tag functions ──────────────────────────────────────────

/**
 * Get the view count for a post.
 *
 * @param  int|null $post_id Post ID. Null = current post.
 * @param  string   $period  'day' | 'week' | 'month' | 'year' | 'total'
 * @return int
 */
function wms_get_views( ?int $post_id = null, string $period = 'total' ): int {
	$post_id = $post_id ?? get_the_ID();
	if ( ! $post_id ) {
		return 0;
	}

	$counts = WMS_Query::get_post_counts( (int) $post_id );
	return (int) ( $counts[ $period ] ?? $counts['total'] ?? 0 );
}

/**
 * Output the view count for a post (formatted).
 *
 * @param  int|null $post_id
 * @param  string   $period
 * @param  string   $label   Optional prefix, e.g. "Views: "
 */
function wms_the_views( ?int $post_id = null, string $period = 'total', string $label = '' ) {
	$count = wms_get_views( $post_id, $period );
	echo esc_html( $label . number_format_i18n( $count ) );
}

/**
 * Returns true if the current (or given) post has been viewed at least once.
 *
 * @param  int|null $post_id
 * @return bool
 */
function wms_has_views( ?int $post_id = null ): bool {
	return wms_get_views( $post_id, 'total' ) > 0;
}
