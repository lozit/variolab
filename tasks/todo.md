# Todo — Variolab – A/B Testing Plugin

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
- wp-admin sub-page "Import HTML" + upload form (.html / .htm, 5 MiB max)
- **Blank Canvas** page template: renders raw `post_content`, zero WP wrapper
- "Create new" / "Replace existing" modes
- `wp_slash()` on inputs (fixes the JSON backslash issue)

### Critical bugs fixed (logged in `tasks/lessons.md`)
- `register_post_type` must fire on `init`, not `plugins_loaded`
- WP auto-disables a plugin that fatals at load (re-enable after fix)
- Block themes don't trigger the `the_post` action → mutate the globals directly
- WP filters `private` pages on the front → combo `pre_get_posts` + `posts_results`
- wp-env mod_rewrite isn't loaded without `service apache2 reload`
- `wp_insert_post` eats one level of backslashes via internal `wp_unslash()` → `wp_slash()` is mandatory

---

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
- [x] **Contextual tooltip on "No winner"** (v0.10.0) — explains in one sentence WHY: too early / not enough samples / borderline / genuine null result / generic fallback. Pure helper `Abtest\Admin\StatsExplain` with 8 unit tests covering each branch.
- [x] **Per-URL `noindex` toggle** (v0.11.0) — SEO row on the experiment edit form. When checked, every visit to that URL emits both `<meta robots="noindex,nofollow">` and `X-Robots-Tag: noindex, nofollow` HTTP header. URL-scoped (`abtest_url_settings` option), shared across every experiment on the same URL. New `Abtest\UrlSettings` helper + 7 unit tests + e2e verified in wp-env.
- [x] **README screenshots + WordPress.org canonical layout** (2026-05-03) — 4 admin shots placed in `.wordpress-org/` with the wp.org-canonical names (`screenshot-1.png` … `screenshot-4.png`), referenced inline in `README.md` (1 hero + 3 inline) and captioned in `readme.txt` `== Screenshots ==` block. Excluded from the user-installed `.zip` via a new `--exclude='.wordpress-org'` line in `release.yml`. Ready for future wordpress.org publication via the `10up/action-wordpress-plugin-deploy` GitHub Action (not set up yet — separate task).
- [x] **Plugin Check — final cleanup pass** (v0.11.3) — closed the last 4 findings on the built artifact : (1) excluded `wp-tests-config.php`, `phpunit.xml*`, `phpcs.xml*` from `release.yml` + `ci.yml` rsync (test bootstraps shouldn't ship), (2) replaced `languages/.gitkeep` with the canonical `languages/index.php` "Silence is golden" pattern (`.gitkeep` was rejected as hidden), (3) prefixed two unprefixed locals in `templates/blank-canvas.php` (`$insert_at` → `$abtest_insert_at`, `$body_close` → `$abtest_body_close`). CI Plugin Check now green : 0 errors, 0 warnings.
- [x] **Trademark rename → "Uplift – A/B Testing"** (v0.12.0) — closed the last wp.org submission blocker. Coordinated multi-file change : plugin display name, slug `ab-testing-wordpress` → `uplift-ab-testing`, text domain replaced in every `__()`/`_e()` across `includes/`, main file `git mv`'d to `uplift-ab-testing.php`, Composer + npm package names updated, `phpcs.xml.dist` text-domain element + file ref, `tests/Integration/bootstrap.php` require path, `release.yml` + `ci.yml` build path + zip filename + plugin-file grep. **Internal names preserved** (no breaking change for existing installs) : `Abtest\` namespace, `abtest_*` hooks/cookies, REST `abtest/v1`, table `wp_abtest_events`, option keys.
- [x] **Second rename → "Variolab – A/B Testing"** (v0.13.0, 2026-05-16) — wp.org Plugin Review Team flagged "Uplift" on two grounds : (1) non-distinctive (industry term for the lift metric — VWO/Statsig/Insider all use "uplift" to mean conversion lift), (2) UPLIFT® USPTO Reg. 4973441 owned by UPLIFT INC. in the same "Advertising, Business & Retail Services" class. Picked "Variolab" (vario + lab, invented, 0 wp.org / USPTO / SaaS hit). Same coordinated diff as v0.12.0 : display name, slug `uplift-ab-testing` → `variolab`, text-domain swap in every PHP file, main file `git mv`'d to `variolab.php`, Composer + npm package names, `phpcs.xml.dist` element + file ref + ruleset name, `tests/Integration/bootstrap.php` require path, `release.yml` + `ci.yml` build folder + zip filename + header-version grep. **Internal names preserved** again (Abtest\ namespace, abtest_* hooks/cookies, REST abtest/v1, table wp_abtest_events). GitHub repo renamed `lozit/uplift-ab-testing` → `lozit/variolab` via `gh repo rename` (auto-redirect from old URL preserved by GitHub). Updated URL refs in `README.md` (3), `variolab.php` Plugin URI, `SECURITY.md`, `includes/Admin/HelpTabs.php` (3). Local `origin` remote auto-updated by `gh`. Historical changelog narratives + `docs/security/audit-*.md` left frozen (old URLs auto-redirect).
- [x] **Set up `10up/action-wordpress-plugin-deploy` GitHub Action** (2026-05-27) — `.github/workflows/wordpress-deploy.yml` deploys trunk + tag + `.wordpress-org/` assets to the wp.org SVN on each `v*.*.*` tag (same trigger as `release.yml`, so one tag push = GitHub Release + wp.org deploy). A step strips the leading `v` (`${GITHUB_REF_NAME#v}`) so the SVN tag is `X.Y.Z` and matches `Stable tag`. `SLUG` pinned to `variolab-ab-testing`; trunk exclusions via new `.distignore` (mirrors the `release.yml` rsync list). Secrets `SVN_USERNAME` (lozit) + `SVN_PASSWORD` stored in GitHub Actions.
- [x] **GitHub repo renamed** (2026-05-04) — `lozit/ab-testing-wordpress` → `lozit/uplift-ab-testing` via `gh repo rename`. GitHub set up the permanent redirect for the old URL automatically. Updated all URL refs in `README.md` (badges, clone URL, security advisories link), `SECURITY.md`, `uplift-ab-testing.php` `Plugin URI`, `includes/Admin/HelpTabs.php` (3 GitHub links), `.claude/commands/security-audit.md` (Dependabot API call). Local `origin` remote also auto-updated by `gh`. Historical `docs/security/audit-*.md` reports left untouched (frozen-in-time snapshots — old URLs auto-redirect).

### WordPress.org submission — ✅ ACCEPTED & PUBLISHED (2026-05-27)

**Plugin approved by the wp.org Plugin Review Team and live at https://wordpress.org/plugins/variolab-ab-testing/** First release (v0.15.0) published manually to the SVN repo (`https://plugins.svn.wordpress.org/variolab-ab-testing`, r3550427, committer `lozit`): `trunk/` + `tags/0.15.0/` (54 files each) + `assets/` (2 banners, 2 icons, 3 screenshots from `.wordpress-org/`). Future versions auto-deploy via the GitHub Action (see the deploy-action item above).

Post-launch hygiene (2026-05-28):
- Dropped non-existent wp.org user `guillaumeferrari` from `Contributors:` (wp.org import warning); SVN r3551314 updated `trunk/readme.txt` + `tags/0.15.0/readme.txt`.
- Bumped `Tested up to: 6.9 → 7.0` after WordPress 7.0 released (Plugin Check started failing on the 2026-05-20 push). `.wp-env.json` stays at 6.9.4 until Dependabot's pending `wp-phpunit ^6.9 → ^7.0` PR merges.
- Added `--exclude='.distignore'` + `--exclude='.gitattributes'` to both `release.yml` and `ci.yml` rsync — the `.distignore` added in `c1a9d7c` was leaking into the build folder and tripping Plugin Check's "Hidden files not permitted". CI green again on `6cc2db6`.
- Linked the wp.org plugin page from `README.md` (3779261): new shields.io badge auto-displaying the published version, Install section reordered to lead with `wp-admin → Plugins → Add New → search "Variolab"`, source-clone path kept as the secondary option and pointed at the real `lozit/variolab.git` (was the `<you>` placeholder).
- Repositioned HTML import as the headline use case for AI-generated landing pages (2026-05-28). `readme.txt`: tagline rewritten to lead with "A/B test WordPress pages or AI-generated HTML landings", new opening paragraph in Description name-checks Claude / v0 / Lovable / Cursor / bolt.new, two new Features bullets (HTML/ZIP import + Watch directory), Tags swapped from `analytics` to `landing page` + `html import`, Screenshot 2 caption rewritten benefit-first. `README.md`: hero paragraph extended with "the ones your AI tool just generated", new blockquote callout under the hero pointing at the existing `### HTML import & Blank Canvas` section, that section gets a punchier 2-line intro, and the Quick start gets an "Or import an HTML landing first" branch. `includes/Admin/HtmlImport.php`: the admin-page intro `__()` string now names the supported AI exports. Stable tag stays 0.15.0 — pure copy/positioning, no plugin code change. SVN trunk + tags/0.15.0/readme.txt synced so the wp.org page reflects the new pitch.
- [x] **v0.15.9 — conversion tracking on imported HTML pages + admin preview mode (2026-06-12).** **Bug:** `templates/blank-canvas.php` renders imported landings raw and never runs `wp_enqueue_scripts`, so `Tracker::enqueue_tracker_js()` couldn't reach them — click/URL conversion goals fired for *zero* visitors on every imported page (silent data loss). **Fix:** extracted the tracker payload into `Tracker::script_config()`; `blank_canvas_script_tags()` emits the config + `tracker.js` via `wp_get_inline_script_tag()` / `wp_get_script_tag()` and blank-canvas injects them before `</body>`. **Feature (user request):** `script_config()` now also returns a payload for logged-in `edit_posts` users who are bypassed, with `preview:true`; `tracker.js` in preview mode adds an "A/B preview" badge, outlines elements matching the selector goal, and toasts on a matching click **without** POSTing (no stat pollution). New `tests/Integration/TrackerScriptConfigTest.php` (4 tests: tracked→real tracker, bypassed editor→preview, untracked anon→nothing, no experiment→nothing). 110 unit + 40 integration green. JS preview UI to be confirmed live on the user's `/promo/` imported page.
- [x] **v0.15.8 — close out the 4 residual audit Lows (2026-06-12).** **E1 (real fix):** decoupled the `visitor_hash` dedup salt from `wp_salt('auth')` via a dedicated `abtest_hash_salt` option seeded once from the current auth salt (`Cookie::hash_salt()`) — existing hashes keep matching (no one-time dedup reset, which is what had blocked this), future AUTH_KEY/AUTH_SALT rotations no longer reset dedup; deleted on uninstall; +2 unit tests. **G1 / B1 / D (accepted-by-design, documented in code):** their naive fixes each regress — sanitising the Watcher slug breaks asset URLs (no real desync); strengthening the IP+UA hash adds GDPR-tracking surface for no gain (post-M1 the impression-check is the real dedup gate); a `LIMIT` on the Stats `GROUP BY` aggregates would truncate valid rows. Rationale recorded at each site (`Watcher::scan`, `Cookie::visitor_hash` docblock, `Stats` query). Also added an in-code note on the M1 IP/UA-change conversion under-count trade-off. Audit report + score (10/10) updated.
- [x] **v0.15.7 — explicit HTML-import trust boundary (audit Low-C, Option 3, 2026-06-12).** The Blank Canvas template renders `post_content` raw, so its safety depended on WP's *implicit* kses-on-save for non-`unfiltered_html` users. Made it explicit without removing the feature on multisite (rejected the strict `unfiltered_html` gate for that reason): import now records `_abtest_raw_trusted` (= `current_user_can('unfiltered_html')`) on the page, and `HtmlImport::render_html()` (called from `blank-canvas.php`) renders raw only when trusted, else re-filters through `wp_kses_post()`. Idempotent for content WP already sanitised at save; fail-safe against a future refactor that stored raw content un-filtered; legacy/Watcher pages without the flag stay trusted (zero regression). New `tests/Integration/HtmlImportRenderTest.php` (3 tests: trusted→raw, untrusted→stripped, legacy→raw).
- [x] **v0.15.6 — audit Low-findings hardening (2026-06-12).** Cleared 4 of the 9 🔵 Low audit findings: (A) `HtmlImport::replace_existing()` now rejects any non-`page` target (`invalid_target`) so a tampered `target_page_id` can't overwrite an arbitrary post/CPT; (G2) `Watcher::scan()` `realpath()`-contains the resolved index file under the watch dir (rejects symlink escapes from `RecursiveDirectoryIterator`'s default symlink-follow); (F) webhook Secret help text now recommends `hash_equals()` over `==`; (H) dropped the misleading `wp-tests-config.php` line from `.gitignore` (file stays intentionally tracked — integration bootstrap needs it, only wp-env defaults inside). **Deferred with reasons (in `docs/security/latest.md`):** slug-sanitise (G1, would break asset URLs), `unfiltered_html` import gate (C, multisite capability change — product call), dedicated hash salt (E1, one-time dedup reset on upgrade). B1 mitigated by M1; D informational.
- [x] **v0.15.5 — fix "translation triggered too early" PHP notice (2026-06-12).** `Watcher::register_interval()` (the `cron_schedules` filter callback) wrapped the custom 5-min interval's `display` label in `__()`. Since `Watcher::register()` calls `wp_schedule_event()` at `plugins_loaded` — and the filter also fires on every `wp-cron.php` run — the `__()` ran before `init`, tripping WP 6.7+'s `_load_textdomain_just_in_time` notice (caught via a `doing_it_wrong_run` backtrace under `wp plugin check`). Dropped the `__()`; the label is plain English now (it only surfaces in cron tools like WP Crontrol). Scheduler uses the native `hourly` schedule so it was never affected. Verified: 0 notices on re-running Plugin Check.
- [x] **v0.15.2 — reposition admin notices above the brand header (2026-06-12).** Third-party plugin notices (Solid Security IP detection, WP Rocket, etc.) and Variolab's own `CacheNotice` used to land between the brand header and the page content because WP's core notice-repositioning JS falls back to "insert after first `.wrap h1`" when no `wp-header-end` marker exists. Added `<hr class="wp-header-end">` at the top of `Admin::render_brand_header()` — WP's `notices.move()` in `wp-admin/js/common.js` now uses that as the insertion point, so notices appear above the brand header on all four pages (List / Edit / Settings / Import).
- [x] **v0.15.1 — fix fatal on imported HTML landings (2026-06-12).** `templates/blank-canvas.php:40` called `UrlScripts::render_for_position()` but the class only shipped `print_for_position()` (the echo variant used by themed pages via Router hooks). Result: every visit to an A/B-tested HTML-imported page on a fresh install crashed with `Call to undefined method Abtest\UrlScripts::render_for_position()`. Bug present since initial import — never triggered until a user clicked through an imported landing in production. Fix: added `render_for_position()` (return-string counterpart, wraps each entry via `wp_get_inline_script_tag()`); `print_for_position()` left untouched so themed pages keep their existing behavior. No new SQL / input surface / capability check; intake gate (`manage_options` + nonce + `unfiltered_html` in `Admin\Admin::handle_save()`) unchanged. `docs/security/latest.md` got a one-line "no security delta vs v0.15.0" note to clear `release.yml`'s pre-tag grep.

The official `wordpress/plugin-check-action@v1` was added to CI in v0.11.1, scoped to the built artifact in v0.11.2, and confirmed green in v0.11.3. The items below were suppressed via `ignore-codes` with justification — **all accepted as-is by the review team** (the suppression + rationale held; none required a code change):

- [x] **🚨 BLOCKER — Rename the plugin** ✅ DONE in v0.12.0 → v0.13.0 (final name "Variolab – A/B Testing") — see the rename items above for the full diff scope.
- [x] **`mt_rand` / `mt_srand` in `Cookie::pick_variant()`** — accepted as-is (rationale "speed > crypto-randomness for variant pick" held). Fallback if ever revisited: switch to `wp_rand()` + drop the seed-based deterministic test path.
- [x] **`fopen`/`fwrite`/`fread`/`fclose` in `HtmlImport::extract_zip_to_uploads()`** — accepted as-is (stream-based extraction into a plugin-controlled uploads subfolder). Fallback: `WP_Filesystem::put_contents()` per file.
- [x] **`PluginCheck.Security.DirectDB.UnescapedDBParameter` on `$table` interpolation** — accepted (false positive; `$table` always from `Schema::events_table()`).
- [x] **Direct DB queries on the custom `wp_abtest_events` table** — accepted (custom tables are a wp.org-blessed pattern). Fallback: add a `wp_cache_get/set` layer in `Stats.php`.

### External integrations
- [x] **Generic webhooks** (Zapier, Mixpanel, Segment, Slack, n8n) — webhook list in Settings + optional HMAC SHA256 + `fire_on` filter (all / conversion-only) + "Send test event" button
- [x] **REST API stats endpoint** `GET /wp-json/abtest/v1/stats` — auth via Application Password, query params (url, experiment_id, from, to, status, breakdown), for n8n / Make / external dashboards
- [ ] WooCommerce (price / product description variants)

### Product capabilities
- [x] **Multi-variants A/B/C/D** — equal split, pairwise vs baseline + Bonferroni, dynamic add/remove UI, schema migration v1.2.0, REST + CSV extended
- [ ] Block-level testing (single Gutenberg block instead of a whole page)
- [x] **Targeting** (devices mobile/tablet/desktop + ISO countries via Cloudflare/Kinsta `CF-IPCountry` header or `abtest_visitor_country` filter) — Router gate, admin/bot bypass exempt, 9 unit tests on the UA classifier
- [x] **Multilingual (WPML / Polylang)** (v0.9.0) — `MultiLanguage` helper auto-detected + public `abtest_request_path` filter. Strips the `/{lang}/` prefix before matching → a single experiment with `test_url = /promo/` matches `/fr/promo/`, `/en/promo/`, etc. Compound slugs supported (`pt-br`). Stripping only at the leading position (not mid-path). 9 unit tests + e2e WPML simulated in wp-env.

### Technical quality
- [x] **wp-phpunit integration tests** — bootstrap + wp-tests-config.php, 10 tests (SchemaTest, ExperimentTest, SchedulerTest), runs in the wp-env tests-cli container
- [x] **CI GitHub Actions** — `.github/workflows/ci.yml` with PHP 8.1/8.2/8.3 matrix (syntax check + PHPUnit gating), PHPCS BLOCKING since v0.9.3, concurrency cancel-in-progress, README badges
- [x] **Release workflow** + **Dependabot** (composer/npm/actions weekly)
- [x] **First Dependabot wave merged** (2026-05-11) — 5 PRs, all dev/CI-only (no runtime impact, no plugin version bump): `softprops/action-gh-release` v2→v3, `actions/checkout` v4→v6, `actions/cache` v4→v5, `@wordpress/env` 10.39.0→11.5.0, `yoast/phpunit-polyfills` ^2.0→^4.0. All CI checks green post-merge.
- [x] **Full cache bypass**: universal no-store headers + WP Rocket + LiteSpeed + Kinsta detection (notice with link to MyKinsta Cache Bypass) + readme.txt doc
- [x] **Refactor `Stats::for_experiment` → batch query** (v0.8.1) — new public `Stats::raw_counts_for_experiments(array $ids, $from, $to)` (1 SQL for N experiments). Used by the REST `GET /abtest/v1/stats` endpoint (N+1 → 1) AND the admin list (consolidation, removed the private duplicate `aggregate_event_counts`). 5 new integration tests.
- [x] **Bump WP-env to 6.9** + drop the `~6.5.0` pin on wp-phpunit (v0.8.1) — `.wp-env.json` → `WordPress/WordPress#6.9.4`, `composer.json` → `wp-phpunit/wp-phpunit ^6.9`, `Tested up to: 6.9` in readme.txt. Bonus fix: the WP 6.7+ `_load_textdomain_just_in_time` notice — moved `load_plugin_textdomain` from `plugins_loaded` to `init/0`.

### GDPR / compliance (v0.8.0–v0.8.2)
- [x] **"Respect consent" option** (v0.8.0) — "Require consent" toggle in Settings + `abtest_visitor_has_consent` filter (true/false/null) + silent baseline path (zero cookie, zero impression) when consent is missing. Off by default, no breaking change. `Consent::is_blocked()` helper + 5 unit tests.
- [x] **Cookie text for the privacy policy** (v0.8.0) — 3 surfaces: (a) WP-native `wp_add_privacy_policy_content()` via `includes/PrivacyPolicy.php` (visible in Settings → Privacy → Policy Guide), (b) `## Privacy & GDPR` section in README.md with Complianz/CookieYes/Cookiebot snippets, (c) `== Privacy ==` section in readme.txt.
- [x] **`visitor_hash` anonymization** (v0.8.2) — truncated from 64 → 16 hex chars (64 bits). Smaller attack surface, dedup still safe (collision < 3e-8 at 1M visitors/exp). Schema migration v1.3.0: idempotent SUBSTRING then ALTER COLUMN, verified e2e in wp-env. PrivacyPolicy text updated.

### HTML import — minor limits (v0.7.0)
- [x] **Zip with assets** (CSS, JS, images) — secure extraction to `wp-content/uploads/abtest-templates/{slug}/` (extension allowlist + path-traversal guard), relative href/src/srcset/url() rewritten to absolute URLs in the stored HTML
- [x] **Disk watch directory** (sync IDE → reload) — `Watcher.php` + 5-minute WP-Cron + "Scan now" button in Import HTML, change detection via SHA-256 hash on `index.html`, additive only (never deletes), zip pages tagged with `_abtest_watcher_slug` to avoid duplicates
- [x] **URL match with query string** (`?campaign=fb`) — subset semantics (every param of the `test_url` must be present in the request, but the request can have extras like `utm_*`), `ksort` normalization for canonicalization
- [x] **Unicode URLs in `test_url`** — `rawurldecode` + `mb_strtolower`, `\p{Ll}\p{N}` regex, HTML `pattern=` attribute removed from the form

### Security — audit backlog (auto-managed)

Managed by `/security-audit`. Latest report: [`docs/security/latest.md`](../docs/security/latest.md).
Disclosure policy: [`SECURITY.md`](../SECURITY.md). Current score: **10 / 10** (re-audit 2026-06-12 @ v0.15.7 — both Mediums + 4 Lows fixed and independently re-verified; 0 Critical/High/Medium; see [`docs/security/latest.md`](../docs/security/latest.md)).

**Auto-rules**: the command adds only new Critical / High / Medium findings. Lows stay in the report, not here. Items that disappear from a subsequent audit are auto-ticked.

**Open findings** (audit 2026-06-12, v0.15.2):
- [x] [MEDIUM] B — `includes/Rest/ConvertController.php:77` — bind conversions to server-side proof of impression (fixed v0.15.3, 2026-06-12) — `Tracker::has_impression()` gate returns `409 no_impression` unless a server-side impression row exists for that (experiment, variant, visitor); forgery vector closed, rate stays honest. Covered by `tests/Integration/TrackerConversionTest.php`.
- [x] [MEDIUM] F — `includes/Integrations/Webhook.php:177` — close the authenticated webhook SSRF (fixed v0.15.4, 2026-06-12) — `set_all()` rejects literal loopback/link-local/private/reserved IP hosts (`host_is_blocked()`); `send()` passes `'reject_unsafe_urls' => true` for request-time host resolution + redirect-bypass coverage. Covered by `tests/Integration/WebhookTest.php` (14 cases). **Both audit Medium findings now closed.**

**All findings closed in v0.9.1 → v0.9.3**:
- [x] [MEDIUM] F — `includes/Integrations/Webhook.php:160` — explicit `'sslverify' => true` (fixed v0.9.1, commit `5eff481`)
- [x] [MEDIUM] C — `includes/Admin/HtmlImport.php:241` — error message corrected to mention `.zip` (fixed v0.9.1, commit `5eff481`)
- [x] [MEDIUM] C — `includes/Admin/HtmlImport.php:251` — MIME check via `wp_check_filetype_and_ext()` added (fixed v0.9.2)
- [x] [MEDIUM] F — `includes/Integrations/Webhook.php:78` — non-HTTP(S) schemes refused on webhook URL (anti-SSRF) (fixed v0.9.2)
- [x] [LOW] H — `includes/Autoload.php:27` — refuses `..` in class names (fixed v0.9.2)
- [x] [LOW] B — `includes/Rest/ConvertController.php:69` — rate-limit 60 hits/min/IP via transient + `abtest_convert_rate_limit_per_min` filter (fixed v0.9.2)
- [x] [LOW] G — `includes/Watcher.php:42, 49` — `phpcs:ignore WordPress.WP.CronInterval` annotation (fixed v0.9.2)
- [x] [LOW] G — `includes/Watcher.php:122` + `includes/Admin/HtmlImport.php:289, 469, 485` — `phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents` annotations (fixed v0.9.2)
- [x] [LOW] H — `.gitignore` extended with `.env`, `.env.*`, `wp-tests-config.php`, `*.local.php`, `*.key`, `*.pem`, `*.p12`, `secrets.json` (fixed v0.9.2)
- [x] [LOW] E — Documented plain-text storage of webhook secrets in `SECURITY.md` (out-of-scope) + README Webhooks section (fixed v0.9.2)
- [x] Enable GitHub **Dependabot Alerts + Updates** + **Private vulnerability reporting** in Settings → Code security (done 2026-04-30, verified via API)
- [x] **PHPCS dette repaid** (v0.9.3) — 1083 findings → 0. PHPCBF auto-fix + ruleset relaxed on modern-PHP cosmetics (short array, alignment, trivial docblocks) + ~30 justified `phpcs:ignore` annotations on legitimate false positives (table interpolation, local file_get_contents, third-party hooks like WPML, filter-callback signatures with reserved unused params). All Security / SQL / i18n / capability / nonce sniffs stay strict. CI `lint` job flipped to **blocking** (was `continue-on-error`).
