#!/bin/sh
# SPDX-FileCopyrightText: 2026 Johannes Kolb
# SPDX-License-Identifier: AGPL-3.0-or-later

# Builds the app store tarball, build/artifacts/nextfleet-<version>.tar.gz, unsigned.
# Signing is a maintainer's step on a machine that holds the key (docs/security.md).
#
# It copies a list rather than the tree: PHP has no runtime dependencies, so the tarball is our
# own code and the bundle, nothing else. tests/Unit/PackageTest.php fails when a top-level entry
# is in neither list.

set -eu

SHIP="appinfo css img js l10n lib templates CHANGELOG.md LICENSE LICENSES README.md"
LEAVE=".docker .eslintignore .eslintrc.cjs .github .gitignore .php-cs-fixer.dist.php .stylelintignore CONTEXT.md CONTRIBUTING.md REUSE.toml SECURITY.md composer.json composer.lock design docs package-lock.json package.json phpunit.integration.xml phpunit.xml plan.md playwright.config.js psalm-baseline.xml psalm.xml src stylelint.config.cjs tests tools tsconfig.json vite.config.js vitest.config.js"

cd "$(dirname "$0")/.."

version=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)
stage=build/stage/nextfleet
tarball=build/artifacts/nextfleet-$version.tar.gz

# The build does not empty its output directory, so chunks of every earlier build are still
# there. Only a bundle built into empty directories is the one the lock file describes.
rm -rf js build/stage "$tarball"
rm -f css/nextfleet-*.css css/*.chunk.css
npm ci --no-audit --no-fund
npm run build

mkdir -p "$stage" build/artifacts
# Word splitting is the point: SHIP is a list.
# shellcheck disable=SC2086
cp -R $SHIP "$stage"/
tar --sort=name --owner=0 --group=0 --numeric-owner -czf "$tarball" -C build/stage nextfleet

for needed in appinfo/info.xml js/nextfleet-main.mjs js/nextfleet-settings.mjs; do
	tar -tzf "$tarball" "nextfleet/$needed" >/dev/null
done
echo "$tarball"
