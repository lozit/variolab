<!-- generated-by: groundrules v1.5.0 (adopted) -->
# Adoption log — Variolab – A/B Testing

> A **dated, frozen** record of the `/groundrules:adopt` run on **2026-06-14**: what was here, and what groundrules did. Its purpose is **feedback to the plugin** — add your remarks below and share this file back to improve groundrules. This is **not** `docs/AGENT-EVALS.md` (the agent's behaviour here) nor `/groundrules:apply-best-practices` (external recommendations); it's the account of *this run*.

## What was here (before)

- **Project**: a mature, wordpress.org-published WordPress plugin (`variolab-ab-testing`), namespace `Abtest\`, PSR-4 → `includes/`. Stack: PHP 8.1+, Composer, PHPUnit + wp-phpunit (wp-env), PHPCS (blocking). Remote: GitHub `lozit/variolab`.
- **Docs present**: `README.md`, `readme.txt` (full wp.org changelog), `SECURITY.md`, `docs/security/` (16 archived audit reports + `latest.md`). No `docs/VISION.md`, `docs/decisions/`, `docs/LEARNINGS.md`, or other groundrules docs.
- **Planning**: `tasks/todo.md` (active + extensive shipped log + auto-managed security backlog) and `tasks/lessons.md` (8 WordPress lessons in Context/Mistake/Fix/Rule format).
- **CLAUDE.md**: present, hand-authored, comprehensive (stack, structure, lazy-loaded rules, security gate, workflow, language policy). **Not** tool-managed; no AI-attribution ban.
- **Global CLAUDE.md**: present (`~/.claude/CLAUDE.md`) — documentation/identity rules + RTK; project CLAUDE.md defers to it.
- **No** superpowers/specs. Plugin update check: installed 1.5.0; remote tag lookup returned nothing (fail-silent).

## What groundrules did

**Mode**: `consolidate` (user chose to impose the full groundrules layout and copy existing content into the canonical files).

- **Migrated (git mv, history preserved)**: `tasks/todo.md` → `PLAN.md` (restructured under groundrules headings: In progress / Up next / Ideas / Waiting + full Shipped history + the auto-managed Security backlog kept verbatim, relative links fixed for the new root location); `tasks/lessons.md` → `docs/LEARNINGS.md` (reformatted to rule/Why/When-to-apply, newest-first, code snippets kept). The emptied `tasks/` folder was removed. *Why git mv: keep blame/history on two long-lived docs.*
- **Generated — core**: `docs/VISION.md` (synthesized from README/readme.txt/CLAUDE.md, no invention), `intake/INTENT.md` + `intake/README.md` (INTENT points to the in-repo upstream docs — there was no separate brief), `docs/media/README.md`, `docs/decisions/` with `0000-template.md`, an index `README.md`, and **5 real ADRs** reverse-engineered from history (internal-DB tracking, stable internal names across renames, no-store cache strategy, preserve-data-on-uninstall, trademark-safe naming). *Why real ADRs: the decisions were already in force and documented across changelog/lessons — freezing the context now is higher value than empty stubs.*
- **Generated — optional (all selected)**: `docs/ARCHITECTURE.md`, `docs/DATA_MODEL.md`, `docs/GLOSSARY.md`, `docs/DESIGN_SYSTEM.md`, `docs/I18N.md`, `docs/PROCESS.md`, `docs/ROADMAP.md`, `docs/AGENT-EVALS.md`, `RELEASE.md`, `CHANGELOG.md` (pointer to readme.txt to avoid duplicating the canonical changelog). All filled with real project content drawn from the code/history, not `<fill in>` stubs.
- **Reference sweep (Edit, not regenerate)**: `CLAUDE.md` Tracking section repointed to `PLAN.md` / `docs/LEARNINGS.md` + a "Project docs (groundrules)" pointer block appended (rest untouched — hand-authored file never overwritten); `README.md` + `SECURITY.md` repointed their `tasks/...` links; `.claude/commands/security-audit.md` retargeted its auto-managed backlog from `tasks/todo.md` → `PLAN.md` so the command keeps working. *Why touch the command: leaving it pointed at a deleted file would silently recreate `tasks/todo.md` on the next audit.*
- **Left as-is**: `README.md`/`readme.txt` bodies, `docs/security/**` (audit archive; historical `tasks/todo.md` mentions in old reports left truthful), root `SECURITY.md` (so no `docs/SECURITY.md` generated), all code/config/CI.
- **Backfilled** `.groundrules.json` (`adoptionMode: "consolidate"`, `migratedFiles`, `adoptedFiles`, `generatedFiles`). `noAiAttribution: false` (commits use `Co-Authored-By`).
- **Did not commit** (per the user's standing "release/commit only on explicit go" rule).

## Remarks (fill in, then share back)

> Where did groundrules **not** do what you wanted? Be specific — the sections above give the context that makes each remark actionable when harvested into the groundrules repo.

- _e.g. "It skipped X but I'd have wanted Y" / "the CLAUDE.md omitted Z which my global doesn't actually cover" / "the mapping of `<file>` to `<role>` was wrong"_
-

---

Machine-readable detail of this run lives in `.groundrules.json` (`answers`, `generatedFiles`, `adoptedFiles`, `skippedFiles`, `migratedFiles`). This log is a one-time snapshot — it is **not** kept in sync as the project evolves.
