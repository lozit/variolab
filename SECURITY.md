# Security Policy

## Supported Versions

Security updates are issued for the following versions :

| Version | Supported          |
| ------- | ------------------ |
| 0.9.x   | :white_check_mark: |
| < 0.9   | :x:                |

The plugin is in active development — only the latest minor branch receives patches.

## Reporting a Vulnerability

**Please do not open a public GitHub issue for security vulnerabilities.**

Use GitHub's **Private vulnerability reporting** to disclose responsibly :

1. Go to https://github.com/lozit/variolab/security/advisories
2. Click **Report a vulnerability**
3. Fill the form with reproduction steps + impact assessment

GitHub keeps the report confidential between you and the maintainer until a patch is ready and a public advisory is published.

**Response targets** :
- Acknowledgment within 5 business days.
- Triage + severity assessment within 10 business days.
- Patch + public advisory within 30 days for High/Critical, 90 days for Medium/Low.

If you don't get a reply within these windows, feel free to escalate by opening a *non-sensitive* issue asking the maintainer to check their advisories.

## Audit Cadence

Security is verified at three points :

| When | What | Where |
| ---- | ---- | ----- |
| Every push to `main` | `composer audit` (CVE on dependencies), `composer run lint` (PHPCS WordPress standard), unit + integration tests | GitHub Actions ([`.github/workflows/ci.yml`](./.github/workflows/ci.yml)) |
| Before every release tag | Full manual review using the `/security-audit` slash command — situated checklist (9 plugin-specific surfaces) + OWASP grid (SQLi / XSS / CSRF / RCE / Access Control / Input Sanitization / File Uploads / Info Disclosure) | Reports persisted under [`docs/security/`](./docs/security/) |
| Continuously | GitHub Dependabot alerts (when enabled in repo settings) | GitHub Security tab |

The `/security-audit` command source lives in [`.claude/commands/security-audit.md`](./.claude/commands/security-audit.md). It auto-saves each run's report to `docs/security/audit-YYYY-MM-DD-vX.Y.Z.md` and updates the security backlog in [`tasks/todo.md`](./tasks/todo.md).

## Latest Audit

The most recent audit report is always available at [`docs/security/latest.md`](./docs/security/latest.md).

## Scope

In-scope for security reports :
- Any vulnerability in the plugin's PHP code that affects WordPress sites running this plugin.
- Misconfigurations in the bundled defaults that expose data or capabilities.
- CVEs in direct production dependencies (`composer.json` `require` block).

Out of scope :
- Vulnerabilities in WordPress core, PHP, or third-party plugins/themes installed alongside.
- Issues requiring physical access or compromised admin credentials (unless privilege escalation beyond admin is demonstrated).
- Findings that require a non-default configuration explicitly disabled in the plugin's defaults.
- Webhook secrets and GA4 API keys stored in the WordPress options table in plain text — this matches WordPress's standard storage model for plugin configuration. Any user with `manage_options` can read them via the WordPress dashboard or the REST API. Treat them like any other admin-accessible secret.
