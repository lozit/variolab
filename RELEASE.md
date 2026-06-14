<!-- generated-by: groundrules v1.5.0 (adopted) -->
# Release — Variolab – A/B Testing

> Operational **runbook** for shipping. The CHANGELOG records *what* shipped; this file records *how* to ship safely. Update it whenever a release reveals a fragility (pair it with a `docs/LEARNINGS.md` entry).
>
> **Releases are user-gated.** Build + commit locally, present a summary, and tag/push **only on the user's explicit "go"** (see `docs/PROCESS.md` and `docs/AGENT-EVALS.md`). One tag push triggers both the GitHub Release and the wp.org deploy.

## TL;DR

```bash
# 1. Bump the version in BOTH places to X.Y.Z:
#    - variolab-ab-testing.php : "Version:" header + ABTEST_VERSION constant
#    - readme.txt              : "Stable tag: X.Y.Z" + a "= X.Y.Z =" changelog entry
# 2. Ensure docs/security/latest.md mentions vX.Y.Z (run /security-audit, or add a
#    "no security delta vs v<previous>" note).
# 3. Quality suite green locally (see checklist).
# 4. Commit, then tag + push (ONLY on user "go"):
git tag vX.Y.Z && git push origin main && git push origin vX.Y.Z
```

## Environments

| Env | Trigger | Target | URL |
|---|---|---|---|
| Local | `npx wp-env start` | Docker WordPress | http://localhost:8888 |
| GitHub Release | push tag `vX.Y.Z` (`release.yml`) | built `.zip` artifact | github.com/lozit/variolab/releases |
| wordpress.org | push tag `vX.Y.Z` (`wordpress-deploy.yml`) | SVN trunk + tag + assets | wordpress.org/plugins/variolab-ab-testing/ |

A single `vX.Y.Z` tag push fires **both** workflows.

## Pre-release checklist

- [ ] Version bumped in **both** `variolab-ab-testing.php` (`Version:` header **and** `ABTEST_VERSION`) **and** `readme.txt` (`Stable tag:` **and** a `= X.Y.Z =` changelog block). `release.yml` hard-fails if the header version ≠ the tag.
- [ ] `docs/security/latest.md` mentions `vX.Y.Z` (the regex `v${VERSION}([^0-9]|$)`). Run `/security-audit`, or add a "no security delta vs v<previous>" note. `release.yml` hard-fails otherwise.
- [ ] Quality suite green, in CI order: `composer run lint` → `composer run test` → integration (`npx wp-env run tests-cli --env-cwd=wp-content/plugins/AB-testing-wordpress vendor/bin/phpunit -c phpunit-integration.xml.dist`).
- [ ] `README.md` + `readme.txt` synced (Features / Roadmap; Stable tag / Changelog).
- [ ] **Capture before shipping**: decided → ADR, learned/blocked → `docs/LEARNINGS.md`, agent drift → `docs/AGENT-EVALS.md`, status → `PLAN.md`.
- [ ] User has said **"go"**.

## Secrets & configuration

- wp.org deploy needs GitHub Actions secrets `SVN_USERNAME` (lozit) + `SVN_PASSWORD` — both set.
- Build exclusions for the distributed `.zip` / SVN trunk: kept in sync between `release.yml` rsync, `ci.yml`, and `.distignore` (tests, `.claude/`, `.github/`, `docs/`, `CLAUDE.md`, composer files, `.wordpress-org`, hidden files…).

## Rollback

- **Tags are immutable on wp.org once deployed**: never re-tag a shipped version — bump a new patch (`X.Y.Z+1`) instead.
- A bad GitHub Release can be deleted/recreated; a bad wp.org version is fixed by shipping a corrected higher version.

## Known fragilities

- The two release-gate sanity checks (header-version match, security-note presence) fail the workflow late — verify both locally before tagging.
- `.wp-env.json` is pinned to WordPress 6.9.4 while `Tested up to:` is 7.0 — bump together when the wp-phpunit ^7.0 Dependabot PR merges.
- The plugin folder mounts in wp-env as `AB-testing-wordpress` (the repo dir name), not the slug — integration commands use that path.
