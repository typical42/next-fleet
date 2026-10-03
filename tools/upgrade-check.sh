#!/bin/sh
# SPDX-FileCopyrightText: 2026 Johannes Kolb
# SPDX-License-Identifier: AGPL-3.0-or-later

# Installs a package on throwaway NC 31 and NC 34 servers, first fresh, then as an upgrade over
# the version users have today, and fails unless every row that version wrote survives.
#
#   tools/upgrade-check.sh <tarball> [<base>]
#
# <tarball> is the package to check: pass the file you are about to sign, not a rebuild of it.
# <base> is the version users have, as a git ref or a tarball. It defaults to 27142b4, the 0.2.0
# commit, because no release is tagged yet; once one is, pass its tag.
#
# The servers are tools/upgrade-check.compose.yml, its own project with its own volumes. They are
# removed at the end, pass or fail. The dev servers in .docker/compose.yml are never touched.

set -eu

if [ $# -lt 1 ] || [ ! -f "$1" ]; then
	echo "usage: $0 <tarball> [<base ref or tarball>]" >&2
	exit 2
fi
tarball=$(realpath "$1")
base=${2:-27142b4}
if [ -f "$base" ]; then
	base=$(realpath "$base")
fi

cd "$(dirname "$0")/.."

compose="docker compose -f tools/upgrade-check.compose.yml"
work=$(mktemp -d)

cleanup() {
	$compose down -v --remove-orphans >/dev/null 2>&1 || true
	if [ -d "$work/base" ]; then
		git worktree remove --force "$work/base"
	fi
	rm -rf "$work"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

fail() {
	echo "FAIL: $*" >&2
	exit 1
}

version() {
	tar -xOzf "$1" nextfleet/appinfo/info.xml | sed -n 's:.*<version>\(.*\)</version>.*:\1:p'
}

# The base is built the way it was released: by its own package script, from its own tree, in a
# worktree outside this one so nothing here leaks into it.
if [ -f "$base" ]; then
	cp "$base" "$work/base.tar.gz"
else
	git worktree add --detach "$work/base" "$base"
	sh "$work/base/tools/package.sh"
	cp "$work"/base/build/artifacts/nextfleet-*.tar.gz "$work/base.tar.gz"
	git worktree remove --force "$work/base"
fi

new=$(version "$tarball")
old=$(version "$work/base.tar.gz")
echo "Checking $new, fresh and over $old"

schema() {
	case $1 in
		app34) echo nextcloud ;;
		app31) echo nextcloud31 ;;
	esac
}

sql() {
	$compose exec -T db mariadb -uroot -proot -N "$(schema "$1")" -e "$2"
}

occ() {
	server=$1
	shift
	$compose exec -T -u www-data "$server" php occ "$@"
}

# What a user does: unpack the tarball into the apps directory, over whatever was there.
put() {
	$compose cp "$2" "$1:/tmp/nextfleet.tar.gz"
	$compose exec -T -u www-data "$1" sh -c \
		'rm -rf custom_apps/nextfleet && tar -xzf /tmp/nextfleet.tar.gz -C custom_apps'
}

tables() {
	sql "$1" "select table_name from information_schema.tables
		where table_schema = database() and table_name like 'oc\_fleet\_%' order by table_name"
}

# One line per table: its name, its row count and a checksum over every row's content. A line
# that differs after the upgrade is a row lost or changed. A future migration that means to
# change rows will fail here; read the difference before trusting it.
#
# No pipes and no command substitution in a list: sh has no pipefail, and a failed query would
# leave an empty snapshot that nothing can be missing from.
rows() {
	tables "$1" >"$work/$1.names"
	[ -s "$work/$1.names" ] || fail "$1: the app has no tables"
	: >"$2"
	for table in $(cat "$work/$1.names"); do
		count=$(sql "$1" "select count(*) from $table")
		checksum=$(sql "$1" "checksum table $table")
		echo "$table $count ${checksum##*[[:space:]]}" >>"$2"
	done
	sort -o "$2" "$2"
}

get() {
	$compose exec -T "$1" curl -s -u admin:admin -H 'OCS-APIRequest: true' -w '\n%{http_code}' \
		"http://localhost/index.php/apps/nextfleet/$2"
}

# The page and the overview's own request, as admin. Basic auth needs no session, and the
# OCS-APIRequest header is what lets a request without one past the CSRF check. For a few seconds
# after an enable or an upgrade from occ, Apache still answers from the routes it cached before
# (404), so the page gets a minute. $2 is a plate the overview must list, or nothing.
loads() {
	tries=0
	until page=$(get "$1" "") && [ "$(echo "$page" | tail -n1)" = 200 ]; do
		tries=$((tries + 1))
		[ "$tries" -lt 30 ] || fail "$1: the page answered $(echo "$page" | tail -n1)"
		sleep 2
	done
	echo "$page" | grep -q 'nextfleet-main' || fail "$1: the page does not load the app"

	vehicles=$(get "$1" api/vehicles)
	[ "$(echo "$vehicles" | tail -n1)" = 200 ] ||
		fail "$1: the overview's vehicles answered $(echo "$vehicles" | tail -n1)"
	if [ -n "${2:-}" ]; then
		echo "$vehicles" | grep -q "\"plate\":\"$2\"" || fail "$1: the overview does not list $2"
	fi
}

# A run that was interrupted leaves its servers behind; start from nothing.
$compose down -v --remove-orphans >/dev/null 2>&1

migrations=$(tar -tzf "$tarball" | grep -c '^nextfleet/lib/Migration/Version.*\.php$')

$compose up -d --wait --quiet-pull
for server in app34 app31; do
	put "$server" "$tarball"
	occ "$server" app:enable nextfleet
	ran=$(sql "$server" "select count(*) from oc_migrations where app = 'nextfleet'")
	[ "$ran" = "$migrations" ] || fail "$server: $ran of $migrations migrations ran"
	tables "$server" >"$work/$server.tables"
	loads "$server"
	occ "$server" nextfleet:seed admin
	echo "$server: $new installs fresh, $ran migrations ran, the page loads, the seed runs"
done
$compose down -v

$compose up -d --wait --quiet-pull
for server in app34 app31; do
	put "$server" "$work/base.tar.gz"
	occ "$server" app:enable nextfleet
	occ "$server" nextfleet:seed admin
	rows "$server" "$work/$server.before"
	plate=$(sql "$server" "select plate from oc_fleet_vehicles where deleted_at is null order by id limit 1")
	[ -n "$plate" ] || fail "$server: the $old seed wrote no vehicle"

	put "$server" "$tarball"
	occ "$server" upgrade
	installed=$(occ "$server" config:app:get nextfleet installed_version)
	[ "$installed" = "$new" ] || fail "$server: $installed is installed after the upgrade, not $new"

	rows "$server" "$work/$server.after"
	lost=$(comm -23 "$work/$server.before" "$work/$server.after")
	[ -z "$lost" ] || fail "$server: rows lost or changed in: $(echo "$lost" | cut -d' ' -f1 | tr '\n' ' ')"
	tables "$server" | diff "$work/$server.tables" - ||
		fail "$server: the upgraded tables are not the fresh install's"
	loads "$server" "$plate"
	echo "$server: $old upgrades to $new, all $(wc -l <"$work/$server.before") tables' rows intact, the overview loads"
done

echo "Upgrade check passed"
