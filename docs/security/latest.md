# Security Audit Report — `variolab-ab-testing` v0.15.7

**Date** : 2026-06-12
**Branch** : `main` @ `0933223`
**Auditor** : situated (9 plugin surfaces) + OWASP grid, 3-agent independent re-verification (skill `/security-audit`)
**Previous** : [`audit-2026-06-12-v0.15.2.md`](./audit-2026-06-12-v0.15.2.md)

> Re-audit after the v0.15.2 → v0.15.7 remediation cycle. The v0.15.2 audit found 2 🟡 Medium + 9 🔵 Low; both Mediums and 5 of the Lows have since been fixed across v0.15.3–v0.15.7. This run independently re-verifies every fix (does it actually close the hole, did it introduce a new one?) and re-walks the surfaces those fixes touched, plus a fresh cross-cutting SQL / bootstrap / dangerous-function / escaping sweep. The unchanged surfaces inherit the v0.15.2 clean verdicts.

---

## Step 0 — automated tooling

| Tool | Result |
|------|--------|
| `composer run lint` (PHPCS WP ruleset) | ✅ clean, 32 files |
| `composer run test` (PHPUnit unit) | ✅ 108 tests, 518 assertions |
| `composer run test:integration` (wp-env) | ✅ 36 tests, 77 assertions |
| `composer audit` (composer CVEs) | ✅ no advisories |
| Dependabot open alerts | ✅ none |

No tooling regression. Integration coverage grew from 15 → 36 tests over the remediation cycle (forged-conversion gate, webhook SSRF intake, HTML-import trust render).

---

## 📊 Summary

| Severity | Count | Delta vs v0.15.2 |
|----------|-------|------------------|
| 🔴 Critical | **0** | — |
| 🟠 High | **0** | — |
| 🟡 Medium | **0** | −2 (M1, M2 fixed) |
| 🔵 Low | **4** | −5 (4 fixed; remaining are deferred-with-reason / informational / mitigated) |

## 🏆 Overall Score : **10 / 10**

Both Medium findings that capped the previous audit at 8/10 are closed and independently verified. Zero Critical/High/Medium. **v0.15.8 closes out the 4 residual Lows**: E1 (auth-key-salt coupling) is fixed via a dedicated salt seeded from `wp_salt('auth')`; G1, B1, and D are formally accepted-by-design with the rationale now recorded in code (their naive "fixes" each cause a worse regression — broken asset URLs, more tracking surface, truncated aggregates respectively).

## 🚦 Verdict

✅ **GO release.** No Critical/High/Medium. Nothing to fix before shipping.

> **v0.15.9 — no security delta vs v0.15.8.** Injects the conversion tracker into Blank Canvas (imported-HTML) pages, which previously never loaded it, plus an admin-only preview mode. The tracker config is emitted via `wp_get_inline_script_tag()` + `wp_json_encode()` (no injection; `goalValue` is JSON-encoded), the page already sends `nocache_headers()` so the per-request nonce isn't cached, and the conversion endpoint keeps its M1 impression gate + rate-limit. Preview mode runs only for logged-in `edit_posts` users and never POSTs. No new input surface / capability / data-flow.

---

## ✅ Remediation re-verification (independently confirmed)

**[was 🟡 Medium] M1 — forged conversions via attacker-set cookie → FIXED (v0.15.3), verified sound**
- `ConvertController::handle()` gates `log_conversion` on `Tracker::has_impression( $experiment_id, $variant, $visitor )`, returning `409 no_impression` otherwise. `has_impression()` runs the prepared `COUNT(*) … WHERE experiment_id=%d AND variant=%s AND event_type='impression' AND visitor_hash=%s` — variant **is** matched, so flipping the cookie variant to skew a specific arm is rejected.
- **No bypass**: `log_impression()` has exactly one call site (`Router.php:211`), fired server-side only for tracked visitors. No REST/admin-ajax/public path inserts an impression row, so a forged cookie for a guessed `experiment_id` has no matching impression and is turned away. Forged conversions can't outrun impressions → the rate stays honest. Covered by `tests/Integration/TrackerConversionTest.php`.

**[was 🟡 Medium] M2 — webhook SSRF → FIXED (v0.15.4), verified sound**
- `Webhook::set_all()` → `host_is_blocked()` rejects literal loopback/link-local/private/reserved IP hosts (`FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`, IPv6 `[::1]` brackets stripped) and correctly lets public hostnames through to the runtime guard. `Webhook::send()` passes `'reject_unsafe_urls' => true` on every path (event delivery + "Send test event"), so WP's `wp_http_validate_url()` resolves the host and blocks private targets, DNS-rebinding, redirect bypasses, **and** the non-canonical IP encodings (decimal `2130706433`, octal/hex) that the intake literal-IP check intentionally doesn't see. Covered by `tests/Integration/WebhookTest.php` (14 cases).

**[was 🔵 Low-C] HTML-import raw render relied on implicit kses → FIXED (v0.15.7), verified sound**
- Import records `_abtest_raw_trusted = current_user_can('unfiltered_html')`; `HtmlImport::render_html()` renders raw only when trusted, else `wp_kses_post()`. `blank-canvas.php` is the **sole** raw-render path and calls `render_html()` — grep confirmed no other `post_content`/`the_content` echo bypasses it. Flag-absent (legacy single-site imports by `unfiltered_html` admins, or `Watcher` filesystem pages) is treated as trusted; no low-trust user can produce a no-flag page that renders raw. Covered by `tests/Integration/HtmlImportRenderTest.php`.

**[was 🔵 Low-A] `target_page_id` arbitrary-post overwrite → FIXED (v0.15.6), verified**
- `replace_existing()` returns `invalid_target` unless `'page' === $existing->post_type`, before `wp_update_post`.

**[was 🔵 Low-G2] `RecursiveDirectoryIterator` symlink-follow → FIXED (v0.15.6), verified**
- `Watcher::scan()` requires `str_starts_with( realpath($index), trailingslashit( realpath($base_dir) ) )` and rejects when either `realpath` is `false`. The trailing slash closes the sibling-prefix off-by-one; a symlinked file/dir resolving outside the watch root is rejected before `file_get_contents`.

**[was 🔵 Low-F] receiver-side `hash_equals()` undocumented → FIXED (v0.15.6), verified**
- Webhook Secret help text now recommends a constant-time comparison, never `==`.

**[was 🔵 Low-H] `wp-tests-config.php` tracked despite `.gitignore` → FIXED (v0.15.6), verified**
- Misleading `.gitignore` line removed; file intentionally tracked (integration bootstrap needs it; only wp-env public defaults inside), with a comment.

**Also re-verified (non-finding deltas):** the v0.15.1 `UrlScripts::render_for_position()` fatal fix routes through `wp_get_inline_script_tag()` (no raw echo; intake gated on `unfiltered_html`); the v0.15.5 i18n fix removed the only pre-`init` translation call (no `__()` remains in `register_interval()` or any `cron_schedules`-time path).

---

## 🔵 Residual Low findings (all non-blocking)

**[🔵 Low] `visitor_hash` salt couples dedup continuity to auth-key rotation** — ✅ FIXED in v0.15.8
- `includes/Cookie.php` — Surface E. Introduced a dedicated `abtest_hash_salt` option, **seeded once from `wp_salt('auth')`** on first use (`Cookie::hash_salt()`). Existing hashes keep matching (no one-time dedup reset — the deferral blocker), and a later `AUTH_KEY`/`AUTH_SALT` rotation no longer disturbs dedup. Deleted on uninstall. Covered by 2 new unit tests (seed-from-auth-salt + decoupled-from-rotation).

**[🔵 Low] Watcher slug taken raw from on-disk folder name** — ✅ accepted by design (documented in code, v0.15.8)
- `includes/Watcher.php` — Surface G. `basename()` (no traversal); store + lookup use the same raw basename via `META_SLUG` (no desync), and `wp_insert_post()` sanitises `post_name` internally. Sanitising the slug ourselves would **break asset URLs** (the asset base URL points at the real on-disk folder). Not a security issue — left as-is with a code comment recording the rationale.

**[🔵 Low] Conversion dedup hash is coarse IP+UA** — ✅ accepted by design (mitigated by M1; documented in code, v0.15.8)
- `includes/Cookie.php` — Surface B. IP+UA granularity is intentional (GDPR-minimal). Post-M1 a conversion also requires a matching server-side impression, so the hash is no longer the sole dedup gate; strengthening the fingerprint would only add tracking surface for no real gain. Rationale now in the `visitor_hash()` docblock.

**[🔵 Low] Unbounded aggregate SELECTs (no `LIMIT`)** — ✅ accepted by design (documented in code, v0.15.8)
- `includes/Stats.php`; `includes/Admin/CsvExport.php` — Surface D. `GROUP BY` keeps the result set bounded by (#days × #variants × #event_types); a `LIMIT` would risk truncating valid aggregates. Not a DoS vector. Rationale now in a code comment at the query.

---

## ℹ️ Informational (not a finding, no severity)

**M1 fix makes `visitor_hash` a hard gate on conversions.** A legitimate visitor whose IP or User-Agent changes between the server-side impression (page render) and the later conversion POST — mobile Wi-Fi↔cellular handoff, CGNAT reassignment, a browser UA update — now hashes to a different visitor and is rejected `409 no_impression`, silently under-counting that conversion. This fails *closed* (biases stats downward, never inflates) and tracker.js ignores the response, so it is not a security issue; the common same-session case is unaffected (CacheBypass guarantees the impression renders fresh). Worth a one-line code comment at `ConvertController::handle()` documenting the trade-off; no behavioural change recommended.

---

## ✅ Surfaces verified clean (this run)

- **B / REST**: `/convert` public-by-design with rate-limit + dedup + impression gate; `/stats` requires `manage_options`; responses leak no `visitor_hash`/IP/`wp_salt`/secrets.
- **C / upload**: extension allowlist → `wp_check_filetype_and_ext()` → `is_uploaded_file()` → size cap; zip rejects `..`/absolute/dotfile/`__MACOSX`/code-bearing entries; `wp_slash` on content; preview iframe `sandbox="allow-scripts"` without `allow-same-origin`.
- **D / SQL**: all 11 `$wpdb` call sites prepared / typed-format `insert` / int-only `IN()` / plugin-controlled table names. No user value reaches a query string.
- **F / outbound HTTP**: `sslverify => true` + `reject_unsafe_urls => true` + intake host block; fire-and-forget; GA4 endpoint hardcoded, secret `rawurlencode`'d, never logged.
- **G / cron+fs**: Watcher path-bounded to `uploads/abtest-templates/` with realpath containment; SHA-256 dedup; no external input; no arbitrary-post overwrite.
- **H / bootstrap**: every production PHP file guards `defined('ABSPATH') || exit;`; `uninstall.php` guards `WP_UNINSTALL_PLUGIN`; `Autoload::load` refuses `..`; no `eval`/`system`/`exec`/`shell_exec`/`proc_open`/`popen`/`unserialize`-of-user-input / variable-include anywhere.
- **I / consent**: fails closed (ON + no consent ⇒ no cookie, no impression, no conversion script); default OFF preserved; admin/bot bypass exempt.
- **Secrets / git hygiene**: no hardcoded secrets (`git ls-files | grep -E '(API_KEY|SECRET|PASSWORD|TOKEN|PRIVATE_KEY|sk_live|AKIA|AIza)='` clean); GA4/webhook secrets via `get_option`; `.gitignore` covers `.env*`/`*.key`/`*.pem`/`*.p12`.

---

## 🎯 Top 3 priorities

Nothing blocking. In rough order of (low) value:
1. **[Informational]** Add a code comment at `ConvertController::handle()` noting the IP/UA-change conversion under-count trade-off introduced by the M1 gate.
2. **[Low, deferred]** `visitor_hash` dedicated salt — only worth it bundled with a migration that avoids the one-time dedup reset.
3. **[Low, informational]** Revisit `Stats`/`CsvExport` query cost only if `abtest_events` grows to millions of rows (add a rollup table); no action needed today.

---

## Verdict

**Score 10/10 — GO.** Both Medium findings from the v0.15.2 audit are fixed and independently re-verified; the remediation introduced no new issue across the changed surfaces; the fresh SQL / bootstrap / dangerous-function / escaping sweep is clean. The 4 residual Lows are deferred-with-reason, mitigated, or informational — none blocks a release.
