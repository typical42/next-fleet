<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Reads the stack the way docker itself does - `docker compose config` resolves and
 * validates the file against the compose schema without ever contacting the daemon, so
 * the shape of the dev environment is checked on a machine that cannot run it.
 */
class ComposeStackTest extends TestCase {
	private const FILE = __DIR__ . '/../../.docker/compose.yml';

	/** @var array<string, mixed> */
	private static array $config;

	public static function setUpBeforeClass(): void {
		if (self::capture('command -v docker')['status'] !== 0) {
			self::markTestSkipped('the docker CLI is not installed');
		}

		$compose = self::capture(
			'docker compose -f ' . escapeshellarg(self::FILE) . ' config --format json',
		);

		// Only an absent CLI is a skip. A CLI that read the file and rejected it is the
		// failure this test exists to report.
		self::assertSame(0, $compose['status'], "docker refused the compose file:\n" . $compose['stderr']);

		self::$config = (array)json_decode($compose['stdout'], true, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * Separate pipes rather than `2>&1`: docker writes its deprecation notices to stderr,
	 * and merging them into stdout would leave json_decode parsing a warning.
	 *
	 * @return array{status: int, stdout: string, stderr: string}
	 */
	private static function capture(string $command): array {
		$pipes = [];
		$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		self::assertIsResource($process, "could not run: $command");

		$stdout = (string)stream_get_contents($pipes[1]);
		$stderr = (string)stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);

		return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
	}

	/** @return array<string, mixed> */
	private function service(string $name): array {
		$services = (array)(self::$config['services'] ?? []);
		$this->assertArrayHasKey($name, $services, "the stack declares no '$name' service");

		return (array)$services[$name];
	}

	/**
	 * docs/development.md names five: the database, both Nextcloud majors the M0 gate
	 * loads, the cron runner that makes reminders fire, and the SMTP sink.
	 */
	public function testTheStackDeclaresTheServicesTheDocsDescribe(): void {
		$this->assertSame(
			['app', 'app31', 'cron', 'db', 'mail'],
			$this->sortedServiceNames(),
		);
	}

	/**
	 * The M0 gate is checked by loading both ports, so the pairing of major to port is the
	 * one thing in this file a typo would silently invert.
	 */
	public function testBothNextcloudMajorsArePublishedOnTheirOwnPort(): void {
		$this->assertSame('nextcloud:34-apache', $this->service('app')['image']);
		$this->assertSame([8080], $this->publishedPorts('app'));

		$this->assertSame('nextcloud:31-apache', $this->service('app31')['image']);
		$this->assertSame([8081], $this->publishedPorts('app31'));
	}

	/**
	 * Edit in WSL, reload the browser. cron needs it too, or the job it runs every five
	 * minutes executes a different copy of the app than the one being edited.
	 *
	 * @dataProvider nextcloudServices
	 */
	public function testEveryNextcloudServiceBindMountsTheRepoAsTheApp(string $name): void {
		$repo = (string)realpath(__DIR__ . '/../..');

		$mounted = array_filter(
			(array)($this->service($name)['volumes'] ?? []),
			static fn (array $v): bool => ($v['target'] ?? null) === '/var/www/html/custom_apps/nextfleet',
		);

		$this->assertCount(1, $mounted, "$name does not mount the app exactly once");
		$this->assertSame($repo, rtrim((string)reset($mounted)['source'], '/'));
	}

	/**
	 * Without it Nextcloud refuses the request outright, which reads as a broken app
	 * rather than as a missing setting. cron is exempt: its entrypoint bypasses the
	 * image's, so nothing there reads the environment at all.
	 *
	 * @dataProvider webServices
	 */
	public function testEveryNextcloudServiceTrustsLocalhost(string $name): void {
		$this->assertSame(
			'localhost',
			(string)((array)$this->service($name)['environment'])['NEXTCLOUD_TRUSTED_DOMAINS'],
		);
	}

	/**
	 * Docker creates the bind mount's parent as root and the image only takes ownership of
	 * it while it is still empty, so without this NC 31 refuses to install. It is one line
	 * of shell in a YAML file, and nothing but this test notices when it goes.
	 *
	 * @dataProvider webServices
	 */
	public function testEveryNextcloudServiceTakesOwnershipOfTheAppDirectory(string $name): void {
		$entrypoint = implode(' ', (array)($this->service($name)['entrypoint'] ?? []));

		$this->assertStringContainsString('chown www-data:www-data /var/www/html/custom_apps', $entrypoint);
		$this->assertStringContainsString('/entrypoint.sh', $entrypoint, 'the image never gets to install');
	}

	/**
	 * A shared schema or a shared html volume means whichever major starts second runs
	 * `occ upgrade` against the other's install, and the gate then tests one version twice.
	 */
	public function testTheTwoMajorsShareNothingButTheDatabaseServer(): void {
		$app = (array)$this->service('app')['environment'];
		$app31 = (array)$this->service('app31')['environment'];

		$this->assertSame($app['MYSQL_HOST'], $app31['MYSQL_HOST']);
		$this->assertNotSame($app['MYSQL_DATABASE'], $app31['MYSQL_DATABASE']);

		$this->assertSame([], array_intersect($this->namedVolumes('app'), $this->namedVolumes('app31')));
	}

	/** @return list<string> */
	private function namedVolumes(string $name): array {
		$volumes = array_filter(
			(array)($this->service($name)['volumes'] ?? []),
			static fn (array $v): bool => ($v['type'] ?? null) === 'volume',
		);

		return array_values(array_map(static fn (array $v): string => (string)$v['source'], $volumes));
	}

	/** @return iterable<string, list<string>> */
	public static function nextcloudServices(): iterable {
		yield from self::webServices();
		yield 'cron' => ['cron'];
	}

	/** @return iterable<string, list<string>> */
	public static function webServices(): iterable {
		yield 'app' => ['app'];
		yield 'app31' => ['app31'];
	}

	/** @return list<int> */
	private function publishedPorts(string $name): array {
		$ports = array_map(
			static fn (array $p): int => (int)$p['published'],
			(array)($this->service($name)['ports'] ?? []),
		);
		sort($ports);

		return $ports;
	}

	/** @return list<string> */
	private function sortedServiceNames(): array {
		$names = array_keys((array)(self::$config['services'] ?? []));
		sort($names);

		return array_map('strval', $names);
	}
}
