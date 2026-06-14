<!-- generated-by: groundrules v1.5.0 (adopted) -->
# 0004 — Preserve A/B data by default on uninstall

**Date**: 2026-06-14
**Status**: Accepted

## Context

`uninstall.php` runs when a user clicks **Delete** in wp-admin. It used to unconditionally drop `wp_abtest_events` and delete every experiment, page-import record, and option. In the field, users upgraded the plugin by **deleting + reinstalling** (rather than the Update action) — silently wiping all their A/B history every time. This bit the maintainer's own sites across two hosts (heyjoe, Kinsta).

## Decision

The uninstaller **preserves all data by default**. `uninstall.php` returns early unless the admin has explicitly opted in via `abtest_settings['delete_data_on_uninstall']` (default `false`). A **Settings → Data & uninstall** section explains "Update, don't Delete" and exposes the opt-in checkbox for a deliberate clean removal. Shipped in v0.18.0.

## Alternatives considered

- **Keep destructive default + add a warning note only**: rejected — documentation doesn't stop an accidental Delete; the data is gone before the user reads anything.
- **Never delete data at all (drop `uninstall.php`)**: rejected — leaves orphaned tables/options on genuine removal; violates the principle of a clean uninstall and wp.org expectations.

## Consequences

### Positive
- Accidental delete (or delete-to-reinstall) no longer loses experiments/stats/settings.
- Still offers a true clean removal for users who want it (opt-in, explicit consent).

### Negative / Tradeoffs
- A user who deletes expecting a full wipe now leaves data behind unless they ticked the box — mitigated by the Settings copy.
- Only protects installs already on v0.18.0+ at delete time; older versions still wipe → the "Update, don't Delete" guidance remains in readme/FAQ.

### Neutral
- The destructive code path is unchanged; it's now gated behind an explicit flag. No security delta (recorded in `docs/security/latest.md`).

## Notes

`uninstall.php`, `Settings.php` (Data & uninstall section), `Plugin::activate()` default. Changelog + FAQ in `readme.txt`.
