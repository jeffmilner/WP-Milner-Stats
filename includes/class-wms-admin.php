<?php
/**
 * Admin dashboard controller for WP Milner Stats.
 *
 * Registers the admin menu, settings page, and all admin-facing assets.
 * The actual dashboard HTML is in the render_page() method below.
 *
 * @package WP_Milner_Stats
 */

defined( 'ABSPATH' ) || exit;

class WMS_Admin {

	/** Slug used for the top-level admin menu item */
	const MENU_SLUG = 'wp-milner-stats';

	public function init() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// Add a quick "Stats" link to the plugin action links
		add_filter( 'plugin_action_links_' . plugin_basename( WMS_PLUGIN_FILE ),
			[ $this, 'add_action_link' ] );
	}

	// ── Menu ──────────────────────────────────────────────────────────────

	public function register_menu() {
		add_menu_page(
			__( 'Milner Stats', 'wp-milner-stats' ),
			__( 'Milner Stats', 'wp-milner-stats' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_page' ],
			'dashicons-chart-bar',
			25
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Overview', 'wp-milner-stats' ),
			__( 'Overview', 'wp-milner-stats' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'wp-milner-stats' ),
			__( 'Settings', 'wp-milner-stats' ),
			'manage_options',
			self::MENU_SLUG . '-settings',
			[ $this, 'render_settings_page' ]
		);
	}

	// ── Assets ────────────────────────────────────────────────────────────

	public function enqueue_assets( string $hook ) {
		// Only load on our admin pages
		if ( strpos( $hook, self::MENU_SLUG ) === false ) {
			return;
		}

		// Chart.js from CDN
		wp_enqueue_script(
			'chartjs',
			'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js',
			[],
			'4.4.3',
			true
		);

		wp_enqueue_style(
			'wms-admin',
			WMS_PLUGIN_URL . 'admin/css/admin.css',
			[],
			WMS_VERSION
		);

		wp_enqueue_script(
			'wms-admin',
			WMS_PLUGIN_URL . 'admin/js/admin.js',
			[ 'chartjs', 'wp-api-fetch' ],
			WMS_VERSION,
			true
		);

		wp_localize_script( 'wms-admin', 'wmsAdmin', [
			'restBase'   => esc_url_raw( rest_url( 'wp-milner-stats/v1' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'periods'    => WMS_Query::PERIODS,
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'i18n'       => [
				'showAll'         => __( 'Show All', 'wp-milner-stats' ),
				'showTop10'       => __( 'Top 10',   'wp-milner-stats' ),
				'showAllAriaLabel' => __( 'Show all posts for this period', 'wp-milner-stats' ),
				'showTop10AriaLabel' => __( 'Show only the top 10 posts', 'wp-milner-stats' ),
			],
		] );
	}

	// ── Plugin action links ───────────────────────────────────────────────

	public function add_action_link( array $links ): array {
		$stats_link = sprintf(
			'<a href="%s">%s</a>',
			admin_url( 'admin.php?page=' . self::MENU_SLUG ),
			__( 'Stats', 'wp-milner-stats' )
		);
		array_unshift( $links, $stats_link );
		return $links;
	}

	// ── Main dashboard page ───────────────────────────────────────────────

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wp-milner-stats' ) );
		}

		// Pre-fetch all period stats (views + visitors) for initial render
		$all_stats = WMS_Insights::get_all_period_stats();
		$periods   = WMS_Query::PERIODS;

		$card_defs = [
			'day'   => __( 'Last 24 Hours', 'wp-milner-stats' ),
			'week'  => __( 'Last 7 Days',   'wp-milner-stats' ),
			'month' => __( 'Last 30 Days',  'wp-milner-stats' ),
			'year'  => __( 'Last 365 Days', 'wp-milner-stats' ),
		];
		?>
		<div class="wrap wms-wrap">
			<h1 class="wms-heading">
				<span class="dashicons dashicons-chart-bar"></span>
				<?php esc_html_e( 'Milner Stats', 'wp-milner-stats' ); ?>
			</h1>

			<!-- Summary Cards: Views + Visitors split, clearly labelled -->
			<div class="wms-cards">
				<?php foreach ( $card_defs as $key => $label ) :
					$views    = $all_stats[ $key ]['views']    ?? 0;
					$visitors = $all_stats[ $key ]['visitors'] ?? 0;
					?>
					<div class="wms-card" data-period="<?php echo esc_attr( $key ); ?>">
						<span class="wms-card__label"><?php echo esc_html( $label ); ?></span>
						<span class="wms-card__value"><?php echo esc_html( number_format_i18n( $views ) ); ?></span>
						<span class="wms-card__metric-label"><?php esc_html_e( 'Views', 'wp-milner-stats' ); ?></span>
						<div class="wms-card__divider"></div>
						<div class="wms-card__visitor-row">
							<span class="wms-card__visitor-count"><?php echo esc_html( number_format_i18n( $visitors ) ); ?></span>
							<span class="wms-card__visitor-label"><?php esc_html_e( 'Visitors', 'wp-milner-stats' ); ?></span>
						</div>
					</div>
				<?php endforeach; ?>
				<div class="wms-card wms-card--total">
					<span class="wms-card__label"><?php esc_html_e( 'All Time', 'wp-milner-stats' ); ?></span>
					<span class="wms-card__value"><?php echo esc_html( number_format_i18n( $all_stats['total']['views'] ?? 0 ) ); ?></span>
					<span class="wms-card__metric-label"><?php esc_html_e( 'Views', 'wp-milner-stats' ); ?></span>
					<div class="wms-card__divider"></div>
					<div class="wms-card__visitor-row">
						<span class="wms-card__visitor-count"><?php echo esc_html( number_format_i18n( $all_stats['total']['visitors'] ?? 0 ) ); ?></span>
						<span class="wms-card__visitor-label"><?php esc_html_e( 'Visitors', 'wp-milner-stats' ); ?></span>
					</div>
				</div>
			</div>

			<!-- Activity Chart (dual: views + visitors) -->
			<div class="wms-section">
				<div class="wms-section__header">
					<h2><?php esc_html_e( 'Activity', 'wp-milner-stats' ); ?></h2>
					<div class="wms-chart-controls">
						<button class="wms-btn wms-btn--active" data-range="24h"><?php esc_html_e( '24 Hours', 'wp-milner-stats' ); ?></button>
						<button class="wms-btn" data-range="7"><?php esc_html_e( '7 Days', 'wp-milner-stats' ); ?></button>
						<button class="wms-btn" data-range="30"><?php esc_html_e( '30 Days', 'wp-milner-stats' ); ?></button>
						<button class="wms-btn" data-range="365"><?php esc_html_e( '1 Year', 'wp-milner-stats' ); ?></button>
						<button class="wms-btn" data-range="1825"><?php esc_html_e( '5 Years', 'wp-milner-stats' ); ?></button>
						<button class="wms-btn" data-range="3650"><?php esc_html_e( '10 Years', 'wp-milner-stats' ); ?></button>
						<button class="wms-btn" data-range="all"><?php esc_html_e( 'All Time', 'wp-milner-stats' ); ?></button>
					</div>
				</div>
				<div class="wms-chart-legend">
					<span class="wms-chart-legend-item">
						<span class="wms-legend-dot wms-legend-dot--views"></span>
						<?php esc_html_e( 'Views', 'wp-milner-stats' ); ?>
					</span>
					<span class="wms-chart-legend-item">
						<span class="wms-legend-dot wms-legend-dot--visitors"></span>
						<?php esc_html_e( 'Visitors', 'wp-milner-stats' ); ?>
					</span>
				</div>
				<div class="wms-chart-wrapper">
					<canvas id="wms-activity-chart" aria-label="<?php esc_attr_e( 'Views and visitors over time', 'wp-milner-stats' ); ?>"></canvas>
					<div class="wms-chart-loading" id="wms-chart-loading" aria-live="polite">
						<span class="wms-spinner"></span>
					</div>
				</div>
			</div>

			<!-- Top Posts Table -->
			<div class="wms-section">
				<div class="wms-section__header">
					<div class="wms-section__header-left">
						<h2><?php esc_html_e( 'Top Posts', 'wp-milner-stats' ); ?></h2>
						<button
							id="wms-toggle-all-posts"
							class="wms-btn wms-btn--toggle"
							data-showing="10"
							aria-label="<?php esc_attr_e( 'Show all posts for this period', 'wp-milner-stats' ); ?>"
						>
							<?php esc_html_e( 'Show All', 'wp-milner-stats' ); ?>
						</button>
					</div>
					<div class="wms-period-tabs" role="tablist">
						<?php foreach ( $periods as $p ) : ?>
							<button
								class="wms-tab <?php echo 'day' === $p ? 'wms-tab--active' : ''; ?>"
								data-period="<?php echo esc_attr( $p ); ?>"
								role="tab"
								aria-selected="<?php echo 'day' === $p ? 'true' : 'false'; ?>"
							>
								<?php echo esc_html( self::period_label( $p ) ); ?>
							</button>
						<?php endforeach; ?>
					</div>
				</div>
				<div id="wms-top-posts" class="wms-table-wrapper">
					<div class="wms-table-loading" aria-live="polite">
						<span class="wms-spinner"></span>
						<span><?php esc_html_e( 'Loading…', 'wp-milner-stats' ); ?></span>
					</div>
				</div>
			</div>

			<!-- Trending Posts -->
			<div class="wms-section">
				<div class="wms-section__header">
					<h2>🔥 <?php esc_html_e( 'Trending Now', 'wp-milner-stats' ); ?></h2>
					<span class="wms-section__hint">
						<?php esc_html_e( 'Posts with unusually high views today vs. their 30-day average', 'wp-milner-stats' ); ?>
					</span>
				</div>
				<div id="wms-trending-posts" class="wms-table-wrapper">
					<div class="wms-table-loading" aria-live="polite">
						<span class="wms-spinner"></span>
						<span><?php esc_html_e( 'Loading…', 'wp-milner-stats' ); ?></span>
					</div>
				</div>
			</div>

			<!-- ═══════════════════════════════════════════════════════════════
			     INSIGHTS: Referrers, Search Terms, Outlinks
			     ═══════════════════════════════════════════════════════════ -->

			<!-- Referrers & Search Terms -->
			<div class="wms-section">
				<div class="wms-section__header">
					<h2><?php esc_html_e( 'Referrers &amp; Search Terms', 'wp-milner-stats' ); ?></h2>
					<div class="wms-period-tabs wms-insights-tabs" role="tablist" data-insights-section="referrers">
						<?php foreach ( $periods as $p ) : ?>
							<button
								class="wms-tab <?php echo 'day' === $p ? 'wms-tab--active' : ''; ?>"
								data-period="<?php echo esc_attr( $p ); ?>"
								role="tab"
								aria-selected="<?php echo 'day' === $p ? 'true' : 'false'; ?>"
							>
								<?php echo esc_html( self::period_label( $p ) ); ?>
							</button>
						<?php endforeach; ?>
					</div>
				</div>

				<!-- Referrer summary bar -->
				<div id="wms-referrer-summary" class="wms-insights-summary">
					<div class="wms-table-loading" aria-live="polite">
						<span class="wms-spinner"></span>
					</div>
				</div>

				<!-- Two-column: referrers + search terms -->
				<div class="wms-insights-cols">
					<div class="wms-insights-col">
						<h3 class="wms-insights-col__title">
							<span class="dashicons dashicons-admin-links"></span>
							<?php esc_html_e( 'Top Referrers', 'wp-milner-stats' ); ?>
						</h3>
						<div id="wms-referrers-list" class="wms-table-wrapper">
							<div class="wms-table-loading" aria-live="polite">
								<span class="wms-spinner"></span>
							</div>
						</div>
					</div>
					<div class="wms-insights-col">
						<h3 class="wms-insights-col__title">
							<span class="dashicons dashicons-search"></span>
							<?php esc_html_e( 'Search Terms', 'wp-milner-stats' ); ?>
						</h3>
						<div id="wms-search-terms-list" class="wms-table-wrapper">
							<div class="wms-table-loading" aria-live="polite">
								<span class="wms-spinner"></span>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Outbound Links -->
			<div class="wms-section">
				<div class="wms-section__header">
					<h2><?php esc_html_e( 'Outbound Links', 'wp-milner-stats' ); ?></h2>
					<div class="wms-period-tabs wms-insights-tabs" role="tablist" data-insights-section="outlinks">
						<?php foreach ( $periods as $p ) : ?>
							<button
								class="wms-tab <?php echo 'day' === $p ? 'wms-tab--active' : ''; ?>"
								data-period="<?php echo esc_attr( $p ); ?>"
								role="tab"
								aria-selected="<?php echo 'day' === $p ? 'true' : 'false'; ?>"
							>
								<?php echo esc_html( self::period_label( $p ) ); ?>
							</button>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="wms-insights-cols">
					<div class="wms-insights-col">
						<h3 class="wms-insights-col__title">
							<span class="dashicons dashicons-external"></span>
							<?php esc_html_e( 'Top Links Clicked', 'wp-milner-stats' ); ?>
						</h3>
						<div id="wms-outlinks-list" class="wms-table-wrapper">
							<div class="wms-table-loading" aria-live="polite">
								<span class="wms-spinner"></span>
							</div>
						</div>
					</div>
					<div class="wms-insights-col">
						<h3 class="wms-insights-col__title">
							<span class="dashicons dashicons-networking"></span>
							<?php esc_html_e( 'Top Domains Clicked', 'wp-milner-stats' ); ?>
						</h3>
						<div id="wms-outlink-domains-list" class="wms-table-wrapper">
							<div class="wms-table-loading" aria-live="polite">
								<span class="wms-spinner"></span>
							</div>
						</div>
					</div>
				</div>
			</div>

		</div><!-- .wms-wrap -->
		<?php
	}

	// ── Settings page ─────────────────────────────────────────────────────

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wp-milner-stats' ) );
		}

		// ── Action handlers — must run BEFORE the diagnostic table is built ──
		// Re-run setup: create/update missing tables
		if ( isset( $_GET['wms_rerun_setup'] ) && check_admin_referer( 'wms_rerun_setup' ) ) {
			WMS_Activator::activate();
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Setup complete. All tables have been created or updated.', 'wp-milner-stats' )
				. '</p></div>';
		}

		// Test referrer insertion
		if ( isset( $_GET['wms_test_referrer'] ) && check_admin_referer( 'wms_test_referrer' ) ) {
			global $wpdb;
			$refs_table  = $wpdb->prefix . WMS_REFERRERS_TABLE;
			$first_post  = $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type='post' LIMIT 1" ); // phpcs:ignore
			if ( $first_post ) {
				$inserted = $wpdb->insert(
					$refs_table,
					[
						'view_id'       => 0,
						'post_id'       => (int) $first_post,
						'viewed_at'     => current_time( 'mysql', true ),
						'referrer_url'  => 'https://example.com/test-page',
						'referrer_host' => 'example.com',
						'search_term'   => '',
					],
					[ '%d', '%d', '%s', '%s', '%s', '%s' ]
				);
				if ( $inserted ) {
					echo '<div class="notice notice-success is-dismissible"><p>'
						. esc_html__( 'Test referrer inserted successfully! Check the "Referrers & Search Terms" section on the main Stats page (set to "This Month" if Today shows nothing). If you see example.com listed, the referrer pipeline is working correctly. You can delete this test record by running Cleanup or by clearing the table.', 'wp-milner-stats' )
						. '</p></div>';
				} else {
					echo '<div class="notice notice-error is-dismissible"><p>'
						. esc_html__( 'Test referrer INSERT FAILED. DB error: ', 'wp-milner-stats' )
						. esc_html( $wpdb->last_error )
						. '</p></div>';
				}
			} else {
				echo '<div class="notice notice-warning"><p>'
					. esc_html__( 'No published posts found to attach the test referrer to.', 'wp-milner-stats' )
					. '</p></div>';
			}
		}

		// Manual cleanup trigger
		if ( isset( $_GET['wms_run_cleanup'] ) && check_admin_referer( 'wms_run_cleanup' ) ) {
			WMS_Activator::cleanup_old_records();
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Cleanup complete.', 'wp-milner-stats' )
				. '</p></div>';
		}

		// Save settings
		if ( isset( $_POST['wms_save_settings'] ) ) {
			check_admin_referer( 'wms_settings_save' );

			update_option( 'wms_skip_admin_views',   isset( $_POST['wms_skip_admin_views'] ) ? 1 : 0 );
			update_option( 'wms_track_post_types',   array_map( 'sanitize_key', (array) ( $_POST['wms_track_post_types'] ?? [] ) ) );
			update_option( 'wms_dedup_window',       absint( $_POST['wms_dedup_window'] ?? 30 ) );

			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Settings saved.', 'wp-milner-stats' )
				. '</p></div>';
		}

		$skip_admin      = get_option( 'wms_skip_admin_views', 1 );
		$tracked_types   = get_option( 'wms_track_post_types', [ 'post', 'page' ] );
		$dedup_window    = get_option( 'wms_dedup_window', 30 );
		$all_post_types  = get_post_types( [ 'public' => true ], 'objects' );

		// Cleanup stats
		$last_cleanup    = get_transient( 'wms_last_cleanup' );

		global $wpdb;
		$table      = $wpdb->prefix . WMS_TABLE_NAME;
		$total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		?>
		<div class="wrap wms-wrap">
			<h1><?php esc_html_e( 'Milner Stats — Settings', 'wp-milner-stats' ); ?></h1>

			<form method="post" action="">
				<?php wp_nonce_field( 'wms_settings_save' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Skip Admin Views', 'wp-milner-stats' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="wms_skip_admin_views" value="1" <?php checked( $skip_admin, 1 ); ?>>
								<?php esc_html_e( "Don't count views from editors and administrators", 'wp-milner-stats' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Tracked Post Types', 'wp-milner-stats' ); ?></th>
						<td>
							<?php foreach ( $all_post_types as $pt ) : ?>
								<label style="display:block;margin-bottom:6px">
									<input type="checkbox"
										name="wms_track_post_types[]"
										value="<?php echo esc_attr( $pt->name ); ?>"
										<?php checked( in_array( $pt->name, (array) $tracked_types, true ) ); ?>>
									<?php echo esc_html( $pt->label ); ?>
									<code style="font-size:11px;opacity:.6">(<?php echo esc_html( $pt->name ); ?>)</code>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Deduplication Window', 'wp-milner-stats' ); ?></th>
						<td>
							<input type="number" name="wms_dedup_window"
								value="<?php echo esc_attr( $dedup_window ); ?>"
								min="5" max="1440" step="5" style="width:80px">
							<?php esc_html_e( 'minutes', 'wp-milner-stats' ); ?>
							<p class="description">
								<?php esc_html_e( 'Minimum time before the same visitor counts as a new view.', 'wp-milner-stats' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" name="wms_save_settings" class="button button-primary">
						<?php esc_html_e( 'Save Settings', 'wp-milner-stats' ); ?>
					</button>
				</p>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Database Diagnostics', 'wp-milner-stats' ); ?></h2>
			<?php
			// Check all three tables exist and get row counts
			$tables_info = [];
			$check_tables = [
				WMS_TABLE_NAME      => 'Page Views',
				WMS_REFERRERS_TABLE => 'Referrers',
				WMS_OUTLINKS_TABLE  => 'Outbound Links',
			];
			foreach ( $check_tables as $table_suffix => $label ) {
				$full  = $wpdb->prefix . $table_suffix;
				$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $full ) ) === $full;
				$count  = $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$full}`" ) : null; // phpcs:ignore
				$tables_info[] = [ 'label' => $label, 'table' => $full, 'exists' => $exists, 'count' => $count ];
			}
			?>
			<table class="widefat striped" style="max-width:650px;margin-bottom:16px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Table', 'wp-milner-stats' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wp-milner-stats' ); ?></th>
						<th><?php esc_html_e( 'Rows', 'wp-milner-stats' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $tables_info as $t ) : ?>
					<tr>
						<td><code><?php echo esc_html( $t['table'] ); ?></code><br>
							<small><?php echo esc_html( $t['label'] ); ?></small>
						</td>
						<td>
							<?php if ( $t['exists'] ) : ?>
								<span style="color:#00a32a">✔ <?php esc_html_e( 'Exists', 'wp-milner-stats' ); ?></span>
							<?php else : ?>
								<span style="color:#d63638">✘ <?php esc_html_e( 'MISSING — click "Re-run Setup" below', 'wp-milner-stats' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php echo $t['exists'] ? esc_html( number_format_i18n( $t['count'] ) ) : '—'; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			// Show most recent referrer rows for debugging
			$refs_table   = $wpdb->prefix . WMS_REFERRERS_TABLE;
			$refs_exist   = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $refs_table ) ) === $refs_table;
			if ( $refs_exist ) :
				$recent_refs = $wpdb->get_results( "SELECT referrer_host, search_term, viewed_at FROM `{$refs_table}` ORDER BY id DESC LIMIT 5" ); // phpcs:ignore
				?>
				<h3 style="font-size:14px;margin-bottom:6px"><?php esc_html_e( 'Last 5 Referrer Records', 'wp-milner-stats' ); ?></h3>
				<?php if ( $recent_refs ) : ?>
					<table class="widefat striped" style="max-width:650px;margin-bottom:16px">
						<thead><tr>
							<th>Referrer Host</th><th>Search Term</th><th>Viewed At (UTC)</th>
						</tr></thead>
						<tbody>
						<?php foreach ( $recent_refs as $r ) : ?>
							<tr>
								<td><?php echo esc_html( $r->referrer_host ); ?></td>
								<td><?php echo esc_html( $r->search_term ?: '—' ); ?></td>
								<td><?php echo esc_html( $r->viewed_at ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p style="color:#666;font-style:italic"><?php esc_html_e( 'No referrer records yet. Referrers are only recorded when a visitor arrives from an external website (not direct traffic or same-site navigation).', 'wp-milner-stats' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>

			<p style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WMS_Admin::MENU_SLUG . '-settings&wms_rerun_setup=1&_wpnonce=' . wp_create_nonce( 'wms_rerun_setup' ) ) ); ?>"
				   class="button button-primary">
					<?php esc_html_e( 'Re-run Setup (create missing tables)', 'wp-milner-stats' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WMS_Admin::MENU_SLUG . '-settings&wms_test_referrer=1&_wpnonce=' . wp_create_nonce( 'wms_test_referrer' ) ) ); ?>"
				   class="button">
					<?php esc_html_e( 'Insert Test Referrer Record', 'wp-milner-stats' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WMS_Admin::MENU_SLUG . '-settings&wms_run_cleanup=1&_wpnonce=' . wp_create_nonce( 'wms_run_cleanup' ) ) ); ?>"
				   class="button">
					<?php esc_html_e( 'Run Cleanup Now', 'wp-milner-stats' ); ?>
				</a>
			</p>
			<?php
			// Cron info
			$next = wp_next_scheduled( 'wms_daily_cleanup' );
			?>
			<div style="background:#f9f9f9;border:1px solid #ddd;border-radius:6px;padding:14px 16px;margin-top:16px;font-size:13px;line-height:1.6">
				<strong><?php esc_html_e( 'How referrer tracking works', 'wp-milner-stats' ); ?></strong>
				<p style="margin:6px 0 0"><?php esc_html_e( 'Referrers are only recorded when a visitor arrives at your site from an external website. Direct traffic (typing the URL, bookmarks, emails) has no referrer by design — this is how browsers work.', 'wp-milner-stats' ); ?></p>
				<p style="margin:4px 0 0"><?php esc_html_e( 'To generate referrer data: click a link to your site from another website, from a Google search result, or from a social media post. Then check back here.', 'wp-milner-stats' ); ?></p>
				<p style="margin:4px 0 0;color:#888"><?php esc_html_e( 'Note: Google and most major search engines hide search terms for privacy reasons. You will see "google.com" as a referrer domain but the search term may be empty.', 'wp-milner-stats' ); ?></p>
			</div>
			<p style="color:#666;font-size:12px;margin-top:10px">
				<?php esc_html_e( 'Next scheduled cleanup:', 'wp-milner-stats' ); ?>
				<?php echo $next ? esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ) ) ) : esc_html__( 'Not scheduled', 'wp-milner-stats' ); ?>
				| <?php esc_html_e( 'Plugin version:', 'wp-milner-stats' ); ?> <?php echo esc_html( WMS_VERSION ); ?>
				| <?php esc_html_e( 'DB schema version:', 'wp-milner-stats' ); ?> <?php echo esc_html( get_option( 'wms_db_version', 'none' ) ); ?>
			</p>
		</div>
		<?php
	}

	// ── Static helpers ────────────────────────────────────────────────────

	public static function period_label( string $period ): string {
		$labels = [
			'day'   => __( 'Today', 'wp-milner-stats' ),
			'week'  => __( 'This Week', 'wp-milner-stats' ),
			'month' => __( 'This Month', 'wp-milner-stats' ),
			'year'  => __( 'This Year', 'wp-milner-stats' ),
		];
		return $labels[ $period ] ?? ucfirst( $period );
	}
}
