# Dormouse — CLAUDE.md

The build plan, kept out of the repo, is the authority for this project. `PLAN.md`
at the repo root is gitignored (it names LAN hosts) and is never committed. This
file is derived from it and from the repo as it stands — if the two conflict,
re-derive this file from the current repo state, not from memory of an earlier
plan.

## Forge and releases (changed 2 Nov 2026)

**Gitea is primary; GitHub is a read-only push mirror.** Develop against `https://git.kiztigs.com/kmbrimble/unraid-dormouse`
(`origin`, via the gt credential helper; use `gt`, not `gh`, for issues/PRs). `github.com/kmbrimble/unraid-dormouse` receives every
push to Gitea within about a minute (`sync_on_commit`, plus a 10-minute sweep: `interval: 10m0s` in Gitea's `push_mirrors` API, checked 2026-11-04) and **must never be pushed to directly**: the mirror
force-syncs and prunes refs, so anything that exists only on GitHub is lost. GitHub has Issues, Wiki, Projects, Discussions and PRs
switched off. The `.plg` URLs (`raw.githubusercontent.com/.../main/dormouse.plg` and the GitHub release assets) deliberately still
point at GitHub, which is where installed servers fetch from.

- `main` is protected on Gitea: changes go through a PR, the Gitea CI check (`CI / lint-and-test`) must be green, and butler-bot
  merges its own green PRs (no human approval).
- CI lives in `.gitea/workflows/ci.yml` (the Gitea runner reads that directory only). It includes a **release-readiness** step:
  if `&version;` in `dormouse.plg` has no `v<version>` tag yet, the build must be reproducible and `&md5;` must already equal its md5.
- **How to release:** in the PR that bumps `&version;` (and adds the `###<version>` CHANGES entry and the CHANGELOG), also set `&md5;`.
  Get the value from CI: the release-readiness step fails with `Set &md5; to <value> in the PR that bumps the version.` (`scripts/check-md5.sh`) on the first run, so push again with that
  value. Merge the PR, then tag the merge commit on Gitea (`git tag v<version> && git push origin v<version>`). The tag is mirrored;
  `.github/workflows/release.yml` on GitHub then rebuilds, requires the same md5, and publishes the GitHub Release with the `.txz`.
  Nothing writes back to the repo after the tag. See the `forge-release` skill.
- `release.yml` is also dispatchable by hand on GitHub as a **dry run** (builds and prints the md5, publishes nothing).
- The first real release on this layout is the final proof of the tag path; it had not happened when this section was written, and still had not on 2026-11-04 (latest tag `v0.2.3`, which predates the Gitea move).

## Non-negotiable constraints

Hard-won from SecretsMan and NetMan. Not optional, not footnotes. Rules 1–7 apply to any
Unraid plugin; the rationale and the general procedure are in the `unraid-plugin-packaging`
skill (headings named per rule). Each rule's statement and every Dormouse-specific detail stay
here.

1. **Versions are compared with `strcmp`, not semver** (`1.0.10` sorts older than `1.0.9`):
   keep every component single-digit or zero-padded; never cross from date-based to semantic
   versions without `plugin install <url> forced`. Enforced by `scripts/version-sorts-after.php`,
   shared between `.github/workflows/release.yml` (refuses a version that does not sort after
   the previous tag) and `tests/run.php`. Skill: "Versions are compared with strcmp".
2. **A `FILE` block with no `<MD5>`/`<SHA256>` is never overwritten.** No versionless md5
   sidecar, no re-check of the download in a script. No bash-level md5 check exists anywhere
   in this repo; the plugin manager's check of the `.txz` against its own `FILE` block's
   `<MD5>` is the only one. Skill: "FILE blocks and checksums".
3. **The Plugins tab description comes from the installed `README.md`.** `plugin/README.md`
   stays in the stock shape: `**Dormouse**`, a blank line, one paragraph, no headings. Never
   package the repo README. Skill: "Package layout".
4. **Exercise the uninstall path deliberately.** Every script a `.plg` `FILE` block invokes
   has a shebang and is gated on `[[ -f script ]]`, never `[[ -x script ]]` (SecretsMan's
   uninstall once silently never ran that way). Skill: "Install and remove scripts".
   **Documented exception:** `plugin/event/started` and
   `plugin/event/stopping_svcs` (0.2.3) — emhttpd's own `emhttp_event`
   dispatcher (`/usr/local/sbin/emhttp_event` on the host, read live
   2026-09-15) gates `event/*` scripts on `[ -x $Dir/event/$1 ]` itself, not
   `-f`. This is emhttpd's dispatch convention, not this repo's `.plg`
   invoking a script directly, so rule 4 doesn't apply to these two files —
   they are packaged executable on purpose, verified in
   `scripts/build-plugin.sh` and `tests/run.php`. `file.activity`,
   `unbalanced` and `tips.and.tweaks` all use the identical `started`/
   `stopping_svcs` event names for their own daemons (confirmed by reading
   their installed `event/` directories live).
5. **`upgradepkg` may leave both versions in `/var/log/packages/`.** Cosmetic
   (tmpfs, rebuilt at boot), not a bug.
6. **`gh release list` is not evidence about what is on the host.** Read
   `/boot/config/plugins/dormouse.plg` on the host itself.
7. **Plugins install before the array's pools mount at boot — never start the daemon
   unconditionally from the `.plg` install step.** On this host `/mnt` is a bind mount of
   rootfs (same `st_dev` as `/`), so `mountpoint -q /mnt` is true before any pool mounts: never
   walk up an unmounted target's path to the nearest ancestor `mountpoint -q` accepts. Compare
   device IDs against root instead (`dormouse_db_path_mounted()` in `lib.php`). The install
   step, the daemon itself, and the emhttpd `started`/`stopping_svcs` event hooks together are
   what make 0.2.3 safe — see "Boot order and lifecycle" below. Skill: "Boot, array start and
   daemons". The live evidence (syslog timestamps of the 12 Sep boot, `findmnt`, the `stat`
   device IDs, verified 2026-09-15) is in `docs/history.md`.

### Boot order and lifecycle (0.2.3)

- `plugin/event/started` runs `rc.dormouse start`; `plugin/event/stopping_svcs`
  runs `rc.dormouse stop`. These are emhttpd's real lifecycle signals — see
  rule 4's documented `-x` exception above.
- `plugin/scripts/array-ready.sh`: the `.plg` install step starts the daemon
  immediately only if `/var/local/emhttp/var.ini` already has
  `mdState="STARTED"` **and** both `cache_root` and `pool_root` (read from
  `dormouse.cfg`, never hardcoded) are real mountpoints. Otherwise it prints
  that the daemon will start via the `started` hook. Its exit code is
  consumed as `if bash array-ready.sh; then READY=0; else READY=1; fi` —
  **never** as a bare statement followed by `READY=$?`, because the install
  block runs under `set -e` and a bare statement's non-zero exit (the
  expected outcome on a boot-time install) would abort the whole install
  script before it reaches the "defer to the started hook" branch. Caught
  by the review pipeline while building 0.2.3 (agreement 2/3, then 4/5 after
  more passes) — verify this shape survives any future edit to the install
  block.
- `dormoused` itself refuses to start (before any `mkdir`) unless
  `pool_root` is a real mountpoint and the directory that would actually
  hold `db_path` is on a mounted filesystem — the latter via device-ID
  comparison against root (`dormouse_db_path_mounted()` /
  `dormouse_nearest_existing_ancestor()` in `lib.php`), not `mountpoint -q`
  on an assumed `cache_root` ancestor, per rule 7 above. `DORMOUSE_ASSUME_CACHE_MOUNTED`
  / `DORMOUSE_ASSUME_POOL_MOUNTED` env overrides exist for tests; both
  handle an explicit `"0"` correctly (`getenv() ?: null` would silently
  treat `"0"` as unset).
- `rc.dormouse stop` waits `DORMOUSE_STOP_WAIT_ITERATIONS` (20 × 0.5s = 10s
  in production) for a graceful exit, then escalates to `SIGKILL` for both
  `dormoused` and its `inotifywait` child (matched via a path-specific,
  self-match-safe `pkill -f` pattern — bracket one character per the
  pgrep/pgrep-self-match note in the "Dev-environment fact" section below),
  and only removes the pidfile once death of both is confirmed.
- **Whether inotify watches alone block a ZFS unmount:** no. Checked live
  2026-09-16: the running `inotifywait` child's only meaningful open fd is
  `anon_inode:inotify` (a kernel-internal notification queue) — it holds no
  fd under `/mnt/cache` or `/mnt/snowflake`. Per `inotify(7)`, an unmount of
  a watched filesystem delivers `IN_UNMOUNT` and the kernel drops the watch
  rather than blocking the unmount. The confirmed blocker remains the open
  SQLite handle (`manifest.db`/-wal/-shm) on `/mnt/cache`, which is exactly
  what the `stopping_svcs` hook exists to release before the array unmounts
  disks.
- **Not yet verified:** behaviour across a real reboot (reboots are the user's action, never this
  dev container's — see the `unraid-live-host-testing` skill, "Hard limits").
  Everything above was verified by hand-running the event scripts and by
  reading live host state; a real boot-time install has not been observed
  end-to-end.

### WebGUI rules

The rules and their rationale are in the `unraid-webgui-plugin-pages` skill ("Requests:
transport and CSRF", "PHP endpoints"). In this repo:

- Plain urlencoded `$.post` is the only POST path `Dormouse.page` uses — no multipart
  `FormData`, no `fetch()`.
- No custom CSRF check in `dormouse-api.php` (`local_prepend.php` has already consumed and
  unset `$_POST['csrf_token']`).
- Functions reachable from both CLI and web must not reference `STDOUT`, `STDERR` or `$argv`.
  `tests/run.php` runs under the CLI SAPI where these exist, which makes this bug class
  structurally invisible to the test suite.

### Release requirements (every build)

Mechanics (version-bump PR, `&md5;` from CI, tagging, verifying the GitHub Release) are in the
`forge-release` skill and "Forge and releases" above. Every release also needs:

- `.plg` published as a GitHub Release with the `.txz` attached and the real
  `<MD5>` written onto the FILE block (committed in the version-bump PR, see "Forge and releases").
- `CHANGELOG.md` updated (Keep a Changelog format).
- `README.md` (repo) and `plugin/README.md` (stock one-liner) both current.
- `CLAUDE.md` current — derived from the repo, never invented; cite verification
  method and date inline.
- Releases are cut without asking: merge the PR, then tag it on Gitea (see "Forge and releases"). No automatic release on merge any more.
- Pre-push secrets scan: a broad pass across tracked files, then a targeted pass
  on new files for serials, IPMI credentials, IPs, keys. (The host IP and ssh key
  path in `scripts/*-on-host.sh` are the same pattern the public secretsman repo
  already ships, and are acceptable.)

### Never stat pool paths on a timer (Phase 2 onward, one deliberate exception)

The daemon must not `stat()` or otherwise poll paths on `/mnt/snowflake` on a
timer — that spins the disks the plugin exists to keep asleep. State comes
from the event stream (`inotifywait`, `smbstatus -j`) and from the cache side
only. This is easy to violate by accident in a reconciliation or housekeeping
loop; call it out explicitly in review whenever that code is touched.

The **one** deliberate exception, shipped in Phase 2: `dormouse_build_watch_dirs()`
in `plugin/scripts/lib.php` walks the watched shares down to `watch_depth` to
build the `inotifywait --fromfile` list, at startup and every
`watch_refresh_minutes`. It is bounded (never recursive past `watch_depth`)
and every call logs `watch refresh: N dirs found in X.XXs` — watch that
duration during the evidence week; if a *refresh* (not the cold startup walk)
takes more than a second or two, this exception is spinning the pool on a
15-minute timer and needs revisiting before Phase 3.

## What this is

Hot-file tiering for Unraid: detect sustained reads on slow storage, pull the
surrounding working set onto fast storage, and put it back when it goes cold.
Media lives on `snowflake`, a ZFS pool with two raidz1 vdevs across six 14 TB
disks; because ZFS stripes across vdevs, every read spins all six disks, with a
10–15 s spin-up latency per episode. Dormouse automates the manual
Unbalanced-plugin workaround for this, and generalises it to any share that
opts in — it is client-agnostic, keying off filesystem and SMB activity rather
than a Plex API, because a real regular viewing path on this host (a
file-browser app connecting anonymously as `nobody`) is invisible to Plex.

**The fanotify finding:** `fanotify_mark()` on this host **succeeds** against
the ZFS pool and then **delivers zero events**, including for the test's own
probe read — a silent failure that would let a fanotify-based daemon (e.g. a
port of `kaedinger/unspin`) install cleanly, log that it is watching, and never
promote anything. This was tested and controlled: identical code against both
mounts in the same run delivered events from `/mnt/cache` (btrfs) and nothing
from `/mnt/snowflake` (ZFS). **The mechanism was not determined.** A plausible
explanation is OpenZFS not wiring the VFS fsnotify hooks that mount-wide
fanotify marks depend on, but that is unverified and must not be asserted as
fact anywhere in this repo. `inotify` works fully on this ZFS pool (`IN_OPEN`,
`IN_ACCESS`, `IN_CLOSE_NOWRITE` all confirmed), which is why the daemon is
inotify-based, PHP, and not a fork of Unspin.

## Host facts that packaging depends on

Per the build plan's verified-facts table (checked live on the host; see the
build plan for the exact commands used):

- Unraid 7.3.1, `192.168.0.10`, ssh key `/root/.ssh/unraid_secretsman`.
- Host PHP is **8.4.21 CLI**, with `json`, `sqlite3`, `pcntl`, `posix`.
- **No `python3` and no compiler on the host.** Any comparison or build logic
  that must run on the host itself cannot depend on either. Packaging scripts
  that need `python3` (the strcmp version check in
  `scripts/install-on-host.sh`) run in *this development container*, which has
  `python3`, not on the host. The release workflow's strcmp guard runs on the
  GitHub-hosted runner (and CI on the Gitea runner), which have PHP, via `scripts/version-sorts-after.php`.

## Dev-environment fact: this container runs ON the live host

Verified 2026-09-13: this dev container (`claude-code`) is itself a Docker container on the
production Unraid box that `scripts/*-on-host.sh` ssh into, with the host's docker socket
bind-mounted in. The consequences (no staging box, `docker run` starts a real container on the
NAS, `pgrep -f`/`pkill -f` can self-match the agent's prompt so bracket one character, e.g.
`[d]ormoused`) are in the `unraid-live-host-testing` skill ("Hard limits", "Things this
environment does that surprise"); throwaway-container and cleanup rules are in the global
`CLAUDE.md`. Two Dormouse-specific points:

- A process started in this container is visible in `ps` over `ssh root@192.168.0.10` under
  its real pid — because the host sees every container's processes, not because namespaces are
  shared (the container still has its own PID namespace).
- This container now has `php` (8.4.27 CLI) and `xz` (5.8.1) (`command -v php xz`, both
  `/usr/bin`, checked 2026-11-04; the 2026-09-13 text said it had neither). The full
  `php tests/run.php` still runs in CI only (the isolated Gitea runner VM; the tag release on a
  GitHub-hosted runner): its `rc.dormouse` tests start and SIGKILL real processes, which are
  visible on the live host. A filtered local run is safe — see "Test command".
  From PR #4 (`build-plugin.sh` pins `xz -6 -T1` and `--mode`) a local build gives the same md5
  as CI (`f55ba445…` for `main`'s v0.2.3 tree both locally and in Gitea CI run 149, 2026-11-04);
  before it, the container's multi-threaded xz 5.8.1 gave a different one. Still take the
  release `&md5;` from the CI log of the exact commit (see `forge-release`).

`scripts/uninstall-on-host.sh` hit the `pgrep` self-match twice while being debugged; that
history, and the original text of this section, are in `docs/history.md`.

## Repo layout

```
dormouse.plg                    .plg — entities, CHANGES, install/remove FILE blocks
plugin/                         installed tree (unpacked to /usr/local/emhttp/plugins/dormouse/)
  README.md                     stock one-paragraph description (Plugins tab)
  Dormouse.page                 settings page — live activity view (Phase 2), polled via $.post
  scripts/rc.dormouse           start|stop|restart|status, supervises dormoused
  scripts/dormoused              PHP daemon — Phase 2: smbstatus poller + inotifywait child
  scripts/lib.php                config/db/smb/inotify helpers, shared by dormoused, the api
                                  endpoint and tests/run.php
  scripts/dormouse-api.php        read-only JSON status endpoint for Dormouse.page
  scripts/array-ready.sh          install-time gate: array STARTED + cache/pool mounted (0.2.3)
  event/started                   emhttpd hook -> rc.dormouse start (0.2.3, packaged executable)
  event/stopping_svcs             emhttpd hook -> rc.dormouse stop (0.2.3, packaged executable)
scripts/
  build-plugin.sh <version>     builds dist/dormouse-<version>.txz
  install-on-host.sh            installs/upgrades on the live host over ssh, verifies
  uninstall-on-host.sh          removes on the live host over ssh, asserts clean revert
  version-sorts-after.php       strcmp version guard, shared by release.yml and tests
tests/run.php                   hand-rolled PHP assert runner
tests/fixtures/smbstatus.json   synthetic smbstatus -j JSON (RFC 5737 IPs, fake titles)
scripts/check-md5.sh            build + require the .plg &md5; to match (CI and release)
.gitea/workflows/ci.yml         lint + test + release-readiness on push/PR (Gitea runner)
.github/workflows/release.yml   on a mirrored v* tag: version guard, rebuild, md5 check, GitHub Release (dry run via dispatch)
```

## The `activity` table (Phase 2)

SQLite at `db_path` (default `/mnt/cache/appdata/dormouse/manifest.db`, never
on flash), created idempotently by `dormouse_open_db()`, WAL mode:

```sql
CREATE TABLE activity (
  ts INTEGER NOT NULL, rel_path TEXT NOT NULL, share TEXT NOT NULL,
  client_ip TEXT NOT NULL, source TEXT NOT NULL, event TEXT NOT NULL,
  count INTEGER NOT NULL DEFAULT 1,
  reads_delta INTEGER, writes_delta INTEGER  -- added 0.2.1, nullable, NULL on pre-0.2.1 rows
);
CREATE TABLE stats (key TEXT PRIMARY KEY, value INTEGER NOT NULL DEFAULT 0);
```

`reads_delta`/`writes_delta` are added by `dormouse_migrate_activity_schema()`,
guarded by `PRAGMA table_info` so it is a no-op on an already-migrated db —
called unconditionally from `dormouse_open_db()`. **`dormouse-api.php` does
not go through `dormouse_open_db()`** — it opens the db directly
`SQLITE3_OPEN_READONLY` (it must never write) — so the migration only
actually happens when `dormoused` (re)starts, which the `.plg` install block
does immediately on every upgrade. In the brief window on a fresh upgrade
before the daemon has started against an existing 0.2.0-shaped db, a poll of
the API endpoint hits a `no such column` exception on `reads_delta`, which
its existing try/catch turns into a generic "manifest database unavailable"
JSON response rather than a crash — never assume the api endpoint migrates
anything itself. The same applies to `zfs_io` (0.2.2): a pre-0.2.2 db has no
such table at all, so `dormouse_build_status()`'s call into
`dormouse_zfs_24h()` throws `no such table: zfs_io` in that same brief
upgrade window, caught by the same try/catch, same generic error — not a
separate gap to fix.

One deviation from the PLAN.md §4 `activity` shape: a `count` column, needed
because inotify `ACCESS` events are coalesced (at most one row per file per
60s, with a count) rather than one row per event — a 20 GB read would
otherwise be ~20,000 rows. `stats` holds `watch_count`,
`watch_last_refresh_ts`, and `inotify_overflow_count`.

SMB rows are **transitions**, not one row per open handle per poll: `open` on
first sight of a handle, `dirwatch` instead of `open` for a share-root handle
(Plex's long-lived directory watch — Samba reports its filename as `.` or
possibly `""`; both are treated as dirwatch, verified against a live capture
for the `""` case only, see G5), `close` when a previously-seen handle
disappears. Client IP is resolved via the matching `tcon.machine`, never via
`sessions[].username` (guests are all `nobody`).

`inotify_overflow_count` increments whenever an inotifywait `%e` field
contains the substring `OVERFLOW`; this is unverified against a real
`IN_Q_OVERFLOW` until gate G4 actually produces one (unlikely — the daemon's
drain loop should comfortably outrun a spinning raidz1's read rate).

The settings page (`Dormouse.page`) never touches the db directly — it polls
`dormouse-api.php` (urlencoded `$.post` with `csrf_token`, per the WebGUI
rules above) every 15s, which opens the db `SQLITE3_OPEN_READONLY` and
returns JSON built by `dormouse_build_status()`.

## Source C — disk spin-state log (0.2.1)

Verified on the host, 14 Sep 2026 (do not re-derive):

- Unraid writes nothing to syslog about ZFS pool disk spin-up/down. The only
  data is `/var/local/emhttp/disks.ini` (tmpfs, maintained by `emhttpd`):
  per-disk sections `["snowflake"]`…`["snowflake6"]` (plus `parity`, `cache`,
  `flash`, etc.) with `device="sdX"`, `spundown="0|1"`, `numReads=`,
  `numWrites=`. A live capture over ssh (read-only `cat`/`grep`, no write)
  showed plain LF line endings, not CRLF — `dormouse_parse_disks_ini()`
  tolerates a trailing `\r` anyway, cheaply, in case a future build changes
  that.
- **Spin state comes from `disks.ini`, never from touching the pool.** Reading
  it is tmpfs-only and costs nothing; `stat()`-ing anything under
  `/mnt/snowflake` on a timer is the thing this whole project exists to avoid
  (see "Never stat pool paths on a timer" above).
- `smartctl -n standby -i /dev/sdX` returns rc 2 when a disk is in standby and
  rc 0 when active, without waking it. Used **only** as a cross-check on a
  detected `disks.ini` transition (`dormouse_smartctl_standby()` /
  `dormouse_smartctl_agrees()` in `lib.php`) — never as a regular poll.
  Disagreements increment the `disk_state_disagreements` stats key and log a
  warning line.

`pool_disk_prefix` (config key, default `snowflake`) selects which `disks.ini`
section names are tracked: `dormouse_pool_disk_names()` filters to names
starting with that prefix. On the daemon's existing 15s tick (same cadence as
the `smbstatus` poll, no separate timer), `dormouse_disk_poll_tick()` diffs
each tracked disk's `spundown`/`numReads`/`numWrites` against the previous
tick: unseen disk → one `event='state'` baseline row (this is how the daemon
start baseline happens — no separate startup code path, the first tick's
`$diskState` is simply empty); a `spundown` flip → one `event='spinup'`
(1→0) or `'spindown'` (0→1) row carrying `reads_delta`/`writes_delta` since
the previous tick; no change → no row. A delta that would come out negative
(a counter reset or wrap) is recorded as `NULL`, never a huge unsigned
number. `count` is overloaded for `source='disk'` rows: it holds the reads delta
(0 for a baseline `state` row), not an event-coalescing count as it does for
`inotify`/`smb` rows — this is a deliberate divergence from the rest of the
table, so don't "fix" the `Dormouse.page` Count column into matching
`smb`/`inotify` semantics without checking the source column first.

`stats` also gets `disk_last_poll_ts` and, per disk,
`disk_state_<name>` / `disk_state_<name>_since` (maintained every tick a row
was written, read back by `dormouse_build_disk_states()` for the settings
page's current-state line).

`inotifywait`'s event set also gained `close_write` this release, recorded
uncoalesced (one row per write, unlike the coalesced `access` rows) as
`source='inotify', event='write'`.

`dormouse_build_spin_events()` returns the last 30 `spinup`/`spindown` rows,
each with the distinct non-disk activity rows in the 60s window immediately
before and after it — shown on the settings page next to the transition so a
spin-up can be read next to the opens that plausibly caused it.

## Source D — ZFS-level I/O attribution (0.2.2)

**Why:** the 0.2.1 spin log shows spin-ups with no activity in any watched
share. `inotify` only sees file opens under watched roots, so ZFS-internal
I/O — metadata, snapshots, scrubs, the root dataset, anything on a share we
don't watch — is invisible to Sources A/B. The kernel's own ZFS counters see
everything, and reading them never touches the disks.

Verified on the host, 15 Sep 2026 (do not re-derive; the fixtures under
`tests/fixtures/objset-*` and `tests/fixtures/diskstats` copy the exact
line structure, column headers, field order and field widths of a live
`ssh cat` of `/proc/spl/kstat/zfs/snowflake/objset-0x36` (root),
`objset-0x2d7` (`Filing Cabinet`), `objset-0x247` (`Content`), and
`/proc/diskstats` — read-only, no write. Only the numeric magnitudes are
replaced with distinguishable test values; the shape is real, not guessed):

- **`/proc/spl/kstat/zfs/<pool>/objset-0x*`** — one file per dataset: a
  numeric header line, a `name type data` column-header line, then rows
  shaped `key type value` (e.g. `dataset_name 7 snowflake/Games`, `writes 4
  <n>`, `nwritten 4 <bytes>`, `reads 4 <n>`, `nread 4 <bytes>`, `nunlinks 4
  <n>`, `nunlinked 4 <n>`, plus `zil_*` rows that are ignored).
  `dormouse_parse_objset_file()` splits each row into at most 3 tokens
  (`key`, `type`, rest-of-line) specifically so a dataset name containing a
  space — `snowflake/Filing Cabinet` — survives intact as the value. Datasets
  on this host: `snowflake` (root) and `snowflake/<Share>` for each of
  Content, Dashcam, Downloads, Filing Cabinet, Games, Kieren, Movies, Music,
  Photos, Teegan.
- **`/proc/diskstats`** — standard kernel format;
  `dormouse_parse_diskstats()` reads fields 4/6/8/10 (reads completed,
  sectors read, writes completed, sectors written) keyed by device name.
  The six pool devices (`sdc`–`sdh` on this host) are mapped to disk names
  via the same `disks.ini` parser Source C already uses
  (`dormouse_zfs_device_stats()`) — never hardcoded, since a live-boot device
  reassignment would silently break a hardcoded map.
- **`zpool iostat` / `zpool events` are not polled.** They would mean a fork
  per tick for numbers the kstats already expose directly. **Standing rule:
  ZFS state comes from kstats, never from a `zpool`/`zfs` command on a
  timer** — the same "never stat pool paths on a timer" principle as the
  rest of this file, extended to shelling out on a timer as well.
- Counters are monotonic since pool import/kernel boot. A negative delta
  (counter reset, e.g. a pool re-import) is recorded as `NULL`, never a
  huge unsigned number — the same rule Source C already applies.

On the daemon's existing 15s tick, `dormouse_zfs_poll_tick()` diffs every
dataset and device against the previous tick: first sight of a name is a
silent baseline (state recorded, no row — unlike Source C's baseline `state`
row); a tick where every field's delta is zero writes nothing; otherwise one
row per dataset/device into the new `zfs_io` table (`kind='dataset'|'device'`,
`name` = dataset name or `<diskname>/<sdX>`, `reads`/`nread`/`writes`/
`nwritten` deltas, `unlinks` = the `nunlinks` delta for datasets, always
`NULL` for devices). Device bytes are sectors × 512. A device absent from a
given tick's `/proc/diskstats` (`stats === null` from
`dormouse_zfs_device_stats()`) is skipped for that tick and its prior state
is dropped, rather than guessing a delta across the gap. `stats` key
`zfs_last_poll_ts` advances every tick regardless of whether any row was
written. Trimmed by the same `activity_retain_days` job as `activity`, via
`dormouse_trim_zfs_io()`.

`dormouse_zfs_window()` sums per-dataset/per-device deltas over an arbitrary
`[from, to)` range; `dormouse_build_spin_events()` calls it with the same
±60s window already used for the `before`/`after` activity rows, so a
spin-up with zero watched-share activity now shows e.g. `snowflake (root):
2,201 reads, 88 MB` instead of nothing. `dormouse_zfs_24h()` feeds the
settings page's "ZFS I/O — last 24h by dataset" table. Config:
`zfs_pool_name` (default `snowflake`) and `zfs_kstat_dir` (default
`/proc/spl/kstat/zfs`, overridable for tests) — both cfg keys, not env vars,
matching `pool_disk_prefix`'s existing pattern rather than the process-path
env-var overrides (`DORMOUSE_PIDFILE` etc.).

## Movies is out of scope

Never watched, never listed as a watched share, never acted on — PlexCache-D
already covers it and a single-file movie directory gets nothing from
Dormouse's sequential-sibling model. The five `shareUseCache=yes` shares
(Kieren, Teegan, Downloads, Filing Cabinet, Photos) have `snowflake` as their
*secondary* pool, so the stock Unraid mover already moves cache→snowflake for
them on its own schedule — a Phase 4+ interlock consideration (Dormouse
promoting a file the stock mover is about to demote back), nothing to
implement yet.

## Test command

```
php tests/run.php
```

That is the full suite, and it runs in CI only: it starts and kills real processes, which this
dev container shares with the live host. Locally, once PRs #4 and #5 have merged, run only
matching tests with `DORMOUSE_TEST_FILTER=<pattern> php tests/run.php` (a substring of the test
name). That is safe for the build and install-block tests: `reproducible`, `build-plugin.sh` and
`plg install:` (the last runs the real install INLINE against recording stubs and starts nothing).
Anything else, the `rc.dormouse` and `dormoused` tests above all, stays in CI.

## Deploy and verify

1. `scripts/install-on-host.sh [version] [--forced]` — installs or upgrades on
   the live host, waiting for the CDN copy of `dormouse.plg` to converge on the
   released version and md5 first (raw.githubusercontent.com caches for up to
   5 minutes).
2. `scripts/uninstall-on-host.sh` — removes and asserts a clean revert: no
   flash `.plg`, no flash plugin dir, no installed tree, no plugin-manager
   registration, no daemon process, no pid file, no package entry.
3. After tagging, watch the GitHub release workflow with `gh run list -R kmbrimble/unraid-dormouse` / `gh run watch <id>
   -R kmbrimble/unraid-dormouse --exit-status` (a read on GitHub, allowed). CI for PRs is on Gitea: `gt run list -R kmbrimble/unraid-dormouse`.

## Phase roadmap

Each phase is one `/feature` invocation, ends green in CI with a cut release,
and is verified on the live host before the next phase starts.

- **Phase 1 — scaffold and release pipeline. (0.1.0, shipped.)**
- **Phase 2 — observation mode (no moves). (0.2.0–0.2.3 shipped; current phase.)** Both
  event sources unified into an `activity` table, plus disk spin state (Source C, 0.2.1), ZFS
  kstat attribution (Source D, 0.2.2) and boot-order safety (0.2.3); moves impossible, not
  merely disabled (enforced by a grep test over `plugin/`). The evidence week started at the
  0.2.0 install (2026-09-13) to decide which shares beyond Content benefit; its conclusion is
  not recorded in this repo. Full Phase 1/2 write-ups: `docs/history.md`.
- **Phase 3 — manifest, rules engine, dry-run promote.** SQLite schema, startup
  reconciliation, both rule classes, fill guard, full decision logging. Still
  moves nothing.
- **Phase 4 — live promote.** Copy/verify/hold-rename, open-handle veto,
  interlocks. Single share (Content), single rule class (`sequential`).
- **Phase 5 — demote, eviction, orphan sweep.**
- **Phase 6 — settings GUI and log viewer.**
- **Phase 7 — interlocks and coexistence.** PlexCache-D exclusion export,
  mover/parity pause, a second rule class enabled based on Phase 2 evidence.

## Phase 2 verification gates — outcomes still in force

Gates G1–G5 ran on the host 2026-09-13 after `install-on-host.sh 0.2.0`; the full write-up
(probe commands, cleanup, measurements) is in `docs/history.md`. What later phases depend on:

- **G1:** shfs unions the `only`-on-snowflake share's cache copy into `/mnt/user/<share>`, as
  PLAN.md assumed.
- **G2 (decision for Phase 4):** a dot-prefixed top-level pool directory is not turned into a
  share, so the preferred hold location `/mnt/snowflake/.dormouse/hold/<share>/<relpath>` is
  usable as-is; the in-share fallback is not needed.
- **G3:** `inotifywait -m -r` auto-adds directories created after start on this host — not
  used by Phase 2's fixed-depth-plus-refresh design, a live option for Phase 3+.
- **G4:** a ≥10 GB read produced no overflow and one coalesced `access` row; **`IN_Q_OVERFLOW`
  handling remains code-reviewed but not exercised.**
- **Still open — G5 dirwatch half:** the root-directory dirwatch discrimination (`.` or `""`
  → `dirwatch`) has **not been confirmed against a live Plex root handle**. Recheck once Plex
  is actively browsing; if Samba reports something other than `.`/`""` for this host's actual
  Plex session, `dormouse_parse_smbstatus_json()` in `plugin/scripts/lib.php` needs a third
  case added.
- **End-to-end:** reads via `/mnt/user/Content/...` route down to the pool path the daemon
  watches (shfs → ZFS-side watch confirmed).
- Also still open: a real boot-time install (see "Boot order and lifecycle").
