#!/bin/bash
# Decides whether it is safe to start dormoused right now: the array must
# already be STARTED (per var.ini) AND both cache_root and pool_root (read
# from dormouse.cfg, never hardcoded) must be real mountpoints.
# dormouse.plg's install step uses this to avoid starting dormoused during a
# boot-time install, which runs before the cache/snowflake pools are mounted
# (confirmed from /boot/logs/syslog, 2026-09-15) — starting early would let
# dormoused create manifest.db on the RAM rootfs, silently covered by the
# later cache mount and lost at shutdown. Exit 0 if safe to start now, 1
# otherwise (the `started` event hook is what actually starts it in the
# boot case).
set -u

VARINI="${DORMOUSE_VARINI:-/var/local/emhttp/var.ini}"
CFG="${DORMOUSE_CFG:-/boot/config/plugins/dormouse/dormouse.cfg}"

array_started() {
    [[ -f "$VARINI" ]] && grep -q 'mdState="STARTED"' "$VARINI"
}

# Reads key=value from dormouse.cfg, falling back to $2 if the file or key
# is absent — mirrors dormouse_load_config()'s defaults without requiring
# PHP in this bash-only install-time context.
cfg_value() {
    local key="$1" default="$2" line
    if [[ -f "$CFG" ]]; then
        line="$(grep -E "^${key}=" "$CFG" | tail -n1)"
        if [[ -n "$line" ]]; then
            printf '%s' "${line#*=}"
            return
        fi
    fi
    printf '%s' "$default"
}

# $2 is a test override ('1'/'0'/unset) for whether $1 is mounted. Checked
# with -n, not `:-`/`?:`, so an explicit "0" override is honoured rather
# than falling through to the real check.
is_mounted() {
    local dir="$1" override="$2"
    if [[ -n "$override" ]]; then
        [[ "$override" == "1" ]]
        return
    fi
    mountpoint -q "$dir"
}

CACHE_ROOT="$(cfg_value cache_root /mnt/cache)"
POOL_ROOT="$(cfg_value pool_root /mnt/snowflake)"

array_started \
    && is_mounted "$CACHE_ROOT" "${DORMOUSE_TEST_CACHE_MOUNTED:-}" \
    && is_mounted "$POOL_ROOT" "${DORMOUSE_TEST_POOL_MOUNTED:-}"
