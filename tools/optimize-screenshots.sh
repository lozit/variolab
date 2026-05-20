#!/usr/bin/env bash
#
# Resize + lossy-compress wp.org plugin screenshots in .wordpress-org/.
# Targets ~120 KB per file at 1200px wide (~2x DPI for retina-quality
# display in the wp.org plugin listing, which renders them at ~600px).
#
# Prerequisites (one-shot, local dev only — NOT a runtime dependency):
#   brew install pngquant
#
# Re-run this script after exporting fresh screenshots into
# .wordpress-org/screenshot-*.png (typically from wp-env at full retina
# resolution, ~2500-3000 px wide and a few hundred KB to ~1 MB each).
#
# This file is committed for reproducibility. /tools/ is excluded from
# the wp.org distribution zip via .github/workflows/release.yml's rsync
# filter, so it never ships to end users.

set -euo pipefail

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
ROOT="$( cd "${SCRIPT_DIR}/.." && pwd )"
DIR="${ROOT}/.wordpress-org"

cd "${DIR}"

for f in screenshot-*.png; do
	[ -f "$f" ] || continue
	echo "→ ${f}"
	# 1. Resize to max 1200px wide (preserves aspect ratio).
	sips --resampleWidth 1200 "$f" --out "$f.tmp.png" > /dev/null
	mv "$f.tmp.png" "$f"
	# 2. Lossy compress with pngquant. 85-95 quality is visually
	#    indistinguishable from original for screenshots while typically
	#    reducing weight by ~70%. --strip removes metadata; --skip-if-larger
	#    falls back to the unquantized output if compression would inflate.
	pngquant --quality=85-95 --skip-if-larger --strip --force --output "$f" -- "$f"
done

echo
echo "Done. Final sizes:"
ls -lh screenshot-*.png
echo
echo "Total:"
du -ch screenshot-*.png | tail -1
