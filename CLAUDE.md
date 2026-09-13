# Dormouse — CLAUDE.md

The build plan, kept out of the repo, is the authority for this project. `PLAN.md`
at the repo root is gitignored (it names LAN hosts) and is never committed. This
file is derived from it and from the repo as it stands — if the two conflict,
re-derive this file from the current repo state, not from memory of an earlier
plan.

## Non-negotiable constraints

Hard-won from SecretsMan and NetMan. Not optional, not footnotes.

1. **Versions are compared with `strcmp`, not semver.** `dynamix.plugin.manager`'s
   `plugin` script does a plain string compare, so `1.0.10` sorts *older* than
   `1.0.9`. Keep every version component single-digit or zero-padded. Never cross
   from date-based to semantic versioning without `plugin install <url> forced`.
   The release workflow refuses to publish a version that does not sort after the
   previous tag as a plain string — enforced by `scripts/version-sorts-after.php`,
   shared between `.github/workflows/release.yml` and `tests/run.php`.
2. **A `FILE` block with no `<MD5>`/`<SHA256>` is never overwritten.** A plain
   `<INLINE>` file is written once and skipped forever. Do not add a versionless
   md5 sidecar and re-check the download against it in a script — that fails
   every upgrade after the first with a spurious mismatch. No bash-level md5
   check exists anywhere in this repo; the plugin manager verifies the `.txz`
   against the `<MD5>` on its own `FILE` block, and that is the only check.
3. **The Plugins tab description comes from the installed `README.md`.**
   `plugin/README.md` stays in the stock shape: `**Dormouse**`, a blank line, one
   paragraph, no headings. Packaging the repo README puts an H1 into a shared
   table cell and blows out the row height.
4. **Exercise the uninstall path deliberately.** Every script a `.plg` `FILE`
   block invokes must have a shebang and be gated on `[[ -f script ]]`, never
   `[[ -x script ]]` — SecretsMan's uninstall once silently never ran because its
   script had no shebang and was gated on `-x`.
5. **`upgradepkg` may leave both versions in `/var/log/packages/`.** Cosmetic
   (tmpfs, rebuilt at boot), not a bug.
6. **`gh release list` is not evidence about what is on the host.** Read
   `/boot/config/plugins/dormouse.plg` on the host itself.

### WebGUI rules

- **Do not use multipart `FormData`/`fetch()`** — incompatible with the host's
  nginx `auth_request` subrequest setup. Plain urlencoded `$.post` is the only
  proven-working POST path in the Unraid webGui.
- **Do not add a custom CSRF check.** `local_prepend.php` consumes and unsets
  `$_POST['csrf_token']` before plugin code runs; a redundant check always 403s.
- **Functions reachable from both CLI and web must not reference `STDOUT`,
  `STDERR` or `$argv`.** `tests/run.php` runs under the CLI SAPI where these
  exist, which makes this bug class structurally invisible to the test suite.

### Release requirements (every build)

- `.plg` published as a GitHub Release with the `.txz` attached and the real
  `<MD5>` written onto the FILE block.
- `CHANGELOG.md` updated (Keep a Changelog format).
- `README.md` (repo) and `plugin/README.md` (stock one-liner) both current.
- `CLAUDE.md` current — derived from the repo, never invented; cite verification
  method and date inline.
- Releases are cut automatically on merge to `main` — no asking before shipping.
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
  GitHub-hosted runner, which has PHP, via `scripts/version-sorts-after.php`.

## Dev-environment fact: this container runs ON the live host

Verified 2026-09-13: this dev container (`claude-code`) is itself a Docker
container on the production Unraid box that `scripts/*-on-host.sh` ssh into,
with the host's docker socket bind-mounted in. So `docker images`/`docker ps`
here show the live production containers, and a process started in this
container is visible in `ps` over `ssh root@192.168.0.10` under its real pid —
because the host sees every container's processes, not because namespaces are
shared (the container still has its own PID namespace). Two consequences:

- `docker run` here starts a real container on the production NAS. Don't reach
  for a throwaway image (e.g. a PHP image) to get missing local tooling — this
  container has no PHP and no `xz`; leave PHP tests and the `.txz` build to CI,
  which runs on an isolated GitHub-hosted runner. (A global PreToolUse hook
  only auto-allows `docker rm`/`docker stop` on `smoketest-` prefixed names.)
- Any `pgrep -f` (or similar) run against this host will match THIS agent
  session's own process if the pattern is a substring of the task prompt —
  the prompt is visible in `ps` output verbatim. `scripts/uninstall-on-host.sh`
  hit this twice while being debugged: first matching a bare `dormoused`
  substring, then matching its own `pgrep -f '...'` invocation's quoted
  argument after being narrowed to a full path. Match a specific-enough
  pattern and bracket one character of it (`[d]ormoused`) so pgrep's own
  invoking shell can't self-match.

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
scripts/
  build-plugin.sh <version>     builds dist/dormouse-<version>.txz
  install-on-host.sh            installs/upgrades on the live host over ssh, verifies
  uninstall-on-host.sh          removes on the live host over ssh, asserts clean revert
  version-sorts-after.php       strcmp version guard, shared by release.yml and tests
tests/run.php                   hand-rolled PHP assert runner
tests/fixtures/smbstatus.json   synthetic smbstatus -j JSON (RFC 5737 IPs, fake titles)
.github/workflows/ci.yml        lint + test on push/PR
.github/workflows/release.yml   version guard, build, tag, GitHub Release on push to main
```

## The `activity` table (Phase 2)

SQLite at `db_path` (default `/mnt/cache/appdata/dormouse/manifest.db`, never
on flash), created idempotently by `dormouse_open_db()`, WAL mode:

```sql
CREATE TABLE activity (
  ts INTEGER NOT NULL, rel_path TEXT NOT NULL, share TEXT NOT NULL,
  client_ip TEXT NOT NULL, source TEXT NOT NULL, event TEXT NOT NULL,
  count INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE stats (key TEXT PRIMARY KEY, value INTEGER NOT NULL DEFAULT 0);
```

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

## Deploy and verify

1. `scripts/install-on-host.sh [version] [--forced]` — installs or upgrades on
   the live host, waiting for the CDN copy of `dormouse.plg` to converge on the
   released version and md5 first (raw.githubusercontent.com caches for up to
   5 minutes).
2. `scripts/uninstall-on-host.sh` — removes and asserts a clean revert: no
   flash `.plg`, no flash plugin dir, no installed tree, no plugin-manager
   registration, no daemon process, no pid file, no package entry.
3. Watch the release workflow with `gh run list` / `gh run watch <id>
   --exit-status` after every push to `main`.

## Phase roadmap

Each phase is one `/feature` invocation, ends green in CI with a cut release,
and is verified on the live host before the next phase starts.

- **Phase 1 — scaffold and release pipeline. (0.1.0, shipped.)** Repo,
  `.plg` skeleton, packaging script, release workflow with the strcmp version
  guard, empty settings page, `rc.dormouse` starting/stopping a no-op daemon.
  Installed and uninstalled on the live host, uninstall verified to revert
  cleanly, before any tiering logic exists.
- **Phase 2 — observation mode (no moves). (this release, 0.2.0, current.)**
  Both event sources unified into an `activity` table; settings page shows a
  live activity view; moves impossible, not merely disabled (enforced by a
  grep test over `plugin/`). Installed on the live host over 0.1.0, verified
  the cfg upgrade and the new daemon; the evidence week starts at install and
  runs for a week from there to decide which shares beyond Content benefit.
  See "Live-host verification gates — Phase 2" below for results.
- **Phase 3 — manifest, rules engine, dry-run promote.** SQLite schema, startup
  reconciliation, both rule classes, fill guard, full decision logging. Still
  moves nothing.
- **Phase 4 — live promote.** Copy/verify/hold-rename, open-handle veto,
  interlocks. Single share (Content), single rule class (`sequential`).
- **Phase 5 — demote, eviction, orphan sweep.**
- **Phase 6 — settings GUI and log viewer.**
- **Phase 7 — interlocks and coexistence.** PlexCache-D exclusion export,
  mover/parity pause, a second rule class enabled based on Phase 2 evidence.

## Live-host verification gates — Phase 2

All run 2026-09-13, on the host, immediately after `install-on-host.sh 0.2.0`
upgraded 0.1.0 in place. Each probe cleaned up after itself (confirmed below).

- **G1 — shfs union probe: PASS.** `touch /mnt/cache/Content/.dormouse-probe`,
  confirmed visible at `/mnt/user/Content/.dormouse-probe`, removed it,
  confirmed gone from `/mnt/user` too. The `only`-on-snowflake share's cache
  copy is transparently unioned in, as PLAN.md assumed.
- **G2 — hold-directory visibility: does NOT auto-promote.**
  `mkdir /mnt/snowflake/.dormouse`, waited 10s: absent from both
  `ls /mnt/user/` and `ls /boot/config/shares/`. `rmdir`'d it. Unraid does
  **not** turn a dot-prefixed top-level pool directory into a share on this
  host — the preferred hold location
  (`/mnt/snowflake/.dormouse/hold/<share>/<relpath>`) from PLAN.md §4 is
  usable as-is; the in-share fallback is not needed. Decision for Phase 4.
- **G3 — `inotifywait -m -r` new-directory behaviour: auto-adds.** Under
  `/mnt/snowflake/Content/.dormouse-probe-g3/`, started `inotifywait -m -r`,
  created a subdirectory, created and read a file inside it. Output included
  `Watching new directory .../newsub/` and delivered CREATE/OPEN/ACCESS/
  CLOSE_NOWRITE for the new file. `-r` does pick up directories created after
  start on this host/inotify-tools version — not used by Phase 2's
  fixed-depth-plus-periodic-refresh design, but recorded as a live option for
  Phase 3+. Probe dir removed after.
- **G4 — `IN_Q_OVERFLOW` under a real large read: no overflow, daemon
  survived.** Found a real Content episode ≥10GB
  (`find ... -size +10G -print -quit`), `cat` to `/dev/null` (19s). After the
  60s coalescing window: `inotify_overflow_count` stat key absent (never
  incremented), one coalesced `access` row with `count=28004`, plus one
  `open` and one `close` row — not ~28,000 individual rows. Daemon's pid was
  unchanged before/after (no crash/restart). `IN_Q_OVERFLOW` handling
  remains code-reviewed but not exercised — the drain loop comfortably
  outran this read.
- **G5 — Plex directory-watch handles: file-open half confirmed, dirwatch
  half not observed live.** No Plex session was connected during this
  window, so no root-directory handle existed to capture. Generated a real
  SMB file open instead (`smbclient //127.0.0.1/Content -N -c 'get ...'`,
  chosen to run ~18s so multiple 15s daemon polls would catch it): live
  `smbstatus -j` showed the open handle across 6 consecutive polls, and the
  `activity` table recorded `source=smb, event=open` then `close`, with
  `client_ip=127.0.0.1` (resolved via `tcon.machine`, confirming the IP path
  works end to end on real Samba output, not just the fixture). The
  dirwatch-vs-open discrimination itself (root handle → `.` or `""` →
  `dirwatch`) is unit-tested against the earlier live capture's shape but
  **was not confirmed against a live Plex root handle** — recheck once Plex
  is actively browsing during the evidence week; if Samba reports something
  other than `.`/`""` for this host's actual Plex session, `dormouse_parse_smbstatus_json()`
  in `plugin/scripts/lib.php` needs a third case added.
- **End-to-end: PASS, both paths.** A `cat` of a small Content file via
  `/mnt/user/Content/...` (not `/mnt/snowflake/...` directly) produced
  `open`/`close`/coalesced-`access` rows on the ZFS-side watch within the
  minute — confirms shfs reads route down to the pool path the daemon
  actually watches. (The ≥10GB G4 read was done directly via
  `/mnt/snowflake/...` and also confirmed.)
