#!/usr/bin/env bash
# Package Dialoga into a WordPress.org-ready zip.
#
# Output: build/dialoga.zip (entries rooted at "dialoga/" so WordPress.org's
# slug matches). The staging dir is rebuilt from scratch every run, so the
# artifact is always a faithful, residue-free copy of the working tree.
#
# Exclusions have a single source of truth: .distignore (same file wp-cli's
# dist-archive would honor). Only extra OS junk (.DS_Store, ._*, __MACOSX)
# is filtered in addition to it.
#
# Usage: bash tools/build-zip.sh  (or "npm run package")
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DISTIGNORE="${ROOT}/.distignore"
STAGE="${ROOT}/build/dialoga"
ZIP="${ROOT}/build/dialoga.zip"
ZIPDIR="$(dirname "${ZIP}")"

# --- Version from the plugin header (only place it is authoritative) --------
VERSION="$(grep '^ \* Version:' "${ROOT}/dialoga.php" | sed 's/[^0-9.]*//g' | head -n1)"
if [ -z "${VERSION}" ]; then
    echo "ERROR: could not read 'Version:' from dialoga.php" >&2
    exit 1
fi
echo "Packaging Dialoga ${VERSION} ..."

# --- Fresh staging ----------------------------------------------------------
rm -rf "${STAGE}" "${ZIP}"
mkdir -p "${STAGE}" "${ZIPDIR}"

# --- rsync honoring .distignore + OS junk -----------------------------------
EXCLUDES=()
while IFS= read -r line || [ -n "$line" ]; do
    [ -z "${line}" ] && continue
    EXCLUDES+=(--exclude "${line}")
done < "${DISTIGNORE}"
EXCLUDES+=(--exclude '.DS_Store' --exclude '._*' --exclude '__MACOSX')

rsync -a "${EXCLUDES[@]}" "${ROOT}/" "${STAGE}/"

# --- Sanity: production files present, no stale build -----------------------
for required in dialoga.php uninstall.php readme.txt includes templates assets languages; do
    [ -e "${STAGE}/${required}" ] || { echo "ERROR: missing ${required} in staging" >&2; exit 1; }
done

# --- Zip with "dialoga/" as the root entry ----------------------------------
(
    cd "${ZIPDIR}"
    zip -rq dialoga.zip dialoga
)

# --- Validation -------------------------------------------------------------
if [ ! -s "${ZIP}" ]; then
    echo "ERROR: ${ZIP} was not produced or is empty" >&2
    exit 1
fi

LISTING="$(unzip -Z1 "${ZIP}")"

# Never ship anything that isn't production code.
if echo "${LISTING}" | grep -Eq '(^|/)\.git(/|$)|(^|/)tests(/|$)|(^|/)tools(/|$)|(^|/)src(/|$)|(^|/)node_modules(/|$)'; then
    echo "ERROR: dev-only paths leaked into the zip:" >&2
    echo "${LISTING}" | grep -E '(^|/)\.git(/|$)|(^|/)tests(/|$)|(^|/)tools(/|$)|(^|/)src(/|$)|(^|/)node_modules(/|$)' >&2
    exit 1
fi

# Hidden files of any kind are rejected by WordPress.org plugin checks.
if echo "${LISTING}" | grep -E '(^|/)\.[^/.]+(/|$)|/\.DS_Store' | grep -v 'dialoga/$' >/dev/null; then
    echo "ERROR: hidden files found in the zip:" >&2
    echo "${LISTING}" | grep -E '(^|/)\.[^/.]+(/|$)|/\.DS_Store' >&2
    exit 1
fi

# Zip must be rooted at dialoga/ only (no sibling entries, no ../ escapes).
if echo "${LISTING}" | grep -Evq '^dialoga(/|$)'; then
    echo "ERROR: zip contains entries outside dialoga/:" >&2
    echo "${LISTING}" | grep -Ev '^dialoga(/|$)' >&2
    exit 1
fi

SIZES="$(ls -lh "${ZIP}" | awk '{print $5}')"
FILES="$(echo "${LISTING}" | grep -vc '/$')"
echo "OK  ${ZIP} (${SIZES}, ${FILES} files)"
echo
unzip -l "${ZIP}" | head -n 24
echo "  ..."
echo "Full listing: unzip -l ${ZIP}"
