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

echo "waiting for raw.githubusercontent.com to serve version $VERSION..."
ATTEMPTS=40
for i in $(seq 1 "$ATTEMPTS"); do
    RAW="$(curl -fsSL -H 'Cache-Control: no-cache' "$PLG_URL" || true)"
    REMOTE_VERSION="$(sed -rn 's|^<!ENTITY version[[:space:]]+"([^"]+)">.*|\1|p' <<<"$RAW")"
    REMOTE_MD5="$(sed -rn 's|^<!ENTITY md5[[:space:]]+"([^"]+)">.*|\1|p' <<<"$RAW")"
    if [[ "$REMOTE_VERSION" == "$VERSION" && -n "$REMOTE_MD5" && "$REMOTE_MD5" != "00000000000000000000000000000000" ]]; then
        echo "CDN serving $VERSION (md5 $REMOTE_MD5) after $i attempt(s)"
        break
    fi
    if [[ "$i" == "$ATTEMPTS" ]]; then
        echo "ERROR: CDN never converged on version $VERSION after $ATTEMPTS attempts" >&2
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
    "[[ -f /usr/local/emhttp/plugins/dormouse/README.md && -f /usr/local/emhttp/plugins/dormouse/Dormouse.page && -f /usr/local/emhttp/plugins/dormouse/scripts/rc.dormouse && -f /usr/local/emhttp/plugins/dormouse/scripts/dormoused ]]"
check "packaged README has no heading" \
    "! grep -q '^#' /usr/local/emhttp/plugins/dormouse/README.md"
check "rc.dormouse reports running" \
    "bash /usr/local/emhttp/plugins/dormouse/scripts/rc.dormouse status"
check "pid file present" \
    "[[ -f /var/run/dormouse.pid ]]"

if [[ "$FAIL" -ne 0 ]]; then
    echo "install-on-host: one or more checks FAILED" >&2
    exit 1
fi

echo "install-on-host: all checks passed"
