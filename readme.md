# WP Milner Stats

Lightweight post view tracking with day, week, month, year, and multi-year breakdowns. No external services. No bloat.

---

## 📋 Metadata

* **Contributors:** jeffmilner
* **Tags:** stats, analytics, post views, popular posts, trending
* **Requires at least:** 5.8
* **Tested up to:** 6.7
* **Stable tag:** 1.0.0
* **Requires PHP:** 7.4
* **License:** [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html)

---

## 🚀 Description

**WP Stats Counter** gives you Jetpack-style view tracking for your posts and pages — without the bloat of a full Jetpack installation.

### Key Features:

* **View tracking** — Counts page views for all post types using a non-blocking JS beacon.
* **Time-period breakdowns** — Day, week, month, and year views for every post.
* **Trending posts** — Identifies posts with unusual traffic spikes vs. their 30-day average.
* **Admin dashboard** — Full stats page with an interactive Chart.js activity graph and sortable top-posts table.
* **Dashboard widget** — A quick stats summary on the WordPress dashboard home.
* **Admin bar** — View count for the current post shown in the top toolbar.
* **Post list columns** — View counts in the Posts and Pages admin list tables (sortable!).
* **Editor meta box** — Per-post stats panel with a 14-day sparkline in the block editor.
* **Sidebar widget** — Display Popular Posts in any widget area with full customization.
* **Shortcodes** — `[wms_views]` and `[wms_top_posts]` for use anywhere in content.
* **Template tags** — `wms_get_views()`, `wms_the_views()`, `wms_has_views()` for theme developers.
* **CSV export** — Download your stats as spreadsheets (top posts, daily totals, raw data).
* **Settings page** — Configure tracked post types, deduplication window, and admin exclusion.
* **Caching plugin compatible** — Works with WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache.
* **Privacy-friendly** — IP addresses are one-way hashed, never stored in plain text.
* **Bot detection** — Automatically skips crawlers and search engine bots.
* **No external services** — All data stays in your WordPress database.
* **Automatic cleanup** — Daily cron job removes records older than 366 days to keep the DB lean.

---

## 🛠 Usage

### Shortcode examples:

```markdown
[wms_views]                                   — Views for the current post (all-time)
[wms_views period="week"]                     — Views in the last 7 days
[wms_views id="42" label="Views: "]           — Views for post ID 42 with a label
[wms_top_posts]                               — Top 5 posts this week
[wms_top_posts period="month" limit="10" show_count="true"]

```

### Template tag examples:

```php
<?php wms_the_views(); ?>                         // Echo all-time views
<?php echo wms_get_views( null, 'week' ); ?>      // Get this week's views as integer
<?php if ( wms_has_views() ) { ... }  ?>          // Conditional: post has been viewed

```

### Available REST endpoints (Admin only):

| Method | Endpoint | Description |
| --- | --- | --- |
| **GET** | `/wp-json/wp-milner-stats/v1/stats` | Summary counts |
| **GET** | `/wp-json/wp-milner-stats/v1/stats/posts` | Top posts by period |
| **GET** | `/wp-json/wp-milner-stats/v1/stats/chart` | Time-series chart data |
| **GET** | `/wp-json/wp-milner-stats/v1/stats/post/{id}` | Per-post counts + chart |
| **GET** | `/wp-json/wp-milner-stats/v1/stats/trending` | Trending posts |
| **POST** | `/wp-json/wp-milner-stats/v1/track` | Record a view (public) |
| **POST** | `/wp-json/wp-milner-stats/v1/cache/flush` | Flush stats cache |

---

## ⚙️ Installation

1. Upload the `wp-milner-stats` folder to `/wp-content/plugins/` — or install directly via **Plugins > Add New > Upload Plugin**.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **Stats Counter** in your admin sidebar.

*The plugin creates a `{prefix}_wms_post_views` table on activation. No additional configuration is required.*

---

## ❓ FAQ

**Does this work with caching plugins?** Yes. The tracker uses JavaScript `fetch()` / `sendBeacon()`, which fires after the page loads from cache.

**Will it slow down my site?** No. The frontend tracker script is tiny (~1 KB) and fires via `requestIdleCallback`. It has zero impact on page load time or Core Web Vitals.

**Does it track logged-in admins?** By default, views from users with `edit_posts` capability are excluded. This is configurable in Settings.

**Does it store IP addresses?** No. IP addresses are processed through a one-way SHA-256 hash with a site-specific salt.

**What does "Trending" mean?** A post is trending when its views in the last 24 hours are $\ge 2.0$x its daily average over the past 30 days (minimum 3 views today).

---

## 📸 Screenshots

1. **Admin Dashboard** — Activity chart with range selector and top posts table.
2. **Trending Posts** — Posts with unusual traffic spikes.
3. **Editor Meta Box** — Per-post stats with 14-day sparkline.
4. **Posts List Column** — View counts with today's delta.
5. **Dashboard Widget** — Quick summary on the WordPress dashboard home.
6. **Admin Bar** — Current page stats in the toolbar.
7. **Sidebar Widget** — Popular Posts widget on the frontend.
8. **Settings Page** — Configure tracking behavior.
9. **Export Page** — Download stats as CSV files.

---

## 📜 Changelog

### 1.0.0

* Initial release