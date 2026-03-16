<?php
/**
 * WordPress Sidebar Widget: Top Viewed Posts
 *
 * Lets site owners display a "Most Popular Posts" list in any widget area.
 * Fully configurable from Appearance > Widgets or the Customizer.
 *
 * @package WP_Milner_Stats
 */

defined( 'ABSPATH' ) || exit;

class WMS_Sidebar_Widget extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'wms_top_posts_widget',
			__( 'Popular Posts (Milner Stats)', 'wp-milner-stats' ),
			[
				'description' => __( 'Show your most-viewed posts for a chosen time period.', 'wp-milner-stats' ),
				'classname'   => 'widget_wms_top_posts',
			]
		);
	}

	/**
	 * Register this widget with WordPress.
	 */
	public static function register() {
		register_widget( __CLASS__ );
	}

	// ── Front-end display ──────────────────────────────────────────────────

	public function widget( $args, $instance ) {
		$title      = apply_filters( 'widget_title', $instance['title'] ?? '', $instance, $this->id_base );
		$period     = sanitize_key( $instance['period']     ?? 'week' );
		$limit      = absint( $instance['limit']            ?? 5 );
		$show_count = ! empty( $instance['show_count'] );
		$show_thumb = ! empty( $instance['show_thumb'] );
		$show_date  = ! empty( $instance['show_date'] );

		$posts = WMS_Query::get_top_posts( $period, $limit );

		if ( empty( $posts ) ) {
			return; // Don't render widget if no data
		}

		echo wp_kses_post( $args['before_widget'] );

		if ( $title ) {
			echo wp_kses_post( $args['before_title'] . $title . $args['after_title'] );
		}

		echo '<ul class="wms-widget-list">';

		foreach ( $posts as $post_obj ) {
			$post = get_post( $post_obj->post_id );
			if ( ! $post ) {
				continue;
			}

			echo '<li class="wms-widget-list__item">';

			if ( $show_thumb && $post_obj->thumbnail ) {
				printf(
					'<a href="%s" class="wms-widget-list__thumb" tabindex="-1" aria-hidden="true">'
					. '<img src="%s" alt="" width="50" height="50" loading="lazy">'
					. '</a>',
					esc_url( $post_obj->permalink ),
					esc_url( $post_obj->thumbnail )
				);
			}

			echo '<div class="wms-widget-list__body">';
			printf(
				'<a href="%s" class="wms-widget-list__title">%s</a>',
				esc_url( $post_obj->permalink ),
				esc_html( $post_obj->post_title )
			);

			$meta = [];
			if ( $show_count ) {
				$meta[] = sprintf(
					'<span class="wms-widget-list__views">%s %s</span>',
					esc_html( number_format_i18n( $post_obj->views ) ),
					esc_html( _n( 'view', 'views', $post_obj->views, 'wp-milner-stats' ) )
				);
			}
			if ( $show_date ) {
				$meta[] = sprintf(
					'<span class="wms-widget-list__date">%s</span>',
					esc_html( get_the_date( '', $post ) )
				);
			}
			if ( $meta ) {
				echo '<span class="wms-widget-list__meta">' . implode( ' · ', $meta ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}

			echo '</div></li>';
		}

		echo '</ul>';

		// Inline styles (scoped, only loaded when widget is active)
		$this->inline_styles( $show_thumb );

		echo wp_kses_post( $args['after_widget'] );
	}

	// ── Admin form ─────────────────────────────────────────────────────────

	public function form( $instance ) {
		$title      = $instance['title']      ?? __( 'Popular Posts', 'wp-milner-stats' );
		$period     = $instance['period']     ?? 'week';
		$limit      = $instance['limit']      ?? 5;
		$show_count = $instance['show_count'] ?? true;
		$show_thumb = $instance['show_thumb'] ?? false;
		$show_date  = $instance['show_date']  ?? false;

		$periods = [
			'day'   => __( 'Last 24 Hours', 'wp-milner-stats' ),
			'week'  => __( 'Last 7 Days',   'wp-milner-stats' ),
			'month' => __( 'Last 30 Days',  'wp-milner-stats' ),
			'year'  => __( 'Last Year',     'wp-milner-stats' ),
		];
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
				<?php esc_html_e( 'Title:', 'wp-milner-stats' ); ?>
			</label>
			<input class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
				type="text"
				value="<?php echo esc_attr( $title ); ?>">
		</p>

		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'period' ) ); ?>">
				<?php esc_html_e( 'Time Period:', 'wp-milner-stats' ); ?>
			</label>
			<select class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'period' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'period' ) ); ?>">
				<?php foreach ( $periods as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $period, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'limit' ) ); ?>">
				<?php esc_html_e( 'Number of posts:', 'wp-milner-stats' ); ?>
			</label>
			<input class="tiny-text"
				id="<?php echo esc_attr( $this->get_field_id( 'limit' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'limit' ) ); ?>"
				type="number" min="1" max="20" step="1"
				value="<?php echo esc_attr( $limit ); ?>">
		</p>

		<p>
			<input type="checkbox"
				id="<?php echo esc_attr( $this->get_field_id( 'show_count' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'show_count' ) ); ?>"
				value="1" <?php checked( $show_count ); ?>>
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_count' ) ); ?>">
				<?php esc_html_e( 'Show view count', 'wp-milner-stats' ); ?>
			</label>
		</p>

		<p>
			<input type="checkbox"
				id="<?php echo esc_attr( $this->get_field_id( 'show_thumb' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'show_thumb' ) ); ?>"
				value="1" <?php checked( $show_thumb ); ?>>
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_thumb' ) ); ?>">
				<?php esc_html_e( 'Show thumbnail', 'wp-milner-stats' ); ?>
			</label>
		</p>

		<p>
			<input type="checkbox"
				id="<?php echo esc_attr( $this->get_field_id( 'show_date' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'show_date' ) ); ?>"
				value="1" <?php checked( $show_date ); ?>>
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_date' ) ); ?>">
				<?php esc_html_e( 'Show publish date', 'wp-milner-stats' ); ?>
			</label>
		</p>
		<?php
	}

	// ── Save ───────────────────────────────────────────────────────────────

	public function update( $new_instance, $old_instance ) {
		return [
			'title'      => sanitize_text_field( $new_instance['title'] ?? '' ),
			'period'     => in_array( $new_instance['period'] ?? '', WMS_Query::PERIODS, true )
			                  ? $new_instance['period']
			                  : 'week',
			'limit'      => min( max( absint( $new_instance['limit'] ?? 5 ), 1 ), 20 ),
			'show_count' => ! empty( $new_instance['show_count'] ) ? 1 : 0,
			'show_thumb' => ! empty( $new_instance['show_thumb'] ) ? 1 : 0,
			'show_date'  => ! empty( $new_instance['show_date'] )  ? 1 : 0,
		];
	}

	// ── Styles ─────────────────────────────────────────────────────────────

	private function inline_styles( bool $show_thumb ) {
		static $printed = false;
		if ( $printed ) return;
		$printed = true;
		?>
		<style>
		.wms-widget-list {
			list-style: none;
			margin: 0;
			padding: 0;
		}
		.wms-widget-list__item {
			display: flex;
			align-items: flex-start;
			gap: 10px;
			padding: 8px 0;
			border-bottom: 1px solid rgba(0,0,0,.07);
		}
		.wms-widget-list__item:last-child { border-bottom: none; }
		.wms-widget-list__thumb img {
			width: 50px;
			height: 50px;
			object-fit: cover;
			border-radius: 4px;
			flex-shrink: 0;
		}
		.wms-widget-list__body {
			flex: 1;
			min-width: 0;
		}
		.wms-widget-list__title {
			display: block;
			font-weight: 600;
			font-size: .9em;
			line-height: 1.3;
			color: inherit;
			text-decoration: none;
			margin-bottom: 3px;
		}
		.wms-widget-list__title:hover { text-decoration: underline; }
		.wms-widget-list__meta {
			display: block;
			font-size: .8em;
			opacity: .65;
		}
		</style>
		<?php
	}
}
