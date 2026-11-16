#!/usr/bin/env bash
# Local package/signing only; never uploads or publishes.
set -euo pipefail
umask 077
project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
require_signing="${APP_REQUIRE_SIGNING:-0}"
if [[ "$require_signing" != 0 && "$require_signing" != 1 ]]; then
    echo 'APP_REQUIRE_SIGNING must be 0 or 1.' >&2; exit 1
fi
mode="${1:-}"
if [[ "$require_signing" == 1 && "$mode" == --unsigned ]]; then
    echo 'APP_REQUIRE_SIGNING=1 forbids unsigned builds.' >&2; exit 1
fi
if [[ "$mode" != --signed && "$mode" != --unsigned ]]; then
    echo 'Usage: bash tools/build-release.sh --signed|--unsigned [output-directory]' >&2
    exit 1
fi
for dependency in php npm tar gzip git realpath sha256sum; do
    command -v "$dependency" >/dev/null || { echo "Required tool missing: $dependency" >&2; exit 1; }
done
version="$(php -r '$x = simplexml_load_file($argv[1]); if ((string)$x->id !== "shi_re") exit(1); echo $x->version;' "$project_root/appinfo/info.xml")"
if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z]+([.-][0-9A-Za-z]+)*)?$ ]]; then
    echo 'Invalid app version' >&2; exit 1
fi
output_directory="$(realpath -m "${2:-$project_root/dist}")"
release_directory="$output_directory/shi_re-$version-${mode#--}"
if [[ -e "$release_directory" ]]; then
    echo "Output already exists: $release_directory (choose another output directory)" >&2; exit 1
fi
if [[ "$mode" == --signed ]]; then
    command -v openssl >/dev/null
    signing_key="${APP_SIGNING_KEY_FILE:-}"
    signing_certificate="${APP_SIGNING_CERTIFICATE_FILE:-}"
    nextcloud_occ="${NEXTCLOUD_OCC:-}"
    if [[ ! -f "$signing_key" || ! -r "$signing_key" || ! -f "$signing_certificate" || ! -r "$signing_certificate" || ! -f "$nextcloud_occ" ]]; then
        echo 'Set APP_SIGNING_KEY_FILE, APP_SIGNING_CERTIFICATE_FILE and NEXTCLOUD_OCC to readable files.' >&2; exit 1
    fi
    signing_key="$(realpath "$signing_key")"
    signing_certificate="$(realpath "$signing_certificate")"
    nextcloud_occ="$(realpath "$nextcloud_occ")"
    php "$project_root/tools/check-signing-material.php" "$signing_key" "$signing_certificate" shi_re
fi
staging_directory="$(mktemp -d)"
trap 'rm -rf -- "$staging_directory"' EXIT
package_root="$staging_directory/shi_re"
mkdir -p "$package_root" "$staging_directory/frontend-build" "$staging_directory/artifacts"
# Only tracked files from runtime directories; use current working-tree contents.
# No checkout credentials, node_modules, tests, deploy scripts or unrelated tools.
while IFS= read -r -d '' entry; do
    case "$entry" in
        appinfo/signature.json) continue ;;
        appinfo/*|config/*|css/*|img/*|js/*|lib/*|templates/*|tools/pdf-metadata/*|tools/build-pdf-metadata.sh|README.md|LICENSE|COPYING|LICENSES/*|THIRD_PARTY_NOTICES.md|CHANGELOG.md) ;;
        *) continue ;;
    esac
    if [[ -L "$project_root/$entry" || ! -f "$project_root/$entry" ]]; then
        echo "Not a regular release file: $entry" >&2; exit 1
    fi
    case "$entry" in *.key|*.pem|*.p12|*.pfx|*.csr|*/.env*) echo "Credential-like file refused: $entry" >&2; exit 1 ;; esac
    mkdir -p "$package_root/$(dirname "$entry")"
    cp -- "$project_root/$entry" "$package_root/$entry"
done < <(git -C "$project_root" ls-files -z)
for required in appinfo/info.xml config/X-Rechnung.odt config/en16931.xml config/mapping.tsv img/app.svg tools/pdf-metadata/ShiRePdfMetadata.class tools/pdf-metadata/ShiRePdfMetadata.java README.md; do
    test -s "$package_root/$required" || { echo "Missing release file: $required" >&2; exit 1; }
done
# Build in isolation: do not modify the checkout's generated files or node_modules.
cp -- "$project_root/package.json" "$project_root/package-lock.json" "$staging_directory/frontend-build/"
cp -R -- "$project_root/frontend" "$staging_directory/frontend-build/"
(
    cd "$staging_directory/frontend-build"
    mkdir css
    npm ci --no-audit --no-fund >&2
    npm run build >&2
)
cp -- "$staging_directory/frontend-build/js/validation-actions.js" "$package_root/js/"
cp -- "$staging_directory/frontend-build/css/validation-actions.css" "$package_root/css/"
# esbuild may produce separate license notice files.
for notice in "$staging_directory/frontend-build/js/"*.LEGAL.txt "$staging_directory/frontend-build/css/"*.LEGAL.txt; do
    if [[ -f "$notice" ]]; then
        target="$(basename "$(dirname "$notice")")"
        cp -- "$notice" "$package_root/$target/"
    fi
done
find "$package_root" -type d -exec chmod 0755 {} +
find "$package_root" -type f -exec chmod 0644 {} +
if [[ "$mode" == --signed ]]; then
    php "$nextcloud_occ" integrity:sign-app --privateKey="$signing_key" --certificate="$signing_certificate" --path="$package_root" >&2
    test -s "$package_root/appinfo/signature.json"
    chmod 0644 "$package_root/appinfo/signature.json"
    php "$nextcloud_occ" integrity:check-app shi_re --path="$package_root" >&2
fi
artifact_root="$staging_directory/artifacts"
archive="$artifact_root/shi_re-$version.tar.gz"
tar --create --directory "$staging_directory" --sort=name --mtime='@0' --owner=0 --group=0 --numeric-owner --format=ustar shi_re | gzip -n > "$archive"
if [[ "$mode" == --signed ]]; then
    openssl dgst -sha512 -sign "$signing_key" -out "$archive.sig" "$archive"
    openssl base64 -A -in "$archive.sig" -out "$archive.sig.b64"
    cp -- "$signing_certificate" "$archive.crt"
    openssl x509 -in "$archive.crt" -pubkey -noout > "$staging_directory/public.pem"
    openssl dgst -sha512 -verify "$staging_directory/public.pem" -signature "$archive.sig" "$archive" >&2
fi
(
    cd "$artifact_root"
    checksum_entries=("shi_re-$version.tar.gz")
    if [[ "$mode" == --signed ]]; then
        checksum_entries+=("shi_re-$version.tar.gz.sig" "shi_re-$version.tar.gz.sig.b64" "shi_re-$version.tar.gz.crt")
    fi
    sha256sum "${checksum_entries[@]}" > "shi_re-$version.tar.gz.sha256"
    sha256sum --check --strict "shi_re-$version.tar.gz.sha256" >&2
)
# Only expose a complete, verified set. Never overwrite an existing release.
mkdir -p "$output_directory"
mkdir "$release_directory"
cp -- "$artifact_root/"* "$release_directory/"
printf '%s\n' "$release_directory/shi_re-$version.tar.gz"
