#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN="woocammerce-usd-to-toman"
VERSION="${1:-$(sed -n 's/^ \* Version: \(.*\)$/\1/p' "$ROOT/$PLUGIN.php" | head -n1)}"
[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+([-.][0-9A-Za-z.-]+)?$ ]] || { echo "Invalid version: $VERSION" >&2; exit 1; }
OUT="$ROOT/release"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
DEST="$STAGE/$PLUGIN"
mkdir -p "$DEST"

# Keep this allow-list deliberate: a WordPress upload must never contain the
# repository, tests, CI configuration, or build tooling.
cp "$ROOT/$PLUGIN.php" "$ROOT/readme.txt" "$DEST/"
if [[ -d "$ROOT/includes" ]]; then cp -R "$ROOT/includes" "$DEST/"; fi
if [[ -d "$ROOT/assets" ]]; then cp -R "$ROOT/assets" "$DEST/"; fi

mkdir -p "$OUT"
rm -f "$OUT/$PLUGIN-$VERSION.zip"
( cd "$STAGE" && zip -qr "$OUT/$PLUGIN-$VERSION.zip" "$PLUGIN" )
unzip -Z1 "$OUT/$PLUGIN-$VERSION.zip" | grep -q "^$PLUGIN/$PLUGIN.php$"
echo "Built $OUT/$PLUGIN-$VERSION.zip"
