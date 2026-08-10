#!/usr/bin/env bash
# docs/manual/fixtures/dump.sh — freeze the current GLPI state as the golden doc fixture.
#
# Run this when you have the demo data set up exactly as the manual should show it (see
# CHECKLIST.md). Commit the resulting dump as its own change — never mixed into a docs or
# feature commit, so "the fixture changed" is always visible in the history.
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
source docs/manual/manual.env

OUT=docs/manual/fixtures
mkdir -p "$OUT"

echo "==> dumping ${DB_NAME} from ${DB_CONTAINER}"
# --skip-dump-date keeps the dump itself diff-stable; --no-tablespaces avoids needing the
# PROCESS privilege on MySQL 8 / MariaDB 10.5+.
"${ENGINE}" exec -i "${DB_CONTAINER}" mariadb-dump \
  --single-transaction --no-tablespaces --skip-dump-date \
  --default-character-set=utf8mb4 \
  -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" \
  | gzip -9 > "${OUT}/golden.sql.gz"

# Uploads that appear in screenshots (logos, imported zone files) live outside the DB.
if "${ENGINE}" exec "${GLPI_CONTAINER}" test -d "files/_plugins/${PLUGIN_KEY}" 2>/dev/null; then
  echo "==> dumping files/_plugins/${PLUGIN_KEY}"
  "${ENGINE}" exec "${GLPI_CONTAINER}" \
    tar -cf - -C files/_plugins "${PLUGIN_KEY}" | gzip -9 > "${OUT}/golden-files.tar.gz"
fi

echo
echo "==> wrote:"
ls -lh "${OUT}"/golden*
echo
cat <<'EOF'
Before committing, check the dump is safe to publish:

  zcat docs/manual/fixtures/golden.sql.gz | grep -iE 'ticgal|real-client|BEGIN (RSA|EC) PRIVATE'

Registrar credentials are encrypted in the DB, but the *account labels*, domain names and
any notes are not. Screenshots taken from this fixture go to clients.
EOF
