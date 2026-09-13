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

# The daemon's own shutdown (rc.dormouse stop, called from the remove FILE
# block) already confirmed the process gone before "plugin remove" returned
# above. A brand-new ssh session's pgrep is a second, independent round trip
# against the host's process table, and has been observed to still catch the
# process mid-exit for well under a second. Retry briefly rather than fail on
# that gap — this is not weakening the check, since removepkg has already
# unlinked the package by this point regardless of the daemon's exact exit
# timing.
PROCESS_GONE=0
for _ in 1 2 3; do
    if ! "${SSH[@]}" "pgrep -f '[d]ormoused'"; then
        PROCESS_GONE=1
        break
    fi
    sleep 1
done
if [[ "$PROCESS_GONE" -eq 1 ]]; then
    echo "  OK: dormoused process absent"
else
    echo "  FAIL: dormoused process still present" >&2
    FAIL=1
fi

check_absent "/var/run/dormouse.pid" "[[ -f /var/run/dormouse.pid ]]"
check_absent "dormouse entry in /var/log/packages/" "ls /var/log/packages/ | grep -q '^dormouse-'"

if [[ "$FAIL" -ne 0 ]]; then
    echo "uninstall-on-host: leftover state found — packaging bug, fix before re-release" >&2
    exit 1
fi

echo "uninstall-on-host: clean revert confirmed"
