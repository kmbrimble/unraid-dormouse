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

### Future-phase constraint — never stat pool paths on a timer

From Phase 2 onward, the daemon must not `stat()` or otherwise poll paths on
`/mnt/snowflake` on a timer — that spins the disks the plugin exists to keep
asleep. State comes from the event stream (`inotifywait`, `smbstatus -j`) and
from the cache side only. This is easy to violate by accident in a
reconciliation or housekeeping loop; call it out explicitly in review whenever
that code is touched.

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

## Dev-environment fact: this container shares the live host

Verified 2026-09-13: `docker images` in this dev container lists the live
production containers (Immich, Radarr, PlexCache-D, etc.), and a process
started in this container is visible via `ps` over `ssh
root@192.168.0.10` under its own real pid. This container is not an isolated
build sandbox — it shares the host's docker daemon and process namespace with
the actual Unraid box `scripts/*-on-host.sh` ssh into. Two consequences:

- Never `docker run` anything here to get a tool (e.g. a throwaway PHP image)
  — it starts a real container on the production NAS. Missing local tooling
  (this container has no PHP) has to be worked around some other way, or left
  to CI, which runs on an isolated GitHub-hosted runner.
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
  Dormouse.page                 settings page, registered under Settings > Utilities
  scripts/rc.dormouse           start|stop|restart|status, supervises dormoused
  scripts/dormoused             PHP no-op daemon (Phase 1)
scripts/
  build-plugin.sh <version>     builds dist/dormouse-<version>.txz
  install-on-host.sh            installs/upgrades on the live host over ssh, verifies
  uninstall-on-host.sh          removes on the live host over ssh, asserts clean revert
  version-sorts-after.php       strcmp version guard, shared by release.yml and tests
tests/run.php                   hand-rolled PHP assert runner
.github/workflows/ci.yml        lint + test on push/PR
.github/workflows/release.yml   version guard, build, tag, GitHub Release on push to main
```

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

- **Phase 1 — scaffold and release pipeline. (this release, 0.1.0.)** Repo,
  `.plg` skeleton, packaging script, release workflow with the strcmp version
  guard, empty settings page, `rc.dormouse` starting/stopping a no-op daemon.
  Installed and uninstalled on the live host, uninstall verified to revert
  cleanly, before any tiering logic exists.
- **Phase 2 — observation mode (no moves).** Both event sources unified into an
  `activity` table; settings page shows a live activity view; moves impossible,
  not merely disabled. Run for a week to gather evidence on which shares beyond
  Content would benefit.
- **Phase 3 — manifest, rules engine, dry-run promote.** SQLite schema, startup
  reconciliation, both rule classes, fill guard, full decision logging. Still
  moves nothing.
- **Phase 4 — live promote.** Copy/verify/hold-rename, open-handle veto,
  interlocks. Single share (Content), single rule class (`sequential`).
- **Phase 5 — demote, eviction, orphan sweep.**
- **Phase 6 — settings GUI and log viewer.**
- **Phase 7 — interlocks and coexistence.** PlexCache-D exclusion export,
  mover/parity pause, a second rule class enabled based on Phase 2 evidence.
