# Changelog

All notable changes to this project are documented in this file, in the
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.

Versioning is semantic in intent, but every component is kept single-digit or
zero-padded, because Unraid's plugin manager compares versions with a plain
`strcmp`, not a semver-aware comparison — `1.0.10` would otherwise sort
*before* `1.0.9`.

## [Unreleased]

## [0.2.0] - 2026-09-13

### Added

- Observation mode: `plugin/scripts/lib.php` unifies two event sources into
  an `activity` table in SQLite under `/mnt/cache/appdata/dormouse/` (never
  on flash) — a `smbstatus -j` poller (open/close/dirwatch transitions per
  handle, client IP resolved via tcon, never username) and a long-running
  `inotifywait -m` child over pool-side watch dirs bounded to `watch_depth`,
  with explicit `IN_Q_OVERFLOW` handling and per-file access coalescing.
- `dormouse.cfg` (flash, ini-style) with real defaults for the six watched
  shares, pool/cache roots, poll/refresh intervals, and `db_path`; the
  install block upgrades the Phase 1 placeholder cfg in place without ever
  touching a cfg that already has real keys.
- `plugin/scripts/dormouse-api.php` — read-only status endpoint, and a live
  activity view on the settings page (daemon status, watch count, overflow
  counter, per-share 24h counts, last 50 rows), polled via urlencoded
  `$.post` per the WebGUI rules in `CLAUDE.md`.
- Moves remain structurally impossible, not merely disabled: a test greps
  the whole `plugin/` tree for move/rename/delete call shapes and fails the
  suite if any appear.
- `scripts/uninstall-on-host.sh` now also asserts the daemon's inotifywait
  child is gone; the manifest db under appdata is deliberately preserved.

### Changed

- `scripts/install-on-host.sh` verifies the upgraded cfg has real keys and
  that the inotifywait child is running (with a retry, since the daemon's
  own startup watch-list walk can take tens of seconds on a cold pool).

This release still moves nothing. It exists to gather a week of real
activity evidence (which shares beyond Content actually get read) before any
scoring or promote logic is written in Phase 3.

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
