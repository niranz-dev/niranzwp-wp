#!/bin/sh
#
# Build a release ZIP and the manifest that points at it.
#
#   ./release/build.sh 1.1.0 https://niranz.dev/niranzwp
#
# The manifest carries the SHA-256 of the ZIP. The updater refuses to install
# an update whose bytes do not match it, so this pair has to be produced
# together -- publishing one without the other breaks every install's updater.

set -eu

VERSION="${1:?usage: build.sh <version> <base-url>}"
BASE="${2:?usage: build.sh <version> <base-url>}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/release"
ZIP="$OUT/niranzwp-wp-$VERSION.zip"

rm -rf "$OUT/build" "$ZIP"
mkdir -p "$OUT/build/niranzwp-wp"

rsync -a \
	--exclude '.git' --exclude '.github' --exclude 'tests' --exclude 'release' \
	--exclude '.DS_Store' --exclude 'node_modules' --exclude '*.zip' \
	"$ROOT/" "$OUT/build/niranzwp-wp/"

( cd "$OUT/build" && zip -qr "$ZIP" niranzwp-wp )
rm -rf "$OUT/build"

SHA="$(shasum -a 256 "$ZIP" | cut -d' ' -f1)"

cat > "$OUT/plugin.json" <<JSON
{
  "name": "NiranzWP",
  "slug": "niranzwp",
  "version": "$VERSION",
  "download_url": "$BASE/niranzwp-wp-$VERSION.zip",
  "sha256": "$SHA",
  "requires": "6.9",
  "requires_php": "8.0",
  "tested": "7.0",
  "homepage": "https://niranz.dev",
  "author": "Niranjan"
}
JSON

printf '\n  %s\n  %s\n\n  sha256 %s\n\n  Upload both to %s/\n\n' \
	"$(basename "$ZIP")" "plugin.json" "$SHA" "$BASE"
