<!-- generated-by: groundrules v1.5.0 (adopted) -->
# Architecture — Variolab – A/B Testing

**Living** snapshot of the current architecture. Updated as the structure evolves. For the **why** behind choices → see `docs/decisions/`.

## Overview

A standalone WordPress plugin. The bootstrap file (`variolab-ab-testing.php`) defines constants, sets up PSR-4 autoloading (Composer, with an `includes/Autoload.php` fallback), and registers activation/deactivation/uninstall hooks — **no side effects at load**. `Abtest\Plugin` wires every component on the appropriate WordPress hooks. The front-end request flow is: a visitor hits a URL → the **Router** matches it to a running experiment → assigns/reads a variant cookie → swaps the page content (or renders a Blank Canvas import) → logs an impression. A small **tracker.js** posts conversions to a REST endpoint. The wp-admin side manages experiments, settings, HTML import, and a stats dashboard.

## Stack

- WordPress 6.0+, PHP 8.1+
- Composer + PSR-4 autoload (namespace `Abtest\` → `includes/`)
- PHPUnit (unit, no WP boot) + wp-phpunit (integration, wp-env) + PHPCS (WordPress ruleset, blocking in CI)
- Vanilla JS (no build step); variable fonts bundled as WOFF2
- Local env: wp-env (Docker); CI: GitHub Actions (PHP 8.1/8.2/8.3 matrix + Plugin Check); deploy: wp.org SVN via `10up/action-wordpress-plugin-deploy`

## Components

### Bootstrap & lifecycle — `variolab-ab-testing.php`, `Abtest\Plugin`
Defines `ABTEST_VERSION` / `ABTEST_DB_VERSION`, registers hooks, runs idempotent schema migrations, sets default options on activate. `uninstall.php` preserves data by default (ADR 0004).

### Routing & rendering — `Abtest\Router`, `Abtest\Experiment`, `templates/blank-canvas.php`
`Router::maybe_route()` matches the request path to a running experiment, gates on bypass (admin/bot/consent/targeting/cache-probe), assigns a variant, and swaps content (mutating the globals so block themes work — see LEARNINGS). `Experiment` is the CPT (`abtest_experiment`) + variant/state accessors. Imported HTML renders via the Blank Canvas template with zero theme wrapper.

### Tracking — `Abtest\Tracker`, `Abtest\Cookie`, `assets/js/tracker.js`, `Abtest\Rest\ConvertController`
`Cookie` handles the 50/50 (1/N) split + the salted `visitor_hash`. `Tracker` logs impressions server-side and exposes the conversion config to `tracker.js`, which POSTs to `POST /abtest/v1/convert` (public, rate-limited, deduped, requires a prior impression).

### Stats & admin — `Abtest\Stats`, `Abtest\Admin\*`, `Abtest\Rest\StatsController`
`Stats` computes rate / lift / z-test / 95% CI via batched SQL (`raw_counts_for_experiments()`). `Admin\*` renders the branded list, edit form, settings, HTML import, help tabs. `StatsController` (`GET /abtest/v1/stats`, Application-Password auth) exposes stats to external tools.

### Caching — `Abtest\CacheBypass`, `Abtest\CacheNotice`, `assets/js/cache-check.js`
Universal `no-store` headers + per-plugin filters + host detection (ADR 0003); opt-in cache-resilient redirect; admin cache-diagnostic pills (anonymous client-side probes).

### Integrations & background — `Abtest\Integrations\{Ga4,Webhook}`, `Abtest\Scheduler`, `Abtest\Watcher`, `Abtest\Consent`, `Abtest\MultiLanguage`, `Abtest\UrlSettings`, `Abtest\UrlScripts`
GA4 Measurement Protocol + generic webhooks (opt-in, SSRF-guarded). WP-Cron scheduling + a 5-min Watcher syncing edited HTML imports. Consent gate, WPML/Polylang path normalisation, per-URL settings (noindex) and per-URL tracking scripts.

## Main flows

1. **Impression** — request → `Router::maybe_route()` → bypass checks → variant assigned (cookie) → content swapped → impression row written → `no-store` headers sent.
2. **Conversion** — `tracker.js` fires on URL/selector goal → `POST /abtest/v1/convert` → rate-limit + dedup + impression check → conversion row written → optional GA4/webhook forward.
3. **Reporting** — admin list / REST stats → `Stats` batched aggregate query over `wp_abtest_events`.

## Environments

- **Local**: wp-env (Docker), `http://localhost:8888`; integration tests via the `tests-cli` container.
- **Production**: any WordPress 6.0+ / PHP 8.1+ host; distributed via wordpress.org.

## Points of attention

- Cache correctness is the dominant operational risk (see ADR 0003 + cache-diagnostic pills).
- Direct `$wpdb` on the custom table is intentional (Plugin Check sniffs suppressed with rationale).
- Schema changes must follow strict `dbDelta` rules + idempotent versioning (`.claude/rules/db-migrations.md`).
- Block-theme rendering requires global mutation, not loop hooks (`docs/LEARNINGS.md`).
