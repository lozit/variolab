<!-- generated-by: groundrules v1.5.0 (adopted) -->
# 0005 — Trademark-safe naming → "Variolab"

**Date**: 2026-05-16
**Status**: Accepted

## Context

The plugin needed a public name acceptable to the wp.org Plugin Review Team. "WordPress" is forbidden in name/slug (first rename, → "Uplift"). "Uplift" was then flagged on two grounds: (1) **non-distinctive** — it is the standard industry term for the A/B-testing lift metric (VWO/Statsig/Insider all use "uplift" for conversion lift); (2) **UPLIFT®** is a live USPTO trademark (Reg. 4973441, UPLIFT INC.) in the same "Advertising, Business & Retail Services" class.

## Decision

Adopt **"Variolab"** (vario + lab) — an **invented** term with zero wp.org / USPTO / SaaS collision at pick time. Public slug `variolab` (later `variolab-ab-testing` for the wp.org-reserved slug). The rename is purely user-facing; internal names stay frozen (see ADR 0002).

## Alternatives considered

- **Keep "Uplift"**: rejected — non-distinctive + active trademark in-class; would fail review.
- **A descriptive name** (e.g. "Simple A/B Testing"): rejected — non-distinctive, crowded, weak brand.

## Consequences

### Positive
- Distinctive, defensible, review-passing name. Plugin approved and published on wp.org (2026-05-27).

### Negative / Tradeoffs
- Two consecutive renames cost coordinated multi-file diffs + a GitHub repo rename (auto-redirect preserved).
- Brand ("Variolab") no longer matches internal `Abtest`/`abtest` identifiers (accepted — see ADR 0002).

### Neutral
- Historical changelog/audit narratives keep the old names truthfully (frozen; old URLs auto-redirect).

## Notes

Rename mechanics in `PLAN.md` (v0.13.0). Final wp.org slug `variolab-ab-testing` (v0.14.0).
