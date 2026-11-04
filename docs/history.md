# Dormouse — history

Historical narrative split out of `CLAUDE.md` on 2026-11-04, copied verbatim (wording, dates and section
cross-references are as they stood in `CLAUDE.md` at d6bba1c; "below"/"above" refer to that file). Current rules,
gates and open items stay in `CLAUDE.md`; nothing here is a rule that is still in force unless `CLAUDE.md` restates it.

## Non-negotiable constraint 7 — original wording, with the live evidence (verified 2026-09-15)

7. **Plugins install before the array's pools mount at boot — never start
   the daemon unconditionally from the `.plg` install step.** Verified live
   2026-09-15: `/boot/logs/syslog` for the 12 Sep boot shows
   `rc.local: plugin: installing: …` from 11:20:38-11:22:00, and
   `emhttpd: mounting /mnt/cache` only at 11:22:14 — plugins install first,
   pools mount after. `findmnt /mnt` → `rootfs[/mnt] rootfs`: `/mnt` itself
   is a bind mount of rootfs (same `st_dev` as `/`, confirmed via
   `stat -c '%n dev=%d' / /mnt /mnt/cache /mnt/snowflake` on the host,
   2026-09-15 — `/`=2, `/mnt`=2, `/mnt/cache`=44, `/mnt/snowflake`=45), so
   `mountpoint -q /mnt` is true even before any pool is mounted. A guard
   that walks up an unmounted target's path looking for the nearest
   directory `mountpoint -q` accepts will stop at `/mnt` and wrongly report
   "mounted" — compare device IDs against root instead (see
   `dormouse_db_path_mounted()` in `lib.php`), never `mountpoint -q` on an
   intermediate ancestor. The install step, the daemon itself, and the
   emhttpd `started`/`stopping_svcs` event hooks together are what make
   0.2.3 safe — see "Boot order and lifecycle" below.

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
  which runs on the isolated Gitea runner VM (and the tag release on a GitHub-hosted runner). (A global PreToolUse hook
  only auto-allows `docker rm`/`docker stop` on `smoketest-` prefixed names.)
- Any `pgrep -f` (or similar) run against this host will match THIS agent
  session's own process if the pattern is a substring of the task prompt —
  the prompt is visible in `ps` output verbatim. `scripts/uninstall-on-host.sh`
  hit this twice while being debugged: first matching a bare `dormoused`
  substring, then matching its own `pgrep -f '...'` invocation's quoted
  argument after being narrowed to a full path. Match a specific-enough
  pattern and bracket one character of it (`[d]ormoused`) so pgrep's own
  invoking shell can't self-match.

## Phase roadmap — Phase 1 and Phase 2 entries as written at the 0.2.0 release

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
