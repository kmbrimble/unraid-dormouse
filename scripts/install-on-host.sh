#!/bin/bash
# Install (or upgrade) dormouse on the live Unraid host over ssh, then
# verify the install. Run from this container — the host has no way to
# reach GitHub Actions and this container is not on the same trust boundary
# as the host, so all verification happens here, over ssh, read-only aside
# from the `plugin install` call itself.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLG="dormouse.plg"
HOST="192.168.0.10"
SSH_KEY="/root/.ssh/unraid_secretsman"
SSH=(ssh -o StrictHostKeyChecking=no -i "$SSH_KEY" "root@$HOST")

FORCED=""
VERSION=""
for arg in "$@"; do
    case "$arg" in
        --forced) FORCED="forced" ;;
        *) VERSION="$arg" ;;
    esac
done

VERSION="${VERSION:-$(sed -rn 's|^<!ENTITY version[[:space:]]+"([^"]+)">.*|\1|p' "$REPO_ROOT/$PLG")}"
PLG_URL="https://raw.githubusercontent.com/kmbrimble/unraid-dormouse/main/$PLG"

# The release workflow commits the real md5 onto main AFTER building — wait for
# that commit to exist upstream, and pin to ITS md5, not merely "any non-
# placeholder md5". Waiting for the CDN to match a same-looking-but-wrong md5
# from a stale, previously-released version would pass this check and then
# fail the plugin manager's own MD5 verification on install.
git -C "$REPO_ROOT" fetch -q origin main
EXPECTED_PLG="$(git -C "$REPO_ROOT" show "origin/main:$PLG")"
EXPECTED_VERSION="$(sed -rn 's|^<!ENTITY version[[:space:]]+"([^"]+)">.*|\1|p' <<<"$EXPECTED_PLG")"
EXPECTED_MD5="$(sed -rn 's|^<!ENTITY md5[[:space:]]+"([^"]+)">.*|\1|p' <<<"$EXPECTED_PLG")"

if [[ "$EXPECTED_VERSION" != "$VERSION" ]]; then
    echo "ERROR: origin/main has version $EXPECTED_VERSION, expected $VERSION — has the release workflow run yet?" >&2
    exit 1
fi
if [[ -z "$EXPECTED_MD5" || "$EXPECTED_MD5" == "00000000000000000000000000000000" ]]; then
    echo "ERROR: origin/main's dormouse.plg still has the placeholder md5 — release workflow has not written the real one back yet" >&2
    exit 1
fi

echo "waiting for raw.githubusercontent.com to serve version $VERSION with md5 $EXPECTED_MD5..."
ATTEMPTS=40
for i in $(seq 1 "$ATTEMPTS"); do
    RAW="$(curl -fsSL -H 'Cache-Control: no-cache' "$PLG_URL" || true)"
    REMOTE_VERSION="$(sed -rn 's|^<!ENTITY version[[:space:]]+"([^"]+)">.*|\1|p' <<<"$RAW")"
    REMOTE_MD5="$(sed -rn 's|^<!ENTITY md5[[:space:]]+"([^"]+)">.*|\1|p' <<<"$RAW")"
    if [[ "$REMOTE_VERSION" == "$VERSION" && "$REMOTE_MD5" == "$EXPECTED_MD5" ]]; then
        echo "CDN serving $VERSION (md5 $REMOTE_MD5) after $i attempt(s)"
        break
    fi
    if [[ "$i" == "$ATTEMPTS" ]]; then
        echo "ERROR: CDN never converged on version $VERSION / md5 $EXPECTED_MD5 after $ATTEMPTS attempts" >&2
        exit 1
    fi
    sleep 15
done

INSTALLED_VERSION="$("${SSH[@]}" "sed -rn 's|^<!ENTITY version[[:space:]]+\"([^\"]+)\">.*|\1|p' /boot/config/plugins/$PLG 2>/dev/null || true")"

# The host has no python3 and this container has no php, so the strcmp
# check runs here in python3 (the container has it) rather than via
# scripts/version-sorts-after.php (which the release workflow and tests use,
# both on GitHub-hosted runners that do have php). Same rule either way:
# plain string comparison, matching dynamix.plugin.manager's `plugin` script.
if [[ -n "$INSTALLED_VERSION" && -z "$FORCED" ]]; then
    if ! python3 -c 'import sys; sys.exit(0 if sys.argv[2] > sys.argv[1] else 1)' "$INSTALLED_VERSION" "$VERSION"; then
        echo "ERROR: $VERSION does not sort after installed $INSTALLED_VERSION (strcmp). Use --forced to override." >&2
        exit 1
    fi
fi

echo "installing $VERSION on $HOST..."
"${SSH[@]}" "plugin install '$PLG_URL' $FORCED"

echo "verifying install..."
FAIL=0

check() {
    local desc="$1"; shift
    if "${SSH[@]}" "$@"; then
        echo "  OK: $desc"
    else
        echo "  FAIL: $desc"
        FAIL=1
    fi
}

check "flash .plg reports version $VERSION" \
    "grep -q '<!ENTITY version *\"$VERSION\">' /boot/config/plugins/$PLG"
check "registered in /var/log/plugins/dormouse.plg" \
    "[[ -f /var/log/plugins/dormouse.plg ]]"
check "installed tree complete" \
    "[[ -f /usr/local/emhttp/plugins/dormouse/README.md && -f /usr/local/emhttp/plugins/dormouse/Dormouse.page && -f /usr/local/emhttp/plugins/dormouse/scripts/rc.dormouse && -f /usr/local/emhttp/plugins/dormouse/scripts/dormoused && -f /usr/local/emhttp/plugins/dormouse/scripts/lib.php && -f /usr/local/emhttp/plugins/dormouse/scripts/dormouse-api.php ]]"
check "packaged README has no heading" \
    "! grep -q '^#' /usr/local/emhttp/plugins/dormouse/README.md"
check "rc.dormouse reports running" \
    "bash /usr/local/emhttp/plugins/dormouse/scripts/rc.dormouse status"
check "pid file present" \
    "[[ -f /var/run/dormouse.pid ]]"
check "dormouse.cfg was upgraded to real defaults (not the Phase 1 placeholder)" \
    "grep -q '^watched_shares=' /boot/config/plugins/dormouse/dormouse.cfg"

# The daemon walks all six watched shares to watch_depth before it spawns
# inotifywait — on a cold pool that's a real spin-up plus a seek per
# directory, plausibly 30-60s, so this can't be a single immediate check.
# -fc (not bare -f) so this also catches an upgrade leaving two children
# running, not just zero vs one.
echo "waiting for the inotifywait child to start (daemon walks the pool first)..."
INOTIFY_OK=0
for i in $(seq 1 60); do
    COUNT="$("${SSH[@]}" "pgrep -fc 'inotifywait.*--fromfile /var/run/[d]ormouse/watch.list'" || echo 0)"
    if [[ "$COUNT" == "1" ]]; then
        echo "  OK: inotifywait child running against the flash-free watch list (after ~$((i * 2))s)"
        INOTIFY_OK=1
        break
    fi
    sleep 2
done
if [[ "$INOTIFY_OK" -ne 1 ]]; then
    echo "  FAIL: inotifywait child not running with exactly one instance after 120s (last count: $COUNT)"
    FAIL=1
fi

if [[ "$FAIL" -ne 0 ]]; then
    echo "install-on-host: one or more checks FAILED" >&2
    exit 1
fi

echo "install-on-host: all checks passed"
