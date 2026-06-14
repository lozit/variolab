<!-- generated-by: groundrules v1.5.0 (adopted) -->
# 0003 — Universal `no-store` headers over cache-plugin filters

**Date**: 2026-04-29
**Status**: Accepted

## Context

A cached A/B-test page is fatal: on a cache HIT, WordPress never runs, so no impression is logged, conversions are dropped, and the 50/50 split freezes on whichever variant got cached first. WordPress sites sit behind a wide variety of caches (WP Rocket, LiteSpeed, W3TC, Varnish/Cloudways, nginx page cache, Cloudflare APO, Kinsta's nginx+Cloudflare double layer). A cache plugin's own filter (`rocket_cache_reject_uri`, `litespeed_force_nocache_url`) only affects *that* plugin's cache — an external CDN never sees it.

## Decision

The **primary, universal** cache-bypass mechanism is sending `Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private` (via `nocache_headers()` + explicit header) from the Router on every A/B-tested page, as early as `parse_request`. Plugin-specific filters are added **on top** as best-effort, and host-specific exclusion guidance is surfaced in the admin. For caches that strip/ignore `no-store` (e.g. Cloudways Varnish), an **opt-in cache-resilient mode** (v0.16.0) redirects to a unique `?_abtcb=…` URL.

## Alternatives considered

- **Rely only on per-plugin filters**: rejected — blind to external CDNs/edge caches; the actual failure case in the field.
- **Always redirect to a unique URL**: rejected as the default — adds a redirect on first paint + a query param (SEO trade-off); kept as the opt-in fallback.

## Consequences

### Positive
- Works across any cache that respects HTTP cache headers, with no per-host config.
- Layered: universal header → plugin filters → host guidance → resilient-mode fallback.

### Negative / Tradeoffs
- Some caches ignore `no-store` → still need manual exclusion or resilient mode.
- Diagnosing which layer is failing is non-obvious → led to the cache-diagnostic pills (v0.17.0) that probe the real edge state anonymously from the admin browser.

### Neutral
- A diagnostic probe sends an `X-Abtest-Cache-Check` header the Router recognises to suppress counting (fail-safe, no auth).

## Notes

See `docs/LEARNINGS.md` (Kinsta double cache) and `CacheBypass` / `CacheNotice`. readme.txt has the per-host == Caching == section.
