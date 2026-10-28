#!/usr/bin/env bash
#
# Builds a distributable WordPress plugin zip:
#
#   dist/fluentsmtp-postal-<version>.zip
#
# The archive holds a single top-level directory named after the plugin slug,
# which is what WordPress requires for "Plugins -> Add New -> Upload Plugin".
# Only runtime files are shipped: the PHP sources, the compiled admin bundle in
# assets/, and the user-facing readme/license. Development-only material
# (resources/ sources, the merge/ upstream kit, webpack/npm/composer configs,
# CI) is left out so the installed plugin stays small and unambiguous.
#
# The version is read from the plugin header, so it is the single source of
# truth; bump it there (and in readme.txt's Stable tag) before tagging.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="fluentsmtp-postal"
MAIN_FILE="$ROOT/$SLUG.php"

# --- Version ----------------------------------------------------------------

VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' "$MAIN_FILE" | head -n 1 | tr -d '[:space:]')"

if [[ -z "$VERSION" ]]; then
    echo "error: could not read Version from the plugin header in $MAIN_FILE" >&2
    exit 1
fi

# --- Pre-flight checks ------------------------------------------------------

# These are the artifacts the plugin cannot run without. The compiled bundle is
# committed to the repo, so a missing one means the checkout is incomplete and
# the zip would install but render a blank settings screen.
REQUIRED=(
    "$SLUG.php"
    "src/Bootstrap.php"
    "src/ProviderRegistrar.php"
    "src/Providers/Postal/Handler.php"
    "assets/admin/js/postal-admin.js"
    "assets/admin/css/postal-admin.css"
    "assets/admin/css/fonts/element-icons.woff"
    "assets/images/postal.svg"
)

for path in "${REQUIRED[@]}"; do
    if [[ ! -e "$ROOT/$path" ]]; then
        echo "error: missing required file: $path" >&2
        echo "       (run 'npm ci && npm run prod' to rebuild assets/)" >&2
        exit 1
    fi
done

# A bundle that compiled without the settings fields would install cleanly and
# then show an empty screen, which is indistinguishable from a broken deploy.
BUNDLE="$ROOT/assets/admin/js/postal-admin.js"
for marker in 'Postal Server URL' 'Server API Key'; do
    if ! grep -qF "$marker" "$BUNDLE"; then
        echo "error: admin bundle is missing the \"$marker\" field" >&2
        echo "       (the bundle is stale; run 'npm run prod' and commit assets/)" >&2
        exit 1
    fi
done

# Warn — but do not fail — when readme.txt advertises a different release.
STABLE_TAG="$(sed -n 's/^Stable tag:[[:space:]]*//p' "$ROOT/readme.txt" | head -n 1 | tr -d '[:space:]')"
if [[ -n "$STABLE_TAG" && "$STABLE_TAG" != "$VERSION" ]]; then
    echo "warning: readme.txt Stable tag ($STABLE_TAG) != plugin Version ($VERSION)" >&2
fi

# --- Stage ------------------------------------------------------------------

STAGE="$(mktemp -d "${TMPDIR:-/tmp}/${SLUG}.XXXXXX")"
trap 'rm -rf "$STAGE"' EXIT

PLUGIN_DIR="$STAGE/$SLUG"
mkdir -p "$PLUGIN_DIR"

SHIP=(
    "$SLUG.php"
    "src"
    "assets"
    "readme.txt"
    "LICENSE"
)

for item in "${SHIP[@]}"; do
    cp -R "$ROOT/$item" "$PLUGIN_DIR/"
done

# Finder metadata would otherwise ride along into the archive.
find "$STAGE" -name '.DS_Store' -delete

# --- Package ----------------------------------------------------------------

DIST="$ROOT/dist"
ZIP="$DIST/${SLUG}-${VERSION}.zip"

mkdir -p "$DIST"
rm -f "$ZIP"

# -X drops platform-specific extra attributes so repeated builds are stable.
(cd "$STAGE" && zip -r -q -X "$ZIP" "$SLUG")

# --- Verify -----------------------------------------------------------------

# Captured rather than piped into 'grep -q': an early exit there closes the
# pipe, and 'pipefail' would then report the (harmless) SIGPIPE as a failure.
LISTING="$(unzip -Z1 "$ZIP")"

if ! grep -qxF "${SLUG}/${SLUG}.php" <<<"$LISTING"; then
    echo "error: ${SLUG}/${SLUG}.php is not at the archive root; WordPress will reject this zip" >&2
    exit 1
fi

# Boot the staged copy the way WordPress would, in both dependency states. This
# catches a bad autoload path or a missing class that a flat file listing cannot.
if command -v php >/dev/null 2>&1; then
    for mode in '' 'with-fluentsmtp'; do
        if ! php "$ROOT/bin/smoke-test.php" "$PLUGIN_DIR" $mode >/dev/null; then
            echo "error: smoke test failed (mode: ${mode:-no-fluentsmtp}); rerun for detail:" >&2
            echo "       php bin/smoke-test.php <plugin-dir> $mode" >&2
            exit 1
        fi
    done
    echo "smoke test: ok (plugin boots with and without FluentSMTP)"
else
    echo 'smoke test: skipped (php not on PATH)' >&2
fi

echo "built $ZIP ($(du -h "$ZIP" | cut -f1 | tr -d ' '))"
echo
sort <<<"$LISTING" | sed 's/^/  /'
