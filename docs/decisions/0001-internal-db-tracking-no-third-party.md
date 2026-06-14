<!-- generated-by: groundrules v1.5.0 (adopted) -->
# 0001 — Internal DB tracking, no third-party dependency

**Date**: 2026-04-28
**Status**: Accepted

## Context

The plugin needs to record A/B-test impressions and conversions and compute statistics. The market norm (VWO, Optimizely, Google Optimize-style) routes events to an external SaaS. For a privacy-respecting, self-hosted WordPress audience — and to avoid GDPR friction and per-seat pricing — sending visitor data off-site is undesirable.

## Decision

Store all events in a **custom database table** (`{$wpdb->prefix}abtest_events`) on the site's own database. No external service is required for the core product to work. Variant assignment is a **persistent cookie** (50/50, or 1/N for multi-variant). GA4 / webhook forwarding exist only as **opt-in** integrations.

## Alternatives considered

- **External analytics SaaS**: rejected — data leaves the site (privacy/GDPR), adds a dependency and a cost, and contradicts the "no third-party dependency" pitch.
- **WordPress post meta / options for events**: rejected — unbounded growth, no indexing, poor query performance for aggregates.

## Consequences

### Positive
- No data leaves the site; GDPR story is simple (no raw IP/UA/email; truncated salted `visitor_hash`).
- Full control of the schema + fast indexed aggregate queries (`Stats::raw_counts_for_experiments()`).

### Negative / Tradeoffs
- We own schema migrations (`dbDelta`, idempotent versioning) and uninstall semantics.
- Direct `$wpdb` queries on a custom table trip some Plugin Check sniffs (accepted with rationale; wp.org-blessed pattern).
- No cross-site / cross-device tracking (by design).

### Neutral
- Reporting lives in a custom wp-admin dashboard rather than a third-party UI.

## Notes

Schema rules in `.claude/rules/db-migrations.md`. Privacy details in `readme.txt` (== Privacy ==).
