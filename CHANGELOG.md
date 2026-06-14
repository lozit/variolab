<!-- generated-by: groundrules v1.5.0 (adopted) -->
# Changelog

This project is published on wordpress.org, where the **canonical, full changelog** lives in [`readme.txt`](./readme.txt) under `== Changelog ==` (wp.org format, one entry per released version). To avoid drift, that file is the single source of truth — do not duplicate the full history here.

This file exists for the [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) / SemVer convention and to capture anything **not yet released** (the `readme.txt` changelog only lists shipped versions).

## How to read history

- **Released versions** → [`readme.txt`](./readme.txt) `== Changelog ==` (newest first; `Stable tag` marks the latest).
- **What shipped + when, with engineering context** → [`PLAN.md`](./PLAN.md) (Shipped history).
- **Why a structural choice was made** → [`docs/decisions/`](./docs/decisions/).

## [Unreleased]

- **v0.18.0 — uninstall data safeguard + upgrade warning** (built + committed locally `52ce25a`, awaiting release). Uninstaller preserves all A/B data by default; opt-in "Delete all data on uninstall" in Settings → Data & uninstall; readme FAQ "Update, don't Delete". See [`PLAN.md`](./PLAN.md) and ADR [0004](./docs/decisions/0004-preserve-data-on-uninstall.md).

> On release, this entry moves into `readme.txt` `== Changelog ==` as `= 0.18.0 =` (already drafted there).
