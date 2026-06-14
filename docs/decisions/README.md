<!-- generated-by: groundrules v1.5.0 (adopted) -->
# Architecture Decisions (ADR)

This folder contains the project's **Architecture Decision Records**: each structural decision made during the project is recorded in a file.

## Format

Inspired by [Michael Nygard](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions). See `0000-template.md`.

## Naming convention

`NNNN-title-kebab.md` where NNNN is a 4-digit incremental integer.

## When to create an ADR

When a decision has a **long-term impact** on the architecture, is **hard to reverse**, has **explicit tradeoffs** worth documenting, or might be **revisited later**. No ADR for trivial choices.

## Index

| # | Title | Status | Date |
|---|---|---|---|
| 0000 | Template | — | — |
| [0001](0001-internal-db-tracking-no-third-party.md) | Internal DB tracking, no third-party dependency | Accepted | 2026-04-28 |
| [0002](0002-stable-internal-names-across-renames.md) | Keep internal names stable across plugin renames | Accepted | 2026-05-16 |
| [0003](0003-universal-no-store-cache-strategy.md) | Universal `no-store` headers over cache-plugin filters | Accepted | 2026-04-29 |
| [0004](0004-preserve-data-on-uninstall.md) | Preserve A/B data by default on uninstall | Accepted | 2026-06-14 |
| [0005](0005-trademark-safe-naming-variolab.md) | Trademark-safe naming → "Variolab" | Accepted | 2026-05-16 |

> ADRs 0001–0005 were reconstructed at groundrules adoption time (2026-06-14) from the project history (`PLAN.md` shipped log, `docs/LEARNINGS.md`, changelog). They document decisions already in force.
