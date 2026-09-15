# Changelog

All notable changes to this project are documented in this file, in the
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.

Versioning is semantic in intent, but every component is kept single-digit or
zero-padded, because Unraid's plugin manager compares versions with a plain
`strcmp`, not a semver-aware comparison — `1.0.10` would otherwise sort
*before* `1.0.9`.

## [Unreleased]

## [0.2.3] - 2026-09-16

### Fixed

Boot-order bug within Phase 2 (observation mode only; no tiering logic
added — the moves-impossible grep test stays green). Verified on the live
host 2026-09-15: plugins install before the cache/pool mounts at boot, so
0.1.0-0.2.2's unconditional daemon start in the `.plg` install step could
create `manifest.db` on the RAM rootfs, silently covered by the later cache
mount and lost at shutdown; `dormoused` also held `manifest.db`/-wal/-shm
open on `/mnt/cache` with no event hooks to release them before an array
stop. Never actually fired (Dormouse hadn't been through a boot since
install), but would have on the next reboot.

- `plugin/event/started` / `plugin/event/stopping_svcs` (new, executable —
  emhttpd gates these on `-x`, a documented exception to CLAUDE.md rule 4,
  confirmed live via `/usr/local/sbin/emhttp_event` and the identical hooks
  shipped by `file.activity`/`unbalanced`/`tips.and.tweaks`) hook the real
  emhttpd lifecycle instead of relying on install-time ordering, matching
  Godwit's 0.1.1 fix for the identical bug (which has the same `set -e`
  hazard described below, unfixed there — out of scope here, Godwit is
  reference-only).
- `plugin/scripts/array-ready.sh` (new): the install step starts the daemon
  only when the array is already `STARTED` and both `cache_root` and
  `pool_root` (read from `dormouse.cfg`, never hardcoded) are real
  mountpoints; otherwise it defers to the `started` event hook. Its result
  is consumed via `if bash array-ready.sh; then READY=0; else READY=1; fi`
  — a bare statement would have its non-zero exit (the expected, common
  case) abort the whole install script under the block's `set -e`, exactly
  on the boot-time case this release exists to handle. Caught by the review
  pipeline (2/3 then 4/5 agreement), fixed before merge.
- `dormoused` refuses to start (before any mkdir) unless `pool_root` is a
  real mountpoint and the directory that would actually hold `db_path` is
  on a mounted filesystem — checked by comparing device IDs
  (`dormouse_db_path_mounted()`/`dormouse_nearest_existing_ancestor()`)
  rather than assuming `db_path` lives under `cache_root`, and rather than
  walking up to the nearest directory `mountpoint -q` accepts: `/mnt` on
  this host is itself a bind mount of rootfs (`findmnt /mnt` ->
  `rootfs[/mnt] rootfs`), so `mountpoint -q /mnt` is true even before any
  pool mounts — that walk would have made the guard fail open on exactly
  the boot-time case it exists to catch. Caught by `advisor` after the
  first review round; verified against a live host read
  (`stat -c '%n dev=%d' / /mnt /mnt/cache /mnt/snowflake`) and reproduced in
  a test with a real `mount --bind`. Env overrides for tests handle an
  explicit `"0"` correctly (the `getenv() ?: null` pitfall).
- `rc.dormouse stop` escalates to SIGKILL for `dormoused` and its
  `inotifywait` child if the graceful wait times out, and only removes the
  pidfile once death is confirmed.
- Tests (98 total): array-ready states, mount-guard override in both
  directions (including `"0"`), the bind-mount-of-rootfs edge case, the
  `set -e` regression, SIGKILL escalation, event scripts packaged with
  shebang + executable bit, `dormouse.plg` XML well-formedness.
- Untracked `.scratch/` (pre-existing local scratch content that had been
  accidentally swept into a checkpoint commit by `git add -A`).

## [0.2.2] - 2026-09-15

### Added

- Source D: `dormoused` reads every `/proc/spl/kstat/zfs/<pool>/objset-0x*`
  file and `/proc/diskstats` on its existing 15s tick — never `zpool`/`zfs`
  commands, never a path under `/mnt/snowflake` — via new
  `dormouse_parse_objset_file()`/`dormouse_parse_diskstats()`/
  `dormouse_zfs_device_stats()` in `lib.php`. Fixtures under
  `tests/fixtures/objset-*` and `tests/fixtures/diskstats` copy the exact
  line structure of a live, read-only `ssh cat` of the root dataset,
  `Filing Cabinet` (space in the name), `Content`, and `/proc/diskstats`
  (2026-09-15).
- Deltas since the previous tick are computed per dataset and per device
  (`dormouse_zfs_poll_tick()`) and written to a new `zfs_io` table only when
  at least one field's delta is non-zero or a reset (NULL, reusing Source
  C's `dormouse_disk_delta()` reset rule) — first sight of a dataset/device
  is a silent baseline, no row. Device byte counts convert diskstats sectors
  to bytes (×512). Trimmed by the same `activity_retain_days` job as
  `activity`, via `dormouse_trim_zfs_io()`. `stats` key `zfs_last_poll_ts`
  advances every tick regardless of whether a row was written.
- `dormouse_build_spin_events()`'s entries gain `zfs_window`: per-dataset/
  per-device delta sums over the same ±60s window already used for the
  `before`/`after` activity rows — a spin-up with zero watched-share
  activity now shows the ZFS-internal I/O (root dataset, snapshots,
  unwatched shares) that actually caused it. `dormouse_build_status()` gains
  `zfs_24h`, a per-dataset 24h summary. Both aggregation queries preserve a
  `NULL` from a summed counter reset rather than letting SQLite's `SUM()`
  turn it into a silent `0` (`dormouse_zfs_sum_or_null()`).
- `Dormouse.page` gains a "ZFS I/O — last 24h by dataset" table and renders
  each spin event's `zfs_window`, with a `humanBytes()` formatter; every
  interpolated value (including formatted-byte strings) goes through the
  existing `esc()` convention before `innerHTML`.
- Config: `zfs_pool_name` (default `snowflake`) and `zfs_kstat_dir` (default
  `/proc/spl/kstat/zfs`, overridable for tests) — both cfg keys like
  `pool_disk_prefix`, not env vars. Deliberately independent of
  `pool_disk_prefix` rather than derived from it: `pool_disk_prefix` filters
  `disks.ini` section names, `zfs_pool_name` names a kstat directory — the
  same value by convention on this host, not by a coupling the code
  enforces. Revisit if a future host ever needs them to differ.
- `stats` key `zfs_dataset_count` (datasets found this tick) is surfaced next
  to `zfs_last_poll_ts` on the settings page, so a wrong `zfs_pool_name`/
  `zfs_kstat_dir` or an empty glob is visible as "0 datasets tracked" rather
  than installing cleanly and silently watching nothing — the same failure
  class as the fanotify finding this project already documents.
- Still Phase 2 — no moves; the existing no-move grep test stays green (and
  caught a literal "zpool" in a comment during development, confirming it's
  live, not decorative).

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
