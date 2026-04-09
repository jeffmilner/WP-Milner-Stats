=== Milner Stats ===
Contributors: jeffmilner
Tags: analytics, post views, statistics, popular posts, tracking
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight post view tracking with day, week, month, year, and multi-year breakdowns. No external services. No bloat.

== Description ==

Milner Stats gives you simple, privacy-friendly view tracking for your posts and pages — all stored in your own WordPress database with no third-party services involved.

**Key Features:**

* **View tracking** — Counts page views using a non-blocking JS beacon (works with all caching plugins).
* **Views vs Visitors** — Separate counts for total views and unique visitors per period.
* **Time-period breakdowns** — Last 24h, 7, 30, 365 days and multi-year ranges.
* **Activity chart** — Interactive Chart.js dual-line graph (views + visitors) with range selector.
* **Top posts table** — Sortable top-posts list by any period.
* **Trending posts** — Identifies posts with unusual traffic spikes vs. their 30-day average.
* **Referrer tracking** — Top referring domains and search terms.
* **Outbound link tracking** — Track clicks on external links.
* **Admin bar sparkline** — 7-day mini sparkline graph in the WordPress toolbar.
* **Admin dashboard** — Full stats page under the Milner Stats menu.
* **Dashboard widget** — Quick stats summary on the WordPress dashboard home.
* **Post list columns** — View counts in the Posts and Pages admin tables (sortable).
* **Editor meta box** — Per-post stats panel with a 14-day sparkline in the block editor.
* **Sidebar widget** — "Popular Posts" widget for any widget area.
* **Shortcodes** — `[wms_views]` and `[wms_top_posts]` for use in content.
* **Template tags** — `wms_get_views()`, `wms_the_views()`, `wms_has_views()` for theme developers.
* **CSV export** — Download stats as spreadsheets (top posts, daily totals, raw data).
* **Settings page** — Configure tracked post types, deduplication window, and admin exclusion.
* **Caching compatible** — Works with WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache.
* **Privacy-friendly** — IP addresses are one-way SHA-256 hashed, never stored in plain text.
* **Bot detection** — Automatically skips crawlers and search engine bots.
* **Automatic cleanup** — Daily cron removes records older than 366 days.

== Installation ==

1. Upload the `milner-stats` folder to `/wp-content/plugins/`.
2. Activate the plugin through **Plugins > Installed Plugins**.
3. Navigate to **Milner Stats** in your admin sidebar.

The plugin creates its database tables on activation. No additional configuration is required.

== Frequently Asked Questions ==

= Does this work with caching plugins? =
Yes. The tracker uses JavaScript `fetch()` / `sendBeacon()`, which fires after the page loads — bypassing page cache entirely.

= Will it slow down my site? =
No. The frontend script is tiny (~1 KB) and defers via `requestIdleCallback`. It has zero impact on page load time or Core Web Vitals.

= Does it track logged-in admins? =
By default, views from users with `edit_posts` capability are excluded. This is configurable under Settings.

= Does it store IP addresses? =
No. IP addresses are processed through a one-way SHA-256 hash with a site-specific salt. The original IP is never stored.

= What does "Trending" mean? =
A post is trending when its views in the last 24 hours are ≥ 2× its daily average over the past 30 days (minimum 3 views today).

= Why are my Visitors and Views counts similar? =
This is expected for sites where most visitors read one post per session. Views counts every qualifying page load; Visitors counts unique IPs per day across the whole site.

== Screenshots ==

1. Admin Dashboard — Activity chart with range selector and top posts table.
2. Trending Posts — Posts with unusual traffic spikes.
3. Editor Meta Box — Per-post stats with 14-day sparkline.
4. Posts List Column — View counts with today's delta.
5. Dashboard Widget — Quick summary on the WordPress dashboard home.
6. Admin Bar — 7-day sparkline in the toolbar.
7. Sidebar Widget — Popular Posts widget on the frontend.
8. Settings Page — Configure tracking behaviour.
9. Export Page — Download stats as CSV files.

== Changelog ==

= 1.1.0 =
* Added: Views vs Visitors separation (unique visitor tracking per day, sitewide).
* Added: Dual-line activity chart (views + visitors).
* Added: Referrer domain and search term tracking.
* Added: Outbound link click tracking.
* Added: 7-day sparkline in the admin bar instead of plain text.
* Fixed: Unique visitor count was scoped per-post instead of per-site, causing visitors ≈ views.
* Bundled: Chart.js 4.4.3 (no longer loaded from CDN).

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.1.0 =
Fixes unique visitor counting. Historical visitor counts recorded before this update will remain as-is; new views will be counted accurately.
