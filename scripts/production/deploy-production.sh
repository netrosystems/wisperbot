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

prune_old_releases() {
    local retained=("$RELEASE_PATH")
    local cursor="$RELEASE_PATH"
    local previous_name previous_path candidate release_name revision kept
    local preserve_zip

    if [[ ! -L "$CURRENT_LINK" || "$(readlink -f "$CURRENT_LINK")" != "$RELEASE_PATH" ]]; then
        echo "Warning: Active release changed; skipping cleanup." >&2
        return 0
    fi

    while (( ${#retained[@]} < 3 )) && [[ -f "$cursor/PREVIOUS_RELEASE" ]]; do
        previous_name="$(< "$cursor/PREVIOUS_RELEASE")"
        [[ "$previous_name" =~ ^[0-9]{8}-[0-9]{6}-[a-f0-9]{7,40}$ ]] || break
        previous_path="$RELEASES_DIR/$previous_name"
        [[ -d "$previous_path" && ! -L "$previous_path" ]] || break
        retained+=("$previous_path")
        cursor="$previous_path"
    done

    for candidate in "$RELEASES_DIR"/*; do
        [[ -d "$candidate" && ! -L "$candidate" ]] || continue
        release_name="${candidate##*/}"
        [[ "$release_name" =~ ^[0-9]{8}-[0-9]{6}-[a-f0-9]{7,40}$ ]] || continue

        for kept in "${retained[@]}"; do
            [[ "$candidate" == "$kept" ]] && continue 2
        done

        revision=""
        if [[ -f "$candidate/REVISION" && ! -L "$candidate/REVISION" ]]; then
            revision="$(< "$candidate/REVISION")"
        fi

        if ! rm -rf -- "$candidate"; then
            echo "Warning: Could not remove old release: $candidate" >&2
            continue
        fi
        echo "Removed old release: $release_name"

        preserve_zip=0
        for kept in "${retained[@]}"; do
            if [[ -f "$kept/REVISION" && "$(< "$kept/REVISION")" == "$revision" ]]; then
                preserve_zip=1
                break
            fi
        done
        if (( preserve_zip == 0 )) && [[ "$revision" =~ ^[a-f0-9]{40}$ ]] && [[ -f "$ARTIFACTS_DIR/build-$revision.zip" && ! -L "$ARTIFACTS_DIR/build-$revision.zip" ]]; then
            rm -f -- "$ARTIFACTS_DIR/build-$revision.zip" || echo "Warning: Could not remove old build ZIP for $release_name" >&2
        fi
    done
}

recover_on_error() {
    local exit_code="${1:-$?}"
    local restored=0
    trap - ERR INT TERM
    set +e

    echo "Deployment failed. Attempting to restore the previous release." >&2
    if [[ -n "$PREVIOUS_RELEASE" && -f "$PREVIOUS_RELEASE/artisan" && -L "$CURRENT_LINK" && "$(readlink -f "$CURRENT_LINK")" != "$PREVIOUS_RELEASE" ]]; then
        if activate_release "$PREVIOUS_RELEASE"; then
            restored=1
        else
            echo "CRITICAL: Could not restore the previous release." >&2
        fi
    fi
    if [[ ( "$MAINTENANCE" -eq 1 || "$restored" -eq 1 ) && -L "$CURRENT_LINK" ]]; then
        run_artisan "$(readlink -f "$CURRENT_LINK")" up
    fi

    echo "Incomplete release retained for inspection: ${RELEASE_PATH:-not created}" >&2
    exit "$exit_code"
}

trap recover_on_error ERR
trap 'recover_on_error 130' INT
trap 'recover_on_error 143' TERM

mkdir -p "$APP_BASE"
exec 9>"$APP_BASE/.release.lock"
if ! flock -n 9; then
    echo "Another deployment or rollback is already running." >&2
    exit 1
fi

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
chmod o+x "$RELEASE_PATH"
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
    MAINTENANCE=1
    run_artisan "$PREVIOUS_RELEASE" down --retry=60
fi

run_artisan "$RELEASE_PATH" migrate --force
activate_release "$RELEASE_PATH"
run_artisan "$RELEASE_PATH" app:deploy:finalize --revision="$TARGET_SHA"
run_artisan "$RELEASE_PATH" up
MAINTENANCE=0
curl --connect-timeout 10 --max-time 30 --retry 2 -fsS "$HEALTH_URL" >/dev/null
if [[ -n "$PREVIOUS_RELEASE" ]]; then
    printf '%s\n' "$(basename "$PREVIOUS_RELEASE")" > "$RELEASE_PATH/PREVIOUS_RELEASE"
fi

trap - ERR INT TERM
echo "Deployment complete: $RELEASE_NAME ($TARGET_SHA)"
echo "Previous release: ${PREVIOUS_RELEASE:-none}"
prune_old_releases || echo "Warning: Release cleanup did not complete." >&2
