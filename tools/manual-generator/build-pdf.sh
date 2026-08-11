#!/usr/bin/env bash
# tools/manual-generator/build-pdf.sh — MANUAL.md → client-ready PDF.
#   ./tools/manual-generator/build-pdf.sh            # all locales in manual.env
#   ./tools/manual-generator/build-pdf.sh es_ES
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
source tools/manual-generator/manual.env

command -v pandoc >/dev/null || { echo "pandoc not installed: apt install pandoc" >&2; exit 1; }
command -v weasyprint >/dev/null || {
  # weasyprint handles CSS page breaks and image sizing far better than LaTeX for
  # screenshot-heavy documents, and needs no TeX install.
  echo "weasyprint not installed: pipx install weasyprint" >&2; exit 1; }

VERSION="$(grep -oP "define\('PLUGIN_[A-Z_]+_VERSION',\s*'\K[^']+" setup.php)"
LOCALES="${1:-${MANUAL_LOCALES}}"
mkdir -p tools/manual-generator/dist

for LOC in ${LOCALES}; do
  DIR="docs/manual/${LOC}"
  SRCS=("${DIR}/01-intro.md" "${DIR}/02-setup.md" "${DIR}/03-usage.md" "${DIR}/04-troubleshooting.md")
  MISSING=0
  for f in "${SRCS[@]}"; do [[ -f "$f" ]] || MISSING=1; done
  [[ "$MISSING" -eq 0 ]] || { echo "skipping ${LOC}: missing one of ${SRCS[*]}"; continue; }
  OUT="tools/manual-generator/dist/MANUAL-${PLUGIN_KEY}-${VERSION}-${LOC}.pdf"
  echo "==> ${OUT}"
  pandoc "${SRCS[@]}" \
    --resource-path="docs/manual/${LOC}" \
    --toc --toc-depth=2 \
    --pdf-engine=weasyprint \
    --css=tools/manual-generator/manual.css \
    -M title="${PLUGIN_KEY} ${VERSION} — User Manual" \
    -M lang="${LOC/_/-}" \
    -o "$OUT"
done

ls -lh tools/manual-generator/dist/MANUAL-*.pdf
