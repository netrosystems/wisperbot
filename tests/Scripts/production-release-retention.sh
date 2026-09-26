#!/usr/bin/env bash

set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
APP_BASE="$(mktemp -d "$ROOT/.release-retention-test.XXXXXX")"
trap '[[ "$APP_BASE" == "$ROOT"/.release-retention-test.* ]] && rm -rf -- "$APP_BASE"' EXIT

RELEASES_DIR="$APP_BASE/releases"
ARTIFACTS_DIR="$APP_BASE/artifacts"
CURRENT_LINK="$APP_BASE/current"
mkdir -p "$RELEASES_DIR" "$ARTIFACTS_DIR" "$APP_BASE/shared/storage"

eval "$(sed -n '/^prune_old_releases() {/,/^}/p' "$ROOT/scripts/production/deploy-production.sh")"

make_release() {
    local name="$1" revision="$2" previous="${3:-}"
    mkdir "$RELEASES_DIR/$name"
    printf '%s\n' "$revision" > "$RELEASES_DIR/$name/REVISION"
    if [[ -n "$previous" ]]; then
        printf '%s\n' "$previous" > "$RELEASES_DIR/$name/PREVIOUS_RELEASE"
    fi
    : > "$ARTIFACTS_DIR/build-$revision.zip"
}

link_release() {
    local target="$1" link="$2"
    if [[ "$(uname -s)" == MINGW* ]]; then
        powershell.exe -NoProfile -NonInteractive -Command "New-Item -ItemType Junction -Path '$(cygpath -w "$link")' -Target '$(cygpath -w "$target")' | Out-Null"
    else
        ln -s "$target" "$link"
    fi
}

r1=20260918-120000-aaaaaaaa
r2=20260919-120000-bbbbbbbb
r3=20260920-120000-cccccccc
r4=20260921-120000-dddddddd
orphan=20260917-120000-eeeeeeee
printf -v sha1 '%040d' 1
printf -v sha2 '%040d' 2
printf -v sha3 '%040d' 3
printf -v sha4 '%040d' 4

make_release "$r1" "$sha1"
make_release "$r2" "$sha2" "$r1"
make_release "$r3" "$sha3" "$r2"
make_release "$r4" "$sha4" "$r3"
make_release "$orphan" "$sha3"
mkdir "$RELEASES_DIR/20260916-120000-ffffffff"
: > "$ARTIFACTS_DIR/unrelated.zip"
link_release "$RELEASES_DIR/$r4" "$CURRENT_LINK"
RELEASE_PATH="$RELEASES_DIR/$r4"

prune_old_releases

[[ ! -e "$RELEASES_DIR/$r1" && ! -e "$ARTIFACTS_DIR/build-$sha1.zip" ]]
[[ -d "$RELEASES_DIR/$r2" && -d "$RELEASES_DIR/$r3" && -d "$RELEASES_DIR/$r4" ]]
[[ ! -e "$RELEASES_DIR/$orphan" && -f "$ARTIFACTS_DIR/build-$sha3.zip" ]]
[[ ! -e "$RELEASES_DIR/20260916-120000-ffffffff" && -f "$ARTIFACTS_DIR/unrelated.zip" ]]
[[ -d "$APP_BASE/shared/storage" ]]

make_release "$r1" "$sha1"
link_release "$RELEASES_DIR/$r3" "$APP_BASE/new-current"
CURRENT_LINK="$APP_BASE/new-current"
prune_old_releases
[[ -d "$RELEASES_DIR/$r1" ]]

echo 'Release retention checks passed.'
