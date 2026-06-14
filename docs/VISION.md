<!-- generated-by: groundrules v1.5.0 (adopted) -->
# Vision — Variolab – A/B Testing

> Synthesis of the project intent. Source: README.md + readme.txt + CLAUDE.md (in-repo, see `intake/INTENT.md`). Update when intent evolves (rare; tactical decisions go in `docs/decisions/`).

## Goal

A standalone WordPress plugin for **whole-page A/B testing** with **internal database tracking** and **no third-party dependency** — no data leaves the site. Visitors are split 50/50 (or 1/N for multi-variant) via a persistent cookie; once assigned, they always see the same variant. The plugin also lets you A/B test **HTML landings built outside WordPress** (AI-generated exports from Claude / v0 / Lovable / Cursor / bolt.new, hand-coded HTML, mockup extracts) by rendering them byte-perfect with zero theme wrapper ("Blank Canvas"). Results — impressions, conversions, lift, statistical significance (two-proportion z-test) — show in a branded wp-admin dashboard.

## Users / personas

- **Site owners / marketers** running conversion-rate optimisation on WordPress pages or paid-traffic landing pages, who want a privacy-respecting tool that keeps data on their own server.
- **Builders shipping AI-generated landings** who need to A/B test an exported HTML page against an existing WordPress page without rebuilding it in Gutenberg.
- **Non-statisticians** — the UI explains p-values, "no winner" reasons, and multi-variant correction in plain language (contextual help tabs).
- **Integrators** pulling stats programmatically via the REST API (n8n, Make, Pipedream, dashboards) or forwarding events to GA4 / generic webhooks.

## Constraints

- WordPress 6.0+, PHP 8.1+. Composer + PSR-4 (`Abtest\` → `includes/`). No JS build step (vanilla).
- **wp.org-published** (`variolab-ab-testing`) → must pass Plugin Check + the Plugin Review Team's guidelines (no remote code load, prefixed globals, late escaping, trademark-safe naming).
- **Security is non-negotiable**: every SQL query prepared, every output escaped, every state-changing action nonce + capability gated, every input sanitised. Internal security audit before each release (target score ≥ 8/10, currently 10/10).
- **Privacy / GDPR by design**: no raw IP / UA / email stored; `visitor_hash` is a truncated salted SHA-256 (64 bits); optional consent gate.
- **Cache-correctness**: an A/B page must never be served from a cache (freezes a variant, drops conversions) — universal `no-store` headers + per-host guidance + opt-in cache-resilient mode.
- **English only in the repo** (code, comments, UI source strings, docs); translations live as `.po` files.
- Internal names (`Abtest\` namespace, `abtest_*` prefixes, REST `abtest/v1`, table `wp_abtest_events`) stay stable across plugin renames → no breaking change for existing installs.

## Out of scope for V1 (non-goals)

- Block-level testing (single Gutenberg block) — whole-page only for now.
- WooCommerce price / product-description variants.
- Multisite network-wide schema management.
- Server-side rendering of variants behind a shared cache without a redirect (the cache-resilient mode is the current trade-off).
- Per-visitor individual data erasure (no reversible identifier is stored by design; erase via `TRUNCATE wp_abtest_events`).

## V1 acceptance criteria

- Create an experiment targeting a URL, split visitors 50/50 via a persistent cookie, and serve the assigned variant consistently (themed pages and imported Blank Canvas pages).
- Log impressions + conversions to `wp_abtest_events`; show conversion rate, lift, 95% CI, and significance in the admin dashboard.
- Conversions require a server-side impression (no forgery); public endpoint rate-limited + deduped.
- A/B pages emit `no-store` headers; cache-diagnostic pills confirm bypass; opt-in cache-resilient mode for un-editable caches.
- Logged-in editors and bots are bypassed; consent gate available.
- Passes PHPCS (WordPress ruleset, blocking), unit + integration suites, and Plugin Check; published on wp.org.

---

Further reading:
- `intake/` — raw upstream notes
- `docs/decisions/` — structural decisions
- `docs/LEARNINGS.md` — non-trivial learnings
- `docs/ARCHITECTURE.md` — architecture snapshot
