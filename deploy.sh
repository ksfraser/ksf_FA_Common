#!/bin/bash
# Deployment script for KSF FA modules
# Rsyncs the dev tree into the deployed fa_modules tree, excluding dev-only
# and third-party artifacts (.git, vendor, tests, composer.lock, phpunit.xml).
# Then runs composer install --no-dev in the destination.
#
# Usage: ./deploy.sh <module-name>
#   module-name: e.g. ksf_FA_Upc2Item (directory under ~/Documents/ and
#                under ~/Documents/ksf_Infrastructure/fa_modules/)
#
set -euo pipefail

MODULE="${1:?Usage: $0 <module-name>}"

KSF_DOCS_DIR="${KSF_DOCS_DIR:-/home/kevin/Documents}"
SOURCE="${KSF_DOCS_DIR}/${MODULE}"
TARGET="${KSF_DOCS_DIR}/ksf_Infrastructure/fa_modules/${MODULE}"

if [ ! -d "$SOURCE" ]; then
    echo "Source does not exist: $SOURCE" >&2
    exit 1
fi

if [ ! -d "$TARGET" ]; then
    echo "Target does not exist: $TARGET" >&2
    exit 1
fi

echo "Deploying ${MODULE} -> ${TARGET}"

rsync -av \
    --exclude='.git/' \
    --exclude='vendor/' \
    --exclude='tests/' \
    --exclude='composer.lock' \
    --exclude='phpunit.xml' \
    --exclude='.phpunit.result.cache' \
    --exclude='.phpunit.cache/' \
    --exclude='deploy.sh' \
    "$SOURCE/" "$TARGET/"

echo ""
echo "Running composer install --no-dev in ${TARGET}"

if command -v composer >/dev/null 2>&1; then
    (cd "$TARGET" && composer install --no-dev --no-interaction --prefer-dist 2>&1)
else
    echo "composer not found; skipping composer install. Run manually in ${TARGET}" >&2
fi

echo ""
echo "Deployed ${MODULE}."
