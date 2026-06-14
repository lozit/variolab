<!-- generated-by: groundrules v1.5.0 (adopted) -->
# Process — Variolab – A/B Testing

> The **working method contract** for this project: how we work, with explicit gates. Neither the intent (`intake/`) nor the vision (`docs/VISION.md`) — the *how we proceed*. This mirrors and extends the Workflow section of `CLAUDE.md`; `CLAUDE.md` stays authoritative if the two ever drift.

## Phases (per task)

1. **Plan** — Plan mode by default for any non-trivial task (3+ steps or an architectural decision). Use subagents for parallel exploration to keep the main context clean.
2. **Load the right rules** — read the lazy-loaded rule for the surface being touched:
   - DB / user input → `.claude/rules/wp-security.md`
   - new hook / name / i18n → `.claude/rules/wp-conventions.md`
   - schema change / activation → `.claude/rules/db-migrations.md`
3. **Build** — simplicity first; no abstraction "just in case" (three similar lines beat a bad abstraction). Match the surrounding code's idiom.
4. **Verify** — green PHPCS + unit + integration, and a real demo (browser via Playwright for front-end behaviour) before declaring done.
5. **Capture** — record what was decided/learned (see below) before the checkpoint.
6. **Release** — only on the user's explicit "go" (see `RELEASE.md`); batch changes, don't tag per file change.

## Validation gates

- A task is **complete only after proof it works** (green tests + demo) — never on "should work".
- Front-end "it doesn't work" reports: **reproduce in a real browser FIRST** (the environment — cache/CDN/optimiser — is the usual culprit). See `docs/LEARNINGS.md`.
- **Releases require explicit user approval.** Build + commit locally, present a summary, wait for "go".

## Security gate (non-negotiable)

For any code touching DB writes, `$_POST`/`$_GET`, REST endpoints, or admin forms: prepared SQL, escaped output, nonce + capability on every state-changing action, sanitised input. Internal `/security-audit` before a release tag (target ≥ 8/10; currently 10/10).

## Capture at checkpoints (where things go)

- **Decided** (structural, hard to reverse) → an ADR in `docs/decisions/`.
- **Learned / blocked** (a gotcha, a correction, a cost paid) → `docs/LEARNINGS.md`.
- **Agent mistake / drift / hallucination** → `docs/AGENT-EVALS.md`.
- **Active task state** → `PLAN.md`. **Long-term direction** → `docs/ROADMAP.md`.
- Keep `README.md` + `readme.txt` in sync after each dev cycle (readme.txt: Stable tag + Changelog; README: Features + Roadmap).

## Working style

- Interviews: grouped questions (3–4 at a time, with options), not a long questionnaire.
- Substitutive visual changes: mock one screen, get validation, then propagate.
- **English only** in every committed file; only the live chat may be French.
