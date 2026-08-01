#!/usr/bin/env bash
#
# Build the archive an administrator installs.
#
# What goes in is deliberately narrow: the app as it runs, and nothing about how
# it is developed. Sources, tests, node_modules and the composer dev
# dependencies would multiply the download by two orders of magnitude and give a
# reviewer more to read than the app itself.
#
# The bundle is built here rather than committed, so a release can never ship a
# stale one — that mismatch is invisible until the page renders empty.
set -euo pipefail

APP_ID=done_transcription
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT="${ROOT}/build"

VERSION=$(sed -n 's|.*<version>\(.*\)</version>.*|\1|p' "${ROOT}/appinfo/info.xml" | head -1)
if [[ -z "${VERSION}" ]]; then
	echo "could not read the version from appinfo/info.xml" >&2
	exit 1
fi

echo "building ${APP_ID} ${VERSION}"

npm ci --silent
npm run build --silent

rm -rf "${OUT}"
mkdir -p "${OUT}/${APP_ID}"

# No vendor/: every composer dependency is dev-only, and Nextcloud autoloads an
# app's own classes from lib/ by itself. Shipping one would only add code nobody
# audited.
for item in appinfo lib templates img js l10n README.md COPYING; do
	if [[ -e "${ROOT}/${item}" ]]; then
		cp -R "${ROOT}/${item}" "${OUT}/${APP_ID}/"
	fi
done

# COPYFILE_DISABLE keeps macOS from writing its ._ AppleDouble companions into
# the archive. Nextcloud's autoloader treats ._PageController.php as a class
# file and fails to load it, on every request, with an error that names a class
# nobody wrote.
# Source maps are for development; shipping them multiplies the archive and
# exposes the un-minified source. COPYFILE_DISABLE keeps macOS from adding ._
# companions the autoloader would choke on.
COPYFILE_DISABLE=1 tar -czf "${OUT}/${APP_ID}-${VERSION}.tar.gz" \
	--exclude '._*' --exclude '.DS_Store' --exclude '*.map' \
	-C "${OUT}" "${APP_ID}"
rm -rf "${OUT:?}/${APP_ID}"

echo "built ${OUT}/${APP_ID}-${VERSION}.tar.gz"
ls -lh "${OUT}/${APP_ID}-${VERSION}.tar.gz" | awk '{print "  size:", $5}'
