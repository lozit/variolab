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

		// Compute Y scale. Stats::daily_breakdown_for_url() already returns
		// rates in PERCENT (0..100, not 0..1), so no further scaling here.
		var maxPct = 0;
		seriesList.forEach( function ( s ) {
			( s.rates || [] ).forEach( function ( r ) {
				if ( typeof r === 'number' && r > maxPct ) {
					maxPct = r;
				}
			} );
		} );
		if ( maxPct <= 0 ) {
			maxPct = 1; // floor (in %), so a flat-zero series still draws a baseline
		}

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

		// Vertical experiment-transition markers (start / end). Drawn before the
		// polylines so the lines render on top — markers stay in the background.
		// Each marker carries a <title> for hover/screen-reader context.
		if ( data.markers && data.markers.length ) {
			data.markers.forEach( function ( m ) {
				var idx = data.days.indexOf( m.date );
				if ( idx < 0 ) {
					return;
				}
				var mx = ML + idx * xStep;
				var label = ( m.kind === 'start' ? 'Started: ' : 'Ended: ' ) + ( m.title || '' );
				parts.push(
					'<line class="vlab-spark-marker" x1="' + mx.toFixed( 1 ) + '" y1="' + MT + '"' +
					' x2="' + mx.toFixed( 1 ) + '" y2="' + ( H - MB ) + '"' +
					' vector-effect="non-scaling-stroke">' +
					'<title>' + escapeXml( label ) + '</title>' +
					'</line>'
				);
			} );
		}

		// One polyline per series. Color comes from the server-computed palette
		// (the `color` field is injected in render_url_sparkline so the chart
		// stays in sync with the variant tag colors in the experiment rows).
		seriesList.forEach( function ( s ) {
			var points = [];
			( s.rates || [] ).forEach( function ( r, i ) {
				if ( typeof r !== 'number' ) {
					return; // skip null/missing days; polyline jumps gracefully
				}
				var x = ML + i * xStep;
				var y = MT + chartH * ( 1 - r / maxPct );
				points.push( x.toFixed( 1 ) + ',' + y.toFixed( 1 ) );
			} );
			if ( points.length === 0 ) {
				return;
			}
			// A = solid baseline, every other variant (B/C/D) = dashed so the
			// baseline is visually anchored on a per-experiment basis even when
			// the chart packs many overlapping lines.
			var dash = ( s.variant === 'A' ) ? '' : ' stroke-dasharray="6 4"';
			parts.push(
				'<polyline class="vlab-spark-line" points="' + points.join( ' ' ) +
				'" stroke="' + ( s.color || '#888' ) + '"' + dash +
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
