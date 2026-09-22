#!/usr/bin/env bash

set -Eeuo pipefail

APP_BASE="${APP_BASE:-/home/bluestar/wisperbot.com/deployment}"
PHP_BIN="${PHP_BIN:-/usr/local/bin/php}"
HEALTH_URL="${HEALTH_URL:-https://wisperbot.com/up}"
RELEASES_DIR="$APP_BASE/releases"
CURRENT_LINK="$APP_BASE/current"
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
    local exit_code="${1:-$?}"
    local restored=0
    trap - ERR INT TERM
    set +e

    echo "Rollback failed. Restoring the original release." >&2
    if [[ -n "${CURRENT_RELEASE:-}" && -L "$CURRENT_LINK" && "$(readlink -f "$CURRENT_LINK")" != "$CURRENT_RELEASE" ]]; then
        if activate_release "$CURRENT_RELEASE"; then
            restored=1
        else
            echo "CRITICAL: Could not restore the original release." >&2
        fi
    fi
    if [[ "$MAINTENANCE" -eq 1 || "$restored" -eq 1 ]]; then
        run_artisan "$CURRENT_RELEASE" up
    fi

    exit "$exit_code"
}

trap recover_on_error ERR
trap 'recover_on_error 130' INT
trap 'recover_on_error 143' TERM

test -d "$APP_BASE"
exec 9>"$APP_BASE/.release.lock"
if ! flock -n 9; then
    echo "Another deployment or rollback is already running." >&2
    exit 1
fi

test -L "$CURRENT_LINK"
CURRENT_RELEASE="$(readlink -f "$CURRENT_LINK")"
TARGET_RELEASE="${1:-}"

if [[ -z "$TARGET_RELEASE" ]]; then
    if [[ -f "$CURRENT_RELEASE/PREVIOUS_RELEASE" ]]; then
        previous_name="$(tr -d '\r\n' < "$CURRENT_RELEASE/PREVIOUS_RELEASE")"
        if [[ "$previous_name" != */* && -n "$previous_name" ]]; then
            TARGET_RELEASE="$RELEASES_DIR/$previous_name"
        fi
    fi
elif [[ "$TARGET_RELEASE" != /* ]]; then
    TARGET_RELEASE="$RELEASES_DIR/$TARGET_RELEASE"
fi

test -n "$TARGET_RELEASE" || {
    echo "No previous release is available." >&2
    exit 1
}
TARGET_RELEASE="$(readlink -f "$TARGET_RELEASE")"
test -f "$TARGET_RELEASE/artisan"
test -f "$TARGET_RELEASE/vendor/autoload.php"
test -f "$TARGET_RELEASE/public/build/manifest.json"
test -f "$TARGET_RELEASE/REVISION"

TARGET_REVISION="$(tr -d '[:space:]' < "$TARGET_RELEASE/REVISION")"
test -n "$TARGET_REVISION"

echo "Current:  $CURRENT_RELEASE"
echo "Rollback: $TARGET_RELEASE"
echo "Database migrations are not rolled back by this script."

MAINTENANCE=1
run_artisan "$CURRENT_RELEASE" down --retry=60
activate_release "$TARGET_RELEASE"

run_artisan "$TARGET_RELEASE" optimize:clear
run_artisan "$TARGET_RELEASE" config:cache
run_artisan "$TARGET_RELEASE" route:cache
run_artisan "$TARGET_RELEASE" view:cache
run_artisan "$TARGET_RELEASE" queue:restart
run_artisan "$TARGET_RELEASE" up
curl --connect-timeout 10 --max-time 30 --retry 2 -fsS "$HEALTH_URL" >/dev/null

MAINTENANCE=0
trap - ERR INT TERM

echo "Rollback complete: $(basename "$TARGET_RELEASE") ($TARGET_REVISION)"
