<!-- generated-by: groundrules v1.5.0 (adopted) -->
# Agent evals — Variolab – A/B Testing

> A log of the **agent's own** observed failure modes on this project — recurring mistakes, hallucinations, drifts — and the guard added for each. Reverse-chronological (newest at the top). This is **meta**: how the agent behaves *here*, not the project's domain.

**How this differs from `docs/LEARNINGS.md`**: LEARNINGS captures rules about the *project* (domain gotchas, stack pitfalls). AGENT-EVALS captures patterns about the *agent* (what it gets wrong on this repo, and the guard that should stop it). An eval entry usually produces a fix in `CLAUDE.md` or `.claude/rules/` — link it.

**When to add an entry**: when the agent repeats a mistake, fabricates a fact/API, drifts from an instruction, or you catch a hallucination. Capture it at the next checkpoint (typically before a push/release).

---

## 2026-06-12 — Ships fixes from code-reasoning before reproducing the symptom

**Observed**: on a "conversion doesn't work / click twice" report, the agent reasoned from the code and shipped two patch releases (v0.15.9, v0.15.10) before opening a real browser — the actual cause was a cache/optimiser plugin (WP Rocket delay-JS). The wrong root cause was "fixed" first.
**Trigger**: a front-end "it doesn't work on my site" report where the environment (cache/CDN/optimiser) is the likely culprit.
**Guard added**: `docs/LEARNINGS.md` rule "Diagnose front-end 'it doesn't work' in a real browser FIRST"; `docs/PROCESS.md` validation gate requires browser repro before coding a front-end fix.
**Status**: watching.

## 2026-06-12 — Tags + deploys a wp.org release after almost every file change

**Observed**: ~10 tag+deploy cycles in one hour; the user pushed back ("Arrête de pousser à chaque modification de fichier, on a fait 10 versions en 1h").
**Trigger**: completing any self-contained change and treating it as release-ready.
**Guard added**: standing rule — build + commit locally, present a summary, and **release only on the user's explicit "go"**. Recorded in `docs/PROCESS.md` (Release gate) and `docs/LEARNINGS.md` (batch releases).
**Status**: active rule.

<!-- Add new entries above this line, newest first. -->
