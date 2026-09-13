# Changelog

All notable changes to this project are documented in this file, in the
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.

Versioning is semantic in intent, but every component is kept single-digit or
zero-padded, because Unraid's plugin manager compares versions with a plain
`strcmp`, not a semver-aware comparison — `1.0.10` would otherwise sort
*before* `1.0.9`.

## [Unreleased]

### Plan — Phase 2, observation mode (2026-09-13)

Both event sources (`smbstatus -j` poll, `inotifywait -m` on pool-side watch
dirs) unified into an `activity` table in SQLite under
`/mnt/cache/appdata/dormouse/`. Settings page gets a live, read-only activity
view. No scoring, no `promoted` table, no move code anywhere in the shipped
tree — enforced by a grep test. Six watched shares from day one (Content,
Kieren, Teegan, Downloads, Filing Cabinet, Photos); Movies out of scope.
Files: `plugin/scripts/lib.php` (config/db/smb/inotify helpers),
`plugin/scripts/dormoused` (rewritten to run both sources), a small
`plugin/scripts/dormouse-api.php` read endpoint, `Dormouse.page` polling it,
`dormouse.plg` cfg-seeding upgraded to detect the Phase 1 placeholder cfg and
replace it with real defaults. Version 0.2.0.

## [0.1.0] - 2026-09-13

### Added

- Initial plugin scaffold: `dormouse.plg` with install/remove `FILE` blocks,
  a settings page registered under Settings > Utilities, and `rc.dormouse`
  supervising a no-op PHP daemon (`dormoused`).
- `scripts/build-plugin.sh`, `scripts/install-on-host.sh`,
  `scripts/uninstall-on-host.sh` for packaging and live-host deploy/verify.
- `scripts/version-sorts-after.php` — the strcmp version guard shared by the
  release workflow and the test suite.
- CI (`ci.yml`) and release (`release.yml`, with the strcmp version guard)
  GitHub Actions workflows.
- `tests/run.php` covering `.plg` structure, the version guard, the packaged
  build's layout and executable bits, and `rc.dormouse` start/stop/status.

This release moves no files and implements no tiering logic — it exists to
prove the packaging and release pipeline end to end before any observation or
scoring logic is written (see `CLAUDE.md` for the phase roadmap).
