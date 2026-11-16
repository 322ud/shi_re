#!/usr/bin/env bash
set -euo pipefail
project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf -- "$work"' EXIT
if APP_REQUIRE_SIGNING=1 bash "$project_root/tools/build-release.sh" --unsigned "$work/forbidden" > "$work/error" 2>&1; then
    echo 'Required signing bypassed' >&2; exit 1
fi
grep -q 'forbids unsigned builds' "$work/error"
test ! -e "$work/forbidden"
if APP_REQUIRE_SIGNING=invalid bash "$project_root/tools/build-release.sh" --signed > "$work/error" 2>&1; then
    echo 'Invalid signing policy accepted' >&2; exit 1
fi
grep -q 'must be 0 or 1' "$work/error"
export APP_REQUIRE_SIGNING=1
for tool in openssl php tar npm git; do command -v "$tool" >/dev/null; done
openssl req -x509 -newkey rsa:4096 -nodes -keyout "$work/key" -out "$work/cert" -days 1 -subj /CN=shi_re > /dev/null 2>&1
export APP_SIGNING_KEY_FILE="$work/key" APP_SIGNING_CERTIFICATE_FILE="$work/cert"
export NEXTCLOUD_OCC="$project_root/tests/fixtures/signing-occ.php"
archive="$(bash "$project_root/tools/build-release.sh" --signed "$work/first")"
second="$(bash "$project_root/tools/build-release.sh" --signed "$work/second")"
cmp "$archive" "$second"
mkdir "$work/extracted"
tar -xzf "$archive" -C "$work/extracted"
php "$NEXTCLOUD_OCC" integrity:check-app shi_re --path="$work/extracted/shi_re"
test -s "$work/extracted/shi_re/js/validation-actions.js"
test -s "$work/extracted/shi_re/css/validation-actions.css"
test -s "$work/extracted/shi_re/tools/pdf-metadata/ShiRePdfMetadata.class"
if tar -tzf "$archive" | grep -E '(^|/)(node_modules|tests|\.git|\.github|frontend|deploy.sh|build-release.sh)(/|$)|\.(key|pem|p12|pfx)$'; then
    echo 'Unexpected development or credential file in package' >&2; exit 1
fi
openssl base64 -d -A -in "$archive.sig.b64" -out "$work/decoded"
cmp "$archive.sig" "$work/decoded"
openssl x509 -in "$archive.crt" -pubkey -noout > "$work/public"
cp "$archive" "$work/tampered"
printf x >> "$work/tampered"
if openssl dgst -sha512 -verify "$work/public" -signature "$archive.sig" "$work/tampered" >/dev/null 2>&1; then
    echo 'Tampered archive accepted' >&2; exit 1
fi
if bash "$project_root/tools/build-release.sh" --signed "$work/first"; then
    echo 'Existing release overwritten' >&2; exit 1
fi
if APP_SIGNING_KEY_FILE="$work/missing" bash "$project_root/tools/build-release.sh" --signed "$work/missing-output"; then
    echo 'Missing signing key accepted' >&2; exit 1
fi
# A failed Nextcloud integrity check must never yield release artifacts.
if SIGNING_TEST_FAIL_CHECK=1 bash "$project_root/tools/build-release.sh" --signed "$work/rejected"; then
    echo 'Failed integrity check accepted' >&2; exit 1
fi
test ! -e "$work/rejected"
unsigned="$(APP_REQUIRE_SIGNING=0 bash "$project_root/tools/build-release.sh" --unsigned "$work/unsigned")"
if tar -tzf "$unsigned" | grep -q 'appinfo/signature.json'; then exit 1; fi
test ! -e "$unsigned.sig"
echo 'Release packaging, reproducibility, signatures and failure checks passed (test certificate / occ fixture).'
