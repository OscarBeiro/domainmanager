#!/usr/bin/env bash
# tools/manual-generator/fixtures/restore.sh — reset GLPI to the golden doc fixture.
#
# Runs before every capture pass, from the host rather than from a Playwright hook: a failed
# capture must still leave the database in a known state for the next attempt.
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
source tools/manual-generator/manual.env

DUMP=tools/manual-generator/fixtures/golden.sql.gz
[[ -f "$DUMP" ]] || { echo "missing $DUMP — run tools/manual-generator/fixtures/dump.sh first" >&2; exit 1; }

echo "==> dropping and recreating ${DB_NAME}"
# Recreate rather than import over the top: mysqldump's DROP TABLE only covers tables that
# exist in the dump, so a table added since would survive and quietly change screenshots.
"${ENGINE}" exec -i "${DB_CONTAINER}" mariadb -u root -p"${DB_ROOT_PASS}" <<SQL
DROP DATABASE IF EXISTS \`${DB_NAME}\`;
CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
SQL

echo "==> importing golden fixture"
gunzip -c "$DUMP" | "${ENGINE}" exec -i "${DB_CONTAINER}" \
  mariadb --default-character-set=utf8mb4 -u root -p"${DB_ROOT_PASS}" "${DB_NAME}"

if [[ -f tools/manual-generator/fixtures/golden-files.tar.gz ]]; then
  echo "==> restoring files/_plugins/${PLUGIN_KEY}"
  gunzip -c tools/manual-generator/fixtures/golden-files.tar.gz \
    | "${ENGINE}" exec -i "${GLPI_CONTAINER}" tar -xf - -C files/_plugins
fi

# GLPI caches config, translations and the plugin list. Skipping this gives you screenshots
# of the *previous* fixture's data, which is a maddening bug to chase.
# CONFIRM the command name on GLPI 11: bin/console cache:clear (GLPI 10: cache:clear too).
echo "==> clearing GLPI cache"
"${ENGINE}" exec "${GLPI_CONTAINER}" php bin/console --no-interaction cache:clear \
  || echo "!!  cache:clear failed — verify the console command for this GLPI version" >&2
# GLPI 11 compiles Twig templates lazily on first render. Right after cache:clear, the very
# first hit on a given page (login, and separately each plugin tab template) can be slow or
# flaky enough that Playwright's default timeouts miss it — observed as a real browser login
# failing ("Incorrect username or password" for correct credentials) and, past login, the
# Domain Manager tab/panel not appearing in time. curl warm-up hits, done here once up front,
# make the real capture run hit only already-compiled templates. Best-effort: don't fail the
# restore over it.
echo "==> warming up (Twig template compile is lazy; avoids cold-cache flakiness in the real run)"
HOST_URL="http://localhost:${GLPI_HOST_PORT:-65123}"
WARM_JAR="$(mktemp)"
WARM_HTML="$(curl -s -c "${WARM_JAR}" "${HOST_URL}/")"
WARM_TOKEN="$(printf '%s' "${WARM_HTML}" | grep -oP 'name="_glpi_csrf_token" value="\K[^"]+' | head -1)"
curl -s -b "${WARM_JAR}" -c "${WARM_JAR}" -o /dev/null \
  --data-urlencode "_glpi_csrf_token=${WARM_TOKEN}" \
  --data-urlencode "login_name=warmup-only-not-a-real-user" \
  --data-urlencode "login_password=x" \
  --data-urlencode "auth=local" \
  "${HOST_URL}/front/login.php" 2>/dev/null || true
# A real login, so the plugin-tab pages below render past the login wall.
WARM_HTML2="$(curl -s -b "${WARM_JAR}" -c "${WARM_JAR}" "${HOST_URL}/")"
WARM_TOKEN2="$(printf '%s' "${WARM_HTML2}" | grep -oP 'name="_glpi_csrf_token" value="\K[^"]+' | head -1)"
curl -s -b "${WARM_JAR}" -c "${WARM_JAR}" -o /dev/null \
  --data-urlencode "_glpi_csrf_token=${WARM_TOKEN2}" \
  --data-urlencode "login_name=manual_en" \
  --data-urlencode "login_password=${DOC_USER_PASS}" \
  --data-urlencode "auth=local" \
  "${HOST_URL}/front/login.php" 2>/dev/null || true
curl -s -b "${WARM_JAR}" -c "${WARM_JAR}" -o /dev/null "${HOST_URL}/front/supplier.form.php?id=1" 2>/dev/null || true
curl -s -b "${WARM_JAR}" -c "${WARM_JAR}" -o /dev/null "${HOST_URL}/front/domain.form.php?id=1" 2>/dev/null || true
rm -f "${WARM_JAR}"

echo "==> fixture restored"
