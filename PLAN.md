<!-- generated-by: groundrules v1.5.0 (adopted) -->
# PLAN — Variolab – A/B Testing

**Active** plan/todo for the project. Maintained by Claude during work. The top of this file is what's happening **now**; the full shipped history is kept further down for context (also mirrored in `readme.txt` Changelog and `CHANGELOG.md`). Long-term direction → `docs/ROADMAP.md`.

**Status vocabulary**: `[ ]` to do · `[~]` delivered, in review / awaiting validation · `[x]` done & validated. Annotate reverts and key commits inline (e.g. `reverted (commit abc123)`).

## In progress

- _(nothing in flight — v0.19.0 released, bundling the v0.18.0 uninstall safeguard.)_

## Up next

- [ ] WooCommerce variants (test prices, product descriptions)
- [ ] Block-level testing (single Gutenberg block instead of a whole page)

## Ideas — to triage

Raw ideas, captured before they're lost. Not yet vetted. Each gets triaged later → a **decision** (ADR), a **build**, a **milestone** (ROADMAP), or dropped.

- [ ] Auto-purge Kinsta cache via REST API on test transitions
- [ ] Auto-detection of installed consent plugins (Complianz, CookieYes, Cookiebot) — today via filter snippet

## Waiting / blocked

- [ ] Dependabot `wp-phpunit ^6.9 → ^7.0` PR — `.wp-env.json` stays pinned at 6.9.4 until it merges.

---

# Shipped (history)

## ✅ Shipped

### v0.1.0 — MVP
- Plugin bootstrap (PSR-4, autoload fallback, activation/deactivation hooks)
- CPT `ab_experiment`, custom table `wp_abtest_events`
- Persistent 50/50 cookie, Router via `template_redirect`, content swap
- Tracker (impressions + conversions, dedup), REST endpoint `/abtest/v1/convert`, `tracker.js`
- Stats: rate, lift, two-proportion z-test
- Admin: list + edit form + start/pause/end/delete actions + nonces + capabilities
- CacheNotice (WP Rocket / W3TC / LiteSpeed detection)
- PHPUnit unit tests: Stats + Cookie
- `abtest_event_logged` hook exposed for future integrations

### v0.2.0 — URL decoupled from pages (major refactor)
- `test_url` field at the experiment level, decoupled from `control_id`
- Schema migration v1.1.0: `test_url` column + index, auto-backfill
- Router refactor: `parse_request` instead of `template_redirect`, URL-based match
- `pre_get_posts` + `posts_results` hooks to serve `private` pages
- A/B pages forced to `private` automatically when running
- "View original" admin-bar button
- Manual e2e integration tests via wp-env

### v0.3.0 — Full CRO workflow
- **Baseline mode**: optional Variant B (visitors all see A until B is added)
- **Stats by URL** view merged into the main list (no submenu)
- **"+ Add experiment to this URL"** button per section, pre-fills the form
- **Auto-downgrade to draft** on URL conflict (at save or Start)
- **"Replace running"** button (atomic swap: pause current + start new)
- **Strict state machine**:
  - DRAFT → RUNNING
  - RUNNING → PAUSED | ENDED
  - PAUSED → ENDED (via End) or DUPLICATE-RESUME
  - ENDED = terminal
- **Resume** = duplicate the experiment + flip the original to ENDED (each run period gets its own dates)
- **Duration display** (`human_time_diff`): "Since X (3 days)" or "X → Y (2 weeks)"
- **Inline buttons** instead of the status dropdown (Save & Start / Save & Pause / Save & End)

### Import HTML → Blank Canvas
- Upload a `.html` (or `.zip` with CSS/JS/images), render byte-perfect with zero WordPress wrapper
- Blank Canvas template, relative asset URL rewriting, sandboxed iframe preview

### Critical bugs fixed (logged in `docs/LEARNINGS.md`)
- `register_post_type` on `init` (not `plugins_loaded`)
- Serving `private` pages to logged-out visitors (`pre_get_posts` + `posts_results`)
- `wp_slash()` before `wp_insert_post`/`wp_update_post` for non-`$_POST` content
- Block themes don't fire `the_post` → mutate the globals directly
- WP auto-disables a plugin that fatals on load (check `active_plugins`)
- Kinsta double cache (nginx + Cloudflare) bypass via `Cache-Control: no-store`

## 🟢 Prioritized backlog

### Top 3 shipped
- [x] **Chart.js timeline** per URL (line chart of daily conversion rate)
- [x] **GA4 integration** via Measurement Protocol + Settings page
- [x] ~~**Inject the admin bar into Blank Canvas**~~ — tried then dropped: injecting `wp_head/wp_footer` breaks SPA bundlers, and moving it outside `<body>` breaks the admin-bar CSS. Trade-off accepted: admin preview via `?abtest_preview=a|b|original` in the URL.

### Per-URL tracking scripts shipped
- [x] **UrlScripts** helper + `abtest_url_scripts` option
- [x] Dynamic editor in the edit form (add/remove rows + vanilla JS)
- [x] Injection `after_body_open` / `before_body_close`:
  - via `wp_body_open` + `wp_footer` on regular WP pages (override)
  - via `stripos` + `substr_replace` inside the Blank Canvas template
- [x] Shared across every experiment on the URL

### Workflow / UX
- [x] **CSV export** of experiments + stats — button in the list, respects date + show filters, UTF-8 BOM (Excel-friendly)
- [x] **Auto scheduling** via WP-Cron (hourly tick) — `_abtest_schedule_start_at` / `_abtest_schedule_end_at` meta + datetime-local UI + soft-conflict skip
- [x] **95% confidence interval** (Wald) shown next to the lift
- [x] **HTML preview before upload** (sandboxed iframe `srcdoc` rendered live on file selection)
- [x] **Drag & drop file picker** (visible drop zone with hover state, size + extension validated client-side)
- [x] **Date range filter** (from/to + 7/30 days/all-time presets) on stats + chart
- [x] **Default "running only" filter** on URLs (hides URLs without a running experiment, "Show all" toggle)
- [x] **Didactic Help / Info area** (v0.10.0) — native WordPress help tabs (top-right "Help") on the A/B Tests pages, 4 tabs (Quick start, Stats explained, Multi-variant, Privacy & GDPR) written for non-statisticians.
- [x] **Contextual tooltip on "No winner"** (v0.10.0) — explains in one sentence WHY: too early / not enough samples / borderline / genuine null result / generic fallback. Pure helper `Abtest\Admin\StatsExplain` with 8 unit tests.
- [x] **Per-URL `noindex` toggle** (v0.11.0) — SEO row on the experiment edit form; emits `<meta robots="noindex,nofollow">` + `X-Robots-Tag`. URL-scoped (`abtest_url_settings`). New `Abtest\UrlSettings` helper + 7 unit tests + e2e.
- [x] **README screenshots + WordPress.org canonical layout** (2026-05-03) — 4 admin shots in `.wordpress-org/` with the wp.org-canonical names, referenced in `README.md` + `readme.txt`. Excluded from the `.zip` via `--exclude='.wordpress-org'`.
- [x] **Plugin Check — final cleanup pass** (v0.11.3) — excluded test bootstraps from the build, `languages/index.php` instead of `.gitkeep`, prefixed unprefixed locals in `templates/blank-canvas.php`. CI Plugin Check green.
- [x] **Trademark rename → "Uplift – A/B Testing"** (v0.12.0) — slug `ab-testing-wordpress` → `uplift-ab-testing`, text domain swap, main file rename, internal names preserved.
- [x] **Second rename → "Variolab – A/B Testing"** (v0.13.0, 2026-05-16) — wp.org flagged "Uplift" (non-distinctive + UPLIFT® USPTO Reg. 4973441). Picked "Variolab". Slug `uplift-ab-testing` → `variolab`, repo renamed `lozit/uplift-ab-testing` → `lozit/variolab`. Internal names preserved.
- [x] **Set up `10up/action-wordpress-plugin-deploy`** (2026-05-27) — `.github/workflows/wordpress-deploy.yml` deploys trunk + tag + `.wordpress-org/` to wp.org SVN on each `v*.*.*` tag. Secrets set.
- [x] **GitHub repo renamed** (2026-05-04) — `lozit/ab-testing-wordpress` → `lozit/uplift-ab-testing` via `gh repo rename`; URL refs updated; historical audit reports left frozen.

### WordPress.org submission — ✅ ACCEPTED & PUBLISHED (2026-05-27)

**Plugin approved by the wp.org Plugin Review Team and live at https://wordpress.org/plugins/variolab-ab-testing/** First release (v0.15.0) published manually to the SVN repo. Future versions auto-deploy via the GitHub Action.

Post-launch hygiene (2026-05-28):
- Dropped non-existent wp.org user `guillaumeferrari` from `Contributors:` (SVN r3551314).
- Bumped `Tested up to: 6.9 → 7.0` after WordPress 7.0 released.
- Added `--exclude='.distignore'` + `--exclude='.gitattributes'` to both `release.yml` and `ci.yml` rsync.
- Linked the wp.org plugin page from `README.md` (version badge + Install reorder).
- Repositioned HTML import as the headline use case for AI-generated landing pages (2026-05-28).
- [x] **v0.19.0 — clearer cache diagnostics on the A/B Tests list (2026-06-14).** Reworked the "Cache check" box after live feedback on a Kinsta+Cloudflare site. Added server-side **detection chips** (Kinsta / Cloudflare / cache plugin + a neutral "cache detected" indicator) — all blue/grey, because a site-level cache isn't a problem in itself (only test URLs must bypass). The baseline pill is now neutral (`cache detected` / `no cache detected`, no more alarming "NO PAGE CACHE DETECTED"). The key sentence ("make sure every test URL shows out of cache") is now a bold, highlighted callout; all box text is black. **Removed the top-of-page caching admin notice** (`CacheBypass::maybe_render_notice` + `has_running_experiment`) and folded its concise host-specific guidance (Kinsta Cache Bypass steps, cache-resilient mode, "More help → Settings") into the box. Test pills still red `CACHED`. `cacheDetectedServerSide` localized so the callout shows whenever a cache is detected server-side or by probe. PHPCS 32/32; 114 unit + 51 integration green; verified in wp-env with Playwright (Kinsta+CF simulated). Bundles the v0.18.0 uninstall safeguard. Released (tag v0.19.0, wp.org).
- [x] **v0.18.0 — uninstall data safeguard + upgrade warning (2026-06-14).** Deleting the plugin used to run `uninstall.php`, which dropped the events table and removed every experiment, page-import record, and option — so users who "deleted + reinstalled" to upgrade silently lost all their A/B history. `uninstall.php` now returns early and **preserves everything by default**, only purging when `abtest_settings['delete_data_on_uninstall']` is explicitly truthy. New **Settings → Data & uninstall** section explains "Update, don't Delete" + opt-in checkbox (off by default). readme.txt changelog + FAQ. No security delta. PHPCS clean; unit + integration green. Shipped within the v0.19.0 release tag.
- [x] **v0.17.1 — cache-check polish (2026-06-14).** "How to fix" note revealed only when a cache is detected; resilient-mode pill reads "cache resilient mode". Released (tag v0.17.1, wp.org).
- [x] **v0.17.0 — cache diagnostic pills on the A/B Tests list (2026-06-13).** Per-running-URL pill + baseline pill. Probes run client-side, anonymously, sending `X-Abtest-Cache-Check` → Router skips impression + cookie + cache-buster. Results in `localStorage`; mode `cache_check_mode`. New `assets/js/cache-check.js`, `CacheBypass::random_classic_url()`. 114 unit + 51 integration green. Released.
- [x] **v0.16.0 — cache-resilient mode + Caching/CDN documentation (2026-06-13).** Opt-in cache-resilient mode (`abtest_settings['cache_resilient']`, default off) redirects to a unique `?_abtcb=…` URL. Settings → "Caching & CDN" host-specific steps; Cloudflare detection. 112 unit + 48 integration green. Released.
- [x] **v0.15.11 — exclude the tracker from delay-JS optimisers (the REAL "click twice" cause, 2026-06-12).** `rocket_delay_js_exclusions` + `perfmatters_delay_js_exclusions`. +2 unit tests.
- [x] **v0.15.10 — conversions no longer depend on the variant cookie (2026-06-12).** `Tracker::impression_variant()` derives the variant from the impression row. New `ConvertControllerTest.php`.
- [x] **v0.15.9 — conversion tracking on imported HTML pages + admin preview mode (2026-06-12).** `Tracker::script_config()` + `blank_canvas_script_tags()`. Preview mode (badge + outlines + toast, no POST). New `TrackerScriptConfigTest.php`.
- [x] **v0.15.8 — close out the 4 residual audit Lows (2026-06-12).** E1: decoupled dedup salt via `abtest_hash_salt`. G1/B1/D accepted-by-design. Score 10/10.
- [x] **v0.15.7 — explicit HTML-import trust boundary (2026-06-12).** `_abtest_raw_trusted`; raw only when trusted, else `wp_kses_post()`. New `HtmlImportRenderTest.php`.
- [x] **v0.15.6 — audit Low-findings hardening (2026-06-12).** Reject non-`page` replace target; `realpath()`-contain the Watcher index; recommend `hash_equals()`; `.gitignore` cleanup.
- [x] **v0.15.5 — fix "translation triggered too early" PHP notice (2026-06-12).** Dropped `__()` on the custom cron interval label.
- [x] **v0.15.2 — reposition admin notices above the brand header (2026-06-12).** `<hr class="wp-header-end">` in `render_brand_header()`.
- [x] **v0.15.1 — fix fatal on imported HTML landings (2026-06-12).** Added missing `UrlScripts::render_for_position()`.

The official `wordpress/plugin-check-action@v1` was added to CI in v0.11.1, scoped to the built artifact in v0.11.2, confirmed green in v0.11.3. Items below were suppressed via `ignore-codes` with justification — **all accepted as-is by the review team**:

- [x] **🚨 BLOCKER — Rename the plugin** ✅ DONE in v0.12.0 → v0.13.0 (final name "Variolab – A/B Testing").
- [x] **`mt_rand` / `mt_srand` in `Cookie::pick_variant()`** — accepted (speed > crypto-randomness for variant pick).
- [x] **`fopen`/`fwrite`/`fread`/`fclose` in `HtmlImport::extract_zip_to_uploads()`** — accepted (stream extraction into plugin-controlled uploads).
- [x] **`DirectDB.UnescapedDBParameter` on `$table`** — accepted (false positive; `$table` always from `Schema::events_table()`).
- [x] **Direct DB queries on the custom `wp_abtest_events` table** — accepted (custom tables are a wp.org-blessed pattern).

### External integrations
- [x] **Generic webhooks** (Zapier, Mixpanel, Segment, Slack, n8n) — webhook list in Settings + optional HMAC SHA256 + `fire_on` filter + "Send test event".
- [x] **REST API stats endpoint** `GET /wp-json/abtest/v1/stats` — Application Password auth, query params, for n8n / Make / dashboards.
- [ ] WooCommerce (price / product description variants)

### Product capabilities
- [x] **Multi-variants A/B/C/D** — equal split, pairwise vs baseline + Bonferroni, dynamic UI, schema migration v1.2.0, REST + CSV extended.
- [ ] Block-level testing (single Gutenberg block instead of a whole page)
- [x] **Targeting** (devices + ISO countries via `CF-IPCountry` or `abtest_visitor_country` filter) — Router gate, admin/bot bypass exempt, 9 unit tests.
- [x] **Multilingual (WPML / Polylang)** (v0.9.0) — `MultiLanguage` helper + `abtest_request_path` filter; strips `/{lang}/` prefix before matching. 9 unit tests + e2e.

### Technical quality
- [x] **wp-phpunit integration tests** — bootstrap + wp-tests-config.php, runs in the wp-env tests-cli container.
- [x] **CI GitHub Actions** — PHP 8.1/8.2/8.3 matrix, PHPCS BLOCKING since v0.9.3, concurrency cancel-in-progress, README badges.
- [x] **Release workflow** + **Dependabot** (composer/npm/actions weekly).
- [x] **First Dependabot wave merged** (2026-05-11) — 5 dev/CI-only PRs, all CI green.
- [x] **Full cache bypass**: universal no-store headers + WP Rocket + LiteSpeed + Kinsta detection + readme.txt doc.
- [x] **Refactor `Stats::for_experiment` → batch query** (v0.8.1) — `Stats::raw_counts_for_experiments()` (1 SQL for N experiments). REST N+1 → 1. 5 integration tests.
- [x] **Bump WP-env to 6.9** + drop the `~6.5.0` pin on wp-phpunit (v0.8.1).

### GDPR / compliance (v0.8.0–v0.8.2)
- [x] **"Require consent" option** (v0.8.0) — toggle + `abtest_visitor_has_consent` filter + silent baseline path. Off by default. `Consent::is_blocked()` + 5 unit tests.
- [x] **Cookie text for the privacy policy** (v0.8.0) — `wp_add_privacy_policy_content()`, README + readme.txt sections.
- [x] **`visitor_hash` anonymization** (v0.8.2) — truncated 64 → 16 hex (64 bits). Schema migration v1.3.0 (idempotent SUBSTRING then ALTER).

### HTML import — minor limits (v0.7.0)
- [x] **Zip with assets** — secure extraction to `wp-content/uploads/abtest-templates/{slug}/` (extension allowlist + path-traversal guard), relative URL rewriting.
- [x] **Disk watch directory** — `Watcher.php` + 5-min WP-Cron + "Scan now", SHA-256 change detection, additive only.
- [x] **URL match with query string** — subset semantics, `ksort` normalization.
- [x] **Unicode URLs in `test_url`** — `rawurldecode` + `mb_strtolower`, `\p{Ll}\p{N}` regex.

### Security — audit backlog (auto-managed)

Managed by `/security-audit`. Latest report: [`docs/security/latest.md`](docs/security/latest.md).
Disclosure policy: [`SECURITY.md`](SECURITY.md). Current score: **10 / 10** (re-audit 2026-06-12 @ v0.15.7 — both Mediums + 4 Lows fixed and independently re-verified; 0 Critical/High/Medium).

**Auto-rules**: the command adds only new Critical / High / Medium findings. Lows stay in the report, not here. Items that disappear from a subsequent audit are auto-ticked.

**Open findings** (audit 2026-06-12, v0.15.2):
- [x] [MEDIUM] B — `includes/Rest/ConvertController.php:77` — bind conversions to server-side proof of impression (fixed v0.15.3) — `Tracker::has_impression()` gate returns `409 no_impression`. Covered by `tests/Integration/TrackerConversionTest.php`.
- [x] [MEDIUM] F — `includes/Integrations/Webhook.php:177` — close the authenticated webhook SSRF (fixed v0.15.4) — `set_all()` rejects private/reserved IP hosts; `send()` uses `'reject_unsafe_urls' => true`. Covered by `tests/Integration/WebhookTest.php`. **Both audit Medium findings now closed.**

**All findings closed in v0.9.1 → v0.9.3**:
- [x] [MEDIUM] F — `includes/Integrations/Webhook.php:160` — explicit `'sslverify' => true` (fixed v0.9.1).
- [x] [MEDIUM] C — `includes/Admin/HtmlImport.php:241` — error message corrected to mention `.zip` (fixed v0.9.1).
- [x] [MEDIUM] C — `includes/Admin/HtmlImport.php:251` — MIME check via `wp_check_filetype_and_ext()` (fixed v0.9.2).
- [x] [MEDIUM] F — `includes/Integrations/Webhook.php:78` — non-HTTP(S) schemes refused (fixed v0.9.2).
- [x] [LOW] H — `includes/Autoload.php:27` — refuses `..` in class names (fixed v0.9.2).
- [x] [LOW] B — `includes/Rest/ConvertController.php:69` — rate-limit 60 hits/min/IP (fixed v0.9.2).
- [x] [LOW] G — `includes/Watcher.php:42, 49` — `phpcs:ignore WordPress.WP.CronInterval` (fixed v0.9.2).
- [x] [LOW] G — file_get_contents annotations in `Watcher.php` + `HtmlImport.php` (fixed v0.9.2).
- [x] [LOW] H — `.gitignore` extended with secret patterns (fixed v0.9.2).
- [x] [LOW] E — Documented plain-text webhook-secret storage in `SECURITY.md` + README (fixed v0.9.2).
- [x] Enable GitHub **Dependabot Alerts + Updates** + **Private vulnerability reporting** (done 2026-04-30).
- [x] **PHPCS dette repaid** (v0.9.3) — 1083 findings → 0; CI `lint` job flipped to blocking.
