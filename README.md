# Dormouse

*"Keeps your disks asleep."*

Hot-file tiering for Unraid: detect sustained reads on slow storage, pull the
surrounding working set onto fast storage, and put it back when it goes cold.
Built for a ZFS pool where every read spins the whole pool, and for readers —
like a guest device browsing files anonymously over SMB — that a Plex-API-based
tool like PlexCache-D can't see. Dormouse is reactive and client-agnostic: it
watches filesystem and SMB activity directly, so it works for photos, Time
Machine backups, or anything else regularly read off slow storage, not just
Plex libraries.

**Status: Phase 2 — observation only.** The daemon polls `smbstatus -j`,
watches pool-side directories with `inotifywait` (including file writes), and
tracks per-disk spin state from `disks.ini`, recording all three into a
SQLite activity log shown live on the settings page. It moves nothing —
no move/copy/delete code exists anywhere in the shipped plugin tree, and a
test enforces that.

## Relationship with PlexCache-D

[PlexCache-D](https://github.com/StudioNirin/PlexCache-D) proactively prefetches Plex's On
Deck/watchlist items before playback starts, but it's blind to any client that
isn't Plex — including a file-browser app connecting anonymously over SMB,
which is a real, regular viewing path on the host this was built for. Dormouse
is reactive instead: it promotes files based on observed reads from any
client. The two are complementary, not competing, and later phases will make
Dormouse export its open-handle information so PlexCache-D never demotes a
file mid-playback for a session it can't otherwise see.

## Install

Add this URL in the Unraid Plugins tab ("Install Plugin"):

```
https://raw.githubusercontent.com/kmbrimble/unraid-dormouse/main/dormouse.plg
```

## Licence

GPL-2.0 — see [LICENSE](LICENSE).
