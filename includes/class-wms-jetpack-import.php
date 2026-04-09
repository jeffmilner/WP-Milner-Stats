<?php
/**
 * One-time import tool: pulls historical per-post view counts from the
 * Jetpack / WordPress.com Stats API and inserts them as synthetic rows
 * in the wms_post_views table.
 *
 * Uses Jetpack's own stored connection — no manual token required.
 *
 * Imported rows are tagged with ip_hash = 'jpimport' so the process is
 * idempotent — re-running it skips posts that already have imported data.
 *
 * Limitations:
 *  - View counts will be accurate. Visitor counts cannot be imported because
 *    Jetpack's API provides views per post but visitors only as a sitewide
 *    daily total — there is no way to assign them per post.
 *  - All imported timestamps are set to noon UTC on each historical date.
 *  - WordPress.com retains approximately 3 years of data.
 *
 * @package Milner_Stats
 */

defined( 'ABSPATH' ) || exit;

class WMS_Jetpack_Import {

	const MENU_SLUG   = 'wms-jetpack-import';
	const IMPORT_MARK = 'jpimport';  // ip_hash marker — identifies synthetic rows

	// ── Boot ─────────────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
		add_action( 'wp_ajax_wms_jp_get_posts',    [ __CLASS__, 'ajax_get_posts' ] );
		add_action( 'wp_ajax_wms_jp_import_post',  [ __CLASS__, 'ajax_import_post' ] );
		add_action( 'wp_ajax_wms_jp_clear_import', [ __CLASS__, 'ajax_clear_import' ] );
		add_action( 'wp_ajax_wms_jp_debug',        [ __CLASS__, 'ajax_debug' ] );
	}

	public static function add_menu(): void {
		add_submenu_page(
			'wp-milner-stats',
			__( 'Import Jetpack Stats', 'wp-milner-stats' ),
			__( 'Import Jetpack Data', 'wp-milner-stats' ),
			'manage_options',
			self::MENU_SLUG,
			[ __CLASS__, 'render_page' ]
		);
	}

	// ── Admin page ────────────────────────────────────────────────────────────

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wp-milner-stats' ) );
		}

		// Check Jetpack is available and connected
		$jetpack_ready = self::jetpack_client_available();
		$site_id       = class_exists( 'Jetpack_Options' ) ? (string) Jetpack_Options::get_option( 'id' ) : '';

		// Count any already-imported rows so the user knows the current state
		global $wpdb;
		$table = $wpdb->prefix . WMS_TABLE_NAME;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$imported_count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE ip_hash = %s", self::IMPORT_MARK )
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import Stats from Jetpack', 'wp-milner-stats' ); ?></h1>

			<div style="max-width:700px">

				<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:14px 16px;margin-bottom:20px;font-size:13px;line-height:1.7">
					<strong><?php esc_html_e( 'Before you start:', 'wp-milner-stats' ); ?></strong>
					<ul style="margin:8px 0 0 18px;list-style:disc">
						<li><?php esc_html_e( 'Re-running this import is safe — posts already imported are skipped automatically.', 'wp-milner-stats' ); ?></li>
						<li><?php esc_html_e( 'View counts will be accurate. Visitor counts cannot be imported — historical visitor graphs will remain empty.', 'wp-milner-stats' ); ?></li>
						<li><?php esc_html_e( 'WordPress.com retains roughly 3 years of data.', 'wp-milner-stats' ); ?></li>
					</ul>
				</div>

				<?php if ( $imported_count > 0 ) : ?>
				<div style="background:#d4edda;border:1px solid #c3e6cb;border-radius:6px;padding:12px 16px;margin-bottom:20px;font-size:13px">
					<?php
					printf(
						/* translators: %s: formatted number */
						esc_html__( '%s imported view rows are currently in the database.', 'wp-milner-stats' ),
						'<strong>' . esc_html( number_format_i18n( $imported_count ) ) . '</strong>'
					);
					?>
					<a href="#" id="wms-jp-clear-link" style="margin-left:12px;color:#d63638">
						<?php esc_html_e( 'Clear all imported data', 'wp-milner-stats' ); ?>
					</a>
				</div>
				<?php endif; ?>

				<?php if ( ! $jetpack_ready ) : ?>
				<div style="background:#f8d7da;border:1px solid #f5c6cb;border-radius:6px;padding:14px 16px;margin-bottom:20px;font-size:13px">
					<strong><?php esc_html_e( 'Jetpack is not available or not connected.', 'wp-milner-stats' ); ?></strong>
					<?php esc_html_e( 'Please ensure Jetpack is active and connected to WordPress.com before running the import.', 'wp-milner-stats' ); ?>
				</div>
				<?php else : ?>
				<div style="background:#d4edda;border:1px solid #c3e6cb;border-radius:6px;padding:12px 16px;margin-bottom:20px;font-size:13px">
					&#10003; <?php esc_html_e( 'Jetpack is connected.', 'wp-milner-stats' ); ?>
					<?php if ( $site_id ) : ?>
						<?php /* translators: %s: numeric site ID */ ?>
						<?php printf( esc_html__( 'Site ID: %s', 'wp-milner-stats' ), '<strong>' . esc_html( $site_id ) . '</strong>' ); ?>
					<?php endif; ?>
				</div>
				<?php endif; ?>

				<p style="margin-top:16px;display:flex;gap:8px;align-items:center">
					<button id="wms-jp-start" class="button button-primary" <?php echo $jetpack_ready ? '' : 'disabled'; ?>>
						<?php esc_html_e( 'Start Import', 'wp-milner-stats' ); ?>
					</button>
					<button id="wms-jp-stop" class="button" style="display:none">
						<?php esc_html_e( 'Stop', 'wp-milner-stats' ); ?>
					</button>
				</p>

				<div id="wms-jp-progress" style="display:none;margin-top:18px">
					<div style="background:#e0e0e0;border-radius:4px;overflow:hidden;height:12px;margin-bottom:8px">
						<div id="wms-jp-bar" style="background:#2271b1;height:100%;width:0;transition:width .3s ease"></div>
					</div>
					<p id="wms-jp-status" style="font-size:13px;color:#555;margin:0 0 10px"></p>
					<div id="wms-jp-log"
						style="background:#f6f7f7;border:1px solid #ddd;border-radius:4px;padding:10px 12px;
							   max-height:280px;overflow-y:auto;font-size:12px;font-family:monospace;line-height:1.6;
							   white-space:pre-wrap"></div>
				</div>

				<div id="wms-jp-done"
					style="display:none;margin-top:18px;padding:14px 16px;
						   background:#d4edda;border:1px solid #c3e6cb;border-radius:6px;font-size:13px;line-height:1.7">
				</div>

				<details style="margin-top:24px;font-size:12px;color:#888">
					<summary style="cursor:pointer">API diagnostic</summary>
					<div style="margin-top:10px;display:flex;gap:8px;align-items:center">
						<input type="number" id="wms-jp-debug-id" placeholder="Post ID" style="width:100px">
						<button id="wms-jp-debug-btn" class="button">Test API for this post</button>
					</div>
					<pre id="wms-jp-debug-out"
						style="margin-top:8px;background:#f6f7f7;border:1px solid #ddd;border-radius:4px;
							   padding:10px;font-size:11px;white-space:pre-wrap;max-height:200px;overflow-y:auto;display:none"></pre>
				</details>

			</div><!-- /.wrap inner -->
		</div><!-- /.wrap -->

		<script>
		( function () {
			var nonce   = <?php echo wp_json_encode( wp_create_nonce( 'wms_jp_import' ) ); ?>;
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

			var startBtn   = document.getElementById( 'wms-jp-start' );
			var stopBtn    = document.getElementById( 'wms-jp-stop' );
			var progressEl = document.getElementById( 'wms-jp-progress' );
			var bar        = document.getElementById( 'wms-jp-bar' );
			var statusEl   = document.getElementById( 'wms-jp-status' );
			var logEl      = document.getElementById( 'wms-jp-log' );
			var doneEl     = document.getElementById( 'wms-jp-done' );
			var clearLink  = document.getElementById( 'wms-jp-clear-link' );

			var stopped = false;
			var totalViews = 0, totalDates = 0, totalSkipped = 0, totalErrors = 0;

			function log( msg ) {
				logEl.textContent += msg + '\n';
				logEl.scrollTop = logEl.scrollHeight;
			}

			function post( action, data ) {
				data._ajax_nonce = nonce;
				data.action      = action;
				return fetch( ajaxUrl, {
					method:      'POST',
					headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
					body:        new URLSearchParams( data ).toString(),
					credentials: 'same-origin',
				} ).then( function ( r ) { return r.json(); } );
			}

			// ── Clear imported data ────────────────────────────────────────
			if ( clearLink ) {
				clearLink.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					if ( ! confirm( 'Delete all imported Jetpack rows from the database? This cannot be undone.' ) ) return;
					clearLink.textContent = 'Clearing…';
					post( 'wms_jp_clear_import', {} ).then( function ( res ) {
						if ( res.success ) {
							clearLink.parentElement.innerHTML =
								'<strong style="color:#00a32a">&#10003; Cleared ' + ( res.data.deleted || 0 ).toLocaleString() + ' rows.</strong>';
						} else {
							clearLink.textContent = 'Error — try again';
						}
					} );
				} );
			}

			// ── Start import ───────────────────────────────────────────────
			startBtn.addEventListener( 'click', function () {
				stopped      = false;
				totalViews   = 0; totalDates  = 0;
				totalSkipped = 0; totalErrors = 0;
				logEl.textContent    = '';
				doneEl.style.display = 'none';
				progressEl.style.display = 'block';
				startBtn.style.display   = 'none';
				stopBtn.style.display    = '';
				bar.style.width          = '0';
				statusEl.textContent     = 'Fetching post list\u2026';

				post( 'wms_jp_get_posts', {} )
					.then( function ( res ) {
						if ( ! res.success ) {
							statusEl.textContent = 'Error: ' + ( res.data || 'unknown' );
							startBtn.style.display = '';
							stopBtn.style.display  = 'none';
							return;
						}
						var posts = res.data.posts;
						log( 'Found ' + posts.length + ' posts/pages to process.\n' );
						processNext( posts, 0 );
					} )
					.catch( function ( e ) {
						statusEl.textContent = 'Network error: ' + e.message;
						startBtn.style.display = '';
						stopBtn.style.display  = 'none';
					} );
			} );

			stopBtn.addEventListener( 'click', function () {
				stopped          = true;
				stopBtn.disabled = true;
				statusEl.textContent = 'Stopping after current post\u2026';
			} );

			function processNext( posts, index ) {
				if ( stopped || index >= posts.length ) {
					finish( posts.length, index );
					return;
				}

				var p   = posts[ index ];
				var pct = Math.round( ( index / posts.length ) * 100 );
				bar.style.width      = pct + '%';
				statusEl.textContent = '(' + ( index + 1 ) + '\u2009/\u2009' + posts.length + ')  ' + p.title;

				post( 'wms_jp_import_post', { post_id: p.id } )
					.then( function ( res ) {
						if ( res.success ) {
							var d = res.data;
							if ( d.skipped ) {
								totalSkipped++;
								log( '  \u21B7 [' + p.id + '] ' + p.title + ' \u2014 already imported, skipped' );
							} else if ( d.views_imported === 0 ) {
								log( '  \u2013 [' + p.id + '] ' + p.title + ' \u2014 no Jetpack data' );
							} else {
								totalViews += d.views_imported || 0;
								totalDates += d.dates_imported || 0;
								log( '  \u2714 [' + p.id + '] ' + p.title
									+ ' \u2014 ' + ( d.views_imported || 0 ).toLocaleString() + ' views'
									+ ' across ' + ( d.dates_imported || 0 ) + ' days' );
							}
						} else {
							totalErrors++;
							log( '  \u2718 [' + p.id + '] ' + p.title + ' \u2014 ' + ( res.data || 'error' ) );
						}
						// Small delay to be polite to the API
						setTimeout( function () { processNext( posts, index + 1 ); }, 250 );
					} )
					.catch( function ( e ) {
						totalErrors++;
						log( '  \u2718 [' + p.id + '] ' + p.title + ' \u2014 network error: ' + e.message );
						setTimeout( function () { processNext( posts, index + 1 ); }, 250 );
					} );
			}

			function finish( total, processed ) {
				bar.style.width        = '100%';
				startBtn.style.display = '';
				stopBtn.style.display  = 'none';
				stopBtn.disabled       = false;

				var msg = stopped
					? 'Import stopped after ' + processed + ' of ' + total + ' posts.'
					: 'Import complete \u2014 all ' + total + ' posts processed.';

				statusEl.textContent = msg;
				doneEl.style.display = 'block';
				doneEl.innerHTML =
					'<strong>' + msg + '</strong><br>'
					+ totalViews.toLocaleString() + ' views imported across '
					+ totalDates.toLocaleString() + ' days<br>'
					+ totalSkipped + ' posts skipped (already imported)<br>'
					+ ( totalErrors
						? '<span style="color:#d63638">' + totalErrors + ' errors \u2014 see log above</span>'
						: '<span style="color:#00a32a">No errors.</span>' );
			}
			// ── Debug ─────────────────────────────────────────────────────────
			var debugBtn = document.getElementById( 'wms-jp-debug-btn' );
			var debugOut = document.getElementById( 'wms-jp-debug-out' );
			if ( debugBtn ) {
				debugBtn.addEventListener( 'click', function () {
					var pid = document.getElementById( 'wms-jp-debug-id' ).value.trim();
					if ( ! pid ) { alert( 'Enter a post ID first.' ); return; }
					debugBtn.disabled    = true;
					debugOut.style.display = 'block';
					debugOut.textContent   = 'Requesting…';
					post( 'wms_jp_debug', { post_id: pid } ).then( function ( res ) {
						debugOut.textContent = JSON.stringify( res, null, 2 );
						debugBtn.disabled = false;
					} ).catch( function ( e ) {
						debugOut.textContent = 'Network error: ' + e.message;
						debugBtn.disabled = false;
					} );
				} );
			}
		} )();
		</script>
		<?php
	}

	// ── AJAX: list posts ──────────────────────────────────────────────────────

	public static function ajax_get_posts(): void {
		check_ajax_referer( 'wms_jp_import' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$tracked = (array) get_option( 'wms_track_post_types', [ 'post', 'page' ] );
		$ids     = get_posts( [
			'post_type'      => $tracked,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
		] );

		$posts = array_map( function ( $id ) {
			return [ 'id' => $id, 'title' => get_the_title( $id ) ];
		}, (array) $ids );

		wp_send_json_success( [ 'posts' => $posts ] );
	}

	// ── AJAX: import one post ─────────────────────────────────────────────────

	public static function ajax_import_post(): void {
		check_ajax_referer( 'wms_jp_import' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_send_json_error( 'Missing post_id.' );
		}

		// Idempotency: skip if we've already imported this post
		if ( self::has_imported_data( $post_id ) ) {
			wp_send_json_success( [ 'skipped' => true ] );
		}

		$daily = self::fetch_post_stats( $post_id );
		if ( is_wp_error( $daily ) ) {
			wp_send_json_error( $daily->get_error_message() );
		}

		if ( empty( $daily ) ) {
			wp_send_json_success( [ 'skipped' => false, 'views_imported' => 0, 'dates_imported' => 0 ] );
		}

		$counts = self::insert_rows( $post_id, $daily );

		wp_send_json_success( [
			'skipped'        => false,
			'views_imported' => $counts['views'],
			'dates_imported' => $counts['dates'],
		] );
	}

	// ── AJAX: clear all imported rows ─────────────────────────────────────────

	public static function ajax_clear_import(): void {
		check_ajax_referer( 'wms_jp_import' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		global $wpdb;
		$table   = $wpdb->prefix . WMS_TABLE_NAME;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM `{$table}` WHERE ip_hash = %s", self::IMPORT_MARK )
		);

		wp_send_json_success( [ 'deleted' => $deleted ] );
	}

	// ── AJAX: raw API diagnostic ──────────────────────────────────────────────

	public static function ajax_debug(): void {
		check_ajax_referer( 'wms_jp_import' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_send_json_error( 'Missing post_id.' );
		}

		if ( ! class_exists( 'Jetpack_Options' ) ) {
			wp_send_json_error( 'Jetpack_Options class not found.' );
		}

		$site_id = (string) Jetpack_Options::get_option( 'id' );
		$path    = '/sites/' . rawurlencode( $site_id ) . '/stats/post/' . $post_id;

		// Check whether a user token exists for the current user
		$has_user_token = false;
		if ( class_exists( 'Automattic\Jetpack\Connection\Manager' ) ) {
			$manager        = new Automattic\Jetpack\Connection\Manager();
			$has_user_token = (bool) $manager->get_access_token( get_current_user_id(), false, false );
		} elseif ( class_exists( 'Jetpack_Data' ) ) {
			$has_user_token = (bool) Jetpack_Data::get_access_token( get_current_user_id() );
		}

		$results = [ 'site_id' => $site_id, 'path' => $path, 'has_user_token' => $has_user_token ];

		// Get the raw user token secret so we can make a direct request,
		// bypassing the Jetpack client entirely (to rule out local proxy interception).
		$token_secret = '';
		if ( class_exists( 'Automattic\Jetpack\Connection\Manager' ) ) {
			$tok = ( new Automattic\Jetpack\Connection\Manager() )->get_access_token( get_current_user_id() );
			if ( $tok && ! empty( $tok->secret ) ) {
				$token_secret = $tok->secret;
			}
		} elseif ( class_exists( 'Jetpack_Data' ) ) {
			$tok = Jetpack_Data::get_access_token( get_current_user_id() );
			if ( $tok && ! empty( $tok->secret ) ) {
				$token_secret = $tok->secret;
			}
		}

		$results['token_secret_length'] = strlen( $token_secret );

		// Direct wp_remote_get — bypasses Jetpack client and any proxy filters
		$direct = function( string $url ) use ( $token_secret ): array {
			$r = wp_remote_get( $url, [
				'headers' => [ 'Authorization' => 'X_JETPACK token=' . $token_secret ],
				'timeout' => 15,
				'sslverify' => true,
			] );
			if ( is_wp_error( $r ) ) {
				return [ 'wp_error' => $r->get_error_message() ];
			}
			return [
				'http_code' => wp_remote_retrieve_response_code( $r ),
				'body'      => json_decode( wp_remote_retrieve_body( $r ), true ),
			];
		};

		$results['direct_sitewide_3332322']  = $direct( 'https://public-api.wordpress.com/rest/v1.1/sites/3332322/stats/' );
		$results['direct_post_3332322']      = $direct( 'https://public-api.wordpress.com/rest/v1.1/sites/3332322/stats/post/' . $post_id );
		$results['direct_sitewide_5836086']  = $direct( 'https://public-api.wordpress.com/rest/v1.1/sites/5836086/stats/' );

		wp_send_json_success( $results );
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Return true if Jetpack's connection client is available and the site is connected.
	 */
	private static function jetpack_client_available(): bool {
		if ( ! class_exists( 'Jetpack_Options' ) ) {
			return false;
		}
		if ( ! Jetpack_Options::get_option( 'id' ) ) {
			return false;
		}
		return class_exists( 'Automattic\Jetpack\Connection\Client' )
			|| class_exists( 'Jetpack_Client' );
	}

	/**
	 * Return true if this post already has imported rows in the database.
	 */
	private static function has_imported_data( int $post_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . WMS_TABLE_NAME;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM `{$table}` WHERE post_id = %d AND ip_hash = %s LIMIT 1",
				$post_id,
				self::IMPORT_MARK
			)
		);
	}

	/**
	 * Fetch daily view counts for a post using Jetpack's stored connection.
	 *
	 * @return array<string,int>|WP_Error  e.g. ['2023-01-01' => 45, ...]
	 */
	private static function fetch_post_stats( int $post_id ) {
		if ( ! class_exists( 'Jetpack_Options' ) ) {
			return new WP_Error( 'no_jetpack', 'Jetpack is not active.' );
		}

		$site_id = (string) Jetpack_Options::get_option( 'id' );
		if ( ! $site_id ) {
			return new WP_Error( 'no_site_id', 'Jetpack site ID not found — is Jetpack connected?' );
		}

		$path = '/sites/' . rawurlencode( $site_id ) . '/stats/post/' . $post_id;

		// Use the blog token (always present when Jetpack is connected) with the
		// v1.1 base URL explicitly — the default v2 base doesn't have the stats route.
		if ( class_exists( 'Automattic\Jetpack\Connection\Client' ) ) {
			$response = Automattic\Jetpack\Connection\Client::wpcom_json_api_request_as_blog(
				$path,
				'1.1',
				[],
				null,
				'https://public-api.wordpress.com'
			);
		} elseif ( class_exists( 'Jetpack_Client' ) ) {
			$response = Jetpack_Client::wpcom_json_api_request_as_blog( $path, '1.1' );
		} else {
			return new WP_Error( 'no_client', 'Jetpack HTTP client not found.' );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 401 === $code ) {
			return new WP_Error( 'auth', 'Jetpack authentication failed — try reconnecting Jetpack.' );
		}
		if ( 403 === $code ) {
			return new WP_Error( 'forbidden', 'Jetpack connection does not have access to stats for this site.' );
		}
		if ( 404 === $code ) {
			// Post exists locally but has no Jetpack stats record — normal for new or unviewed posts
			return [];
		}
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'api', "WordPress.com API returned HTTP {$code}." );
		}

		if ( empty( $body['data'] ) || ! is_array( $body['data'] ) ) {
			return [];
		}

		// Response format: "data" => [["2023-01-01", 45], ["2023-01-02", 12], ...]
		$result = [];
		foreach ( $body['data'] as $row ) {
			if ( is_array( $row ) && isset( $row[0], $row[1] ) && (int) $row[1] > 0 ) {
				$date = sanitize_text_field( $row[0] );
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
					$result[ $date ] = (int) $row[1];
				}
			}
		}
		return $result;
	}

	/**
	 * Bulk-insert synthetic view rows for a post's historical data.
	 *
	 * @param  int               $post_id
	 * @param  array<string,int> $daily    ['YYYY-MM-DD' => view_count]
	 * @return array{views:int, dates:int}
	 */
	private static function insert_rows( int $post_id, array $daily ): array {
		global $wpdb;
		$table      = $wpdb->prefix . WMS_TABLE_NAME;
		$total_views = 0;
		$total_dates = 0;
		$buffer      = [];
		$chunk_size  = 500;

		foreach ( $daily as $date => $count ) {
			$viewed_at = $date . ' 12:00:00';
			for ( $i = 0; $i < $count; $i++ ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$buffer[] = $wpdb->prepare( '(%d, %s, %s, 0)', $post_id, $viewed_at, self::IMPORT_MARK );
			}
			$total_dates++;
			$total_views += $count;

			if ( count( $buffer ) >= $chunk_size ) {
				self::flush_buffer( $table, $buffer );
				$buffer = [];
			}
		}

		if ( ! empty( $buffer ) ) {
			self::flush_buffer( $table, $buffer );
		}

		return [ 'views' => $total_views, 'dates' => $total_dates ];
	}

	/**
	 * Execute one bulk INSERT for a buffer of prepared value strings.
	 */
	private static function flush_buffer( string $table, array $buffer ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			"INSERT INTO `{$table}` (post_id, viewed_at, ip_hash, is_new_visitor) VALUES "
			. implode( ', ', $buffer )
		);
	}
}
