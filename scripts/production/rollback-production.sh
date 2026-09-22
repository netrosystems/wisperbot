#!/usr/bin/env bash

set -Eeuo pipefail

APP_BASE="${APP_BASE:-/home/bluestar/wisperbot.com/deployment}"
PHP_BIN="${PHP_BIN:-/usr/local/bin/php}"
HEALTH_URL="${HEALTH_URL:-https://wisperbot.com/up}"
RELEASES_DIR="$APP_BASE/releases"
CURRENT_LINK="$APP_BASE/current"
SWITCHED=0
MAINTENANCE=0

run_artisan() {
    local release="$1"
    shift
    (cd "$release" && "$PHP_BIN" artisan "$@")
}

activate_release() {
    local release="$1"
    local next_link="$APP_BASE/.rollback-$(date +%s)-$$"
    ln -s "$release" "$next_link"
    mv -Tf "$next_link" "$CURRENT_LINK"
}

recover_on_error() {
    local exit_code=$?
    set +e

    echo "Rollback failed. Restoring the original release." >&2
    if [[ "$SWITCHED" -eq 1 ]]; then
        activate_release "$CURRENT_RELEASE"
    fi
    if [[ "$MAINTENANCE" -eq 1 ]]; then
        run_artisan "$CURRENT_RELEASE" up
    fi

    exit "$exit_code"
}

trap recover_on_error ERR

test -L "$CURRENT_LINK"
CURRENT_RELEASE="$(readlink -f "$CURRENT_LINK")"
TARGET_RELEASE="${1:-}"

if [[ -z "$TARGET_RELEASE" ]]; then
    while IFS= read -r candidate; do
        if [[ "$(readlink -f "$candidate")" != "$CURRENT_RELEASE" ]]; then
            TARGET_RELEASE="$candidate"
            break
        fi
    done < <(find "$RELEASES_DIR" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' | sort -nr | cut -d' ' -f2-)
elif [[ "$TARGET_RELEASE" != /* ]]; then
    TARGET_RELEASE="$RELEASES_DIR/$TARGET_RELEASE"
fi

test -n "$TARGET_RELEASE" || {
    echo "No previous release is available." >&2
    exit 1
}
TARGET_RELEASE="$(readlink -f "$TARGET_RELEASE")"
test -f "$TARGET_RELEASE/artisan"
test -f "$TARGET_RELEASE/public/build/manifest.json"
test -f "$TARGET_RELEASE/REVISION"

TARGET_REVISION="$(tr -d '[:space:]' < "$TARGET_RELEASE/REVISION")"
test -n "$TARGET_REVISION"

echo "Current:  $CURRENT_RELEASE"
echo "Rollback: $TARGET_RELEASE"
echo "Database migrations are not rolled back by this script."

run_artisan "$CURRENT_RELEASE" down --retry=60
MAINTENANCE=1
activate_release "$TARGET_RELEASE"
SWITCHED=1

run_artisan "$TARGET_RELEASE" optimize:clear
run_artisan "$TARGET_RELEASE" config:cache
run_artisan "$TARGET_RELEASE" route:cache
run_artisan "$TARGET_RELEASE" view:cache
run_artisan "$TARGET_RELEASE" queue:restart
run_artisan "$TARGET_RELEASE" up
curl --connect-timeout 10 --max-time 30 --retry 2 -fsS "$HEALTH_URL" >/dev/null

MAINTENANCE=0
trap - ERR

echo "Rollback complete: $(basename "$TARGET_RELEASE") ($TARGET_REVISION)"
