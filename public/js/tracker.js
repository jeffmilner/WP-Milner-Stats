/**
 * WP Milner Stats — Frontend Tracker
 *
 * Uses WordPress admin-ajax.php for tracking — the most reliable mechanism
 * for unauthenticated POST requests in WordPress. Unlike the REST API,
 * admin-ajax.php works on all WordPress installs regardless of:
 *   - REST API disabled or restricted by security plugins
 *   - Caching plugins intercepting or blocking REST routes
 *   - Server-level rules that block /wp-json/ paths
 *
 * Both requests use fetch() with keepalive:true so they survive page navigation.
 *
 * Injected globals via wp_localize_script:
 *   wmsData.ajaxUrl  — WordPress admin-ajax.php URL
 *   wmsData.nonce    — action-specific nonce (wms_track_nonce)
 *   wmsData.postId   — current post ID
 *   wmsData.siteHost — site hostname for external-link detection
 */

( function () {
	'use strict';

	if ( typeof wmsData === 'undefined' || ! wmsData.postId ) {
		return;
	}

	var postId   = parseInt( wmsData.postId, 10 );
	var ajaxUrl  = wmsData.ajaxUrl  || '';
	var siteHost = ( wmsData.siteHost || location.hostname ).toLowerCase();

	if ( ! ajaxUrl ) {
		return;
	}

	// ── Shared POST helper ─────────────────────────────────────────────────
	// Uses application/x-www-form-urlencoded — the native format for
	// admin-ajax.php, parsed directly from $_POST on the server.

	function postAjax( action, data ) {
		var params = 'action=' + encodeURIComponent( action );

		for ( var key in data ) {
			if ( Object.prototype.hasOwnProperty.call( data, key ) ) {
				params += '&' + encodeURIComponent( key ) + '=' + encodeURIComponent( data[ key ] );
			}
		}

		try {
			fetch( ajaxUrl, {
				method:    'POST',
				headers:   { 'Content-Type': 'application/x-www-form-urlencoded' },
				body:      params,
				keepalive: true,
			} ).catch( function () {} );
		} catch ( e ) {}
	}

	// ── 1. Page view ───────────────────────────────────────────────────────

	function trackView() {
		postAjax( 'wms_track', {
			post_id:  postId,
			referrer: document.referrer || '',
		} );
	}

	// ── 2. Outbound link clicks ────────────────────────────────────────────

	function isExternalLink( href ) {
		if ( ! href || href.charAt( 0 ) === '#' ) {
			return false;
		}
		try {
			var url = new URL( href, location.href );
			return (
				url.hostname.toLowerCase() !== siteHost &&
				( url.protocol === 'http:' || url.protocol === 'https:' )
			);
		} catch ( e ) {
			return false;
		}
	}

	function trackOutlink( href, text ) {
		postAjax( 'wms_track_outlink', {
			post_id:   postId,
			link_url:  href,
			link_text: ( text || '' ).trim().substring( 0, 100 ),
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		var target = e.target;
		while ( target && target.tagName !== 'A' ) {
			target = target.parentElement;
		}
		if ( ! target ) return;
		var href = target.getAttribute( 'href' );
		if ( isExternalLink( href ) ) {
			trackOutlink( href, target.textContent );
		}
	}, false );

	// ── Boot ───────────────────────────────────────────────────────────────

	if ( 'requestIdleCallback' in window ) {
		window.requestIdleCallback( trackView, { timeout: 4000 } );
	} else {
		window.addEventListener( 'load', function () {
			setTimeout( trackView, 500 );
		} );
	}

} )();
