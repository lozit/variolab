# Security Audit Report — `variolab-ab-testing` v0.15.0

> **v0.15.2 — no security delta vs v0.15.1.** Patch is a one-line UI tweak in `Admin::render_brand_header()`: emits `<hr class="wp-header-end">` so WP's core notice-repositioning JS (`wp-admin/js/common.js` `notices.move()`) drops third-party admin notices above the brand header instead of after the `<h1>`. No code path / option / DB touched.
>
> **v0.15.1 — no security delta vs v0.15.0.** Patch ships a single-method addition: `UrlScripts::render_for_position()` (return-string counterpart of the pre-existing `print_for_position()`), called by `templates/blank-canvas.php` to inject inline `<script>` tags at byte offsets inside imported HTML. Both methods wrap output via `wp_get_inline_script_tag()` / `wp_print_inline_script_tag()` — the WP-blessed inline-script helpers cleared in the v0.14.0 audit. No new SQL, no new input surface, no new capability check; the same trust model gate at intake in `Admin\Admin::handle_save()` (`manage_options` + nonce + `unfiltered_html`) governs every script body the new method renders. Verdict: ✅ ship.

**Date** : 2026-05-20
**Branch** : `main` @ `52b4d79` + version bump
**Auditor** : situated + OWASP grid (skill `/security-audit`)
**Previous** : [`audit-2026-05-20-v0.14.0-list-redesign.md`](./audit-2026-05-20-v0.14.0-list-redesign.md)

> v0.15.0 is the **list-page redesign + brand-shell rollout** shipped via PR #7 (squash-merged into `main` as `52b4d79`). The plugin code is bit-for-bit identical to the previous "list-redesign branch" audit (10/10, 0 findings) — this run is the pre-tag re-affirmation required by `release.yml`'s sanity check that `docs/security/latest.md` mentions the tag version.
>
> Diff vs the previous audit: only the version bump (`ABTEST_VERSION 0.14.0` → `0.15.0`) and a fresh `readme.txt` Changelog entry. Nothing under `includes/`, `assets/`, or the build pipeline changed.

---

## 📊 Summary

| Severity | Count | Delta vs v0.14.0 list-redesign |
|----------|-------|---------------------------------|
| 🔴 Critical | **0** | — |
| 🟠 High | **0** | — |
| 🟡 Medium | **0** | — |
| 🔵 Low | **0** | — |

## 🏆 Overall Score : **10 / 10**

## 🚦 Verdict

✅ **GO release — tag + push, build the .zip, upload to wp.org.** No regression vs the previous audit. The plugin code carried into v0.15.0 is the same set of changes already cleared in the branch audit:

- Inter Tight + JetBrains Mono variable fonts bundled as WOFF2 latin subset (~200 KB total, SIL OFL 1.1)
- New design-token layer (`assets/css/admin-tokens.css`) + shared brand shell (`admin-shell.css`) + list-specific stylesheet (`admin-list.css`)
- Chart.js dropped (-205 KB), replaced by ~200 LOC vanilla `list-interactions.js` (inline SVG sparklines)
- `Stats::overview_kpis()` aggregator (pure-PHP fold, no new SQL)
- `Admin::render_brand_header()` shared helper applied to List / Edit / Settings / Import (dual `.vlab-page.abtest-wrap` class preserves legacy form-table styles)
- Cream canvas replacing wp-admin gray on plugin pages only (scoped via `body.toplevel_page_abtest-experiments`)
- New `?status_filter=all|draft|running|paused|ended` query arg (back-compat with old `?show=` for one release)

Internal naming preserved (no DB / cookie / option / hook break): `Abtest\` namespace, `abtest_*` prefixes, REST `abtest/v1`, table `wp_abtest_events`.

---

## Step 0 — Automated tooling

| Tool | Status |
|---|---|
| `composer run lint` (PHPCS WPCS) | ✅ 32/32 |
| `composer run test` (PHPUnit unit) | ✅ 108 tests, 518 assertions |
| `composer audit` (composer CVE) | ✅ 0 advisories |
| `gh api repos/lozit/variolab/dependabot/alerts` | ✅ 0 open alerts |

---

## Step 2 — Situated checklist (delta-focused since baseline is unchanged)

Every surface verified by grep against the same patterns as the previous audit:

| Surface | Status |
|---|---|
| A. `admin_post_*` handlers (×8) | ✅ All cap (`manage_options`) + nonce (`check_admin_referer`) gated |
| B. REST endpoints (convert + stats) | ✅ Correct `permission_callback` per endpoint, rate-limit + dedup on public `convert` |
| C. File upload + zip extraction (HtmlImport) | ✅ Extension + MIME allowlist; `zip_entry_is_safe()` rejects path traversal / dotfiles / `__MACOSX/` / absolute paths; HTML read in memory (never written to disk) |
| D. SQL | ✅ All `$wpdb->` calls use `prepare()` or plugin-controlled interpolation with explicit `phpcs:disable` + rationale; `Stats::overview_kpis()` is a pure-PHP fold over the existing batched query |
| E. Cookies + visitor_hash | ✅ Flags httponly / samesite=Lax / secure=is_ssl, salted SHA-256, 16-char truncation, schema CHAR(16) |
| F. Outbound HTTP (GA4 + Webhook) | ✅ Explicit `sslverify: true`, anti-SSRF protocol check on webhook URLs |
| G. WP-Cron + filesystem | ✅ Watcher bounded by `wp_upload_dir()['basedir'] . HtmlImport::ASSETS_SUBDIR`, RecursiveDirectoryIterator with SKIP_DOTS |
| H. Direct file access + bootstrap | ✅ All 27+ PHP files start with `defined('ABSPATH') || exit;` (within the first 40 lines — ExperimentsList has a ~30-line docblock) |
| I. Consent gate (GDPR) | ✅ Default OFF; `abtest_visitor_has_consent` must strictly return `true`; admin/bot bypass exempt; 5 unit tests cover the gate states |
| J. Per-URL tracking scripts | ✅ Output via `wp_print_inline_script_tag()`; intake gated by `unfiltered_html` capability |
| K. Brand shell + list-page rendering | ✅ Every echo path uses `esc_html` / `esc_attr` / `esc_url`; inline `background-color:` on variant tags uses `esc_attr` on hex strings from a 12-hardcoded-string palette; `list-interactions.js` `innerHTML` is fed only by server-controlled values or `escapeXml()`'d text |

---

## Step 2bis — OWASP grid

### 🔴 Critical

| Check | Result |
|---|---|
| SQL Injection | ✅ Every `$wpdb->` call uses `prepare()` with proper placeholders OR plugin-controlled interpolation (Schema::events_table(), $wpdb->prefix, $wpdb->posts) with explicit `phpcs:disable` + rationale |
| Dynamic file inclusion | ✅ Only 2 sites: `Autoload.php:37` (rejects `..`) + `variolab-ab-testing.php:28` (plugin-controlled vendor autoload path) |
| Arbitrary code execution | ✅ 0 occurrences of `eval` / `system` / `exec` / `shell_exec` / `passthru` / `popen` / `proc_open` across `includes/` + `assets/js/` |
| Hardcoded secrets | ✅ 0 hits via `git ls-files \| xargs grep -lE '(API_KEY\|SECRET\|PASSWORD\|TOKEN\|PRIVATE_KEY\|sk_live\|AIza\|AKIA)\s*='` |

### 🟠 High

| Check | Result |
|---|---|
| XSS (unescaped output) | ✅ Verified: each echo path on the new render uses `esc_html` / `esc_attr` / `esc_url` per context; raw-output sites carry strong-rationale `phpcs:ignore` (admin notice through `wp_kses` with allowlist; tracking-script output via `wp_print_inline_script_tag`) |
| CSRF | ✅ All 8 `admin_post_*` handlers have `check_admin_referer()`; no GET-based state changes |
| Access control | ✅ All admin pages cap-gated on `manage_options`; public `convert` REST has rate-limit + dedup; `unfiltered_html` cap on url_scripts intake |

### 🟡 Medium

| Check | Result |
|---|---|
| Input sanitization | ✅ All visitor-facing `$_SERVER` reads sanitize_text_field(wp_unslash(...)); admin `$_POST` reads sanitized per field type via the form_state_from_post helper |
| File uploads | ✅ See Surface C; .js / .html / .htm intentionally NOT extracted from zips to disk |
| Sensitive info disclosure | ✅ No DB ID / path / SQL / secret leakage in any response path; brand header exposes only `ABTEST_VERSION` (already public via plugin header) |
| Headers / configuration | ✅ `Cache-Control: no-store` preserved on visitor-side responses; ABSPATH guard everywhere |

### 🔵 Low

None.

---

## Step 3 — Secrets + git hygiene

- ✅ No hardcoded credentials in tracked files (vendor / node_modules / lock files excluded from the scan).
- ✅ `.gitignore` excludes `.env`, `.env.*`, `wp-tests-config.php`, `*.local.php`, `*.key`, `*.pem`, `*.p12`, `secrets.json`, `/variolab-template/`, `/tools/*` (with `!/tools/build-fonts.sh` allow-list), `/.phpactor.json`.
- ✅ `composer.lock` shows no recent accidental credential leak.

---

## Verification of v0.15.0-specific deltas (the version bump itself)

The only working-tree changes vs the audited `feat/list-redesign-v1` branch:

1. `variolab-ab-testing.php`:
   - `Version: 0.14.0` → `0.15.0` (plugin header)
   - `ABTEST_VERSION '0.14.0'` → `'0.15.0'` (constant)
2. `readme.txt`:
   - `Stable tag: 0.14.0` → `0.15.0`
   - New `= 0.15.0 =` Changelog entry above the v0.14.0 entry
3. `docs/security/audit-2026-05-20-v0.15.0.md` (this file) + `docs/security/latest.md` (mirror)

None of these affect runtime behavior, security model, or external dependencies. Pure version metadata + documentation.

---

## Top 3 priorities

None — no Critical, High, or Medium findings. Tag v0.15.0 + push.

---

For the full audit content of earlier versions, see [`audit-2026-04-30-v0.9.3.md`](./audit-2026-04-30-v0.9.3.md) (the canonical comprehensive audit, carried forward through v0.10 → v0.14).
