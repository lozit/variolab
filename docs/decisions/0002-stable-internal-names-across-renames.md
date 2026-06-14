<!-- generated-by: groundrules v1.5.0 (adopted) -->
# 0002 — Keep internal names stable across plugin renames

**Date**: 2026-05-16
**Status**: Accepted

## Context

The plugin was renamed twice for wp.org compliance: `ab-testing-wordpress` → `uplift-ab-testing` (v0.12.0, "WordPress" forbidden in name/slug) → `variolab` (v0.13.0, trademark). Each rename touches the display name, slug, text domain, main file, package names, CI build paths. The risk: a rename that also changes **internal** identifiers (DB table, option keys, cookies, hooks, REST namespace, PHP namespace) would break **every existing install** (lost data, orphaned options, broken integrations).

## Decision

The user-facing identity (name, slug, text domain) may change, but the **internal naming is frozen**: PHP namespace `Abtest\`, prefixes `abtest_*` (hooks / cookies / options / meta), REST namespace `abtest/v1`, and custom table `wp_abtest_events` never change across renames.

## Alternatives considered

- **Rename internals to match each new brand** (e.g. `variolab_*`): rejected — requires data migration on every brand change, with high risk of silent data loss and broken third-party integrations against the REST namespace / hooks.

## Consequences

### Positive
- Renames are safe, mechanical, and zero-breaking-change for existing installs.
- Third-party code hooking `abtest_*` / calling `abtest/v1` keeps working forever.

### Negative / Tradeoffs
- Internal names (`Abtest`, `abtest`) no longer match the brand ("Variolab") — a minor source-readability quirk, documented here and in CLAUDE.md.

### Neutral
- The CPT slug is an exception: it was migrated `ab_experiment` → `abtest_experiment` (v0.14.0, DB v1.4.0) for the wp.org ≥4-char-prefix rule, via an idempotent post-type rename migration.

## Notes

Rename diffs recorded in `PLAN.md` (v0.12.0 / v0.13.0 entries). Conventions in `.claude/rules/wp-conventions.md`.
