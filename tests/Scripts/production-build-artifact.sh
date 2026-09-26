#!/usr/bin/env bash
# Regression test for deploy-production.sh's build archive check: it must
# accept a valid archive whatever position build/manifest.json has (a
# `grep -q` pipeline failed with SIGPIPE when it was not last), and reject
# an archive without the manifest or a corrupt one.

set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/build-artifact-test.XXXXXX")"
trap 'rm -rf -- "$WORK"' EXIT

eval "$(sed -n '/^assert_build_artifact() {/,/^}/p' "$ROOT/scripts/production/deploy-production.sh")"

cd "$WORK"
mkdir -p build/assets
echo '{}' > build/manifest.json
# Enough entries that unzip is still writing when the manifest is found.
for i in $(seq 1 3000); do echo "x" > "build/assets/file-$i.js"; done

zip -qr -X manifest-first.zip build/manifest.json build/assets
zip -qr -X manifest-last.zip build/assets build/manifest.json
zip -qr -X no-manifest.zip build/assets
printf 'not a zip' > corrupt.zip

for attempt in 1 2 3 4 5; do
    assert_build_artifact manifest-first.zip
    assert_build_artifact manifest-last.zip
done

if assert_build_artifact no-manifest.zip; then
    echo "FAIL: an archive without build/manifest.json was accepted" >&2
    exit 1
fi
if assert_build_artifact corrupt.zip >/dev/null 2>&1; then
    echo "FAIL: a corrupt archive was accepted" >&2
    exit 1
fi

echo "production-build-artifact: ok"
