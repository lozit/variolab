# Variolab – A/B Testing Plugin

Standalone WordPress plugin for whole-page A/B testing : internal DB tracking, persistent-cookie 50/50 split, no third-party dependency.

## Stack
- WordPress 6.x, PHP 8.1+
- Composer + PSR-4 autoload (namespace `Abtest\`)
- PHPUnit (WP test suite) + PHPCS (WordPress ruleset, blocking in CI)
- No JS build step (vanilla)

## Essential commands
- `composer install` — install deps
- `composer run test` (or `./vendor/bin/phpunit`) — run unit tests
- `composer run test:integration` — run integration tests (requires wp-env)
- `composer run lint` (or `./vendor/bin/phpcs`) — lint, BLOCKING in CI
- `composer run lint:fix` (or `./vendor/bin/phpcbf`) — auto-fix
- Local env : **wp-env** (official WordPress, Docker)
  - `npx wp-env start` — boot the local WP (port 8888 by default)
  - `npx wp-env stop` — stop
  - `npx wp-env clean all` — full reset (DB + uploads)
  - `npx wp-env run cli wp <cmd>` — wp-cli inside the container
  - `npx wp-env run tests-cli wp <cmd>` — wp-cli on the tests instance
  - Config in `.wp-env.json`

## Structure
```
variolab-ab-testing.php    # bootstrap (plugin header + autoload)
includes/                  # plugin classes (PSR-4)
includes/Admin/            # wp-admin UI
assets/js/                 # tracker.js frontend
tests/Unit/                # PHPUnit unit tests (no WP boot)
tests/Integration/         # PHPUnit integration tests (wp-phpunit)
docs/security/             # archived security audit reports + latest.md
tools/                     # one-off helper scripts (excluded from .zip release)
```

## Lazy-loaded rules
- PHP touching the DB or user input → **read** `@./.claude/rules/wp-security.md`
- New hook / new name / i18n → **read** `@./.claude/rules/wp-conventions.md`
- DB schema change / activation hook → **read** `@./.claude/rules/db-migrations.md`

## Workflow
1. **Plan mode by default** for any non-trivial task (3+ steps or architectural decision).
2. Subagents for parallel exploration, keep the main context clean.
3. After a user correction → update `docs/LEARNINGS.md`.
4. Mark a task complete ONLY after proof it works (green tests + demo).
5. Simplicity first — no abstraction "just in case". Three similar lines beat a bad abstraction.

## Tracking
- `PLAN.md` — active todo + shipped history (was `tasks/todo.md`)
- `docs/LEARNINGS.md` — lessons learned from user corrections (was `tasks/lessons.md`)

### Project docs (groundrules)
- Vision: `docs/VISION.md` · Decisions: `docs/decisions/` · Learnings: `docs/LEARNINGS.md` · Active plan: `PLAN.md` · Roadmap: `docs/ROADMAP.md`
- Architecture: `docs/ARCHITECTURE.md` · Data model: `docs/DATA_MODEL.md` · Glossary: `docs/GLOSSARY.md` · Process: `docs/PROCESS.md` · Release runbook: `RELEASE.md`
- Capture at checkpoints: decided → an ADR (`docs/decisions/`), learned/blocked → `docs/LEARNINGS.md`, agent drift → `docs/AGENT-EVALS.md`. The repo is the only memory.

## Language policy
**Every file committed to this repo MUST be in English** : code, comments, docblocks, UI strings (the source string passed to `__()`/`_e()`), `.md` docs, `readme.txt`, audit reports, slash commands, commit messages, tag annotations. The only exception is the live chat conversation between Claude and the user. French translations of UI strings can later live as `.po` files under `languages/`.

<important if="touching DB writes, $_POST/$_GET, REST endpoints, or admin forms">
NON-NEGOTIABLE SECURITY :
- Every custom SQL query goes through `$wpdb->prepare()` — never direct interpolation.
- Every HTML output is escaped (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`).
- Every state-changing action verifies a nonce (`check_admin_referer`, `wp_verify_nonce`) AND a capability (`current_user_can`).
- Every input is sanitized (`sanitize_text_field`, `absint`, `sanitize_key`, etc.).
See `@./.claude/rules/wp-security.md` for the detailed patterns.
</important>
