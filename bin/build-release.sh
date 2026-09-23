#!/usr/bin/env bash
set -euo pipefail

# Builds a production-ready, installable ZIP of the plugin: exports the
# committed tree, installs only production Composer dependencies in an
# isolated copy (never touching this checkout's own vendor/, so the
# developer's dev dependencies for testing are untouched), strips dev-only
# files, and zips it up. The resulting ZIP needs no Composer/npm/Node on the
# WordPress server — just upload/extract and activate.
#
# Usage: bin/build-release.sh [ref] [output-zip]
#   ref         git ref to build from (default: HEAD)
#   output-zip  where to write the ZIP (default: build/carnet-equidad.zip)

REF="${1:-HEAD}"
OUT="${2:-build/carnet-equidad.zip}"

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="$(mktemp -d)"
trap 'rm -rf "$BUILD_DIR"' EXIT

PLUGIN_DIR="$BUILD_DIR/carnet-equidad"
mkdir -p "$PLUGIN_DIR"

echo "==> Exportando $REF ..."
git -C "$ROOT_DIR" archive "$REF" | tar -x -C "$PLUGIN_DIR"

# Dev-only files that never belong in a production install.
rm -rf "$PLUGIN_DIR"/tests "$PLUGIN_DIR"/phpunit.xml.dist "$PLUGIN_DIR"/docs "$PLUGIN_DIR"/.gitignore "$PLUGIN_DIR"/bin

echo "==> Instalando dependencias de producción (composer install --no-dev) ..."
(cd "$PLUGIN_DIR" && composer install --no-dev --optimize-autoloader --no-interaction)

# composer.json/lock are a build-time concern, not something the WP server needs.
rm -f "$PLUGIN_DIR"/composer.json "$PLUGIN_DIR"/composer.lock

OUT_PATH="$ROOT_DIR/$OUT"
mkdir -p "$(dirname "$OUT_PATH")"
rm -f "$OUT_PATH"

echo "==> Empaquetando $OUT_PATH ..."
(cd "$BUILD_DIR" && zip -rq "$OUT_PATH" carnet-equidad -x '.*')

echo "==> Listo: $OUT_PATH"
