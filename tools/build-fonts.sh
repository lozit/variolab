#!/usr/bin/env bash
#
# Convert the 4 TTF variable fonts under variolab-template/ds/fonts/ to WOFF2
# with a Latin Unicode subset. Output: assets/fonts/*.woff2 (~200 KB total).
#
# Prerequisite (one-shot, local dev only — NOT a runtime dependency):
#   pip3 install --user fonttools brotli zopfli
#   export PATH="$HOME/Library/Python/3.12/bin:$PATH"  # macOS Python.org installer
#
# Re-run this script after refreshing variolab-template/ds/fonts/ from upstream
# (e.g. a newer Inter Tight or JetBrains Mono release).
#
# This file is committed for reproducibility. /tools/ is excluded from the
# wp.org distribution zip via .github/workflows/release.yml's rsync filter,
# so it never ships to end users.

set -euo pipefail

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
ROOT="$( cd "${SCRIPT_DIR}/.." && pwd )"

SRC_DIR="${ROOT}/variolab-template/ds/fonts"
OUT_DIR="${ROOT}/assets/fonts"
mkdir -p "${OUT_DIR}"

# Google-Fonts "latin" subset: basic Latin, Latin-1 supplement, plus the few
# punctuation/currency/arrows code points used by Inter & JetBrains Mono.
# Keep the full wght axis (variable font, do NOT instance).
UNICODES="U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+2000-206F,U+2074,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215"

subset_one() {
  local src="$1"
  local out="$2"
  echo "→ ${src} → ${out}"
  pyftsubset "${SRC_DIR}/${src}" \
    --output-file="${OUT_DIR}/${out}" \
    --flavor=woff2 \
    --layout-features='*' \
    --unicodes="${UNICODES}" \
    --no-hinting \
    --desubroutinize
}

subset_one "InterTight-VariableFont_wght.ttf"        "inter-tight.woff2"
subset_one "InterTight-Italic-VariableFont_wght.ttf" "inter-tight-italic.woff2"
subset_one "JetBrainsMono-VariableFont_wght.ttf"     "jetbrains-mono.woff2"
subset_one "JetBrainsMono-Italic-VariableFont_wght.ttf" "jetbrains-mono-italic.woff2"

echo
echo "Done. Sizes:"
ls -lh "${OUT_DIR}"/*.woff2
echo
echo "Total:"
du -ch "${OUT_DIR}"/*.woff2 | tail -1
