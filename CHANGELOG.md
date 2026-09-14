# Changelog

All notable changes to this project are documented in this file, in the
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.

Versioning is semantic in intent, but every component is kept single-digit or
zero-padded, because Unraid's plugin manager compares versions with a plain
`strcmp`, not a semver-aware comparison — `1.0.10` would otherwise sort
*before* `1.0.9`.

## [Unreleased]

## [0.2.1] - 2026-09-14

### Added

- Source C: `dormoused` parses `/var/local/emhttp/disks.ini` (tmpfs,
  emhttpd-maintained, never the pool itself) on its existing 15s tick, via
  new `dormouse_parse_disks_ini()`/`dormouse_pool_disk_names()` in `lib.php`,
  tested against a sanitised fixture modelled on a live capture
  (`tests/fixtures/disks.ini`, 2026-09-14, fake serials).
- Tracks `spundown`/`numReads`/`numWrites` per disk whose section name starts
  with `pool_disk_prefix` (new config key, default `snowflake`). On a
  `spundown` transition, records one `activity` row (`source='disk'`,
  `event='spinup'|'spindown'`) with `reads_delta`/`writes_delta` (new
  nullable columns, added via an idempotent `PRAGMA table_info`-guarded
  `ALTER TABLE` in `dormouse_migrate_activity_schema()`). Negative deltas
  (counter reset/wrap) are recorded as NULL, never a huge number. Baseline
  `event='state'` rows on daemon start. A disk that briefly drops out of
  `disks.ini` for one tick keeps its prior state instead of losing
  read/write continuity or emitting a spurious baseline row on reappearance.
- Optional smartctl cross-check on transitions only (`smartctl -n standby`,
  hard-capped with `timeout 3` so a slow device probe can't stall the main
  loop's SIGTERM handling) — logs and counts disagreements with disks.ini.
- `close_write` added to the inotifywait event set, recorded uncoalesced as
  `event='write'` (unlike the coalesced `access` rows).
- `dormouse-api.php`/`Dormouse.page` gain a disk spin-events view: the last
  30 transitions with the distinct activity in the surrounding ±60s window,
  plus a per-disk current-state line.
- Still Phase 2 — no moves; the existing no-move grep test stays green.

### Fixed

- Review found the `smartctl` cross-check had no timeout despite running
  inline in the main loop right before the SIGTERM check the 10s shutdown
  window depends on — capped with `timeout 3`.
- CLAUDE.md overstated where the 0.2.1 schema migration runs: `dormouse-api.php`
  opens the manifest read-only and never calls `dormouse_open_db()`, so it
  never migrates a pre-0.2.1 db itself — corrected, and documented as an
  already-handled (try/catch, not a crash) edge case rather than a gap.

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
