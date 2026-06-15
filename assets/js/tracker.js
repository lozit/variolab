(function () {
	'use strict';

	if (typeof window.AbtestTracker === 'undefined') {
		return;
	}

	var cfg = window.AbtestTracker;
	var preview = !!cfg.preview;

	function fireConversion() {
		try {
			fetch(cfg.restUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.nonce
				},
				body: JSON.stringify({ experiment_id: cfg.experimentId }),
				keepalive: true
			});
		} catch (e) {
			// swallow — tracking must never break the page
		}
	}

	// --- Admin preview helpers (only used when cfg.preview is true) ------------
	// In preview mode the visitor is a logged-in admin/editor who is NOT tracked,
	// so clicks must never log a conversion. Instead we surface a visible signal
	// (badge + outline + toast) so the goal can be verified without polluting stats.

	var toastEl = null;
	var toastTimer = null;

	function toast(message) {
		if (!toastEl) {
			toastEl = document.createElement('div');
			toastEl.setAttribute('role', 'status');
			toastEl.style.cssText =
				'position:fixed;z-index:2147483647;left:50%;bottom:60px;transform:translateX(-50%);' +
				'max-width:90vw;padding:12px 18px;border-radius:10px;background:#11150f;color:#fff;' +
				'font:600 14px/1.4 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;' +
				'box-shadow:0 6px 24px rgba(0,0,0,.35);opacity:0;transition:opacity .15s;pointer-events:none;';
			(document.body || document.documentElement).appendChild(toastEl);
		}
		toastEl.textContent = message;
		toastEl.style.opacity = '1';
		if (toastTimer) {
			clearTimeout(toastTimer);
		}
		toastTimer = setTimeout(function () {
			toastEl.style.opacity = '0';
		}, 2400);
	}

	function addBadge() {
		var badge = document.createElement('div');
		badge.textContent = 'A/B preview';
		badge.title =
			'Variolab admin preview — clicks are NOT counted.\nGoal: ' +
			(cfg.goalType || '(none)') + ' = ' + (cfg.goalValue || '(none)');
		badge.style.cssText =
			'position:fixed;z-index:2147483647;right:12px;bottom:12px;padding:6px 11px;border-radius:999px;' +
			'background:#E8643C;color:#fff;font:600 12px/1 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;' +
			'box-shadow:0 2px 10px rgba(0,0,0,.25);cursor:default;';
		(document.body || document.documentElement).appendChild(badge);
	}

	function outlineTargets() {
		if (cfg.goalType !== 'selector' || !cfg.goalValue) {
			return;
		}
		try {
			var nodes = document.querySelectorAll(cfg.goalValue);
			for (var i = 0; i < nodes.length; i++) {
				nodes[i].style.outline = '2px dashed #E8643C';
				nodes[i].style.outlineOffset = '2px';
			}
		} catch (e) {
			// invalid selector — the badge tooltip already shows what was configured
		}
	}

	if (preview) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', function () {
				addBadge();
				outlineTargets();
			});
		} else {
			addBadge();
			outlineTargets();
		}
	}

	// A goal matched. Real visitor -> log it; admin preview -> just show feedback.
	function onGoalMatched(label) {
		if (preview) {
			toast('✓ ' + label + ' matched — conversion would be logged (admin preview, not counted)');
		} else {
			fireConversion();
		}
	}

	function matchesUrlGoal(href) {
		if (!cfg.goalValue) {
			return false;
		}
		try {
			var url = new URL(href, window.location.href);
			var goalUrl = new URL(cfg.goalValue, window.location.href);
			// path equality is the simple, predictable rule for v1
			return url.pathname.replace(/\/$/, '') === goalUrl.pathname.replace(/\/$/, '');
		} catch (e) {
			return false;
		}
	}

	if (cfg.goalType === 'url' && cfg.goalValue) {
		document.addEventListener(
			'click',
			function (event) {
				var anchor = event.target && event.target.closest ? event.target.closest('a[href]') : null;
				if (!anchor) {
					return;
				}
				if (matchesUrlGoal(anchor.getAttribute('href'))) {
					onGoalMatched('URL goal');
				}
			},
			true
		);
	}

	if (cfg.goalType === 'selector' && cfg.goalValue) {
		document.addEventListener(
			'click',
			function (event) {
				var target = event.target;
				if (!target || !target.closest) {
					return;
				}
				try {
					if (target.closest(cfg.goalValue)) {
						onGoalMatched('Selector goal');
					}
				} catch (e) {
					// invalid selector — ignore
				}
			},
			true
		);
	}

	// HubSpot embedded forms render in a cross-origin iframe, so a click goal can't
	// reach the submit button. We detect the submission two ways, covering both
	// current and older embeds, and fire once (server-side nonce + prior-impression
	// + dedup still apply):
	//   1. Forms V4 (current embed) dispatches a DOM CustomEvent on `window`
	//      ("hs-form-event:on-submission:success") — it does NOT broadcast a window
	//      message. This is HubSpot's documented hook.
	//   2. Legacy embed (v2) posts a cross-domain `hsFormCallback` window message.
	if (cfg.goalType === 'hubspot') {
		var hsFired = false;

		var hsConvert = function (formId) {
			// When a specific form GUID is configured, only count that form.
			if (cfg.goalValue && formId && String(formId) !== String(cfg.goalValue)) {
				return;
			}
			if (hsFired) {
				return;
			}
			hsFired = true;
			onGoalMatched('HubSpot form');
		};

		// (1) HubSpot Forms V4 — current embed.
		window.addEventListener('hs-form-event:on-submission:success', function (event) {
			var formId = null;
			try {
				var api = window.HubSpotFormsV4 || window.HubspotFormsV4;
				if (api && typeof api.getFormFromEvent === 'function') {
					var form = api.getFormFromEvent(event);
					if (form && typeof form.getFormId === 'function') {
						formId = form.getFormId();
					}
				}
			} catch (e) {
				// can't read the form id — fall through and count it anyway
			}
			if (preview) {
				try {
					/* eslint-disable-next-line no-console */
					console.log('[Variolab] HubSpot V4 submission success — formId:', formId, event);
				} catch (e) {}
			}
			hsConvert(formId);
		});

		// (2) Legacy embed — cross-domain window message from a HubSpot origin.
		var isHubspotOrigin = function (origin) {
			try {
				var host = new URL(origin).hostname;
				return /(^|\.)hsforms\.(net|com)$/.test(host) ||
					/(^|\.)hubspot\.com$/.test(host) ||
					/(^|\.)hubspotusercontent[^.]*\.(net|com)$/.test(host);
			} catch (e) {
				return false;
			}
		};
		window.addEventListener('message', function (event) {
			if (!isHubspotOrigin(event.origin)) {
				return;
			}
			var d = event.data;
			if (!d || typeof d !== 'object' || d.type !== 'hsFormCallback') {
				return;
			}
			if (preview) {
				try {
					/* eslint-disable-next-line no-console */
					console.log('[Variolab] HubSpot legacy message:', d.eventName, d.id || '', d);
				} catch (e) {}
			}
			if (d.eventName !== 'onFormSubmitted' && d.eventName !== 'onFormSubmit') {
				return;
			}
			hsConvert(d.id);
		});
	}
})();
