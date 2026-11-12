#!/bin/sh
set -eu

if [ "$#" -ne 1 ]; then
    echo 'Usage: sh tools/build-pdf-metadata.sh /path/to/Mustang-CLI-2.26.0.jar' >&2
    exit 1
fi
command -v javac >/dev/null 2>&1 || { echo 'Build requires a JDK with javac (Java 11 or newer).' >&2; exit 1; }
shire_metadata_dir=$(CDPATH= cd -- "$(dirname -- "$0")/pdf-metadata" && pwd)
javac --release 11 -encoding UTF-8 -proc:none -cp "$1" -d "$shire_metadata_dir" "$shire_metadata_dir/ShiRePdfMetadata.java"
java -cp "$shire_metadata_dir:$1" ShiRePdfMetadata --check
