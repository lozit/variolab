<!-- generated-by: groundrules v1.5.0 (adopted) -->
# Roadmap — Variolab – A/B Testing

**Long-term** breakdown into deliverable milestones. Distinct from `PLAN.md` (the **active** todo right now): the roadmap describes the trajectory. Structural decisions go in `docs/decisions/`.

## Condensed vision

A privacy-respecting, self-hosted whole-page A/B testing plugin for WordPress that "just works" behind any cache and excels at testing AI-generated HTML landings.

## Milestones

### Milestone 1 — MVP & CRO workflow — ✅ Shipped (v0.1–v0.5)
- Page-level A/B tests, internal tracking, cookie split, stats.
- URL-decoupled experiments, state machine, baseline mode, multi-variant A/B/C/D.

### Milestone 2 — HTML import & integrations — ✅ Shipped (v0.4–v0.9)
- Blank Canvas HTML/ZIP import + watch directory; GA4 + generic webhooks; REST stats API; targeting; WPML/Polylang.

### Milestone 3 — Compliance & wp.org publication — ✅ Shipped (v0.8–v0.15)
- GDPR consent gate + privacy content; PHPCS-clean + Plugin Check green; trademark-safe rename; published on wordpress.org (2026-05-27).

### Milestone 4 — Cache correctness & data safety — ✅ Shipped (v0.16–v0.18)
- Cache-resilient mode + per-host guidance; cache-diagnostic pills; preserve-data-on-uninstall safeguard.

### Milestone 5 — Deeper testing surfaces — 🔭 Upcoming
- **Goal**: test more than whole pages.
- **Scope**: block-level testing (single Gutenberg block); WooCommerce price / product-description variants.
- **Exit criteria**: a block or product variant can be A/B tested with the same stats pipeline.
- **Status**: Upcoming.

### Milestone 6 — Operational polish — 🔭 Upcoming
- **Goal**: reduce cache/consent setup friction.
- **Scope**: auto-purge Kinsta cache via REST on test transitions; auto-detection of installed consent plugins (Complianz, CookieYes, Cookiebot).
- **Status**: Upcoming.

## Out of scope (for now)

- Multisite network-wide schema management.
- Server-side variant rendering behind a shared cache without a redirect.
- Per-visitor individual data erasure (no reversible identifier stored by design).
