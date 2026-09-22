#!/usr/bin/env bash

set -Eeuo pipefail

APP_BASE="${APP_BASE:-/home/bluestar/wisperbot.com/deployment}"
SOURCE_REPO="${SOURCE_REPO:-/home/bluestar/wisperbot.com}"
PHP_BIN="${PHP_BIN:-/usr/local/bin/php}"
COMPOSER_BIN="${COMPOSER_BIN:-/home/bluestar/bin/composer}"
HEALTH_URL="${HEALTH_URL:-https://wisperbot.com/up}"

RELEASES_DIR="$APP_BASE/releases"
ARTIFACTS_DIR="$APP_BASE/artifacts"
CURRENT_LINK="$APP_BASE/current"
PREVIOUS_RELEASE=""
RELEASE_PATH=""
ACTIVATED=0
MAINTENANCE=0

run_artisan() {
    local release="$1"
    shift
    (cd "$release" && "$PHP_BIN" artisan "$@")
}

activate_release() {
    local release="$1"
    local next_link="$APP_BASE/.current-$(date +%s)-$$"
    ln -s "$release" "$next_link"
    mv -Tf "$next_link" "$CURRENT_LINK"
}

recover_on_error() {
    local exit_code=$?
    set +e

    echo "Deployment failed. Attempting to restore the previous release." >&2
    if [[ "$ACTIVATED" -eq 1 && -n "$PREVIOUS_RELEASE" && -f "$PREVIOUS_RELEASE/artisan" ]]; then
        activate_release "$PREVIOUS_RELEASE"
    fi
    if [[ "$MAINTENANCE" -eq 1 && -L "$CURRENT_LINK" ]]; then
        run_artisan "$(readlink -f "$CURRENT_LINK")" up
    fi

    echo "Incomplete release retained for inspection: ${RELEASE_PATH:-not created}" >&2
    exit "$exit_code"
}

trap recover_on_error ERR

mkdir -p "$RELEASES_DIR" "$ARTIFACTS_DIR"
test -d "$SOURCE_REPO/.git"
test -f "$APP_BASE/shared/.env"
test -e "$APP_BASE/shared/storage"

git -C "$SOURCE_REPO" fetch origin main
TARGET_SHA="$(git -C "$SOURCE_REPO" rev-parse origin/main)"
SHORT_SHA="$(git -C "$SOURCE_REPO" rev-parse --short origin/main)"
BUILD_ZIP="$ARTIFACTS_DIR/build-$TARGET_SHA.zip"

test -f "$BUILD_ZIP" || {
    echo "Missing frontend artifact: $BUILD_ZIP" >&2
    exit 1
}
unzip -tqq "$BUILD_ZIP"
unzip -Z1 "$BUILD_ZIP" | grep -qx 'build/manifest.json'

RELEASE_NAME="$(date +%Y%m%d-%H%M%S)-$SHORT_SHA"
RELEASE_PATH="$RELEASES_DIR/$RELEASE_NAME"
mkdir "$RELEASE_PATH"
git -C "$SOURCE_REPO" archive "$TARGET_SHA" | tar -x -C "$RELEASE_PATH"

CONFIG_SOURCE="$SOURCE_REPO/public"
if [[ -L "$CURRENT_LINK" ]]; then
    PREVIOUS_RELEASE="$(readlink -f "$CURRENT_LINK")"
    CONFIG_SOURCE="$PREVIOUS_RELEASE/public"
fi

for config_file in .htaccess .user.ini php.ini; do
    if [[ -f "$CONFIG_SOURCE/$config_file" ]]; then
        cp "$CONFIG_SOURCE/$config_file" "$RELEASE_PATH/public/$config_file"
    fi
done
if [[ -d "$CONFIG_SOURCE/.well-known" ]]; then
    cp -a "$CONFIG_SOURCE/.well-known" "$RELEASE_PATH/public/.well-known"
fi

mv "$RELEASE_PATH/storage" "$RELEASE_PATH/storage-skeleton"
ln -s "$APP_BASE/shared/storage" "$RELEASE_PATH/storage"
ln -s "$APP_BASE/shared/.env" "$RELEASE_PATH/.env"
unzip -q "$BUILD_ZIP" -d "$RELEASE_PATH/public"
printf '%s\n' "$TARGET_SHA" > "$RELEASE_PATH/REVISION"

(cd "$RELEASE_PATH" && "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction)
chmod -R ug+rwX "$RELEASE_PATH/bootstrap/cache"
run_artisan "$RELEASE_PATH" storage:link
run_artisan "$RELEASE_PATH" about >/dev/null
test -f "$RELEASE_PATH/public/build/manifest.json"

if [[ -n "$PREVIOUS_RELEASE" ]]; then
    run_artisan "$PREVIOUS_RELEASE" down --retry=60
    MAINTENANCE=1
fi

run_artisan "$RELEASE_PATH" migrate --force
activate_release "$RELEASE_PATH"
ACTIVATED=1
run_artisan "$RELEASE_PATH" app:deploy:finalize --revision="$TARGET_SHA"
run_artisan "$RELEASE_PATH" up
MAINTENANCE=0
curl --connect-timeout 10 --max-time 30 --retry 2 -fsS "$HEALTH_URL" >/dev/null

trap - ERR
echo "Deployment complete: $RELEASE_NAME ($TARGET_SHA)"
echo "Previous release: ${PREVIOUS_RELEASE:-none}"
