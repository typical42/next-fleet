#!/bin/sh
# SPDX-FileCopyrightText: 2026 Johannes Kolb
# SPDX-License-Identifier: AGPL-3.0-or-later

# Installs a package every way a user reaches it, on throwaway servers:
#
#   NC 31        fresh, and over the version users have today
#   NC 34        fresh, and over that version, the server staying on 34
#   NC 34 -> 35  that version on NC 34, the server upgraded to 35 under it, then the package
#   NC 35        fresh
#
# After every path the app is enabled at the package's version, has run exactly the package's
# migrations, and `occ nextfleet:check` finds nothing; nextcloud.log holds no warning that names
# the app. The one exception is an old version that allows no NC 35: the server upgrade disables
# it, logs that, and logs failing to replay its migrations, which the package cannot prevent. An
# upgrade also keeps every row the old version wrote, and ends in the schema a fresh install has,
# to the last column and index.
#
#   tools/upgrade-check.sh [--db mariadb|pgsql|oracle] <tarball> [<base>]
#
# <tarball> is the package to check: pass the file you are about to sign, not a rebuild of it.
# <base> is the version users have, as a git ref or a tarball. It defaults to v0.4.0, the last
# release; each release moves it on.
#
# The servers are tools/upgrade-check.compose.yml, its own project with its own volumes. They are
# removed at the end, pass or fail. The dev servers in .docker/compose.yml are never touched.
# --db picks their database, MariaDB by default; Oracle checks only NC 34 -> 35 and NC 35 fresh
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
# $fresh installs the package on nothing; $paths start from the base, each on its first server.
case $db in
	mariadb | pgsql) fresh="app31 app34 app35" paths="app31 app34 from34" ;;
	oracle) fresh=app35 paths=from34 ;;
	*) usage ;;
esac

if [ $# -lt 1 ] || [ ! -f "$1" ]; then
	usage
fi
tarball=$(realpath "$1")
base=${2:-v0.4.0}
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

# The newest Nextcloud major a package allows.
max_version() {
	tar -xOzf "$1" nextfleet/appinfo/info.xml | sed -n 's:.*<nextcloud [^>]*max-version="\([0-9]*\)".*:\1:p'
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
base_max=$(max_version "$work/base.tar.gz")
# Anything but one number would make the test in the 34 -> 35 path fail into its other branch.
case $base_max in
	'' | *[!0-9]*) fail "$old names no single newest Nextcloud major: $base_max" ;;
esac
echo "Checking $new, fresh and over $old, on $db"

schema() {
	case $1 in
		app34) echo nextcloud ;;
		app31) echo nextcloud31 ;;
		app35) echo nextcloud35 ;;
		from34 | to35) echo nextcloud34to35 ;;
	esac
}

# The fresh install a path's schema must end as: the one on the major it ends on.
fresh_of() {
	case $1 in
		from34) echo app35 ;;
		*) echo "$1" ;;
	esac
}

# A package's migrations by version, as oc_migrations names them.
migrations() {
	tar -tzf "$1" | sed -n 's:^nextfleet/lib/Migration/Version\(.*\)\.php$:\1:p' | sort
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
		# One schema, the server's that runs. CSV prints a value without sqlplus's padding; define off keeps an &
		# from prompting.
		oracle) printf '%s\n' 'set markup csv on quote off' \
			'set heading off feedback off pagesize 0 long 100000 sqlblanklines on numwidth 40 define off' \
			'whenever sqlerror exit failure' "$2;" 'commit;' |
			$compose exec -T db sqlplus -s -L nextcloud/nextcloud@//localhost/FREEPDB1 ;;
	esac
}

# Over the columns $3 names, order-blind: the same rows in another physical order are the same
# table.
checksum() {
	case $db in
		mariadb) sql "$1" "set session group_concat_max_len = 4294967295;
			select md5(coalesce(group_concat(r order by r separator ','), ''))
			from (select json_array($3) r from \"$2\") t" ;;
		pgsql) sql "$1" "select md5(coalesce(string_agg(r, ',' order by r), ''))
			from (select row($3)::text r from \"$2\") t" ;;
		oracle) sql "$1" "select checksum(json_array($3 null on null)) from \"$2\"" ;;
	esac
}

# A table's columns, quoted and joined, as the first snapshot found them. The later one reads the
# same list, so a column the upgrade adds changes no checksum: the schema check judges it. Kept by
# database, which 34 -> 35's two servers share.
columns() {
	file="$work/$(schema "$1").$2.columns"
	if [ ! -f "$file" ]; then
		case $db in
			mariadb | pgsql) names=$(sql "$1" "select column_name from information_schema.columns
				where table_schema = $here and table_name = '$2' order by column_name") ;;
			oracle) names=$(sql "$1" "select column_name from user_tab_columns
				where table_name = '$2' order by column_name") ;;
		esac
		list=
		for name in $names; do
			list="$list${list:+, }\"$name\""
		done
		[ -n "$list" ] || fail "$1: $2 has no columns"
		echo "$list" >"$file"
	fi
	cat "$file"
}

occ() {
	server=$1
	shift
	$compose exec -T -u www-data "$server" php occ "$@"
}

# What a user does: unpack the tarball into the apps directory, over whatever was there. The app
# store's installer then resets PHP's opcache (lib/private/Installer.php in the server); reloading
# Apache does that here. Without it the image's opcache.revalidate_freq of 60 s keeps serving the
# old entities against the new columns, and every read of them fails.
put() {
	$compose cp "$2" "$1:/tmp/nextfleet.tar.gz"
	$compose exec -T -u www-data "$1" sh -c \
		'rm -rf custom_apps/nextfleet && tar -xzf /tmp/nextfleet.tar.gz -C custom_apps'
	$compose exec -T "$1" apachectl -k graceful 2>/dev/null || fail "$1: Apache did not reload"
}

# The Oracle image installs nothing by itself; the others have installed on start. The app store
# goes off: a server upgrade asks it for a newer nextfleet (Updater::upgradeAppStoreApps) and
# installs that over custom_apps, so a release in the store would be checked in place of the
# package.
install() {
	if [ "$db" = oracle ]; then
		occ "$1" maintenance:install --database=oci --database-host=db --database-name=FREEPDB1 \
			--database-user=nextcloud --database-pass=nextcloud --admin-user=admin --admin-pass=admin
	fi
	occ "$1" config:system:set appstoreenabled --type=boolean --value=false >/dev/null
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

# One line per table: its name, its row count and a checksum over every row's content in the
# columns it had before the upgrade. A line that differs after the upgrade is a row lost or
# changed. A future migration that means to change rows will fail here; read the difference
# before trusting it.
#
# No pipes and no command substitution in a list: sh has no pipefail, and a failed query would
# leave an empty snapshot that nothing can be missing from.
rows() {
	tables "$1" >"$work/$1.names"
	[ -s "$work/$1.names" ] || fail "$1: the app has no tables"
	: >"$2"
	for table in $(cat "$work/$1.names"); do
		count=$(sql "$1" "select count(*) from \"$table\"")
		listed=$(columns "$1" "$table")
		checksum=$(checksum "$1" "$table" "$listed")
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
			# Oracle keeps a default as the text its DDL wrote: a CREATE leaves `NULL ` or none, an
			# ALTER that widens a column writes `NULL`. Doctrine reads all three as no default
			# (OracleSchemaManager), so they compare as one here. The column is a LONG, which SQL
			# cannot trim. Captured first, so a failing query still fails the check.
			described=$(sql "$1" "select table_name, column_name, data_type, data_length, data_precision,
					data_scale, nullable, data_default
				from user_tab_columns where table_name like 'oc\_fleet\_%' escape '\'
				order by table_name, column_name")
			printf '%s\n' "$described" | sed 's/ *$//; s/,NULL$/,/'
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

# Which of the app's migrations the server has run, in migrations()'s order.
ran() {
	sql "$1" "select \"version\" from \"oc_migrations\" where \"app\" = 'nextfleet'" >"$2"
	sort -o "$2" "$2"
}

# Every line of nextcloud.log at warning or above that names the app: its own, and the server's
# about it. A line that is no JSON counts as an error. The arguments after the server are pairs
# of patterns for the lines the path expects: one for the message and one for its exception's
# message, which an empty pattern leaves unchecked.
# shellcheck disable=SC2016 # the dollars are PHP's
complaints() {
	server=$1
	shift
	$compose exec -T -u www-data "$server" php -r '
		$log = "data/nextcloud.log";
		$expected = array_chunk(array_slice($argv, 1), 2);
		foreach (is_file($log) ? file($log, FILE_IGNORE_NEW_LINES) : [] as $line) {
			$entry = json_decode($line, true);
			$level = is_array($entry) ? (int)($entry["level"] ?? 4) : 4;
			if ($level < 2 || stripos($line, "nextfleet") === false) {
				continue;
			}
			$exception = is_array($entry) && is_array($entry["exception"] ?? null) ? $entry["exception"] : [];
			foreach ($expected as [$message, $thrown]) {
				if (preg_match($message, (string)($entry["message"] ?? ""))
					&& ($thrown === "" || preg_match($thrown, (string)($exception["Message"] ?? "")))) {
					continue 2;
				}
			}
			echo $line, PHP_EOL;
		}' -- "$@"
}

# What must hold after every path: the package enabled at its version, all its migrations and no
# other run, no finding, no complaint but those the further arguments expect (complaints()).
flawless() {
	installed=$(occ "$1" config:app:get nextfleet installed_version)
	[ "$installed" = "$new" ] || fail "$1: $installed is installed, not $new"
	enabled=$(occ "$1" config:app:get nextfleet enabled)
	[ "$enabled" = yes ] || fail "$1: the app is not enabled ($enabled)"

	ran "$1" "$work/$1.ran"
	diff "$work/new.migrations" "$work/$1.ran" || fail "$1: the migrations run are not the package's"

	found=$(occ "$1" nextfleet:check) || fail "$1: nextfleet:check found: $found"
	[ "$found" = 'No findings.' ] || fail "$1: nextfleet:check reports: $found"

	said=$(complaints "$@")
	[ -z "$said" ] || fail "$1: nextcloud.log names the app: $said"
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

migrations "$tarball" >"$work/new.migrations"
migrations "$work/base.tar.gz" >"$work/base.migrations"
[ -s "$work/new.migrations" ] || fail "$new has no migrations"
[ -s "$work/base.migrations" ] || fail "$old has no migrations"

# Word splitting is the point: $fresh and $paths are lists. --build because Oracle's servers are
# built from .docker/oracle/ under a fixed tag, which would otherwise keep whatever image was
# built last.
# shellcheck disable=SC2086
$compose up -d --wait --quiet-pull --build $fresh
for server in $fresh; do
	install "$server"
	put "$server" "$tarball"
	occ "$server" app:enable nextfleet
	shape "$server" >"$work/$server.fresh"
	loads "$server"
	occ "$server" nextfleet:seed admin
	flawless "$server"
	echo "$server: $new installs fresh, the page loads, the seed runs, nothing to find or complain about"
done
$compose down -v

# shellcheck disable=SC2086
$compose up -d --wait --quiet-pull --build $paths
for path in $paths; do
	server=$path
	install "$server"
	put "$server" "$work/base.tar.gz"
	occ "$server" app:enable nextfleet
	alias_sequence "$server" create
	loads "$server"
	history "$server"
	rows "$server" "$work/$path.before"
	empty=$(awk '$2 == 0 { print $1 }' "$work/$path.before")
	[ -z "$empty" ] || fail "$server: empty before the upgrade, so unproven: $(echo "$empty" | tr '\n' ' ')"
	plate=$(sql "$server" "select \"plate\" from \"oc_fleet_vehicles\" where \"deleted_at\" is null
		order by \"id\" fetch first 1 rows only")
	[ -n "$plate" ] || fail "$server: the $old seed wrote no vehicle"
	alias_sequence "$server" drop

	# The log lines the path expects, for flawless(): none unless the case sets them.
	set --
	case $path in
		# The image switch: NC 35 starts on NC 34's install and upgrades it, the app's migrations
		# untouched.
		from34)
			$compose stop "$server"
			server=to35
			$compose up -d --wait --quiet-pull "$server"
			ran "$server" "$work/$path.base-ran"
			diff "$work/base.migrations" "$work/$path.base-ran" ||
				fail "$server: the server upgrade changed which of the app's migrations ran"
			if [ "$base_max" -lt 35 ]; then
				# A base that allows no NC 35 comes out disabled, and enabling the package must run
				# the rest. The server logs the disabling as an error, naming the app by its
				# info.xml name, which it reads as an array. Its schema check then replays the
				# migrations of the disabled app on NC 35's own Table class, which a base with
				# Doctrine's in its type hints cannot take.
				listed=$(occ "$server" app:list --disabled)
				case $listed in
					*"- nextfleet:"*) ;;
					*) fail "$server: the server upgrade left the app not listed as disabled: $listed" ;;
				esac
				put "$server" "$tarball"
				occ "$server" app:enable nextfleet
				set -- \
					"/^App \"[^\"]*\" cannot be installed because the following dependencies are not fulfilled: Server version $base_max or lower is required\\.\$/" '' \
					'/^Could not replay migrations for disabled app \{app\}$/' \
					'/^OCA\\NextFleet\\Migration\\Version\w+::\w+\(\): Argument #\d+ \([$]table\) must be of type Doctrine\\DBAL\\Schema\\Table, OC\\DB\\Schema\\Table given\b/'
			else
				# A base that allows NC 35 stays enabled, and the package is an update like any other.
				enabled=$(occ "$server" config:app:get nextfleet enabled)
				[ "$enabled" = yes ] || fail "$server: the server upgrade disabled the app ($enabled)"
				put "$server" "$tarball"
				occ "$server" upgrade
			fi
			;;
		*)
			put "$server" "$tarball"
			occ "$server" upgrade
			;;
	esac
	# The log is read after the path's last request, so what the upgraded app logs on its first
	# ones counts too.
	loads "$server" "$plate"
	flawless "$server" "$@"

	rows "$server" "$work/$path.after"
	lost=$(comm -23 "$work/$path.before" "$work/$path.after")
	[ -z "$lost" ] || fail "$server: rows lost or changed in: $(echo "$lost" | cut -d' ' -f1 | tr '\n' ' ')"
	shape "$server" >"$work/$path.upgraded"
	diff "$work/$(fresh_of "$path").fresh" "$work/$path.upgraded" ||
		fail "$server: the upgraded schema is not the fresh install's"
	echo "$server: $old upgrades to $new, all $(wc -l <"$work/$path.before") tables' rows intact, the schema the fresh one's, nothing to find or complain about, the overview loads"
done

echo "Upgrade check passed"
