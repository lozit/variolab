<!-- generated-by: groundrules v1.5.0 (adopted) -->
# Design System — Variolab – A/B Testing

**Living** reference of the admin UI's visual identity. Keep it in sync with the CSS tokens. The **why** behind choices → `docs/decisions/`.

> Scope: this is the **wp-admin** design system (the branded A/B Tests screens), not the front-end (variant pages render in the site's own theme or as zero-wrapper Blank Canvas imports).

## Principles

- Branded but restrained: a distinct cream canvas that reads as "a product", not raw wp-admin gray, while staying legible and dense.
- Scoped: all styling is scoped to the plugin's admin pages (`body.toplevel_page_abtest-experiments`) so it never leaks into core wp-admin.
- Self-contained: fonts bundled (no remote load — wp.org rule), CSS layered by concern.

## Colors

| Token | Value | Usage |
|---|---|---|
| canvas | `#EFECE4` (cream) | Page background across all plugin admin screens |
| status / pill palette | `--vlab-status-*` tokens | Status badges, cache-diagnostic pills (ok = green, warn = amber, danger = red, pending = grey) |
| variant series | 12-hex rotating palette | Per-variant chart line + variant tag colour (kept consistent between chart and row) |

> Exact hex values live in the CSS token file — treat that as source of truth and update this table when they change.

## Typography

- **Inter Tight** (UI) + **JetBrains Mono** (code/numerals), bundled as WOFF2 with a Latin Unicode subset (~200 KB total via `pyftsubset`). SIL OFL 1.1 license files shipped alongside.

## Components

- **Brand header** — `Admin::render_brand_header($title)`: icon + wordmark + version pill; shared across List / Edit / Settings / Import. Includes `<hr class="wp-header-end">` so WP repositions admin notices above it.
- **KPI strip** — 5 cards (Active tests / Impressions / Conversions / Overall rate / Winners shipped), driven by `Stats::overview_kpis()`.
- **Status chips** — All / Draft / Running / Paused / Ended filter toolbar.
- **Cache-diagnostic pills** — `.vlab-cache-pill` with `--ok` / `--warn` / `--pending` states.
- **Sparklines** — inline SVG polyline per (experiment, variant); A solid, B/C/D dashed; start/end date markers. (Chart.js was dropped in v0.15.0 for a ~200-LOC vanilla renderer.)

## Where tokens live

CSS architecture: `admin-tokens.css` (design tokens + `@font-face`, everywhere) → `admin-shell.css` (cream bg + brandline + buttons, everywhere) → `admin-list.css` (list-page-specific). The legacy `admin.css` is kept verbatim for Edit / Settings / Import. Source: `assets/css/`.

## Accessibility

- Status conveyed by text label, not colour alone (pills carry words like "CACHED", "out of cache").
- Native WordPress form controls reused where possible (keyboard + screen-reader behaviour inherited).
