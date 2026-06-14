=== Variolab – A/B Testing ===
Contributors: lozit
Tags: ab testing, split testing, landing page, conversion, html import
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.20.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A/B test WordPress pages or AI-generated HTML landings. Internal tracking, 50/50 cookie split, GDPR-friendly, no third-party dependency.

== Description ==

Run A/B tests on WordPress pages or on HTML landings you've imported — including the ones your AI tool just generated. Drop a .html (or a .zip with CSS/JS/images) into wp-admin → A/B Tests → Import HTML; Variolab renders the page byte-perfect with zero WordPress wrapper, then runs a real 50/50 cookie-split test against any existing page on your site. Claude, v0, Lovable, Cursor, bolt.new exports all work out of the box.

The classic two-page path works too: point the plugin at two existing pages — one as the control (Variant A), one as the variant (Variant B). Visitors are split 50/50 via a persistent cookie; once assigned, they always see the same variant.

Tracking is fully internal: impressions and conversions land in a custom database table, and the wp-admin dashboard shows conversion rates, lift, and a basic statistical significance indicator (two-proportion z-test).

Security-audited internally before every release (situated checklist + OWASP grid). See SECURITY.md on GitHub for the disclosure policy and the latest audit report (`docs/security/latest.md`).

= Features =
* HTML / ZIP import for landing pages built outside WordPress — drop a .html or a .zip with assets; pages render byte-perfect with zero theme wrapper. Ideal for AI-generated landings (Claude, v0, Lovable, Cursor, bolt.new), hand-coded HTML, or mockup-tool extracts.
* Watch directory — keep editing the HTML in your IDE / Cursor / SFTP / cloud sync; WP-Cron picks up changes every 5 minutes (hash-based, additive only).
* Page-level A/B tests (entire page as variant — no Gutenberg surgery needed)
* Persistent cookie split (httponly, samesite=Lax)
* Internal tracking — no third-party dependency, no data leaving your site
* Conversion goals: URL visited or CSS selector clicked
* Auto bypass for logged-in editors and bots
* Two-proportion z-test for statistical significance
* `abtest_event_logged` action hook ready for v2 GA4/webhook integrations

== Caching ==

A/B testing breaks under page caching: the first variant served gets cached for everyone, all subsequent visitors get that same response, the 50/50 split dies. The plugin handles most cases automatically.

= What the plugin does automatically =

1. **Sends `Cache-Control: no-store` headers** on every page response under A/B test. Respected by Cloudflare, Varnish, Kinsta edge cache, nginx page cache, and most server-level caches.
2. **Hooks WP Rocket's `rocket_cache_reject_uri` filter** when WP Rocket is detected — your test URLs are auto-added to the never-cache list.
3. **Hooks LiteSpeed Cache's `litespeed_force_nocache_url` filter** when LiteSpeed is detected — same idea.
4. **Surfaces an admin notice** when a cache plugin or known host (like Kinsta) is detected, with what to verify.

= Hosting on Kinsta =

Kinsta uses a two-layer cache (nginx server-cache + Cloudflare Enterprise edge cache). The plugin's no-store headers bypass both — but for 100% safety, also add your test URLs to **MyKinsta → Tools → Cache → Cache Bypass** as URL Patterns (e.g. `^/promo/$`). After publishing a new test, **purge the Kinsta cache** to flush any version cached before the experiment started.

Verify it works by inspecting headers on your test URL:

`curl -I https://yoursite.com/promo/`

Look for `X-Kinsta-Cache: BYPASS` (or `MISS`). If you see `HIT`, you're getting the cached version and the split is broken — purge the cache.

= Hosting on other CDNs / hosts =

* **Cloudflare APO**: Cache-Control headers from origin override the cache. Should work out of the box. Verify with `curl -I` looking for `cf-cache-status: BYPASS` or `DYNAMIC`.
* **WP Engine**: Add the test URLs to the "Cache Exclusions" list in your User Portal.
* **Pagely / Pantheon / Pressable**: Cache-Control headers respected. Add manual URL exclusion in the host's panel for safety.

= Plugins not auto-supported =

For W3 Total Cache, WP Super Cache, WP Fastest Cache, and Cache Enabler — the plugin shows a notice but does not automatically exclude URLs (no clean public API). Manually add your test URLs to that plugin's cache exclusion list.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate **Variolab – A/B Testing** through the Plugins menu.
3. Go to **A/B Tests** in the admin sidebar to create your first experiment.

== REST API ==

Pull stats programmatically from external tools (n8n, Make, Pipedream, dashboards).

* Endpoint: `GET /wp-json/abtest/v1/stats`
* Auth: WP Application Passwords (Basic Auth). The user must have `manage_options`.
* Generate one in your WP profile → Application Passwords.

Optional query params:

* `url=/promo/` — filter to a single test URL.
* `experiment_id=38` — fetch a single experiment by ID.
* `status=running|paused|ended|draft` — filter by status.
* `from=YYYY-MM-DD&to=YYYY-MM-DD` — restrict event date range for the stats computation.
* `breakdown=daily` — include per-day time series (for charting).

Example:

`curl -u 'admin:xxxx xxxx xxxx xxxx xxxx xxxx' 'https://yoursite.com/wp-json/abtest/v1/stats?status=running'`

The response includes for each experiment: id, title, test_url, status, dates, control/variant IDs, goal, and a stats block with A/B impressions/conversions/rate, lift, p-value, significance, and 95% confidence interval bounds for both lift and absolute difference.

== Privacy ==

The plugin stores no raw IP, no User-Agent, no email, no name, and no cross-site tracking identifier. The events table contains: experiment_id, variant, test_url, event_type, created_at, and a `visitor_hash` = first 16 hex chars (64 bits) of `sha256(IP + UA + wp_salt('auth'))` — non-reversible, single-site, salt-rotated, dedup-safe. Cookies are httponly, samesite=Lax, secure on HTTPS, value = a single letter (a/b/c/d), 30-day TTL.

A native WordPress privacy guide snippet is registered automatically — find it under Settings → Privacy → Policy Guide → Variolab – A/B Testing to paste into your privacy policy.

For consent-banner sites: enable "Require consent" in the plugin settings and wire your banner to the `abtest_visitor_has_consent` filter (return true to track, false/null to block). Snippets for Complianz, CookieYes, and Cookiebot are in the README on GitHub.

Right to erasure: because no reversible identifier is stored, individual deletion isn't possible. Use `TRUNCATE wp_abtest_events` to erase all A/B testing data.

== Frequently Asked Questions ==

= How is a visitor assigned to a variant? =
On their first visit to the control page, a cookie `abtest_{experiment_id}` is set with value `a` or `b`. Subsequent visits read that cookie — the visitor always sees the same variant.

= Will admins see the test? =
No. Logged-in users with `edit_posts` capability are bypassed and always see the control. The admin bar shows a marker indicating which experiment is running on the page.

= How do I upgrade without losing my experiments and stats? =
Always use the **Update** action — Plugins → Installed Plugins → Update, or `wp plugin update variolab-ab-testing` on the command line. Do **not** delete the plugin to reinstall a newer copy: deleting runs the uninstaller. As of v0.18.0 the uninstaller keeps your data by default (so an accidental delete no longer wipes your experiments, stats, page-import records, or settings), but the Update action is still the correct, cleanest way to upgrade. If you ever do want a full clean removal that erases everything, tick **Delete all data on uninstall** in Settings → Data & uninstall *before* deleting the plugin.

= Does it work with WooCommerce / Gutenberg blocks? =
v1 only swaps the entire page (the variant must be a separate post). Block-level and product-level testing are on the roadmap.

= My conversions aren't recorded / I use a CDN or a cache (Cloudflare, Cloudways, SiteGround, Kinsta, WP Rocket…) =
A/B test pages must never be served from a cache. A cached page freezes one variant for every visitor (breaking the 50/50 split) and skips impression logging — so conversions get dropped. The plugin already sends `no-store` headers and auto-excludes test URLs from WP Rocket and LiteSpeed, but server-level and edge caches it can't control (Cloudways/Varnish, generic nginx caches, Cloudflare APO) may still cache the page. Two options:

1. **Exclude your test URLs from the cache (recommended).** Add them to your host/CDN cache-exclusion list. Tip: give test URLs a common prefix (e.g. `/lp/…`) so one rule covers all of them. Step-by-step guidance for your host is shown in **Settings → Caching & CDN**.
2. **Turn on Cache-resilient mode** (Settings → Caching & CDN). It forces a fresh render of test pages via a one-time redirect to a unique URL no cache can have stored — no cache config needed. Trade-off: a brief redirect on first paint and a query parameter on test URLs (consider `noindex` on those pages if SEO matters).

== Screenshots ==

1. A/B Tests admin list — KPI strip (active tests, impressions, conversions, overall rate, winners shipped), status filter chips (All / Draft / Running / Paused / Ended), date range with 7d / 30d / All-time presets, experiments grouped by URL with per-variant stats + lift + confidence interval + significance badges, archived ended tests collapsed into a details panel, daily conversion-rate sparkline per URL with start/end markers
2. Import HTML page — drop a .html or .zip (with CSS/JS/images) from any source: Claude / v0 / Lovable / Cursor / bolt.new exports, hand-coded landings, mockup-tool extracts. Variolab extracts to wp-content/uploads/abtest-templates/{slug}/, rewrites relative href / src / srcset / url() to the extracted assets, and renders the page with zero WordPress wrapper. Watch directory panel lets you keep editing the HTML in your IDE / Cursor / SFTP / cloud sync — WP-Cron picks up changes every 5 minutes.
3. Settings — privacy & consent gate (GDPR), Google Analytics 4 Measurement Protocol integration, generic webhooks (Zapier / Make / Mixpanel / Segment / Slack / n8n), and REST API documentation with a copy-paste curl example

== External services ==

This plugin connects to one external service, **only when the site administrator opts in** through the plugin's Settings → Google Analytics 4 panel by entering a Measurement ID and API Secret. With the GA4 integration disabled (default), no data leaves your site.

= Google Analytics 4 (Measurement Protocol) =

What it is and what it's used for: when the GA4 integration is enabled, the plugin forwards A/B-test impression and conversion events to Google Analytics 4 via the Measurement Protocol, so the test results can be analyzed alongside your existing GA4 reports.

What data is sent and when: on each impression and each conversion logged by the plugin, a single fire-and-forget HTTPS request is sent to `https://www.google-analytics.com/mp/collect` with a JSON payload containing:

* `client_id` — the plugin's internal visitor hash (truncated salted SHA-256 of IP + User-Agent; never the raw IP or UA)
* `events[].name` — `abtest_impression` or `abtest_conversion`
* `events[].params.experiment_id` — the WordPress post ID of the experiment
* `events[].params.variant` — the variant served (`a`, `b`, `c`, or `d`)
* `events[].params.test_url` — the URL path under test (e.g. `/promo/`)

No raw IP address, User-Agent string, email, name, WordPress user ID, or page content is sent.

Service provided by Google. Please review their terms and policies before enabling the integration:

* Google Analytics terms of service: https://marketingplatform.google.com/about/analytics/terms/us/
* Google privacy policy: https://policies.google.com/privacy

== Changelog ==

= 0.20.0 =
* **New conversion goal: "HubSpot form submitted".** Embedded HubSpot forms render inside a cross-origin iframe, so a CSS-selector "click" goal can never reach the submit button (the browser blocks it). This new goal type listens for HubSpot's own form-submission event (the `hsFormCallback` message HubSpot posts to the page — the same hook Google Analytics / GTM use), verifies it really comes from a HubSpot origin, and records the conversion. Leave the goal value empty to count any HubSpot form submission on the test page, or paste a HubSpot form GUID to count only that specific form. When you preview a running test as a logged-in admin, the browser console logs each HubSpot event so you can confirm the wiring with one test submission.

= 0.19.0 =
* **Clearer cache diagnostics on the A/B Tests list.** The "Cache check" box now shows what was detected as neutral chips — **Kinsta**, **Cloudflare**, your cache plugin, and a **cache detected** indicator (blue/grey, because a site-level cache is normal and not a problem in itself; only your *test* URLs must bypass it). When a cache is present, a single bold, highlighted line spells out what matters — *make sure every test URL above shows "out of cache"* — followed by short, host-specific guidance (e.g. the Kinsta Cache Bypass steps, and the one-click cache-resilient mode). The separate caching admin notice that used to sit at the top of the screen is gone: everything now lives in one place, right next to the per-URL pills it refers to. Per-test pills still turn red **CACHED** when a test page is wrongly served from cache.

= 0.18.0 =
* **Your data now survives a plugin delete.** Deleting the plugin used to run the uninstaller, which dropped the events table and removed every experiment, page-import record, and setting — so anyone who "deleted + reinstalled" to upgrade lost all their A/B history. The uninstaller now **keeps your data by default**: an accidental delete (or a delete-to-reinstall) no longer wipes anything. Always use the normal **Update** action to upgrade (Plugins → Update, or `wp plugin update`); only delete when you really want the plugin gone. A new **Settings → Data & uninstall** section explains this and offers an opt-in "Delete all data on uninstall" checkbox (off by default) for when you want a clean removal that erases everything.

= 0.17.1 =
* **Cache check polish.** The "how to fix it" guidance now appears in the Cache check box only when an active cache is actually detected (instead of always living in Settings). And a test page handled by cache-resilient mode now reads **CACHE RESILIENT MODE** (amber) instead of the ambiguous "CACHED (RESILIENT)".

= 0.17.0 =
* **Cache check on the A/B Tests list.** Each running test now shows a status pill — **out of cache ✓** or **CACHED ⚠️** — so you can confirm at a glance that your test pages bypass the cache (a cached test page freezes the variant and drops conversions). A baseline pill checks a normal page too, which *should* be cached, confirming your cache works in general. The check runs from your browser, anonymously, and never logs an impression (it sends a header that tells the plugin to skip counting). Choose **Smart** (auto, refreshed in the background) or **Manual** (only on the “Re-check” button) in Settings → Caching & CDN — handy if you run many tests.

= 0.16.0 =
* **Caching & CDN: new guidance + an opt-in "Cache-resilient mode".** A cached A/B test page breaks the test (one variant frozen for everyone, conversions silently dropped because WordPress never runs to log the impression). New in Settings → Caching & CDN: clear, host-specific instructions for excluding test URLs from your cache (Cloudways/Varnish, Cloudflare, Kinsta, nginx…), and a one-click **Cache-resilient mode** that forces a fresh render via a one-time `?_abtcb=…` redirect when you can't edit your cache rules. The admin cache notice now also detects Cloudflare and points to both options. (Default off; recommended fix remains a proper cache exclusion.)

= 0.15.11 =
* **Fix conversions with WP Rocket "Delay JavaScript Execution" (the real "click twice" cause).** When that optimisation is on (also in Perfmatters and similar), it defers every script until the visitor's first interaction — so the very first click only woke the tracker up and wasn't recorded, and you had to click again. The conversion tracker is now automatically excluded from delay-JS, so it runs on page load and the first click counts. If you use another delay-JS tool, exclude `variolab-ab-testing/assets/js/tracker.js` and `AbtestTracker` from it manually.

= 0.15.10 =
* **Conversions no longer depend on the variant cookie ("had to click twice" fix).** A conversion was only recorded if the visitor's variant cookie was present, which could be missing on the first click (a CDN stripping the Set-Cookie header, or first-paint timing) — so the conversion was lost and you had to click again. The variant is now determined from the visitor's server-side impression instead, so a real click counts the first time even if the cookie didn't arrive. As a bonus this is also more tamper-resistant: a forged cookie can no longer attribute a conversion to a different variant.

= 0.15.9 =
* **Conversion goals now work on imported HTML landing pages.** Click and URL conversion goals previously never fired on pages imported via Import HTML (Blank Canvas), because the conversion tracker was only loaded on regular theme pages. The tracker is now injected into imported landings too, so "click a button matching a CSS selector" and "visit/click a URL" goals are recorded for real visitors. If your test runs on an imported page, this is the fix that makes its conversions count.
* **New admin preview mode for goals.** When you view a running test page while logged in (as an admin/editor — your visits are never counted), the page now shows a small "A/B preview" badge, outlines every element your CSS-selector goal matches, and pops a toast when you click one — so you can confirm a goal is wired correctly without polluting your stats.

= 0.15.8 =
* **Visitor-dedup salt decoupled from WordPress auth keys.** The de-duplication hash used `wp_salt('auth')`, so rotating your site's authentication keys silently reset visitor de-duplication (re-counting returning visitors). It now uses a dedicated salt seeded once from the current auth salt — existing counts are preserved (no reset on update), and future key rotations no longer disturb your stats. Last clean-up item from the 2026-06-12 internal security audit.

= 0.15.7 =
* **Make the HTML-import trust boundary explicit (defence-in-depth).** Imported pages are rendered with no theme wrapper, so any markup they contain runs as-is. The plugin now records, at import time, whether the importer was allowed to store raw HTML/JS (the `unfiltered_html` capability) and, when rendering, re-filters anything that wasn't through WordPress's standard sanitizer. Single-site admins and existing imported pages are unaffected; the feature also remains available to multisite site administrators (their content is simply sanitized as WordPress already does on save). Closes the last actionable item from the 2026-06-12 internal security audit.

= 0.15.6 =
* **Security hardening (defence-in-depth, from the 2026-06-12 audit).** Four low-severity items addressed: HTML import "replace existing" now refuses any target that is not a page (can no longer be coerced into overwriting an arbitrary post); the watch-directory scanner verifies the resolved index file stays inside its folder (rejects symlink escapes); the webhook Secret help text now recommends a constant-time signature comparison (`hash_equals()`); and a misleading `.gitignore` entry was cleaned up. No functional change for normal use.

= 0.15.5 =
* **Fix "translation triggered too early" PHP notice (WP 6.7+).** The custom 5-minute cron interval registered a translated display label from the `cron_schedules` filter, which fires before the `init` action — tripping WordPress 6.7+'s `_load_textdomain_just_in_time` notice on every cron run (visible with WP_DEBUG and flagged by Plugin Check). The label, only ever shown in cron-management tools, is no longer translated, so nothing loads the text domain early. No functional change.

= 0.15.4 =
* **Security: webhooks can no longer be pointed at internal addresses (SSRF hardening).** Outgoing event webhooks now refuse URLs whose host is a loopback, link-local, private, or reserved IP — both when you save them (literal IPs like `127.0.0.1`, `169.254.169.254`, `10.0.0.0/8`, `192.168.x`) and at request time, where WordPress resolves the hostname and rejects targets that point inside your network (`reject_unsafe_urls`, which also blocks redirect-based bypasses). Legitimate public endpoints (Zapier, Make, Slack, your own API) are unaffected. Hardening from the 2026-06-12 internal security audit.

= 0.15.3 =
* **Security: conversions now require a prior impression.** The public conversion endpoint used to log a conversion based on a cookie value, which a client fully controls — so a request with a hand-crafted cookie for a guessed experiment ID could record conversions that never happened and skew a test's result. Conversions are now only recorded when the visitor already has a server-side impression for that exact experiment and variant (impressions are written by the server when the page is actually served, and cannot be forged). Every real conversion therefore moves in lock-step with an impression, keeping the conversion rate honest. No change to legitimate tracking. Hardening from the 2026-06-12 internal security audit; combines with the existing per-IP rate limit and per-visitor dedup.

= 0.15.2 =
* **Admin notices reposition.** Third-party admin notices (security plugins, cache plugins, the plugin's own CacheNotice, etc.) used to land between the Variolab brand header and the page content, pushing the table down. They now appear above the brand header — same slot WordPress uses on every other admin screen. Implemented via the standard `<hr class="wp-header-end">` marker injected at the top of `Admin::render_brand_header()`, so all four plugin pages (List / Edit / Settings / Import) get the fix.

= 0.15.1 =
* **Fix fatal error on imported HTML landings.** `templates/blank-canvas.php` called `UrlScripts::render_for_position()`, which was never defined on the class — every visit to an HTML-imported page running an A/B test crashed with `Call to undefined method`. Added the missing method as the return-string counterpart of the existing `print_for_position()`; themed pages are unaffected.

= 0.15.0 =
* **List page redesign.** New branded dashboard at A/B Tests: header with Variolab brandline (icon + wordmark + version pill); 5-card KPI strip (Active tests / Impressions / Conversions / Overall rate / Winners shipped) driven by a new `Stats::overview_kpis()` aggregator; toolbar with 5 status chips (All / Draft / Running / Paused / Ended) + date range with 7d/30d/All-time presets; URL blocks rendered as cards with per-experiment 3-column CSS grid; ended experiments collapse into a native `<details>` per URL block. Cream canvas (#EFECE4) replaces the wp-admin gray across every plugin admin screen (scoped via `body.toplevel_page_abtest-experiments`).
* **Inline SVG sparklines, Chart.js dropped.** Replaces the ~205 KB vendored Chart.js + the `assets/js/url-charts.js` wrapper with a ~200-LOC vanilla `list-interactions.js` that renders an SVG polyline per (experiment, variant) using a 12-hex rotating palette so the chart line color matches the variant tag color in the row above. Variant A renders solid, B/C/D dashed. Dashed light-gray vertical markers show each experiment's start + end dates with hover tooltip.
* **Shared brand shell across all admin pages.** New `Admin::render_brand_header( $title )` helper applied to List / Edit / Settings / Import; the legacy form-table styles on Edit / Settings / Import are preserved by the dual `wrap vlab-page abtest-wrap` class so the form submission paths are unchanged.
* **Inter Tight + JetBrains Mono variable fonts bundled** as WOFF2 with a Latin Unicode subset (~200 KB total via `pyftsubset`). SIL OFL 1.1 license files shipped alongside.
* New `?status_filter=all|draft|running|paused|ended` query arg replaces the old `?show=`. The legacy parameter is translated silently for one release so bookmarks keep working.
* New CSS architecture: `admin-tokens.css` (design tokens + `@font-face`, everywhere) → `admin-shell.css` (cream bg + brandline + buttons, everywhere) → `admin-list.css` (list-specific, list page only). The legacy `admin.css` is kept verbatim for the other pages.
* Internal naming preserved: `Abtest\` PHP namespace, `abtest_*` hook / cookie / option / table prefixes, REST namespace `abtest/v1`, custom table `wp_abtest_events`. No DB / cookie / option breaking change.

= 0.14.0 =
* **wp.org Plugin Review round 2 — all findings addressed.**
* **Slug** `variolab` → `variolab-ab-testing` (matches the slug wp.org reserved on resubmission). Text domain mass-updated across every `__()` / `_e()` call, main file `variolab.php` → `variolab-ab-testing.php`, `composer.json` / `package.json` package names, `phpcs.xml.dist` text-domain element + file ref, `tests/Integration/bootstrap.php` require path, `.github/workflows/{ci,release}.yml` build folder + zip filename + header-version grep.
* **HtmlImport hardening**: zip extraction no longer writes `.html` / `.htm` / `.js` files to the uploads directory (wp.org policy: no code-bearing files in uploads even though the area itself is allowed). The main `index.html` is read directly from the zip into memory and stored in `post_content` instead — never touches disk. CSS, images, fonts continue to extract normally. Local JS the template referenced via `<script src="./bundle.js">` will 404 at render time; admins can re-inject inline JS via the per-URL tracking-scripts feature.
* **Per-URL tracking scripts refactor**: `UrlScripts::print_for_position()` now wraps each entry via `wp_print_inline_script_tag()` — the WP-blessed inline-script helper — instead of raw `echo`. The new `UrlScripts::parse_script_input()` silently strips `<script ...>` / `</script>` wrappers the admin pastes, extracting `src` / `async` / `defer` / `type` / `id` attributes for fidelity. 11 unit tests cover plain JS / single wrapper / multi-script degraded mode / orphan tags / boolean attributes.
* **CPT slug** `ab_experiment` → `abtest_experiment` (wp.org requires ≥4-char prefixes; `ab_` was too short). **Menu slug** `ab-testing` → `abtest-experiments`. New idempotent migration `Plugin::pre_install_rename_post_type()` runs at upgrade (DB schema v1.3.0 → v1.4.0) renaming every existing `post_type` row in one statement before the new CPT registers on `init`. Uninstall handler accepts both new and legacy slugs so old installs still get cleaned up.
* Internal `Abtest\` namespace, `abtest_*` hook / cookie / option / table prefixes (already 6-char), and REST namespace `abtest/v1` stay untouched — no breaking change for existing data.

= 0.13.0 =
* **Renamed plugin** to **Variolab – A/B Testing** (slug `variolab`). The wp.org Plugin Review Team flagged "Uplift" on two cumulative grounds: (1) it is the standard industry term for the A/B-testing lift metric (non-distinctive — every VWO/Statsig/Insider/etc. doc uses "uplift" to mean conversion-rate lift), and (2) UPLIFT® is a live USPTO trademark (Reg. 4973441, UPLIFT INC., San Francisco) in the same "Advertising, Business & Retail Services" class as the plugin. Variolab is an invented term (vario + lab) with no wp.org / USPTO / SaaS hit at name-pick time.
* Coordinated multi-file change: plugin header display name + text domain (`uplift-ab-testing` → `variolab` everywhere — every `__()`/`_e()` call across `includes/`), main plugin file `uplift-ab-testing.php` → `variolab.php`, `composer.json`/`package.json` package names, `phpcs.xml.dist` text-domain element + file ref + ruleset name, `tests/Integration/bootstrap.php` require path, `.github/workflows/{ci,release}.yml` build folder + zip filename + header-version grep.
* **Internal naming kept untouched** (no DB / cookie / option / hook breaking change for existing installs): `Abtest\` PHP namespace, `abtest_*` hook/cookie prefixes, REST namespace `abtest/v1`, custom table `wp_abtest_events`, option keys (`abtest_settings`, `abtest_db_version`).

= 0.12.0 =
* **Renamed plugin** to **Uplift – A/B Testing** (slug `uplift-ab-testing`). The WordPress trademark guideline forbids the word "WordPress" in both the plugin display name and the slug — this rename closes the last remaining wp.org submission blocker.
* Coordinated multi-file change: plugin header, text domain (`uplift-ab-testing` everywhere — every `__()`/`_e()` call across `includes/`), main plugin file `ab-testing-wordpress.php` → `uplift-ab-testing.php`, `composer.json`/`package.json` package names, `phpcs.xml.dist` text-domain element, `tests/Integration/bootstrap.php` require path, `release.yml` + `ci.yml` build paths and zip filename.
* **Internal naming kept untouched** (no breaking change for existing installs): the `Abtest\` PHP namespace, `abtest_*` hook prefixes, `abtest_*` cookies, REST namespace `abtest/v1`, custom table `wp_abtest_events`, and option keys (`abtest_settings`, `abtest_db_version`) all stay as-is. They're internal — never visible to wp.org reviewers and never on a user URL.

= 0.11.3 =
* WordPress.org compliance — final Plugin Check cleanup:
  * `wp-tests-config.php`, `phpunit.xml*`, and `phpcs.xml*` are now excluded from the built plugin folder by both `release.yml` and `ci.yml`. They were leaking into the artifact and tripping `missing_direct_file_access_protection` (the test bootstrap doesn't and shouldn't have an `ABSPATH` guard).
  * Replaced `languages/.gitkeep` with `languages/index.php` (the canonical "Silence is golden" pattern). `.gitkeep` was rejected as a hidden file by Plugin Check.
  * Renamed two unprefixed locals in `templates/blank-canvas.php` (`$insert_at` → `$abtest_insert_at`, `$body_close` → `$abtest_body_close`). Template files run in global scope, so unprefixed top-level vars trip `PrefixAllGlobals.NonPrefixedVariableFound`.
* Plugin Check on the built artifact is now green: 0 errors, 0 warnings.

= 0.11.2 =
* WordPress.org compliance hardening (post-Plugin-Check first run):
  * Plugin Check CI now runs against the **built** plugin folder (mirroring `release.yml`'s rsync) instead of the raw repo, so dev-only files (`tests/`, `.claude/`, `.github/`, `CLAUDE.md`, `composer.json`, etc.) no longer pollute the report. Cuts ~80% of the false-positive noise.
  * `ignore-codes` list added with one-line rationale per entry: custom-table direct queries, file-system ops on plugin-controlled paths, `mt_rand`/`mt_srand` for variant picking, `meta_query` slow-query warnings, the `init` core-hook false positive.
* Removed `load_plugin_textdomain()` call: WordPress.org auto-loads translations for hosted plugins since WP 4.6 — manual loading is now discouraged. Text-domain header stays declared so JIT loading still works.
* Added empty `languages/` folder (with a `.gitkeep` documenting why) to satisfy the `Domain Path: /languages` plugin header — Plugin Check (and wp.org reviewers) flag the header when the folder doesn't exist.

= 0.11.1 =
* WordPress.org compliance: Chart.js (used to render the per-URL conversion-rate timeline on the admin list view) is no longer loaded from the jsdelivr CDN — it's now bundled under `assets/js/vendor/chart.umd.min.js`. This satisfies the wp.org plugin guideline #5 "Trying to remotely load code". MIT license attribution + update instructions are documented in `assets/js/vendor/README.md`.
* New CI step: WordPress's official `plugin-check-action` runs on every push to `main` and PR. Same automated checks as the wp.org reviewers (plugin headers, i18n, late escaping, deprecated APIs, internationalization). Any future regression that would be flagged at submission time is caught at push time instead.

= 0.11.0 =
* New: **per-URL no-index toggle**. A new "SEO" row on the experiment edit form lets you mark any test URL as no-index. When checked, every visit to that URL emits both a `<meta name="robots" content="noindex,nofollow">` tag and a matching `X-Robots-Tag` HTTP header — regardless of which experiment is currently running. Recommended for landing pages dedicated to paid traffic, or any URL where you don't want both A/B variants to compete in search results.
* The setting is URL-scoped (stored in a new `abtest_url_settings` option keyed by URL path) so every experiment that lands on the same URL inherits it. Future URL-scoped flags can plug into the same store.
* New `Abtest\UrlSettings` helper class with 7 unit tests covering normalization, default pruning, and per-URL independence.

= 0.10.1 =
* i18n cleanup: every committed file is now in English. The plugin's user-facing strings (HelpTabs, StatsExplain) ship as English source so the standard WordPress translation pipeline (`.pot` / `.po`) can produce localized versions later. Audit reports, todo, slash commands, internal rules, lessons-learned all translated. CLAUDE.md adds an explicit "English only in the repo" rule to prevent regressions.

= 0.10.0 =
* New: **WordPress contextual help** on the A/B Tests screens. Click "Help" at the top-right of any A/B Tests page to get 4 didactic tabs: Quick start, Stats explained (p-value / α / "no winner" reasons), Multi-variant (Bonferroni correction), Privacy & GDPR. Designed for non-statisticians installing the plugin for the first time.
* New: **contextual tooltip on the "No winner" badge** in the experiments list. Hover (or screen-reader-focus) the badge to see WHY this experiment doesn't have a winner — the explanation auto-detects between: "too early" (running < 14 days), "sample too small" (< 200 imp/variant), "borderline" (p just above α), "genuine null result" (rates within ±15%), or generic "keep the test running". Powered by a new pure-function helper `Abtest\Admin\StatsExplain` with 8 unit tests covering each branch.

= 0.9.3 =
* PHPCS WordPress Coding Standards : repaid the 1083-finding cosmetic dette. The codebase is now fully WPCS-clean and the GitHub Actions `lint` job is BLOCKING (was `continue-on-error`). Any new code that violates the ruleset fails the build.
* phpcs.xml.dist relaxed for modern PHP 8.1+ idioms : short array syntax `[]`, short ternary `?:`, alignment, and trivial-method docblocks no longer enforced. All Security / SQL / i18n / capability / nonce sniffs remain strict.
* All `phpcs:ignore` annotations on the codebase carry a one-line justification (why the rule is suppressed at this site).
* Bonus i18n fixes : added missing `translators:` comments on all `_n()` / `__()` calls with placeholders so the `.pot` file can guide translators.
* Bonus naming fix : renamed `Autoload::load($class)` to `Autoload::load($class_name)` since `class` is a PHP reserved keyword as a parameter name.

= 0.9.2 =
* Security hardening sweep — all open findings from the v0.9.1 audit closed.
* HTML upload now performs a real MIME check (`wp_check_filetype_and_ext()`) on top of the extension allowlist — for `.zip` this catches a PHP file disguised as a zip via magic-byte mismatch.
* Webhook URLs are now refused if they don't start with `http://` or `https://` (anti-SSRF basic — blocks `gopher://`, `ftp://`, `webcal://`, etc. that `esc_url_raw()` would otherwise accept).
* Public REST endpoint `/abtest/v1/convert` now rate-limits each visitor IP to 60 conversions per minute (filterable via `abtest_convert_rate_limit_per_min`). Returns HTTP 429 when exceeded. Prevents distributed flood from biasing experiment statistics.
* PSR-4 autoloader rejects class names containing `..` defensively (anti-traversal hardening).
* `.gitignore` extended with `.env`, `.env.*`, `wp-tests-config.php`, `*.local.php`, `*.key`, `*.pem`, `*.p12`, `secrets.json` (preventive — none of these files exist today).
* PHPCS false-positive annotations added on `file_get_contents()` calls reading local files (4 spots) and on the intentional 5-minute Watcher cron interval.

= 0.9.1 =
* Security hardening (post-audit): outbound webhook POSTs now pass `'sslverify' => true` explicitly so a third-party `http_request_args` filter can't silently downgrade SSL verification. Aligns with the explicit setting already in the GA4 integration.
* HTML import error message corrected — used to say "Only .html and .htm files are accepted" even though .zip has been accepted since v0.7.0. Message now generated from the live ALLOWED_EXTS constant and reports the rejected extension.

= 0.9.0 =
* Multilingual support (WPML / Polylang): a single experiment with `test_url = /promo/` now matches `/fr/promo/`, `/en/promo/`, `/de/promo/`, etc. The bundled `MultiLanguage` helper auto-detects WPML/Polylang and strips the language prefix from request paths before matching. Compound slugs (`pt-br`, `en-us`) supported. Mid-path occurrences of a language slug (e.g. `/blog/fr/x/`) are NOT stripped — only true URL prefixes.
* New filter `abtest_request_path` for custom multilingual setups: receives the normalized request path, returns whatever you want the matcher to see. Documented in README.
* Filter is opt-out for non-default behavior: `remove_filter('abtest_request_path', [\Abtest\MultiLanguage::class, 'strip_language_prefix'])`.

= 0.8.2 =
* RGPD data minimization: visitor_hash is now stored as 16 hex chars (64 bits) instead of 64 chars (256 bits). Birthday-collision probability stays under 3e-8 even at 1M visitors per experiment, dedup integrity preserved, and the smaller surface harder to brute-force against IP+UA rainbow tables. DB schema bumped to v1.3.0 — migration auto-truncates existing visitor_hash values via SUBSTRING before the column ALTER (idempotent, runs before dbDelta).
* Privacy policy guide text updated to describe the 64-bit truncated hash.

= 0.8.1 =
* Tested up to WordPress 6.9 (was 6.5). Local dev env (wp-env) and the wp-phpunit test suite both bumped to 6.9.4.
* Fixed PHP notice on WP 6.7+ ("_load_textdomain_just_in_time was called incorrectly") — load_plugin_textdomain now runs on `init` priority 0 instead of `plugins_loaded`.
* Performance: `GET /wp-json/abtest/v1/stats` now runs a single batched SQL query for N experiments instead of N individual queries (N+1 → 1). New public `Stats::raw_counts_for_experiments()` powers both the REST endpoint and the admin list — same SQL path everywhere.

= 0.8.0 =
* Privacy & consent gating (GDPR): new "Require consent" toggle in Settings — when on, the plugin sets no cookie and logs no event until the `abtest_visitor_has_consent` filter returns true. Without consent, visitors silently see Variant A (same path as out-of-target). Off by default, no breaking change.
* Native WordPress privacy guide content registered via `wp_add_privacy_policy_content()` — find it under Settings → Privacy → Policy Guide → Variolab – A/B Testing, ready to paste into your privacy policy.
* README now has a Privacy & GDPR section with copy-paste filter snippets for Complianz, CookieYes, and Cookiebot.
* New `Consent` helper class + 5 unit tests covering the 4 gate states (off, on+true, on+false, on+null/missing filter).

= 0.7.0 =
* HTML import accepts `.zip` archives — extracts CSS/JS/images to `wp-content/uploads/abtest-templates/{slug}/`, rewrites relative asset URLs in the HTML so the page renders with full styling (security: extension allowlist + path-traversal guard).
* Watch directory: drop or edit `index.html` files in `wp-content/uploads/abtest-templates/{slug}/` from your IDE, SFTP, or cloud sync — WP-Cron syncs changed files into pages every 5 minutes (or click "Scan now" in the Import HTML page). Hash-based change detection skips unchanged files.
* URL targeting now matches query strings (subset semantics): `test_url = /promo/?campaign=fb` matches visitor URL `/promo/?campaign=fb&utm_source=email`. Param order is canonicalized.
* URL targeting accepts Unicode paths: `test_url = /promotion-été/` matches both the raw and percent-encoded request paths.
* Validation regex updated to accept Unicode lowercase letters/digits (was ASCII-only). HTML form `pattern=` constraint removed accordingly.

= 0.6.1 =
* Targeting refinement: out-of-target visitors now silently see the baseline (Variant A) instead of getting a 404 on custom URLs. They are NOT tracked — no cookie set, no impression logged, no conversion script enqueued. Out-of-target visitors on URLs that override an existing public page still fall through to that original page (unchanged).
* The point: ad-paid traffic from outside your target audience (geo or device) doesn't waste clicks on 404s and doesn't pollute your test stats either.

= 0.6.0 =
* Targeting by device (mobile / tablet / desktop) and country (ISO codes).
* HTML import: drag-and-drop dropzone + sandboxed iframe preview before submit.
* Visitor device classified from User-Agent; country pulled from Cloudflare/Kinsta `CF-IPCountry` header (and similar X-* headers), with a `abtest_visitor_country` filter for custom geo plugins.
* Targeting check happens server-side before any cookie is set or impression logged — out-of-target visitors fall through (no variant assigned).
* Admin/bot bypass mode is exempt from targeting so preview is independent of the previewer's device/country.

= 0.5.0 =
* Multi-variant tests up to 4 variants (A/B/C/D) with equal split (1/N each).
* Stats engine supports pairwise comparisons vs baseline + Bonferroni-corrected alpha.
* Schema migration v1.2.0 — auto-backfills `_abtest_variants` from legacy control_id/variant_id pair.
* Admin form: dynamic variants list (add/remove rows up to MAX_VARIANTS).
* Experiments list: variants stacked vertically per row with lift + 95% CI vs baseline.
* CSV export extended with per-variant + pairwise columns.
* REST API stats response now includes `variants`, `comparisons`, `baseline`, `best`, `alpha`.
* Back-compat: legacy `control_id`/`variant_id` accessors and meta still work; legacy A/B keys still in compute() output.

= 0.4.0 =
* URL-decoupled experiments — `test_url` independent from variant pages.
* State machine (DRAFT → RUNNING → PAUSED/ENDED) with Resume = duplicate semantics.
* Baseline mode (Variant B optional) and auto-downgrade on URL conflict.
* Replace running atomic swap action.
* HTML import → Blank Canvas template (zero WP wrapper).
* Per-URL tracking scripts (Adwords, FB Pixel, Lemlist, etc.).
* Cache bypass (universal Cache-Control headers + WP Rocket + LiteSpeed + Kinsta detection).
* Google Analytics 4 integration (Measurement Protocol).
* Generic webhook integration (Zapier, Mixpanel, Segment, Slack, n8n) with HMAC.
* REST API GET /wp-json/abtest/v1/stats with Application Password auth.
* 95% confidence interval for the lift, date range filter, Chart.js timeline.
* GitHub Actions CI (PHP 8.1/8.2/8.3 matrix) + release workflow + Dependabot.

= 0.1.0 =
* Initial MVP — page-level A/B tests, internal tracking, cookie split, basic stats.
