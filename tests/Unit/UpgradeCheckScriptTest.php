<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * tools/upgrade-check.sh, on MariaDB unless a case says otherwise, against a docker stub that plays
 * the servers: it keeps each install's code, version, enabled flag and migrations, answers the
 * script's queries alike for every table, and runs the script's own log filter with this host's PHP. Its NC 35 disables an
 * app capped below 35, as the real one does, and logs $CHECK_UPGRADE_LOG while it upgrades. Its app store
 * always holds a newer nextfleet that allows 35, which the server upgrade takes unless the store is off.
 * UpgradeCheckStackTest covers the servers, a real run the rest.
 */
class UpgradeCheckScriptTest extends TestCase {
	private const SCRIPT = __DIR__ . '/../../tools/upgrade-check.sh';

	/**
	 * What NC 35.0.2 logs about a capped 0.4.0 while it upgrades the server under it, cut to the
	 * fields the check reads. The first names the app only in an argument of its trace.
	 */
	private const DISABLED = '{"level":3,"app":"no app in context","message":"App \"Array\" cannot be installed because the following dependencies are not fulfilled: Server version 34 or lower is required.","exception":{"Exception":"Exception","Message":"App \"Array\" cannot be installed because the following dependencies are not fulfilled: Server version 34 or lower is required.","Trace":[{"function":"upgradeAppStoreApps","class":"OC\\\\Updater","args":[["*** sensitive parameters replaced ***"],{"nextfleet":"yes"}]}]}}';
	private const REPLAY = '{"level":2,"app":"nextfleet","message":"Could not replay migrations for disabled app {app}","exception":{"Exception":"TypeError","Message":"OCA\\\\NextFleet\\\\Migration\\\\Version000001Date20260101000000::common(): Argument #1 ($table) must be of type Doctrine\\\\DBAL\\\\Schema\\\\Table, OC\\\\DB\\\\Schema\\\\Table given, called in /var/www/html/custom_apps/nextfleet/lib/Migration/Version000001Date20260101000000.php on line 35"}}';

	private string $dir;
	private string $log;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/nextfleet-upgrade-check-' . bin2hex(random_bytes(4));
		$this->log = $this->dir . '/log';
		mkdir($this->dir . '/bin', 0700, true);
		mkdir($this->dir . '/repo/tools', 0700, true);
		mkdir($this->dir . '/state', 0700);
		touch($this->log);
		copy(self::SCRIPT, $this->dir . '/repo/tools/upgrade-check.sh');

		file_put_contents($this->dir . '/bin/docker', <<<'SH'
			#!/bin/sh
			echo "docker $*" >>"$CHECK_LOG"
			shift
			while [ "$1" = -f ]; do shift 2; done
			state=$CHECK_STATE
			# The database a server's install lives in, as the MariaDB file has it.
			db() {
				case $1 in
					app31) echo nextcloud31 ;;
					app34) echo nextcloud ;;
					app35) echo nextcloud35 ;;
					from34 | to35) echo nextcloud34to35 ;;
				esac
			}
			info() {
				tar -xOzf "$(cat "$state/$1.code")" nextfleet/appinfo/info.xml
			}
			migrations() {
				tar -tzf "$(cat "$state/$1.code")" | sed -n 's:^nextfleet/lib/Migration/Version\(.*\)\.php$:\1:p'
			}
			# Installs the code's version, and remembers an install that replaced another.
			installs() {
				version=$(info "$1" | sed -n 's:.*<version>\(.*\)</version>.*:\1:p')
				if [ -s "$state/$1.installed" ] && [ "$(cat "$state/$1.installed")" != "$version" ]; then
					touch "$state/$1.upgraded"
				fi
				echo "$version" >"$state/$1.installed"
				migrations "$1" >"$state/$1.ran"
			}
			case $1 in
				down) rm -rf "${state:?}"/* ;;
				up)
					for last; do :; done
					# Oracle's one schema holds the install of the server started last.
					db "$last" >"$state/current"
					[ "$last" = to35 ] || exit 0
					d=$(db to35)
					capped=
					case $(info "$d") in
						*'max-version="3'[0-4]'"'*) capped=1 ;;
					esac
					# $CHECK_WRONG_UPGRADE=1 turns it around: a capped app stays enabled, any other
					# comes out disabled. The store's release replaces a capped app and stays enabled.
					if [ "$capped" != "${CHECK_WRONG_UPGRADE:-}" ] && [ "$(cat "$state/$d.store" 2>/dev/null)" = off ]; then
						echo no >"$state/$d.enabled"
					fi
					mkdir -p "$state/to35/data"
					cat "$CHECK_UPGRADE_LOG" >>"$state/to35/data/nextcloud.log"
					;;
				cp) cp "$2" "$state/$(db "${3%%:*}").tarball" && echo "$state/$(db "${3%%:*}").tarball" >"$state/$(db "${3%%:*}").code" ;;
				exec)
					shift
					while :; do
						case $1 in
							-T) shift ;;
							-u | -e) shift 2 ;;
							*) break ;;
						esac
					done
					server=$1
					shift
					d=$(db "$server")
					case "$1 $2" in
						'php occ')
							shift 2
							case $* in
								'config:app:get nextfleet installed_version') cat "$state/$d.installed" ;;
								'config:app:get nextfleet enabled') cat "$state/$d.enabled" ;;
								'app:enable nextfleet')
									echo yes >"$state/$d.enabled"
									installs "$d"
									;;
								upgrade)
									[ "$(cat "$state/$d.enabled")" = yes ] || exit 0
									installs "$d"
									;;
								'config:system:set appstoreenabled --type=boolean --value=false') echo off >"$state/$d.store" ;;
								'app:list --disabled')
									echo 'Disabled:'
									[ "$(cat "$state/$d.enabled")" = yes ] || echo '  - nextfleet: 0.4.0'
									;;
								nextfleet:check) echo 'No findings.' ;;
							esac
							;;
						'php -r')
							mkdir -p "$state/$server"
							cd "$state/$server" && exec "$@"
							;;
						'curl -s')
							# $CHECK_REQUEST_LOG_ON names a server whose upgraded app logs on every
							# request.
							if [ "$server" = "${CHECK_REQUEST_LOG_ON:-}" ] && [ -f "$state/$d.upgraded" ]; then
								mkdir -p "$state/$server/data"
								echo '{"level":3,"app":"nextfleet","message":"the upgraded app failed a request"}' >>"$state/$server/data/nextcloud.log"
							fi
							printf '"plate":"P1" nextfleet-main\n200\n'
							;;
						'mariadb -uroot' | 'psql -U' | 'sqlplus -s')
							if [ "$1" = sqlplus ]; then
								d=$(cat "$state/current")
								sql=$(cat)
							else
								d=$5
								for sql; do :; done
							fi
							case $sql in
								*oc_migrations*) cat "$state/$d.ran" ;;
								*information_schema.tables* | *user_tables*) echo oc_fleet_trips ;;
								*column_type* | *data_type* | *information_schema.statistics* | *pg_indexes* | *user_indexes* | *user_sequences* | *user_triggers*) echo shape ;;
								*information_schema.columns* | *user_tab_columns*) echo id ;;
								*md5* | *'checksum('*) echo 0cc175b9 ;;
								*'count(*)'*) echo 1 ;;
								*'select v."uuid"'*) echo v1 ;;
								*'select "uuid"'*) echo t1 ;;
								*'"updated_at" from'*) echo 1 ;;
								*json_object* | *json_build_object*) echo '{}' ;;
								*oc_jobs*) echo 7 ;;
								*'"plate" from'*) echo P1 ;;
								*synonym*) ;;
								*)
									echo "the stub has no answer to: $sql" >&2
									exit 1
									;;
							esac
							;;
					esac
					;;
			esac
			SH);
		chmod($this->dir . '/bin/docker', 0700);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->dir));
	}

	/**
	 * A base capped below 35 comes out of the server upgrade disabled, its migrations untouched;
	 * enabling the package runs the rest. The two lines NC 35 logs about the old code are expected.
	 */
	public function testACappedBaseIsEnabledAgainAfterTheServerUpgrade(): void {
		[$status, $output] = $this->check('34', self::DISABLED . "\n" . self::REPLAY);

		$this->assertSame(0, $status, $output);
		$this->assertStringContainsString('to35: 0.4.0 upgrades to 0.4.1', $output);
		$to35 = $this->calls('to35');
		$this->assertContains('app:list --disabled', $to35);
		$this->assertContains('app:enable nextfleet', $to35);
		$this->assertNotContains('upgrade', $to35);
	}

	/** NC 35 names the base's own cap in the line about disabling it. */
	public function testABaseCappedLowerIsExpectedToBeDisabledForItsOwnCap(): void {
		[$status, $output] = $this->check('33', str_replace('Server version 34', 'Server version 33', self::DISABLED) . "\n" . self::REPLAY);

		$this->assertSame(0, $status, $output);
		$this->assertStringContainsString('to35: 0.4.0 upgrades to 0.4.1', $output);
	}

	/** A line about another cap than the base's is not the disabling the path expects. */
	public function testADisablingForAnotherCapFailsTheCheck(): void {
		[$status, $output] = $this->check('33', self::DISABLED . "\n" . self::REPLAY);

		$this->assertSame(1, $status, $output);
		$this->assertStringContainsString('FAIL: to35: nextcloud.log names the app:', $output);
	}

	/**
	 * The replay warning is expected only for the old code's Table hints.
	 *
	 * @dataProvider otherReplayFailures
	 */
	public function testAnyOtherReplayFailureFailsTheCheck(string $search, string $replace): void {
		$other = str_replace($search, $replace, self::REPLAY);
		$this->assertNotSame(self::REPLAY, $other);

		[$status, $output] = $this->check('34', self::DISABLED . "\n" . $other);

		$this->assertSame(1, $status, $output);
		$this->assertStringContainsString('FAIL: to35: nextcloud.log names the app:', $output);
		$this->assertStringContainsString('Could not replay migrations', $output);
	}

	/** @return iterable<string, list<string>> */
	public static function otherReplayFailures(): iterable {
		yield 'another parameter' => ['($table)', '($column)'];
		yield 'another type' => ['Doctrine\\\\DBAL\\\\Schema\\\\Table,', 'Doctrine\\\\DBAL\\\\Schema\\\\Column,'];
		yield 'another class' => ['OCA\\\\NextFleet\\\\Migration\\\\Version000001Date20260101000000', 'OCA\\\\NextFleet\\\\Db\\\\Version000001Date20260101000000'];
	}

	/** A server upgrade that does not disable a capped base is not the one the path expects. */
	public function testACappedBaseThatStaysEnabledFailsTheCheck(): void {
		[$status, $output] = $this->check('34', '', ['CHECK_WRONG_UPGRADE' => '1']);

		$this->assertSame(1, $status, $output);
		$this->assertStringContainsString('FAIL: to35: the server upgrade left the app not listed as disabled', $output);
	}

	public function testABaseThatAllows35AndComesOutDisabledFailsTheCheck(): void {
		[$status, $output] = $this->check('35', '', ['CHECK_WRONG_UPGRADE' => '1']);

		$this->assertSame(1, $status, $output);
		$this->assertStringContainsString('FAIL: to35: the server upgrade disabled the app (no)', $output);
	}

	/**
	 * A base that allows 35 stays enabled through the server upgrade and is finished with
	 * `occ upgrade`, as the app store does it.
	 */
	public function testABaseThatAllows35StaysEnabledAndIsUpgraded(): void {
		[$status, $output] = $this->check('35', '');

		$this->assertSame(0, $status, $output);
		$to35 = $this->calls('to35');
		$this->assertContains('upgrade', $to35);
		$this->assertNotContains('app:enable nextfleet', $to35);
	}

	/**
	 * A base that allows 35 has no reason to log either line, so neither is excused.
	 *
	 * @dataProvider capped35Lines
	 */
	public function testABaseThatAllows35ExcusesNoLogLine(string $line): void {
		[$status, $output] = $this->check('35', $line);

		$this->assertSame(1, $status, $output);
		$this->assertStringContainsString('FAIL: to35: nextcloud.log names the app:', $output);
	}

	/** @return iterable<string, list<string>> */
	public static function capped35Lines(): iterable {
		yield 'disabled' => [self::DISABLED];
		yield 'replay' => [self::REPLAY];
	}

	/**
	 * The log is read after the last request of every path, so what the upgraded app logs on its
	 * first requests fails the check.
	 *
	 * @dataProvider upgradedServers
	 */
	public function testWhatTheUpgradedAppLogsOnARequestFailsTheCheck(string $server, string $baseMax, string $upgradeLog): void {
		[$status, $output] = $this->check($baseMax, $upgradeLog, ['CHECK_REQUEST_LOG_ON' => $server]);

		$this->assertSame(1, $status, $output);
		$this->assertStringContainsString("FAIL: $server: nextcloud.log names the app:", $output);
		$this->assertStringContainsString('the upgraded app failed a request', $output);
	}

	/** @return iterable<string, list<string>> */
	public static function upgradedServers(): iterable {
		yield 'NC 31' => ['app31', '34', self::DISABLED . "\n" . self::REPLAY];
		yield 'NC 34' => ['app34', '34', self::DISABLED . "\n" . self::REPLAY];
		yield 'NC 34 -> 35, capped base' => ['to35', '34', self::DISABLED . "\n" . self::REPLAY];
		yield 'NC 34 -> 35, base allows 35' => ['to35', '35', ''];
	}

	/**
	 * Every server turns the app store off before it first enables the app, so no upgrade can take
	 * a newer nextfleet from the store in place of the package.
	 *
	 * @dataProvider pathsByDatabase
	 * @param list<string> $servers
	 */
	public function testEveryServerTurnsTheAppStoreOffFirst(string $db, array $servers): void {
		[$status, $output] = $this->check('34', self::DISABLED . "\n" . self::REPLAY, [], $db);

		$this->assertSame(0, $status, $output);
		foreach (array_unique(array_map(fn (string $server): string => $server === 'to35' ? 'from34' : $server, $servers)) as $server) {
			$calls = $this->calls($server);
			$off = array_keys($calls, 'config:system:set appstoreenabled --type=boolean --value=false', true);
			$enabled = array_keys($calls, 'app:enable nextfleet', true);
			$this->assertCount(count($enabled), $off, "$server: " . implode(' | ', $calls));
			foreach ($off as $i => $at) {
				$this->assertLessThan($enabled[$i], $at, "$server: " . implode(' | ', $calls));
			}
		}
	}

	/**
	 * MariaDB and PostgreSQL run every path. Oracle has one schema and the slowest servers, so it
	 * runs only the two that reach NC 35, the one it is weakest on.
	 *
	 * @dataProvider pathsByDatabase
	 * @param list<string> $servers
	 */
	public function testEachDatabaseRunsItsPaths(string $db, array $servers): void {
		[$status, $output] = $this->check('34', self::DISABLED . "\n" . self::REPLAY, [], $db);

		$this->assertSame(0, $status, $output);
		preg_match_all('/^(\w+): 0\.4\.[01] (?:installs fresh|upgrades to 0\.4\.1)/m', $output, $passed);
		$this->assertSame($servers, $passed[1]);
	}

	/** @return iterable<string, array{string, list<string>}> */
	public static function pathsByDatabase(): iterable {
		$all = ['app31', 'app34', 'app35', 'app31', 'app34', 'to35'];
		yield 'mariadb' => ['mariadb', $all];
		yield 'pgsql' => ['pgsql', $all];
		yield 'oracle' => ['oracle', ['app35', 'to35']];
	}

	/**
	 * Runs the check of a 0.4.1 package over a 0.4.0 base that allows NC up to $baseMax.
	 *
	 * @param array<string, string> $env
	 * @return array{int, string} the exit status and stdout with stderr
	 */
	private function check(string $baseMax, string $upgradeLog, array $env = [], string $db = 'mariadb'): array {
		$base = $this->package('0.4.0', $baseMax, ['000001Date20260101000000', '000002Date20260909000000']);
		$new = $this->package('0.4.1', '35', ['000001Date20260101000000', '000002Date20260909000000', '000003Date20260919000000']);
		file_put_contents($this->dir . '/upgrade.log', $upgradeLog === '' ? '' : $upgradeLog . "\n");

		$command = 'sh ' . escapeshellarg($this->dir . '/repo/tools/upgrade-check.sh') . ' --db ' . escapeshellarg($db)
			. ' ' . escapeshellarg($new) . ' ' . escapeshellarg($base) . ' 2>&1';
		$env += [
			'PATH' => $this->dir . '/bin:' . getenv('PATH'),
			'HOME' => (string)getenv('HOME'),
			'CHECK_LOG' => $this->log,
			'CHECK_STATE' => $this->dir . '/state',
			'CHECK_UPGRADE_LOG' => $this->dir . '/upgrade.log',
		];
		$process = proc_open($command, [1 => ['pipe', 'w']], $pipes, $this->dir, $env);
		$this->assertIsResource($process);
		$output = (string)stream_get_contents($pipes[1]);
		fclose($pipes[1]);

		return [proc_close($process), $output];
	}

	/** @param list<string> $migrations */
	private function package(string $version, string $max, array $migrations): string {
		$stage = "$this->dir/stage-$version";
		mkdir("$stage/nextfleet/appinfo", 0700, true);
		mkdir("$stage/nextfleet/lib/Migration", 0700, true);
		file_put_contents("$stage/nextfleet/appinfo/info.xml", "<info>\n\t<version>$version</version>\n"
			. "\t<dependencies>\n\t\t<nextcloud min-version=\"31\" max-version=\"$max\"/>\n\t</dependencies>\n</info>\n");
		foreach ($migrations as $migration) {
			touch("$stage/nextfleet/lib/Migration/Version$migration.php");
		}
		$tarball = "$this->dir/nextfleet-$version.tar.gz";
		exec('tar -czf ' . escapeshellarg($tarball) . ' -C ' . escapeshellarg($stage) . ' nextfleet', $output, $status);
		$this->assertSame(0, $status);

		return $tarball;
	}

	/**
	 * The occ commands the script ran on $server.
	 *
	 * @return list<string>
	 */
	private function calls(string $server): array {
		$lines = file($this->log, FILE_IGNORE_NEW_LINES);
		$this->assertIsArray($lines);
		$calls = [];
		foreach ($lines as $line) {
			if (preg_match('/ exec -T -u www-data ' . $server . ' php occ (.*)$/', $line, $match)) {
				$calls[] = $match[1];
			}
		}

		return $calls;
	}
}
