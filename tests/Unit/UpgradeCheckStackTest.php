<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The servers tools/upgrade-check.sh runs its paths on, merged with each database's file as the
 * script merges them. Only the CLI reads the files here; the script's run is what proves the paths.
 */
class UpgradeCheckStackTest extends TestCase {
	/** The environment variable that names a server's database, by the --db that picks it. Oracle has one. */
	private const DATABASE = ['mariadb' => 'MYSQL_DATABASE', 'pgsql' => 'POSTGRES_DB'];

	/** @var array<string, array<string, mixed>> services by database */
	private static array $services = [];

	/**
	 * A server on another major than its name passes the check for a version nobody asked about.
	 * Oracle's run only the paths that reach NC 35.
	 *
	 * @dataProvider servers
	 */
	public function testEveryServerRunsTheMajorItIsNamedFor(string $db, string $name, string $major): void {
		$service = $this->service($db, $name);
		if ($db === 'oracle') {
			$this->assertSame($major, (string)($service['build']['args']['NEXTCLOUD'] ?? ''));
		} else {
			$this->assertSame("nextcloud:$major-apache", $service['image'] ?? null);
		}
	}

	/** @return iterable<string, list<string>> */
	public static function servers(): iterable {
		foreach (['mariadb', 'pgsql'] as $db) {
			yield "$db app31" => [$db, 'app31', '31'];
			yield "$db app34" => [$db, 'app34', '34'];
			yield "$db app35" => [$db, 'app35', '35'];
			yield "$db from34" => [$db, 'from34', '34'];
			yield "$db to35" => [$db, 'to35', '35'];
		}
		yield 'oracle app35' => ['oracle', 'app35', '35'];
		yield 'oracle from34' => ['oracle', 'from34', '34'];
		yield 'oracle to35' => ['oracle', 'to35', '35'];
	}

	/**
	 * The server upgrade is the image switch over the install NC 34 left: its html volume, which
	 * holds config, data and the app, and its database. Anything less is a fresh NC 35.
	 *
	 * @dataProvider databases
	 */
	public function testThe34To35PathKeepsOneInstallAcrossTheImageSwitch(string $db): void {
		$this->assertNotSame([], $this->namedVolumes($db, 'from34'), 'NC 34 keeps its install in no named volume');
		$this->assertSame($this->namedVolumes($db, 'from34'), $this->namedVolumes($db, 'to35'));
		if (isset(self::DATABASE[$db])) {
			$this->assertNotSame('', $this->database($db, 'from34'), 'NC 34 names no database');
			$this->assertSame($this->database($db, 'from34'), $this->database($db, 'to35'));
		}
	}

	/** @return iterable<string, list<string>> */
	public static function databases(): iterable {
		yield 'mariadb' => ['mariadb'];
		yield 'pgsql' => ['pgsql'];
		yield 'oracle' => ['oracle'];
	}

	/**
	 * One server upgrading another's install is the path above, never by accident. Oracle has one
	 * schema, so the script runs its servers one phase at a time instead.
	 *
	 * @dataProvider databasesPerInstall
	 */
	public function testEveryOtherServerHasAnInstallOfItsOwn(string $db): void {
		$databases = array_map(fn (string $name): string => $this->database($db, $name), ['app31', 'app34', 'app35', 'from34']);
		$this->assertNotContains('', $databases);
		$this->assertSame($databases, array_unique($databases));

		foreach (['app31', 'app34', 'app35'] as $name) {
			$this->assertSame([], $this->namedVolumes($db, $name), "$name shares a named volume");
		}
	}

	/** @return iterable<string, list<string>> */
	public static function databasesPerInstall(): iterable {
		yield 'mariadb' => ['mariadb'];
		yield 'pgsql' => ['pgsql'];
	}

	/** @return array<string, mixed> */
	private function service(string $db, string $name): array {
		$services = self::config($db);
		$this->assertArrayHasKey($name, $services, "the upgrade check on $db declares no '$name' server");

		return (array)$services[$name];
	}

	private function database(string $db, string $name): string {
		$environment = (array)($this->service($db, $name)['environment'] ?? []);

		return (string)($environment[self::DATABASE[$db] ?? ''] ?? '');
	}

	/** @return list<string> */
	private function namedVolumes(string $db, string $name): array {
		$volumes = array_filter(
			(array)($this->service($db, $name)['volumes'] ?? []),
			static fn (array $v): bool => ($v['type'] ?? null) === 'volume' && isset($v['source']),
		);

		return array_values(array_map(static fn (array $v): string => (string)$v['source'], $volumes));
	}

	/** @return array<string, mixed> */
	private static function config(string $db): array {
		if (isset(self::$services[$db])) {
			return self::$services[$db];
		}

		$tools = __DIR__ . '/../../tools';
		$pipes = [];
		$process = proc_open(
			'docker compose -f ' . escapeshellarg("$tools/upgrade-check.compose.yml")
				. ' -f ' . escapeshellarg("$tools/upgrade-check.$db.yml") . ' config --format json',
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
		);
		self::assertIsResource($process);
		$stdout = (string)stream_get_contents($pipes[1]);
		$stderr = (string)stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status = proc_close($process);

		if ($status === 127) {
			self::markTestSkipped('the docker CLI is not installed');
		}
		self::assertSame(0, $status, "docker refused the upgrade check's compose files for $db:\n$stderr");

		$config = (array)json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);

		return self::$services[$db] = (array)($config['services'] ?? []);
	}
}
