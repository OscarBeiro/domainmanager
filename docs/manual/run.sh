#!/usr/bin/env bash
# docs/manual/run.sh — capture screenshots in a version-matched Playwright container.
#
#   ./docs/manual/run.sh                        # en_GB against http://glpi
#   MANUAL_LOCALE=es_ES ./docs/manual/run.sh
#   GLPI_NETWORK=domainmanager_default ./docs/manual/run.sh --headed
#
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"
[[ -f docs/manual/manual.env ]] && source docs/manual/manual.env

MANUAL_LOCALE="${MANUAL_LOCALE:-en_GB}"
BASE_URL="${BASE_URL:-http://glpi}"
GLPI_NETWORK="${GLPI_NETWORK:-glpi_default}"
TZ="${TZ:-Europe/Madrid}"
ENGINE="${ENGINE:-podman}"

# The image tag must match @playwright/test exactly, or Chromium won't be found — and the
# error message reads like a missing install, which sends you looking in the wrong place.
if [[ ! -f docs/manual/node_modules/@playwright/test/package.json ]]; then
  echo "run 'npm install' in docs/manual first (need @playwright/test to derive the image tag)" >&2
  exit 1
fi
PW_VERSION="$(node -p "require('./docs/manual/node_modules/@playwright/test/package.json').version")"
IMAGE="mcr.microsoft.com/playwright:v${PW_VERSION}-noble"

# Restore the golden fixture so the run starts from known state. Do this outside the
# container: a failed capture must still leave the DB clean for the next attempt.
if [[ -x docs/manual/fixtures/restore.sh ]]; then
  echo "==> restoring golden fixture"
  docs/manual/fixtures/restore.sh
else
  echo "!!  docs/manual/fixtures/restore.sh missing — screenshots will not be reproducible" >&2
fi

NET_ARGS=(--network "${GLPI_NETWORK}")
if [[ "${BASE_URL}" == *"host.containers.internal"* ]]; then
  NET_ARGS=(--add-host=host.containers.internal:host-gateway)
elif [[ "${BASE_URL}" == *"localhost"* || "${BASE_URL}" == *"127.0.0.1"* ]]; then
  NET_ARGS=(--network=host)
fi

echo "==> capturing ${MANUAL_LOCALE} from ${BASE_URL} using ${IMAGE}"
# MANUAL_OUT is relative to this container's cwd (docs/manual/, via -w below), whereas
# render-manual.mjs runs on the host from the repo root afterwards — same locale name,
# different base path, both correct for where each process actually runs.
#
# -it only when actually attached to a terminal (needed for --headed debugging runs).
# Confirmed on this GLPI 11.0.8 image: with -it allocated under a piped/non-interactive
# invocation, the very first login attempt reproducibly fails ("Incorrect username or
# password" for correct credentials) — root cause not chased down (likely a reporter/event
# loop timing shift from the fake TTY, not GLPI itself), but dropping -it here fixes it.
TTY_ARGS=()
[[ -t 0 && -t 1 ]] && TTY_ARGS=(-it)
"${ENGINE}" run --rm "${TTY_ARGS[@]}" \
  "${NET_ARGS[@]}" \
  --ipc=host \
  --security-opt seccomp=unconfined \
  -v "${PWD}:/work" \
  -w /work/docs/manual \
  -e BASE_URL="${BASE_URL}" \
  -e MANUAL_LOCALE="${MANUAL_LOCALE}" \
  -e MANUAL_OUT="${MANUAL_LOCALE}" \
  -e TZ="${TZ}" \
  -e CI="${CI:-}" \
  "${IMAGE}" \
  npx playwright test --config playwright.config.ts "$@"

# Note on ownership: rootless Podman maps container root to your host UID, so the PNGs come
# out owned by you. Do NOT add --userns=keep-id here — it usually breaks that mapping and
# makes /ms-playwright unreadable. On Docker, add: --user "$(id -u):$(id -g)".

echo "==> rendering MANUAL.md"
MANUAL_LOCALE="${MANUAL_LOCALE}" node docs/manual/render-manual.mjs

git status --short docs/manual | grep -E '\.png$' >/dev/null \
  && echo "==> screenshots changed — review the diff before committing" \
  || echo "==> screenshots unchanged"
