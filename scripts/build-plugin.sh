#!/bin/bash
# Build dist/dormouse-<version>.txz from plugin/, packaged at the path
# Unraid unpacks .txz files to: usr/local/emhttp/plugins/dormouse/.
set -euo pipefail

VERSION="${1:?usage: build-plugin.sh <version>}"
NAME="dormouse"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_DIR="$REPO_ROOT/plugin"
OUT_DIR="$REPO_ROOT/dist"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

INSTALL_ROOT="$WORK_DIR/usr/local/emhttp/plugins/$NAME"
mkdir -p "$INSTALL_ROOT"

# Copy the installed tree as real files (no symlinks) — .page files must sit
# at the plugin's installed root, not a subdirectory, or Unraid's page
# loader will not find them.
cp -a "$PLUGIN_DIR/." "$INSTALL_ROOT/"

chmod +x "$INSTALL_ROOT/scripts/rc.dormouse" "$INSTALL_ROOT/scripts/dormoused"

# emhttpd gates event/* scripts on the executable bit itself (not -f, unlike
# every script our own .plg invokes directly) — a documented exception to
# CLAUDE.md rule 4.
chmod +x "$INSTALL_ROOT/event/started" "$INSTALL_ROOT/event/stopping_svcs"

mkdir -p "$OUT_DIR"
TXZ="$OUT_DIR/$NAME-$VERSION.txz"
rm -f "$TXZ"

# Deterministic archive: the same source must give the same bytes on any machine, because the .plg's
# <MD5> is committed before the tag and the GitHub release workflow rebuilds and requires it to match
# (scripts/check-md5.sh).
# - sorted names, fixed mtime, numeric root owner;
# - --mode: cp/mkdir/git checkout follow the caller's umask, so normalise to 0644 files / 0755 dirs
#   (the chmod +x above survives as 0755);
# - xz -T1 on the command line: xz >= 5.6 defaults to multi-threaded, which writes sizes into the block
#   header and changes the bytes (identical tar stream, different .txz). Command-line flags beat
#   XZ_OPT/XZ_DEFAULTS.
# tests/run.php builds under different umask/TZ/XZ_DEFAULTS and requires one md5.
echo "  $(tar --version | head -1); $(xz --version | head -1)" >&2  # stderr: check-md5.sh discards stdout
tar -C "$WORK_DIR" --sort=name --mtime='UTC 2020-01-01' --owner=0 --group=0 --numeric-owner \
    --mode='u=rwX,go=rX' -I 'xz -6 -T1' -cf "$TXZ" usr

MD5="$(md5sum "$TXZ" | awk '{print $1}')"

echo "built: $TXZ"
echo "md5: $MD5"
