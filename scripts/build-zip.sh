#!/usr/bin/env bash
# Build a clean, installable ${NAME}.zip, honouring .distignore.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NAME="$(grep -m1 -oE 'Text Domain:[[:space:]]+[a-z0-9-]+' "$ROOT_DIR"/*.php | awk '{print $3}')"
[ -n "$NAME" ] || { echo "ERROR: could not read Text Domain from the plugin header" >&2; exit 1; }
OUT_DIR="${1:-/tmp/${NAME}-build}"
STAGE="${OUT_DIR}/${NAME}"

rm -rf "${OUT_DIR}"
mkdir -p "${STAGE}"

rsync -a --exclude-from="${ROOT_DIR}/.distignore" \
    --exclude '.git' --exclude 'node_modules' --exclude '.DS_Store' \
    "${ROOT_DIR}/" "${STAGE}/"

# Runtime dependencies (QR library) ship in the zip, without dev tools.
DEPS="$(mktemp -d)"
cp "${ROOT_DIR}/composer.json" "${ROOT_DIR}/composer.lock" "${DEPS}/"
mkdir -p "${DEPS}/src"
composer install --no-dev --optimize-autoloader --no-interaction --quiet -d "${DEPS}"
rm -rf "${STAGE}/vendor" && cp -R "${DEPS}/vendor" "${STAGE}/vendor"
rm -rf "${DEPS}"

find "${STAGE}" -name '.DS_Store' -delete

rm -f "/tmp/${NAME}.zip"
( cd "${OUT_DIR}" && zip -rqX "/tmp/${NAME}.zip" "${NAME}" -x '*.DS_Store' )
echo "Built /tmp/${NAME}.zip from ${STAGE}"
