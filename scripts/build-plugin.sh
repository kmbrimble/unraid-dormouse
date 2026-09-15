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

tar -C "$WORK_DIR" --owner=0 --group=0 -cJf "$TXZ" usr

MD5="$(md5sum "$TXZ" | awk '{print $1}')"

echo "built: $TXZ"
echo "md5: $MD5"
