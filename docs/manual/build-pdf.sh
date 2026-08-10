#!/usr/bin/env bash
# docs/manual/build-pdf.sh — MANUAL.md → client-ready PDF.
#   ./docs/manual/build-pdf.sh            # all locales in manual.env
#   ./docs/manual/build-pdf.sh es_ES
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
source docs/manual/manual.env

command -v pandoc >/dev/null || { echo "pandoc not installed: apt install pandoc" >&2; exit 1; }
command -v weasyprint >/dev/null || {
  # weasyprint handles CSS page breaks and image sizing far better than LaTeX for
  # screenshot-heavy documents, and needs no TeX install.
  echo "weasyprint not installed: pipx install weasyprint" >&2; exit 1; }

VERSION="$(grep -oP "define\('PLUGIN_[A-Z_]+_VERSION',\s*'\K[^']+" setup.php)"
LOCALES="${1:-${MANUAL_LOCALES}}"
mkdir -p dist

for LOC in ${LOCALES}; do
  SRC="docs/manual/${LOC}/MANUAL.md"
  [[ -f "$SRC" ]] || { echo "skipping ${LOC}: no ${SRC}"; continue; }
  OUT="dist/MANUAL-${PLUGIN_KEY}-${VERSION}-${LOC}.pdf"
  echo "==> ${OUT}"
  pandoc "$SRC" \
    --resource-path="docs/manual/${LOC}" \
    --toc --toc-depth=2 \
    --pdf-engine=weasyprint \
    --css=docs/manual/manual.css \
    -M title="${PLUGIN_KEY} ${VERSION} — User Manual" \
    -M lang="${LOC/_/-}" \
    -o "$OUT"
done

ls -lh dist/MANUAL-*.pdf
