---
description: Full security audit — situated (plugin surfaces) + OWASP grid + persisted report + auto-updated todo
---

Run a complete security audit **specific to this plugin**. Trigger before a release tag, after a feature touching DB / REST / upload, after merging a sensitive PR, or on a periodic cadence.

The audit produces:
- a **persisted report** in `docs/security/audit-YYYY-MM-DD-vX.Y.Z.md` (archive) + `docs/security/latest.md` (overwrite),
- an **automatic update** of `PLAN.md` (section "Security — audit backlog"),
- a **chat summary** with score 1–10, Go/No-Go verdict, and top 3 priorities.

---

## Step 0 — automated tooling first

Run in this order. If any fails, fix before moving on to the manual audit (the WP PHPCS standard already surfaces missing escaping, missing nonce, missing sanitization — don't duplicate that work by hand).

- `composer run lint` — PHPCS WordPress ruleset.
- `composer run test` then `composer run test:integration` — cover regressions on consent gate, dedup, state machine, batch stats.
- `composer audit` — CVEs on composer dependencies.
- `gh api repos/lozit/variolab/dependabot/alerts --jq '.[] | select(.state == "open") | {state, severity, package: .security_advisory.summary}'` — open Dependabot alerts (ignore "fixed" / "dismissed"). Skip if Dependabot is disabled on the repo.

If everything passes: continue. If something breaks: report the tooling findings in the report first, fix, re-run, then move on.

---

## Step 1 — reference rules

Load and apply (don't duplicate):

- `@./.claude/rules/wp-security.md` — SQL prepare, escape output, sanitize input, nonces + capabilities, REST endpoints, cookies, asset loading.
- `@./.claude/rules/wp-conventions.md` — `abtest_*` prefixes, defensive boot `defined('ABSPATH') || exit;`, activation/deactivation hooks.
- `@./.claude/rules/db-migrations.md` — strict dbDelta format, idempotent versioning, uninstall.

---

## Step 2 — checklist by plugin surface (situated)

For each surface: inspect the listed files, verify each point, cite the line in the report.

### A. `admin_post_*` handlers
Files: `includes/Admin/Admin.php`, `includes/Admin/HtmlImport.php`, `includes/Admin/Settings.php`. Hook list in `Admin::register()`.

- Does each handler call `current_user_can('manage_options')` AND `check_admin_referer(<nonce>, <field>)` BEFORE any mutation?
- Are `$_POST` / `$_GET` inputs all passed through `wp_unslash()` then sanitized (`sanitize_text_field`, `absint`, `sanitize_key`, `esc_url_raw`, `wp_kses_post`)?
- No `wp_die()` that leaks paths / SQL in debug mode?
- Redirects via `wp_safe_redirect()` (not `wp_redirect`) with validated arg?

### B. REST endpoints
Files: `includes/Rest/ConvertController.php`, `includes/Rest/StatsController.php`.

- Is `permission_callback` defined AND coherent: `__return_true` for `convert` (intentional — public tracking); `current_user_can('manage_options')` for `stats`?
- `args` schema with `validate_callback` + `sanitize_callback` on each parameter?
- Response doesn't leak: `wp_salt`, webhook secrets, App Passwords, raw IP, another visitor's hash?
- Public `convert` endpoint: does dedup by `visitor_hash` prevent flood? Rate-limit considered for high-traffic sites?

### C. File upload + zip extraction
File: `includes/Admin/HtmlImport.php`.

- Extension allowlist (`.html` / `.htm` / `.zip`) applied BEFORE reading?
- MIME check via `wp_check_filetype_and_ext()` (not just the filename extension)?
- `is_uploaded_file()` checked on `tmp_name`?
- Size bounded by `max_bytes()` AND capped by `wp_max_upload_size()`?
- Zip extraction: `../`, absolute paths, dotfiles, `__MACOSX/` rejected?
- Allowlist of zip-entry extensions (html/css/js/images/fonts) applied?
- Extraction slug passed through `sanitize_title()`?
- HTML stored via `wp_slash()` (otherwise `wp_insert_post()` eats one level of backslashes)?
- Iframe preview: `sandbox="allow-scripts"` (or stricter), no `allow-same-origin`?

### D. SQL
Files: `includes/Stats.php`, `includes/Tracker.php`, `includes/Experiment.php`, `includes/Schema.php`, and migrations in `includes/Plugin.php`.

- `grep -n '$wpdb->' includes/` then for each hit: is `$wpdb->prepare()` used with placeholders (`%d`, `%s`, `%f`)?
- Are `IN (?)` clauses built dynamically (`implode(',', array_fill(...))`) fed only by `(int)` casts?
- DB migrations (`Plugin::migrate_to_*`, `Plugin::pre_install_truncate_visitor_hash`) idempotent (re-runnable without corruption)?
- No direct `{$user_input}` interpolation in an SQL string?

### E. Cookies + hashing
File: `includes/Cookie.php`.

- Cookie flags: `httponly=true`, `samesite=Lax`, `secure=is_ssl()`, TTL ≤ 30 days?
- `visitor_hash` always salted with `wp_salt('auth')`, never raw IP / UA stored?
- Length truncated to `Cookie::HASH_LENGTH` (16 chars / 64 bits) at runtime AND in schema (`CHAR(16)`)?
- Webhook HMAC (`includes/Integrations/Webhook.php`): does the receiver-side documentation recommend `hash_equals()` (timing-safe comparison)?

### F. Outbound HTTP
Files: `includes/Integrations/Webhook.php`, `includes/Integrations/Ga4.php`.

- `wp_remote_post()` with a reasonable `timeout` (5–10 s) — don't block the user page?
- Explicit `'sslverify' => true` (don't trust the WP default — a third-party filter could disable it)?
- Secrets (GA4 API key, webhook HMAC secret) never logged via `error_log` / `WP_DEBUG_LOG` / REST responses?
- User-supplied webhook URLs validated with `esc_url_raw()` + protocol check `http(s)://` (basic anti-SSRF: refuse `file://`, `gopher://`, private IPs if you want to be paranoid)?
- `Webhook::send()` uses `blocking=false` by default (except "Send test event") to avoid stalling the request?

### G. WP-Cron + filesystem
Files: `includes/Scheduler.php`, `includes/Watcher.php`.

- Does the Watcher scan only under `wp_upload_dir()['basedir'] . '/abtest-templates/'` — no path traversal via slug?
- Is `RecursiveDirectoryIterator` bounded by that root (no uncontrolled symlink follow)?
- SHA-256 hashes used for dedup (not MD5 / SHA-1)?
- Cron handlers `tick()` / `scan()` trust no external input — everything comes from the DB or filesystem we control?

### H. Direct file access + bootstrap
Files: `includes/*.php`, `variolab-ab-testing.php`.

- Do all PHP files start with `defined('ABSPATH') || exit;`?
- No side-effects at require in the main file (only hook registrations)?
- `register_activation_hook` and `register_deactivation_hook` correctly declared and idempotent?

### I. Consent gate (GDPR)
Files: `includes/Consent.php`, `includes/Router.php` (around `should_bypass`).

- When the `require_consent` setting is ON AND the `abtest_visitor_has_consent` filter returns `null` → really **no** cookie set, **no** impression logged, **no** conversion script enqueued?
- Admin / bot bypass exempt from consent (otherwise previews break)?
- Default OFF preserved? (no silent breaking change for sites without a banner)

---

## Step 2bis — OWASP grid (cross-cutting)

Complement the situated inspection with an attacker-oriented pass. For each category, grep + read, then classify each real finding by the standard severity below.

### 🔴 Critical
- **SQL Injections**: `$wpdb->query` / `get_results` / `get_row` / `get_var` containing a variable without `prepare()`, or `prepare()` with `%s` around an int.
- **Dynamic File Inclusion**: `include` / `require` with a variable from `$_GET` / `$_POST` / `$_SERVER`. Also check that autoloaders refuse `..` in class names.
- **Arbitrary Code Execution**: `eval`, `system`, `exec`, `shell_exec`, `passthru`, `popen`, `proc_open`, `assert`, `call_user_func($_GET[...])`.
- **Hardcoded secrets in repo**: `git ls-files | xargs grep -lEn '(API_KEY|SECRET|PASSWORD|TOKEN|PRIVATE_KEY|sk_|pk_|AIza|AKIA)\s*='`. Vendor key patterns (Stripe, AWS, Google, Slack).

### 🟠 High
- **XSS**: any `echo` / `print` / `_e` / `printf` with a variable not escaped according to context (`esc_html`, `esc_attr`, `esc_url`, `esc_js`, `wp_kses_post`).
- **CSRF**: forms without `wp_nonce_field()` + `check_admin_referer()`; AJAX without `wp_verify_nonce()`; state-changing actions via GET without nonce.
- **Access Control**: admin menus without `current_user_can()`; `wp_ajax_nopriv_*` running sensitive ops; REST without `permission_callback` or with `__return_true` on a sensitive action; PHP file without `defined('ABSPATH') || exit;`.

### 🟡 Medium
- **Input Sanitization**: `$_GET` / `$_POST` written to DB without `sanitize_text_field` / `sanitize_email` / `absint` / `esc_url_raw` / `wp_kses_post`; `update_option` / `update_post_meta` without sanitize.
- **File Uploads**: no real MIME check (`wp_check_filetype_and_ext`), public destination without a PHP-blocking `.htaccess`, unbounded size.
- **Sensitive Information Disclosure**: error messages leaking paths / SQL / PHP version, `debug.log` files in the plugin, secrets in REST responses.
- **Headers / Configuration**: missing `defined('ABSPATH') || exit;` (already in High but worth re-checking), missing no-store headers on cacheable pages.

### 🔵 Low
- Nonces with too-broad scope or too-long TTL, SQL queries without `LIMIT` that can return 100k rows, missing rate-limit on public endpoints, transients storing PII.

---

## Step 3 — secrets + git hygiene

- `git ls-files | xargs grep -lEn '(API_KEY|SECRET|PASSWORD|TOKEN|PRIVATE_KEY)\s*=' 2>/dev/null` — inspect each hit (real usages must come from `get_option()`, never hardcoded).
- Verify that `.env`, `wp-tests-config.php` (if it contains creds), `*.local.php`, `*.key`, `*.pem` are properly in `.gitignore`.
- `git log --oneline -p -- composer.json composer.lock | head -200` — no recent accidental leak in the lockfile.

---

## Step 4 — compose the report

Format for each finding (from the OWASP grid):

```markdown
**[SEVERITY] Issue Title**
- File: `relative/path/to/file.php`
- Line: NN
- Surface: <A-I letter + name>
- Problematic code: `[code excerpt 1-3 lines]`
- Risk: [concrete description of the risk]
- Fix: [corrected code or recommended approach]
```

Conclude with:
1. **Severity counter** (4-row table)
2. **Top 3 priorities** to fix first
3. **Overall score 1–10** (10 = flawless, 8+ = production-ready, < 5 = don't release)
4. **Verdict**: Go release / Don't release until fix (based on the presence of Critical or High)

---

## Step 5 — persist the report on disk

Read the current version:
```bash
grep "ABTEST_VERSION" variolab-ab-testing.php | grep -oE "[0-9]+\.[0-9]+\.[0-9]+"
```

Write the report into **TWO** files:

1. `docs/security/audit-YYYY-MM-DD-vX.Y.Z.md` — immutable archive. If the file already exists (audit on the same day for the same version), suffix with `-2`, `-3`, etc.
2. `docs/security/latest.md` — identical copy, overwritten. This is the file linked from `README.md` and `SECURITY.md`.

Create the `docs/security/` directory if it doesn't exist. The directory is excluded from the distributed `.zip` via `--exclude='docs'` in `release.yml`.

---

## Step 6 — sync `PLAN.md`

Locate (or create if missing) the `### Security — audit backlog (auto-managed)` section in `PLAN.md`.

For each **Critical / High / Medium** finding in the new report:
- If it doesn't already exist in the section (match by `file:line` + severity) → append in the format:
  ```
  - [ ] [SEV] Surface — `file.php:NN` — recommended action (audit YYYY-MM-DD)
  ```
- If it already exists → do nothing (no duplication).

For each **unticked** item already present that no longer appears in the new report (= fixed between 2 audits) → tick `[x]` and append `(fixed YYYY-MM-DD)`.

**Low** findings stay in the report but **don't pollute** `PLAN.md`.

---

## Step 7 — chat summary

Print:
- Overall score X/10 + Go/No-Go verdict.
- Counter by severity.
- Top 3 priorities with their file:line.
- Link to the persisted report (`docs/security/latest.md`).
- Diff of `PLAN.md` (how many items added, how many ticked as fixed).

---

No security warning should be ignored without a written justification in the report. When in doubt → be conservative (refuse / sanitize / capability).
