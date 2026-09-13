#!/bin/bash
# Remove dormouse from the live Unraid host over ssh and assert the revert
# was clean. Pre-release, nothing is preserved on uninstall — any leftover
# here is a packaging bug, not intended state, and this fails loudly on one.
set -euo pipefail

HOST="192.168.0.10"
SSH_KEY="/root/.ssh/unraid_secretsman"
SSH=(ssh -o StrictHostKeyChecking=no -i "$SSH_KEY" "root@$HOST")

echo "removing dormouse from $HOST..."
"${SSH[@]}" "plugin remove dormouse.plg"

echo "verifying clean revert..."
FAIL=0

check_absent() {
    local desc="$1" cond="$2"
    if "${SSH[@]}" "$cond"; then
        echo "  FAIL: $desc still present" >&2
        FAIL=1
    else
        echo "  OK: $desc absent"
    fi
}

check_absent "/boot/config/plugins/dormouse.plg" "[[ -f /boot/config/plugins/dormouse.plg ]]"
check_absent "/boot/config/plugins/dormouse/" "[[ -d /boot/config/plugins/dormouse ]]"
check_absent "/usr/local/emhttp/plugins/dormouse/" "[[ -d /usr/local/emhttp/plugins/dormouse ]]"
check_absent "/var/log/plugins/dormouse.plg" "[[ -f /var/log/plugins/dormouse.plg ]]"

# Match the installed daemon's full absolute path, not the bare word
# "dormoused" — this host also runs long-lived processes (e.g. an agent
# session) whose command line can legitimately quote text that mentions
# "dormoused", and a bare `pgrep -f dormoused` matches those too. The
# absolute install path is specific enough to avoid that, but pgrep -f also
# matches its OWN invoking shell (`sh -c "pgrep -f '...'"` has the pattern
# text in ITS argv, and pgrep only excludes its own pid, not its parent's) —
# so the single-character bracket still has to stay, on the last path
# segment, to keep the invoking shell's literal argument from self-matching.
check_absent "dormoused process" "pgrep -f '/usr/local/emhttp/plugins/dormouse/scripts/[d]ormoused'"

# rc.dormouse's stop sends SIGTERM to dormoused, which terminates its own
# inotifywait child as part of its shutdown handler before dormoused exits —
# so this must be absent too, or Phase 2's watcher is left orphaned on the
# pool after every uninstall (and every plain `rc.dormouse stop`).
check_absent "inotifywait child process" "pgrep -f 'inotifywait.*--fromfile /var/run/[d]ormouse/watch.list'"

check_absent "/var/run/dormouse.pid" "[[ -f /var/run/dormouse.pid ]]"
check_absent "dormouse entry in /var/log/packages/" "ls /var/log/packages/ | grep -q '^dormouse-'"

if [[ "$FAIL" -ne 0 ]]; then
    echo "uninstall-on-host: leftover state found — packaging bug, fix before re-release" >&2
    exit 1
fi

# Deliberately NOT checked here: /mnt/cache/appdata/dormouse/manifest.db.
# The Phase 2 evidence week's whole point is a week of activity history to
# survive a plugin remove/reinstall cycle during development — the plg's
# remove block only ever touched the flash cfg dir and the installed tree,
# never appdata, and that stays true here.
echo "uninstall-on-host: clean revert confirmed (manifest db under appdata is deliberately preserved)"
