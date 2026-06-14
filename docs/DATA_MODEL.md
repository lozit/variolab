<!-- generated-by: groundrules v1.5.0 (adopted) -->
# Data Model — Variolab – A/B Testing

**Living** description of the data model. Update it whenever the schema changes. For the **why** behind choices → see `docs/decisions/` (ADR 0001 internal DB tracking).

## Overview

One **custom table** (`wp_abtest_events`) holds every impression and conversion. Experiments are a **custom post type** (`abtest_experiment`) with post meta for variants, goal, state, schedule, and trust flags. Configuration lives in a handful of **options**. No raw PII is stored — visitors are identified only by a truncated salted hash.

## Entities

### Event — `{$wpdb->prefix}abtest_events` (custom table)

| Field | Type | Constraints | Description |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | PK, AUTO_INCREMENT | Row id |
| `experiment_id` | `BIGINT UNSIGNED` | NOT NULL | The experiment CPT post id |
| `variant` | `CHAR(1)` | NOT NULL | `a` / `b` / `c` / `d` |
| `event_type` | `VARCHAR(20)` | NOT NULL | `impression` or `conversion` |
| `test_url` | `VARCHAR` | indexed | URL path under test (e.g. `/promo/`) |
| `visitor_hash` | `CHAR(16)` | NOT NULL | first 16 hex chars (64 bits) of `sha256(IP + UA + abtest_hash_salt)` |
| `created_at` | `DATETIME` | DEFAULT CURRENT_TIMESTAMP | Event timestamp |

Keys: PK `(id)`, plus composite indexes on `(experiment_id, variant, event_type)` and `(visitor_hash, experiment_id)` for dedup + aggregate queries.

### Experiment — CPT `abtest_experiment` (post + meta)

| Meta key | Description |
|---|---|
| `_abtest_test_url` | URL path the experiment targets |
| `_abtest_variants` | variant → page-id map (A/B/C/D) |
| `_abtest_state` | DRAFT / RUNNING / PAUSED / ENDED |
| `_abtest_goal` | conversion goal (URL visited or CSS selector clicked) |
| `_abtest_schedule_start_at` / `_abtest_schedule_end_at` | WP-Cron auto start/end |
| `_abtest_raw_trusted` | whether the importer had `unfiltered_html` (HTML import trust boundary) |
| `_abtest_watcher_slug` | links a page to its watched HTML-import folder |

> Post status is forced to `private` while running (served via Router; see LEARNINGS). Legacy CPT slug `ab_experiment` migrated → `abtest_experiment` (DB v1.4.0).

### Options

| Option | Description |
|---|---|
| `abtest_settings` | main config array: `cookie_days`, `bypass_admins`, `bypass_bots`, `require_consent`, `cache_resilient`, `cache_check_mode`, `delete_data_on_uninstall` |
| `abtest_db_version` | schema version for idempotent migrations |
| `abtest_hash_salt` | dedicated dedup salt (seeded once from `wp_salt('auth')`, decoupled so key rotation doesn't reset dedup) |
| `abtest_url_settings` | per-URL flags (e.g. noindex) keyed by URL path |
| `abtest_url_scripts` | per-URL tracking scripts |

## Relationships

- `Experiment` 1—N `Event` (via `experiment_id`).
- A `test_url` may be shared by several experiments (per-URL settings/scripts shared across them).

## Privacy

No raw IP / User-Agent / email / name / cross-site identifier is stored. `visitor_hash` is non-reversible, single-site, salt-rotated, dedup-safe (64-bit truncation: collision < 3e-8 at 1M visitors/experiment). Cookies: httponly, samesite=Lax, secure on HTTPS, value = a single letter, 30-day TTL. Erasure: `TRUNCATE wp_abtest_events`.

## Indexes and performance

- `(experiment_id, variant, event_type)` — aggregate counts per arm.
- `(visitor_hash, experiment_id)` — impression lookup + conversion dedup.
- Stats use one batched query for N experiments (`Stats::raw_counts_for_experiments()`, REST N+1 → 1).

## Migrations

Schema lives in `Abtest\Schema` / migration helpers in `Abtest\Plugin`. Strict `dbDelta` rules + idempotent version gating — see `.claude/rules/db-migrations.md`. History: v1.0.0 (initial) → v1.1.0 (`test_url`) → v1.2.0 (multi-variant backfill) → v1.3.0 (16-char hash) → v1.4.0 (CPT rename).
