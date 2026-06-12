# Security Audit Report — `variolab-ab-testing` v0.15.2

**Date** : 2026-06-12
**Branch** : `main` @ `f608928`
**Auditor** : situated (9 plugin surfaces) + OWASP grid, fan-out across 5 parallel reviewers (skill `/security-audit`)
**Previous** : [`audit-2026-05-20-v0.15.0.md`](./audit-2026-05-20-v0.15.0.md)

> First full code-path audit since the v0.14.0 wp.org round-2 review. The v0.15.0/0.15.1/0.15.2 deltas (list redesign, `UrlScripts::render_for_position()` fatal fix, `wp-header-end` notice reposition) were all UI/render-path changes; this audit re-walks every surface end to end, not just the deltas.
>
> **Patch trail since this audit** — v0.15.3 fixes M1 (forged conversions), v0.15.4 fixes M2 (webhook SSRF); both Medium findings closed (see the marked entries below). **v0.15.5 — no security delta vs v0.15.4**: drops a `__()` from the `cron_schedules` display label so the text domain no longer loads before `init` (kills a WP 6.7+ `_load_textdomain_just_in_time` notice). Pure i18n-timing fix, no input surface / capability / data-flow change.

---

## Step 0 — automated tooling

| Tool | Result |
|------|--------|
| `composer run lint` (PHPCS WP ruleset) | ✅ clean, 32 files |
| `composer run test` (PHPUnit) | ✅ 108 tests, 518 assertions |
| `composer audit` (composer CVEs) | ✅ no advisories |
| Dependabot open alerts | ✅ none |

No tooling regression. Proceeded to the manual audit.

---

## 📊 Summary

| Severity | Count | Delta vs v0.15.0 |
|----------|-------|------------------|
| 🔴 Critical | **0** | — |
| 🟠 High | **0** | — |
| 🟡 Medium | **2** | +2 (deeper walk than the frozen-code re-affirmation) |
| 🔵 Low | **9** | +9 |

## 🏆 Overall Score : **8 / 10**

Production-ready. No Critical or High. The two Medium findings are (1) a genuine data-integrity gap in the public conversion endpoint that undermines the plugin's core promise — trustworthy A/B numbers — and (2) an admin-trust-mitigated SSRF that core WordPress itself permits. Neither blocks a release; M1 is the one worth scheduling because it weakens the product's whole reason to exist.

## 🚦 Verdict

✅ **GO release** — no Critical/High. **Recommended next patch**: address M1 (forged conversions). The rest is hardening / documentation / robustness.

---

## 🟡 Medium findings

**[🟡 Medium] Public `/convert` endpoint accepts forged conversions via attacker-set cookie** — ✅ FIXED in v0.15.3 (2026-06-12): `ConvertController::handle()` now calls `Tracker::has_impression( $experiment_id, $variant, $visitor )` and returns `409 no_impression` unless a server-side impression row exists for that exact (experiment, variant, visitor). Covered by `tests/Integration/TrackerConversionTest.php` (4 tests). The forgery vector is closed and the conversion rate stays honest (every conversion now requires a preceding impression).
- File: `includes/Rest/ConvertController.php`
- Line: 77–84
- Surface: B (REST endpoints)
- Problematic code: `$variant = Cookie::get_variant( $experiment_id ); ... Tracker::instance()->log_conversion( $experiment_id, $variant, $visitor );`
- Risk: The inline comment claims the variant "comes from the cookie set during impression — never trusted from the client", but the cookie is fully client-controlled. An unauthenticated attacker can `POST /abtest/v1/convert` with any *running* `experiment_id` while sending `Cookie: abtest_<id>=a` (or `=b`) by hand. `Cookie::get_variant()` accepts any value matching the allowed labels, so the conversion logs. Per-IP rate limiting (60/min) + `visitor_hash` dedup throttle a single host, but the dedup hash is IP+UA only (see Low-E1), so a rotating/distributed attacker can inflate a competitor's conversion count for a guessable `experiment_id` and tip the z-test toward a false "winner". Blast radius is bounded (running experiments only, no DB-fill, no priv escalation) → data-integrity, Medium not High. But the entire value of the plugin is *trustworthy* A/B numbers, so this is the finding worth fixing.
- Fix: Bind a conversion to server-side proof of a prior impression. Option A (lightweight): in `handle()`, before logging, require an existing impression row for this `experiment_id`+`visitor_hash` (`Tracker::already_logged(... EVENT_IMPRESSION ...)`); a forged conversion then also needs a forged impression, which is itself visitor-hash-deduped. Option B (stronger): issue a short-lived HMAC token (`hash_hmac('sha256', "$experiment_id|$visitor_hash|".time_bucket(), wp_salt())`) only when an impression is actually logged, send it to `tracker.js`, and verify it in `handle()`.

**[🟡 Medium] No host/IP SSRF guard on admin-configured webhook URL** — ✅ FIXED in v0.15.4 (2026-06-12): `Webhook::set_all()` now rejects URLs whose host is a literal loopback/link-local/private/reserved IP via `host_is_blocked()` (`FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`), and `Webhook::send()` passes `'reject_unsafe_urls' => true` so WP resolves hostnames and blocks internal targets (incl. redirect bypasses) at request time. Covered by `tests/Integration/WebhookTest.php` (14 cases). Both Medium findings now closed → effective score 10/10.
- File: `includes/Integrations/Webhook.php`
- Line: 80 (intake protocol check), 166–177 (`wp_remote_post`)
- Surface: F (outbound HTTP)
- Problematic code: `if ( ! preg_match( '#^https?://#i', $url ) ) { continue; }` … `wp_remote_post( (string) $hook['url'], [ ... 'sslverify' => true ] )`
- Risk: The protocol allowlist correctly blocks `file://` / `gopher://` / `ftp://` pivots (closed in v0.9.2). But there is no host filtering, so an admin — or anyone who hijacks an admin session — can point a webhook at `http://169.254.169.254/…` (cloud metadata), `http://localhost`, or RFC1918 private IPs, and the server POSTs from inside the trust boundary. This is an *authenticated* SSRF (`manage_options`), the same posture core WordPress takes on outbound requests → Medium, not Critical.
- Fix: Add `'reject_unsafe_urls' => true` to the `wp_remote_post` args so WP's own redirect/host validation engages. For defence-in-depth, reject loopback + link-local (`169.254.0.0/16`) + RFC1918 ranges at intake in `set_all()`.

---

## 🔵 Low findings

**[🔵 Low] Conversion dedup hash is coarse IP+UA — trivially defeated**
- File: `includes/Cookie.php:89` / `includes/Tracker.php:78–84` — Surface B
- `visitor_hash = substr(sha256(ip|ua|wp_salt), 0,16)`. Dedup is permanent (events-table COUNT) and correct, but an attacker varying the UA header mints unlimited distinct hashes, defeating dedup and amplifying M1. Acceptable for an MVP tracker; resolved for free by M1 Option B (token-based).

**[🔵 Low] `visitor_hash` salt couples dedup continuity to auth-key rotation**
- File: `includes/Cookie.php:89` — Surface E
- Salted with `wp_salt('auth')`, so it is *not* attacker-forgeable (good). But `wp_salt('auth')` rotates on `AUTH_KEY`/`AUTH_SALT` changes (incident response, host key rotation), silently resetting dedup and re-counting visitors. This is operational robustness, not a security hole.
- Fix (optional): use a dedicated stored salt — `add_option('abtest_hash_salt', wp_generate_password(64,true,true))` once on activation — so dedup survives auth-key rotation while staying unforgeable.

**[🔵 Low] HTML import raw render relies on implicit kses-on-save rather than an explicit `unfiltered_html` gate**
- File: `includes/Admin/HtmlImport.php:208` (gate) → `templates/blank-canvas.php:65` (raw echo) — Surface C
- Import is `manage_options` + nonce, no `nopriv`. `post_content` is rendered raw (no `the_content`, no escaping). Today this is safe because `wp_insert_post` applies WP's default `content_save_pre` kses filtering for users *without* `unfiltered_html` (the multisite site-admin case), so only true `unfiltered_html` admins get raw `<script>` — stored-XSS-to-self, the accepted WP trust model. The risk is *latent*: a future refactor that bypassed `wp_insert_post` or called `kses_remove_filters()` would hand a multisite site-admin a stored-XSS-to-visitors primitive.
- Fix: gate import on `current_user_can('unfiltered_html')` (matching the URL-scripts path at `Admin.php:358`) — explicit, self-documenting, multisite-safe.

**[🔵 Low] `target_page_id` lets an admin overwrite an arbitrary post, not just plugin pages**
- File: `includes/Admin/HtmlImport.php:280, 363–379` — Surface A
- `replace_existing()` validates only `instanceof WP_Post`, not post type or plugin origin, then `wp_update_post` overwrites content + forces the Blank Canvas template. Admin-only, nonce-gated → self-inflicted/social-engineering, not a boundary crossing.
- Fix (optional): require `'page' === get_post_type($page_id)` (or membership in the curated dropdown list) before overwriting.

**[🔵 Low] `wp-tests-config.php` is tracked despite its `.gitignore` entry**
- File: `.gitignore:48` — Surface H
- The file was committed before being ignored, so the ignore rule is inert — future edits adding a real DB password would commit silently. Current contents read `DB_PASSWORD` from `getenv()` with wp-env's public default `'password'`; no real secret today.
- Fix: either `git rm --cached wp-tests-config.php` or drop the misleading `.gitignore` line and keep it tracked intentionally.

**[🔵 Low] Unbounded aggregate SELECTs (no `LIMIT`)**
- File: `includes/Stats.php:58, 185`; `includes/Admin/CsvExport.php:217` — Surface D
- `GROUP BY day/experiment/variant/event_type` so the result set is naturally small (#days × #variants × #types), but the underlying `abtest_events` table is unbounded so aggregation cost grows over time. Not a DoS vector; informational. Indexes (`exp_var_type`, `test_url_idx`) already cover the queries.

**[🔵 Low] Webhook receiver-side `hash_equals()` not documented**
- File: `includes/Admin/Settings.php:224` — Surface F
- The help text describes the `X-Abtest-Signature` HMAC header but gives no verification guidance, so integrators may verify with `==`/`===` (timing side-channel on their end).
- Fix: append "Verify with a constant-time comparison (e.g. PHP `hash_equals()`)." to the help string.

**[🔵 Low] Watcher slug taken raw from on-disk folder name**
- File: `includes/Watcher.php:116, 166, 224–236` — Surface G
- `$slug = basename($folder)` (no traversal — `basename` strips paths) flows unsanitized into the `META_SLUG` lookup while `wp_insert_post` runs `sanitize_title` internally on `post_name`. A folder name that `sanitize_title` mangles could desync create-vs-update detection (re-create instead of update). Correctness edge case, not a security hole; no arbitrary-post-overwrite (updates match the private `_abtest_watcher_slug` meta).
- Fix (optional): `$slug = sanitize_title(basename($folder)); if ('' === $slug) continue;` used consistently for both lookup and `post_name`.

**[🔵 Low] `RecursiveDirectoryIterator` follows symlinks by default**
- File: `includes/Watcher.php:211` — Surface G
- The iterator is rooted inside `uploads/abtest-templates/` but follows symlinks by default, so a symlinked subfolder pointing outside the watch dir would be traversed. Requires an attacker who can already write symlinks into `wp-content/uploads` (pre-existing FS access) → very low.
- Fix (optional): guard each yielded path with a `realpath()` containment check against the watch root.

---

## ✅ Surfaces verified clean

- **Surface A** (admin handlers): every state-changing handler enforces `current_user_can('manage_options')` then `check_admin_referer()` before mutation (`handle_save`, `handle_status_change`, `handle_resume`, `handle_replace_running`, `handle_delete`, `HtmlImport::handle_upload/handle_scan_now`, `Settings::handle_save/handle_test_webhook`, `CsvExport::handle`). All `$_POST/$_GET` go through `wp_unslash()` + sanitizer; raw-code exceptions (`variants`, `url_scripts[*][code]`, `webhooks`) are each re-validated downstream. All redirects use `wp_safe_redirect()` with whitelisted notice types. Menus registered with `manage_options`. URL-scripts raw-JS path correctly gated on `unfiltered_html` + nonce.
- **Surface B** (REST): `permission_callback` coherent — `__return_true` only on the intentional public `/convert` (rate-limited + deduped); `/stats` requires `manage_options`. `args` schemas present; date params validated by `Stats::is_valid_date()` regex + `checkdate()` before SQL. No secret/hash/IP leakage in responses. `/convert` validates experiment exists + is running before any insert. (Forgery via cookie = M1.)
- **Surface C** (upload/zip): extension allowlist → `wp_check_filetype_and_ext()` MIME → `is_uploaded_file()` → size cap (`min(max_bytes, wp_max_upload_size)`) all present and correctly ordered. Zip extraction rejects `..`/absolute/dotfiles/`__MACOSX/`/code-bearing files; HTML/JS never written to disk (wp.org policy); slug `sanitize_title`'d; content `wp_slash`'d; iframe preview `sandbox="allow-scripts"` without `allow-same-origin`.
- **Surface D** (SQL): all 11 `$wpdb` call sites use `prepare()` with correct placeholders, `$wpdb->insert` with typed format arrays, or int-only `IN()` placeholder strings over `intval`-mapped IDs. No user-controlled column/table/`ORDER BY`. Migrations idempotent + version-gated.
- **Surface E** (cookies): `httponly=true`, `samesite=Lax`, `secure=is_ssl()`, TTL ≤ 30d. No raw IP/UA stored. Runtime `substr(…,0,16)` matches schema `CHAR(16)`. Cookie variant validated (`sanitize_key` + allowlist), never trusted for a privileged decision; visitor hash recomputed server-side.
- **Surface F** (outbound HTTP): `sslverify => true` explicit on both Webhook + GA4; HMAC `sha256=...` over exact JSON body; secret never logged; `esc_url_raw` + http(s) allowlist on intake; non-blocking by default (8s blocking only for the test button). GA4 endpoint hardcoded, secrets `rawurlencode`'d.
- **Surface G** (cron/fs): Scheduler trusts only DB queries + `current_time()`, no external input. Watcher path-bounded to `uploads/abtest-templates/`, SHA-256 dedup, no arbitrary-post-overwrite (matches private meta).
- **Surface H** (bootstrap): every production PHP file guards `defined('ABSPATH') || exit;`; `uninstall.php` guards `WP_UNINSTALL_PLUGIN`. No side-effects at require in the main file. `Autoload::load` refuses `..` and resolves only the `Abtest\` prefix — no traversal escape.
- **Surface I** (consent gate): traced — when `require_consent` ON and `abtest_visitor_has_consent` returns null/false, `is_blocked()` fails closed → no cookie, no impression (`current_is_tracked` false), no conversion script (`Tracker::enqueue_tracker_js` early-returns). Admin/bot bypass exempt; default OFF preserved.
- **JS** (6 files): no DOM-XSS sink. tracker.js reads only `window.AbtestTracker`, sends `X-WP-Nonce`, ignores responses; no `eval`/`new Function`/URL/cookie injection. Editor JS uses static template literals or server-escaped + `JSON.parse`+`escapeXml` data. Preview iframe sandboxed. (One informational note: `list-interactions.js` `escapeXml()` omits quote-escaping but its output lands only in SVG text content, never attributes — harden defensively if ever reused in an attribute.)
- **Secrets / git hygiene**: no hardcoded secrets (`git ls-files | grep -E '(API_KEY|SECRET|PASSWORD|TOKEN|...)'` clean); GA4 + webhook secrets via `get_option`. `.gitignore` covers `.env*`, `*.key`, `*.pem`, `*.p12`, `*.local.php`, `secrets.json`.

---

## 🎯 Top 3 priorities

1. **[Medium] Forged conversions via attacker cookie** — `includes/Rest/ConvertController.php:77` — bind a conversion to a server-side proof of impression (existing-impression-row check, or HMAC token issued at impression time).
2. **[Medium] Webhook SSRF host guard** — `includes/Integrations/Webhook.php:177` — add `'reject_unsafe_urls' => true`; optionally reject loopback/link-local/RFC1918 at intake.
3. **[Low → do it, cheap] Gate HTML import on `unfiltered_html`** — `includes/Admin/HtmlImport.php:208` — makes the raw-render trust boundary explicit and multisite-safe, matching the URL-scripts path.

---

## Verdict

**Score 8/10 — GO release.** No Critical or High findings; tooling all green; all nine surfaces walked end to end. Two Medium findings (forged conversions, authenticated webhook SSRF) and nine Lows, none of them release blockers. Schedule M1 for the next patch since it bears directly on the integrity of the A/B numbers the plugin exists to produce.
