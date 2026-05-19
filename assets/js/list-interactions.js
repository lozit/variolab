/**
 * Variolab — A/B Tests list page interactions.
 *
 * Replaces assets/js/url-charts.js (Chart.js stack, dropped in this
 * commit). Renders inline SVG sparklines from the JSON blob each URL
 * block emits server-side. Status-filter chips are plain <a href>
 * links — no JS needed for those; the page navigates with query args.
 *
 * Data shape (from PHP Stats::daily_breakdown_for_url(), unchanged):
 *
 *   {
 *     days:    ["2026-05-01", "2026-05-02", …],
 *     series:  {
 *       <key>: {
 *         experiment_id: 5,
 *         variant:       "A" | "B" | "C" | "D",
 *         rates:         [0.05, 0.06, null, …],   // null on no-impression days
 *         impressions:   [100, 120, 0, …],
 *         conversions:   [5, 7, 0, …]
 *       },
 *       …
 *     },
 *     titles:  { "<experiment_id>": "Experiment title" }
 *   }
 *
 * Lines are colored per variant (matches the variant tag dots in the
 * rest of the page); the legend wraps the experiment title with a
 * little color swatch. No hover tooltips in v1 — the per-row variant
 * counts already surface the same data.
 */
( function () {
	'use strict';

	// Render after DOMContentLoaded so the SVG shells exist.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	function init() {
		document.querySelectorAll( 'svg.vlab-sparkline' ).forEach( renderSparkline );
	}

	function renderSparkline( svg ) {
		var blob = svg.parentElement && svg.parentElement.querySelector( 'script.vlab-sparkline-data' );
		if ( ! blob ) {
			return;
		}
		var data;
		try {
			data = JSON.parse( blob.textContent );
		} catch ( e ) {
			return;
		}
		if ( ! data || ! data.days || ! data.series ) {
			return;
		}
		var seriesList = Object.keys( data.series ).map( function ( k ) {
			return data.series[ k ];
		} );
		if ( seriesList.length === 0 || data.days.length < 2 ) {
			return;
		}

		// Compute Y scale (rate ratios are in 0..1; render as percent).
		var maxRate = 0;
		seriesList.forEach( function ( s ) {
			( s.rates || [] ).forEach( function ( r ) {
				if ( typeof r === 'number' && r > maxRate ) {
					maxRate = r;
				}
			} );
		} );
		if ( maxRate <= 0 ) {
			maxRate = 0.01; // floor so a flat-zero line still draws
		}
		var maxPct = maxRate * 100;

		// Layout (viewBox is 800 x 220; we stretch horizontally with preserveAspectRatio).
		var W = 800;
		var H = 220;
		var ML = 50;
		var MR = 10;
		var MT = 14;
		var MB = 24;
		var chartW = W - ML - MR;
		var chartH = H - MT - MB;
		var n = data.days.length;
		var xStep = n > 1 ? chartW / ( n - 1 ) : chartW;

		// Build SVG content as a string then inject — single repaint, cheaper than DOM ops.
		var svgNs = 'http://www.w3.org/2000/svg';
		var parts = [];

		// Horizontal grid: 5 lines at 0 / 25 / 50 / 75 / 100 % of maxPct.
		[ 0, 0.25, 0.5, 0.75, 1 ].forEach( function ( frac ) {
			var y = MT + chartH * ( 1 - frac );
			parts.push(
				'<line class="vlab-spark-grid" x1="' + ML + '" y1="' + y + '" x2="' + ( W - MR ) + '" y2="' + y + '"/>'
			);
			parts.push(
				'<text class="vlab-spark-axis" x="' + ( ML - 6 ) + '" y="' + ( y + 3 ) + '" text-anchor="end">' +
				fmtPct( maxPct * frac ) + '</text>'
			);
		} );

		// Axis labels: first + last day.
		parts.push(
			'<text class="vlab-spark-axis" x="' + ML + '" y="' + ( H - 6 ) + '" text-anchor="start">' +
			escapeXml( data.days[ 0 ] ) + '</text>'
		);
		parts.push(
			'<text class="vlab-spark-axis" x="' + ( W - MR ) + '" y="' + ( H - 6 ) + '" text-anchor="end">' +
			escapeXml( data.days[ n - 1 ] ) + '</text>'
		);

		// One polyline per series.
		seriesList.forEach( function ( s ) {
			var points = [];
			( s.rates || [] ).forEach( function ( r, i ) {
				if ( typeof r !== 'number' ) {
					return; // skip null/missing days; polyline jumps gracefully
				}
				var x = ML + i * xStep;
				var pct = r * 100;
				var y = MT + chartH * ( 1 - pct / maxPct );
				points.push( x.toFixed( 1 ) + ',' + y.toFixed( 1 ) );
			} );
			if ( points.length === 0 ) {
				return;
			}
			parts.push(
				'<polyline class="vlab-spark-line" points="' + points.join( ' ' ) +
				'" stroke="' + variantColor( s.variant ) + '"' +
				dashAttr( s.variant ) +
				' vector-effect="non-scaling-stroke"/>'
			);
		} );

		// Replace SVG content (the server only emits <title>; preserve nothing else).
		// Using innerHTML is safe — server-side JSON is wp_json_encode()'d and we
		// pass it through JSON.parse + escapeXml on display text only.
		var inner = svg.querySelector( 'title' );
		var titleMarkup = inner ? inner.outerHTML : '';
		svg.innerHTML = titleMarkup + parts.join( '' );
	}

	function variantColor( variant ) {
		// Mirrors the --vlab-variant-*-tag tokens; hard-coded so we don't pay a
		// getComputedStyle round-trip per series.
		switch ( variant ) {
			case 'A':
				return '#50575e';
			case 'B':
				return '#2271b1';
			case 'C':
				return '#00a32a';
			case 'D':
				return '#dba617';
			default:
				return '#6c7280';
		}
	}

	function dashAttr( variant ) {
		// A solid, B dashed, C dotted, D loose-dashed — same primitive as the
		// old Chart.js renderer (it dashed B only).
		switch ( variant ) {
			case 'B':
				return ' stroke-dasharray="6 4"';
			case 'C':
				return ' stroke-dasharray="2 3"';
			case 'D':
				return ' stroke-dasharray="10 4 2 4"';
			default:
				return '';
		}
	}

	function fmtPct( value ) {
		if ( value >= 10 ) {
			return value.toFixed( 0 ) + '%';
		}
		return value.toFixed( 1 ) + '%';
	}

	function escapeXml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}
}() );
