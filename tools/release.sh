#!/bin/sh
# SPDX-FileCopyrightText: 2026 Johannes Kolb
# SPDX-License-Identifier: AGPL-3.0-or-later

# The release steps of docs/development.md#release, in two phases. Each stops where the maintainer
# acts: prepare before the commit, build before the upload. Neither commits, pushes or uploads.
#
#   npm run release -- prepare <YYYY-MM-DD>
#   npm run release -- build --key <path> --cert <path>

set -eu

# The versions users upgrade from, as tools/upgrade-check.sh takes them: 0.2.0's and 0.3.0's
# source, since neither was released. Once a release is out, its tarball replaces them. Oracle
# checks the first only.
BASES="9056da5 e7bdbe1"

usage() {
	echo "usage: $0 prepare <YYYY-MM-DD>" >&2
	echo "       $0 build --key <path> --cert <path>" >&2
	exit 2
}

fail() {
	echo "release: $*" >&2
	exit 1
}

case ${1:-} in
	prepare | build) phase=$1 ;;
	*) usage ;;
esac
shift

cd "$(dirname "$0")/.."

# Untracked files count: the review lists them, so they belong to the release or must go.
changes=$(git status --porcelain)
[ -z "$changes" ] || fail "the tree has uncommitted changes; commit or remove them first"

version=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)
unreleased="## $version — not released"

prepare() {
	[ $# -eq 1 ] || usage
	date=$1
	echo "$date" | grep -qxE '[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])' ||
		fail "the date is '$date', not YYYY-MM-DD"

	grep -qxF "$unreleased" CHANGELOG.md || fail "CHANGELOG.md has no line '$unreleased'"
	# No sed -i: BSD and GNU sed disagree on its argument.
	awk -v from="$unreleased" -v to="## $version — $date" '$0 == from { $0 = to } { print }' \
		CHANGELOG.md >CHANGELOG.md.new
	mv CHANGELOG.md.new CHANGELOG.md
	# Everything released is promised (docs/api.md#what-v1-promises).
	cp openapi.json tests/Api/v1-baseline.json

	# A rerun refuses the tree these edits leave.
	trap 'echo "release: a check failed. To start over: git checkout CHANGELOG.md tests/Api/v1-baseline.json" >&2' EXIT
	npm run build
	composer test
	composer lint
	npm test
	npm run lint
	trap - EXIT

	cat <<EOF

All checks passed. Review the diff, then commit now with this message:

Release $version

Date the CHANGELOG section and promise the API as it stands:
tests/Api/v1-baseline.json is now openapi.json.

Then run: npm run release -- build --key <path> --cert <path>
EOF
}

build() {
	key=
	cert=
	while [ $# -ge 2 ]; do
		case $1 in
			--key) key=$2 ;;
			--cert) cert=$2 ;;
			*) usage ;;
		esac
		shift 2
	done
	[ $# -eq 0 ] && [ -n "$key" ] && [ -n "$cert" ] || usage
	for file in "$key" "$cert"; do
		[ -f "$file" ] || fail "there is no file $file"
	done
	key=$(realpath "$key")
	cert=$(realpath "$cert")
	case $key in
		"$(pwd -P)"/*) fail "the key is inside the repository; keep it offline (docs/security.md#supply-chain)" ;;
	esac

	if grep -qxF "$unreleased" CHANGELOG.md || ! cmp -s openapi.json tests/Api/v1-baseline.json; then
		fail "run npm run release -- prepare <YYYY-MM-DD> and commit first"
	fi

	tarball=build/artifacts/nextfleet-$version.tar.gz
	npm run package

	# Signed and checked on a throwaway server of the upgrade check's stack, so neither the dev
	# servers nor a mount of the tree is needed.
	compose="docker compose -f tools/upgrade-check.compose.yml -f tools/upgrade-check.mariadb.yml"
	trap '$compose down -v --remove-orphans >/dev/null 2>&1 || true' EXIT
	trap 'exit 130' INT TERM
	$compose down -v --remove-orphans >/dev/null 2>&1
	$compose up -d --wait --quiet-pull app34

	rm -rf build/sign
	mkdir -p build/sign
	tar -xzf "$tarball" -C build/sign
	# Public, and kept beside the build; git ignores build/ and package.sh never packs it.
	cp "$cert" build/nextfleet.crt
	$compose cp build/sign/nextfleet app34:/tmp/nextfleet
	$compose cp build/nextfleet.crt app34:/tmp/nextfleet.crt
	$compose exec -T app34 chown -R www-data:www-data /tmp/nextfleet
	# The key goes over stdin only: no file in the container, the tree or build/.
	$compose exec -T -u www-data app34 php occ integrity:sign-app --privateKey=php://stdin \
		--certificate=/tmp/nextfleet.crt --path=/tmp/nextfleet <"$key"
	$compose cp app34:/tmp/nextfleet/appinfo/signature.json build/sign/nextfleet/appinfo/signature.json
	tar --sort=name --owner=0 --group=0 --numeric-owner -czf "$tarball" -C build/sign nextfleet

	# Installed the way a user's server gets it. check-app passes an app with no signature.json
	# silently, so only its verbose "No errors found" counts.
	$compose cp "$tarball" app34:/tmp/nextfleet.tar.gz
	$compose exec -T -u www-data app34 sh -c \
		'rm -rf custom_apps/nextfleet && tar -xzf /tmp/nextfleet.tar.gz -C custom_apps'
	$compose exec -T -u www-data app34 php occ app:enable nextfleet
	if ! checked=$($compose exec -T -u www-data app34 php occ integrity:check-app -v nextfleet); then
		fail "the signed tarball does not check out: $checked"
	fi
	case $checked in
		*'No errors found'*) ;;
		*) fail "the signed tarball does not check out: $checked" ;;
	esac
	$compose down -v --remove-orphans

	for base in $BASES; do
		sh tools/upgrade-check.sh "$tarball" "$base"
	done
	for base in $BASES; do
		sh tools/upgrade-check.sh --db pgsql "$tarball" "$base"
	done
	sh tools/upgrade-check.sh --db oracle "$tarball" "${BASES%% *}"

	# A file, not a pipe: sh has no pipefail, and a failed signing would print an empty one.
	openssl dgst -sha512 -sign "$key" -out build/nextfleet.sig "$tarball"
	signature=$(openssl base64 -in build/nextfleet.sig)
	rm build/nextfleet.sig

	cat <<EOF

$tarball is signed and checked. Its signature for the app store:

$signature

Next: publish the source and upload (docs/development.md#release, steps 7 and 8).
EOF
}

"$phase" "$@"
