/**
 * WP Milner Stats — Admin Dashboard JS
 *
 * Sections:
 *   1. Activity Chart  — dual-line (Views + Visitors) via Chart.js
 *   2. Top Posts       — tabbed table by period
 *   3. Trending        — velocity-scored posts
 *   4. Referrers       — top referrer domains + summary bar
 *   5. Search Terms    — extracted from referrer URLs
 *   6. Outbound Links  — top clicked links + top domains
 */

/* global wmsAdmin, Chart */
( function () {
	'use strict';

	if ( typeof wmsAdmin === 'undefined' ) return;

	// ── State ──────────────────────────────────────────────────────────────
	var activityChart    = null;
	var currentRange     = '24h';
	var currentPeriod    = 'day';
	var referrerPeriod   = 'day';
	var outlinkPeriod    = 'day';

	// ── Fetch helper ───────────────────────────────────────────────────────
	function apiFetch( path ) {
		return fetch( wmsAdmin.restBase + path, {
			headers: { 'X-WP-Nonce': wmsAdmin.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
		} ).then( function ( res ) {
			if ( ! res.ok ) throw new Error( 'HTTP ' + res.status );
			return res.json();
		} );
	}

	// ── Escape helpers ─────────────────────────────────────────────────────
	function escHtml( str ) {
		var d = document.createElement( 'div' );
		d.appendChild( document.createTextNode( String( str ) ) );
		return d.innerHTML;
	}
	function escAttr( str ) {
		return String( str )
			.replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
	}
	function spinner() {
		return '<div class="wms-table-loading"><span class="wms-spinner"></span></div>';
	}
	function emptyState( msg ) {
		return '<div class="wms-empty"><span class="wms-empty__icon dashicons dashicons-chart-bar"></span>'
			+ '<p class="wms-empty__text">' + escHtml( msg ) + '</p></div>';
	}

	// ══════════════════════════════════════════════════════════════════════
	// 1. ACTIVITY CHART
	// ══════════════════════════════════════════════════════════════════════

	function bindChartControls() {
		var btns = document.querySelectorAll( '.wms-chart-controls .wms-btn' );
		btns.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				btns.forEach( function ( b ) { b.classList.remove( 'wms-btn--active' ); } );
				btn.classList.add( 'wms-btn--active' );
				currentRange = btn.dataset.range;
				loadChartData( currentRange );
			} );
		} );
	}

	function loadChartData( range ) {
		var loading = document.getElementById( 'wms-chart-loading' );
		if ( loading ) loading.classList.remove( 'is-hidden' );

		apiFetch( '/stats/chart?range=' + encodeURIComponent( range ) )
			.then( renderChart )
			.catch( function () {} )
			.finally( function () {
				if ( loading ) loading.classList.add( 'is-hidden' );
			} );
	}

	function renderChart( data ) {
		var canvas = document.getElementById( 'wms-activity-chart' );
		if ( ! canvas ) return;

		if ( activityChart ) { activityChart.destroy(); activityChart = null; }

		var labels   = data.map( function ( d ) { return d.label; } );
		var views    = data.map( function ( d ) { return d.views; } );
		var visitors = data.map( function ( d ) { return d.visitors; } );

		var ctx = canvas.getContext( '2d' );

		var gradViews = ctx.createLinearGradient( 0, 0, 0, 280 );
		gradViews.addColorStop( 0,   'rgba(34, 113, 177, 0.20)' );
		gradViews.addColorStop( 1,   'rgba(34, 113, 177, 0)' );

		var gradVisitors = ctx.createLinearGradient( 0, 0, 0, 280 );
		gradVisitors.addColorStop( 0,   'rgba(0, 163, 42, 0.15)' );
		gradVisitors.addColorStop( 1,   'rgba(0, 163, 42, 0)' );

		var pointRadius = labels.length <= 48 ? 3 : 0;

		activityChart = new Chart( ctx, {
			type: 'line',
			data: {
				labels: labels,
				datasets: [
					{
						label:                'Views',
						data:                 views,
						fill:                 true,
						backgroundColor:      gradViews,
						borderColor:          'rgba(34, 113, 177, 1)',
						borderWidth:          2,
						pointRadius:          pointRadius,
						pointHoverRadius:     5,
						pointBackgroundColor: '#fff',
						pointBorderColor:     'rgba(34, 113, 177, 1)',
						tension:              0.35,
						order:                1,
					},
					{
						label:                'Visitors',
						data:                 visitors,
						fill:                 true,
						backgroundColor:      gradVisitors,
						borderColor:          'rgba(0, 163, 42, 0.9)',
						borderWidth:          2,
						pointRadius:          pointRadius,
						pointHoverRadius:     5,
						pointBackgroundColor: '#fff',
						pointBorderColor:     'rgba(0, 163, 42, 0.9)',
						tension:              0.35,
						order:                2,
					},
				],
			},
			options: {
				responsive:          true,
				maintainAspectRatio: true,
				interaction:         { mode: 'index', intersect: false },
				plugins: {
					legend: { display: false }, // We render our own legend in PHP
					tooltip: {
						backgroundColor: '#1d2327',
						titleFont:       { size: 12, weight: '600' },
						bodyFont:        { size: 13 },
						padding:         10,
						cornerRadius:    6,
						callbacks: {
							label: function ( ctx ) {
								return ' ' + ctx.dataset.label + ': ' + ctx.parsed.y.toLocaleString();
							},
						},
					},
				},
				scales: {
					x: {
						grid:  { color: 'rgba(0,0,0,.04)', drawTicks: false },
						ticks: { color: '#6b7280', font: { size: 11 }, maxRotation: 0, maxTicksLimit: 12 },
						border: { color: '#dcdcde' },
					},
					y: {
						beginAtZero: true,
						grid:  { color: 'rgba(0,0,0,.04)', drawTicks: false },
						ticks: {
							color: '#6b7280', font: { size: 11 }, maxTicksLimit: 6,
							callback: function ( v ) { return v >= 1000 ? ( v / 1000 ).toFixed(1) + 'k' : v; },
						},
						border: { display: false },
					},
				},
			},
		} );
	}

	// ══════════════════════════════════════════════════════════════════════
	// 2. TOP POSTS
	// ══════════════════════════════════════════════════════════════════════

	var topPostsLimit = 10; // current limit; toggled by "Show All" button

	function bindPeriodTabs() {
		var tabs = document.querySelectorAll( '.wms-period-tabs:not(.wms-insights-tabs) .wms-tab' );
		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				tabs.forEach( function ( t ) {
					t.classList.remove( 'wms-tab--active' );
					t.setAttribute( 'aria-selected', 'false' );
				} );
				tab.classList.add( 'wms-tab--active' );
				tab.setAttribute( 'aria-selected', 'true' );
				currentPeriod = tab.dataset.period;
				loadTopPosts( currentPeriod, topPostsLimit );
			} );
		} );

		// Show All / Top 10 toggle
		var toggleBtn = document.getElementById( 'wms-toggle-all-posts' );
		if ( toggleBtn ) {
			toggleBtn.addEventListener( 'click', function () {
				var i18n = ( wmsAdmin.i18n || {} );
				if ( topPostsLimit === 10 ) {
					topPostsLimit = 500;
					toggleBtn.textContent = i18n.showTop10 || 'Top 10';
					toggleBtn.dataset.showing = '500';
					toggleBtn.setAttribute( 'aria-label', i18n.showTop10AriaLabel || 'Show only the top 10 posts' );
				} else {
					topPostsLimit = 10;
					toggleBtn.textContent = i18n.showAll || 'Show All';
					toggleBtn.dataset.showing = '10';
					toggleBtn.setAttribute( 'aria-label', i18n.showAllAriaLabel || 'Show all posts for this period' );
				}
				loadTopPosts( currentPeriod, topPostsLimit );
			} );
		}
	}

	function loadTopPosts( period, limit ) {
		limit = limit || 10;
		var container = document.getElementById( 'wms-top-posts' );
		if ( ! container ) return;
		container.innerHTML = spinner();

		apiFetch( '/stats/posts?period=' + period + '&limit=' + limit )
			.then( function ( posts ) { renderTopPosts( container, posts ); } )
			.catch( function () {
				container.innerHTML = emptyState( 'Could not load stats. Please refresh.' );
			} );
	}

	function renderTopPosts( container, posts ) {
		if ( ! posts || ! posts.length ) {
			container.innerHTML = emptyState( 'No views recorded for this period yet.' );
			return;
		}
		var max  = posts[0].views;
		var rows = posts.map( function ( p, i ) {
			var pct = max > 0 ? Math.round( p.views / max * 100 ) : 0;
			return '<tr>'
				+ '<td class="wms-col-rank">' + ( i + 1 ) + '</td>'
				+ '<td class="wms-col-title"><a href="' + escAttr( p.permalink ) + '" target="_blank" rel="noopener">' + escHtml( p.post_title || '(no title)' ) + '</a></td>'
				+ '<td class="wms-col-type"><span class="wms-col-type-badge">' + escHtml( p.post_type ) + '</span></td>'
				+ '<td class="wms-col-views"><div class="wms-views-bar-wrapper">'
				+ '<div class="wms-views-bar"><div class="wms-views-bar__fill" style="width:' + pct + '%"></div></div>'
				+ '<span class="wms-views-count">' + p.views.toLocaleString() + '</span></div></td>'
				+ '<td class="wms-col-actions">' + ( p.edit_url ? '<a href="' + escAttr( p.edit_url ) + '">✏️</a>' : '' ) + '</td>'
				+ '</tr>';
		} ).join( '' );

		container.innerHTML = '<table class="wms-posts-table"><thead><tr>'
			+ '<th class="wms-col-rank">#</th><th>Post / Page</th><th class="wms-col-type">Type</th>'
			+ '<th class="wms-col-views" style="text-align:right">Views</th><th class="wms-col-actions"></th>'
			+ '</tr></thead><tbody>' + rows + '</tbody></table>';

		requestAnimationFrame( function () {
			container.querySelectorAll( '.wms-views-bar__fill' ).forEach( function ( el ) {
				var w = el.style.width; el.style.width = '0';
				requestAnimationFrame( function () { el.style.width = w; } );
			} );
		} );
	}

	// ══════════════════════════════════════════════════════════════════════
	// 3. TRENDING
	// ══════════════════════════════════════════════════════════════════════

	function loadTrending( container ) {
		container.innerHTML = spinner();
		apiFetch( '/stats/trending?limit=10' )
			.then( function ( posts ) { renderTrending( container, posts ); } )
			.catch( function () { container.innerHTML = emptyState( 'Could not load trending data.' ); } );
	}

	function renderTrending( container, posts ) {
		if ( ! posts || ! posts.length ) {
			container.innerHTML = emptyState( 'No trending posts right now. Check back after more traffic.' );
			return;
		}
		var rows = posts.map( function ( p, i ) {
			var cls = p.trend_score >= 5 ? 'wms-trend-score wms-trend-score--high' : 'wms-trend-score';
			return '<tr>'
				+ '<td class="wms-col-rank">' + ( i + 1 ) + '</td>'
				+ '<td class="wms-col-title"><a href="' + escAttr( p.permalink ) + '" target="_blank" rel="noopener">' + escHtml( p.post_title || '(no title)' ) + '</a></td>'
				+ '<td class="wms-col-type"><span class="wms-col-type-badge">' + escHtml( p.post_type ) + '</span></td>'
				+ '<td style="text-align:right;padding-right:16px"><span class="' + cls + '">🔥 ' + escHtml( String( p.trend_score ) ) + '×</span></td>'
				+ '<td style="text-align:right;padding-right:16px"><span class="wms-views-count">' + escHtml( String( p.views_today ) ) + '</span><span style="font-size:11px;color:#888;display:block">today</span></td>'
				+ '<td style="font-size:12px;color:#888;padding-right:16px">avg ' + escHtml( String( p.avg_daily ) ) + '/day</td>'
				+ '<td class="wms-col-actions">' + ( p.edit_url ? '<a href="' + escAttr( p.edit_url ) + '">✏️</a>' : '' ) + '</td>'
				+ '</tr>';
		} ).join( '' );

		container.innerHTML = '<table class="wms-posts-table"><thead><tr>'
			+ '<th class="wms-col-rank">#</th><th>Post / Page</th><th class="wms-col-type">Type</th>'
			+ '<th style="text-align:right">Trend</th><th style="text-align:right">Today</th>'
			+ '<th>Avg</th><th class="wms-col-actions"></th>'
			+ '</tr></thead><tbody>' + rows + '</tbody></table>';
	}

	// ══════════════════════════════════════════════════════════════════════
	// 4 & 5. REFERRERS + SEARCH TERMS
	// ══════════════════════════════════════════════════════════════════════

	function loadReferrers( period ) {
		var summaryEl  = document.getElementById( 'wms-referrer-summary' );
		var refList    = document.getElementById( 'wms-referrers-list' );
		var termsList  = document.getElementById( 'wms-search-terms-list' );

		if ( summaryEl ) summaryEl.innerHTML = spinner();
		if ( refList   ) refList.innerHTML   = spinner();
		if ( termsList ) termsList.innerHTML  = spinner();

		// Fetch referrers (includes summary) and search terms in parallel
		Promise.all( [
			apiFetch( '/stats/referrers?period='    + period ),
			apiFetch( '/stats/search-terms?period=' + period ),
		] ).then( function ( results ) {
			var refData   = results[0];
			var termData  = results[1];

			if ( summaryEl ) renderReferrerSummary( summaryEl, refData.summary );
			if ( refList   ) renderReferrerList( refList, refData.referrers );
			if ( termsList ) renderSearchTerms( termsList, termData );
		} ).catch( function () {
			if ( summaryEl ) summaryEl.innerHTML = '';
			if ( refList   ) refList.innerHTML   = emptyState( 'Could not load referrer data.' );
			if ( termsList ) termsList.innerHTML  = emptyState( 'Could not load search term data.' );
		} );
	}

	function renderReferrerSummary( el, summary ) {
		if ( ! summary ) { el.innerHTML = ''; return; }
		el.innerHTML =
			'<div class="wms-ref-summary">'
			+ '<div class="wms-ref-summary__item"><span class="wms-ref-summary__val">' + ( summary.total_referrals || 0 ).toLocaleString() + '</span><span class="wms-ref-summary__label">Referral Visits</span></div>'
			+ '<div class="wms-ref-summary__item"><span class="wms-ref-summary__val">' + ( summary.search_referrals || 0 ).toLocaleString() + '</span><span class="wms-ref-summary__label">Search Visits</span></div>'
			+ '<div class="wms-ref-summary__item"><span class="wms-ref-summary__val">' + ( summary.direct_views || 0 ).toLocaleString() + '</span><span class="wms-ref-summary__label">Direct / Unknown</span></div>'
			+ '</div>';
	}

	function renderReferrerList( container, referrers ) {
		if ( ! referrers || ! referrers.length ) {
			container.innerHTML = emptyState( 'No referrer data for this period.' );
			return;
		}
		var max  = referrers[0].visits;
		var rows = referrers.map( function ( r, i ) {
			var pct = max > 0 ? Math.round( r.visits / max * 100 ) : 0;
			return '<tr>'
				+ '<td class="wms-col-rank">' + ( i + 1 ) + '</td>'
				+ '<td class="wms-col-title">'
				+   '<a href="https://' + escAttr( r.referrer_host ) + '" target="_blank" rel="noopener noreferrer">'
				+     escHtml( r.referrer_host ) + '</a></td>'
				+ '<td class="wms-col-views"><div class="wms-views-bar-wrapper">'
				+   '<div class="wms-views-bar"><div class="wms-views-bar__fill" style="width:' + pct + '%"></div></div>'
				+   '<span class="wms-views-count">' + r.visits.toLocaleString() + '</span>'
				+ '</div></td>'
				+ '</tr>';
		} ).join( '' );

		container.innerHTML = '<table class="wms-posts-table"><thead><tr>'
			+ '<th class="wms-col-rank">#</th><th>Domain</th>'
			+ '<th class="wms-col-views" style="text-align:right">Visits</th>'
			+ '</tr></thead><tbody>' + rows + '</tbody></table>';
	}

	function renderSearchTerms( container, terms ) {
		if ( ! terms || ! terms.length ) {
			container.innerHTML = emptyState( 'No search terms recorded. (Most engines hide search terms — this captures what is available.)' );
			return;
		}
		var max  = terms[0].count;
		var rows = terms.map( function ( t, i ) {
			var pct = max > 0 ? Math.round( t.count / max * 100 ) : 0;
			return '<tr>'
				+ '<td class="wms-col-rank">' + ( i + 1 ) + '</td>'
				+ '<td class="wms-col-title">' + escHtml( t.search_term ) + '</td>'
				+ '<td class="wms-col-views"><div class="wms-views-bar-wrapper">'
				+   '<div class="wms-views-bar"><div class="wms-views-bar__fill" style="width:' + pct + '%"></div></div>'
				+   '<span class="wms-views-count">' + t.count.toLocaleString() + '</span>'
				+ '</div></td>'
				+ '</tr>';
		} ).join( '' );

		container.innerHTML = '<table class="wms-posts-table"><thead><tr>'
			+ '<th class="wms-col-rank">#</th><th>Search Term</th>'
			+ '<th class="wms-col-views" style="text-align:right">Visits</th>'
			+ '</tr></thead><tbody>' + rows + '</tbody></table>';
	}

	// ══════════════════════════════════════════════════════════════════════
	// 6. OUTBOUND LINKS
	// ══════════════════════════════════════════════════════════════════════

	function loadOutlinks( period ) {
		var linksList    = document.getElementById( 'wms-outlinks-list' );
		var domainsList  = document.getElementById( 'wms-outlink-domains-list' );

		if ( linksList   ) linksList.innerHTML   = spinner();
		if ( domainsList ) domainsList.innerHTML  = spinner();

		apiFetch( '/stats/outlinks?period=' + period )
			.then( function ( data ) {
				if ( linksList   ) renderOutlinks( linksList, data.links );
				if ( domainsList ) renderOutlinkDomains( domainsList, data.domains );
			} )
			.catch( function () {
				if ( linksList   ) linksList.innerHTML   = emptyState( 'Could not load outlink data.' );
				if ( domainsList ) domainsList.innerHTML  = emptyState( 'Could not load outlink data.' );
			} );
	}

	function renderOutlinks( container, links ) {
		if ( ! links || ! links.length ) {
			container.innerHTML = emptyState( 'No outbound link clicks recorded for this period.' );
			return;
		}
		var max  = links[0].clicks;
		var rows = links.map( function ( l, i ) {
			var pct       = max > 0 ? Math.round( l.clicks / max * 100 ) : 0;
			var shortUrl  = l.link_url.length > 55 ? l.link_url.substring( 0, 52 ) + '…' : l.link_url;
			return '<tr>'
				+ '<td class="wms-col-rank">' + ( i + 1 ) + '</td>'
				+ '<td class="wms-col-title">'
				+   '<a href="' + escAttr( l.link_url ) + '" target="_blank" rel="noopener noreferrer" title="' + escAttr( l.link_url ) + '">'
				+     escHtml( l.link_text || shortUrl ) + '</a>'
				+   '<span style="display:block;font-size:11px;color:#888">' + escHtml( l.link_host ) + '</span>'
				+ '</td>'
				+ '<td class="wms-col-views"><div class="wms-views-bar-wrapper">'
				+   '<div class="wms-views-bar"><div class="wms-views-bar__fill" style="width:' + pct + '%"></div></div>'
				+   '<span class="wms-views-count">' + l.clicks.toLocaleString() + '</span>'
				+ '</div></td>'
				+ '</tr>';
		} ).join( '' );

		container.innerHTML = '<table class="wms-posts-table"><thead><tr>'
			+ '<th class="wms-col-rank">#</th><th>Link</th>'
			+ '<th class="wms-col-views" style="text-align:right">Clicks</th>'
			+ '</tr></thead><tbody>' + rows + '</tbody></table>';
	}

	function renderOutlinkDomains( container, domains ) {
		if ( ! domains || ! domains.length ) {
			container.innerHTML = emptyState( 'No outbound domain clicks recorded for this period.' );
			return;
		}
		var max  = domains[0].clicks;
		var rows = domains.map( function ( d, i ) {
			var pct = max > 0 ? Math.round( d.clicks / max * 100 ) : 0;
			return '<tr>'
				+ '<td class="wms-col-rank">' + ( i + 1 ) + '</td>'
				+ '<td class="wms-col-title">'
				+   '<a href="https://' + escAttr( d.link_host ) + '" target="_blank" rel="noopener noreferrer">'
				+     escHtml( d.link_host ) + '</a>'
				+   '<span style="font-size:11px;color:#888;margin-left:6px">' + d.unique_urls + ' unique URL' + ( d.unique_urls !== 1 ? 's' : '' ) + '</span>'
				+ '</td>'
				+ '<td class="wms-col-views"><div class="wms-views-bar-wrapper">'
				+   '<div class="wms-views-bar"><div class="wms-views-bar__fill" style="width:' + pct + '%"></div></div>'
				+   '<span class="wms-views-count">' + d.clicks.toLocaleString() + '</span>'
				+ '</div></td>'
				+ '</tr>';
		} ).join( '' );

		container.innerHTML = '<table class="wms-posts-table"><thead><tr>'
			+ '<th class="wms-col-rank">#</th><th>Domain</th>'
			+ '<th class="wms-col-views" style="text-align:right">Clicks</th>'
			+ '</tr></thead><tbody>' + rows + '</tbody></table>';
	}

	// ══════════════════════════════════════════════════════════════════════
	// INSIGHTS TABS (shared handler for referrer + outlink sections)
	// ══════════════════════════════════════════════════════════════════════

	function bindInsightsTabs() {
		document.querySelectorAll( '.wms-insights-tabs' ).forEach( function ( tabGroup ) {
			var section = tabGroup.dataset.insightsSection;
			var tabs    = tabGroup.querySelectorAll( '.wms-tab' );

			tabs.forEach( function ( tab ) {
				tab.addEventListener( 'click', function () {
					tabs.forEach( function ( t ) {
						t.classList.remove( 'wms-tab--active' );
						t.setAttribute( 'aria-selected', 'false' );
					} );
					tab.classList.add( 'wms-tab--active' );
					tab.setAttribute( 'aria-selected', 'true' );

					var period = tab.dataset.period;

					if ( section === 'referrers' ) {
						referrerPeriod = period;
						loadReferrers( period );
					} else if ( section === 'outlinks' ) {
						outlinkPeriod = period;
						loadOutlinks( period );
					}
				} );
			} );
		} );
	}

	// ══════════════════════════════════════════════════════════════════════
	// BOOT
	// ══════════════════════════════════════════════════════════════════════

	document.addEventListener( 'DOMContentLoaded', function () {
		// Activity chart
		var canvas = document.getElementById( 'wms-activity-chart' );
		if ( canvas ) { bindChartControls(); loadChartData( currentRange ); }

		// Top posts
		var topPosts = document.getElementById( 'wms-top-posts' );
		if ( topPosts ) { bindPeriodTabs(); loadTopPosts( currentPeriod, topPostsLimit ); }

		// Trending
		var trendingEl = document.getElementById( 'wms-trending-posts' );
		if ( trendingEl ) { loadTrending( trendingEl ); }

		// Referrers + search terms
		if ( document.getElementById( 'wms-referrers-list' ) ) {
			loadReferrers( referrerPeriod );
		}

		// Outlinks
		if ( document.getElementById( 'wms-outlinks-list' ) ) {
			loadOutlinks( outlinkPeriod );
		}

		// Bind insights period tabs
		bindInsightsTabs();
	} );

} )();
