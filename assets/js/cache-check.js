/**
 * Cache diagnostic for the A/B Tests admin list.
 *
 * Probes each running test URL (+ a baseline normal page) anonymously from the
 * admin's browser and reads the response headers to tell whether the page is
 * served from a cache. Test pages MUST bypass cache (a cached test page freezes
 * the variant and drops conversions); a normal page SHOULD be cached.
 *
 * Anonymous (credentials:'omit') so login cookies don't bypass the cache and mask
 * it. Sends a custom header (config.headerName) so the plugin skips impression
 * logging — the probe never pollutes stats. Results are cached in localStorage so
 * pills show instantly on arrival; refreshed in the background (smart mode) or on
 * the "Re-check" button (manual mode).
 */
(function () {
	'use strict';

	var cfg = window.AbtestCacheCheck;
	if ( ! cfg || typeof cfg !== 'object' ) {
		return;
	}

	var STORE_KEY = 'abtest_cachecheck_v1';
	var i18n = cfg.i18n || {};

	function readStore() {
		try {
			return JSON.parse( window.localStorage.getItem( STORE_KEY ) ) || {};
		} catch ( e ) {
			return {};
		}
	}

	function writeStore( data ) {
		try {
			window.localStorage.setItem( STORE_KEY, JSON.stringify( data ) );
		} catch ( e ) {
			/* storage full / disabled — non-fatal */
		}
	}

	function setPill( el, state ) {
		if ( ! el ) {
			return;
		}
		var map = {
			pending:  [ 'vlab-cache-pill--pending', i18n.checking || 'checking…', '' ],
			ok:       [ 'vlab-cache-pill--ok', i18n.ok || 'out of cache', i18n.okTitle || '' ],
			cached:   [ 'vlab-cache-pill--bad', i18n.cached || 'CACHED', i18n.cachedTitle || '' ],
			handled:  [ 'vlab-cache-pill--warn', i18n.handled || 'cached (resilient)', i18n.handledTitle || '' ],
			error:    [ 'vlab-cache-pill--error', i18n.error || 'check failed', '' ],
			baseOk:   [ 'vlab-cache-pill--ok', i18n.baselineCached || 'cache active', '' ],
			baseNone: [ 'vlab-cache-pill--warn', i18n.baselineNoCache || 'no page cache detected', '' ]
		};
		var spec = map[ state ] || map.pending;
		el.className = 'vlab-cache-pill ' + spec[0];
		el.textContent = spec[1];
		if ( spec[2] ) {
			el.title = spec[2];
		}
	}

	// Two anonymous fetches (warm + measure). Reads cache signals from the 2nd.
	function probe( url ) {
		var opts = { credentials: 'omit', cache: 'no-store', headers: {} };
		opts.headers[ cfg.headerName || 'X-Abtest-Cache-Check' ] = '1';
		return fetch( url, opts ).then( function () {
			return fetch( url, opts );
		} ).then( function ( r ) {
			var age    = parseInt( r.headers.get( 'age' ) || '0', 10 );
			var cf     = ( r.headers.get( 'cf-cache-status' ) || '' ).toUpperCase();
			var xcache = ( r.headers.get( 'x-cache' ) || '' ).toUpperCase();
			var bypass = !! r.headers.get( 'x-abtest-bypass' );
			var servedFromCache = ( age > 0 ) || cf === 'HIT' || xcache.indexOf( 'HIT' ) !== -1;
			return { servedFromCache: servedFromCache, bypass: bypass, error: false };
		} ).catch( function () {
			return { error: true };
		} );
	}

	// Verdict for a TEST url: it should bypass. Cached => bad (or "handled" if the
	// resilient redirect is on). The bypass marker missing also means it was cached.
	function testVerdict( res ) {
		if ( res.error ) {
			return 'error';
		}
		var cached = res.servedFromCache || ! res.bypass;
		if ( ! cached ) {
			return 'ok';
		}
		return cfg.resilientMode ? 'handled' : 'cached';
	}

	// Verdict for the BASELINE normal page: cached is the expected/good state.
	function baselineVerdict( res ) {
		if ( res.error ) {
			return 'error';
		}
		return res.servedFromCache ? 'baseOk' : 'baseNone';
	}

	function testPills() {
		return Array.prototype.slice.call( document.querySelectorAll( '[data-abtest-cache-url]' ) );
	}

	function baselinePill() {
		return document.querySelector( '[data-abtest-cache-baseline]' );
	}

	// Reveal the explanatory note in the box only when an active cache is detected:
	// either the baseline normal page is cached, or some test URL is cached/handled.
	function updateNote( results, baseline ) {
		var el = document.querySelector( '[data-abtest-cache-note]' );
		if ( ! el ) {
			return;
		}
		var detected = ( baseline === 'baseOk' );
		if ( ! detected ) {
			for ( var key in results ) {
				if ( results[ key ] === 'cached' || results[ key ] === 'handled' ) {
					detected = true;
					break;
				}
			}
		}
		el.hidden = ! detected;
	}

	function renderFromStore() {
		var store = readStore();
		var results = store.results || {};
		testPills().forEach( function ( el ) {
			var key = el.getAttribute( 'data-abtest-cache-url' );
			setPill( el, results[ key ] || 'pending' );
		} );
		setPill( baselinePill(), store.baseline || 'pending' );
		updateNote( results, store.baseline );
		return store;
	}

	// Run probes for all pills (+ baseline) in small concurrent batches.
	function runChecks() {
		var pills = testPills();
		pills.forEach( function ( el ) { setPill( el, 'pending' ); } );
		setPill( baselinePill(), 'pending' );

		var jobs = pills.map( function ( el ) {
			var path = el.getAttribute( 'data-abtest-cache-url' );
			var url;
			try {
				url = new URL( path, window.location.origin ).href;
			} catch ( e ) {
				url = path;
			}
			return function () {
				return probe( url ).then( function ( res ) {
					return { kind: 'test', key: path, el: el, state: testVerdict( res ) };
				} );
			};
		} );

		if ( cfg.classicUrl ) {
			jobs.push( function () {
				return probe( cfg.classicUrl ).then( function ( res ) {
					return { kind: 'baseline', el: baselinePill(), state: baselineVerdict( res ) };
				} );
			} );
		}

		var results = {};
		var baseline = 'pending';
		var BATCH = 4;
		var i = 0;

		function next() {
			if ( i >= jobs.length ) {
				updateNote( results, baseline );
				writeStore( { ts: Date.now(), results: results, baseline: baseline } );
				return Promise.resolve();
			}
			var slice = jobs.slice( i, i + BATCH );
			i += BATCH;
			return Promise.all( slice.map( function ( job ) {
				return job().then( function ( out ) {
					setPill( out.el, out.state );
					if ( out.kind === 'test' ) {
						results[ out.key ] = out.state;
					} else {
						baseline = out.state;
					}
				} );
			} ) ).then( next );
		}

		return next();
	}

	function isStale( store ) {
		if ( ! store || ! store.ts ) {
			return true;
		}
		var maxAge = ( cfg.staleMinutes || 10 ) * 60 * 1000;
		return ( Date.now() - store.ts ) > maxAge;
	}

	function init() {
		var store = renderFromStore();

		if ( cfg.mode !== 'manual' && isStale( store ) ) {
			runChecks();
		}

		var btn = document.querySelector( '[data-abtest-cache-recheck]' );
		if ( btn ) {
			btn.addEventListener( 'click', function () {
				runChecks();
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
})();
