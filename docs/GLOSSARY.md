<!-- generated-by: groundrules v1.5.0 (adopted) -->
# Glossary — Variolab – A/B Testing

Domain vocabulary for the project. One entry per term, alphabetical order. Definitions short and precise.

---

## A

**A/B test** — Showing two (or more) versions of a page to comparable visitor groups to see which converts better.

## B

**Baseline / Baseline mode** — The control version (Variant A). In baseline mode, Variant B is optional; until it's added, every visitor sees A.

**Blank Canvas** — The template that renders an imported HTML page byte-perfect with zero WordPress theme wrapper.

**Bonferroni correction** — Adjustment to the significance threshold (α) when comparing multiple variants against the baseline, to control false positives.

## C

**Cache-resilient mode** — Opt-in feature that forces a fresh render of a test page via a one-time redirect to a unique `?_abtcb=…` URL no cache can have stored.

**Conversion** — A tracked goal completion (a URL visited or a CSS-selector element clicked).

**Control** — See *Baseline*. Variant A.

## I

**Impression** — One server-side record that a visitor was served a variant of a test page. A conversion requires a prior impression.

## L

**Lift** — The relative difference in conversion rate between a variant and the baseline (the "uplift" metric).

## P

**p-value** — Probability the observed difference happened by chance under the null hypothesis; below α (typically 0.05) → statistically significant.

## T

**test_url** — The URL path an experiment targets (e.g. `/promo/`), decoupled from the variant page IDs.

**Two-proportion z-test** — The statistical test used to compare two conversion rates for significance.

## V

**Variant** — One version under test (`a`, `b`, `c`, `d`). Variant A is the baseline/control.

**visitor_hash** — A non-reversible, truncated (64-bit) salted SHA-256 of IP + User-Agent, used for per-visitor dedup without storing PII.
