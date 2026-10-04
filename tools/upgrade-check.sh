#!/bin/sh
# SPDX-FileCopyrightText: 2026 Johannes Kolb
# SPDX-License-Identifier: AGPL-3.0-or-later

# Installs a package on throwaway NC 31 and NC 34 servers, first fresh, then as an upgrade over
# the version users have today, and fails unless every row that version wrote survives in every
# table, and the upgraded schema is the fresh install's to the last column and index.
#
#   tools/upgrade-check.sh [--db mariadb|pgsql|oracle] <tarball> [<base>]
#
# <tarball> is the package to check: pass the file you are about to sign, not a rebuild of it.
# <base> is the version users have, as a git ref or a tarball. It defaults to 27142b4, the 0.2.0
# commit, because no release is tagged yet; once one is, pass its tag.
#
# The servers are tools/upgrade-check.compose.yml, its own project with its own volumes. They are
# removed at the end, pass or fail. The dev servers in .docker/compose.yml are never touched.
# --db picks their database, MariaDB by default; Oracle checks NC 34 alone
# (docs/development.md#release).

set -eu

usage() {
	echo "usage: $0 [--db mariadb|pgsql|oracle] <tarball> [<base ref or tarball>]" >&2
	exit 2
}

db=mariadb
if [ "${1:-}" = --db ]; then
	[ $# -ge 2 ] || usage
	db=$2
	shift 2
fi
case $db in
	mariadb | pgsql) servers="app34 app31" ;;
	oracle) servers=app34 ;;
	*) usage ;;
esac

if [ $# -lt 1 ] || [ ! -f "$1" ]; then
	usage
fi
tarball=$(realpath "$1")
base=${2:-27142b4}
# --db goes first; after the tarball it would be taken for the base.
case $base in -*) usage ;; esac
if [ -f "$base" ]; then
	base=$(realpath "$base")
fi

cd "$(dirname "$0")/.."

compose="docker compose -f tools/upgrade-check.compose.yml -f tools/upgrade-check.$db.yml"
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
echo "Checking $new, fresh and over $old, on $db"

schema() {
	case $1 in
		app34) echo nextcloud ;;
		app31) echo nextcloud31 ;;
	esac
}

# What differs between the databases. Everything else below is SQL all three accept. Identifiers
# are quoted because Nextcloud creates them quoted, in lower case, on Oracle, where a bare name
# folds to upper case; MariaDB reads the quotes as identifiers in ANSI_QUOTES mode, which sql()
# sets. $pair joins a key to its value in $json.
case $db in
	mariadb)
		here='database()'
		json=json_object
		pair=,
		new_uuid='uuid()'
		now='unix_timestamp()'
		;;
	pgsql)
		here='current_schema()'
		json=json_build_object
		pair=,
		new_uuid='gen_random_uuid()'
		now='extract(epoch from now())::bigint'
		;;
	oracle)
		json=json_object
		pair=' value '
		new_uuid="lower(regexp_replace(rawtohex(uuid()), '(.{8})(.{4})(.{4})(.{4})(.{12})', '\\1-\\2-\\3-\\4-\\5'))"
		now="round((cast(sys_extract_utc(systimestamp) as date) - date '1970-01-01') * 86400)"
		;;
esac

sql() {
	case $db in
		mariadb) $compose exec -T db mariadb -uroot -proot -N "$(schema "$1")" \
			-e "set session sql_mode = concat(@@sql_mode, ',ANSI_QUOTES'); $2" ;;
		pgsql) $compose exec -T db psql -U postgres -d "$(schema "$1")" -AtqX -v ON_ERROR_STOP=1 -c "$2" ;;
		# One schema, NC 34's. CSV prints a value without sqlplus's padding; define off keeps an &
		# from prompting.
		oracle) printf '%s\n' 'set markup csv on quote off' \
			'set heading off feedback off pagesize 0 long 100000 sqlblanklines on numwidth 40 define off' \
			'whenever sqlerror exit failure' "$2;" 'commit;' |
			$compose exec -T db sqlplus -s -L nextcloud/nextcloud@//localhost/FREEPDB1 ;;
	esac
}

# Order-blind, as MariaDB's own is: the same rows in another physical order are the same table.
checksum() {
	case $db in
		mariadb) sql "$1" "checksum table \"$2\"" ;;
		pgsql) sql "$1" "select md5(coalesce(string_agg(t::text, ',' order by t::text), '')) from \"$2\" t" ;;
		oracle) sql "$1" "select checksum(json_object(*)) from \"$2\" t" ;;
	esac
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

# The Oracle image installs nothing by itself; the others have installed on start.
install() {
	[ "$db" = oracle ] || return 0
	occ "$1" maintenance:install --database=oci --database-host=db --database-name=FREEPDB1 \
		--database-user=nextcloud --database-pass=nextcloud --admin-user=admin --admin-pass=admin
}

# Why an older base needs the synonym is in docs/development.md#oracle. It is dropped before the
# upgrade, so the package runs without it.
alias_sequence() {
	[ "$db" = oracle ] || return 0
	case $2 in
		create) sql "$1" "create synonym \"oc_fleet_reminder_recipients_SEQ\"
			for \"oc_fleet_reminder_recipien_SEQ\"" ;;
		drop) sql "$1" "drop synonym \"oc_fleet_reminder_recipients_SEQ\"" ;;
	esac
}

tables() {
	case $db in
		mariadb | pgsql) sql "$1" "select table_name from information_schema.tables
			where table_schema = $here and table_name like 'oc\_fleet\_%' order by table_name" ;;
		oracle) sql "$1" "select table_name from user_tables
			where table_name like 'oc\_fleet\_%' escape '\' order by table_name" ;;
	esac
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
		count=$(sql "$1" "select count(*) from \"$table\"")
		checksum=$(checksum "$1" "$table")
		echo "$table $count ${checksum##*[[:space:]]}" >>"$2"
	done
	sort -o "$2" "$2"
}

# Every column's type, nullability and default, and every index's columns and uniqueness. An
# upgrade must end in the schema a fresh install has; a table list alone misses a lost index.
shape() {
	case $db in
		mariadb)
			sql "$1" "select table_name, column_name, column_type, is_nullable, column_default, extra
				from information_schema.columns
				where table_schema = $here and table_name like 'oc\_fleet\_%'
				order by table_name, column_name"
			sql "$1" "select table_name, index_name, seq_in_index, column_name, non_unique
				from information_schema.statistics
				where table_schema = $here and table_name like 'oc\_fleet\_%'
				order by table_name, index_name, seq_in_index"
			;;
		pgsql)
			sql "$1" "select table_name, column_name, data_type, character_maximum_length,
					numeric_precision, numeric_scale, is_nullable, column_default, is_identity
				from information_schema.columns
				where table_schema = $here and table_name like 'oc\_fleet\_%'
				order by table_name, column_name"
			# The definition names the columns, their order and uniqueness in one line.
			sql "$1" "select tablename, indexname, indexdef from pg_indexes
				where schemaname = $here and tablename like 'oc\_fleet\_%'
				order by tablename, indexname"
			;;
		oracle)
			sql "$1" "select table_name, column_name, data_type, data_length, data_precision,
					data_scale, nullable, data_default
				from user_tab_columns where table_name like 'oc\_fleet\_%' escape '\'
				order by table_name, column_name"
			# Oracle names the primary key itself, differently on every install.
			sql "$1" "select i.table_name,
					case i.generated when 'Y' then 'generated' else i.index_name end,
					c.column_position, c.column_name, i.uniqueness
				from user_indexes i join user_ind_columns c on c.index_name = i.index_name
				where i.table_name like 'oc\_fleet\_%' escape '\'
				order by 1, 2, 3"
			# The autoincrement is a sequence and a trigger.
			sql "$1" "select sequence_name, increment_by from user_sequences
				where sequence_name like 'oc\_fleet\_%' escape '\' order by 1"
			sql "$1" "select trigger_name, table_name, status from user_triggers
				where table_name like 'oc\_fleet\_%' escape '\' order by 1"
			;;
	esac
}

get() {
	$compose exec -T "$1" curl -s -u admin:admin -H 'OCS-APIRequest: true' -w '\n%{http_code}' \
		"http://localhost/index.php/apps/nextfleet/$2"
}

# A write as admin, as the sheets send it; anything but 200 fails the check.
send() {
	answer=$($compose exec -T "$1" curl -s -u admin:admin -H 'OCS-APIRequest: true' \
		-H 'Content-Type: application/json' -X "$2" -d "$4" -w '\n%{http_code}' \
		"http://localhost/index.php/apps/nextfleet/$3")
	[ "$(echo "$answer" | tail -n1)" = 200 ] || fail "$1: $2 $3 answered $answer"
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

# The base's seed, then enough use that no table is empty: an empty table proves nothing about its
# rows. A seed that can give a car to a driver fills the grants and the bookings. Logbook Mode
# switched on and a trip edited fill the audit trail, the reminder job the receipts.
history() {
	# Not in the case word, where a failed occ would not stop the script.
	seed=$(occ "$1" help nextfleet:seed)
	case $seed in
		*--grant-to*)
			$compose exec -T -e OC_PASS=upgrade-check-driver-password -u www-data "$1" \
				php occ user:add --password-from-env driver >/dev/null
			occ "$1" nextfleet:seed admin --grant-to driver
			;;
		*) occ "$1" nextfleet:seed admin ;;
	esac

	trip=$(sql "$1" "select \"uuid\" from \"oc_fleet_trips\" where \"deleted_at\" is null
		order by \"id\" fetch first 1 rows only")
	[ -n "$trip" ] || fail "$1: the $old seed wrote no trip"
	vehicle=$(sql "$1" "select v.\"uuid\" from \"oc_fleet_vehicles\" v
		join \"oc_fleet_trips\" t on t.\"vehicle_id\" = v.\"id\" where t.\"uuid\" = '$trip'")
	stamp=$(sql "$1" "select \"updated_at\" from \"oc_fleet_vehicles\" where \"uuid\" = '$vehicle'")
	send "$1" PUT "api/vehicles/$vehicle" "{\"logbook_mode\":true,\"updated_at\":$stamp}"

	# An edit replaces the whole trip, so the body is the row with a new purpose.
	pairs=
	for column in started_at started_at_off ended_at ended_at_off start_odo end_odo distance \
		from_label to_label partner category updated_at; do
		pairs="$pairs'$column'$pair\"$column\", "
	done
	body=$(sql "$1" "select $json($pairs'purpose'$pair concat(coalesce(\"purpose\", ''), ' (edited)'))
		from \"oc_fleet_trips\" where \"uuid\" = '$trip'")
	send "$1" PUT "api/vehicles/$vehicle/trips/$trip" "$body"

	job=$(sql "$1" "select \"id\" from \"oc_jobs\" where \"class\" like '%NextFleet%ReminderJob'")
	[ -n "$job" ] || fail "$1: the reminder job is not registered"
	occ "$1" background-job:execute --force-execute "$job" >/dev/null

	# 0.2.0's seed grants nothing and its API cannot, so its grant goes in by hand.
	[ "$(sql "$1" "select count(*) from \"oc_fleet_access\"")" = 0 ] || return 0
	occ "$1" group:add viewers >/dev/null
	sql "$1" "insert into \"oc_fleet_access\" (\"uuid\", \"created_at\", \"updated_at\",
			\"created_by\", \"vehicle_id\", \"grantee\", \"grantee_type\", \"role\")
		select $new_uuid, $now, $now, \"created_by\", \"id\", 'viewers', 'group', 'viewer'
		from \"oc_fleet_vehicles\" where \"uuid\" = '$vehicle'"
}

# A run that was interrupted leaves its servers behind; start from nothing.
$compose down -v --remove-orphans >/dev/null 2>&1

migrations=$(tar -tzf "$tarball" | grep -c '^nextfleet/lib/Migration/Version.*\.php$')

# Word splitting is the point: $servers is a list. --build because Oracle's app34 is built from
# .docker/oracle/ under a fixed tag, which would otherwise keep whatever image was built last.
# shellcheck disable=SC2086
$compose up -d --wait --quiet-pull --build $servers
for server in $servers; do
	install "$server"
	put "$server" "$tarball"
	occ "$server" app:enable nextfleet
	ran=$(sql "$server" "select count(*) from \"oc_migrations\" where \"app\" = 'nextfleet'")
	[ "$ran" = "$migrations" ] || fail "$server: $ran of $migrations migrations ran"
	shape "$server" >"$work/$server.fresh"
	loads "$server"
	occ "$server" nextfleet:seed admin
	echo "$server: $new installs fresh, $ran migrations ran, the page loads, the seed runs"
done
$compose down -v

# shellcheck disable=SC2086
$compose up -d --wait --quiet-pull $servers
for server in $servers; do
	install "$server"
	put "$server" "$work/base.tar.gz"
	occ "$server" app:enable nextfleet
	alias_sequence "$server" create
	loads "$server"
	history "$server"
	rows "$server" "$work/$server.before"
	empty=$(awk '$2 == 0 { print $1 }' "$work/$server.before")
	[ -z "$empty" ] || fail "$server: empty before the upgrade, so unproven: $(echo "$empty" | tr '\n' ' ')"
	plate=$(sql "$server" "select \"plate\" from \"oc_fleet_vehicles\" where \"deleted_at\" is null
		order by \"id\" fetch first 1 rows only")
	[ -n "$plate" ] || fail "$server: the $old seed wrote no vehicle"

	alias_sequence "$server" drop
	put "$server" "$tarball"
	occ "$server" upgrade
	installed=$(occ "$server" config:app:get nextfleet installed_version)
	[ "$installed" = "$new" ] || fail "$server: $installed is installed after the upgrade, not $new"

	rows "$server" "$work/$server.after"
	lost=$(comm -23 "$work/$server.before" "$work/$server.after")
	[ -z "$lost" ] || fail "$server: rows lost or changed in: $(echo "$lost" | cut -d' ' -f1 | tr '\n' ' ')"
	shape "$server" >"$work/$server.upgraded"
	diff "$work/$server.fresh" "$work/$server.upgraded" ||
		fail "$server: the upgraded schema is not the fresh install's"
	loads "$server" "$plate"
	echo "$server: $old upgrades to $new, all $(wc -l <"$work/$server.before") tables' rows intact, the schema the fresh one's, the overview loads"
done

echo "Upgrade check passed"
